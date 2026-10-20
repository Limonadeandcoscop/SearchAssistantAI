<?php
declare(strict_types=1);
namespace SearchAssistantAI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use SearchAssistantAI\Service\Indexing\VectorIndexInterface;
use Doctrine\ORM\EntityManager;
use Laminas\Log\LoggerInterface;

/**
 * CLI command for manual vector reindexing of Omeka S resources.
 *
 * This command provides a command-line interface for triggering vector indexing
 * operations with optional resource type filtering and progress visualization.
 * Designed for manual reindexing operations, bulk indexing, and maintenance tasks.
 *
 * Usage:
 * <code>
 * # Reindex all resources
 * php bin/console vector:reindex
 *
 * # Reindex only items
 * php bin/console vector:reindex --type=item
 *
 * # Reindex with custom batch size
 * php bin/console vector:reindex --batch-size=200
 *
 * # Reindex specific resource type with custom batch size
 * php bin/console vector:reindex --type=media --batch-size=50
 * </code>
 *
 * Options:
 * - --type: Filter by resource type (item, media, collection)
 * - --batch-size: Number of resources to process per batch (default: 100)
 * - --limit: Maximum number of resources to index (for testing)
 *
 * The command displays a progress bar during indexing and provides summary
 * statistics upon completion including success rate, duration, and throughput.
 */
class VectorReindexCommand extends Command
{
    /**
     * @var string Default command name
     */
    protected static $defaultName = 'vector:reindex';

    /**
     * @var VectorIndexInterface Vector indexing service
     */
    private $vectorIndexService;

    /**
     * @var LoggerInterface Laminas logger for error logging
     */
    private $logger;

    /**
     * @var EntityManager Doctrine entity manager
     */
    private $entityManager;

    /**
     * Constructor.
     *
     * @param VectorIndexInterface $vectorIndexService Vector indexing service instance
     * @param LoggerInterface $logger Logger for error and progress logging
     * @param EntityManager $entityManager Doctrine entity manager for entity queries
     */
    public function __construct(
        VectorIndexInterface $vectorIndexService,
        LoggerInterface $logger,
        EntityManager $entityManager
    ) {
        parent::__construct();
        $this->vectorIndexService = $vectorIndexService;
        $this->logger = $logger;
        $this->entityManager = $entityManager;
    }

