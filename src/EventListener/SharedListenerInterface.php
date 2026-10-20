<?php
declare(strict_types=1);

namespace SearchAssistantAI\EventListener;

use Laminas\EventManager\SharedEventManagerInterface;

/**
 * A listener listed in the `search_assistant.listeners` registry: Module builds it
 * with `new $class($services)` (the service container) and attaches it to
 * the shared event manager.
 */
interface SharedListenerInterface
{
    public function attach(SharedEventManagerInterface $events): void;
}
