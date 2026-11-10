<?php
declare(strict_types=1);

namespace SearchAssistantAITest\Service\VectorStore;

use PHPUnit\Framework\TestCase;
use SearchAssistantAI\Service\VectorStore\ElasticsearchFilterBuilder;
use SearchAssistantAI\Service\VectorStore\SearchFilter;

class ElasticsearchFilterBuilderTest extends TestCase
{
    public function testEmptyFilterGivesNull(): void
    {
        self::assertNull((new ElasticsearchFilterBuilder())->fromSearchFilter(SearchFilter::empty()));
    }

    public function testExactMatchesAndRangesBecomeMustClauses(): void
    {
        $filter = SearchFilter::fromPublicInput([
            'type' => 'item',
            'author' => 'Hugo',
            'date_range' => ['gte' => '1800', 'lte' => '1899'],
        ]);

        self::assertSame(['bool' => ['must' => [
            ['match' => ['payload.type' => 'item']],
            ['match' => ['payload.author' => 'Hugo']],
            ['range' => ['payload.created_date' => ['gte' => '1800', 'lte' => '1899']]],
        ]]], (new ElasticsearchFilterBuilder())->fromSearchFilter($filter));
    }

    public function testAnyTextBecomesAShouldGroup(): void
    {
        $filter = SearchFilter::fromPublicInput(['type' => 'item'])->withAnyText(['title', 'description'], ['marine', 'corse']);

        $query = (new ElasticsearchFilterBuilder())->fromSearchFilter($filter);

        self::assertSame([['match' => ['payload.type' => 'item']]], $query['bool']['must']);
        self::assertSame(1, $query['bool']['minimum_should_match']);
        self::assertSame([
            ['match' => ['payload.title' => 'marine']],
            ['match' => ['payload.description' => 'marine']],
            ['match' => ['payload.title' => 'corse']],
            ['match' => ['payload.description' => 'corse']],
        ], $query['bool']['should']);
    }
}
