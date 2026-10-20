<?php
declare(strict_types=1);

namespace SearchAssistantAITest\Support;

use Interop\Container\ContainerInterface;
use Laminas\Stdlib\ArrayUtils;
use Omeka\Settings\Settings;

/**
 * Minimal service container for unit tests: resolves the module's services
 * through the factories and delegators declared in config/module.config.php,
 * like the Laminas service manager, with Omeka settings backed by an array.
 */
final class ModuleServices implements ContainerInterface
{
    /** @var array<string, mixed> */
    private array $config;

    /** @var array<string, mixed> */
    private array $services;

    /** @var array<string, mixed> */
    private array $instances = [];

    /**
     * @param array<string, mixed> $services Services provided as is (by name)
     * @param array<string, mixed> $extraConfig Merged into the module config
     */
    public function __construct(array $services, array $extraConfig = [])
    {
        $config = require dirname(__DIR__, 2) . '/config/module.config.php';
        // Same merge as Laminas' ConfigListener for a module loaded after this one.
        $this->config = ArrayUtils::merge($config, $extraConfig);
        $this->services = $services + ['Config' => $this->config];
    }

    /**
     * Container with the given Omeka settings service and a no-op logger.
     *
     * @param array<string, mixed> $services Extra services by name
     * @param array<string, mixed> $extraConfig Merged into the module config
     */
    public static function withSettings(Settings $settings, array $services = [], array $extraConfig = []): self
    {
        return new self($services + [
            'Omeka\Settings' => $settings,
            'Omeka\Logger' => new \Laminas\Log\Logger(['writers' => [['name' => 'noop']]]),
        ], $extraConfig);
    }

    /**
     * @param string $id
     * @return mixed
     */
    public function get($id)
    {
        if (array_key_exists($id, $this->services)) {
            return $this->services[$id];
        }
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        $sm = $this->config['service_manager'];
        if (isset($sm['aliases'][$id])) {
            return $this->get($sm['aliases'][$id]);
        }

        $create = function () use ($id, $sm) {
            if (isset($sm['factories'][$id])) {
                $factory = $sm['factories'][$id];
                return (is_string($factory) ? new $factory() : $factory)($this, $id);
            }
            if (isset($sm['invokables'][$id])) {
                $class = $sm['invokables'][$id];
                return new $class();
            }
            throw new \RuntimeException("No service named {$id}");
        };
        foreach ($sm['delegators'][$id] ?? [] as $delegator) {
            $next = $create;
            $create = function () use ($delegator, $id, $next) {
                return (new $delegator())($this, $id, $next);
            };
        }

        return $this->instances[$id] = $create();
    }

    /**
     * @param string $id
     */
    public function has($id): bool
    {
        $sm = $this->config['service_manager'];
        return array_key_exists($id, $this->services)
            || isset($sm['factories'][$id]) || isset($sm['invokables'][$id]) || isset($sm['aliases'][$id]);
    }
}
