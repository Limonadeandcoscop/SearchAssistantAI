<?php
declare(strict_types=1);

namespace SearchAssistantAITest\Service\Indexing;

use SearchAssistantAI\Service\Indexing\SyncPolicy;
use Laminas\Log\LoggerInterface;
use Omeka\Entity\Item;
use Omeka\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure routing policy extracted from SyncService.
 *
 * Covers:
 *   - getSyncMode() — reads search_assistant_sync_mode setting, falls back to
 *     'asynchronous' on missing or invalid values
 *   - shouldProcessAsync() — routes heavy entities (many properties / many
 *     media on items) to the queue, light ones inline; fails closed to
 *     async on errors
 */
class SyncPolicyTest extends TestCase
{
    /** @var Settings&\PHPUnit\Framework\MockObject\MockObject */
    private $settings;

    /** @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $logger;

    protected function setUp(): void
    {
        $this->settings = $this->createMock(Settings::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testGetSyncModeReadsConfiguredValue(): void
    {
        $this->settings->method('get')->willReturn('synchronous');
        $policy = new SyncPolicy($this->settings, $this->logger);
        self::assertSame('synchronous', $policy->getSyncMode());
    }

    public function testGetSyncModeFallsBackOnInvalidValue(): void
    {
        $this->settings->method('get')->willReturn('garbage');
        $policy = new SyncPolicy($this->settings, $this->logger);
        self::assertSame('asynchronous', $policy->getSyncMode());
    }

    public function testGetSyncModeFallsBackOnMissingValue(): void
    {
        // Settings::get() returns its second argument (default) when unset.
        $this->settings->method('get')->willReturnArgument(1);
        $policy = new SyncPolicy($this->settings, $this->logger);
        self::assertSame('asynchronous', $policy->getSyncMode());
    }

    public function testShouldProcessAsyncFalseForLightEntity(): void
    {
        $entity = new class {
            public function getValues(): array { return array_fill(0, 3, 'x'); }
        };
        $policy = new SyncPolicy($this->settings, $this->logger, 50, 10);
        self::assertFalse($policy->shouldProcessAsync($entity));
    }

    public function testShouldProcessAsyncTrueWhenPropertyCountAboveThreshold(): void
    {
        $entity = new class {
            public function getValues(): array { return array_fill(0, 60, 'x'); }
        };
        $policy = new SyncPolicy($this->settings, $this->logger, 50, 10);
        self::assertTrue($policy->shouldProcessAsync($entity));
    }

    public function testShouldProcessAsyncPropertyThresholdIsStrictlyGreater(): void
    {
        $entity = new class {
            public function getValues(): array { return array_fill(0, 50, 'x'); }
        };
        $policy = new SyncPolicy($this->settings, $this->logger, 50, 10);
        self::assertFalse($policy->shouldProcessAsync($entity), 'exactly at threshold stays inline');
    }

    public function testShouldProcessAsyncTrueForItemWithManyMedia(): void
    {
        $item = $this->createMock(Item::class);
        $item->method('getValues')->willReturn(array_fill(0, 2, 'x'));
        $item->method('getMedia')->willReturn(array_fill(0, 15, 'm'));
        $policy = new SyncPolicy($this->settings, $this->logger, 50, 10);
        self::assertTrue($policy->shouldProcessAsync($item));
    }

    public function testShouldProcessAsyncMediaThresholdOnlyAppliesToItems(): void
    {
        // Non-Item entity with many "media"-like things stays inline (policy
        // explicitly checks instanceof Item).
        $entity = new class {
            public function getValues(): array { return []; }
            public function getMedia(): array { return array_fill(0, 50, 'm'); }
        };
        $policy = new SyncPolicy($this->settings, $this->logger);
        self::assertFalse($policy->shouldProcessAsync($entity));
    }

    public function testShouldProcessAsyncDefaultsToAsyncOnError(): void
    {
        $entity = new class {
            public function getValues(): array { throw new \RuntimeException('boom'); }
        };
        $policy = new SyncPolicy($this->settings, $this->logger);
        self::assertTrue($policy->shouldProcessAsync($entity));
    }

    public function testShouldProcessAsyncTreatsEntityWithoutGetValuesAsLight(): void
    {
        $policy = new SyncPolicy($this->settings, $this->logger);
        self::assertFalse($policy->shouldProcessAsync(new \stdClass()));
    }
}
