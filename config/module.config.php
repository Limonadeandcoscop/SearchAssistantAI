<?php
return [
    'service_manager' => [
        'aliases' => [
            'VectorIndexService' => \SearchAssistantAI\Service\Indexing\VectorIndexService::class,
            'SyncService' => \SearchAssistantAI\Service\Indexing\SyncService::class,
            'SemanticSearchService' => \SearchAssistantAI\Service\Search\SemanticSearchService::class,
            // Extension points an add-on module re-points in its own config:
            // criteria extraction and re-ranking of the RAG pipeline (none by
            // default), who may chat (everyone), and where prompts come from
            // (the files shipped in config/prompts).
            \SearchAssistantAI\Service\Search\CriteriaExtractorInterface::class => \SearchAssistantAI\Service\Search\PassThroughCriteriaExtractor::class,
            \SearchAssistantAI\Service\Search\ResultScorerInterface::class => \SearchAssistantAI\Service\Search\IdentityScorer::class,
            \SearchAssistantAI\Permissions\ChatAccessPolicyInterface::class => \SearchAssistantAI\Permissions\AllowAllPolicy::class,
            \SearchAssistantAI\Service\Prompt\PromptProviderInterface::class => \SearchAssistantAI\Service\Prompt\FilePromptProvider::class,
        ],
        'invokables' => [
            \SearchAssistantAI\Job\VectorIndexJob::class => \SearchAssistantAI\Job\VectorIndexJob::class,
            \SearchAssistantAI\Job\IndexEntityJob::class => \SearchAssistantAI\Job\IndexEntityJob::class,
            \SearchAssistantAI\Job\DeleteEntityJob::class => \SearchAssistantAI\Job\DeleteEntityJob::class,
            \SearchAssistantAI\Job\ReindexJob::class => \SearchAssistantAI\Job\ReindexJob::class,
            \SearchAssistantAI\Service\Search\PassThroughCriteriaExtractor::class => \SearchAssistantAI\Service\Search\PassThroughCriteriaExtractor::class,
            \SearchAssistantAI\Service\Search\IdentityScorer::class => \SearchAssistantAI\Service\Search\IdentityScorer::class,
            \SearchAssistantAI\Permissions\AllowAllPolicy::class => \SearchAssistantAI\Permissions\AllowAllPolicy::class,
        ],
        'factories' => [
            // The configured vector store; backends come from search_assistant.vector_store_backends.
            \SearchAssistantAI\Service\VectorStore\VectorStoreInterface::class => \SearchAssistantAI\Service\VectorStore\VectorStoreFactory::class,
            \SearchAssistantAI\Service\VectorStore\VectorStoreRegistry::class => \SearchAssistantAI\Service\VectorStore\VectorStoreRegistryFactory::class,
            \SearchAssistantAI\Service\VectorStore\VectorStoreBootstrap::class => \SearchAssistantAI\Service\VectorStore\VectorStoreBootstrapFactory::class,
            \SearchAssistantAI\Service\VectorStore\QdrantService::class => \SearchAssistantAI\Service\VectorStore\QdrantServiceFactory::class,
            \SearchAssistantAI\Service\Search\QueryProcessorService::class => \SearchAssistantAI\Service\Search\QueryProcessorServiceFactory::class,
            \SearchAssistantAI\Service\RateLimit\RateLimitService::class => \SearchAssistantAI\Service\RateLimit\RateLimitServiceFactory::class,
            \SearchAssistantAI\Service\Prompt\FilePromptProvider::class => \SearchAssistantAI\Service\Prompt\FilePromptProviderFactory::class,
            \SearchAssistantAI\Service\Embedding\EmbeddingService::class => \SearchAssistantAI\Service\Embedding\EmbeddingServiceFactory::class,
            \SearchAssistantAI\Service\Indexing\VectorIndexService::class => \SearchAssistantAI\Service\Indexing\VectorIndexServiceFactory::class,
            \SearchAssistantAI\Service\Indexing\SyncService::class => \SearchAssistantAI\Service\Indexing\SyncServiceFactory::class,
            \SearchAssistantAI\Command\VectorReindexCommand::class => \SearchAssistantAI\Command\VectorReindexCommandFactory::class,
            // The configured LLM provider; consumers depend on LlmInterface only.
            \SearchAssistantAI\Service\Llm\LlmInterface::class => \SearchAssistantAI\Service\Llm\LlmServiceFactory::class,
            \SearchAssistantAI\Service\Llm\OllamaService::class => \SearchAssistantAI\Service\Llm\OllamaServiceFactory::class,
            \SearchAssistantAI\Service\Llm\OllamaHealthCheck::class => \SearchAssistantAI\Service\Llm\OllamaHealthCheckFactory::class,
            \SearchAssistantAI\Service\Analytics\HealthCheckerInterface::class => \SearchAssistantAI\Service\Analytics\HealthCheckerFactory::class,
            \SearchAssistantAI\Service\Analytics\StatsService::class => \SearchAssistantAI\Service\Analytics\StatsServiceFactory::class,
            \SearchAssistantAI\Service\Search\SemanticSearchService::class => \SearchAssistantAI\Service\Search\SemanticSearchServiceFactory::class,
        ],
        'delegators' => [
            // Add public access rules for Search Assistant AI controllers after ACL is created
            'Omeka\Acl' => [
                \SearchAssistantAI\Service\AclDelegatorFactory::class,
            ],
        ],
    ],
    'form_elements' => [
        'factories' => [
            \SearchAssistantAI\Form\ConfigForm::class => \SearchAssistantAI\Form\ConfigFormFactory::class,
        ],
        'invokables' => [
            \SearchAssistantAI\Form\DashboardActionForm::class => \SearchAssistantAI\Form\DashboardActionForm::class,
        ],
    ],
    'view_helpers' => [
        'factories' => [
            'searchAssistantWidget' => \SearchAssistantAI\View\Helper\SearchAssistantWidgetFactory::class,
        ],
    ],
    'laminas-cli' => [
        'commands' => [
            'vector:reindex' => \SearchAssistantAI\Command\VectorReindexCommand::class,
        ],
    ],
    'controllers' => [
        'invokables' => [
            'SearchAssistantAI\Controller\Admin\Index' => \SearchAssistantAI\Controller\Admin\IndexController::class,
            'SearchAssistantAI\Controller\Admin\Config' => \SearchAssistantAI\Controller\Admin\ConfigController::class,
            'SearchAssistantAI\Controller\Admin\Reindex' => \SearchAssistantAI\Controller\Admin\ReindexController::class,
        ],
        'factories' => [
            'SearchAssistantAI\Controller\Admin\Dashboard' => function ($serviceManager) {
                $statsService = $serviceManager->get('SearchAssistantAI\Service\Analytics\StatsService');
                $syncService = $serviceManager->get('SyncService');
                $logger = $serviceManager->get('Omeka\Logger');
                $vectorStore = $serviceManager->get(\SearchAssistantAI\Service\VectorStore\VectorStoreInterface::class);
                $embeddingService = $serviceManager->get(\SearchAssistantAI\Service\Embedding\EmbeddingService::class);
                return new \SearchAssistantAI\Controller\Admin\DashboardController(
                    $statsService,
                    $syncService,
                    $logger,
                    $vectorStore,
                    $embeddingService,
                    $serviceManager->get(\SearchAssistantAI\Service\VectorStore\VectorStoreRegistry::class),
                    $serviceManager->get(\SearchAssistantAI\Service\VectorStore\VectorStoreBootstrap::class),
                    $serviceManager->get('Config')['search_assistant']['llm_providers'] ?? [],
                    ...\SearchAssistantAI\Controller\Admin\DashboardControllerPanels::resolve($serviceManager)
                );
            },
            'SearchAssistantAI\Controller\Site\Chat' => \SearchAssistantAI\Controller\Site\ChatControllerFactory::class,
        ],
    ],
    'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'search-assistant' => [
                        'type' => 'Literal',
                        'options' => [
                            'route' => '/search-assistant',
                            'defaults' => [
                                '__NAMESPACE__' => 'SearchAssistantAI\Controller\Admin',
                                'controller' => 'Index',
                                'action' => 'index',
                            ],
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            'dashboard' => [
                                'type' => 'Literal',
                                'options' => [
                                    'route' => '/dashboard',
                                    'defaults' => [
                                        'controller' => 'Dashboard',
                                        'action' => 'index',
                                    ],
                                ],
                                'may_terminate' => true,
                                'child_routes' => [
                                    'stats' => [
                                        'type' => 'Literal',
                                        'options' => [
                                            'route' => '/stats',
                                            'defaults' => [
                                                'action' => 'stats',
                                            ],
                                        ],
                                    ],
                                    'reindex' => [
                                        'type' => 'Literal',
                                        'options' => [
                                            'route' => '/reindex',
                                            'defaults' => [
                                                'action' => 'reindex',
                                            ],
                                        ],
                                    ],
                                    'sync' => [
                                        'type' => 'Literal',
                                        'options' => [
                                            'route' => '/sync',
                                            'defaults' => [
                                                'action' => 'sync',
                                            ],
                                        ],
                                    ],
                                    'clear-cache' => [
                                        'type' => 'Literal',
                                        'options' => [
                                            'route' => '/clear-cache',
                                            'defaults' => [
                                                'action' => 'clear-cache',
                                            ],
                                        ],
                                    ],
                                    'clear-vector-store' => [
                                        'type' => 'Literal',
                                        'options' => [
                                            'route' => '/clear-vector-store',
                                            'defaults' => [
                                                'action' => 'clear-vector-store',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            'config' => [
                                'type' => 'Literal',
                                'options' => [
                                    'route' => '/config',
                                    'defaults' => [
                                        'controller' => 'Config',
                                        'action' => 'index',
                                    ],
                                ],
                                'may_terminate' => true,
                                'child_routes' => [
                                    'test-qdrant' => [
                                        'type' => 'Literal',
                                        'options' => [
                                            'route' => '/test-qdrant',
                                            'defaults' => [
                                                'action' => 'test-qdrant',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            'reindex' => [
                                'type' => 'Segment',
                                'options' => [
                                    'route' => '/reindex[/:action]',
                                    'constraints' => [
                                        'action' => 'index|preview|launch|progress|cancel',
                                    ],
                                    'defaults' => [
                                        'controller' => 'Reindex',
                                        'action' => 'index',
                                    ],
                                ],
                                'may_terminate' => true,
                            ],
                        ],
                    ],
                ],
            ],
            'search-assistant-api' => [
                'type' => 'Literal',
                'options' => [
                    'route' => '/search-assistant/api',
                    'defaults' => [
                        '__NAMESPACE__' => 'SearchAssistantAI\Controller\Site',
                        'controller' => 'Chat',
                    ],
                ],
                'may_terminate' => false,
                'child_routes' => [
                    'query' => [
                        'type' => 'Literal',
                        'options' => [
                            'route' => '/query',
                            'defaults' => [
                                'action' => 'query',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            OMEKA_PATH . '/modules/SearchAssistantAI/view',
        ],
        'strategies' => [
            'ViewJsonStrategy',
        ],
    ],
    'navigation' => [
        // String keys (not a list) so another module can add pages to this
        // menu: navigation.AdminModule.search_assistant.pages.<key>, placed by 'order'.
        'AdminModule' => [
            'search_assistant' => [
                'label' => 'Search Assistant AI',
                'route' => 'admin/search-assistant',
                'resource' => 'SearchAssistantAI\Controller\Admin\Index',
                'pages' => [
                    'dashboard' => [
                        'label' => 'Dashboard',
                        'route' => 'admin/search-assistant/dashboard',
                        'resource' => 'SearchAssistantAI\Controller\Admin\Dashboard',
                        'order' => 10,
                    ],
                    'interface' => [
                        'label' => 'Interface',
                        'route' => 'admin/search-assistant/config',
                        'resource' => 'SearchAssistantAI\Controller\Admin\Config',
                        'order' => 30,
                    ],
                ],
            ],
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => \Laminas\I18n\Translator\Loader\Gettext::class,
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
    'search_assistant' => [
        // Extension points: other modules add string-keyed entries, merged
        // into these lists (modules load in alphabetical order).
        //
        // Shared-event listeners (SharedListenerInterface), attached by Module.
        'listeners' => [],
        // LLM providers, keyed by the search_assistant_llm_provider value.
        'llm_providers' => [
            'ollama' => [
                'label' => 'Self-hosted (Ollama or OpenAI-compatible)',
                'service' => \SearchAssistantAI\Service\Llm\OllamaService::class,
                'health' => \SearchAssistantAI\Service\Llm\OllamaHealthCheck::class,
            ],
        ],
        // Vector-store backends, keyed by the search_assistant_vector_store_backend value.
        'vector_store_backends' => [
            'qdrant' => [
                'label' => 'Qdrant',
                'service' => \SearchAssistantAI\Service\VectorStore\QdrantService::class,
                'collection_setting' => 'search_assistant_qdrant_collection',
            ],
        ],
        // Module configure page: form sections (add fields), layout (fieldsets
        // and subgroups, sorted by 'order'), and the settings whose change
        // requires a reindex.
        'config_form' => [
            'sections' => [
                'vector_store' => \SearchAssistantAI\Form\Section\VectorStoreSection::class,
                'embeddings' => \SearchAssistantAI\Form\Section\EmbeddingSection::class,
                'llm' => \SearchAssistantAI\Form\Section\LlmSection::class,
                'interface' => \SearchAssistantAI\Form\Section\InterfaceSection::class,
                'advanced' => \SearchAssistantAI\Form\Section\AdvancedSection::class,
            ],
            'layout' => [
                'vector_store' => [
                    'order' => 20,
                    'title' => 'Vector Store',
                    'id' => 'search-assistant-section-vector-store',
                    'fields' => ['search_assistant_vector_store_backend'],
                    'subgroups' => [
                        'qdrant' => [
                            'id' => 'search-assistant-subgroup-qdrant',
                            'title' => 'Qdrant',
                            'fields' => ['search_assistant_qdrant_url', 'search_assistant_qdrant_collection', 'search_assistant_qdrant_api_key'],
                        ],
                    ],
                ],
                'embeddings' => [
                    'order' => 30,
                    'title' => 'Embeddings',
                    'id' => 'search-assistant-section-embeddings',
                    'fields' => ['search_assistant_embeddings_url', 'search_assistant_embeddings_api_format', 'search_assistant_embeddings_model', 'search_assistant_embedding_dimension'],
                ],
                'llm' => [
                    'order' => 40,
                    'title' => 'LLM Provider',
                    'id' => 'search-assistant-section-llm',
                    'fields' => ['search_assistant_llm_provider'],
                    'subgroups' => [
                        'ollama' => [
                            'id' => 'search-assistant-subgroup-ollama',
                            'title' => 'Self-hosted LLM',
                            'fields' => ['search_assistant_llm_api_format', 'search_assistant_ollama_url', 'search_assistant_ollama_model'],
                        ],
                    ],
                ],
                'advanced' => [
                    'order' => 50,
                    'title' => 'Advanced Settings',
                    'id' => 'search-assistant-section-advanced',
                    'fields' => [
                        'search_assistant_verbose_logging',
                    ],
                ],
            ],
            // Stylesheets and scripts of the configure page: [path, module].
            'assets' => [
                'configure_css' => ['css/admin-configure.css', 'SearchAssistantAI'],
                'configure_js' => ['js/admin-configure.js', 'SearchAssistantAI'],
            ],
            // setting => default; a change flags the collection for re-check and asks for a reindex.
            'reindex_watch' => [
                'search_assistant_vector_store_backend' => 'qdrant',
            ],
        ],
        // Admin dashboard panels: a partial ('template') and the service
        // providing its data ('stats', a DashboardStatsContributorInterface).
        'dashboard' => [
            'panels' => [],
        ],
        'settings' => [
            'qdrant_url' => 'http://localhost:6333',
            'embeddings_url' => 'http://embeddings:8000/embed',
            'search_assistant_sync_mode' => 'asynchronous', // synchronous|asynchronous|batch
            'search_assistant_queue_batch_size' => 10,
            'search_assistant_sync_log_level' => 'info',
            'search_assistant_auto_process_queue' => true,
            // Token limit settings for Claude API compliance
            'search_assistant_max_context_tokens' => 150000, // Max tokens for search context (leave headroom for response)
            'search_assistant_max_payload_chars' => 5000, // Max characters per result payload
            'search_assistant_max_properties' => 20, // Max vocabulary properties per result (0 to disable)
        ],
    ],
];
