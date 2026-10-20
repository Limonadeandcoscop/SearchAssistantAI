<?php
declare(strict_types=1);

namespace SearchAssistantAITest\Service\Indexing;

use SearchAssistantAI\Service\Indexing\PayloadBuilder;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PayloadBuilder — the pure resource → payload/metadata/text
 * transformation extracted out of VectorIndexService in v1.1.
 *
 * Uses hand-rolled anonymous classes instead of mocks because the Omeka API
 * representation surface (`values() -> ValueRepresentation[]`, each with
 * `->property() -> PropertyRepresentation -> id()/term()`) is annoying to
 * express through PHPUnit's mock builder and the builder does nothing fake
 * fixtures don't already do.
 */
class PayloadBuilderTest extends TestCase
{
    public function testExtractMetadataFromApiRepresentation(): void
    {
        $resource = $this->fakeApiResource(
            42,
            'Les Misérables',
            'Roman français',
            '/items/42',
            [
                'dcterms:creator' => [['Hugo', 1]],
                'dcterms:date' => [['1862', 2]],
            ],
        );

        $builder = new PayloadBuilder();
        $metadata = $builder->extractMetadata($resource);

        self::assertSame('Les Misérables', $metadata['title']);
        self::assertSame('Roman français', $metadata['description']);
        self::assertSame(['Hugo'], $metadata['dcterms:creator']);
        self::assertSame(['1862'], $metadata['dcterms:date']);
    }

    public function testExtractMetadataMultiValueIsPreserved(): void
    {
        $resource = $this->fakeApiResource(
            1,
            'X',
            '',
            '/items/1',
            [
                'dcterms:subject' => [['Art', 10], ['History', 10]],
            ],
        );
        $builder = new PayloadBuilder();
        $metadata = $builder->extractMetadata($resource);
        self::assertSame(['Art', 'History'], $metadata['dcterms:subject']);
    }

    public function testExtractMetadataRespectsPropertyAllowlist(): void
    {
        $resource = $this->fakeApiResource(
            1,
            'X',
            '',
            '',
            [
                'dcterms:creator' => [['Hugo', 1]],
                'dcterms:date' => [['1862', 2]],
                'dcterms:subject' => [['Art', 3]],
            ],
        );

        $builder = new PayloadBuilder(['1', 3]);   // allow creator + subject only
        $metadata = $builder->extractMetadata($resource);

        self::assertArrayHasKey('dcterms:creator', $metadata);
        self::assertArrayHasKey('dcterms:subject', $metadata);
        self::assertArrayNotHasKey('dcterms:date', $metadata);
    }

    public function testSetIndexedPropertyIdsReplacesTheAllowlist(): void
    {
        $resource = $this->fakeApiResource(
            1,
            'X',
            '',
            '',
            [
                'dcterms:creator' => [['Hugo', 1]],
                'dcterms:date' => [['1862', 2]],
            ],
        );

        $builder = new PayloadBuilder([1]);
        $builder->setIndexedPropertyIds([2]);
        $metadata = $builder->extractMetadata($resource);

        self::assertArrayNotHasKey('dcterms:creator', $metadata);
        self::assertArrayHasKey('dcterms:date', $metadata);
    }

    public function testGenerateCompositeTextLabelsEachField(): void
    {
        $builder = new PayloadBuilder();
        $text = $builder->generateCompositeText([
            'title' => 'Les Misérables',
            'description' => 'Roman',
            'dcterms:creator' => ['Hugo'],
            'dcterms:subject' => ['Art', 'History'],
        ], 'item');

        $expected = "Title: Les Misérables\n"
            . "Description: Roman\n"
            . "Creator: Hugo\n"
            . "Subject: Art, History\n"
            . "Resource Type: item";
        self::assertSame($expected, $text);
    }

    public function testGenerateCompositeTextSkipsEmptyAndItemSets(): void
    {
        $builder = new PayloadBuilder();
        $text = $builder->generateCompositeText([
            'title' => 'X',
            'description' => '',
            'item_sets' => [['id' => 5, 'title' => 'Set', 'url' => '']],
        ], '');
        self::assertSame('Title: X', $text);
    }

