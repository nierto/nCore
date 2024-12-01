<?php
/**
 * nCore Theme Functions
 * 
 * Core theme initialization and setup with NiertoCore integration.
 * 
 * @package     nCore
 * @version     2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Core system requirements
require_once get_template_directory() . '/inc/core/ModuleInterface.php';
require_once get_template_directory() . '/inc/core/NiertoCore.php';

/**
 * Initialize NiertoCore with all required modules
 */
function nCore_initialize_core() {
    try {
        $core = \nCore\Core\NiertoCore::getInstance();
        
        // Initialize core with configuration
        $core->initialize([
            'theme_path' => get_template_directory(),
            'debug' => WP_DEBUG,
            'modules' => [
                'error' => [
                    'enabled' => true,
                    'log_path' => WP_CONTENT_DIR . '/logs/nierto-cube',
                    'error_logging' => true,
                    'display_errors' => WP_DEBUG
                ],
                'cache' => [
                    'enabled' => true,
                    'driver' => 'valkey',
                    'prefix' => 'nCore_',
                    'ttl' => HOUR_IN_SECONDS
                ],
                'api' => [
                    'enabled' => true,
                    'prefix' => 'nierto-cube/v1',
                    'cache_enabled' => true
                ],
                'manifest' => [
                    'enabled' => true,
                    'cache_enabled' => true,
                    'ttl' => DAY_IN_SECONDS
                ]
            ]
        ]);

        // Register core modules in proper order
        $core->registerModule('Error', \nCore\Modules\ErrorManager::class, [], true, 1);
        $core->registerModule('Cache', \nCore\Modules\CacheManager::class, ['Error'], true, 2);
        $core->registerModule('API', \nCore\Modules\APIManager::class, ['Error', 'Cache'], true, 3);
        $core->registerModule('Assets', \nCore\Modules\AssetManager::class, ['Error', 'Cache'], true, 4);
        $core->registerModule('PostTypes', \nCore\PostTypes\PostTypeManager::class, ['Error', 'Cache'], true, 5);

    } catch (\Exception $e) {
        error_log('nCore Core Initialization Error: ' . $e->getMessage());
    }
}
add_action('after_setup_theme', 'nCore_initialize_core', 5);

// Load modular components
require_once get_template_directory() . '/inc/setup/assets.php';
require_once get_template_directory() . '/inc/setup/post-types.php';
require_once get_template_directory() . '/inc/theme/logo.php';
require_once get_template_directory() . '/inc/theme/content.php';
require_once get_template_directory() . '/inc/customizer/customizer.php';

/**
 * Initialize theme features and support
 */
function nCore_setup() {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('custom-logo');
    add_theme_support('html5', [
        'search-form',
        'comment-form',
        'comment-list',
        'gallery',
        'caption',
        'style',
        'script'
    ]);

    // Register nav menus if needed
    register_nav_menus([
        'primary' => __('Primary Menu', 'nierto-cube')
    ]);

    // Set content width
    if (!isset($content_width)) {
        $content_width = 1200;
    }
}
add_action('after_setup_theme', 'nCore_setup', 10);

/**
 * Set up theme defaults that require hooks
 */
function nCore_init() {
    // Remove global styles and SVG filters if not needed
    remove_action('wp_enqueue_scripts', 'wp_enqueue_global_styles');
    remove_action('wp_body_open', 'wp_global_styles_render_svg_filters');
}
add_action('init', 'nCore_init');