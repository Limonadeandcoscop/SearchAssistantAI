<?php
declare(strict_types=1);

namespace SearchAssistantAITest\Job;

use SearchAssistantAI\Job\IndexEntityJob;
use SearchAssistantAI\Service\Indexing\VectorIndexInterface;
use Doctrine\ORM\EntityManager;
use Omeka\Entity\Item;
use Omeka\Entity\ItemSet;
use Omeka\Entity\Media;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for IndexEntityJob.
 *
 * AbstractJob's runtime plumbing (getServiceLocator/getArg/shouldStop) is
 * patched out via onlyMethods so the test stays focused on the job's own
 * responsibilities: arg validation, service lookup, cancellation check,
 * entity-type dispatch, not-found tolerance, and error propagation.
 */
class IndexEntityJobTest extends TestCase
{
    /** @var VectorIndexInterface&MockObject */
    private $vectorIndex;

    /** @var EntityManager&MockObject */
    private $em;

    /** @var object Logger stub — accepts any method. */
    private $logger;

    /** @var \Interop\Container\ContainerInterface&MockObject */
    private $services;

    protected function setUp(): void
    {
        $this->vectorIndex = $this->createMock(VectorIndexInterface::class);
        $this->em = $this->createMock(EntityManager::class);
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
                'SearchAssistantAI\Service\Indexing\VectorIndexService' => $this->vectorIndex,
                'Omeka\Logger' => $this->logger,
                'Omeka\EntityManager' => $this->em,
            ];
            return $map[$id] ?? null;
        });
    }

    public function testIndexesItem(): void
    {
        $item = $this->createMock(Item::class);
        $item->method('getId')->willReturn(42);
        $this->em->method('find')->with(Item::class, 42)->willReturn($item);

        $this->vectorIndex->expects(self::once())->method('indexItem')->with($item);

        $this->makeJob(['entity_id' => 42, 'entity_type' => 'items'])->perform();
    }

    public function testIndexesMedia(): void
    {
        $media = $this->createMock(Media::class);
        $media->method('getId')->willReturn(7);
        $this->em->method('find')->with(Media::class, 7)->willReturn($media);

        $this->vectorIndex->expects(self::once())->method('indexMedia')->with($media);

        $this->makeJob(['entity_id' => 7, 'entity_type' => 'media'])->perform();
    }

    public function testIndexesItemSet(): void
    {
        $set = $this->createMock(ItemSet::class);
        $set->method('getId')->willReturn(3);
        $this->em->method('find')->with(ItemSet::class, 3)->willReturn($set);

        $this->vectorIndex->expects(self::once())->method('indexCollection')->with($set);

        $this->makeJob(['entity_id' => 3, 'entity_type' => 'item_sets'])->perform();
    }

    public function testMissingEntityIdThrows(): void
    {
        $this->vectorIndex->expects(self::never())->method(self::anything());
        $this->expectException(\InvalidArgumentException::class);
        $this->makeJob(['entity_type' => 'items'])->perform();
    }

    public function testMissingEntityTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->makeJob(['entity_id' => 1])->perform();
    }

    public function testInvalidEntityTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid entity type/');
        $this->makeJob(['entity_id' => 1, 'entity_type' => 'sites'])->perform();
    }

    public function testMissingEntityIsTreatedAsSuccess(): void
    {
        $this->em->method('find')->willReturn(null);
        $this->vectorIndex->expects(self::never())->method(self::anything());

        $this->makeJob(['entity_id' => 99, 'entity_type' => 'items'])->perform();

        self::assertNotEmpty($this->logger->calls['warn'] ?? [], 'should log a warning');
    }

    public function testCancellationBeforeStartReturnsEarly(): void
    {
        $this->vectorIndex->expects(self::never())->method(self::anything());
        $this->em->expects(self::never())->method('find');

        $this->makeJob(['entity_id' => 1, 'entity_type' => 'items'], true)->perform();
    }

    public function testIndexingFailuresPropagate(): void
    {
        $item = $this->createMock(Item::class);
        $item->method('getId')->willReturn(1);
        $this->em->method('find')->willReturn($item);
        $this->vectorIndex->method('indexItem')->willThrowException(new \RuntimeException('boom'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');
        $this->makeJob(['entity_id' => 1, 'entity_type' => 'items'])->perform();
    }

    /** @param array<string, mixed> $args */
    private function makeJob(array $args, bool $shouldStop = false): IndexEntityJob
    {
        $job = $this->getMockBuilder(IndexEntityJob::class)
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
