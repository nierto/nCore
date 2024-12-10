<?php
/**
 * Advanced Resource Management System
 * 
 * Handles resource optimization, critical path analysis, and dependency resolution.
 * Integrates with StateManager for configuration and MetricsManager for performance tracking.
 * 
 * @package     nCore
 * @subpackage  Modules
 * @version     2.0.0
 */

namespace nCore\Modules;

use nCore\Core\ModuleInterface;
use nCore\Core\nCore;

class ResourceManager implements ModuleInterface {
    /** @var ResourceManager Singleton instance */
    private static $instance = null;
    
    /** @var bool Initialization state */
    private $initialized = false;
    
    /** @var array Resource registry */
    private $resources = [];
    
    /** @var array Critical resources */
    private $criticalResources = [];
    
    /** @var array Dependency graph */
    private $dependencyGraph = [];
    
    /** @var array Resource load order */
    private $loadOrder = [];
    
    /** @var array Performance metrics */
    private $metrics = [
        'critical_size' => 0,
        'total_resources' => 0,
        'load_times' => []
    ];

    /** @var array Default configuration */
    private const DEFAULT_CONFIG = [
        'critical_css_limit' => 14 * 1024, // 14KB
        'dependency_timeout' => 100, // 100ms
        'resource_types' => ['script', 'style', 'font', 'image'],
        'preload_types' => ['style', 'script'],
        'defer_scripts' => true,
        'async_scripts' => false,
        'minify' => true,
        'combine' => true,
        'cache_ttl' => DAY_IN_SECONDS,
        'version_salt' => 'nCore_v2'
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
     * Initialize resource manager
     */
    public function initialize(array $config = []): void {
        if ($this->initialized) {
            return;
        }

        try {
            // Initialize configuration
            $this->config = array_merge(self::DEFAULT_CONFIG, $config);

            // Get core dependencies
            $core = nCore::getInstance();
            $this->stateManager = $core->getModule('State');
            $this->cacheManager = $core->getModule('Cache');
            $this->metricsManager = $core->getModule('Metrics');

            // Register WordPress hooks
            $this->registerHooks();

            // Initialize critical path optimization
            $this->initializeCriticalPath();

            $this->initialized = true;

            // Record initialization metric
            $this->recordMetric('initialization', [
                'timestamp' => microtime(true),
                'config' => $this->config
            ]);

        } catch (\Exception $e) {
            throw new \RuntimeException('ResourceManager initialization failed: ' . $e->getMessage());
        }
    }

    /**
     * Register WordPress hooks
     */
    private function registerHooks(): void {
        // Early resource optimization
        add_action('wp_default_scripts', [$this, 'optimizeScripts'], 1);
        add_action('wp_default_styles', [$this, 'optimizeStyles'], 1);

        // Resource enqueuing
        add_action('wp_enqueue_scripts', [$this, 'enqueueResources'], 10);
        
        // Resource loading optimization
        add_filter('script_loader_tag', [$this, 'optimizeScriptLoading'], 10, 3);
        add_filter('style_loader_tag', [$this, 'optimizeStyleLoading'], 10, 4);

        // Resource hints
        add_action('wp_head', [$this, 'addResourceHints'], 2);

        // Critical CSS injection
        add_action('wp_head', [$this, 'injectCriticalCSS'], 1);
    }

    /**
     * Register new resource
     */
    public function registerResource(string $type, string $identifier, array $config): void {
        if (!in_array($type, $this->config['resource_types'])) {
            throw new \InvalidArgumentException("Invalid resource type: {$type}");
        }

        // Validate configuration
        $this->validateResourceConfig($config);

        // Generate resource hash
        $hash = $this->generateResourceHash($type, $identifier, $config);

        // Store resource metadata
        $this->resources[$hash] = [
            'type' => $type,
            'identifier' => $identifier,
            'config' => $config,
            'dependencies' => $config['dependencies'] ?? [],
            'load_time' => 0,
            'size' => 0,
            'critical' => $config['critical'] ?? false
        ];

        // Update dependency graph
        if (!empty($config['dependencies'])) {
            $this->updateDependencyGraph($hash, $config['dependencies']);
        }

        // Mark as critical if specified
        if ($config['critical'] ?? false) {
            $this->criticalResources[$hash] = true;
        }

        $this->recordMetric('resource_registered', [
            'type' => $type,
            'identifier' => $identifier,
            'critical' => $config['critical'] ?? false
        ]);
    }

    /**
     * Optimize resource loading order
     */
    private function optimizeLoadOrder(): void {
        $startTime = microtime(true);

        try {
            // Reset load order
            $this->loadOrder = [];
            $visited = [];
            $processing = [];

            // Perform topological sort
            foreach ($this->resources as $hash => $resource) {
                if (!isset($visited[$hash])) {
                    $this->visitResource($hash, $visited, $processing);
                }
            }

            // Prioritize critical resources
            $this->prioritizeCriticalResources();

            $this->recordMetric('load_order_optimization', [
                'duration' => microtime(true) - $startTime,
                'resource_count' => count($this->resources)
            ]);

        } catch (\Exception $e) {
            throw new \RuntimeException('Load order optimization failed: ' . $e->getMessage());
        }
    }

    /**
     * Visit resource for dependency resolution
     */
    private function visitResource(string $hash, array &$visited, array &$processing): void {
        // Check for circular dependencies
        if (isset($processing[$hash])) {
            throw new \RuntimeException("Circular dependency detected for resource: {$hash}");
        }

        $processing[$hash] = true;

        // Visit dependencies first
        if (isset($this->dependencyGraph[$hash])) {
            foreach ($this->dependencyGraph[$hash] as $dependency) {
                if (!isset($visited[$dependency])) {
                    $this->visitResource($dependency, $visited, $processing);
                }
            }
        }

        unset($processing[$hash]);
        $visited[$hash] = true;
        $this->loadOrder[] = $hash;
    }

    /**
     * Generate critical CSS
     */
    private function generateCriticalCSS(): string {
        $startTime = microtime(true);
        
        try {
            // Try cache first
            $cacheKey = 'critical_css_' . $this->getCurrentPageType();
            if ($cached = $this->cacheManager->get($cacheKey, 'resources')) {
                return $cached;
            }

            $criticalCSS = '';
            foreach ($this->criticalResources as $hash => $true) {
                $resource = $this->resources[$hash];
                if ($resource['type'] === 'style') {
                    $criticalCSS .= $this->processCriticalStyles($resource);
                }
            }

            // Minify if enabled
            if ($this->config['minify']) {
                $criticalCSS = $this->minifyCSS($criticalCSS);
            }

            // Verify size limit
            if (strlen($criticalCSS) > $this->config['critical_css_limit']) {
                throw new \RuntimeException('Critical CSS exceeds size limit');
            }

            // Cache result
            $this->cacheManager->set(
                $cacheKey,
                $criticalCSS,
                'resources',
                $this->config['cache_ttl']
            );

            $this->recordMetric('critical_css_generation', [
                'duration' => microtime(true) - $startTime,
                'size' => strlen($criticalCSS)
            ]);

            return $criticalCSS;

        } catch (\Exception $e) {
            throw new \RuntimeException('Critical CSS generation failed: ' . $e->getMessage());
        }
    }

    /**
     * Process critical styles for a resource
     */
    private function processCriticalStyles(array $resource): string {
        $styles = '';

        // Load file content
        $content = file_get_contents($resource['config']['path']);
        if ($content === false) {
            throw new \RuntimeException("Failed to load resource: {$resource['identifier']}");
        }

        // Extract critical selectors
        $criticalSelectors = $this->extractCriticalSelectors($content);

        // Build critical styles
        foreach ($criticalSelectors as $selector => $rules) {
            $styles .= "{$selector} { {$rules} }\n";
        }

        return $styles;
    }

    /**
     * Extract critical selectors from CSS content
     */
    private function extractCriticalSelectors(string $content): array {
        $selectors = [];
        
        // Parse CSS
        $parser = new \Sabberworm\CSS\Parser($content);
        $cssDocument = $parser->parse();

        foreach ($cssDocument->getAllRuleSets() as $ruleSet) {
            $selector = $ruleSet->getSelectors();
            if ($this->isCriticalSelector($selector)) {
                $selectors[$selector] = $ruleSet->getRules();
            }
        }

        return $selectors;
    }

    /**
     * Check if selector is critical
     */
    private function isCriticalSelector(string $selector): bool {
        $criticalPatterns = [
            'body', 'html', '#header', '#nav', '.main-content',
            'h1', 'h2', 'p:first-of-type', '.hero'
        ];

        foreach ($criticalPatterns as $pattern) {
            if (strpos($selector, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Minify CSS content
     */
    private function minifyCSS(string $css): string {
        // Remove comments
        $css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);
        
        // Remove whitespace
        $css = str_replace(["\r\n", "\r", "\n", "\t"], '', $css);
        $css = preg_replace('/\s+/', ' ', $css);
        
        // Remove unnecessary characters
        $css = str_replace(': ', ':', $css);
        $css = str_replace(' {', '{', $css);
        $css = str_replace('} ', '}', $css);
        $css = str_replace('; ', ';', $css);
        $css = str_replace(', ', ',', $css);
        
        return trim($css);
    }

    /**
     * Get current page type
     */
    private function getCurrentPageType(): string {
        if (is_front_page()) return 'front';
        if (is_single()) return 'single';
        if (is_archive()) return 'archive';
        if (is_page()) return 'page';
        return 'default';
    }

    /**
     * Generate resource hash
     */
    private function generateResourceHash(string $type, string $identifier, array $config): string {
        return hash('xxh3', serialize([
            'type' => $type,
            'identifier' => $identifier,
            'version' => $config['version'] ?? null,
            'salt' => $this->config['version_salt']
        ]));
    }

    /**
     * Update dependency graph
     */
    private function updateDependencyGraph(string $hash, array $dependencies): void {
        if (!isset($this->dependencyGraph[$hash])) {
            $this->dependencyGraph[$hash] = [];
        }

        foreach ($dependencies as $dependency) {
            $depHash = $this->generateResourceHash(
                $dependency['type'],
                $dependency['identifier'],
                $dependency
            );
            $this->dependencyGraph[$hash][] = $depHash;
        }
    }

    /**
     * Record performance metric
     */
    private function recordMetric(string $type, array $data): void {
        if ($this->metricsManager) {
            $this->metricsManager->recordMetric('resource_manager', $type, $data);
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
            'resource_count' => count($this->resources),
            'critical_resources' => count($this->criticalResources),
            'load_order' => count($this->loadOrder),
            'metrics' => $this->metrics
        ];
    }
}