<?php
declare(strict_types=1);

namespace SearchAssistantAITest\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SearchAssistantAI\Service\VectorStore\ElasticsearchService;
use SearchAssistantAI\Service\VectorStore\SearchFilter;
use SearchAssistantAI\Service\VectorStore\VectorStoreInterface;

/**
 * ElasticsearchService against a mocked HTTP layer: the requests it sends
 * (paths, bodies, headers) and how it maps the answers to the
 * VectorStoreInterface contract.
 */
class ElasticsearchServiceTest extends TestCase
{
    private MockHandler $mock;

    /** @var list<array{request: Request, options: array<string, mixed>}> */
    private array $history = [];

    private function service(?string $apiKey = null, bool $verifySsl = true): ElasticsearchService
    {
        $this->mock = new MockHandler();
        $this->history = [];
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        if ($apiKey !== null) {
            $headers['Authorization'] = 'ApiKey ' . $apiKey;
        }
        $client = new Client([
            'handler' => $stack,
            'base_uri' => 'http://es:9200/',
            'verify' => $verifySsl,
            'headers' => $headers,
        ]);
        return new ElasticsearchService('http://es:9200', $apiKey, 5.0, $verifySsl, null, $client);
    }

    private function lastRequest(): Request
    {
        self::assertNotEmpty($this->history, 'no request was sent');
        return $this->history[count($this->history) - 1]['request'];
    }

    /** @return array<string, mixed> */
    private function lastJson(): array
    {
        return json_decode((string) $this->lastRequest()->getBody(), true);
    }

    public function testImplementsVectorStoreInterface(): void
    {
        self::assertInstanceOf(VectorStoreInterface::class, $this->service());
    }

    public function testDefaultClientSendsApiKeyAndSslOptions(): void
    {
        $service = new ElasticsearchService('https://secure:9200/', 'k3y', 2.5, false);
        $client = (new \ReflectionProperty($service, 'client'));
        $client->setAccessible(true);
        /** @var Client $guzzle */
        $guzzle = $client->getValue($service);

        self::assertSame('https://secure:9200/', (string) $guzzle->getConfig('base_uri'));
        self::assertFalse($guzzle->getConfig('verify'));
        self::assertSame('ApiKey k3y', $guzzle->getConfig('headers')['Authorization']);
        self::assertSame(2.5, $guzzle->getConfig('timeout'));
    }

    public function testDefaultClientHasNoAuthorizationWithoutKey(): void
    {
        $service = new ElasticsearchService('http://es:9200', '');
        $client = (new \ReflectionProperty($service, 'client'));
        $client->setAccessible(true);
        /** @var Client $guzzle */
        $guzzle = $client->getValue($service);

        self::assertArrayNotHasKey('Authorization', $guzzle->getConfig('headers'));
        self::assertTrue($guzzle->getConfig('verify'));
    }

    // ─── collections ─────────────────────────────────────────────

    public function testCreateCollectionPutsADenseVectorMapping(): void
    {
        $service = $this->service();
        $this->mock->append(new Response(200, [], '{"acknowledged":true}'));

        self::assertTrue($service->createCollection('omeka_resources', 1024, 'Cosine'));
        self::assertSame('PUT', $this->lastRequest()->getMethod());
        self::assertSame('/omeka_resources', $this->lastRequest()->getUri()->getPath());
        $vector = $this->lastJson()['mappings']['properties']['vector'];
        self::assertSame(['type' => 'dense_vector', 'dims' => 1024, 'index' => true, 'similarity' => 'cosine'], $vector);
    }

    public function testCreateCollectionMapsDistances(): void
    {
        $service = $this->service();
        $this->mock->append(new Response(200, [], '{}'));
        $service->createCollection('c', 3, 'Dot');
        self::assertSame('dot_product', $this->lastJson()['mappings']['properties']['vector']['similarity']);
    }

