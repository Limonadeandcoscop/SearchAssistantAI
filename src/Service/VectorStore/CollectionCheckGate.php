<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\VectorStore;

use Omeka\Settings\Settings;
use SearchAssistantAI\Settings\SearchAssistantSettings;

/**
 * Decides whether a request should probe the vector store to create the
 * configured collection (Module::onBootstrap).
 *
 * The probe runs on any request, public pages included, so it is skipped
 * until the admin has saved the configure form (the shipped defaults point
 * at services nobody chose) and, after a failure, until a retry delay has
 * passed instead of on every request.
 */
class CollectionCheckGate
{
    public const RETRY_DELAY = 300;

    private Settings $settings;

    /** @var callable(): int */
    private $clock;

    /**
     * @param (callable(): int)|null $clock Current Unix time; tests pass a fixed one.
     */
    public function __construct(Settings $settings, ?callable $clock = null)
    {
        $this->settings = $settings;
        $this->clock = $clock ?? 'time';
    }

    public function shouldCheck(): bool
    {
        if ($this->settings->get(SearchAssistantSettings::KEY_COLLECTION_VERIFIED, false)) {
            return false;
        }
        // The configure form always saves the backend: unset means not configured yet.
        if ($this->settings->get(SearchAssistantSettings::KEY_BACKEND) === null) {
            return false;
        }
        return (int) $this->settings->get(SearchAssistantSettings::KEY_COLLECTION_RETRY_AT, 0) <= ($this->clock)();
    }

    public function recordSuccess(): void
    {
        $this->settings->set(SearchAssistantSettings::KEY_COLLECTION_VERIFIED, true);
        $this->settings->delete(SearchAssistantSettings::KEY_COLLECTION_RETRY_AT);
    }

    public function recordFailure(): void
    {
        $this->settings->set(SearchAssistantSettings::KEY_COLLECTION_RETRY_AT, ($this->clock)() + self::RETRY_DELAY);
    }
}
