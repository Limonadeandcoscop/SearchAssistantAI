<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\Llm;

use SearchAssistantAI\Service\Prompt\PromptProviderInterface;
use SearchAssistantAI\Settings\SearchAssistantSettings;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

/**
 * Self-hosted LLM speaking the native Ollama API or the OpenAI-compatible API.
 */
class OllamaServiceFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null): OllamaService
    {
        $conf = new SearchAssistantSettings($services->get('Omeka\Settings'));

        return new OllamaService(
            $conf->getOllamaUrl(),
            $conf->getOllamaModel(),
            LlmServiceFactory::DEFAULT_TIMEOUT,
            $services->get(PromptProviderInterface::class)->getInstructions(),
            $conf->getLlmApiFormat()
        );
    }
}
