<?php
declare(strict_types=1);
namespace SearchAssistantAI\Controller\Admin;

use SearchAssistantAI\Service\Analytics\DashboardStatsContributorInterface;
use SearchAssistantAI\Service\Embedding\EmbeddingInterface;
use SearchAssistantAI\Service\VectorStore\VectorStoreBootstrap;
use SearchAssistantAI\Service\VectorStore\VectorStoreInterface;
use SearchAssistantAI\Service\VectorStore\VectorStoreRegistry;
use SearchAssistantAI\Settings\SearchAssistantSettings;
use SearchAssistantAI\Service\Analytics\StatsService;
use SearchAssistantAI\Service\Indexing\SyncService;
use Laminas\Http\Response;
use Laminas\Log\LoggerInterface;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

/**
 * Admin dashboard controller for Search Assistant AI module
 *
 * Provides overview and statistics for:
 * - Indexation status and progress
 * - Synchronization status
 * - Vector database statistics
 * - System health metrics
 */
class DashboardController extends AbstractActionController
{
    /**
     * @var StatsService
     */
    private $statsService;

    /**
     * @var SyncService
     */
    private $syncService;

    /**
     * @var LoggerInterface Laminas logger for dashboard operation tracking
     */
    private $logger;

    /**
     * @var VectorStoreInterface Backend-aware vector store (gateway in hosted mode)
     */
    private $vectorStore;

    /**
     * @var EmbeddingInterface Holds the embeddings file cache cleared by clearCacheAction
     */
    private $embeddingService;

    /**
     * @var VectorStoreRegistry Configured backend, its label and collection
     */
    private $vectorStoreRegistry;

    /**
     * @var VectorStoreBootstrap Recreates the collection for "Clear vector store"
     */
    private $vectorStoreBootstrap;

    /**
     * @var array<string, array<string, mixed>> search_assistant.llm_providers registry (labels)
     */
    private $llmProviders;

    /**
     * @var list<array{template: string}> Dashboard panels to render, in order
     */
    private $panels;

    /**
     * @var list<DashboardStatsContributorInterface> Data providers of the panels
     */
    private $statsContributors;

    /**
     * Constructor
     *
     * @param StatsService $statsService Statistics service for fetching data
     * @param SyncService $syncService Synchronization service for sync operations
     * @param LoggerInterface $logger Logger for operation tracking
     * @param VectorStoreInterface $vectorStore Vector store for the configured connection mode
     * @param EmbeddingInterface $embeddingService Embeddings service whose cache "Clear cache" empties
     * @param VectorStoreRegistry $vectorStoreRegistry Configured backend, its label and collection
     * @param VectorStoreBootstrap $vectorStoreBootstrap Recreates the collection for "Clear vector store"
     * @param array<string, array<string, mixed>> $llmProviders search_assistant.llm_providers registry (labels)
     * @param list<array{template: string}> $panels Dashboard panels to render, in order
     * @param list<DashboardStatsContributorInterface> $statsContributors Data providers of the panels
     */
    public function __construct(
        StatsService $statsService,
        SyncService $syncService,
        LoggerInterface $logger,
        VectorStoreInterface $vectorStore,
        EmbeddingInterface $embeddingService,
        VectorStoreRegistry $vectorStoreRegistry,
        VectorStoreBootstrap $vectorStoreBootstrap,
        array $llmProviders = [],
        array $panels = [],
        array $statsContributors = []
    ) {
        $this->statsService = $statsService;
        $this->syncService = $syncService;
        $this->logger = $logger;
        $this->vectorStore = $vectorStore;
        $this->embeddingService = $embeddingService;
        $this->vectorStoreRegistry = $vectorStoreRegistry;
        $this->vectorStoreBootstrap = $vectorStoreBootstrap;
        $this->llmProviders = $llmProviders;
        $this->panels = $panels;
        $this->statsContributors = $statsContributors;
    }

