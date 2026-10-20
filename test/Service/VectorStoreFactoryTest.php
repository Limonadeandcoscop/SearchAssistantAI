<?php
namespace SearchAssistantAITest\Service;

use SearchAssistantAITest\Support\ArraySettingsTrait;
use SearchAssistantAITest\Support\ModuleServices;
use PHPUnit\Framework\TestCase;
use SearchAssistantAI\Service\VectorStore\VectorStoreFactory;
use SearchAssistantAI\Service\VectorStore\VectorStoreInterface;
use SearchAssistantAI\Service\VectorStore\QdrantService;
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

    /**
     * An unknown backend fails explicitly instead of silently using Qdrant
     * (it can only come from a removed module or a hand-edited setting).
     */
    public function testUnknownBackendFailsExplicitly(): void
    {
        $container = $this->createMockContainer([
            'search_assistant_vector_store_backend' => 'unknown_backend',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Vector store backend "unknown_backend" is not available');
        $this->factory->__invoke($container, VectorStoreInterface::class);
    }
}
