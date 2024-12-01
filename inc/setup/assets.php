<?php
/**
 * nCore Asset Management System
 * 
 * Implements modular asset loading, dependency management, and optimization
 * through the nCore architecture. Provides systematic approach to
 * script/style registration, localization, and conditional loading.
 *
 * @package     nCore
 * @subpackage  Setup
 * @version     2.0.0
 */

namespace nCore\Setup;

use nCore\Core\ModuleInterface;
use nCore\Core\nCore;

if (!defined('ABSPATH')) {
    exit;
}

class AssetManager implements ModuleInterface {
    /** @var AssetManager Singleton instance */
    private static $instance = null;

    /** @var bool Initialization state */
    private $initialized = false;

    /** @var array Configuration settings */
    private $config = [];

    /** @var array Registered asset dependencies */
    private $dependencies = [
        'core' => [
            'scripts' => [
                'nierto-cube-performance' => [],
                'utils-script' => ['nierto-cube-performance'],
                'config-script' => ['utils-script', 'nierto-cube-performance'],
                'cookie-script' => ['utils-script'],
                'cube-script' => ['utils-script', 'config-script', 'cookie-script', 'nierto-cube-performance']
            ],
            'styles' => [
                'nierto-cube-all-styles' => [],
                'nierto-cube-style' => ['nierto-cube-all-styles']
            ]
        ]
    ];

    /** @var array Asset localization data */
    private $localization_data = [];