    /**
     * Create a JSON response with proper headers
     *
     * Returns a Laminas Response object with JSON content-type header,
     * bypassing the view layer to avoid template-not-found errors.
     *
     * @param array<string, mixed> $data The data to encode as JSON
     * @param int $statusCode HTTP status code (default 200)
     * @return Response
     */
    private function jsonResponse(array $data, int $statusCode = 200): Response
    {
        $response = $this->getResponse();
        $response->setStatusCode($statusCode);
        $response->getHeaders()->addHeaderLine('Content-Type', 'application/json; charset=utf-8');
        $response->setContent(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response;
    }

    /**
     * Display the main dashboard view
     *
     * Shows indexation and synchronization status overview
     *
     * @return ViewModel
     */
    public function indexAction()
    {
        $this->logger->info('[SearchAssistantAI] Dashboard: Loading admin dashboard page');

        // Get the settings service
        $settings = $this->settings();

        // Configured vector store backend, its display name and collection
        $vectorStoreBackend = $this->vectorStoreRegistry->getBackend();
        $vectorStoreDisplayName = $this->vectorStoreRegistry->getLabel();
        $collectionName = $this->vectorStoreRegistry->getCollectionName();

        $llmProvider = (new SearchAssistantSettings($settings))->getLlmProvider();

        // Prepare dashboard data
        $dashboardData = [
            'vectorStoreBackend' => $vectorStoreBackend,
            'vectorStoreDisplayName' => $vectorStoreDisplayName,
            'qdrantUrl' => $settings->get('search_assistant_qdrant_url', ''),
            'collectionName' => $collectionName,
            'lastSync' => $settings->get('search_assistant_last_sync', null),
            'llmProvider' => $llmProvider,
            'llmDisplayName' => $this->llmProviders[$llmProvider]['label'] ?? ucfirst($llmProvider),
        ];

        $this->logger->info('[SearchAssistantAI] Dashboard: Page loaded successfully', [
            'backend' => $vectorStoreBackend,
            'collection' => $dashboardData['collectionName']
        ]);

        // Create form for CSRF token (auto-generated as 'dashboardactionform_csrf')
        $form = $this->getForm(\SearchAssistantAI\Form\DashboardActionForm::class);

        // Return view with dashboard data
        return new ViewModel([
            'dashboardData' => $dashboardData,
            'form' => $form,
            'dashboardPanels' => $this->panels,
        ]);
    }

    /**
     * AJAX endpoint to retrieve dashboard statistics
     *
     * GET: Returns current indexation and synchronization statistics
     * Returns JSON: {success: bool, data: object, message: string}
     *
     * @return Response
     */
    public function statsAction()
    {
        $this->logger->debug('[SearchAssistantAI] Dashboard: Stats API request received');

        // Verify this is an AJAX request
        if (!$this->getRequest()->isXmlHttpRequest()) {
            $this->logger->warn('[SearchAssistantAI] Dashboard: Stats request rejected - not an AJAX request');
            return $this->jsonResponse([
                'success' => false,
                'message' => 'This endpoint only accepts AJAX requests'
            ], 400);
        }

        // Only accept GET requests
        if (!$this->getRequest()->isGet()) {
            $this->logger->warn('[SearchAssistantAI] Dashboard: Stats request rejected - not a GET request');
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Only GET requests are allowed'
            ], 405);
        }

