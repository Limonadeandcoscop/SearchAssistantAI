<?php
declare(strict_types=1);

namespace SearchAssistantAI;

// Load the module's own Composer dependencies if they've been installed
// into a bundled vendor/ (release zip) rather than via host-side composer.
$moduleAutoloader = __DIR__ . '/vendor/autoload.php';
if (file_exists($moduleAutoloader)) {
    require_once $moduleAutoloader;
}

use SearchAssistantAI\EventListener\OmekaSyncListener;
use SearchAssistantAI\EventListener\SharedListenerInterface;
use SearchAssistantAI\Service\Prompt\PromptProviderInterface;
use SearchAssistantAI\Service\Prompt\SeedablePromptProviderInterface;
use SearchAssistantAI\EventListener\WidgetInjector;
use SearchAssistantAI\Form\ConfigFormRenderer;
use SearchAssistantAI\Service\FileLogger;
use SearchAssistantAI\Service\VectorStore\CollectionCheckGate;
use SearchAssistantAI\Service\VectorStore\VectorStoreBootstrap;
use SearchAssistantAI\Service\VectorStore\VectorStoreInterface;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\Mvc\MvcEvent;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Module\AbstractModule;

class Module extends AbstractModule
{
    /** Tables of schema.sql (add-on modules drop their own tables). */
    private const TABLES = ['search_assistant_sync_queue'];

