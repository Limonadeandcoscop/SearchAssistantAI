<?php
declare(strict_types=1);

namespace SearchAssistantAITest\Service\Analytics;

use SearchAssistantAI\Service\Analytics\HealthCheckerInterface;
use SearchAssistantAI\Service\Analytics\HealthCheckInterface;
use SearchAssistantAITest\Support\ArraySettingsTrait;
use SearchAssistantAITest\Support\ModuleServices;
use PHPUnit\Framework\TestCase;

/**
 * The dashboard LLM tile probes the provider chosen in search_assistant.llm_providers.
 */
class HealthCheckerFactoryTest extends TestCase
{
    use ArraySettingsTrait;

    /**
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $services
     * @param array<string, mixed> $config
     */
    private function checker(array $stored, array $services = [], array $config = []): HealthCheckerInterface
    {
        return ModuleServices::withSettings($this->arraySettings($stored), $services, $config)
            ->get(HealthCheckerInterface::class);
    }

    public function testSelfHostedProviderUsesTheOllamaProbe(): void
    {
        // No self-hosted URL stored: nothing to probe.
        $result = $this->checker(['search_assistant_llm_provider' => 'ollama'])->checkLlm();

        self::assertSame('not_configured', $result['status']);
        self::assertSame('Ollama service not configured', $result['error']);
    }

    public function testProviderAddedByAnotherModuleBringsItsProbe(): void
    {
        $probe = $this->createMock(HealthCheckInterface::class);
        $probe->method('check')->willReturn(['status' => 'connected']);

        $result = $this->checker(
            ['search_assistant_llm_provider' => 'custom'],
            ['Custom\Health' => $probe],
            ['search_assistant' => ['llm_providers' => ['custom' => ['label' => 'Custom', 'service' => 'Custom\Llm', 'health' => 'Custom\Health']]]]
        )->checkLlm();

        self::assertSame('connected', $result['status']);
    }

    public function testProviderWithoutProbeIsNotConfigured(): void
    {
        $result = $this->checker(
            ['search_assistant_llm_provider' => 'custom'],
            [],
            ['search_assistant' => ['llm_providers' => ['custom' => ['label' => 'Custom', 'service' => 'Custom\Llm']]]]
        )->checkLlm();

        self::assertSame('not_configured', $result['status']);
    }
}
