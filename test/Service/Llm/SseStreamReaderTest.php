<?php
declare(strict_types=1);

namespace SearchAssistantAITest\Service\Llm;

use SearchAssistantAI\Service\Llm\SseStreamReader;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * SSE parsing shared by ClaudeService (Anthropic), GatewayLlmService and
 * OllamaService in OpenAI-compatible mode.
 */
class SseStreamReaderTest extends TestCase
{
    public function testReadsAnthropicDeltas(): void
    {
        $body = Utils::streamFor(
            "event: message_start\ndata: {\"type\":\"message_start\"}\n\n"
            . "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"delta\":{\"text\":\"Bon\"}}\n\n"
            . "event: ping\ndata: {\"type\":\"ping\"}\n\n"
            . "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"delta\":{\"text\":\"jour\"}}\n\n"
            . "event: message_stop\ndata: {\"type\":\"message_stop\"}\n\n"
        );

        $chunks = [];
        $text = SseStreamReader::read($body, [$this, 'anthropicDelta'], function (string $c) use (&$chunks): void {
            $chunks[] = $c;
        });

        self::assertSame('Bonjour', $text);
        self::assertSame(['Bon', 'jour'], $chunks);
    }

    public function testReadsOpenAiChunksAndStopsAtDone(): void
    {
        $body = Utils::streamFor(
            "data: {\"choices\":[{\"delta\":{\"role\":\"assistant\"}}]}\n\n"
            . "data: {\"choices\":[{\"delta\":{\"content\":\"Hel\"}}]}\n\n"
            . "data: {\"choices\":[{\"delta\":{\"content\":\"lo\"}}]}\n\n"
            . "data: [DONE]\n\n"
            . "data: {\"choices\":[{\"delta\":{\"content\":\"ignored\"}}]}\n\n"
        );

        self::assertSame('Hello', SseStreamReader::read($body, [SseStreamReader::class, 'openAiDelta']));
    }

    public function testHandlesCrlfAndEventsSplitAcrossReads(): void
    {
        $payload = "data: {\"choices\":[{\"delta\":{\"content\":\"a\"}}]}\r\n\r\n"
            . "data: {\"choices\":[{\"delta\":{\"content\":\"b\"}}]}\r\n\r\n";

        $text = SseStreamReader::read($this->trickleStream($payload, 7), [SseStreamReader::class, 'openAiDelta']);

        self::assertSame('ab', $text);
    }

    public function testErrorFrameThrows(): void
    {
        $body = Utils::streamFor("data: {\"type\":\"error\",\"error\":{\"message\":\"overloaded\"}}\n\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('LLM stream error: overloaded');
        SseStreamReader::read($body, [SseStreamReader::class, 'openAiDelta']);
    }

    public function testEmptyStreamThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Empty response from LLM stream');
        SseStreamReader::read(Utils::streamFor("data: [DONE]\n\n"), [SseStreamReader::class, 'openAiDelta']);
    }

    public function testOpenAiDeltaIgnoresNonTextChunks(): void
    {
        self::assertNull(SseStreamReader::openAiDelta(['choices' => [['delta' => ['role' => 'assistant']]]]));
        self::assertNull(SseStreamReader::openAiDelta(['choices' => [['delta' => ['content' => null]]]]));
        self::assertSame('x', SseStreamReader::openAiDelta(['choices' => [['delta' => ['content' => 'x']]]]));
    }

    /**
     * @param array<string, mixed> $event
     */
    public function anthropicDelta(array $event): ?string
    {
        if (($event['type'] ?? '') === 'content_block_delta' && isset($event['delta']['text'])) {
            return (string) $event['delta']['text'];
        }
        return null;
    }

    /**
     * A stream whose read() returns at most $size bytes, to exercise buffering.
     */
    private function trickleStream(string $payload, int $size): StreamInterface
    {
        return new class(Utils::streamFor($payload), $size) implements StreamInterface {
            use StreamDecoratorTrait;

            /** @var int */
            private $size;

            public function __construct(StreamInterface $stream, int $size)
            {
                $this->stream = $stream;
                $this->size = $size;
            }

            public function read($length): string
            {
                return $this->stream->read(min($length, $this->size));
            }
        };
    }
}
