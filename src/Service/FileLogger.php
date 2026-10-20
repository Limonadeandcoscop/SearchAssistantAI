<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service;

use Laminas\Log\LoggerInterface;

/**
 * Thin logging façade used throughout the Search Assistant AI module.
 *
 * Historically this wrote to its own `modules/SearchAssistantAI/logs/search-assistant.log` file,
 * which produced two parallel log streams (module file + Omeka application.log)
 * that always needed cross-referencing during debugging. It now delegates to
 * the Laminas logger supplied by Omeka, so everything lands in the standard
 * `omeka/logs/application.log` with consistent formatting and rotation.
 *
 * The `bind()` method is called from Module::onBootstrap once the service
 * manager is available. Until bound (e.g., during very early bootstrap or in
 * CLI test harnesses) calls fall back to PHP's error_log so nothing is lost.
 *
 * Retained as a static accessor to avoid a mechanical refactor of ~85 call
 * sites; new code should inject the Laminas logger directly via constructors.
 *
 * @deprecated Inject Laminas\Log\LoggerInterface instead. This façade exists
 *             for backwards compatibility and will be removed in a future
 *             major version.
 */
class FileLogger
{
    private static ?FileLogger $instance = null;

    private ?LoggerInterface $logger = null;

    private function __construct()
    {
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Bind the Omeka/Laminas logger so subsequent calls route through it.
     * Invoked from Module::onBootstrap().
     */
    public static function bind(LoggerInterface $logger): void
    {
        self::getInstance()->logger = $logger;
    }

    public function info(string $message): void
    {
        $this->write('info', $message);
    }

    public function warning(string $message): void
    {
        $this->write('warn', $message);
    }

    public function error(string $message): void
    {
        $this->write('err', $message);
    }

    public function debug(string $message): void
    {
        $this->write('debug', $message);
    }

    /**
     * Backwards-compat entry point. `$level` values in use historically are
     * INFO, WARNING, ERROR, DEBUG (uppercase strings).
     */
    public function log(string $message, string $level = 'INFO'): void
    {
        $map = [
            'INFO' => 'info',
            'WARNING' => 'warn',
            'ERROR' => 'err',
            'DEBUG' => 'debug',
        ];
        $this->write($map[strtoupper($level)] ?? 'info', $message);
    }

    private function write(string $priority, string $message): void
    {
        if ($this->logger !== null) {
            $this->logger->{$priority}('[SearchAssistantAI] ' . $message);
            return;
        }
        // Pre-bind fallback (e.g., early bootstrap) — don't silently drop.
        error_log('[SearchAssistantAI] ' . strtoupper($priority) . ': ' . $message);
    }
}
