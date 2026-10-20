<?php
declare(strict_types=1);

namespace SearchAssistantAITest\Service\Search;

use SearchAssistantAI\Service\Search\ResponseFormatter;
use Laminas\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class ResponseFormatterTest extends TestCase
{
    /** @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testFormatSuccessBuildsNumberedSourcesFromPayload(): void
    {
        $formatter = new ResponseFormatter($this->logger);
        $results = [
            ['id' => 1, 'score' => 0.9, 'payload' => [
                'title' => 'Les Misérables',
                'resource_type' => 'item',
                'author' => 'Hugo',
                'date' => '1862',
                'url' => '/items/1',
            ]],
            ['id' => 2, 'score' => 0.7, 'payload' => [
                'title' => 'Notre-Dame',
                'resource_type' => 'item',
                'url' => '/items/2',
            ]],
        ];

        $response = $formatter->formatSuccess('The answer.', $results, '');

        self::assertSame('success', $response['status']);
        self::assertSame('The answer.', $response['answer']);
        self::assertSame([], $response['suggestions']);
        self::assertCount(2, $response['sources']);
        self::assertSame(1, $response['sources'][0]['number']);
        self::assertSame('Les Misérables', $response['sources'][0]['title']);
        self::assertSame('Hugo', $response['sources'][0]['author']);
        self::assertSame('1862', $response['sources'][0]['date']);
        self::assertSame('/items/1', $response['sources'][0]['url']);
        self::assertArrayNotHasKey('author', $response['sources'][1]);
        self::assertArrayNotHasKey('date', $response['sources'][1]);
    }

    public function testFormatSuccessAppliesSiteSlugPrefix(): void
    {
        $formatter = new ResponseFormatter($this->logger);
        $results = [
            ['id' => 1, 'payload' => ['title' => 'X', 'url' => '/items/1']],
        ];
        $response = $formatter->formatSuccess('a', $results, 'en');
        self::assertSame('/s/en/items/1', $response['sources'][0]['url']);
    }

    public function testFormatSuccessStripsLegacySiteSlugBeforeReapplying(): void
    {
        $formatter = new ResponseFormatter($this->logger);
        $results = [
            ['id' => 1, 'payload' => ['title' => 'X', 'url' => '/s/fr/items/42']],
        ];
        $response = $formatter->formatSuccess('a', $results, 'en');
        self::assertSame('/s/en/items/42', $response['sources'][0]['url']);
    }

    public function testFormatSuccessUsesDefaultsForMissingPayloadFields(): void
    {
        $formatter = new ResponseFormatter($this->logger);
        $response = $formatter->formatSuccess('a', [['id' => 3, 'payload' => []]], '');
        self::assertSame('Sans titre', $response['sources'][0]['title']);
        self::assertSame('Unknown', $response['sources'][0]['type']);
        self::assertSame(0.0, $response['sources'][0]['score']);
        self::assertArrayNotHasKey('url', $response['sources'][0]);
    }

    public function testFormatNoResultsReturnsSuggestionsForPresentCriteria(): void
    {
        $formatter = new ResponseFormatter($this->logger);
        $response = $formatter->formatNoResults('q', [
            'keywords' => ['x'],
            'author' => 'Hugo',
            'dateRange' => ['start' => '1800', 'end' => '1900'],
            'type' => 'book',
        ]);
        self::assertSame('success', $response['status']);
        self::assertSame([], $response['sources']);
        self::assertNotEmpty($response['suggestions']);
        // Each present criterion drives at least one suggestion.
        self::assertGreaterThanOrEqual(4, count($response['suggestions']));
    }

    public function testFormatNoResultsFallsBackWhenCriteriaEmpty(): void
    {
        $formatter = new ResponseFormatter($this->logger);
        $response = $formatter->formatNoResults('q', []);
        self::assertNotEmpty($response['suggestions']);
    }

    public function testSuggestionsForResultsOmitsFiltersAlreadyApplied(): void
    {
        $formatter = new ResponseFormatter($this->logger);
        $bare = $formatter->suggestionsForResults([]);
        $withAuthor = $formatter->suggestionsForResults(['author' => 'Hugo']);
        $withBoth = $formatter->suggestionsForResults(['author' => 'x', 'dateRange' => []]);

        self::assertContains('Filtrez par auteur pour des résultats plus ciblés', $bare);
        self::assertNotContains('Filtrez par auteur pour des résultats plus ciblés', $withAuthor);
        // `dateRange` present (even empty array) is still "set" for this helper.
        self::assertContains('Ajoutez une plage de dates pour affiner les résultats', $withAuthor);
        self::assertCount(2, $withBoth);
    }

    public function testPayloadMetricsComputesSizesAndTokenEstimate(): void
    {
        $formatter = new ResponseFormatter($this->logger);
        $results = [
            ['payload' => ['title' => 'A', 'description' => 'desc']],
            ['payload' => ['title' => 'B']],
        ];
        $metrics = $formatter->payloadMetrics($results);

        self::assertGreaterThan(0, $metrics['total_size_bytes']);
        self::assertSame(round($metrics['total_size_bytes'] / 1024, 2), $metrics['total_size_kb']);
        self::assertSame((int) ceil($metrics['total_size_bytes'] / 4), $metrics['estimated_tokens']);
        self::assertSame((int) round($metrics['total_size_bytes'] / 2), $metrics['avg_result_size_bytes']);
        self::assertGreaterThanOrEqual($metrics['avg_result_size_bytes'], $metrics['max_result_size_bytes']);
        self::assertTrue($metrics['individual_sizes'][0]['has_description']);
        self::assertFalse($metrics['individual_sizes'][1]['has_description']);
    }

    public function testPayloadMetricsOnEmptyArray(): void
    {
        $formatter = new ResponseFormatter($this->logger);
        $metrics = $formatter->payloadMetrics([]);
        self::assertSame(0, $metrics['total_size_bytes']);
        self::assertSame(0.0, $metrics['total_size_kb']);
        self::assertSame(0, $metrics['estimated_tokens']);
        self::assertSame(0, $metrics['avg_result_size_bytes']);
        self::assertSame(0, $metrics['max_result_size_bytes']);
        self::assertSame([], $metrics['individual_sizes']);
    }
}
