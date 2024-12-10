<?php
/**
 * StateIntegrations - Advanced State Integration System
 * 
 * Provides enterprise-level integration capabilities for the nCore StateManager,
 * enabling seamless interaction with WordPress core systems and custom managers.
 * 
 * Features:
 * - Real-time state synchronization with WordPress Customizer
 * - Bidirectional data flow with CubeFaceManager
 * - Optimized cache integration with atomic operations
 * - Advanced hook management with prioritization
 * 
 * @package     nCore
 * @subpackage  StateManager\Integrations
 * @version     2.0.0
 */

namespace nCore\StateManager\Integrations;

use nCore\Core\ModuleInterface;
use nCore\Core\CubeFaceManagerInterface;
use nCore\Core\CacheManagerInterface;
use nCore\StateManager\Events\StateChangeEvent;

class StateIntegrations {
    /** @var array Registered integration handlers */
    private $handlers = [];

    /** @var array Integration configurations */
    private $config = [];

    /** @var array Active hooks registry */
    private $activeHooks = [];

    /** @var array Integration metrics */
    private $metrics = [];

    /** @var \WP_Customize_Manager WordPress customizer instance */
    private $wpCustomizer;

    /** @var CacheManagerInterface Cache manager instance */
    private $cacheManager;

    /** @var array Customizer settings map */
    private $customizerMap = [];

    /** @var bool Debug mode flag */
    private $debug;

    /** @var array Default customizer options */
    private const DEFAULT_CUSTOMIZER_OPTIONS = [
        'preview_mode' => true,
        'sync_interval' => 100,
        'transport' => 'postMessage',
        'capability' => 'edit_theme_options'
    ];

    /** @var array Hook priority map */
    private const HOOK_PRIORITIES = [
        'customizer' => 10,
        'cache' => 20,
        'face' => 30,
        'state' => 40
    ];

    /**
     * Initialize integrations system
     */
    public function __construct(array $config = [], bool $debug = false) {
        $this->config = $config;
        $this->debug = $debug;
        $this->initializeMetrics();
    }

    /**
     * Register WordPress Customizer integration
     */
    public function registerThemeCustomizer(array $options = []): void {
        try {
            // Merge options with defaults
            $options = array_merge(self::DEFAULT_CUSTOMIZER_OPTIONS, $options);

            // Initialize customizer hooks
            add_action('customize_register', function($wp_customize) use ($options) {
                $this->wpCustomizer = $wp_customize;
                $this->initializeCustomizerSettings($options);
            }, self::HOOK_PRIORITIES['customizer']);

            // Handle real-time preview updates
            if ($options['preview_mode']) {
                add_action('customize_preview_init', function() {
                    $this->enqueueCustomizerPreview();
                });
            }

            // Register customizer save handler
            add_action('customize_save_after', function($wp_customize) {
                $this->handleCustomizerSave($wp_customize);
            });

            $this->logIntegration('customizer', $options);

        } catch (\Exception $e) {
            $this->handleIntegrationError('customizer_registration_failed', $e);
        }
    }

    /**
     * Register CubeFaceManager integration
     */
    public function registerCubeFaceManager(CubeFaceManagerInterface $manager): void {
        try {
            // Register state change listener
            $manager->onStateChange(function($event) {
                $this->handleFaceStateChange($event);
            });

            // Set up bidirectional sync
            $this->handlers['cube_face'] = [
                'manager' => $manager,
                'sync_enabled' => true,
                'last_sync' => microtime(true)
            ];

            // Register face-specific hooks
            $this->registerFaceHooks($manager);

            $this->logIntegration('cube_face', [
                'manager_class' => get_class($manager)
            ]);

        } catch (\Exception $e) {
            $this->handleIntegrationError('face_manager_registration_failed', $e);
        }
    }

