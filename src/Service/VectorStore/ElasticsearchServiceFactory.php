<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\VectorStore;

use SearchAssistantAI\Settings\SearchAssistantSettings;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ElasticsearchServiceFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null): ElasticsearchService
    {
        $conf = new SearchAssistantSettings($services->get('Omeka\Settings'));
        return new ElasticsearchService(
            $conf->getEsUrl(),
            $conf->getEsApiKey(),
            VectorStoreFactory::CLIENT_TIMEOUT,
            $conf->getEsVerifySsl()
        );
    }
}
