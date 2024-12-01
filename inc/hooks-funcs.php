<?php
/**
 * nCore Hook Management System
 * 
 * This file provides a comprehensive hook system for the nCore theme,
 * establishing standardized integration points for theme customization and extension.
 * It implements both action and filter hooks following WordPress coding standards
 * while maintaining consistent naming conventions across the theme.
 * 
 * @package     nCore
 * @subpackage  Hooks
 * @since       1.0.0
 * 
 * == File Purpose ==
 * Defines and manages all custom hooks used throughout the nCore theme,
 * providing standardized integration points for theme customization and extension.
 * Acts as a central registry for all theme-specific hooks, ensuring consistent
 * naming and usage patterns.
 * 
 * == Key Functions ==
 * - nCore_face_content()       - Filter for modifying cube face content
 * - nCore_before_cube()        - Action before cube rendering
 * - nCore_after_cube()         - Action after cube rendering
 * - nCore_rotation_speed()     - Filter for cube rotation speed
 * - nCore_face_settings()      - Filter for modifying face settings
 * - nCore_cache_behavior()     - Filter for cache behavior modification
 * - nCore_manifest_settings()  - Filter for PWA manifest settings
 * - nCore_before_manifest_cache() - Action before manifest caching
 * - nCore_after_manifest_cache()  - Action after manifest caching
 * 
 * == Dependencies ==
 * Core:
 * - WordPress Core (add_action, add_filter, apply_filters, do_action)
 * 
 * Internal:
 * - CacheManager.php (for cache-related hooks)
 * - manifest-settings.php (for manifest-related hooks)
 * - APIManager.php (for API response hooks)
 * 
 * == Hook Categories ==
 * 1. Cube Face Hooks:
 *    - Content modification
 *    - Rendering lifecycle
 *    - Animation control
 * 
 * 2. Cache System Hooks:
 *    - Cache behavior
 *    - Operation lifecycle
 *    - Invalidation events
 * 
 * 3. API Hooks:
 *    - Response modification
 *    - Custom handlers
 *    - Error management
 * 
 * 4. Manifest Hooks:
 *    - Settings modification
 *    - Cache lifecycle
 * 
 * == Design Patterns ==
 * - Follows WordPress hook naming conventions
 * - Implements before/after pattern for major operations
 * - Uses filter pattern for data modification
 * - Maintains consistent prefix 'nCore_'
 * 
 * == Usage Example ==
 * ```php
 * // Modifying cube face content
 * add_filter('nCore_face_content', function($content, $face_id) {
 *     // Modify content
 *     return $content;
 * }, 10, 2);
 * 
 * // Adding custom action before cube render
 * add_action('nCore_before_cube', function() {
 *     // Execute before cube renders
 * });
 * ```
 * 
 * == Architectural Notes ==
 * - All hooks maintain consistent naming pattern: 'nCore_*'
 * - Action hooks use before/after pattern for operational clarity
 * - Filter hooks always include relevant context parameters
 * - Hook priorities follow WordPress standard (default: 10)
 * 
 * == Future Improvements ==
 * 1. Add hook documentation generation system
 * 2. Implement hook deprecation system
 * 3. Add hook performance monitoring
 * 4. Create hook usage tracking for debugging
 * 5. Add hook authorization system for sensitive operations
 * 
 * == Change Log ==
 * 1.0.0 - Initial version
 *       - Established core hook system
 *       - Implemented cache and manifest hooks
 *       - Added API response hooks
 * 
 * == Security Notes ==
 * - All filter hooks should validate and sanitize input
 * - Cache-related hooks verify permissions
 * - API hooks implement nonce verification
 * 
 * @see CacheManager      For cache-related hook implementations
 * @see APIManager        For API hook integrations
 * @see ManifestSettings For manifest hook usage
 */
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Action hooks for cube face content
function nCore_face_content($content, $face_id) {
    return apply_filters('nCore_face_content', $content, $face_id);
}

// Action before rendering cube
function nCore_before_cube() {
    do_action('nCore_before_cube');
}

// Action after rendering cube
function nCore_after_cube() {
    do_action('nCore_after_cube');
}

// Filter for cube rotation speed
function nCore_rotation_speed($speed) {
    return apply_filters('nCore_rotation_speed', $speed);
}

// Action for adding custom scripts
function nCore_enqueue_scripts() {
    do_action('nCore_enqueue_scripts');
}

// Filter for modifying cube face settings
function nCore_face_settings($settings, $face_id) {
    return apply_filters('nCore_face_settings', $settings, $face_id);
}

// Hook for modifying cache behavior
function nCore_cache_behavior($behavior) {
    return apply_filters('nCore_cache_behavior', $behavior);
}

// Action before cache operations
function nCore_before_cache_operation($operation, $key) {
    do_action('nCore_before_cache_operation', $operation, $key);
}

// Action after cache operations
function nCore_after_cache_operation($operation, $key, $result) {
    do_action('nCore_after_cache_operation', $operation, $key, $result);
}

// Filter for modifying AJAX response
function nCore_ajax_response($response, $action) {
    return apply_filters('nCore_ajax_response', $response, $action);
}

// Action for custom AJAX handlers
function nCore_custom_ajax_handler($action) {
    do_action('nCore_custom_ajax_handler', $action);
}

function nCore_manifest_settings($settings) {
    return apply_filters('nCore_manifest_settings', $settings);
}

function nCore_before_manifest_cache() {
    do_action('nCore_before_manifest_cache');
}

function nCore_after_manifest_cache() {
    do_action('nCore_after_manifest_cache');
}