    /**
     * Configure the command.
     *
     * Sets up command name, description, help text, and available options.
     * This method is called automatically by Symfony Console during command registration.
     */
    protected function configure(): void
    {
        $this
            ->setName('vector:reindex')
            ->setDescription('Reindex Omeka S resources into Qdrant vector database')
            ->setHelp(
                'This command indexes Omeka S resources (Items, Media, Collections) into ' .
                'the Qdrant vector database for semantic search. It supports filtering by ' .
                'resource type and configurable batch sizes for optimal performance.'
            )
            ->addOption(
                'type',
                't',
                InputOption::VALUE_OPTIONAL,
                'Resource type to reindex (item, media, collection). If not specified, all types are indexed.',
                null
            )
            ->addOption(
                'batch-size',
                'b',
                InputOption::VALUE_OPTIONAL,
                'Number of resources to process per batch',
                100
            )
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_OPTIONAL,
                'Maximum number of resources to index (for testing purposes)',
                null
            );
    }

    /**
     * Execute the command.
     *
     * Main command execution logic. Parses command options, initiates indexing
     * operations, displays progress bar, and outputs summary statistics.
     *
     * @param InputInterface $input Command input with options and arguments
     * @param OutputInterface $output Command output for user feedback
     * @return int Exit code (0 for success, 1 for failure)
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        // Parse command options
        $resourceType = $input->getOption('type');
        $batchSize = (int) $input->getOption('batch-size');
        $limit = $input->getOption('limit') ? (int) $input->getOption('limit') : null;

        // Validate options
        if ($batchSize <= 0) {
            $output->writeln('<error>Batch size must be a positive integer</error>');
            return Command::FAILURE;
        }

        if ($resourceType && !in_array($resourceType, ['item', 'media', 'collection'], true)) {
            $output->writeln('<error>Invalid resource type. Must be one of: item, media, collection</error>');
            return Command::FAILURE;
        }

        // Display command header
        $output->writeln('');
        $output->writeln('<info>Vector Reindexing Command</info>');
        $output->writeln('<info>========================</info>');
        $output->writeln('');

        if ($resourceType) {
            $output->writeln(sprintf('<comment>Resource Type:</comment> %s', ucfirst($resourceType)));
        } else {
            $output->writeln('<comment>Resource Type:</comment> All types (items, media, collections)');
        }
        $output->writeln(sprintf('<comment>Batch Size:</comment> %d', $batchSize));
        if ($limit) {
            $output->writeln(sprintf('<comment>Limit:</comment> %d resources', $limit));
        }
        $output->writeln('');

        try {
            // Execute reindexing with progress bar
            if ($resourceType) {
                // Reindex specific resource type with progress bar
                $entityClass = $this->getEntityClass($resourceType);
                $output->writeln(sprintf('<info>Starting reindex for %s resources...</info>', $resourceType));
                $output->writeln('');

                $this->reindexWithProgress($output, $entityClass, $batchSize, $limit);
            } else {
                // Reindex all resource types with progress bars
                $output->writeln('<info>Starting full reindex of all resource types...</info>');
                $output->writeln('');

                $this->reindexAllWithProgress($output, $batchSize, $limit);
            }

            // Calculate statistics
            $duration = microtime(true) - $startTime;
            $memoryUsed = memory_get_usage(true) - $startMemory;
            $peakMemory = memory_get_peak_usage(true);

            // Display success message with statistics
            $output->writeln('');
            $output->writeln('<info>✓ Reindexing completed successfully!</info>');
            $output->writeln('');
            $output->writeln('<comment>Statistics:</comment>');
            $output->writeln(sprintf('  Duration: %.2f seconds', $duration));
            $output->writeln(sprintf('  Memory used: %s', $this->formatBytes($memoryUsed)));
            $output->writeln(sprintf('  Peak memory: %s', $this->formatBytes($peakMemory)));
            $output->writeln('');

            return Command::SUCCESS;

        } catch (\Exception $e) {
            // Log error
            $this->logger->err(sprintf(
                '[VectorReindexCommand] Reindexing failed - Error: %s, Trace: %s',
                $e->getMessage(),
                $e->getTraceAsString()
            ));

            // Display error message
            $output->writeln('');
            $output->writeln('<error>✗ Reindexing failed!</error>');
            $output->writeln(sprintf('<error>Error: %s</error>', $e->getMessage()));
            $output->writeln('');

            return Command::FAILURE;
        }
    }

    /**
     * Reindex a specific resource type with progress bar visualization.
     *
     * Creates a Symfony ProgressBar to visualize indexing progress in real-time.
     * Uses the VectorIndexService for actual indexing operations while displaying
     * progress updates after each batch.
     *
     * @param OutputInterface $output Output interface for progress display
     * @param string $entityClass Fully qualified entity class name
     * @param int $batchSize Number of resources per batch
     * @param int|null $limit Maximum number of resources to index (null for all)
     * @return void
     * @throws \RuntimeException If indexing fails
     */
    private function reindexWithProgress(
        OutputInterface $output,
        string $entityClass,
        int $batchSize,
        ?int $limit = null
    ): void {
        // Get entity manager to count resources
        $entityManager = $this->entityManager;
        // @phpstan-ignore-next-line Doctrine 2.x getRepository() template can't infer dynamic class-string
        $repository = $entityManager->getRepository($entityClass);

        // Count total resources to process
        $totalCount = $repository->count([]);

        // Apply limit if specified
        if ($limit !== null && $limit < $totalCount) {
            $totalCount = $limit;
        }

        // Create progress bar
        $progressBar = new ProgressBar($output, $totalCount);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');
        $progressBar->start();

        // Track statistics
        $successCount = 0;
        $failureCount = 0;

        // Process resources in batches
        for ($offset = 0; $offset < $totalCount; $offset += $batchSize) {
            $currentBatchSize = min($batchSize, $totalCount - $offset);

            // Fetch batch of resources
            $resources = $repository->findBy([], null, $currentBatchSize, $offset);

            // Index each resource in batch
            foreach ($resources as $resource) {
                try {
                    // Call appropriate indexing method
                    if ($entityClass === \Omeka\Entity\Item::class) {
                        $this->vectorIndexService->indexItem($resource);
                    } elseif ($entityClass === \Omeka\Entity\Media::class) {
                        $this->vectorIndexService->indexMedia($resource);
                    } elseif ($entityClass === \Omeka\Entity\ItemSet::class) {
                        $this->vectorIndexService->indexCollection($resource);
                    }

                    $successCount++;
                } catch (\Exception $e) {
                    $failureCount++;
                    // Log error but continue processing
                    $this->logger->err('Failed to index resource', [
                        'resource_id' => method_exists($resource, 'id') ? $resource->id() : 'unknown',
                        'error' => $e->getMessage()
                    ]);
                }

                // Advance progress bar
                $progressBar->advance();
            }

            // Memory management: clear entity manager between batches
            $entityManager->clear();
            gc_collect_cycles();
        }

        $progressBar->finish();
        $output->writeln('');
        $output->writeln('');

        // Display batch statistics
        $output->writeln(sprintf('  <info>Processed:</info> %d resources', $successCount + $failureCount));
        $output->writeln(sprintf('  <info>Success:</info> %d', $successCount));
        if ($failureCount > 0) {
            $output->writeln(sprintf('  <comment>Failures:</comment> %d', $failureCount));
        }
    }

    /**
     * Reindex all resource types sequentially with progress bars.
     *
     * Iterates through Items, Media, and Collections, displaying a separate
     * progress bar for each resource type to visualize overall reindexing progress.
     *
     * @param OutputInterface $output Output interface for progress display
     * @param int $batchSize Number of resources per batch
     * @param int|null $limit Maximum number of resources per type (null for all)
     * @return void
     * @throws \RuntimeException If indexing fails
     */
    private function reindexAllWithProgress(
        OutputInterface $output,
        int $batchSize,
        ?int $limit = null
    ): void {
        $resourceTypes = [
            ['class' => \Omeka\Entity\Item::class, 'name' => 'Items'],
            ['class' => \Omeka\Entity\Media::class, 'name' => 'Media'],
            ['class' => \Omeka\Entity\ItemSet::class, 'name' => 'Collections'],
        ];

        foreach ($resourceTypes as $typeConfig) {
            $output->writeln(sprintf('<info>Indexing %s...</info>', $typeConfig['name']));
            $output->writeln('');

            $this->reindexWithProgress($output, $typeConfig['class'], $batchSize, $limit);

            $output->writeln('');
        }
    }

    /**
     * Get entity class name from resource type string.
     *
     * Maps user-friendly resource type names to Omeka entity class names.
     *
     * @param string $resourceType Resource type ('item', 'media', 'collection')
     * @return string Entity class name
     * @throws \InvalidArgumentException If resource type is invalid
     */
    private function getEntityClass(string $resourceType): string
    {
        $typeMap = [
            'item' => \Omeka\Entity\Item::class,
            'media' => \Omeka\Entity\Media::class,
            'collection' => \Omeka\Entity\ItemSet::class,
        ];

        if (!isset($typeMap[$resourceType])) {
            throw new \InvalidArgumentException(sprintf('Invalid resource type: %s', $resourceType));
        }

        return $typeMap[$resourceType];
    }

    /**
     * Format bytes into human-readable format.
     *
     * Converts byte count to appropriate unit (B, KB, MB, GB) for display.
     *
     * @param int $bytes Number of bytes
     * @param int $precision Decimal precision for display
     * @return string Formatted byte string (e.g., "1.5 MB")
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }
}
