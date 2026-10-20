<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\VectorStore;

/**
 * The module's canonical filter DSL — backend-agnostic.
 *
 * `SemanticSearchService` builds one of these from the public chat-API
 * filter input and hands it to `VectorStoreInterface::search()`. Each
 * backend translates internally via its own `*FilterBuilder`. The
 * Qdrant-shaped `{must, should}` structure no longer crosses any
 * interface boundary outside the Qdrant code path itself.
 *
 * Three independent filter components, ANDed together at match time:
 *
 *   mustMatch  — list of exact-match conditions on payload fields.
 *   ranges     — list of range conditions (gte/lte) on payload fields.
 *   anyText    — optional OR-group: at least one of the given `terms`
 *                must match at least one of the given `fields`. Used
 *                for hybrid keyword search.
 *
 * Immutable by design — `withAnyText()` returns a new instance.
 */
final class SearchFilter
{
    /** Public input keys that translate 1:1 to an exact-match condition. */
    private const EXACT_MATCH_FIELDS = [
        'type', 'resource_type', 'visibility', 'author', 'subject',
    ];

    /** @var list<array{field: string, value: mixed}> */
    public $mustMatch;

    /** @var list<array{field: string, gte?: string, lte?: string}> */
    public $ranges;

    /** @var array{fields: list<string>, terms: list<string>}|null */
    public $anyText;

    /**
     * @param list<array{field: string, value: mixed}> $mustMatch
     * @param list<array{field: string, gte?: string, lte?: string}> $ranges
     * @param array{fields: list<string>, terms: list<string>}|null $anyText
     */
    private function __construct(array $mustMatch, array $ranges, ?array $anyText)
    {
        $this->mustMatch = $mustMatch;
        $this->ranges = $ranges;
        $this->anyText = $anyText;
    }

    public static function empty(): self
    {
        return new self([], [], null);
    }

    /**
     * Parse the module's public chat-API filter input.
     *
     * Accepted keys:
     *   type | resource_type | visibility | author | subject  → exact match
     *   date_range => { gte?, lte? }                          → range on created_date
     *
     * Anything else is ignored; the caller's InputFilter polices shape.
     *
     * @param array<string, mixed> $input
     */
    public static function fromPublicInput(array $input): self
    {
        $mustMatch = [];
        $ranges = [];

        foreach ($input as $key => $value) {
            if (in_array($key, self::EXACT_MATCH_FIELDS, true)) {
                $mustMatch[] = ['field' => $key, 'value' => $value];
                continue;
            }

            if ($key === 'date_range' && is_array($value)) {
                $range = ['field' => 'created_date'];
                if (isset($value['gte'])) {
                    $range['gte'] = $value['gte'];
                }
                if (isset($value['lte'])) {
                    $range['lte'] = $value['lte'];
                }
                if (isset($range['gte']) || isset($range['lte'])) {
                    $ranges[] = $range;
                }
            }
        }

        return new self($mustMatch, $ranges, null);
    }

    /**
     * Add an "any of these terms in any of these fields" OR-group.
     * Used by hybrid keyword search. Returns a new instance.
     *
     * @param list<string> $fields
     * @param list<string> $terms
     */
    public function withAnyText(array $fields, array $terms): self
    {
        $cleanTerms = array_values(array_filter(
            $terms,
            static fn ($t): bool => is_string($t) && $t !== ''
        ));
        return new self(
            $this->mustMatch,
            $this->ranges,
            ['fields' => array_values($fields), 'terms' => $cleanTerms]
        );
    }

    public function isEmpty(): bool
    {
        return empty($this->mustMatch)
            && empty($this->ranges)
            && ($this->anyText === null || empty($this->anyText['terms']));
    }

    /**
     * Neutral wire representation consumed by the hosted gateway's search
     * endpoint, which translates it to the backend's native filter DSL
     * server-side. Mirrors the three components 1:1.
     *
     * @return array{must_match: list<array{field: string, value: mixed}>, ranges: list<array{field: string, gte?: string, lte?: string}>, any_text?: array{fields: list<string>, terms: list<string>}}
     */
    public function toArray(): array
    {
        $out = [
            'must_match' => $this->mustMatch,
            'ranges' => $this->ranges,
        ];
        if ($this->anyText !== null && !empty($this->anyText['terms'])) {
            $out['any_text'] = $this->anyText;
        }
        return $out;
    }
}
