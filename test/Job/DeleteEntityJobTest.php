<?php
declare(strict_types=1);

namespace SearchAssistantAITest\Job;

use SearchAssistantAI\Job\DeleteEntityJob;
use SearchAssistantAI\Service\VectorStore\VectorStoreInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DeleteEntityJob.
 *
 * Covers arg validation, service lookup, cancellation, idempotency on a
 * soft-missing point (deletePoint returns false), 404-style error
 * absorption, and real-failure propagation.
 */
class DeleteEntityJobTest extends TestCase
{
    /** @var VectorStoreInterface&MockObject */
    private $vectorStore;

    private $logger;

    /** @var \Interop\Container\ContainerInterface&MockObject */
    private $services;

    protected function setUp(): void
    {
        $this->vectorStore = $this->createMock(VectorStoreInterface::class);
        $this->logger = new class {
            /** @var array<string, array<int, mixed>> */
            public $calls = [];
            public function __call(string $name, array $args): void
            {
                $this->calls[$name][] = $args[0] ?? '';
            }
        };

        $this->services = $this->createMock(\Interop\Container\ContainerInterface::class);
        $this->services->method('get')->willReturnCallback(function (string $id) {
            $map = [
                VectorStoreInterface::class => $this->vectorStore,
                'Omeka\Logger' => $this->logger,
            ];
            return $map[$id] ?? null;
        });
    }

    public function testDeletesVectorPointWithDefaultCollection(): void
    {
        $this->vectorStore->expects(self::once())
            ->method('deletePoint')
            ->with('omeka_resources', 42)
            ->willReturn(true);

        $this->makeJob(['entity_id' => 42, 'entity_type' => 'items'])->perform();
    }

    public function testHonorsCustomCollectionName(): void
    {
        $this->vectorStore->expects(self::once())
            ->method('deletePoint')
            ->with('custom', 1)
            ->willReturn(true);

        $this->makeJob([
            'entity_id' => 1,
            'entity_type' => 'items',
            'collection_name' => 'custom',
        ])->perform();
    }

    public function testMissingEntityIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->makeJob(['entity_type' => 'items'])->perform();
    }

    public function testMissingEntityTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->makeJob(['entity_id' => 1])->perform();
    }

    public function testDeletePointReturningFalseIsTreatedAsSuccess(): void
    {
        $this->vectorStore->method('deletePoint')->willReturn(false);
        $this->makeJob(['entity_id' => 1, 'entity_type' => 'items'])->perform();
        self::assertNotEmpty($this->logger->calls['warn'] ?? []);
    }

    public function testNotFoundErrorIsAbsorbed(): void
    {
        $this->vectorStore->method('deletePoint')
            ->willThrowException(new \RuntimeException('HTTP 404 point not found'));

        // No exception should propagate.
        $this->makeJob(['entity_id' => 1, 'entity_type' => 'items'])->perform();
        self::assertTrue(true);
    }

    public function testRealFailureIsWrappedInRuntimeException(): void
    {
        $this->vectorStore->method('deletePoint')
            ->willThrowException(new \RuntimeException('connection refused'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to delete vector point/');
        $this->makeJob(['entity_id' => 1, 'entity_type' => 'items'])->perform();
    }

    public function testCancellationBeforeStartSkipsDeletion(): void
    {
        $this->vectorStore->expects(self::never())->method('deletePoint');
        $this->makeJob(['entity_id' => 1, 'entity_type' => 'items'], true)->perform();
    }

    /** @param array<string, mixed> $args */
    private function makeJob(array $args, bool $shouldStop = false): DeleteEntityJob
    {
        $job = $this->getMockBuilder(DeleteEntityJob::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getServiceLocator', 'getArg', 'shouldStop'])
            ->getMock();
        $job->method('getServiceLocator')->willReturn($this->services);
        $job->method('shouldStop')->willReturn($shouldStop);
        $job->method('getArg')->willReturnCallback(
            fn (string $name, $default = null) => $args[$name] ?? $default
        );
        return $job;
    }
}