    /**
     * Get singleton instance
     */
    public static function getInstance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize asset management system
     */
    public function initialize(array $config = []): void {
        if ($this->initialized) {
            return;
        }

        try {
            $this->config = array_merge([
                'theme_dir' => get_template_directory(),
                'theme_uri' => get_template_directory_uri(),
                'debug' => WP_DEBUG,
                'version_salt' => 'nCore_v2',
                'defer_scripts' => true,
                'preload_critical' => true
            ], $config);

            $this->setupHooks();
            $this->initialized = true;

        } catch (\Exception $e) {
            error_log('AssetManager initialization failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Setup WordPress hooks
     */
    private function setupHooks(): void {
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
        add_filter('script_loader_tag', [$this, 'modifyScriptLoading'], 10, 3);
        // Admin assets
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
    }

    /**
     * Enqueue frontend assets
     */
    public function enqueueAssets(): void {
        try {
            $performance = nCore::getInstance()->getModule('Metrics');
            $start_time = microtime(true);

            // Core Styles
            wp_enqueue_style(
                'nierto-cube-all-styles',
                $this->config['theme_uri'] . '/css/all-styles.css',
                [],
                $this->getAssetVersion('/css/all-styles.css')
            );

            wp_enqueue_style(
                'nierto-cube-style',
                get_stylesheet_uri(),
                ['nierto-cube-all-styles'],
                $this->getAssetVersion('/style.css')
            );

            // Core Scripts with Dependency Chain
            $this->enqueueCoreDependencyChain();

            // Conditional PWA Support
            if (is_front_page() && get_theme_mod('enable_pwa', 1)) {
                $this->enqueuePWASupport();
            }

            // Localize Script Data
            $this->localizeScripts();

            if ($performance) {
                $performance->recordMetric('asset_enqueue', [
                    'duration' => microtime(true) - $start_time,
                    'scripts' => count($GLOBALS['wp_scripts']->queue),
                    'styles' => count($GLOBALS['wp_styles']->queue)
                ]);
            }

        } catch (\Exception $e) {
            error_log('Asset enqueue failed: ' . $e->getMessage());
            
            // Fallback to critical assets
            wp_enqueue_style('nierto-cube-critical', get_stylesheet_uri());
        }
    }

    /**
     * Enqueue core dependency chain
     */
    private function enqueueCoreDependencyChain(): void {
        foreach ($this->dependencies['core']['scripts'] as $handle => $deps) {
            wp_enqueue_script(
                $handle,
                $this->config['theme_uri'] . "/js/{$handle}.js",
                $deps,
                $this->getAssetVersion("/js/{$handle}.js"),
                true
            );
        }
    }

    /**
     * Enqueue PWA support
     */
    private function enqueuePWASupport(): void {
        wp_enqueue_script(
            'pwa-script',
            $this->config['theme_uri'] . '/js/pwa.js',
            ['utils-script', 'nierto-cube-performance'],
            $this->getAssetVersion('/js/pwa.js'),
            true
        );
        wp_script_add_data('pwa-script', 'async', true);

        $this->localization_data['pwa'] = [
            'themeUrl' => $this->config['theme_uri'] . '/',
            'installBanner' => get_theme_mod('pwa_install_banner', '')
        ];
    }
/* */
    private function setupCustomizerPreview(): void {
        if (is_customize_preview()) {
            wp_enqueue_script(
                'nierto-cube-customizer-preview',
                $this->config['theme_uri'] . '/js/customizer-preview.js',
                ['customize-preview', 'cube-script'],
                $this->getAssetVersion('/js/customizer-preview.js'),
                true
            );

            // Add customizer settings for live preview
            foreach (['text', 'slug', 'position', 'type'] as $setting) {
                for ($i = 1; $i <= 6; $i++) {
                    add_filter("customize_preview_cube_face_{$i}_{$setting}", function($value) {
                        return $value;
                    });
                }
            }
        }
    }

    /**
     * Localize script data
     */
    private function localizeScripts(): void {
        // Prepare cube faces data
        $cube_faces = [];
        for ($i = 1; $i <= 6; $i++) {
            $cube_faces[] = [
                'buttonText' => get_theme_mod("cube_face_{$i}_text", "Face {$i}"),
                'urlSlug' => get_theme_mod("cube_face_{$i}_slug", "face-{$i}"),
                'facePosition' => get_theme_mod("cube_face_{$i}_position", "face" . ($i - 1)),
                'contentType' => get_theme_mod("cube_face_{$i}_type", "page")
            ];   
        }

        // Add preview data for customizer
        if (is_customize_preview()) {
            $this->localization_data['customizer'] = [
                'settings' => $this->getCustomizerSettings(),
                'transport' => 'postMessage'
            ];
            
            wp_localize_script(
                'nierto-cube-customizer-preview',
                'nCoreCustomizerPreview',
                $this->localization_data['customizer']
            );
        }

        // Core data structure
        $this->localization_data['core'] = [
            'resourceVersion' => get_option('nCore_resource_version', '1.0'),
            'enablePWA' => get_theme_mod('enable_pwa', 0),
            'debugMode' => WP_DEBUG,
            'themeUrl' => get_template_directory_uri(),
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('nCore_ajax')
        ];

        // Localize all data
        wp_localize_script('nierto-cube-performance', 'nCoreData', $this->localization_data['core']);
        wp_localize_script('cube-script', 'nCoreCustomizer', ['cubeFaces' => $cube_faces]);
    }

    /**
     * Enqueue admin assets
     * 
     * @param string $hook_suffix Current admin page hook
     */
    public function enqueueAdminAssets(string $hook_suffix): void {
    if (!is_admin()) {
        return;
    }

    try {
        $start_time = microtime(true);

        // Core admin script
        wp_enqueue_script(
            'nierto-cube-admin',
            $this->config['theme_uri'] . '/js/admin-scripts.js',
            ['nierto-cube-performance'],
            $this->getAssetVersion('/js/admin-scripts.js'),
            true
        );

        // Admin localization data
        $this->localizeAdminScripts();

        // Cache management scripts
        $this->enqueueCacheManagement();

        // Record metrics if available
        $performance = nCore::getInstance()->getModule('Metrics');
        if ($performance) {
            $performance->recordMetric('admin_asset_enqueue', [
                'duration' => microtime(true) - $start_time,
                'hook' => $hook_suffix,
                'scripts' => count($GLOBALS['wp_scripts']->queue)
            ]);
        }

    } catch (\Exception $e) {
        error_log('Admin asset enqueue failed: ' . $e->getMessage());
    }
    }

    /**
    * Localize admin scripts
    */
    private function localizeAdminScripts(): void {
    wp_localize_script('nierto-cube-admin', 'nCoreAdmin', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('nCore_admin_nonce'),
        'debug' => $this->config['debug']
    ]);

    // Add cache management functionality
    wp_add_inline_script('nierto-cube-admin', '
        function nCoreClearCache(type) {
            fetch(nCoreAdmin.ajaxurl, {
                method: "POST",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded"
                },
                body: new URLSearchParams({
                    action: "nCore_clear_cache",
                    nonce: nCoreAdmin.nonce,
                    cache_type: type
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert("Cache cleared successfully!");
                } else {
                    alert("Failed to clear cache: " + data.data.message);
                }
            })
            .catch(error => {
                console.error("Cache clear failed:", error);
                alert("Failed to clear cache. Check console for details.");
            });
        }
    ');
    }

    /**
    * Enqueue cache management functionality
    */
    private function enqueueCacheManagement(): void {
    if (!current_user_can('manage_options')) {
        return;
    }

    add_action('admin_bar_menu', function($wp_admin_bar) {
        $wp_admin_bar->add_node([
            'id' => 'nierto-cube-cache',
            'title' => 'nCore Cache',
            'href' => '#'
        ]);

        $wp_admin_bar->add_node([
            'id' => 'clear-all-cache',
            'title' => 'Clear All Cache',
            'parent' => 'nierto-cube-cache',
            'href' => '#',
            'meta' => [
                'onclick' => 'nCoreClearCache("all"); return false;'
            ]
        ]);

        $wp_admin_bar->add_node([
            'id' => 'clear-face-cache',
            'title' => 'Clear Face Cache',
            'parent' => 'nierto-cube-cache',
            'href' => '#',
            'meta' => [
                'onclick' => 'nCoreClearCache("face"); return false;'
            ]
        ]);
    }, 100);
    }

    private function getCustomizerSettings(): array {
        $settings = [];
        for ($i = 1; $i <= 6; $i++) {
            $settings["face_{$i}"] = [
                'text' => get_theme_mod("cube_face_{$i}_text"),
                'slug' => get_theme_mod("cube_face_{$i}_slug"),
                'position' => get_theme_mod("cube_face_{$i}_position"),
                'type' => get_theme_mod("cube_face_{$i}_type")
            ];
        }
        return $settings;
    }

    /**
     * Get asset version based on file modification time
     */
    private function getAssetVersion(string $file_path): string {
        $full_path = $this->config['theme_dir'] . $file_path;
        return file_exists($full_path) ? 
            hash('xxh32', $this->config['version_salt'] . filemtime($full_path)) : 
            '1.0.0';
    }

    /**
     * Modify script loading (defer/async)
     */
    public function modifyScriptLoading(string $tag, string $handle, string $src): string {
        if ($this->config['defer_scripts'] && !is_admin()) {
            if (isset($this->dependencies['core']['scripts'][$handle])) {
                return str_replace(' src', ' defer src', $tag);
            }
        }
        return $tag;
    }

    /**
     * Get module configuration
     */
    public function getConfig(): array {
        return $this->config;
    }

    /**
     * Update module configuration
     */
    public function updateConfig(array $config): void {
        $this->config = array_merge($this->config, $config);
    }

    /**
     * Check if module is initialized
     */
    public function isInitialized(): bool {
        return $this->initialized;
    }

    /**
     * Get module status
     */
    public function getStatus(): array {
        return [
            'initialized' => $this->initialized,
            'debug' => $this->config['debug'],
            'defer_enabled' => $this->config['defer_scripts'],
            'dependencies' => $this->dependencies,
            'localization' => array_keys($this->localization_data)
        ];
    }

    // Prevent cloning and unserialization
    private function __clone() {}
    public function __wakeup() {
        throw new \Exception("Cannot unserialize singleton");
    }
}

// Initialize AssetManager
add_action('after_setup_theme', function() {
    try {
        $core = nCore::getInstance();
        $core->registerModule(
            'Assets',
            AssetManager::class,
            ['Error', 'Metrics'],
            true,
            5
        );
    } catch (\Exception $e) {
        error_log('AssetManager registration failed: ' . $e->getMessage());
    }
}, 6);