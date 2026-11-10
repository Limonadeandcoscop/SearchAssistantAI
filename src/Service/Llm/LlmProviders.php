<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\Llm;

use SearchAssistantAI\Service\FileLogger;

/**
 * Resolves the stored LLM provider key against the
 * `search_assistant.llm_providers` registry.
 *
 * A stored key with no registry entry (a provider another module used to
 * ship, and that module is no longer active) must not take the admin pages
 * or the chat down: it resolves to the first registered provider, which is
 * what the configure form offers and saves anyway.
 */
final class LlmProviders
{
    /**
     * @param array<string, mixed> $registry The `search_assistant.llm_providers` registry
     */
    public static function effective(array $registry, string $stored): string
    {
        if (isset($registry[$stored]) || $registry === []) {
            return $stored;
        }
        $fallback = (string) array_key_first($registry);
        FileLogger::getInstance()->warning(sprintf(
            '[SearchAssistantAI] LLM provider "%s" is not available (known: %s); using "%s". Save the module configuration to fix the setting.',
            $stored,
            implode(', ', array_keys($registry)),
            $fallback
        ));
        return $fallback;
    }
}
