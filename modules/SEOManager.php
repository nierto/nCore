<?php
/**
 * SEOManager - AI-Enhanced SEO Management System
 * 
 * Provides comprehensive SEO management with AI-powered optimization strategies.
 * Integrates with various AI providers for enhanced meta generation and analysis.
 * 
 * @package     nCore
 * @subpackage  Modules
 * @version     2.0.0
 */

namespace nCore\Modules;

use nCore\Core\ModuleInterface;
use nCore\Core\nCore;
use nCore\SEO\AIStrategies\AIStrategyInterface;
use nCore\SEO\AIStrategies\OllamaLocalStrategy;
use nCore\SEO\AIStrategies\OllamaRemoteStrategy;
use nCore\SEO\AIStrategies\OpenAIStrategy;

if (!defined('ABSPATH')) {
    exit;
}

class SEOManager implements ModuleInterface {
    /** @var SEOManager Singleton instance */
    private static $instance = null;
    
    /** @var bool Initialization state */
    private $initialized = false;
    
    /** @var array Configuration */
    private $config = [];
    
    /** @var AIStrategyInterface Current AI strategy */
    private $aiStrategy = null;
    
    /** @var array Registered AI strategies */
    private $aiStrategies = [];
    
    /** @var array Meta tag cache */
    private $metaCache = [];
    
    /** @var array Schema cache */
    private $schemaCache = [];

    /** @var array Valid meta types */
    private const META_TYPES = [
        'title', 'description', 'robots',
        'og_title', 'og_description', 'og_image',
        'twitter_card', 'twitter_title', 'twitter_description'
    ];