    /**
     * @return array<string, mixed>
     */
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    /**
     * Module bootstrap: ACL rules, JSON response strategy, logger binding,
     * vector-store collection self-check. Called once per request.
     */
    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);

        $services = $this->getServiceLocator();

        // Route the legacy FileLogger façade through the Omeka application
        // logger so the module has a single log stream (application.log).
        FileLogger::bind($services->get('Omeka\Logger'));

        $this->attachJsonResponseStrategy($event);
        $this->ensureVectorStoreCollectionExists();
        $this->seedPromptsFromExamples();
    }

    /**
     * Populate the three search_assistant_prompt_* settings from the shipped
     * example files under config/prompts/ if they are currently empty,
     * when the configured prompt provider stores prompts in settings.
     * This also covers a fresh install (the first request seeds them).
     *
     * Runs on every bootstrap but is gated by an in-memory flag and a
     * check on whether any setting is empty, so it's essentially a
     * no-op after the first seed.
     */
    private function seedPromptsFromExamples(): void
    {
        static $seeded = false;
        if ($seeded) {
            return;
        }
        $seeded = true;

        try {
            $services = $this->getServiceLocator();
            $provider = $services->get(PromptProviderInterface::class);
            if (!$provider instanceof SeedablePromptProviderInterface) {
                return;
            }
            $written = $provider->seedFromFilesOnce($services->get('Omeka\Settings'));
            if (!empty($written)) {
                $services->get('Omeka\Logger')->info(
                    '[SearchAssistantAI] Seeded prompt settings from example files',
                    ['keys' => $written]
                );
            }
        } catch (\Exception $e) {
            error_log('Warning: Could not seed search assistant prompts from example files: ' . $e->getMessage());
        }
    }

    /**
     * Hook in ViewJsonStrategy with high priority so the AJAX endpoints
     * return JSON instead of rendering missing view templates.
     */
    private function attachJsonResponseStrategy(MvcEvent $event): void
    {
        $application = $event->getApplication();
        $serviceManager = $application->getServiceManager();
        $serviceManager->get('ViewManager'); // warm the service
        $jsonStrategy = $serviceManager->get('ViewJsonStrategy');
        $jsonStrategy->attach($application->getEventManager(), 100);
    }

    /**
     * Lightweight once-per-request check that the configured vector-store
     * collection exists; creates it if missing. The per-request flag
     * avoids hitting the store on every request after the first.
     *
     * CollectionCheckGate skips the check until the module is configured and
     * spaces out retries after a failure, so an unreachable vector store
     * does not slow down every page; a success sets the persistent
     * `search_assistant_collection_verified` flag.
     */
    private function ensureVectorStoreCollectionExists(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        try {
            $services = $this->getServiceLocator();
            $gate = new CollectionCheckGate($services->get('Omeka\Settings'));
            if (!$gate->shouldCheck()) {
                return;
            }
            if ($this->initializeVectorStoreCollection($services)) {
                $gate->recordSuccess();
            } else {
                $gate->recordFailure();
            }
        } catch (\Exception $e) {
            error_log('Warning: Could not verify vector store collection: ' . $e->getMessage());
        }
    }

    /**
     * Install: create the module's table and import the data of the former
     * Search Assistant AI module, if any (see Install\ChatbotImport). Prompts are seeded
     * on the first request (see seedPromptsFromExamples()).
     *
     * Vector-store collection creation is intentionally NOT done here.
     * The admin has not yet configured the backend, URL, credentials, or
     * collection name, so probing whatever defaults ship in module.config
     * would just fail against a service the user never picked. The lazy
     * check in onBootstrap creates the collection on the first request
     * after the admin has saved real settings.
     *
     * @param \Interop\Container\ContainerInterface $serviceLocator
     */
    public function install($serviceLocator): void
    {
        /** @var \Omeka\Module\Manager $modules */
        $modules = $serviceLocator->get('Omeka\ModuleManager');
        $active = Install\ChatbotImport::activeOldModules(function (string $id) use ($modules): bool {
            $module = $modules->getModule($id);
            // getModule() returns false for a module that is not on disk.
            return $module instanceof \Omeka\Module\Module && $module->getState() === \Omeka\Module\Manager::STATE_ACTIVE;
        });
        if ($active !== []) {
            $translator = $serviceLocator->get('MvcTranslator');
            throw new \Omeka\Module\Exception\ModuleCannotInstallException(sprintf(
                $translator->translate('Deactivate the %s module first: Search Assistant AI replaces it and imports its settings and data.'), // @translate
                implode(', ', $active)
            ));
        }

        $connection = $serviceLocator->get('Omeka\Connection');
        $connection->executeStatement(file_get_contents(__DIR__ . '/data/install/schema.sql'));

        $import = new Install\ChatbotImport($connection);
        $import->importSettings();
        $import->copyTable('chatbot_sync_queue', 'search_assistant_sync_queue');
    }

    /**
     * Create the configured vector-store collection if it doesn't exist.
     * Errors are logged but don't propagate — the vector store may be
     * unreachable or unconfigured at the time this runs.
     *
     * @param \Interop\Container\ContainerInterface $serviceLocator
     * @return bool True if the collection was verified or created; false on failure.
     */
    private function initializeVectorStoreCollection($serviceLocator): bool
    {
        try {
            $vectorStore = $serviceLocator->get(VectorStoreInterface::class);
            $serviceLocator->get(VectorStoreBootstrap::class)->ensureCollection($vectorStore);
            return true;
        } catch (\Exception $e) {
            $serviceLocator->get('Omeka\Logger')->warn(
                '[SearchAssistantAI] Could not initialize vector store collection',
                ['error' => $e->getMessage()]
            );
            return false;
        }
    }

    /**
     * Uninstall: drop the module's table. Vector-store collection
     * is intentionally left alone — dropping a collection is destructive
     * and should happen from the dashboard's explicit "Clear vector
     * store" action rather than as a side effect of uninstall.
     */
    public function uninstall($serviceLocator): void
    {
        $connection = $serviceLocator->get('Omeka\Connection');
        foreach (self::TABLES as $table) {
            $connection->executeStatement("DROP TABLE IF EXISTS {$table}");
        }
    }

    /**
     * Render the module-configure page form. Concrete work lives in
     * ConfigFormRenderer.
     */
    public function getConfigForm(PhpRenderer $renderer): string
    {
        return (new ConfigFormRenderer($this->getServiceLocator()))->render($renderer);
    }

    /**
     * Handle the module-configure form submission. Concrete work lives
     * in ConfigFormRenderer.
     */
    public function handleConfigForm(AbstractController $controller): bool
    {
        return (new ConfigFormRenderer($this->getServiceLocator()))->handleSubmission($controller);
    }

    /**
     * Wire up the module's event listeners: Omeka entity lifecycle →
     * SyncService (via OmekaSyncListener), view.layout → widget
     * injection on public pages (via WidgetInjector), plus the listeners
     * of the `search_assistant.listeners` registry (e.g. conversation storage).
     */
    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        $services = $this->getServiceLocator();
        (new OmekaSyncListener($services))->attach($sharedEventManager);
        (new WidgetInjector($services))->attach($sharedEventManager);

        // Listeners other features register (search_assistant.listeners registry).
        foreach ($services->get('Config')['search_assistant']['listeners'] ?? [] as $class) {
            $listener = new $class($services);
            if ($listener instanceof SharedListenerInterface) {
                $listener->attach($sharedEventManager);
            }
        }
    }
}
