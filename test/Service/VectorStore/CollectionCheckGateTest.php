<?php
declare(strict_types=1);

namespace SearchAssistantAITest\Service\VectorStore;

use Omeka\Settings\Settings;
use PHPUnit\Framework\TestCase;
use SearchAssistantAI\Service\VectorStore\CollectionCheckGate;
use SearchAssistantAI\Settings\SearchAssistantSettings;

class CollectionCheckGateTest extends TestCase
{
    private const NOW = 1000000;

    /** @var array<string, mixed> */
    private array $stored = [];

    public function testSkipsUntilTheModuleIsConfigured(): void
    {
        self::assertFalse($this->gate()->shouldCheck());
    }

    public function testChecksOnceConfigured(): void
    {
        $this->stored[SearchAssistantSettings::KEY_BACKEND] = 'qdrant';
        self::assertTrue($this->gate()->shouldCheck());
    }

    public function testSkipsOnceVerified(): void
    {
        $this->stored[SearchAssistantSettings::KEY_BACKEND] = 'qdrant';
        $this->gate()->recordSuccess();

        self::assertTrue($this->stored[SearchAssistantSettings::KEY_COLLECTION_VERIFIED]);
        self::assertFalse($this->gate()->shouldCheck());
    }

    public function testWaitsAfterAFailure(): void
    {
        $this->stored[SearchAssistantSettings::KEY_BACKEND] = 'qdrant';
        $this->gate()->recordFailure();

        self::assertFalse($this->gate()->shouldCheck());
        self::assertFalse($this->gate(self::NOW + CollectionCheckGate::RETRY_DELAY - 1)->shouldCheck());
        self::assertTrue($this->gate(self::NOW + CollectionCheckGate::RETRY_DELAY)->shouldCheck());
    }

    public function testSuccessClearsTheRetryDelay(): void
    {
        $this->stored[SearchAssistantSettings::KEY_BACKEND] = 'qdrant';
        $this->gate()->recordFailure();
        $this->gate(self::NOW + CollectionCheckGate::RETRY_DELAY)->recordSuccess();

        self::assertArrayNotHasKey(SearchAssistantSettings::KEY_COLLECTION_RETRY_AT, $this->stored);
    }

    private function gate(int $now = self::NOW): CollectionCheckGate
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('get')->willReturnCallback(function ($id, $default = null) {
            return array_key_exists($id, $this->stored) ? $this->stored[$id] : $default;
        });
        $settings->method('set')->willReturnCallback(function ($id, $value): void {
            $this->stored[$id] = $value;
        });
        $settings->method('delete')->willReturnCallback(function ($id): void {
            unset($this->stored[$id]);
        });
        return new CollectionCheckGate($settings, function () use ($now): int {
            return $now;
        });
    }
}
