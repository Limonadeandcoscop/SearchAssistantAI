<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\Analytics;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use RuntimeException;

/**
 * Probe the external services the search assistant depends on and return a uniform
 * `{status, data|error}` structure the admin dashboard can render.
 *
 * `status` is one of:
 *
 *   - 'connected'      : service reachable and authenticated.
 *   - 'disconnected'   : reachable but failing (timeout, bad key, 5xx).
 *   - 'not_configured' : nothing to probe (missing URL/API key/service).
 *
 * The LLM probe is the `health` service of the active entry in the
 * `search_assistant.llm_providers` registry. Hosted mode swaps this whole checker
 * for GatewayHealthChecker.
 */
class HealthChecker implements HealthCheckerInterface
{
    use HttpProbeTrait;

    private string $embeddingsBaseUrl;
    private string $embeddingsApiFormat;
    private string $embeddingsModel;
    private Client $embeddingsClient;

    /** @var HealthCheckInterface|null Probe of the configured LLM provider */
    private ?HealthCheckInterface $llmCheck;

    public function __construct(
        string $embeddingsBaseUrl,
        ?HealthCheckInterface $llmCheck = null,
        string $embeddingsApiFormat = 'native',
        string $embeddingsModel = ''
    ) {
        $this->embeddingsApiFormat = $embeddingsApiFormat;
        $this->embeddingsModel = $embeddingsModel;
        $this->embeddingsBaseUrl = rtrim($embeddingsBaseUrl, '/');
        if ($embeddingsApiFormat === 'openai') {
            $this->embeddingsBaseUrl = (string) preg_replace('#/v1$#', '', $this->embeddingsBaseUrl);
        }
        $this->llmCheck = $llmCheck;

        $this->embeddingsClient = new Client([
            'base_uri' => $this->embeddingsBaseUrl,
            'timeout' => 30.0,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * @return array{status:string, data?:array<string, mixed>, error?:string}
     */
    public function checkEmbeddings(): array
    {
        if ($this->embeddingsApiFormat === 'openai') {
            return $this->checkOpenAiEmbeddings();
        }

        try {
            $response = $this->executeWithRetry(function () {
                try {
                    return $this->embeddingsClient->get('/health');
                } catch (ClientException $e) {
                    // If the service doesn't expose /health, try / as a fallback.
                    if ($e->getResponse()->getStatusCode() === 404) {
                        try {
                            return $this->embeddingsClient->get('/');
                        } catch (\Exception $rootException) {
                            throw $e;
                        }
                    }
                    throw new RuntimeException($this->extractClientErrorMessage($e), 0, $e);
                } catch (ServerException $e) {
                    $this->logServerError($e, 'checkEmbeddings');
                    throw new RuntimeException(
                        'Embeddings server error (HTTP ' . $e->getResponse()->getStatusCode() . ')',
                        0,
                        $e
                    );
                }
            });

            $body = $response->getBody()->getContents();
            $data = json_decode($body, true);

            return [
                'status' => 'connected',
                'data' => [
                    'http_status' => $response->getStatusCode(),
                    'response' => $data ?? 'Service responding',
                    'timestamp' => time(),
                ],
            ];
        } catch (RuntimeException $e) {
            return ['status' => 'disconnected', 'error' => $e->getMessage()];
        }
    }

    /**
     * OpenAI-compatible servers (vLLM, TEI...) answer /health even when the
     * model is wrong, so embed a short string for real instead.
     *
     * @return array{status:string, data?:array<string, mixed>, error?:string}
     */
    private function checkOpenAiEmbeddings(): array
    {
        if ($this->embeddingsModel === '') {
            return ['status' => 'not_configured', 'error' => 'Embeddings model not configured (required by the OpenAI-compatible API)'];
        }

        try {
            $response = $this->executeWithRetry(function () {
                try {
                    return $this->embeddingsClient->post('/v1/embeddings', [
                        'json' => ['model' => $this->embeddingsModel, 'input' => ['health check']],
                    ]);
                } catch (ClientException $e) {
                    throw new RuntimeException($this->extractClientErrorMessage($e), 0, $e);
                } catch (ServerException $e) {
                    $this->logServerError($e, 'checkEmbeddings');
                    throw new RuntimeException(
                        'Embeddings server error (HTTP ' . $e->getResponse()->getStatusCode() . ')',
                        0,
                        $e
                    );
                }
            });

            $data = json_decode($response->getBody()->getContents(), true);
            $embedding = $data['data'][0]['embedding'] ?? null;
            if (!is_array($embedding)) {
                return ['status' => 'disconnected', 'error' => 'Unexpected response from /v1/embeddings (no data[0].embedding)'];
            }

            return [
                'status' => 'connected',
                'data' => [
                    'http_status' => $response->getStatusCode(),
                    'response' => ['model' => $data['model'] ?? $this->embeddingsModel, 'dimensions' => count($embedding)],
                    'timestamp' => time(),
                ],
            ];
        } catch (RuntimeException $e) {
            return ['status' => 'disconnected', 'error' => $e->getMessage()];
        }
    }

    public function checkLlm(): array
    {
        if ($this->llmCheck === null) {
            return ['status' => 'not_configured', 'error' => 'No health check for the configured LLM provider'];
        }
        return $this->llmCheck->check();
    }
}
