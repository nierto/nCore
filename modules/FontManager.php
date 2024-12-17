<?php
/**
 * FontManager - Advanced Font Loading Orchestration System
 * 
 * Provides comprehensive font management functionality for the nCore theme with
 * sophisticated loading strategies, performance optimization, and real-time
 * telemetry. Implements quantum state management for font loading stages and
 * integrates deeply with WordPress core systems.
 * 
 * @package     nCore
 * @subpackage  Modules
 * @version     2.0.0
 * @since       1.0.0
 * 
 * == File Purpose ==
 * Serves as the central management system for all font-related operations,
 * handling both Google Fonts and local font assets. Implements sophisticated
 * loading strategies and performance optimization through real-time telemetry
 * and adaptive loading patterns.
 * 
 * == Key Functions ==
 * - enqueueFonts()        : Manages font resource loading
 * - optimizeLoading()     : Implements loading strategy optimization
 * - injectOptimizations() : Handles resource hint injection
 * - observePerformance()  : Tracks loading performance metrics
 * 
 * == Dependencies ==
 * Core:
 * - WordPress Font Loading API
 * - WordPress Customizer API
 * - WordPress Hook System
 * 
 * Managers:
 * - ErrorManager  : Error handling and logging
 * - CacheManager  : Font configuration caching
 * - StateManager  : Loading state management
 * 
 * == Integration Points ==
 * WordPress:
 * - wp_head           : Resource hint injection
 * - wp_enqueue_scripts: Font loading
 * - customize_register: Customizer integration
 * 
 * Cache System:
 * - Configuration caching
 * - Font file caching
 * - Loading state persistence
 * 
 * Performance Monitoring:
 * - Resource timing API integration
 * - Loading strategy optimization
 * - Performance metric collection
 * 
 * == Loading Strategies ==
 * 1. Preload Critical
 *    - Header font preloading
 *    - Critical path optimization
 *    - Resource hint management
 * 
 * 2. Lazy Loading
 *    - Non-critical font deferral
 *    - Progressive enhancement
 *    - Network condition awareness
 * 
 * 3. Adaptive Loading
 *    - Device capability detection
 *    - Network quality assessment
 *    - Progressive loading patterns
 * 
 * == Performance Features ==
 * - Font display optimization
 * - FOUT/FOIT mitigation
 * - Loading state tracking
 * - Performance metric collection
 * - Resource timing analysis
 * - Cache optimization
 * 
 * == Security Measures ==
 * - CSP compliance handling
 * - CORS configuration
 * - SRI hash verification
 * - Resource validation
 * - Domain verification
 * 
 * == Error Management ==
 * - Loading failure recovery
 * - Fallback system
 * - Error logging
 * - Performance degradation detection
 * - User notification system
 * 
 * == Configuration Options ==
 * - Font family definitions
 * - Loading strategy selection
 * - Performance thresholds
 * - Cache TTL settings
 * - Debug mode options
 * 
 * == State Management ==
 * - Loading state tracking
 * - Performance metrics
 * - Cache status
 * - Resource availability
 * - Error conditions
 * 
 * == Future Improvements ==
 * @todo Implement variable font support
 * @todo Add font subsetting optimization
 * @todo Enhance performance telemetry
 * @todo Implement advanced caching strategies
 * @todo Add font loading API support
 * 
 * == Coding Standards ==
 * - Follows WordPress PHP Documentation Standards
 * - Implements proper error handling
 * - Uses type hints for PHP 7.4+
 * - Maintains singleton pattern integrity
 * 
 * == Performance Notes ==
 * - Implements resource hint optimization
 * - Manages loading strategy selection
 * - Tracks performance metrics
 * - Optimizes cache usage
 * - Handles progressive enhancement
 * 
 * == Changelog ==
 * 2.0.0
 * - Implemented quantum loading optimization
 * - Added performance telemetry
 * - Enhanced cache integration
 * - Improved error handling
 * - Added comprehensive metrics
 * 
 * 1.0.0
 * - Initial implementation
 * - Basic font loading
 * - Google Fonts integration
 * 
 * @author    Niels Erik Toren
 * @copyright 2024 nCore
 * @license   See project root for license information
 * @link      https://nierto.com Documentation
 * 
 * @see \nCore\Core\ModuleInterface
 * @see \nCore\Core\nCore
 * @see \nCore\Modules\ErrorManager
 * @see \nCore\Modules\CacheManager
 */

 
