<?php
declare(strict_types=1);
namespace SearchAssistantAI\Controller\Admin;

use SearchAssistantAI\Form\DashboardActionForm;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;

/**
 * Admin reindex controller for Search Assistant AI module
 *
 * Provides interface for manual reindexing operations with:
 * - Resource type selection (Items, Media, Collections, All)
 * - Scope configuration (Complete, Incremental, Specific resources)
 * - Execution mode (Synchronous, Asynchronous)
 * - Real-time progress tracking
 * - Job cancellation support
 */
class ReindexController extends AbstractActionController
{
    /** Log lines returned by progressAction (the job log grows with every batch). */
    private const MAX_LOG_LINES = 200;

    /**
     * Display the reindex interface
     *
     * Shows form for configuring and launching reindex operations
     *
     * @return ViewModel
     */
    public function indexAction()
    {
        // Get the settings service
        $settings = $this->settings();

        // Prepare resource type options for the form
        $resourceTypes = [
            'items' => 'Items',
            'media' => 'Media',
            'item_sets' => 'Item Sets (Collections)',
            'all' => 'All Resource Types'
        ];

        // Prepare scope options for the form
        $scopes = [
            'complete' => [
                'label' => 'Complete Reindex',
                'description' => 'Delete existing index entries and recreate from scratch'
            ],
            'incremental' => [
                'label' => 'Incremental Reindex',
                'description' => 'Only index new resources not yet in vector database'
            ],
            'specific' => [
                'label' => 'Specific Resources',
                'description' => 'Target specific resource IDs (comma-separated)'
            ]
        ];

        // Prepare execution mode options
        $executionModes = [
            'async' => [
                'label' => 'Asynchronous (Background)',
                'description' => 'Run as background job (recommended for large datasets)'
            ],
            'sync' => [
                'label' => 'Synchronous (Wait)',
                'description' => 'Wait for completion (only for small datasets)'
            ]
        ];

        // Get current indexation status
        $qdrantUrl = $settings->get('search_assistant_qdrant_url', '');
        $collectionName = $settings->get('search_assistant_collection_name', 'omeka_items');
        $lastIndexation = $settings->get('search_assistant_last_indexation', null);

        // Same CSRF form as the dashboard actions; the AJAX endpoints below validate it.
        $actionForm = $this->getForm(DashboardActionForm::class);

        // Return view with form data
        return new ViewModel([
            'csrfToken' => $actionForm->get('csrf')->getValue(),
            'resourceTypes' => $resourceTypes,
            'scopes' => $scopes,
            'executionModes' => $executionModes,
            'qdrantUrl' => $qdrantUrl,
            'collectionName' => $collectionName,
            'lastIndexation' => $lastIndexation,
        ]);
    }

