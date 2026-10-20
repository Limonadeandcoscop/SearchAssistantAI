<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\Prompt;

use Omeka\Settings\Settings;

/**
 * A prompt provider that stores prompts in settings and fills them once
 * from the shipped files (called on bootstrap by Module).
 */
interface SeedablePromptProviderInterface extends PromptProviderInterface
{
    /**
     * @return list<string> Setting keys that were seeded (empty when already done)
     */
    public function seedFromFilesOnce(Settings $rawSettings): array;
}
