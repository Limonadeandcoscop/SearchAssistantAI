<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\Llm;

use SearchAssistantAI\Service\Analytics\HealthCheckInterface;

/**
 * Self-hosted LLM probe (Ollama or OpenAI-compatible): lists the server's
 * models and checks the configured one is available.
 */
class OllamaHealthCheck implements HealthCheckInterface
{
    /** @var OllamaService|null Null when no self-hosted URL was configured */
    private ?OllamaService $ollamaService;

    public function __construct(?OllamaService $ollamaService)
    {
        $this->ollamaService = $ollamaService;
    }

    /**
     * @return array{status:string, data?:array<string, mixed>, error?:string}
     */
    public function check(): array
    {
        if ($this->ollamaService === null) {
            return ['status' => 'not_configured', 'error' => 'Ollama service not configured'];
        }

        try {
            $result = $this->ollamaService->testConnection();
            if ($result['success']) {
                return [
                    'status' => 'connected',
                    'data' => [
                        'message' => $result['message'],
                        'models' => $result['models'],
                        'model_available' => $result['model_available'],
                        'timestamp' => time(),
                    ],
                ];
            }
            return ['status' => 'disconnected', 'error' => $result['message']];
        } catch (\Exception $e) {
            return ['status' => 'disconnected', 'error' => $e->getMessage()];
        }
    }
}
