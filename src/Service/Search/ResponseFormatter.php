<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\Search;

use Laminas\Log\LoggerInterface;

/**
 * Shape QueryProcessorService's output into the JSON payload the chat
 * API returns to the widget: `{status, answer, sources[], suggestions[]}`.
 *
 * Three concerns:
 *   - formatSuccess() — decorate an LLM-generated answer with numbered
 *     source citations built from the vector-search payloads, and apply
 *     the locale prefix to each source URL for multi-site deployments.
 *   - formatNoResults() — build the "nothing found" response with
 *     contextual refinement suggestions.
 *   - payloadMetrics() — conservative byte/token estimate for the
 *     context we're about to hand the LLM (diagnostic logging only).
 *
 * Extracted from QueryProcessorService during the v1.1 refactor.
 */
class ResponseFormatter
{
    /** Rough char-to-token ratio used by payloadMetrics(); conservative. */
    private const CHARS_PER_TOKEN = 4;

    private const NO_RESULTS_ANSWER =
        'Aucun résultat trouvé pour votre recherche. Veuillez reformuler votre question ou utiliser des termes différents.';

    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param array<int, array{id?: int|string, score?: float, payload?: array<string, mixed>}> $searchResults
     * @return array{status: string, answer: string, sources: list<array<string, mixed>>, suggestions: list<string>}
     */
    public function formatSuccess(string $answer, array $searchResults, string $siteSlug = ''): array
    {
        $sitePrefix = $siteSlug !== '' ? '/s/' . $siteSlug : '';
        $sources = [];

        foreach ($searchResults as $index => $result) {
            $metadata = $result['payload'] ?? [];
            $source = [
                'number' => $index + 1,
                'title' => $metadata['title'] ?? 'Sans titre',
                'type' => $metadata['resource_type'] ?? 'Unknown',
                'id' => $result['id'] ?? null,
                'score' => $result['score'] ?? 0.0,
            ];

            if (isset($metadata['author'])) {
                $source['author'] = $metadata['author'];
            }
            if (isset($metadata['date'])) {
                $source['date'] = $metadata['date'];
            }

            if (isset($metadata['url'])) {
                // Strip any pre-existing locale prefix (legacy data) before
                // applying the current request's locale prefix.
                $url = preg_replace('#^/s/[^/]+/#', '/', (string) $metadata['url']);
                $source['url'] = $sitePrefix . $url;
            }

            $sources[] = $source;
        }

        $this->logger->debug('QueryProcessorService: Formatted success response', [
            'source_count' => count($sources),
            'answer_length' => strlen($answer),
        ]);

        return [
            'status' => 'success',
            'answer' => $answer,
            'sources' => $sources,
            'suggestions' => [],
        ];
    }

    /**
     * @param array{keywords?: list<string>, author?: string, dateRange?: array<string, mixed>, type?: string} $criteria
     * @return array{status: string, answer: string, sources: list<array<string, mixed>>, suggestions: list<string>}
     */
    public function formatNoResults(string $userQuery, array $criteria): array
    {
        $suggestions = $this->suggestionsForNoResults($criteria);

        $this->logger->debug('QueryProcessorService: Formatted no-results response', [
            'suggestion_count' => count($suggestions),
        ]);

        return [
            'status' => 'success',
            'answer' => self::NO_RESULTS_ANSWER,
            'sources' => [],
            'suggestions' => $suggestions,
        ];
    }

    /**
     * Contextual refinement suggestions shown beside the answer when
     * search returned results. Encourages narrowing by fields the user
     * hasn't already constrained.
     *
     * @param array{author?: string, dateRange?: array<string, mixed>, type?: string} $criteria
     * @return list<string>
     */
    public function suggestionsForResults(array $criteria): array
    {
        $suggestions = [
            'Affinez votre recherche avec des mots-clés plus précis',
            'Explorez les sources citées pour plus de détails',
        ];
        if (!isset($criteria['author'])) {
            $suggestions[] = 'Filtrez par auteur pour des résultats plus ciblés';
        }
        if (!isset($criteria['dateRange'])) {
            $suggestions[] = 'Ajoutez une plage de dates pour affiner les résultats';
        }
        return $suggestions;
    }

    /**
     * Byte/token estimate for a set of search results, used by the
     * processor's diagnostic logging before the context goes to the LLM.
     *
     * @param array<int, array{payload?: array{title?: string, description?: string}}> $searchResults
     * @return array{
     *     total_size_bytes: int,
     *     total_size_kb: float,
     *     estimated_tokens: int,
     *     avg_result_size_bytes: int,
     *     max_result_size_bytes: int,
     *     individual_sizes: array<int, array{size_bytes: int, title: string, has_description: bool}>
     * }
     */
    public function payloadMetrics(array $searchResults): array
    {
        $totalSizeBytes = 0;
        $individualSizes = [];
        $maxResultSizeBytes = 0;

        foreach ($searchResults as $index => $result) {
            $resultJson = json_encode($result, JSON_UNESCAPED_UNICODE);
            $resultSize = strlen($resultJson);
            $totalSizeBytes += $resultSize;

            $individualSizes[$index] = [
                'size_bytes' => $resultSize,
                'title' => substr($result['payload']['title'] ?? 'Unknown', 0, 50),
                'has_description' => !empty($result['payload']['description'] ?? ''),
            ];

            if ($resultSize > $maxResultSizeBytes) {
                $maxResultSizeBytes = $resultSize;
            }
        }

        $count = count($searchResults);
        return [
            'total_size_bytes' => $totalSizeBytes,
            'total_size_kb' => round($totalSizeBytes / 1024, 2),
            'estimated_tokens' => (int) ceil($totalSizeBytes / self::CHARS_PER_TOKEN),
            'avg_result_size_bytes' => $count > 0 ? (int) round($totalSizeBytes / $count) : 0,
            'max_result_size_bytes' => $maxResultSizeBytes,
            'individual_sizes' => $individualSizes,
        ];
    }

    /**
     * @param array{keywords?: list<string>, author?: string, dateRange?: array<string, mixed>, type?: string} $criteria
     * @return list<string>
     */
    private function suggestionsForNoResults(array $criteria): array
    {
        $suggestions = [];

        if (!empty($criteria['keywords'])) {
            $suggestions[] = 'Essayez avec des mots-clés différents ou plus généraux';
            $suggestions[] = "Vérifiez l'orthographe des termes de recherche";
        }
        if (isset($criteria['author'])) {
            $suggestions[] = "Essayez de rechercher sans spécifier d'auteur";
        }
        if (isset($criteria['dateRange'])) {
            $suggestions[] = 'Élargissez la plage de dates de recherche';
        }
        if (isset($criteria['type'])) {
            $suggestions[] = 'Essayez sans filtrer par type de ressource';
        }

        if (empty($suggestions)) {
            $suggestions[] = "Reformulez votre question avec d'autres termes";
            $suggestions[] = 'Utilisez des mots-clés plus spécifiques';
        }

        return $suggestions;
    }
}