    /** @var array Schema types */
    private const SCHEMA_TYPES = [
        'Article', 'Product', 'FAQPage',
        'Organization', 'WebSite', 'Event',
        'Recipe', 'VideoObject'
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
     * Initialize SEO management system
     */
    public function initialize(array $config = []): void {
        if ($this->initialized) {
            return;
        }

        try {
            // Initialize configuration
            $this->config = array_merge([
                'enabled' => true,
                'ai_enabled' => true,
                'default_strategy' => 'ollama_local',
                'cache_enabled' => true,
                'debug' => WP_DEBUG,
                'meta_cache_ttl' => HOUR_IN_SECONDS,
                'schema_cache_ttl' => DAY_IN_SECONDS
            ], $config);

            // Register default AI strategies
            $this->registerDefaultStrategies();

            // Initialize selected AI strategy
            $this->initializeAIStrategy();

            // Register WordPress hooks
            $this->registerHooks();
            $this->registerBackwardCompatibility();   
            // Initialize meta boxes
            $this->initializeMetaBoxes();

            $this->initialized = true;

        } catch (\Exception $e) {
            $this->handleError('initialization_failed', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Register default AI strategies
     */
    private function registerDefaultStrategies(): void {
        $this->registerAIStrategy('ollama_local', new OllamaLocalStrategy([
            'model' => 'mixtral',
            'endpoint' => 'http://localhost:11434/api/generate'
        ]));

        $this->registerAIStrategy('ollama_remote', new OllamaRemoteStrategy([
            'model' => 'mixtral',
            'endpoint' => $this->config['ollama_remote_endpoint'] ?? '',
            'api_key' => $this->config['ollama_api_key'] ?? ''
        ]));

        if (!empty($this->config['openai_api_key'])) {
            $this->registerAIStrategy('openai', new OpenAIStrategy([
                'api_key' => $this->config['openai_api_key'],
                'model' => 'gpt-4'
            ]));
        }
    }

    /**
     * Register WordPress hooks
     */
    private function registerHooks(): void {
        // Meta tag management
        add_action('wp_head', [$this, 'outputMetaTags'], 1);
        add_action('wp_head', [$this, 'outputStructuredData'], 2);
        
        // Admin interface
        add_action('add_meta_boxes', [$this, 'addSEOMetaBox']);
        add_action('save_post', [$this, 'saveMetaBoxData']);
        
        // REST API
        add_action('rest_api_init', [$this, 'registerRESTRoutes']);

        // Cache management
        add_action('clean_post_cache', [$this, 'clearPostCache']);
        add_action('switch_theme', [$this, 'clearAllCache']);
    }

    /**
     * Initialize meta boxes
     */
    private function initializeMetaBoxes(): void {
        add_meta_box(
            'ncore_seo_meta_box',
            __('SEO Settings', 'nierto-cube'),
            [$this, 'renderMetaBox'],
            ['post', 'page', 'cube_face'],
            'normal',
            'high'
        );
    }

    /**
     * Register AI strategy
     */
    public function registerAIStrategy(string $name, AIStrategyInterface $strategy): void {
        $this->aiStrategies[$name] = $strategy;
    }

    /**
     * Initialize AI strategy
     */
    private function initializeAIStrategy(): void {
        if (!$this->config['ai_enabled']) {
            return;
        }

        $strategy = $this->config['default_strategy'];
        if (!isset($this->aiStrategies[$strategy])) {
            throw new \RuntimeException("AI strategy not found: {$strategy}");
        }

        $this->aiStrategy = $this->aiStrategies[$strategy];
        $this->aiStrategy->initialize();
    }

    /**
     * Generate meta tags with AI enhancement
     */
    public function generateMetaTags(int $post_id): array {
        if ($this->config['cache_enabled'] && isset($this->metaCache[$post_id])) {
            return $this->metaCache[$post_id];
        }

        $post = get_post($post_id);
        if (!$post) {
            return [];
        }

        $meta = [
            'title' => $this->getMetaTitle($post),
            'description' => $this->getMetaDescription($post),
            'robots' => $this->getRobotsDirectives($post),
            'og_title' => $this->getOpenGraphTitle($post),
            'og_description' => $this->getOpenGraphDescription($post),
            'og_image' => $this->getOpenGraphImage($post),
            'twitter_card' => 'summary_large_image',
            'twitter_title' => $this->getTwitterTitle($post),
            'twitter_description' => $this->getTwitterDescription($post)
        ];

        // Enhance with AI if enabled
        if ($this->config['ai_enabled'] && $this->aiStrategy) {
            $meta = $this->enhanceMetaWithAI($meta, $post);
        }

        // Cache results
        if ($this->config['cache_enabled']) {
            $this->metaCache[$post_id] = $meta;
        }

        return $meta;
    }

    /**
     * Enhance meta tags with AI
     */
    private function enhanceMetaWithAI(array $meta, \WP_Post $post): array {
        try {
            $content = strip_tags($post->post_content);
            $enhancement = $this->aiStrategy->enhanceMeta([
                'title' => $post->post_title,
                'content' => $content,
                'current_meta' => $meta
            ]);

            if (!empty($enhancement['description'])) {
                $meta['description'] = $enhancement['description'];
            }

            if (!empty($enhancement['og_description'])) {
                $meta['og_description'] = $enhancement['og_description'];
            }

            return $meta;

        } catch (\Exception $e) {
            $this->handleError('ai_enhancement_failed', $e->getMessage());
            return $meta;
        }
    }

    /**
     * Generate structured data
     */
    public function generateStructuredData(int $post_id): array {
        if ($this->config['cache_enabled'] && isset($this->schemaCache[$post_id])) {
            return $this->schemaCache[$post_id];
        }

        $post = get_post($post_id);
        if (!$post) {
            return [];
        }

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => $this->getSchemaType($post),
            'name' => get_the_title($post),
            'description' => $this->getMetaDescription($post),
            'url' => get_permalink($post),
            'datePublished' => get_the_date('c', $post),
            'dateModified' => get_the_modified_date('c', $post)
        ];

        // Add type-specific properties
        $schema = $this->addTypeSpecificSchema($schema, $post);

        // Enhance with AI if enabled
        if ($this->config['ai_enabled'] && $this->aiStrategy) {
            $schema = $this->enhanceSchemaWithAI($schema, $post);
        }

        // Cache results
        if ($this->config['cache_enabled']) {
            $this->schemaCache[$post_id] = $schema;
        }

        return $schema;
    }

    /**
     * Add type-specific schema properties
     */
    private function addTypeSpecificSchema(array $schema, \WP_Post $post): array {
        switch ($schema['@type']) {
            case 'Article':
                $schema['author'] = [
                    '@type' => 'Person',
                    'name' => get_the_author_meta('display_name', $post->post_author)
                ];
                $schema['publisher'] = $this->getPublisherSchema();
                break;

            case 'Product':
                $schema = array_merge($schema, $this->getProductSchema($post));
                break;

            case 'FAQPage':
                $schema['mainEntity'] = $this->getFAQSchema($post);
                break;
        }

        return $schema;
    }

    /**
     * Enhance schema with AI
     */
    private function enhanceSchemaWithAI(array $schema, \WP_Post $post): array {
        try {
            $content = strip_tags($post->post_content);
            $enhancement = $this->aiStrategy->enhanceSchema([
                'type' => $schema['@type'],
                'content' => $content,
                'current_schema' => $schema
            ]);

            return array_merge($schema, $enhancement);

        } catch (\Exception $e) {
            $this->handleError('ai_schema_enhancement_failed', $e->getMessage());
            return $schema;
        }
    }

    /**
     * Output meta tags
     */
    public function outputMetaTags(): void {
        if (is_singular()) {
            $meta = $this->generateMetaTags(get_the_ID());
            foreach ($meta as $name => $content) {
                if (empty($content)) continue;

                switch ($name) {
                    case 'title':
                        printf('<title>%s</title>', esc_html($content));
                        break;
                    case 'description':
                        printf('<meta name="description" content="%s">', esc_attr($content));
                        break;
                    case 'robots':
                        printf('<meta name="robots" content="%s">', esc_attr($content));
                        break;
                    default:
                        if (strpos($name, 'og_') === 0) {
                            printf(
                                '<meta property="og:%s" content="%s">',
                                esc_attr(substr($name, 3)),
                                esc_attr($content)
                            );
                        } elseif (strpos($name, 'twitter_') === 0) {
                            printf(
                                '<meta name="twitter:%s" content="%s">',
                                esc_attr(substr($name, 8)),
                                esc_attr($content)
                            );
                        }
                }
            }
        }
    }

    /**
     * Output structured data
     */
    public function outputStructuredData(): void {
        if (is_singular()) {
            $schema = $this->generateStructuredData(get_the_ID());
            if (!empty($schema)) {
                printf(
                    '<script type="application/ld+json">%s</script>',
                    wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                );
            }
        }
    }

    /**
     * Render meta box
     */
    public function renderMetaBox(\WP_Post $post): void {
        wp_nonce_field('ncore_seo_meta_box', 'ncore_seo_meta_box_nonce');

        $meta = $this->generateMetaTags($post->ID);
        ?>
        <div class="ncore-seo-meta-box">
            <?php foreach (self::META_TYPES as $type): ?>
                <div class="ncore-seo-field">
                    <label for="ncore_seo_<?php echo esc_attr($type); ?>">
                        <?php echo esc_html(ucwords(str_replace('_', ' ', $type))); ?>:
                    </label>
                    <?php if ($type === 'description' || strpos($type, 'description') !== false): ?>
                        <textarea
                            id="ncore_seo_<?php echo esc_attr($type); ?>"
                            name="ncore_seo[<?php echo esc_attr($type); ?>]"
                            rows="3"
                            class="large-text"
                        ><?php echo esc_textarea($meta[$type] ?? ''); ?></textarea>
                    <?php else: ?>
                        <input
                            type="text"
                            id="ncore_seo_<?php echo esc_attr($type); ?>"
                            name="ncore_seo[<?php echo esc_attr($type); ?>]"
                            value="<?php echo esc_attr($meta[$type] ?? ''); ?>"
                            class="large-text"
                        >
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <?php if ($this->config['ai_enabled']): ?>
                <div class="ncore-seo-ai-actions">
                    <button type="button" class="button" id="ncore_seo_ai_enhance">
                        <?php esc_html_e('Enhance with AI', 'nierto-cube'); ?>
                    </button>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Save meta box data
     */
    public function saveMetaBoxData(int $post_id): void {
        if (!isset($_POST['ncore_seo_meta_box_nonce']) ||
            !wp_verify_nonce($_POST['ncore_seo_meta_box_nonce'], 'ncore_seo_meta_box') ||
            !current_user_can('edit_post', $post_id)) {
            return;
        }

        // Save meta data
        if (isset($_POST['ncore_seo'])) {
            $meta = array_map('sanitize_text_field', $_POST['ncore_seo']);
            update_post_meta($post_id, '_ncore_seo_meta', $meta);
        }

        // Clear caches
        unset($this->metaCache[$post_id]);
        unset($this->schemaCache[$post_id]);

        // Notify cache manager
        if ($cache = nCore::getInstance()->getModule('Cache')) {
            $cache->delete("seo_meta_{$post_id}", 'seo');
            $cache->delete("seo_schema_{$post_id}", 'seo');
        }
    }
    /**
     * Register backward compatibility layer
     */
    private function registerBackwardCompatibility(): void {
        // Only register if functions don't already exist
        if (!function_exists('nCore_generate_structured_data')) {
            function nCore_generate_structured_data() {
                return SEOManager::getInstance()->generateStructuredData(get_the_ID());
            }
        }

        if (!function_exists('nCore_add_meta_tags')) {
            function nCore_add_meta_tags() {
                SEOManager::getInstance()->outputMetaTags();
            }
        }

        // Register the backward compatibility hook
        add_action('init', function() {
            remove_action('wp_head', 'nCore_add_meta_tags');
            remove_action('wp_head', 'nCore_generate_structured_data');
        }, 1);
    }
    /**
     * Register REST API routes
     */
    public function registerRESTRoutes(): void {
        register_rest_route('ncore/v1', '/seo/meta/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'getMetaAPI'],
            'permission_callback' => function() {
                return current_user_can('edit_posts');
            },
            'args' => [
                'id' => [
                    'required' => true,
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                ]
            ]
        ]);

        register_rest_route('ncore/v1', '/seo/ai-enhance', [
            'methods' => 'POST',
            'callback' => [$this, 'enhanceWithAIAPI'],
            'permission_callback' => function() {
                return current_user_can('edit_posts');
            }
        ]);
    }

    /**
     * Handle meta API request
     */
    public function getMetaAPI(\WP_REST_Request $request): \WP_REST_Response {
        $post_id = (int)$request->get_param('id');
        $meta = $this->generateMetaTags($post_id);
        $schema = $this->generateStructuredData($post_id);

        return new \WP_REST_Response([
            'meta' => $meta,
            'schema' => $schema
        ]);
    }

    /**
     * Handle AI enhancement API request
     */
    public function enhanceWithAIAPI(\WP_REST_Request $request): \WP_REST_Response {
        if (!$this->config['ai_enabled'] || !$this->aiStrategy) {
            return new \WP_REST_Response([
                'error' => 'AI enhancement not available'
            ], 400);
        }

        try {
            $params = $request->get_json_params();
            $post_id = $params['post_id'] ?? 0;
            $post = get_post($post_id);

            if (!$post) {
                return new \WP_REST_Response([
                    'error' => 'Invalid post ID'
                ], 404);
            }

            $meta = $this->generateMetaTags($post_id);
            $enhanced = $this->enhanceMetaWithAI($meta, $post);

            return new \WP_REST_Response([
                'meta' => $enhanced
            ]);

        } catch (\Exception $e) {
            return new \WP_REST_Response([
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Clear post cache
     */
    public function clearPostCache(int $post_id): void {
        unset($this->metaCache[$post_id]);
        unset($this->schemaCache[$post_id]);

        if ($cache = nCore::getInstance()->getModule('Cache')) {
            $cache->delete("seo_meta_{$post_id}", 'seo');
            $cache->delete("seo_schema_{$post_id}", 'seo');
        }
    }

    /**
     * Clear all cache
     */
    public function clearAllCache(): void {
        $this->metaCache = [];
        $this->schemaCache = [];

        if ($cache = nCore::getInstance()->getModule('Cache')) {
            $cache->flush('seo');
        }
    }

    /**
     * Handle error
     */
    private function handleError(string $code, string $message): void {
        if ($error = nCore::getInstance()->getModule('Error')) {
            $error->logError('seo_' . $code, $message);
        } else {
            error_log("SEOManager Error ({$code}): {$message}");
        }
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
            'enabled' => $this->config['enabled'],
            'ai_enabled' => $this->config['ai_enabled'],
            'current_strategy' => $this->config['default_strategy'],
            'cache_enabled' => $this->config['cache_enabled'],
            'cache_status' => [
                'meta' => count($this->metaCache),
                'schema' => count($this->schemaCache)
            ],
            'registered_strategies' => array_keys($this->aiStrategies)
        ];
    }

    // Prevent cloning and unserialization
    private function __clone() {}
    public function __wakeup() {
        throw new \Exception("Cannot unserialize singleton");
    }
}