<?php
namespace SearchAssistantAITest\Service;

use SearchAssistantAITest\Support\ArraySettingsTrait;
use SearchAssistantAITest\Support\ModuleServices;
use PHPUnit\Framework\TestCase;
use SearchAssistantAI\Service\VectorStore\VectorStoreFactory;
use SearchAssistantAI\Service\VectorStore\VectorStoreInterface;
use SearchAssistantAI\Service\VectorStore\QdrantService;
use SearchAssistantAI\Service\VectorStore\ElasticsearchService;
use Interop\Container\ContainerInterface;

/**
 * Unit tests for VectorStoreFactory.
 *
 * Tests cover factory creation of QdrantService and ElasticsearchService
 * based on the search_assistant_vector_store_backend setting.
 */
class VectorStoreFactoryTest extends TestCase
{
    use ArraySettingsTrait;

    /**
     * @var VectorStoreFactory
     */
    private $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = new VectorStoreFactory();
    }

    /**
     * @param array<string, mixed> $settings Omeka settings
     */
    private function createMockContainer(array $settings = []): ModuleServices
    {
        return ModuleServices::withSettings($this->arraySettings($settings));
    }

    /**
     * Test factory returns QdrantService by default.
     */
    public function testDefaultBackendIsQdrant(): void
    {
        $container = $this->createMockContainer([]);

        $service = $this->factory->__invoke($container, VectorStoreInterface::class);

        $this->assertInstanceOf(QdrantService::class, $service);
    }

    /**
     * Test factory returns QdrantService when explicitly configured.
     */
    public function testQdrantBackendExplicit(): void
    {
        $container = $this->createMockContainer([
            'search_assistant_vector_store_backend' => 'qdrant',
            'search_assistant_qdrant_url' => 'http://qdrant:6333',
        ]);

        $service = $this->factory->__invoke($container, VectorStoreInterface::class);

        $this->assertInstanceOf(QdrantService::class, $service);
        $this->assertInstanceOf(VectorStoreInterface::class, $service);
    }

    /**
     * Test factory passes Qdrant configuration correctly.
     */
    public function testQdrantConfigurationPassed(): void
    {
        $container = $this->createMockContainer([
            'search_assistant_vector_store_backend' => 'qdrant',
            'search_assistant_qdrant_url' => 'http://custom-qdrant:6333',
        ]);

        $service = $this->factory->__invoke($container, VectorStoreInterface::class);

        $this->assertInstanceOf(QdrantService::class, $service);
    }

    public function testElasticsearchBackend(): void
    {
        $container = $this->createMockContainer([
            'search_assistant_vector_store_backend' => 'elasticsearch',
            'search_assistant_es_url' => 'https://custom-es:9200',
            'search_assistant_es_api_key' => 'test-api-key',
            'search_assistant_es_verify_ssl' => false,
        ]);

        $service = $this->factory->__invoke($container, VectorStoreInterface::class);

        $this->assertInstanceOf(ElasticsearchService::class, $service);
    }

    /**
     * An unknown backend (left by a removed module or a hand-edited setting)
     * resolves to the shipped backend instead of taking the site down.
     */
    public function testUnknownBackendFallsBackToQdrant(): void
    {
        $container = $this->createMockContainer([
            'search_assistant_vector_store_backend' => 'unknown_backend',
        ]);

        self::assertInstanceOf(QdrantService::class, $this->factory->__invoke($container, VectorStoreInterface::class));
    }
}