        try {
            // Get the settings service
            $settings = $this->settings();

            // Fetch statistics from all services
            $vectorStoreStats = $this->statsService->getVectorStoreStats();
            $embeddingsHealth = $this->statsService->getEmbeddingsHealth();
            $llmHealth = $this->statsService->getLlmHealth();
            $syncStats = $this->syncService->getSyncStats();

            // Calculate total vectors from Qdrant stats
            $totalVectors = 0;
            if ($vectorStoreStats['status'] === 'connected' && isset($vectorStoreStats['data'])) {
                foreach ($vectorStoreStats['data']['collections'] as $collectionName => $collectionStats) {
                    $totalVectors += $collectionStats['points_count'] ?? 0;
                }
            }

            // Get accurate per-type counts from vector store
            $distribution = $this->statsService->getIndexedResourceCounts();

            // Build comprehensive stats response
            $stats = [
                'indexation' => [
                    'total_vectors' => $totalVectors,
                    'indexed_items' => $distribution['items'],
                    'pending_items' => $syncStats['pending'] ?? 0,
                    'last_indexed' => $settings->get('search_assistant_last_indexation', null),
                    'distribution' => $distribution
                ],
                'synchronization' => [
                    'status' => ($syncStats['pending'] ?? 0) > 0 ? 'processing' : 'idle',
                    'last_sync' => $settings->get('search_assistant_last_sync', null),
                    'today_operations' => $syncStats['completed'] ?? 0,
                    'queue_count' => $syncStats['pending'] ?? 0,
                    'total_queue_items' => $syncStats['total'] ?? 0,
                    'failed_items' => $syncStats['failed'] ?? 0,
                    'average_processing_time' => $syncStats['average_processing_time'] ?? null,
                    'oldest_pending_age' => $syncStats['oldest_pending_age'] ?? null,
                    'stale_pending' => ($syncStats['oldest_pending_age'] ?? 0) > 300,
                ],
                'health' => [
                    'qdrant' => $vectorStoreStats,
                    'embeddings' => $embeddingsHealth,
                    'llm' => $llmHealth,
                ],
            ];

            // Data of the registered dashboard panels (e.g. usage metrics).
            foreach ($this->statsContributors as $contributor) {
                $stats = array_merge($stats, $contributor->contribute());
            }

            $this->logger->info('[SearchAssistantAI] Dashboard: Stats fetched successfully', [
                'vectors' => $stats['indexation']['total_vectors'],
                'pending' => $stats['synchronization']['queue_count']
            ]);

            // Return successful response with stats data
            return $this->jsonResponse([
                'success' => true,
                'data' => $stats,
                'message' => 'Statistics retrieved successfully'
            ]);
        } catch (\Exception $e) {
            // Catch any unexpected errors
            $this->logger->err('[SearchAssistantAI] Dashboard: Stats fetch failed', [
                'error' => $e->getMessage()
            ]);
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to retrieve statistics: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * AJAX endpoint to trigger full reindexation
     *
     * POST: Triggers reindexation of all items, media, and collections
     * Returns JSON: {success: bool, message: string}
     *
     * @return Response
     */
    public function reindexAction()
    {
        $this->logger->info('[SearchAssistantAI] Dashboard: Reindex API request received');

        // Verify this is an AJAX request
        if (!$this->getRequest()->isXmlHttpRequest()) {
            $this->logger->warn('[SearchAssistantAI] Dashboard: Reindex request rejected - not an AJAX request');
            return $this->jsonResponse([
                'success' => false,
                'message' => 'This endpoint only accepts AJAX requests'
            ], 400);
        }

        // Only accept POST requests
        if (!$this->getRequest()->isPost()) {
            $this->logger->warn('[SearchAssistantAI] Dashboard: Reindex request rejected - not a POST request');
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Only POST requests are allowed'
            ], 405);
        }

        // CSRF Protection - Validate security token
        $form = $this->getForm(\SearchAssistantAI\Form\DashboardActionForm::class);
        $form->setData($this->getRequest()->getPost());

