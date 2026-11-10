<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\VectorStore;

/**
 * Translate the module's canonical {@see SearchFilter} into an Elasticsearch
 * bool query suitable for the `knn.filter` parameter.
 *
 * Paired with {@see QdrantFilterBuilder}: one translator per backend. Every
 * payload field lives under the `payload` object of the indexed document,
 * so conditions address `payload.<field>`.
 */
class ElasticsearchFilterBuilder
{
    /**
     * @return array{bool: array<string, mixed>}|null
     *   `null` when the filter is empty, so callers can omit `knn.filter`.
     */
    public function fromSearchFilter(SearchFilter $filter): ?array
    {
        if ($filter->isEmpty()) {
            return null;
        }

        $must = [];
        foreach ($filter->mustMatch as $condition) {
            $must[] = [
                'match' => ['payload.' . $condition['field'] => $condition['value']],
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
            if ($bounds !== []) {
                $must[] = ['range' => ['payload.' . $range['field'] => $bounds]];
            }
        }

        $bool = [];
        if ($must !== []) {
            $bool['must'] = $must;
        }

        if ($filter->anyText !== null && !empty($filter->anyText['terms'])) {
            $should = [];
            foreach ($filter->anyText['terms'] as $term) {
                foreach ($filter->anyText['fields'] as $field) {
                    $should[] = ['match' => ['payload.' . $field => $term]];
                }
            }
            if ($should !== []) {
                $bool['should'] = $should;
                $bool['minimum_should_match'] = 1;
            }
        }

        return $bool === [] ? null : ['bool' => $bool];
    }
}
