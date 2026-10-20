<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\Indexing;

use Laminas\Log\LoggerInterface;
use Omeka\Settings\Settings;

/**
 * Pure policy component: decides *how* an incoming sync event should be
 * handled, without touching the queue, database, or indexer.
 *
 * Two questions live here:
 *
 *   - getSyncMode() — the admin-configured global mode
 *     ('synchronous' / 'asynchronous' / 'batch'). Defaults to
 *     'asynchronous' when unset or invalid.
 *   - shouldProcessAsync(\$entity) — per-entity routing. Small entities
 *     get indexed inline for sub-second search availability; heavy ones
 *     (many properties, many media) get pushed to the queue so the
 *     edit-save round-trip stays fast.
 *
 * Extracted from SyncService during the v1.0 → v1.1 refactor so the
 * routing rules can be tuned independently of the event handlers that
 * consume them, and so they're cheap to unit-test in isolation.
 */
class SyncPolicy
{
    private const VALID_MODES = ['synchronous', 'asynchronous', 'batch'];
    private const DEFAULT_MODE = 'asynchronous';

    private Settings $settings;
    private LoggerInterface $logger;

    /** Property count above which an entity is considered "heavy". */
    private int $asyncPropertyThreshold;

    /** Media-attachment count above which an Item is considered "heavy". */
    private int $asyncMediaThreshold;

    public function __construct(
        Settings $settings,
        LoggerInterface $logger,
        int $asyncPropertyThreshold = 50,
        int $asyncMediaThreshold = 10
    ) {
        $this->settings = $settings;
        $this->logger = $logger;
        $this->asyncPropertyThreshold = $asyncPropertyThreshold;
        $this->asyncMediaThreshold = $asyncMediaThreshold;
    }

    public function getSyncMode(): string
    {
        $mode = (string) $this->settings->get('search_assistant_sync_mode', self::DEFAULT_MODE);
        if (!in_array($mode, self::VALID_MODES, true)) {
            $this->logger->warn('SyncPolicy: Invalid sync mode configured, using default', [
                'configured_mode' => $mode,
                'default_mode' => self::DEFAULT_MODE,
            ]);
            return self::DEFAULT_MODE;
        }
        return $mode;
    }

    /**
     * Route an entity to the queue (true) or handle it inline (false).
     * On any error reading entity metadata, we pick "async" as the safe
     * default — the queue will pick it up on the next pass.
     *
     * @param mixed $entity
     */
    public function shouldProcessAsync($entity): bool
    {
        try {
            $propertyCount = 0;
            if (method_exists($entity, 'getValues')) {
                $values = $entity->getValues();
                $propertyCount = $values ? count($values) : 0;
            }

            if ($propertyCount > $this->asyncPropertyThreshold) {
                return true;
            }

            if ($entity instanceof \Omeka\Entity\Item && method_exists($entity, 'getMedia')) {
                $media = $entity->getMedia();
                $mediaCount = $media ? count($media) : 0;
                if ($mediaCount > $this->asyncMediaThreshold) {
                    return true;
                }
            }

            return false;
        } catch (\Exception $e) {
            $this->logger->warn('SyncPolicy: Error determining async status, defaulting to async', [
                'error' => $e->getMessage(),
            ]);
            return true;
        }
    }
}