        if (!$form->isValid()) {
            $this->logger->warn('[SearchAssistantAI] Dashboard: Reindex request rejected - invalid CSRF token');
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Invalid security token. Please refresh the page and try again.'
            ], 403);
        }

        try {
            // Dispatch ReindexJob to reindex all resource types
            $jobArgs = [
                'resource_type' => null,        // null = reindex all resource types (items, media, item_sets)
                'scope' => 'complete',          // Complete reindex
                'batch_size' => 100,            // Process 100 resources per batch
                'delete_existing' => true,      // Clear existing data first to respect current filter settings
            ];

            // Dispatch the background job
            $dispatcher = $this->jobDispatcher();
            $job = $dispatcher->dispatch('SearchAssistantAI\Job\ReindexJob', $jobArgs);

            // Update last indexation timestamp
            $settings = $this->settings();
            $settings->set('search_assistant_last_indexation', date('Y-m-d H:i:s'));

            $this->logger->info('[SearchAssistantAI] Dashboard: Reindexation job dispatched', [
                'job_id' => $job->getId()
            ]);

            return $this->jsonResponse([
                'success' => true,
                'message' => 'Reindexation started successfully. The process is running in the background.',
                'data' => [
                    'job_id' => $job->getId()
                ]
            ]);
        } catch (\Exception $e) {
            $this->logger->err('[SearchAssistantAI] Dashboard: Reindexation failed', [
                'error' => $e->getMessage()
            ]);
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to start reindexation: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * AJAX endpoint to trigger manual synchronization
     *
     * POST: Triggers synchronization process
     * Returns JSON: {success: bool, message: string}
     *
     * @return Response
     */
    public function syncAction()
    {
        $this->logger->info('[SearchAssistantAI] Dashboard: Sync API request received');

        // Verify this is an AJAX request
        if (!$this->getRequest()->isXmlHttpRequest()) {
            $this->logger->warn('[SearchAssistantAI] Dashboard: Sync request rejected - not an AJAX request');
            return $this->jsonResponse([
                'success' => false,
                'message' => 'This endpoint only accepts AJAX requests'
            ], 400);
        }

        // Only accept POST requests
        if (!$this->getRequest()->isPost()) {
            $this->logger->warn('[SearchAssistantAI] Dashboard: Sync request rejected - not a POST request');
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Only POST requests are allowed'
            ], 405);
        }

        // CSRF Protection - Validate security token
        $form = $this->getForm(\SearchAssistantAI\Form\DashboardActionForm::class);
        $form->setData($this->getRequest()->getPost());

        if (!$form->isValid()) {
            $this->logger->warn('[SearchAssistantAI] Dashboard: Sync request rejected - invalid CSRF token');
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Invalid security token. Please refresh the page and try again.'
            ], 403);
        }

        try {
            // Trigger sync queue processing with default batch size
            $result = $this->syncService->processSyncQueue();

            // Update last sync timestamp
            $settings = $this->settings();
            $settings->set('search_assistant_last_sync', date('Y-m-d H:i:s'));

            // Build success message with processing statistics
            $message = sprintf(
                'Synchronization completed: %d items processed, %d succeeded, %d failed',
                $result['processed'],
                $result['succeeded'],
                $result['failed']
            );

            $this->logger->info('[SearchAssistantAI] Dashboard: Sync completed', [
                'processed' => $result['processed'],
                'succeeded' => $result['succeeded'],
                'failed' => $result['failed']
            ]);
            return $this->jsonResponse([
                'success' => true,
                'message' => $message,
                'data' => $result
            ]);
        } catch (\Exception $e) {
            $this->logger->err('[SearchAssistantAI] Dashboard: Sync failed', [
                'error' => $e->getMessage()
            ]);
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to synchronize: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * AJAX endpoint to clear module cache
     *
     * POST: Clears the embeddings cache (cached question and resource vectors)
     * Returns JSON: {success: bool, message: string}
     *
     * @return Response
     */
    public function clearCacheAction()
    {
        $this->logger->info('[SearchAssistantAI] Dashboard: Clear cache API request received');

        // Verify this is an AJAX request
        if (!$this->getRequest()->isXmlHttpRequest()) {
            $this->logger->warn('[SearchAssistantAI] Dashboard: Clear cache request rejected - not an AJAX request');
            return $this->jsonResponse([
                'success' => false,
                'message' => 'This endpoint only accepts AJAX requests'
            ], 400);
        }

        // Only accept POST requests
        if (!$this->getRequest()->isPost()) {
            $this->logger->warn('[SearchAssistantAI] Dashboard: Clear cache request rejected - not a POST request');
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Only POST requests are allowed'
            ], 405);
        }

        // CSRF Protection - Validate security token
        $form = $this->getForm(\SearchAssistantAI\Form\DashboardActionForm::class);
        $form->setData($this->getRequest()->getPost());

        if (!$form->isValid()) {
            $this->logger->warn('[SearchAssistantAI] Dashboard: Clear cache request rejected - invalid CSRF token');
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Invalid security token. Please refresh the page and try again.'
            ], 403);
        }

        try {
            $this->embeddingService->clearCache();

            $this->logger->info('[SearchAssistantAI] Dashboard: Embeddings cache cleared');
            return $this->jsonResponse([
                'success' => true,
                'message' => 'Cache cleared successfully'
            ]);
        } catch (\Exception $e) {
            $this->logger->err('[SearchAssistantAI] Dashboard: Clear cache failed', [
                'error' => $e->getMessage()
            ]);
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to clear cache: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * AJAX endpoint to clear all vector-store data (Qdrant or Elasticsearch).
     *
     * POST: Deletes and recreates the configured vector-store collection.
     * Returns JSON: {success: bool, message: string}
     *
     * @return Response
     */
    public function clearVectorStoreAction()
    {
        $this->logger->info('[SearchAssistantAI] Dashboard: Clear vector store API request received');

        // Verify this is an AJAX request
        if (!$this->getRequest()->isXmlHttpRequest()) {
            $this->logger->warn('[SearchAssistantAI] Dashboard: Clear vector store request rejected - not an AJAX request');
            return $this->jsonResponse([
                'success' => false,
                'message' => 'This endpoint only accepts AJAX requests'
            ], 400);
        }

        // Only accept POST requests
        if (!$this->getRequest()->isPost()) {
            $this->logger->warn('[SearchAssistantAI] Dashboard: Clear vector store request rejected - not a POST request');
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Only POST requests are allowed'
            ], 405);
        }

        // CSRF Protection - Validate security token
        $form = $this->getForm(\SearchAssistantAI\Form\DashboardActionForm::class);
        $form->setData($this->getRequest()->getPost());

        if (!$form->isValid()) {
            $this->logger->warn('[SearchAssistantAI] Dashboard: Clear vector store request rejected - invalid CSRF token');
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Invalid security token. Please refresh the page and try again.'
            ], 403);
        }

        try {
            $bootstrap = $this->vectorStoreBootstrap;
            $collectionName = $bootstrap->getCollectionName();
            // The injected store comes from VectorStoreFactory, which routes
            // through the hosted gateway when connection mode is 'hosted'.
            $backend = get_class($this->vectorStore);

            $this->logger->info('[SearchAssistantAI] Dashboard: Recreating vector store collection', [
                'backend' => $backend,
                'collection' => $collectionName,
                'dimension' => $bootstrap->getDimension(),
                'distance' => VectorStoreBootstrap::DEFAULT_DISTANCE,
            ]);

            if (!$bootstrap->recreateCollection($this->vectorStore)) {
                $this->logger->err('[SearchAssistantAI] Dashboard: Failed to recreate vector store collection', [
                    'backend' => $backend,
                    'collection' => $collectionName,
                ]);
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Failed to recreate collection. The collection may be in an inconsistent state.'
                ], 500);
            }

            $this->logger->info('[SearchAssistantAI] Dashboard: Vector store data cleared successfully', [
                'backend' => $backend,
                'collection' => $collectionName,
            ]);

            return $this->jsonResponse([
                'success' => true,
                'message' => 'Vector store data cleared successfully. All vectors have been removed. You will need to reindex content.'
            ]);
        } catch (\RuntimeException $e) {
            // Connection failed after retry attempts
            $this->logger->err('[SearchAssistantAI] Dashboard: Clear vector store failed - connection error', [
                'error' => $e->getMessage()
            ]);
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Vector store service is not available: ' . $e->getMessage()
            ], 503);
        } catch (\InvalidArgumentException $e) {
            // Invalid arguments
            $this->logger->err('[SearchAssistantAI] Dashboard: Clear vector store failed - invalid arguments', [
                'error' => $e->getMessage()
            ]);
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Invalid configuration: ' . $e->getMessage()
            ], 400);
        } catch (\Exception $e) {
            $this->logger->err('[SearchAssistantAI] Dashboard: Clear vector store failed', [
                'error' => $e->getMessage()
            ]);
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to clear vector store data: ' . $e->getMessage()
            ], 500);
        }
    }
}
