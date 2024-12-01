<?php
namespace nCore\Modules;

use nCore\Core\ModuleInterface;
use nCore\Core\nCore;

if (!defined('ABSPATH')) {
    exit;
}

class ContentManager implements ModuleInterface {
    /** @var ContentManager Singleton instance */
    private static $instance = null;
    
    /** @var bool Initialization state */
    private $initialized = false;
    
    /** @var array Configuration settings */
    private $config = [];

    /** @var ErrorManager Error handling system */
    private $error;

    /** @var CacheManager Cache system */
    private $cache;

    /** @var StateManager State management */
    private $state;

    /** @var MetricsManager Metrics system */
    private $metrics;

    /** @var array Content registry */
    private $content_registry = [];

    /** @var array Content transformers */
    private $transformers = [];

    /** @var array Preview states */
    private $preview_states = [];

    /** @var array Valid content types */
    private const CONTENT_TYPES = [
        'page' => 'WordPress Page',
        'cube_face' => 'Cube Face',
        'post' => 'Blog Post',
        'widget_area' => 'Widget Area',
        'multi_post' => 'Multi-Post Template'
    ];

    /** @var array Content contexts */
    private const CONTENT_CONTEXTS = [
        'display' => 'Frontend Display',
        'preview' => 'Customizer Preview',
        'edit' => 'Editor View',
        'api' => 'API Response'
    ];

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
     * Initialize content management system
     */
    public function initialize(array $config = []): void {
        if ($this->initialized) {
            return;
        }

        try {
            // Initialize configuration
            $this->config = array_merge([
                'enabled' => true,
                'cache_enabled' => true,
                'debug' => WP_DEBUG,
                'cache_ttl' => HOUR_IN_SECONDS,
                'content_types' => self::CONTENT_TYPES,
                'contexts' => self::CONTENT_CONTEXTS,
                'preview_enabled' => true,
                'widget_support' => true,
                'multi_post_limit' => 10,
                'sanitize_content' => true
            ], $config);

            // Get core dependencies
            $core = nCore::getInstance();
            $this->error = $core->getModule('Error');
            $this->cache = $core->getModule('Cache');
            $this->state = $core->getModule('State');
            $this->metrics = $core->getModule('Metrics');

            // Initialize subsystems
            $this->initializeTransformers();
            $this->setupPreviewSystem();

            // Register hooks
            $this->registerHooks();
            $this->registerEndpoints();
            $this->registerCustomizerSupport();

            $this->initialized = true;

            // Record initialization metric
            if ($this->metrics) {
                $this->metrics->recordMetric('content_init', [
                    'timestamp' => microtime(true),
                    'config' => $this->config
                ]);
            }

        } catch (\Exception $e) {
            if ($this->error) {
                $this->error->logError('content_init_failed', $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * Initialize content transformers
     */
    private function initializeTransformers(): void {
        $this->transformers = [
            'sanitize' => function($content) {
                return $this->config['sanitize_content'] ? 
                    wp_kses_post($content) : $content;
            },
            'shortcodes' => function($content) {
                return do_shortcode($content);
            },
            'widgets' => function($content, $context = []) {
                if (!empty($context['widget_area'])) {
                    ob_start();
                    dynamic_sidebar($context['widget_area']);
                    $widgets = ob_get_clean();
                    return $content . $widgets;
                }
                return $content;
            }
        ];
    }

    /**
     * Register WordPress hooks
     */
    private function registerHooks(): void {
        // Content management hooks
        add_action('save_post', [$this, 'clearContentCache']);
        add_action('delete_post', [$this, 'clearContentCache']);
        add_action('customize_save_after', [$this, 'clearAllContentCache']);
        add_action('switch_theme', [$this, 'clearAllContentCache']);

        // Widget integration
        if ($this->config['widget_support']) {
            add_action('widgets_init', [$this, 'registerWidgetAreas']);
            add_action('dynamic_sidebar', [$this, 'trackWidgetUsage']);
        }

        // Legacy support
        add_action('init', [$this, 'registerBackwardCompatibility']);
    }

    /**
     * Register REST API endpoints
     */
    private function registerEndpoints(): void {
        register_rest_route('nierto-cube/v1', '/face-content/(?P<type>[\w-]+)/(?P<slug>[\w-]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'getFaceContent'],
            'permission_callback' => '__return_true',
            'args' => [
                'type' => [
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field'
                ],
                'slug' => [
                    'required' => true,
                    'sanitize_callback' => 'sanitize_title'
                ],
                'context' => [
                    'required' => false,
                    'default' => 'display'
                ]
            ]
        ]);

        // Multi-post endpoint
        register_rest_route('nierto-cube/v1', '/multi-post/(?P<template>[\w-]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'getMultiPostContent'],
            'permission_callback' => '__return_true'
        ]);
    }

    /**
     * Register customizer support
     */
    private function registerCustomizerSupport(): void {
        if (!$this->config['preview_enabled']) {
            return;
        }

        add_action('customize_preview_init', [$this, 'enqueuePreviewAssets']);
        add_action('customize_register', [$this, 'registerCustomizerSettings']);
    }

    /**
     * Set up preview system
     */
    private function setupPreviewSystem(): void {
        if (!$this->config['preview_enabled']) {
            return;
        }

        add_filter('the_content', [$this, 'wrapContentForPreview'], 999);
        add_action('wp_footer', [$this, 'injectPreviewScripts'], 999);
    }

    /**
     * Get face content with full processing
     */
    public function getFaceContent(\WP_REST_Request $request): \WP_REST_Response {
        try {
            $type = $request->get_param('type');
            $slug = $request->get_param('slug');
            $context = $request->get_param('context');
            
            // Validate type and context
            if (!isset($this->config['content_types'][$type]) || 
                !isset($this->config['contexts'][$context])) {
                return new \WP_REST_Response([
                    'error' => 'Invalid parameters'
                ], 400);
            }

            // Try cache first
            $cache_key = "face_content_{$type}_{$slug}_{$context}";
            if ($this->config['cache_enabled'] && $context !== 'preview') {
                $cached = $this->cache->get($cache_key, 'content');
                if ($cached !== false) {
                    return new \WP_REST_Response($cached);
                }
            }

            // Get raw content
            $content = $this->getContent($type, $slug);
            if (empty($content)) {
                return new \WP_REST_Response([
                    'error' => 'Content not found'
                ], 404);
            }

            // Apply transformations
            $content = $this->transformContent($content, [
                'type' => $type,
                'context' => $context,
                'slug' => $slug
            ]);

            // Cache if appropriate
            if ($this->config['cache_enabled'] && $context !== 'preview') {
                $this->cache->set(
                    $cache_key,
                    $content,
                    'content',
                    $this->config['cache_ttl']
                );
            }

            return new \WP_REST_Response($content);

        } catch (\Exception $e) {
            $this->error->logError('content_retrieval_failed', [
                'type' => $type,
                'slug' => $slug,
                'error' => $e->getMessage()
            ]);

            return new \WP_REST_Response([
                'error' => 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get multi-post template content
     */
    public function getMultiPostContent(\WP_REST_Request $request): \WP_REST_Response {
        try {
            $template = $request->get_param('template');
            $cache_key = "multi_post_{$template}";

            // Try cache first
            if ($this->config['cache_enabled']) {
                $cached = $this->cache->get($cache_key, 'content');
                if ($cached !== false) {
                    return new \WP_REST_Response($cached);
                }
            }

            // Get posts for template
            $posts = get_posts([
                'post_type' => 'cube_face',
                'meta_key' => '_cube_face_template',
                'meta_value' => $template,
                'posts_per_page' => $this->config['multi_post_limit'],
                'orderby' => 'date',
                'order' => 'DESC'
            ]);

            if (empty($posts)) {
                return new \WP_REST_Response([
                    'error' => 'No content found for template'
                ], 404);
            }

            // Process posts
            $content = array_map(function($post) {
                return [
                    'id' => $post->ID,
                    'title' => $post->post_title,
                    'content' => apply_filters('the_content', $post->post_content),
                    'date' => get_the_date('c', $post->ID),
                    'modified' => get_the_modified_date('c', $post->ID)
                ];
            }, $posts);

            // Cache results
            if ($this->config['cache_enabled']) {
                $this->cache->set(
                    $cache_key,
                    $content,
                    'content',
                    $this->config['cache_ttl']
                );
            }

            return new \WP_REST_Response($content);

        } catch (\Exception $e) {
            $this->error->logError('multi_post_retrieval_failed', [
                'template' => $template,
                'error' => $e->getMessage()
            ]);

            return new \WP_REST_Response([
                'error' => 'Internal server error'
            ], 500);
        }
    }

    /**
     * Transform content through pipeline
     */
    private function transformContent(array $content, array $context = []): array {
        foreach ($this->transformers as $transformer) {
            if (isset($content['content'])) {
                $content['content'] = $transformer($content['content'], $context);
            }
        }

        // Add preview wrapper if needed
        if ($context['context'] === 'preview') {
            $content['content'] = sprintf(
                '<div class="preview-wrapper" data-type="%s" data-slug="%s">%s</div>',
                esc_attr($context['type']),
                esc_attr($context['slug']),
                $content['content']
            );
        }

        return $content;
    }

    /**
     * Register widget areas
     */
    public function registerWidgetAreas(): void {
        register_sidebar([
            'name' => __('Cube Face Sidebar', 'nierto-cube'),
            'id' => 'cube-face-sidebar',
            'description' => __('Widgets in this area will be shown on cube faces.', 'nierto-cube'),
            'before_widget' => '<section id="%1$s" class="widget %2$s">',
            'after_widget' => '</section>',
            'before_title' => '<h2 class="widget-title">',
            'after_title' => '</h2>'
        ]);
    }

    /**
     * Track widget usage for metrics
     */
    public function trackWidgetUsage($widget): void {
        if ($this->metrics) {
            $this->metrics->recordMetric('widget_usage', [
                'widget' => $widget,
                'timestamp' => time()
            ]);
        }
    }

    /**
     * Register backward compatibility functions
     */
    public function registerBackwardCompatibility(): void {
        if (!function_exists('nCore_get_face_content')) {
            function nCore_get_face_content() {
                return ContentManager::getInstance()->getLegacyFaceContent();
            }
        }
    }

    /**
     * Legacy face content retrieval
     */
    public function getLegacyFaceContent(): array {
        $faces = [];
        for ($i = 1; $i <= 6; $i++) {
            $faces[] = [
                'buttonText' => get_theme_mod("cube_face_{$i}_text", "Face {$i}"),
                'urlSlug' => get_theme_mod("cube_face_{$i}_slug", "face-{$i}"),
                'facePosition' => get_theme_mod("cube_face_{$i}_position", "face" . ($i - 1)),
                'contentType' => get_theme_mod("cube_face_{$i}_type", "cube_face")
            ];
        }
        return $faces;
    }

    /**
     * Clear content cache for specific post
     */
    public function clearContentCache(int $post_id): void {
        if (!$this->config['cache_enabled']) {
            return;
        }

        try {
            $post_type = get_post_type($post_id);
            $slug = get_post_field('post_name', $post_id);
            
            foreach ($this->config['contexts'] as $context => $label) {
                $this->cache->delete(
                    "face_content_{$post_type}_{$slug}_{$context}",
                    'content'
                );
            }

            // Clear template cache if needed
            $template = get_post_meta($post_id, '_cube_face_template', true);
            if ($template) {
                $this->cache->delete("multi_post_{$template}", 'content');
            }

        } catch (\Exception $e) {
            $this->error->logError('cache_clear_failed', [
                'post_id' => $post_id,
                'error' => $e->getMessage()
            ]);
        }
    }

/**
     * Clear all content cache
     */
    public function clearAllContentCache(): void {
        if (!$this->config['cache_enabled']) {
            return;
        }

        try {
            $this->cache->flush('content');
            
            // Record cache clear metric
            if ($this->metrics) {
                $this->metrics->recordMetric('content_cache_clear', [
                    'timestamp' => time(),
                    'type' => 'full_clear'
                ]);
            }
        } catch (\Exception $e) {
            $this->error->logError('cache_flush_failed', $e->getMessage());
        }
    }

    /**
     * Get raw content by type and slug
     */
    private function getContent(string $type, string $slug): ?array {
        if ($type === 'page') {
            return $this->getPageContent($slug);
        } else {
            return $this->getPostContent($type, $slug);
        }
    }

    /**
     * Get page content
     */
    private function getPageContent(string $slug): ?array {
        $page = get_page_by_path($slug);
        if (!$page) {
            return null;
        }

        return [
            'type' => 'page',
            'content' => get_permalink($page->ID),
            'title' => $page->post_title,
            'modified' => get_the_modified_time('U', $page->ID),
            'meta' => get_post_meta($page->ID)
        ];
    }

    /**
     * Get post content
     */
    private function getPostContent(string $type, string $slug): ?array {
        $posts = get_posts([
            'name' => $slug,
            'post_type' => $type,
            'post_status' => 'publish',
            'numberposts' => 1
        ]);

        if (empty($posts)) {
            return null;
        }

        $post = $posts[0];
        $meta = get_post_meta($post->ID);
        
        return [
            'type' => $type,
            'content' => $post->post_content,
            'title' => $post->post_title,
            'template' => $meta['_cube_face_template'][0] ?? null,
            'position' => $meta['_cube_face_position'][0] ?? null,
            'modified' => get_the_modified_time('U', $post->ID),
            'meta' => $meta
        ];
    }

    /**
     * Get configuration
     */
    public function getConfig(): array {
        return $this->config;
    }

    /**
     * Update configuration
     */
    public function updateConfig(array $config): void {
        $this->config = array_merge($this->config, $config);
    }

    /**
     * Check if initialized
     */
    public function isInitialized(): bool {
        return $this->initialized;
    }

    /**
     * Get status information
     */
    public function getStatus(): array {
        return [
            'initialized' => $this->initialized,
            'enabled' => $this->config['enabled'],
            'cache_enabled' => $this->config['cache_enabled'],
            'preview_enabled' => $this->config['preview_enabled'],
            'widget_support' => $this->config['widget_support'],
            'content_types' => array_keys($this->config['content_types']),
            'contexts' => array_keys($this->config['contexts']),
            'transformers' => array_keys($this->transformers),
            'cache_status' => $this->cache?->isAvailable(),
            'preview_states' => count($this->preview_states),
            'registry_count' => count($this->content_registry)
        ];
    }

    /**
     * Get metrics data
     */
    public function getMetrics(): array {
        return [
            'cache_hits' => $this->metrics?->getMetricCount('content_cache_hit') ?? 0,
            'cache_misses' => $this->metrics?->getMetricCount('content_cache_miss') ?? 0,
            'transformations' => $this->metrics?->getMetricCount('content_transform') ?? 0,
            'errors' => $this->metrics?->getMetricCount('content_error') ?? 0,
            'widget_usage' => $this->metrics?->getMetricCount('widget_usage') ?? 0
        ];
    }

    // Prevent cloning and unserialization
    private function __clone() {}
    public function __wakeup() {
        throw new \Exception("Cannot unserialize singleton");
    }
}