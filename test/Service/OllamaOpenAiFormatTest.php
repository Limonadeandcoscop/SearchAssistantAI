<?php
declare(strict_types=1);

namespace SearchAssistantAITest\Service;

use SearchAssistantAI\Service\Llm\OllamaService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionClass;

/**
 * OllamaService wire format per API format: native Ollama (/api/*) vs
 * OpenAI-compatible (/v1/*, e.g. vLLM). The native expectations pin the
 * pre-existing behaviour so the OpenAI port cannot regress it.
 */
class OllamaOpenAiFormatTest extends TestCase
{
    /** @var MockHandler */
    private $mock;

    /** @var array<int, array{request: RequestInterface}> */
    private $history = [];

    private function service(string $format, string $url = 'http://llm:8000'): OllamaService
    {
        $service = new OllamaService($url, 'google/gemma-3-12b-it', 30.0, 'SYSTEM', $format);

        $this->mock = new MockHandler();
        $this->history = [];
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));

        $prop = (new ReflectionClass($service))->getProperty('client');
        $prop->setAccessible(true);
        $prop->setValue($service, new Client(['handler' => $stack]));

        return $service;
    }

    /** @return array<string, mixed> */
    private function lastBody(): array
    {
        $request = end($this->history)['request'];
        return json_decode((string) $request->getBody(), true);
    }

    private function lastPath(): string
    {
        return (string) end($this->history)['request']->getUri();
    }

    // ─── query() ─────────────────────────────────────────────────────

    public function testNativeQueryUsesApiChatAndOptionsTemperature(): void
    {
        $service = $this->service(OllamaService::API_FORMAT_OLLAMA);
        $this->mock->append(new Response(200, [], json_encode(['message' => ['role' => 'assistant', 'content' => 'Salut']])));

        self::assertSame('Salut', $service->query('Bonjour'));
        self::assertSame('api/chat', $this->lastPath());
        $body = $this->lastBody();
        self::assertFalse($body['stream']);
        self::assertSame(['temperature' => 0.2], $body['options']);
        self::assertArrayNotHasKey('temperature', $body);
    }

    public function testOpenAiQueryUsesChatCompletionsAndTopLevelTemperature(): void
    {
        $service = $this->service(OllamaService::API_FORMAT_OPENAI);
        $this->mock->append(new Response(200, [], json_encode([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Salut']]],
        ])));

        self::assertSame('Salut', $service->query('Bonjour'));
        self::assertSame('v1/chat/completions', $this->lastPath());
        $body = $this->lastBody();
        self::assertSame('google/gemma-3-12b-it', $body['model']);
        self::assertFalse($body['stream']);
        self::assertSame(0.2, $body['temperature']);
        self::assertArrayNotHasKey('options', $body);
        self::assertSame('system', $body['messages'][0]['role']);
    }

    public function testUnknownFormatFallsBackToNative(): void
    {
        $service = $this->service('something-else');
        $this->mock->append(new Response(200, [], json_encode(['message' => ['content' => 'ok']])));

        $service->query('Bonjour');
        self::assertSame('api/chat', $this->lastPath());
    }

    public function testOpenAiUrlWithV1SuffixIsNormalised(): void
    {
        $service = new OllamaService('http://llm:8000/v1/', 'm', 30.0, null, OllamaService::API_FORMAT_OPENAI);
        $prop = (new ReflectionClass($service))->getProperty('ollamaUrl');
        $prop->setAccessible(true);
        self::assertSame('http://llm:8000', $prop->getValue($service));
    }

    // ─── queryStream() ───────────────────────────────────────────────

    public function testNativeStreamReadsNdjson(): void
    {
        $service = $this->service(OllamaService::API_FORMAT_OLLAMA);
        $this->mock->append(new Response(200, [], implode("\n", [
            json_encode(['message' => ['content' => 'Bon'], 'done' => false]),
            json_encode(['message' => ['content' => 'jour'], 'done' => false]),
            json_encode(['message' => ['content' => ''], 'done' => true]),
        ]) . "\n"));

        $chunks = [];
        $text = $service->queryStream('Bonjour', [], [], function (string $c) use (&$chunks): void {
            $chunks[] = $c;
        });

        self::assertSame('Bonjour', $text);
        self::assertSame(['Bon', 'jour'], $chunks);
        self::assertSame('api/chat', $this->lastPath());
        self::assertTrue($this->lastBody()['stream']);
    }

    public function testOpenAiStreamReadsSseChunks(): void
    {
        $service = $this->service(OllamaService::API_FORMAT_OPENAI);
        $this->mock->append(new Response(200, ['Content-Type' => 'text/event-stream'],
            "data: {\"choices\":[{\"delta\":{\"role\":\"assistant\"}}]}\n\n"
            . "data: {\"choices\":[{\"delta\":{\"content\":\"Bon\"}}]}\n\n"
            . "data: {\"choices\":[{\"delta\":{\"content\":\"jour\"}}]}\n\n"
            . "data: [DONE]\n\n"
        ));

        $chunks = [];
        $text = $service->queryStream('Bonjour', [], [], function (string $c) use (&$chunks): void {
            $chunks[] = $c;
        });

        self::assertSame('Bonjour', $text);
        self::assertSame(['Bon', 'jour'], $chunks);
        self::assertSame('v1/chat/completions', $this->lastPath());
        $body = $this->lastBody();
        self::assertTrue($body['stream']);
        self::assertSame(0.2, $body['temperature']);
        self::assertSame('google/gemma-3-12b-it', $body['model']);
    }

    // ─── testConnection() ────────────────────────────────────────────

    public function testNativeConnectionListsApiTags(): void
    {
        $service = $this->service(OllamaService::API_FORMAT_OLLAMA);
        $this->mock->append(new Response(200, [], json_encode(['models' => [['name' => 'google/gemma-3-12b-it']]])));

        $result = $service->testConnection();
        self::assertTrue($result['success']);
        self::assertSame('api/tags', $this->lastPath());
        self::assertTrue($result['model_available']);
    }

    public function testOpenAiConnectionListsV1Models(): void
    {
        $service = $this->service(OllamaService::API_FORMAT_OPENAI);
        $this->mock->append(new Response(200, [], json_encode(['data' => [['id' => 'google/gemma-3-12b-it']]])));

        $result = $service->testConnection();
        self::assertTrue($result['success']);
        self::assertSame('v1/models', $this->lastPath());
        self::assertStringContainsString('OpenAI-compatible', $result['message']);
        self::assertTrue($result['model_available']);
    }

    // ─── extractCriteria() ───────────────────────────────────────────

    public function testOpenAiCriteriaExtractionUsesChatCompletions(): void
    {
        $service = $this->service(OllamaService::API_FORMAT_OPENAI);
        $this->mock->append(new Response(200, [], json_encode([
            'choices' => [['message' => ['content' => '{"keywords":["Hugo"]}']]],
        ])));

        $service->extractCriteria('Livres de Victor Hugo');
        self::assertSame('v1/chat/completions', $this->lastPath());
        self::assertSame(0.3, $this->lastBody()['temperature']);
    }
}
