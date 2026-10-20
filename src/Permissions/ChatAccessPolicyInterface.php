<?php
declare(strict_types=1);

namespace SearchAssistantAI\Permissions;

/**
 * May the current visitor use the search assistant (see the widget, call the chat API)?
 *
 * Resolved through the ChatAccessPolicyInterface::class service alias and
 * asked by the chat API, the widget injector and the searchAssistantWidget helper.
 */
interface ChatAccessPolicyInterface
{
    public function canChat(): bool;
}
