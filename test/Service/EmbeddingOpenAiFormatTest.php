<?php
declare(strict_types=1);

namespace SearchAssistantAITest\Service;

use SearchAssistantAI\Service\Embedding\EmbeddingService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionClass;
use RuntimeException;

/**
 * EmbeddingService wire format per API format. Native and hosted-gateway
 * expectations pin the behaviour that existed before the OpenAI port.
 */
class EmbeddingOpenAiFormatTest extends TestCase
{
    private const DIM = 4;

    /** @var MockHandler */
    private $mock;

    /** @var array<int, array{request: RequestInterface}> */
    private $history = [];

    /** @var string */
    private $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/embedding_format_test_' . uniqid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->cacheDir)) {
            rmdir($this->cacheDir);
        }
    }

    private function service(?string $token = null, string $endpointPath = '/embed', string $format = 'native', string $model = '', string $url = 'http://emb:8000'): EmbeddingService
    {
        $service = new EmbeddingService($url, 30.0, $this->cacheDir, self::DIM, $token, $endpointPath, $format, $model);

        $this->mock = new MockHandler();
        $this->history = [];
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));

        $prop = (new ReflectionClass($service))->getProperty('client');
        $prop->setAccessible(true);
        $prop->setValue($service, new Client(['handler' => $stack]));

        return $service;
    }

    /** @return list<float> */
    private function vector(float $v): array
    {
        return array_fill(0, self::DIM, $v);
    }

    private function lastRequest(): RequestInterface
    {
        return end($this->history)['request'];
    }

    /** @return array<string, mixed> */
    private function lastBody(): array
    {
        return json_decode((string) $this->lastRequest()->getBody(), true);
    }

    public function testNativeEmbedPostsTextToEmbed(): void
    {
        $service = $this->service();
        $this->mock->append(new Response(200, [], json_encode(['embeddings' => [$this->vector(0.1)]])));

        self::assertSame($this->vector(0.1), $service->embed('bonjour'));
        self::assertSame('/embed', $this->lastRequest()->getUri()->getPath());
        self::assertSame(['text' => ['bonjour']], $this->lastBody());
    }

    public function testHostedGatewayKeepsEndpointPathAndBearer(): void
    {
        $service = $this->service('tok', '/v1/embed');
        $this->mock->append(new Response(200, [], json_encode(['embeddings' => [$this->vector(0.1)]])));

        $service->embed('bonjour');
        self::assertSame('/v1/embed', $this->lastRequest()->getUri()->getPath());
        self::assertSame(['text' => ['bonjour']], $this->lastBody());
    }

    public function testOpenAiEmbedPostsModelAndInput(): void
    {
        $service = $this->service(null, '/embed', 'openai', 'BAAI/bge-m3');
        $this->mock->append(new Response(200, [], json_encode([
            'data' => [['index' => 0, 'embedding' => $this->vector(0.2)]],
        ])));

        self::assertSame($this->vector(0.2), $service->embed('bonjour'));
        self::assertSame('/v1/embeddings', $this->lastRequest()->getUri()->getPath());
        self::assertSame(['model' => 'BAAI/bge-m3', 'input' => ['bonjour']], $this->lastBody());
    }

    public function testOpenAiBatchReordersByIndex(): void
    {
        $service = $this->service(null, '/embed', 'openai', 'm');
        $this->mock->append(new Response(200, [], json_encode([
            'data' => [
                ['index' => 1, 'embedding' => $this->vector(0.2)],
                ['index' => 0, 'embedding' => $this->vector(0.1)],
            ],
        ])));

        self::assertSame([$this->vector(0.1), $this->vector(0.2)], $service->embedBatch(['a', 'b']));
        self::assertSame(['model' => 'm', 'input' => ['a', 'b']], $this->lastBody());
    }

    public function testOpenAiWithoutModelFails(): void
    {
        $service = $this->service(null, '/embed', 'openai', '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Embeddings model is required');
        $service->embed('bonjour');
    }

    public function testOpenAiStripsV1SuffixFromBaseUrl(): void
    {
        $service = new EmbeddingService('http://emb:8000/v1/', 30.0, $this->cacheDir, self::DIM, null, '/embed', 'openai', 'm');
        $prop = (new ReflectionClass($service))->getProperty('baseUrl');
        $prop->setAccessible(true);
        self::assertSame('http://emb:8000', $prop->getValue($service));
    }

    public function testNativeCacheKeyIsUnchanged(): void
    {
        $service = $this->service();
        $method = (new ReflectionClass($service))->getMethod('getCacheKey');
        $method->setAccessible(true);
        self::assertSame(md5(self::DIM . '|bonjour'), $method->invoke($service, 'bonjour'));
    }

    public function testOpenAiCacheKeyIsScopedByModel(): void
    {
        $a = $this->service(null, '/embed', 'openai', 'model-a');
        $b = new EmbeddingService('http://emb:8000', 30.0, $this->cacheDir, self::DIM, null, '/embed', 'openai', 'model-b');
        $method = (new ReflectionClass(EmbeddingService::class))->getMethod('getCacheKey');
        $method->setAccessible(true);

        self::assertNotSame($method->invoke($a, 'bonjour'), $method->invoke($b, 'bonjour'));
        self::assertNotSame(md5(self::DIM . '|bonjour'), $method->invoke($a, 'bonjour'));
    }

    public function testRateLimitIsRetried(): void
    {
        $service = $this->service(null, '/embed', 'openai', 'm');
        $prop = (new ReflectionClass($service))->getProperty('retryDelays');
        $prop->setAccessible(true);
        $prop->setValue($service, [0, 0, 0]);

        $this->mock->append(
            new Response(429, ['Retry-After' => '0'], '{}'),
            new Response(200, [], json_encode(['data' => [['index' => 0, 'embedding' => $this->vector(0.3)]]]))
        );

        self::assertSame($this->vector(0.3), $service->embed('bonjour'));
        self::assertCount(2, $this->history);
    }
}