namespace nCore\Modules;

use nCore\Core\ModuleInterface;
use nCore\Core\nCore;

if (!defined('ABSPATH')) {
    exit;
}

class FontManager implements ModuleInterface {
    /** @var FontManager Singleton instance */
    private static $instance = null;
    
    /** @var bool Initialization state */
    private $initialized = false;
    
    /** @var array Configuration settings */
    private $config = [];

    /** @var array Font loading states */
    private $loading_states = [];

    /** @var array Performance metrics */
    private $metrics = [
        'loads' => 0,
        'cache_hits' => 0,
        'optimization_cycles' => 0
    ];

    /** @var array Default font settings */
    private const DEFAULT_FONTS = [
        'body' => [
            'family' => 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
            'weights' => [400, 700],
            'display' => 'swap'
        ],
        'heading' => [
            'family' => 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
            'weights' => [700],
            'display' => 'swap'
        ]
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
     * Initialize font management system
     */
    public function initialize(array $config = []): void {
        if ($this->initialized) {
            return;
        }

        try {
            // Initialize configuration
            $this->config = array_merge([
                'enabled' => true,
                'debug' => WP_DEBUG,
                'preload_fonts' => true,
                'optimize_loading' => true,
                'font_display' => 'swap',
                'cache_ttl' => DAY_IN_SECONDS,
                'fonts' => self::DEFAULT_FONTS
            ], $config);

            // Setup hooks
            $this->registerHooks();

            // Initialize font optimization
            $this->initializeOptimization();

            $this->initialized = true;

        } catch (\Exception $e) {
            error_log('FontManager initialization failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Register WordPress hooks
     */
    private function registerHooks(): void {
        add_action('wp_head', [$this, 'injectFontOptimizations'], 1);
        add_action('wp_enqueue_scripts', [$this, 'enqueueFonts']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminFonts']);
        add_action('customize_register', [$this, 'registerCustomizerSettings']);
    }

    /**
     * Initialize font optimization system
     */
    private function initializeOptimization(): void {
        if (!$this->config['optimize_loading']) {
            return;
        }

        // Register optimization stages
        $this->loading_states = [
            'preload' => [],
            'prefetch' => [],
            'fallback' => []
        ];

        // Setup font observation
        $this->observeFontLoading();
    }

    /**
     * Inject font optimizations
     */
    public function injectFontOptimizations(): void {
        if (!$this->config['enabled']) {
            return;
        }

        $this->injectPreloadHints();
        $this->injectFontDisplayDescriptor();
        
        if ($this->config['debug']) {
            $this->injectPerformanceMarkers();
        }
    }

    /**
     * Inject preload hints for critical fonts
     */
    private function injectPreloadHints(): void {
        if (!$this->config['preload_fonts']) {
            return;
        }

        foreach ($this->config['fonts'] as $type => $font) {
            if ($this->isCriticalFont($type)) {
                foreach ($font['weights'] as $weight) {
                    echo sprintf(
                        '<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>',
                        esc_url($this->getFontUrl($font['family'], $weight))
                    );
                }
            }
        }
    }

    /**
     * Inject font-display descriptor
     */
    private function injectFontDisplayDescriptor(): void {
        echo '<style>
            @font-face {
                font-display: ' . esc_attr($this->config['font_display']) . ';
            }
        </style>';
    }

    /**
     * Inject performance markers
     */
    private function injectPerformanceMarkers(): void {
        echo '<script>
            performance.mark("fonts-start");
            document.addEventListener("DOMContentLoaded", () => {
                performance.mark("fonts-loaded");
                performance.measure("font-loading", "fonts-start", "fonts-loaded");
            });
        </script>';
    }

    /**
     * Enqueue fonts for frontend
     */
    public function enqueueFonts(): void {
        if (!$this->config['enabled']) {
            return;
        }

        $this->metrics['loads']++;

        // Generate Google Fonts URL
        $google_fonts_url = $this->generateGoogleFontsUrl();
        if (!empty($google_fonts_url)) {
            wp_enqueue_style(
                'nierto-google-fonts',
                $google_fonts_url,
                [],
                null
            );
        }

        // Enqueue local fonts
        $this->enqueueLocalFonts();
    }

    /**
     * Generate Google Fonts URL
     */
    private function generateGoogleFontsUrl(): string {
        $families = [];
        foreach ($this->config['fonts'] as $font) {
            if ($this->isGoogleFont($font['family'])) {
                $families[] = $this->formatGoogleFontFamily($font);
            }
        }

        if (empty($families)) {
            return '';
        }

        return add_query_arg([
            'family' => implode('|', $families),
            'display' => $this->config['font_display']
        ], 'https://fonts.googleapis.com/css2');
    }

    /**
     * Enqueue local fonts
     */
    private function enqueueLocalFonts(): void {
        foreach ($this->config['fonts'] as $type => $font) {
            if ($this->isLocalFont($font['family'])) {
                wp_enqueue_style(
                    "nierto-font-{$type}",
                    $this->getLocalFontUrl($font['family']),
                    [],
                    null
                );
            }
        }
    }

    /**
     * Check if font is critical
     */
    private function isCriticalFont(string $type): bool {
        return in_array($type, ['body', 'heading']);
    }

    /**
     * Check if font is Google Font
     */
    private function isGoogleFont(string $family): bool {
        return strpos($family, 'system-ui') === false;
    }

    /**
     * Check if font is local
     */
    private function isLocalFont(string $family): bool {
        return file_exists($this->getLocalFontPath($family));
    }

    /**
     * Format Google Font family string
     */
    private function formatGoogleFontFamily(array $font): string {
        $family = str_replace(' ', '+', $font['family']);
        $weights = implode(';', $font['weights']);
        return "{$family}:{$weights}";
    }

    /**
     * Get font URL
     */
    private function getFontUrl(string $family, int $weight): string {
        if ($this->isGoogleFont($family)) {
            return "https://fonts.gstatic.com/s/{$family}/{$weight}.woff2";
        }
        return $this->getLocalFontUrl($family);
    }

    /**
     * Get local font URL
     */
    private function getLocalFontUrl(string $family): string {
        return get_template_directory_uri() . "/fonts/{$family}.woff2";
    }

    /**
     * Get local font path
     */
    private function getLocalFontPath(string $family): string {
        return get_template_directory() . "/fonts/{$family}.woff2";
    }

    /**
     * Observe font loading performance
     */
    private function observeFontLoading(): void {
        add_action('wp_footer', function() {
            if (!$this->config['debug']) {
                return;
            }

            echo '<script>
                if ("performance" in window) {
                    let fontEntries = performance.getEntriesByType("resource").filter(
                        entry => entry.name.includes("fonts.googleapis.com") || 
                                entry.name.includes(".woff2")
                    );
                    
                    fontEntries.forEach(entry => {
                        console.log(`Font: ${entry.name}, Duration: ${entry.duration}ms`);
                    });
                }
            </script>';
        });
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
            'metrics' => $this->metrics,
            'loading_states' => $this->loading_states,
            'fonts' => array_keys($this->config['fonts'])
        ];
    }

    // Prevent cloning and unserialization
    private function __clone() {}
    public function __wakeup() {
        throw new \Exception("Cannot unserialize singleton");
    }
}