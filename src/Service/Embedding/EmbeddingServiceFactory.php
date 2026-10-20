<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\Embedding;

use SearchAssistantAI\Settings\SearchAssistantSettings;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class EmbeddingServiceFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null): EmbeddingService
    {
        $conf = new SearchAssistantSettings($services->get('Omeka\Settings'));

        return new EmbeddingService(
            $conf->getEmbeddingsUrl(),
            30.0,
            './cache/embeddings',
            $conf->getEmbeddingDimension(),
            null,
            '/embed',
            $conf->getEmbeddingsApiFormat(),
            $conf->getEmbeddingsModel()
        );
    }
}
