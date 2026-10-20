<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\Llm;

use SearchAssistantAI\Settings\SearchAssistantSettings;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use RuntimeException;

/**
 * Factory for the configured LLM provider (service name LlmInterface::class).
 *
 * Providers are declared in the `search_assistant.llm_providers` config registry,
 * keyed by the value stored in `search_assistant_llm_provider`:
 *
 *     'search_assistant' => ['llm_providers' => [
 *         'ollama' => ['label' => 'Self-hosted ...', 'service' => OllamaService::class],
 *     ]]
 *
 * Another module adds a provider by adding an entry; its `service` is
 * resolved from the service manager. Providers implementing
 * TokenBudgetAwareInterface get the search_assistant_max_* budgets applied.
 */
class LlmServiceFactory implements FactoryInterface
{
    // Must cover a full generation on slow self-hosted upstreams (a 27B
    // model producing a long answer routinely needs 60-120s).
    public const DEFAULT_TIMEOUT = 240.0;

    public function __invoke(ContainerInterface $services, $requestedName, array $options = null): LlmInterface
    {
        $conf = new SearchAssistantSettings($services->get('Omeka\Settings'));

        $provider = $conf->getLlmProvider();
        $registry = $services->get('Config')['search_assistant']['llm_providers'] ?? [];
        if (!isset($registry[$provider]['service'])) {
            throw new RuntimeException(sprintf(
                'LLM provider "%s" is not available. Known providers: %s',
                $provider,
                implode(', ', array_keys($registry))
            ));
        }

        $service = $services->get($registry[$provider]['service']);
        if (!$service instanceof LlmInterface) {
            throw new RuntimeException(sprintf('LLM provider "%s" does not implement %s', $provider, LlmInterface::class));
        }

        if ($service instanceof TokenBudgetAwareInterface) {
            self::applyBudgetOverrides($service, $conf);
        }

        return $service;
    }

    /**
     * Token-budget overrides keep prompts under the provider's context window.
     */
    public static function applyBudgetOverrides(TokenBudgetAwareInterface $service, SearchAssistantSettings $conf): void
    {
        $maxContextTokens = $conf->getMaxContextTokens();
        $maxPayloadChars = $conf->getMaxPayloadChars();
        $maxProperties = $conf->getMaxProperties();

        if ($maxContextTokens > 0) {
            $service->setMaxContextTokens($maxContextTokens);
        }
        if ($maxPayloadChars > 0) {
            $service->setMaxFieldChars($maxPayloadChars);
        }
        if ($maxProperties > 0) {
            $service->setMaxProperties($maxProperties);
        }
    }
}
