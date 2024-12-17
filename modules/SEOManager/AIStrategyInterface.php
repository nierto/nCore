<?php
/**
 * AI Strategy Interface for SEO Enhancement
 * 
 * Defines the contract that all AI strategies must implement for
 * SEO content enhancement functionality.
 * 
 * @package     nCore
 * @subpackage  SEO\AIStrategies
 * @version     2.0.0
 */

namespace nCore\SEO\AIStrategies;

interface AIStrategyInterface {
    /**
     * Initialize the AI strategy
     */
    public function initialize(): void;

    /**
     * Enhance meta tags using AI
     * 
     * @param array $context Content context including title, content, and current meta
     * @return array Enhanced meta tags
     */
    public function enhanceMeta(array $context): array;

    /**
     * Enhance schema data using AI
     * 
     * @param array $context Schema context including type, content, and current schema
     * @return array Enhanced schema data
     */
    public function enhanceSchema(array $context): array;

    /**
     * Get strategy configuration
     */
    public function getConfig(): array;

    /**
     * Update strategy configuration
     */
    public function updateConfig(array $config): void;

    /**
     * Check if strategy is available
     */
    public function isAvailable(): bool;

    /**
     * Get strategy status
     */
    public function getStatus(): array;
}