    /**
     * Handle WordPress hooks registration and management
     */
    public function handleWordPressHooks(): void {
        try {
            // Core state hooks
            add_action('init', [$this, 'initializeStateHooks'], self::HOOK_PRIORITIES['state']);
            add_action('admin_init', [$this, 'initializeAdminHooks']);
            add_action('wp_loaded', [$this, 'synchronizeState']);

            // AJAX handlers
            add_action('wp_ajax_update_state', [$this, 'handleStateUpdate']);
            add_action('wp_ajax_nopriv_update_state', [$this, 'handleStateUpdate']);

            // REST API endpoints
            add_action('rest_api_init', function() {
                $this->registerStateEndpoints();
            });

            // Asset management
            add_action('wp_enqueue_scripts', [$this, 'enqueueIntegrationAssets']);
            add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);

            $this->logIntegration('wordpress_hooks', [
                'hook_count' => count($this->activeHooks)
            ]);

        } catch (\Exception $e) {
            $this->handleIntegrationError('hook_registration_failed', $e);
        }
    }

    /**
     * Integrate with cache manager
     */
    public function integrateWithCache(CacheManagerInterface $cache): void {
        try {
            $this->cacheManager = $cache;

            // Register cache hooks
            add_action('save_post', [$this, 'invalidateStateCache']);
            add_action('deleted_post', [$this, 'invalidateStateCache']);
            add_action('customize_save_after', [$this, 'invalidateStateCache']);

            // Set up cache event listeners
            $cache->onCacheEvent(function($event) {
                $this->handleCacheEvent($event);
            });

            // Initialize cache groups
            $cache->registerGroup('state', [
                'ttl' => DAY_IN_SECONDS,
                'version' => '1.0',
                'compression' => true
            ]);

            $this->logIntegration('cache', [
                'manager_class' => get_class($cache)
            ]);

        } catch (\Exception $e) {
            $this->handleIntegrationError('cache_integration_failed', $e);
        }
    }

    /**
     * Initialize customizer settings
     */
    private function initializeCustomizerSettings(array $options): void {
        foreach ($this->config['customizer_settings'] ?? [] as $id => $setting) {
            $this->wpCustomizer->add_setting($id, [
                'default' => $setting['default'] ?? null,
                'transport' => $options['transport'],
                'capability' => $options['capability'],
                'sanitize_callback' => $setting['sanitize'] ?? 'sanitize_text_field'
            ]);

            if (isset($setting['control'])) {
                $this->wpCustomizer->add_control($id, $setting['control']);
            }

            // Map setting to state key
            $this->customizerMap[$id] = $setting['state_key'] ?? $id;
        }
    }

    /**
     * Handle customizer save events
     */
    private function handleCustomizerSave(\WP_Customize_Manager $wp_customize): void {
        $changes = [];
        foreach ($this->customizerMap as $setting_id => $state_key) {
            $setting = $wp_customize->get_setting($setting_id);
            if ($setting) {
                $changes[$state_key] = $setting->value();
            }
        }

        if (!empty($changes)) {
            $this->emitStateChange(new StateChangeEvent('customizer', $changes));
        }
    }

    /**
     * Handle face state changes
     */
    private function handleFaceStateChange(StateChangeEvent $event): void {
        if (!isset($this->handlers['cube_face'])) {
            return;
        }

        // Update related customizer settings
        foreach ($event->changes as $key => $value) {
            $setting_id = array_search($key, $this->customizerMap);
            if ($setting_id && $this->wpCustomizer) {
                $this->wpCustomizer->set_post_value($setting_id, $value);
            }
        }

        // Invalidate cache if needed
        if ($this->cacheManager) {
            $this->cacheManager->deleteMultiple(array_keys($event->changes));
        }
    }

    /**
     * Register face-specific hooks
     */
    private function registerFaceHooks(CubeFaceManagerInterface $manager): void {
        // Face update hooks
        add_action('save_post_cube_face', function($post_id) use ($manager) {
            $manager->refreshFace($post_id);
        });

        // Face state sync
        add_action('wp_ajax_sync_face_state', function() use ($manager) {
            $this->handleFaceStateSync($manager);
        });
    }

    /**
     * Register REST API endpoints
     */
    private function registerStateEndpoints(): void {
        register_rest_route('ncore/v1', '/state', [
            'methods' => 'GET',
            'callback' => [$this, 'getStateEndpoint'],
            'permission_callback' => function() {
                return current_user_can('edit_theme_options');
            }
        ]);

        register_rest_route('ncore/v1', '/state', [
            'methods' => 'POST',
            'callback' => [$this, 'updateStateEndpoint'],
            'permission_callback' => function() {
                return current_user_can('edit_theme_options');
            }
        ]);
    }

    /**
     * Handle integration errors
     */
    private function handleIntegrationError(string $code, \Exception $error): void {
        $context = [
            'code' => $code,
            'message' => $error->getMessage(),
            'trace' => $error->getTraceAsString()
        ];

        if ($this->debug) {
            error_log("StateIntegrations Error: " . json_encode($context));
        }

        $this->metrics['errors'][] = [
            'timestamp' => microtime(true),
            'context' => $context
        ];

        // Emit error event
        $this->emitStateChange(new StateChangeEvent('error', [
            'integration_error' => $context
        ]));
    }

    /**
     * Log integration activity
     */
    private function logIntegration(string $type, array $context = []): void {
        $this->metrics['integrations'][$type] = [
            'timestamp' => microtime(true),
            'context' => $context
        ];

        if ($this->debug) {
            error_log("StateIntegrations: Registered {$type} integration");
        }
    }

    /**
     * Initialize metrics tracking
     */
    private function initializeMetrics(): void {
        $this->metrics = [
            'integrations' => [],
            'errors' => [],
            'state_changes' => [],
            'cache_operations' => [],
            'start_time' => microtime(true)
        ];
    }
}