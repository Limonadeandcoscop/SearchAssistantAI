<?php
declare(strict_types=1);

namespace SearchAssistantAITest\Service\VectorStore;

use SearchAssistantAI\Service\VectorStore\ElasticsearchService;
use SearchAssistantAI\Service\VectorStore\QdrantService;
use SearchAssistantAI\Service\VectorStore\VectorStoreRegistry;
use SearchAssistantAI\Settings\SearchAssistantSettings;
use Omeka\Settings\Settings;
use PHPUnit\Framework\TestCase;

class VectorStoreRegistryTest extends TestCase
{
    /**
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $extraBackends
     */
    private function registry(array $stored, array $extraBackends = []): VectorStoreRegistry
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('get')->willReturnCallback(function ($id, $default = null) use ($stored) {
            return array_key_exists($id, $stored) ? $stored[$id] : $default;
        });
        $config = require dirname(__DIR__, 3) . '/config/module.config.php';

        return new VectorStoreRegistry(
            $config['search_assistant']['vector_store_backends'] + $extraBackends,
            new SearchAssistantSettings($settings)
        );
    }

    public function testDefaultsToQdrantAndItsCollection(): void
    {
        $registry = $this->registry(['search_assistant_qdrant_collection' => 'my_items']);

        self::assertSame('qdrant', $registry->getBackend());
        self::assertSame(QdrantService::class, $registry->getServiceName());
        self::assertSame('my_items', $registry->getCollectionName());
        self::assertSame('Qdrant', $registry->getLabel());
    }

    public function testElasticsearchUsesItsIndexAndUrlSettings(): void
    {
        $registry = $this->registry([
            'search_assistant_vector_store_backend' => 'elasticsearch',
            'search_assistant_qdrant_collection' => 'qdrant_name',
            'search_assistant_qdrant_url' => 'http://qdrant:6333',
            'search_assistant_es_index' => 'es_name',
            'search_assistant_es_url' => 'https://es:9200',
        ]);

        self::assertSame('elasticsearch', $registry->getBackend());
        self::assertSame(ElasticsearchService::class, $registry->getServiceName());
        self::assertSame('es_name', $registry->getCollectionName());
        self::assertSame('https://es:9200', $registry->getServerUrl());
        self::assertSame('Elasticsearch 8.x', $registry->getLabel());
    }

    public function testServerUrlComesFromTheBackendEntry(): void
    {
        self::assertSame('http://q:6333', $this->registry(['search_assistant_qdrant_url' => 'http://q:6333'])->getServerUrl());
        self::assertSame('', $this->registry(
            ['search_assistant_vector_store_backend' => 'custom'],
            ['custom' => ['label' => 'Custom', 'service' => 'Custom\\Store']]
        )->getServerUrl());
    }

    public function testCollectionFallsBackToDefault(): void
    {
        self::assertSame(VectorStoreRegistry::DEFAULT_COLLECTION, $this->registry([])->getCollectionName());
    }

    public function testBackendAddedByAnotherModule(): void
    {
        $registry = $this->registry(
            ['search_assistant_vector_store_backend' => 'custom', 'custom_collection' => 'c1'],
            ['custom' => ['label' => 'Custom', 'service' => 'Custom\Store', 'collection_setting' => 'custom_collection']]
        );

        self::assertSame('Custom\Store', $registry->getServiceName());
        self::assertSame('c1', $registry->getCollectionName());
        self::assertArrayHasKey('custom', $registry->getLabels());
    }

    /**
     * A backend another module used to register (e.g. after that module was
     * deactivated) must not take the dashboard or the chat down.
     */
    public function testUnknownBackendFallsBackToTheFirstRegisteredOne(): void
    {
        $registry = $this->registry([
            'search_assistant_vector_store_backend' => 'gone',
            'search_assistant_qdrant_collection' => 'c1',
        ]);

        self::assertFalse($registry->isAvailable('gone'));
        self::assertSame('qdrant', $registry->getBackend());
        self::assertSame(QdrantService::class, $registry->getServiceName());
        self::assertSame('Qdrant', $registry->getLabel());
        self::assertSame('c1', $registry->getCollectionName());
    }

    public function testNoRegisteredBackendIsReported(): void
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('get')->willReturn('qdrant');
        $registry = new VectorStoreRegistry([], new SearchAssistantSettings($settings));

        $this->expectException(\RuntimeException::class);
        $registry->getServiceName();
    }
}
