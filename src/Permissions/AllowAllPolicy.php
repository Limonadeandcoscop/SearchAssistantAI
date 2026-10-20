<?php
declare(strict_types=1);

namespace SearchAssistantAI\Permissions;

/**
 * Every visitor, signed in or not, may use the search_assistant.
 */
class AllowAllPolicy implements ChatAccessPolicyInterface
{
    public function canChat(): bool
    {
        return true;
    }
}
