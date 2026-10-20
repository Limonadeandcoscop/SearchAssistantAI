<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\Llm;

use SearchAssistantAI\Settings\SearchAssistantSettings;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class OllamaHealthCheckFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null): OllamaHealthCheck
    {
        $conf = new SearchAssistantSettings($services->get('Omeka\Settings'));

        // Only probe when a URL was explicitly configured (raw get, to tell
        // "never set" from "defaulted to localhost").
        $url = trim((string) $conf->raw()->get(SearchAssistantSettings::KEY_OLLAMA_URL, ''));
        $service = $url !== ''
            ? new OllamaService($url, $conf->getOllamaModel(), 30.0, null, $conf->getLlmApiFormat())
            : null;

        return new OllamaHealthCheck($service);
    }
}