    /**
     * AJAX endpoint to preview resource count before reindexing
     *
     * POST: Returns count of resources that would be affected by reindex
     * Parameters: type (resource type), scope (complete/incremental/specific), resource_ids (if specific)
     * Returns JSON: {success: bool, data: {count: int, type: string, scope: string}, message: string}
     *
     * @return JsonModel
     */
    public function previewAction()
    {
        // Verify this is an AJAX request
        if (!$this->getRequest()->isXmlHttpRequest()) {
            return new JsonModel([
                'success' => false,
                'message' => 'This endpoint only accepts AJAX requests'
            ]);
        }

        // Only accept POST requests
        if (!$this->getRequest()->isPost()) {
            return new JsonModel([
                'success' => false,
                'message' => 'Only POST requests are allowed'
            ]);
        }

        // CSRF Protection - Validate security token
        $form = $this->getForm(DashboardActionForm::class);
        $form->setData($this->getRequest()->getPost());

        if (!$form->isValid()) {
            return new JsonModel([
                'success' => false,
                'message' => 'Invalid security token. Please refresh the page and try again.'
            ]);
        }

        try {
            // Get request parameters
            $type = $this->params()->fromPost('type', 'items');
            $scope = $this->params()->fromPost('scope', 'complete');
            $resourceIds = $this->params()->fromPost('resource_ids', '');

            // Validate resource type
            $validTypes = ['items', 'media', 'item_sets', 'all'];
            if (!in_array($type, $validTypes)) {
                return new JsonModel([
                    'success' => false,
                    'message' => 'Invalid resource type. Must be one of: ' . implode(', ', $validTypes)
                ]);
            }

            // Validate scope
            $validScopes = ['complete', 'incremental', 'specific'];
            if (!in_array($scope, $validScopes)) {
                return new JsonModel([
                    'success' => false,
                    'message' => 'Invalid scope. Must be one of: ' . implode(', ', $validScopes)
                ]);
            }

            // Calculate resource count based on type and scope
            $count = 0;
            $details = [];

            if ($scope === 'specific') {
                // Count specific resources
                if (empty($resourceIds)) {
                    return new JsonModel([
                        'success' => false,
                        'message' => 'Resource IDs are required for specific scope'
                    ]);
                }

                // Parse comma-separated IDs
                $ids = array_filter(array_map('trim', explode(',', $resourceIds)));
                $count = count($ids);
                $details['resource_ids'] = $ids;
            } else {
                // Count resources by type
                if ($type === 'all') {
                    // Count all resource types
                    $itemsCount = $this->api()->search('items', [], ['returnScalar' => 'totalResults'])->getTotalResults();
                    $mediaCount = $this->api()->search('media', [], ['returnScalar' => 'totalResults'])->getTotalResults();
                    $itemSetsCount = $this->api()->search('item_sets', [], ['returnScalar' => 'totalResults'])->getTotalResults();

                    $count = $itemsCount + $mediaCount + $itemSetsCount;
                    $details['breakdown'] = [
                        'items' => $itemsCount,
                        'media' => $mediaCount,
                        'item_sets' => $itemSetsCount
                    ];
                } else {
                    // Count single resource type
                    $count = $this->api()->search($type, [], ['returnScalar' => 'totalResults'])->getTotalResults();
                }

                // For incremental scope, note that count represents maximum possible
                if ($scope === 'incremental') {
                    $details['note'] = 'Count represents maximum resources. Actual count will be lower (only unindexed resources).';
                }
            }

            // Return successful response with count
            return new JsonModel([
                'success' => true,
                'data' => array_merge([
                    'count' => $count,
                    'type' => $type,
                    'scope' => $scope
                ], $details),
                'message' => sprintf(
                    'Preview: %d %s will be affected',
                    $count,
                    $count === 1 ? 'resource' : 'resources'
                )
            ]);
        } catch (\Exception $e) {
            // Catch any unexpected errors
            return new JsonModel([
                'success' => false,
                'message' => 'Failed to preview resources: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * AJAX endpoint to launch reindex operation
     *
     * POST: Dispatches reindex job with specified parameters
     * Parameters: type, scope, resource_ids (optional), execution_mode
     * Returns JSON: {success: bool, data: {job_id: int}, message: string}
     *
     * @return JsonModel
     */
    public function launchAction()
    {
        // Verify this is an AJAX request
        if (!$this->getRequest()->isXmlHttpRequest()) {
            return new JsonModel([
                'success' => false,
                'message' => 'This endpoint only accepts AJAX requests'
            ]);
        }

        // Only accept POST requests
        if (!$this->getRequest()->isPost()) {
            return new JsonModel([
                'success' => false,
                'message' => 'Only POST requests are allowed'
            ]);
        }

        // CSRF Protection - Validate security token
        $form = $this->getForm(DashboardActionForm::class);
        $form->setData($this->getRequest()->getPost());

        if (!$form->isValid()) {
            return new JsonModel([
                'success' => false,
                'message' => 'Invalid security token. Please refresh the page and try again.'
            ]);
        }

        try {
            // Check for and auto-stop active reindex jobs
            $entityManager = $this->getEvent()->getApplication()->getServiceManager()->get('Omeka\EntityManager');
            $jobRepository = $entityManager->getRepository('Omeka\Entity\Job');

            $qb = $jobRepository->createQueryBuilder('j');
            $activeJobs = $qb
                ->where('j.class = :jobClass')
                ->andWhere('j.status IN (:statuses)')
                ->setParameter('jobClass', 'SearchAssistantAI\Job\ReindexJob')
                ->setParameter('statuses', [\Omeka\Entity\Job::STATUS_STARTING, \Omeka\Entity\Job::STATUS_IN_PROGRESS])
                ->getQuery()
                ->getResult();

            // Auto-stop existing jobs instead of blocking
            $stoppedJobsCount = 0;
            if (count($activeJobs) > 0) {
                foreach ($activeJobs as $activeJob) {
                    $activeJob->setStatus(\Omeka\Entity\Job::STATUS_STOPPING);
                    $entityManager->persist($activeJob);
                    $stoppedJobsCount++;
                }
                $entityManager->flush();

                // Log the auto-stop action
                $logger = $this->getEvent()->getApplication()->getServiceManager()->get('Omeka\Logger');
                $logger->info(sprintf(
                    '[ReindexController] Auto-stopped %d existing ReindexJob instance(s) to start new job - Timestamp: %s',
                    $stoppedJobsCount,
                    date('c')
                ));
            }

            // Get request parameters
            $type = $this->params()->fromPost('type', 'items');
            $scope = $this->params()->fromPost('scope', 'complete');
            $resourceIds = $this->params()->fromPost('resource_ids', '');
            $executionMode = $this->params()->fromPost('execution_mode', 'async');

            // Validate resource type
            $validTypes = ['items', 'media', 'item_sets', 'all'];
            if (!in_array($type, $validTypes)) {
                return new JsonModel([
                    'success' => false,
                    'message' => 'Invalid resource type. Must be one of: ' . implode(', ', $validTypes)
                ]);
            }

            // Validate scope
            $validScopes = ['complete', 'incremental', 'specific'];
            if (!in_array($scope, $validScopes)) {
                return new JsonModel([
                    'success' => false,
                    'message' => 'Invalid scope. Must be one of: ' . implode(', ', $validScopes)
                ]);
            }

            // Prepare job arguments
            $jobArgs = [
                'type' => $type,
                'scope' => $scope,
                'execution_mode' => $executionMode
            ];

            // Handle specific scope - validate and parse resource IDs
            if ($scope === 'specific') {
                if (empty($resourceIds)) {
                    return new JsonModel([
                        'success' => false,
                        'message' => 'Resource IDs are required for specific scope'
                    ]);
                }

                // Parse comma-separated IDs and validate they're numeric
                $ids = array_filter(array_map('trim', explode(',', $resourceIds)));
                $ids = array_filter($ids, function($id) {
                    return is_numeric($id) && $id > 0;
                });

                if (empty($ids)) {
                    return new JsonModel([
                        'success' => false,
                        'message' => 'Valid resource IDs are required for specific scope'
                    ]);
                }

                $jobArgs['resource_ids'] = array_map('intval', $ids);
            }

            // Dispatch the reindex job via Omeka's job dispatcher
            $dispatcher = $this->jobDispatcher();
            $job = $dispatcher->dispatch('SearchAssistantAI\Job\ReindexJob', $jobArgs);

            // Return successful response with job ID and stopped jobs count
            $message = sprintf('Reindex job #%d dispatched successfully', $job->getId());
            if ($stoppedJobsCount > 0) {
                $message .= sprintf(' (%d existing job(s) auto-stopped)', $stoppedJobsCount);
            }

            return new JsonModel([
                'success' => true,
                'data' => [
                    'job_id' => $job->getId(),
                    'stopped_jobs_count' => $stoppedJobsCount
                ],
                'message' => $message
            ]);
        } catch (\Exception $e) {
            // Catch any unexpected errors
            return new JsonModel([
                'success' => false,
                'message' => 'Failed to launch reindex job: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * AJAX endpoint to retrieve job progress and status
     *
     * GET: Returns current status, percentage, and logs for a running job
     * Parameters: job_id (required)
     * Returns JSON: {success: bool, data: {status: string, percentage: float, message: string,
     *                items_processed: int|null, total_items: int|null, errors_count: int,
     *                progress_timestamp: string|null, logs: list<string>}, message: string}
     *
     * Progress data is retrieved from job args (persisted by ReindexJob::updateProgress())
     *
     * @return JsonModel
     */
    public function progressAction()
    {
        // Verify this is an AJAX request
        if (!$this->getRequest()->isXmlHttpRequest()) {
            return new JsonModel([
                'success' => false,
                'message' => 'This endpoint only accepts AJAX requests'
            ]);
        }

        // Only accept GET requests
        if (!$this->getRequest()->isGet()) {
            return new JsonModel([
                'success' => false,
                'message' => 'Only GET requests are allowed'
            ]);
        }

        try {
            // Get job ID from query parameters
            $jobId = $this->params()->fromQuery('job_id');

            // Validate job ID is provided and numeric
            if (empty($jobId) || !is_numeric($jobId)) {
                return new JsonModel([
                    'success' => false,
                    'message' => 'Valid job ID is required'
                ]);
            }

            // Get entity manager and fetch job
            $entityManager = $this->getEvent()->getApplication()->getServiceManager()->get('Omeka\EntityManager');
            $job = $entityManager->find('Omeka\Entity\Job', (int)$jobId);

            // Check if job exists
            if (!$job) {
                return new JsonModel([
                    'success' => false,
                    'message' => sprintf('Job #%d not found', $jobId)
                ]);
            }

            // Get job status
            $status = $job->getStatus();

            // Map Omeka job status to readable status
            $statusMap = [
                \Omeka\Entity\Job::STATUS_STARTING => 'starting',
                \Omeka\Entity\Job::STATUS_IN_PROGRESS => 'in_progress',
                \Omeka\Entity\Job::STATUS_COMPLETED => 'completed',
                \Omeka\Entity\Job::STATUS_ERROR => 'error',
                \Omeka\Entity\Job::STATUS_STOPPING => 'stopping',
                \Omeka\Entity\Job::STATUS_STOPPED => 'stopped'
            ];

            $statusLabel = $statusMap[$status] ?? 'unknown';

            // Get progress data from job args (persisted by ReindexJob::updateProgress())
            $jobArgs = $job->getArgs();
            $progressData = $jobArgs['progress'] ?? [];

            // Extract progress values from job args (primary source)
            $percentage = isset($progressData['percentage']) ? (float) $progressData['percentage'] : 0;
            $itemsProcessed = $progressData['items_processed'] ?? null;
            $totalItems = $progressData['total_items'] ?? null;
            $errorsCount = $progressData['errors_count'] ?? 0;
            $progressMessage = $progressData['message'] ?? null;
            $progressTimestamp = $progressData['timestamp'] ?? null;

            // Override percentage for completed jobs
            if ($status === \Omeka\Entity\Job::STATUS_COMPLETED) {
                $percentage = 100;
            }

            // Omeka keeps a job's log as one text field: return its last lines.
            $logLines = preg_split('/\R/', (string) $job->getLog()) ?: [];
            $logEntries = array_slice(array_values(array_filter($logLines, static function (string $line): bool {
                return $line !== '';
            })), -self::MAX_LOG_LINES);

            // Prepare response data
            $data = [
                'job_id' => $job->getId(),
                'status' => $statusLabel,
                'percentage' => min(100, max(0, $percentage)), // Clamp between 0-100
                'message' => $progressMessage,
                'items_processed' => $itemsProcessed,
                'total_items' => $totalItems,
                'errors_count' => $errorsCount,
                'progress_timestamp' => $progressTimestamp,
                'logs' => $logEntries,
                'started' => $job->getStarted() ? $job->getStarted()->format('Y-m-d H:i:s') : null,
                'ended' => $job->getEnded() ? $job->getEnded()->format('Y-m-d H:i:s') : null,
                'class' => $job->getClass(),
            ];

            // Return successful response with job progress
            return new JsonModel([
                'success' => true,
                'data' => $data,
                'message' => sprintf('Job #%d status: %s', $jobId, $statusLabel)
            ]);
        } catch (\Exception $e) {
            // Catch any unexpected errors
            return new JsonModel([
                'success' => false,
                'message' => 'Failed to retrieve job progress: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * AJAX endpoint to cancel a running reindex job
     *
     * POST: Sets job status to stopping to gracefully halt execution
     * Parameters: job_id (required)
     * Returns JSON: {success: bool, message: string}
     *
     * @return JsonModel
     */
    public function cancelAction()
    {
        // Verify this is an AJAX request
        if (!$this->getRequest()->isXmlHttpRequest()) {
            return new JsonModel([
                'success' => false,
                'message' => 'This endpoint only accepts AJAX requests'
            ]);
        }

        // Only accept POST requests
        if (!$this->getRequest()->isPost()) {
            return new JsonModel([
                'success' => false,
                'message' => 'Only POST requests are allowed'
            ]);
        }

        // CSRF Protection - Validate security token
        $form = $this->getForm(DashboardActionForm::class);
        $form->setData($this->getRequest()->getPost());

        if (!$form->isValid()) {
            return new JsonModel([
                'success' => false,
                'message' => 'Invalid security token. Please refresh the page and try again.'
            ]);
        }

        try {
            // Get job ID from POST parameters
            $jobId = $this->params()->fromPost('job_id');

            // Validate job ID is provided and numeric
            if (empty($jobId) || !is_numeric($jobId)) {
                return new JsonModel([
                    'success' => false,
                    'message' => 'Valid job ID is required'
                ]);
            }

            // Get entity manager and fetch job
            $entityManager = $this->getEvent()->getApplication()->getServiceManager()->get('Omeka\EntityManager');
            $job = $entityManager->find('Omeka\Entity\Job', (int)$jobId);

            // Check if job exists
            if (!$job) {
                return new JsonModel([
                    'success' => false,
                    'message' => sprintf('Job #%d not found', $jobId)
                ]);
            }

            // Check if job is in a cancellable state
            $currentStatus = $job->getStatus();
            $cancellableStatuses = [
                \Omeka\Entity\Job::STATUS_STARTING,
                \Omeka\Entity\Job::STATUS_IN_PROGRESS
            ];

            if (!in_array($currentStatus, $cancellableStatuses)) {
                return new JsonModel([
                    'success' => false,
                    'message' => sprintf(
                        'Job #%d cannot be cancelled (current status: %s)',
                        $jobId,
                        $currentStatus
                    )
                ]);
            }

            // Set job status to stopping
            $job->setStatus(\Omeka\Entity\Job::STATUS_STOPPING);

            // Persist changes to database
            $entityManager->persist($job);
            $entityManager->flush();

            // Return successful response
            return new JsonModel([
                'success' => true,
                'message' => sprintf('Job #%d is being stopped', $jobId)
            ]);
        } catch (\Exception $e) {
            // Catch any unexpected errors
            return new JsonModel([
                'success' => false,
                'message' => 'Failed to cancel job: ' . $e->getMessage()
            ]);
        }
    }
}
