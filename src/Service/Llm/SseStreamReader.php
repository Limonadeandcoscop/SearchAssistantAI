<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\Llm;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Reads a Server-Sent Events body to completion and forwards text fragments.
 *
 * Shared by every SSE-speaking LLM client: Anthropic (ClaudeService), the
 * hosted gateway and OpenAI-compatible servers (OpenAI chat.completions
 * chunks). Understands typed error frames and the OpenAI `data: [DONE]`
 * sentinel; protocol differences live in the $extractDelta callback.
 */
final class SseStreamReader
{
    /**
     * @param callable $extractDelta fn(array $event): ?string, text fragment of one decoded event
     * @param callable|null $onDelta fn(string $textChunk): void, called per non-empty fragment
     * @return string Full concatenated text
     * @throws RuntimeException On an error frame or an empty stream
     */
    public static function read(StreamInterface $body, callable $extractDelta, ?callable $onDelta = null): string
    {
        $buffer = '';
        $fullText = '';

        while (!$body->eof()) {
            $chunk = $body->read(8192);
            if ($chunk === '') {
                continue;
            }
            $buffer .= str_replace("\r", '', $chunk);

            // Complete SSE events are separated by a blank line.
            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $rawEvent = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);

                $data = '';
                foreach (explode("\n", $rawEvent) as $line) {
                    if (strpos($line, 'data:') === 0) {
                        $data .= ($data === '' ? '' : "\n") . ltrim(substr($line, 5));
                    }
                }

                if ($data === '') {
                    continue;
                }
                if ($data === '[DONE]') {
                    return self::assertText($fullText);
                }

                $event = json_decode($data, true);
                if (!is_array($event)) {
                    continue;
                }

                if (($event['type'] ?? '') === 'error') {
                    $message = $event['error']['message'] ?? 'unknown stream error';
                    throw new RuntimeException('LLM stream error: ' . $message);
                }

                $delta = $extractDelta($event);
                if ($delta !== null && $delta !== '') {
                    $fullText .= $delta;
                    if ($onDelta !== null) {
                        $onDelta($delta);
                    }
                }
            }
        }

        return self::assertText($fullText);
    }

    /**
     * OpenAI chat.completions chunk shape: {"choices":[{"delta":{"content":"..."}}]}.
     *
     * @param array<string, mixed> $event
     */
    public static function openAiDelta(array $event): ?string
    {
        if (isset($event['choices'][0]['delta']['content']) && is_string($event['choices'][0]['delta']['content'])) {
            return $event['choices'][0]['delta']['content'];
        }

        return null;
    }

    private static function assertText(string $fullText): string
    {
        if ($fullText === '') {
            throw new RuntimeException('Empty response from LLM stream');
        }

        return $fullText;
    }
}
