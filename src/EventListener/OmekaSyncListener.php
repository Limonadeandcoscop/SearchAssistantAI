<?php
declare(strict_types=1);

namespace SearchAssistantAI\EventListener;

use SearchAssistantAI\Service\Indexing\SyncService;
use Interop\Container\ContainerInterface;
use Laminas\EventManager\SharedEventManagerInterface;

/**
 * Bridge Omeka's API lifecycle events into the module's SyncService.
 *
 * Each resource type (Item, Media, ItemSet) fires four events —
 * create/update/delete/batch_update — and every one needs to drive
 * the vector-store sync the same way. Previously this was twelve
 * near-identical methods on Module.php. Here it's three parametric
 * handlers + a small routing table.
 *
 * Omeka's shared event manager calls back on a registered callable, so
 * the public handle* methods are kept as entry points; wiring them up
 * lives in `attach()`.
 */
class OmekaSyncListener
{
    /**
     * Resource-type → (API adapter, string tag handed to SyncService::handleDelete).
     */
    private const RESOURCE_TYPES = [
        'item' => [
            'adapter' => 'Omeka\Api\Adapter\ItemAdapter',
            'api_name' => 'items',
            'delete_type' => 'items',
            'log_field' => 'item_id',
        ],
        'media' => [
            'adapter' => 'Omeka\Api\Adapter\MediaAdapter',
            'api_name' => 'media',
            'delete_type' => 'media',
            'log_field' => 'media_id',
        ],
        'item_set' => [
            'adapter' => 'Omeka\Api\Adapter\ItemSetAdapter',
            'api_name' => 'item_sets',
            'delete_type' => 'item_sets',
            'log_field' => 'item_set_id',
        ],
    ];

    private ContainerInterface $services;

    public function __construct(ContainerInterface $services)
    {
        $this->services = $services;
    }

    public function attach(SharedEventManagerInterface $events): void
    {
        foreach (self::RESOURCE_TYPES as $key => $meta) {
            $events->attach($meta['adapter'], 'api.create.post',       fn ($e) => $this->onCreate($e));
            $events->attach($meta['adapter'], 'api.update.post',       fn ($e) => $this->onUpdate($e));
            $events->attach($meta['adapter'], 'api.delete.post',       fn ($e) => $this->onDelete($e, $meta['delete_type']));
            $events->attach($meta['adapter'], 'api.batch_update.post', fn ($e) => $this->onBatchUpdate($e, $meta['api_name'], $meta['log_field']));
        }
    }

    /**
     * @param \Laminas\EventManager\Event $event
     */
    public function onCreate($event): void
    {
        $entity = $event->getParam('response')->getContent();
        $this->sync()->handleCreate($entity);
    }

    /**
     * @param \Laminas\EventManager\Event $event
     */
    public function onUpdate($event): void
    {
        $entity = $event->getParam('response')->getContent();
        $this->sync()->handleUpdate($entity);
    }

    /**
     * @param \Laminas\EventManager\Event $event
     */
    public function onDelete($event, string $resourceType): void
    {
        $entityId = $event->getParam('request')->getId();
        $this->sync()->handleDelete($entityId, $resourceType);
    }

    /**
     * Batch updates don't carry entity payloads — the event hands us only
     * the list of IDs. Re-fetch each through the API so SyncService gets
     * the same API-representation shape it sees in create/update paths.
     *
     * @param \Laminas\EventManager\Event $event
     */
    public function onBatchUpdate($event, string $apiName, string $logField): void
    {
        $ids = $event->getParam('request')->getIds();
        if (empty($ids)) {
            return;
        }

        $api = $this->services->get('Omeka\ApiManager');
        $logger = $this->services->get('Omeka\Logger');

        $entities = [];
        foreach ($ids as $id) {
            try {
                $entities[] = $api->read($apiName, $id)->getContent();
            } catch (\Exception $e) {
                // Already-deleted / inaccessible rows skip the batch.
                $logger->warning("Failed to fetch {$apiName} for batch update", [
                    $logField => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (!empty($entities)) {
            $this->sync()->handleBatchUpdate($entities);
        }
    }

    private function sync(): SyncService
    {
        return $this->services->get('SyncService');
    }
}