    public function testCreateCollectionRejectsBadArguments(): void
    {
        $service = $this->service();
        try {
            $service->createCollection(' ', 3);
            self::fail('empty name accepted');
        } catch (InvalidArgumentException $e) {
            self::assertSame('Collection name cannot be empty', $e->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $service->createCollection('c', 0);
    }

    public function testCreateCollectionReportsExistingIndex(): void
    {
        $service = $this->service();
        $this->mock->append(new Response(400, [], '{"error":{"type":"resource_already_exists_exception","reason":"index [c] already exists"}}'));

        self::assertFalse($service->createCollection('c', 3));
    }

    public function testDeleteCollection(): void
    {
        $service = $this->service();
        $this->mock->append(new Response(200, [], '{"acknowledged":true}'));
        self::assertTrue($service->deleteCollection('c'));
        self::assertSame('DELETE', $this->lastRequest()->getMethod());
        self::assertSame('/c', $this->lastRequest()->getUri()->getPath());

        $this->mock->append(new Response(404, [], '{"error":{"type":"index_not_found_exception"}}'));
        self::assertFalse($service->deleteCollection('missing'));
    }

    public function testGetCollectionInfoReadsMappingAndCount(): void
    {
        $service = $this->service();
        $this->mock->append(new Response(200, [], json_encode([
            'c' => ['mappings' => ['properties' => ['vector' => ['type' => 'dense_vector', 'dims' => 1024, 'similarity' => 'cosine']]]],
        ])));
        $this->mock->append(new Response(200, [], '{"count":42}'));

        $info = $service->getCollectionInfo('c');

        self::assertSame(1024, $info['result']['config']['params']['vectors']['size']);
        self::assertSame(42, $info['result']['points_count']);
        self::assertSame('/c/_mapping', $this->history[0]['request']->getUri()->getPath());
        self::assertSame('/c/_count', $this->history[1]['request']->getUri()->getPath());
    }

    public function testGetCollectionInfoIsNullForMissingIndex(): void
    {
        $service = $this->service();
        $this->mock->append(new Response(404, [], '{"error":{"type":"index_not_found_exception","reason":"no such index [c]"}}'));

        self::assertNull($service->getCollectionInfo('c'));
    }

    public function testGetCollectionInfoFailsExplicitlyWhenUnreachable(): void
    {
        $service = $this->service();
        $retries = new \ReflectionProperty($service, 'retryDelays');
        $retries->setAccessible(true);
        $retries->setValue($service, [0, 0, 0]);
        for ($i = 0; $i < 3; $i++) {
            $this->mock->append(new ConnectException('refused', new Request('GET', 'c/_mapping')));
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to connect to Elasticsearch after 3 attempts');
        $service->getCollectionInfo('c');
    }

    // ─── documents ───────────────────────────────────────────────

    public function testUpsertPointIndexesVectorAndPayloadUnderTheResourceId(): void
    {
        $service = $this->service();
        $this->mock->append(new Response(201, [], '{"result":"created"}'));

        self::assertTrue($service->upsertPoint('c', ['id' => 17, 'vector' => [0.1, 0.2], 'payload' => ['type' => 'item']]));
        self::assertSame('PUT', $this->lastRequest()->getMethod());
        self::assertSame('/c/_doc/17', $this->lastRequest()->getUri()->getPath());
        self::assertSame(['vector' => [0.1, 0.2], 'payload' => ['type' => 'item']], $this->lastJson());
    }

    public function testUpsertPointRequiresAVector(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->upsertPoint('c', ['id' => 1, 'payload' => []]);
    }

    public function testBulkUpsertSendsNdjson(): void
    {
        $service = $this->service();
        $this->mock->append(new Response(200, [], '{"errors":false,"items":[]}'));

        self::assertTrue($service->bulkUpsert('c', [
            ['id' => 1, 'vector' => [0.1], 'payload' => ['type' => 'item']],
            ['id' => 2, 'vector' => [0.2]],
        ]));
        $request = $this->lastRequest();
        self::assertSame('/_bulk', $request->getUri()->getPath());
        self::assertSame('application/x-ndjson', $request->getHeaderLine('Content-Type'));
        $lines = explode("\n", rtrim((string) $request->getBody(), "\n"));
        self::assertCount(4, $lines);
        self::assertSame(['index' => ['_index' => 'c', '_id' => '1']], json_decode($lines[0], true));
        self::assertSame(['vector' => [0.1], 'payload' => ['type' => 'item']], json_decode($lines[1], true));
        self::assertSame(['vector' => [0.2], 'payload' => []], json_decode($lines[3], true));
    }

    public function testBulkUpsertReportsItemErrors(): void
    {
        $service = $this->service();
        $this->mock->append(new Response(200, [], json_encode([
            'errors' => true,
            'items' => [['index' => ['_id' => '1', 'status' => 201]], ['index' => ['_id' => '2', 'error' => ['type' => 'mapper_parsing_exception']]]],
        ])));

        self::assertFalse($service->bulkUpsert('c', [['id' => 1, 'vector' => [0.1]], ['id' => 2, 'vector' => [0.2]]]));
    }

    public function testBulkUpsertRejectsEmptyBatch(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->bulkUpsert('c', []);
    }

    public function testDeletePointIsIdempotent(): void
    {
        $service = $this->service();
        $this->mock->append(new Response(200, [], '{"result":"deleted"}'));
        self::assertTrue($service->deletePoint('c', 5));
        self::assertSame('/c/_doc/5', $this->lastRequest()->getUri()->getPath());

        $this->mock->append(new Response(404, [], '{"result":"not_found"}'));
        self::assertTrue($service->deletePoint('c', 5));
    }

    public function testCountPointsMatchesPayloadFields(): void
    {
        $service = $this->service();
        $this->mock->append(new Response(200, [], '{"count":7}'));

        self::assertSame(7, $service->countPoints('c', ['type' => 'media']));
        self::assertSame('/c/_count', $this->lastRequest()->getUri()->getPath());
        self::assertSame(['query' => ['bool' => ['must' => [['match' => ['payload.type' => 'media']]]]]], $this->lastJson());

        $this->mock->append(new Response(200, [], '{"count":9}'));
        self::assertSame(9, $service->countPoints('c'));
        self::assertSame('', (string) $this->lastRequest()->getBody());
    }

    // ─── search ──────────────────────────────────────────────────

    public function testSearchRunsAKnnQueryAndNormalisesHits(): void
    {
        $service = $this->service();
        $this->mock->append(new Response(200, [], json_encode(['hits' => ['hits' => [
            ['_id' => '12', '_score' => 0.91, '_source' => ['payload' => ['title' => 'A']]],
            ['_id' => '7', '_score' => 0.5, '_source' => []],
        ]]])));

        $results = $service->search('c', [0.1, 0.2], 5, 0.4);

        self::assertSame('/c/_search', $this->lastRequest()->getUri()->getPath());
        $body = $this->lastJson();
        self::assertSame(['field' => 'vector', 'query_vector' => [0.1, 0.2], 'k' => 5, 'num_candidates' => 100], $body['knn']);
        self::assertSame(5, $body['size']);
        self::assertSame(0.4, $body['min_score']);
        self::assertSame(['payload'], $body['_source']);
        self::assertSame([
            ['id' => 12, 'score' => 0.91, 'payload' => ['title' => 'A']],
            ['id' => 7, 'score' => 0.5, 'payload' => []],
        ], $results);
    }

    public function testSearchAppliesTheFilterAndSkipsEmptyOnes(): void
    {
        $service = $this->service();
        $this->mock->append(new Response(200, [], '{"hits":{"hits":[]}}'));
        $service->search('c', [0.1], 3, null, SearchFilter::fromPublicInput(['type' => 'item']));
        self::assertSame(['bool' => ['must' => [['match' => ['payload.type' => 'item']]]]], $this->lastJson()['knn']['filter']);
        self::assertArrayNotHasKey('min_score', $this->lastJson());

        $this->mock->append(new Response(200, [], '{"hits":{"hits":[]}}'));
        $service->search('c', [0.1], 3, null, SearchFilter::empty());
        self::assertArrayNotHasKey('filter', $this->lastJson()['knn']);
    }

    public function testSearchRejectsNonPositiveLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->search('c', [0.1], 0);
    }

    public function testSearchReturnsNothingOnClientError(): void
    {
        $service = $this->service();
        $this->mock->append(new Response(400, [], '{"error":{"reason":"bad knn"}}'));

        self::assertSame([], $service->search('c', [0.1], 3));
    }

    // ─── health ──────────────────────────────────────────────────

    public function testIsHealthyReadsTheRootDocument(): void
    {
        $service = $this->service();
        $this->mock->append(new Response(200, [], '{"cluster_name":"docker-cluster","version":{"number":"8.11.3"}}'));
        self::assertTrue($service->isHealthy());
        self::assertSame('/', $this->lastRequest()->getUri()->getPath());

        $this->mock->append(new Response(200, [], '{"hello":"world"}'));
        self::assertFalse($service->isHealthy());
    }

    public function testIsHealthyIsFalseWhenUnreachable(): void
    {
        $service = $this->service();
        $retries = new \ReflectionProperty($service, 'retryDelays');
        $retries->setAccessible(true);
        $retries->setValue($service, [0, 0, 0]);
        for ($i = 0; $i < 3; $i++) {
            $this->mock->append(new ConnectException('refused', new Request('GET', '')));
        }

        self::assertFalse($service->isHealthy());
    }
}
