<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\Analytics;

use SearchAssistantAI\Service\VectorStore\VectorStoreInterface;
use SearchAssistantAI\Service\VectorStore\VectorStoreRegistry;
use SearchAssistantAI\Settings\SearchAssistantSettings;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class StatsServiceFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null): StatsService
    {
        $conf = new SearchAssistantSettings($services->get('Omeka\Settings'));

        $service = new StatsService(
            $conf->getQdrantUrl(),
            $conf->getEmbeddingsUrl(),
            $services->get(HealthCheckerInterface::class)
        );

        $registry = $services->get(VectorStoreRegistry::class);
        $service->setVectorStore(
            $services->get(VectorStoreInterface::class),
            $registry->getBackend(),
            $registry->getCollectionName()
        );

        return $service;
    }
}
