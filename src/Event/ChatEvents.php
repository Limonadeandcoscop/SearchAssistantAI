<?php
declare(strict_types=1);

namespace SearchAssistantAI\Event;

/**
 * Events triggered by the chat API (SearchAssistantAI\Controller\Site\ChatController).
 *
 * Listeners attach to the shared event manager with the controller class
 * as identifier. A listener failure is logged and never breaks the answer.
 */
final class ChatEvents
{
    /**
     * A valid question was accepted, before the pipeline runs.
     * Params: query, sessionId, context, filters, clientIpMasked, userAgent.
     */
    public const QUERY_RECEIVED = 'search_assistant.query.received';

    /**
     * The answer is ready (also after streaming, before the final frame).
     * Params: query, sessionId, result {answer, sources, suggestions},
     * responseTimeMs, streamed.
     */
    public const QUERY_ANSWERED = 'search_assistant.query.answered';

    /**
     * A public page is about to get the floating widget (identifier:
     * SearchAssistantAI\EventListener\WidgetInjector). Params: view (PhpRenderer),
     * routeName. A listener may add its own assets to the view and set the
     * `skip` param to true to keep the widget off that page.
     */
    public const WIDGET_RENDER = 'search_assistant.widget.render';
}
