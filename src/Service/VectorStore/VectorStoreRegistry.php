<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\VectorStore;

use SearchAssistantAI\Service\FileLogger;
use SearchAssistantAI\Settings\SearchAssistantSettings;
use RuntimeException;

/**
 * Vector-store backends declared in the `search_assistant.vector_store_backends`
 * config registry, keyed by the value stored in `search_assistant_vector_store_backend`:
 *
 *     'qdrant' => [
 *         'label' => 'Qdrant',
 *         'service' => QdrantService::class,
 *         'collection_setting' => 'search_assistant_qdrant_collection',
 *     ]
 *
 * Another module adds a backend by adding an entry. This is the single place
 * that answers "which backend, which collection".
 */
class VectorStoreRegistry
{
    public const DEFAULT_COLLECTION = SearchAssistantSettings::DEFAULT_COLLECTION;

    /** @var array<string, array{label?: string, service?: string, collection_setting?: string, url_setting?: string}> */
    private array $backends;

    private SearchAssistantSettings $settings;

    /**
     * @param array<string, array{label?: string, service?: string, collection_setting?: string, url_setting?: string}> $backends
     */
    public function __construct(array $backends, SearchAssistantSettings $settings)
    {
        $this->backends = $backends;
        $this->settings = $settings;
    }

    /**
     * Effective backend key: the stored one (default: qdrant), or the first
     * registered backend when the stored key has no registry entry (a
     * backend another module used to ship, and that module is no longer
     * active). The admin pages and the chat keep working; the configure
     * form shows and saves the fallback.
     */
    public function getBackend(): string
    {
        $stored = $this->settings->getVectorStoreBackendSetting();
        if (isset($this->backends[$stored]) || $this->backends === []) {
            return $stored;
        }
        $fallback = (string) array_key_first($this->backends);
        FileLogger::getInstance()->warning(sprintf(
            '[SearchAssistantAI] Vector store backend "%s" is not available (known: %s); using "%s". Save the module configuration to fix the setting.',
            $stored,
            implode(', ', array_keys($this->backends)),
            $fallback
        ));
        return $fallback;
    }

    public function isAvailable(string $backend): bool
    {
        return isset($this->backends[$backend]['service']);
    }

    /** Service name of the configured backend. */
    public function getServiceName(): string
    {
        $backend = $this->getBackend();
        if (!$this->isAvailable($backend)) {
            throw new RuntimeException(sprintf(
                'Vector store backend "%s" is not available. Known backends: %s',
                $backend,
                implode(', ', array_keys($this->backends))
            ));
        }
        return (string) $this->backends[$backend]['service'];
    }

    /** Collection (Qdrant) or index (Elasticsearch) name of the configured backend. */
    public function getCollectionName(): string
    {
        $key = $this->backends[$this->getBackend()]['collection_setting'] ?? '';
        if ($key === '') {
            return self::DEFAULT_COLLECTION;
        }
        $name = (string) $this->settings->raw()->get($key, self::DEFAULT_COLLECTION);
        return $name !== '' ? $name : self::DEFAULT_COLLECTION;
    }

    /**
     * Server URL of the configured backend, from the setting its registry
     * entry names (`url_setting`); empty when the entry has none.
     */
    public function getServerUrl(): string
    {
        $key = $this->backends[$this->getBackend()]['url_setting'] ?? '';
        return $key === '' ? '' : trim((string) $this->settings->raw()->get($key, ''));
    }

    /** Human-readable name of the configured backend. */
    public function getLabel(): string
    {
        $backend = $this->getBackend();
        return (string) ($this->backends[$backend]['label'] ?? ucfirst($backend));
    }

    /**
     * @return array<string, string> backend key => label, for select options
     */
    public function getLabels(): array
    {
        $labels = [];
        foreach ($this->backends as $key => $entry) {
            $labels[$key] = (string) ($entry['label'] ?? ucfirst($key));
        }
        return $labels;
    }
}
