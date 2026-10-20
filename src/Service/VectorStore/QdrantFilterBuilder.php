<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\VectorStore;

/**
 * Translate the module's public "metadata filter" input shape
 * (plain associative array from the Chat API) into Qdrant's native
 * `must`-condition filter structure.
 *
 * Accepted keys:
 *
 *   type | resource_type | visibility | author | subject
 *       → exact-match condition on the payload field of the same name
 *   date_range: { gte?, lte? }
 *       → range condition on the `created_date` payload field; either
 *         boundary may be omitted, both omitted = no condition added
 *
 * Unrecognised keys are silently ignored — the search-input validator
 * is responsible for policing what reaches this class.
 *
 * Returns `null` when no valid conditions are produced so callers can
 * short-circuit the filter argument to Qdrant.
 *
 * Paired with {@see ElasticsearchFilterBuilder}: one per backend, each
 * responsible for its native query DSL. See that class's docblock for
 * the known interface-leak debt between the two.
 */
class QdrantFilterBuilder
{
    /**
     * Translate the module's canonical {@see SearchFilter} into Qdrant's
     * native `{must?, should?}` filter structure. Preferred over the
     * legacy `build()` entry point; that method stays for the inline
     * Qdrant-shaped paths until the keyword hybrid-search migration
     * is complete.
     *
     * @return array<string, mixed>|null
     */
    public function fromSearchFilter(SearchFilter $filter): ?array
    {
        if ($filter->isEmpty()) {
            return null;
        }

        $must = [];
        foreach ($filter->mustMatch as $condition) {
            $must[] = [
                'key' => $condition['field'],
                'match' => ['value' => $condition['value']],
            ];
        }
        foreach ($filter->ranges as $range) {
            $bounds = [];
            if (isset($range['gte'])) {
                $bounds['gte'] = $range['gte'];
            }
            if (isset($range['lte'])) {
                $bounds['lte'] = $range['lte'];
            }
            if (!empty($bounds)) {
                $must[] = ['key' => $range['field'], 'range' => $bounds];
            }
        }

        $result = [];
        if (!empty($must)) {
            $result['must'] = $must;
        }

        if ($filter->anyText !== null && !empty($filter->anyText['terms'])) {
            $should = [];
            foreach ($filter->anyText['terms'] as $term) {
                foreach ($filter->anyText['fields'] as $field) {
                    $should[] = [
                        'key' => $field,
                        'match' => ['value' => $term],
                    ];
                }
            }
            if (!empty($should)) {
                $result['should'] = $should;
            }
        }

        return empty($result) ? null : $result;
    }

    /** Filter keys that map one-to-one to an exact-match payload condition. */
    private const EXACT_MATCH_FIELDS = [
        'type',
        'resource_type',
        'visibility',
        'author',
        'subject',
    ];

    /**
     * @param array<string,mixed> $filters
     * @return array{must: list<array<string,mixed>>}|null
     */
    public function build(array $filters): ?array
    {
        if (empty($filters)) {
            return null;
        }

        $must = [];

        foreach ($filters as $key => $value) {
            if (in_array($key, self::EXACT_MATCH_FIELDS, true)) {
                $must[] = [
                    'key' => $key,
                    'match' => ['value' => $value],
                ];
                continue;
            }

            if ($key === 'date_range' && is_array($value)) {
                $range = [];
                if (isset($value['gte'])) {
                    $range['gte'] = $value['gte'];
                }
                if (isset($value['lte'])) {
                    $range['lte'] = $value['lte'];
                }
                if (!empty($range)) {
                    $must[] = ['key' => 'created_date', 'range' => $range];
                }
            }
            // Unrecognised keys ignored; validator policed the input already.
        }

        return empty($must) ? null : ['must' => $must];
    }
}
