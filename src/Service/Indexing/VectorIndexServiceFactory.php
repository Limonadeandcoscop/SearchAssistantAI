<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\Indexing;

use SearchAssistantAI\Service\Embedding\EmbeddingService;
use SearchAssistantAI\Service\VectorStore\VectorStoreInterface;
use SearchAssistantAI\Service\VectorStore\VectorStoreRegistry;
use SearchAssistantAI\Settings\SearchAssistantSettings;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class VectorIndexServiceFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null): VectorIndexService
    {
        $settings = $services->get('Omeka\Settings');
        $conf = new SearchAssistantSettings($settings);
        $logger = $services->get('Omeka\Logger');

        $registry = $services->get(VectorStoreRegistry::class);
        $collectionName = $registry->getCollectionName();

        $propertyIds = $conf->getIndexedPropertyIds();
        $collectionIds = $conf->getIndexedCollectionIds();

        if ($conf->isVerboseLogging()) {
            $logger->info('[SearchAssistantAI] VectorIndexService factory resolved', [
                'backend' => $registry->getBackend(),
                'collection' => $collectionName,
                'property_filters' => count($propertyIds),
                'collection_filters' => count($collectionIds),
            ]);
        }

        $payloadBuilder = new PayloadBuilder($propertyIds);
        $batchIndexer = new BatchIndexer(
            $services->get('Omeka\EntityManager'),
            $logger,
            $services->get(VectorStoreInterface::class),
            $services->get(EmbeddingService::class),
            $payloadBuilder,
            $collectionName,
            $collectionIds
        );

        // siteSlug is no longer used for URL construction; kept as empty string
        // for constructor-signature backwards compatibility. Locale prefix is
        // injected at query time by ChatController/QueryProcessorService.
        return new VectorIndexService(
            $services->get('Omeka\EntityManager'),
            $logger,
            $services->get(VectorStoreInterface::class),
            $services->get(EmbeddingService::class),
            $collectionName,
            '',
            $propertyIds,
            $collectionIds,
            $settings,
            $payloadBuilder,
            $batchIndexer
        );
    }
}
