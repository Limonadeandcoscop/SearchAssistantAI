<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\Search;

use SearchAssistantAI\Service\Embedding\EmbeddingService;
use SearchAssistantAI\Service\VectorStore\VectorStoreInterface;
use SearchAssistantAI\Service\VectorStore\VectorStoreRegistry;
use SearchAssistantAI\Settings\SearchAssistantSettings;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class SemanticSearchServiceFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null): SemanticSearchService
    {
        $conf = new SearchAssistantSettings($services->get('Omeka\Settings'));

        return new SemanticSearchService(
            $services->get(VectorStoreInterface::class),
            $services->get(EmbeddingService::class),
            $services->get('Omeka\Logger'),
            $services->get(VectorStoreRegistry::class)->getCollectionName(),
            $conf->getEmbeddingDimension(),
            $services->get(ResultScorerInterface::class)
        );
    }
}
