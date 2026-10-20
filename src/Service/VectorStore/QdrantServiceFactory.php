<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\VectorStore;

use SearchAssistantAI\Settings\SearchAssistantSettings;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class QdrantServiceFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null): QdrantService
    {
        $conf = new SearchAssistantSettings($services->get('Omeka\Settings'));
        return new QdrantService($conf->getQdrantUrl(), VectorStoreFactory::CLIENT_TIMEOUT);
    }
}
