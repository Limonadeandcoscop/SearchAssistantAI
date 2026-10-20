<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\Analytics;

use SearchAssistantAI\Settings\SearchAssistantSettings;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

/**
 * Embeddings probe plus the `health` service of the configured LLM provider
 * (search_assistant.llm_providers registry). Hosted mode replaces it via a delegator.
 */
class HealthCheckerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null): HealthCheckerInterface
    {
        $conf = new SearchAssistantSettings($services->get('Omeka\Settings'));

        $providers = $services->get('Config')['search_assistant']['llm_providers'] ?? [];
        $healthService = $providers[$conf->getLlmProvider()]['health'] ?? null;
        $llmCheck = $healthService !== null ? $services->get($healthService) : null;

        return new HealthChecker(
            $conf->getEmbeddingsUrl(),
            $llmCheck instanceof HealthCheckInterface ? $llmCheck : null,
            $conf->getEmbeddingsApiFormat(),
            $conf->getEmbeddingsModel()
        );
    }
}