    public function testBuildPayloadIncludesUrlFromApiRepresentation(): void
    {
        $resource = $this->fakeApiResource(
            42,
            'X',
            'd',
            '/items/42',
            [
                'dcterms:creator' => [['Hugo', 1]],
                'dcterms:date' => [['1862', 2]],
                'dcterms:subject' => [['Art', 3]],
            ],
        );

        $builder = new PayloadBuilder();
        $metadata = $builder->extractMetadata($resource);
        $payload = $builder->buildPayload($resource, $metadata, 'item');

        self::assertSame(42, $payload['id']);
        self::assertSame('item', $payload['type']);
        self::assertSame('X', $payload['title']);
        self::assertSame('d', $payload['description']);
        self::assertSame('/items/42', $payload['url']);
        self::assertSame('Hugo', $payload['creator']);
        self::assertSame('1862', $payload['date']);
        self::assertSame('Art', $payload['subject']);
        self::assertSame('Hugo', $payload['properties']['dcterms:creator']);
        self::assertArrayHasKey('item_sets', $payload);
    }

    public function testBuildPayloadFallsBackToGeneratedUrlForEntitiesWithoutUrl(): void
    {
        $entity = new class {
            public function getId(): int { return 7; }
            public function getTitle(): string { return 'T'; }
            public function getValues(): array { return []; }
        };

        $builder = new PayloadBuilder();
        $metadata = $builder->extractMetadata($entity);

        self::assertSame('/item/7', $builder->buildPayload($entity, $metadata, 'item')['url']);
        self::assertSame('/media/7', $builder->buildPayload($entity, $metadata, 'media')['url']);
        self::assertSame('/item-set/7', $builder->buildPayload($entity, $metadata, 'collection')['url']);
        self::assertSame('', $builder->buildPayload($entity, $metadata, 'unknown')['url']);
    }

    public function testBuildPayloadOmitsItemSetsForNonItems(): void
    {
        $resource = $this->fakeApiResource(1, 'X', '', '/x', []);
        $builder = new PayloadBuilder();
        $payload = $builder->buildPayload($resource, $builder->extractMetadata($resource), 'media');
        self::assertArrayNotHasKey('item_sets', $payload);
    }

    public function testGetEntityIdPrefersApiId(): void
    {
        $api = new class { public function id(): int { return 11; } };
        $entity = new class { public function getId(): int { return 22; } };
        $neither = new \stdClass();

        $builder = new PayloadBuilder();
        self::assertSame(11, $builder->getEntityId($api));
        self::assertSame(22, $builder->getEntityId($entity));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Entity must expose id() or getId()');
        $builder->getEntityId($neither);
    }

    public function testGetEntityTitleFallsBackToUntitled(): void
    {
        $builder = new PayloadBuilder();
        self::assertSame('Untitled', $builder->getEntityTitle(new \stdClass()));
        self::assertSame(
            'Untitled',
            $builder->getEntityTitle(new class { public function getTitle(): ?string { return null; } })
        );
        self::assertSame(
            'Set',
            $builder->getEntityTitle(new class { public function displayTitle(): string { return 'Set'; } })
        );
    }

    // ─────────────────────────────────────────────────────────────
    // helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * @param array<string, array<int, array{0: string, 1: int}>> $values
     *        term => list of [value, propertyId] pairs
     */
    private function fakeApiResource(int $id, string $title, string $description, string $url, array $values): object
    {
        $valueReps = [];
        foreach ($values as $term => $pairs) {
            $propertyId = $pairs[0][1] ?? 0;
            $property = new class($term, $propertyId) {
                private $term;
                private $id;
                public function __construct(string $term, int $id) { $this->term = $term; $this->id = $id; }
                public function id(): int { return $this->id; }
                public function term(): string { return $this->term; }
            };
            $valueReps[] = new class($property, array_column($pairs, 0)) {
                private $property;
                private $strings;
                public function __construct(object $property, array $strings) { $this->property = $property; $this->strings = $strings; }
                public function property(): object { return $this->property; }
                public function values(): array { return $this->strings; }
            };
        }

        return new class($id, $title, $description, $url, $valueReps) {
            private $id;
            private $title;
            private $description;
            private $url;
            private $valueReps;
            public function __construct(int $id, string $title, string $description, string $url, array $valueReps)
            {
                $this->id = $id;
                $this->title = $title;
                $this->description = $description;
                $this->url = $url;
                $this->valueReps = $valueReps;
            }
            public function id(): int { return $this->id; }
            public function displayTitle(): string { return $this->title; }
            public function displayDescription(): string { return $this->description; }
            public function url(): string { return $this->url; }
            public function values(): array { return $this->valueReps; }
            public function resourceClass(): ?object { return null; }
            public function itemSets(): array { return []; }
        };
    }
}
