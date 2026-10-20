<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\Analytics;

use SearchAssistantAI\Service\FileLogger;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use RuntimeException;

/**
 * HTTP helpers shared by the health probes: retries on network failures
 * and readable error messages.
 */
trait HttpProbeTrait
{
    /**
     * Run an HTTP operation with exponential-backoff retries on network
     * failures (ConnectException only). HTTP 4xx/5xx surface directly.
     *
     * @return mixed
     */
    protected function executeWithRetry(callable $operation)
    {
        $maxRetries = 3;
        $retryDelays = [1, 2, 4];
        $attempt = 0;
        $lastException = null;

        while ($attempt < $maxRetries) {
            try {
                return $operation();
            } catch (ConnectException $e) {
                $lastException = $e;
                $attempt++;
                if ($attempt < $maxRetries) {
                    sleep($retryDelays[$attempt - 1]);
                }
            }
        }

        throw new RuntimeException(
            'Failed to connect after ' . $maxRetries . ' attempts: ' . $lastException->getMessage(),
            0,
            $lastException
        );
    }

    protected function extractClientErrorMessage(ClientException $exception): string
    {
        try {
            $response = $exception->getResponse();
            $statusCode = $response->getStatusCode();
            $data = json_decode($response->getBody()->getContents(), true);

            if (isset($data['status']['error'])) {
                $errorMessage = is_array($data['status']['error'])
                    ? json_encode($data['status']['error'])
                    : $data['status']['error'];
                return "Client error (HTTP {$statusCode}): {$errorMessage}";
            }
            if (isset($data['error']['message']) && is_string($data['error']['message'])) {
                return "Client error (HTTP {$statusCode}): {$data['error']['message']}";
            }
            if (isset($data['error'])) {
                $errorMessage = is_array($data['error'])
                    ? json_encode($data['error'])
                    : $data['error'];
                return "Client error (HTTP {$statusCode}): {$errorMessage}";
            }
            return "Client error (HTTP {$statusCode}): Request failed";
        } catch (\Exception $e) {
            return 'Client error: Request failed with HTTP ' . $exception->getResponse()->getStatusCode();
        }
    }

    protected function logServerError(ServerException $exception, string $operation): void
    {
        $statusCode = $exception->getResponse()->getStatusCode();
        $class = substr((string) strrchr(static::class, '\\'), 1);
        FileLogger::getInstance()->error("{$class} {$operation} failed: Server error (HTTP {$statusCode})");
    }
}
