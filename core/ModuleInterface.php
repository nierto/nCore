<?php
/**
 * nCore Module Interface
 * 
 * Defines the contract that all nCore modules must implement.
 * 
 * @package     nCore
 * @subpackage  Core
 * @version     2.0.0
 */

namespace nCore\Core;

interface ModuleInterface {
    /**
     * Get singleton instance
     */
    public static function getInstance(): self;

    /**
     * Initialize the module
     * 
     * @param array $config Configuration options
     */
    public function initialize(array $config = []): void;

    /**
     * Get module configuration
     */
    public function getConfig(): array;

    /**
     * Update module configuration
     */
    public function updateConfig(array $config): void;

    /**
     * Check if module is initialized
     */
    public function isInitialized(): bool;

    /**
     * Get module status information
     */
    public function getStatus(): array;
}