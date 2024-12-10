<?php

namespace nCore\StateManager\Traits;

/**
 * Advanced state manipulation trait for StateManager
 * 
 * Provides bleeding-edge state management with features like:
 * - Atomic transactions
 * - Validation chains
 * - Middleware processing
 * - Automatic conflict resolution
 * - Performance optimization
 * - State compression
 * - Real-time sync
 */
trait StateManipulation {
    /** @var array Validation chain registry */
    private $validationChains = [];

    /** @var array State compression settings */
    private $compressionSettings = [
        'threshold' => 1024,  // Bytes
        'algorithm' => 'zstd', // Modern compression
        'level' => 3          // Balanced setting
    ];

    /** @var array State access patterns */
    private $accessPatterns = [];

    /** @var array State segments for sharding */
    private $stateSegments = [];

    /**
     * Set state with advanced validation and processing
     *
     * @param string $key State key
     * @param mixed $value State value
     * @param array $options Setting options
     * @throws \RuntimeException If validation or processing fails
     */
    public function setState(string $key, $value, array $options = []): void {
        if (!$this->initialized) {
            throw new \RuntimeException('StateManager not initialized');
        }

        $startTime = hrtime(true);

        try {
            $this->recordMetric('operation', 'state_update');
            
            // Process options with defaults
            $options = array_merge([
                'atomic' => false,
                'validate' => true,
                'compress' => true,
                'track_history' => true,
                'notify' => true,
                'persist' => false,
                'timeout' => 5000, // ms
                'priority' => 'normal',
                'sync' => true
            ], $options);

            // Start transaction if needed
            $internalTransaction = false;
            if (!$this->inTransaction && $options['atomic']) {
                $this->beginTransaction();
                $internalTransaction = true;
            }

            // Run validation chain
            if ($options['validate']) {
                $this->runValidationChain($key, $value);
            }

            // Process through middleware
            $processedValue = $this->runMiddlewareChain('setState', [
                'key' => $key,
                'value' => $value,
                'options' => $options
            ]);

            // Compress if needed
            if ($options['compress'] && $this->shouldCompress($processedValue['value'])) {
                $processedValue['value'] = $this->compressState($processedValue['value']);
            }

            // Optimize storage based on access patterns
            $this->optimizeStateStorage($key);

            // Get old value for notifications and history
            $oldValue = $this->state[$key] ?? null;

            // Update state with conflict resolution
            $this->updateStateValue($key, $processedValue['value'], $oldValue);

            // Track access pattern
            $this->trackStateAccess($key, 'write');

            // Add to history if enabled
            if ($options['track_history']) {
                $this->pushToHistory($key);
            }

            // Handle notifications
            if ($options['notify']) {
                $this->notifyStateChange($key, $oldValue, $this->state[$key]);
            }

            // Persist if requested
            if ($options['persist']) {
                $this->persistStateValue($key);
            }

            // Sync if enabled
            if ($options['sync']) {
                $this->syncState($key);
            }

            // Complete internal transaction
            if ($internalTransaction) {
                $this->commitTransaction($this->currentTransaction);
            }

            // Record performance metrics
            $this->recordPerformanceMetrics($key, $startTime);

        } catch (\Exception $e) {
            if ($internalTransaction ?? false) {
                $this->rollbackTransaction($this->currentTransaction);
            }
            
            $this->handleStateError('set_state_failed', $e, [
                'key' => $key,
                'operation' => 'setState'
            ]);
            
            throw $e;
        }
    }

    /**
     * Get state value with intelligent caching and optimization
     *
     * @param string $key State key
     * @param mixed $default Default value if not found
     * @return mixed State value
     */
    public function getState(string $key, $default = null) {
        $this->recordMetric('operation', 'state_read');
        $this->trackStateAccess($key, 'read');

        try {
            // Check memory cache first
            if (isset($this->state[$key])) {
                $value = $this->state[$key];
                
                // Decompress if needed
                if ($this->isCompressed($value)) {
                    $value = $this->decompressState($value);
                }
                
                return $value;
            }

            // Check distributed cache
            if ($this->cacheManager && ($this->config['use_cache'] ?? true)) {
                $cached = $this->cacheManager->get($key, 'state');
                if ($cached !== false) {
                    // Store in memory for future access
                    $this->state[$key] = $cached;
                    return $this->isCompressed($cached) ? 
                           $this->decompressState($cached) : 
                           $cached;
                }
            }

            // Check backup storage
            $value = $this->loadFromBackupStorage($key);
            if ($value !== null) {
                return $value;
            }

            return $default;

        } catch (\Exception $e) {
            $this->handleStateError('get_state_failed', $e, [
                'key' => $key,
                'operation' => 'getState'
            ]);
            return $default;
        }
    }

    /**
     * Remove state with comprehensive cleanup
     *
     * @param string $key State key
     * @return bool Success status
     */
    public function removeState(string $key): bool {
        if (!$this->initialized || !isset($this->state[$key])) {
            return false;
        }

        try {
            $this->recordMetric('operation', 'state_remove');
            
            // Start atomic operation
            $transactionId = $this->beginTransaction();
            
            // Store old value for history
            $oldValue = $this->state[$key];

            // Add to history
            $this->pushToHistory($key);

            // Remove from all storage layers
            $this->cleanupState($key);

            // Update access patterns
            $this->removeAccessPattern($key);

            // Notify observers
            $this->notifyStateChange($key, $oldValue, null);

            // Commit transaction
            $this->commitTransaction($transactionId);

            return true;

        } catch (\Exception $e) {
            $this->rollbackTransaction($transactionId);
            $this->handleStateError('remove_state_failed', $e, [
                'key' => $key,
                'operation' => 'removeState'
            ]);
            return false;
        }
    }

    /**
     * Merge multiple state updates atomically
     *
     * @param array $newState Key-value pairs to merge
     * @param array $options Merge options
     */
    public function mergeState(array $newState, array $options = []): void {
        if (!$this->initialized) {
            throw new \RuntimeException('StateManager not initialized');
        }

        // Start transaction
        $transactionId = $this->beginTransaction();

        try {
            // Prepare batch operation
            $batch = $this->prepareBatchOperation($newState);

            // Validate entire batch
            if ($options['validate'] ?? true) {
                $this->validateBatch($batch);
            }

            // Apply changes
            foreach ($batch as $key => $update) {
                $this->setState($key, $update['value'], array_merge($options, [
                    'atomic' => false,
                    'persist' => false
                ]));
            }

            // Optimize storage after batch
            $this->optimizeBatchStorage($batch);

            // Persist all changes
            if ($options['persist'] ?? false) {
                $this->persistBatch(array_keys($newState));
            }

            $this->commitTransaction($transactionId);

        } catch (\Exception $e) {
            $this->rollbackTransaction($transactionId);
            throw $e;
        }
    }

    /**
     * Check if state exists with validation
     *
     * @param string $key State key
     * @return bool Existence status
     */
    public function hasState(string $key): bool {
        $this->trackStateAccess($key, 'check');
        
        // Check memory first
        if (isset($this->state[$key])) {
            return true;
        }

        // Check cache if enabled
        if ($this->cacheManager && ($this->config['use_cache'] ?? true)) {
            return $this->cacheManager->has($key, 'state');
        }

        return false;
    }

    /**
     * Reset state to initial values with cleanup
     *
     * @param array|null $keys Specific keys to reset
     */
    public function resetState(?array $keys = null): void {
        if (!$this->initialized) {
            throw new \RuntimeException('StateManager not initialized');
        }

        $this->recordMetric('operation', 'state_reset');
        $transactionId = $this->beginTransaction();

        try {
            $oldState = $keys === null ? $this->state : array_intersect_key($this->state, array_flip($keys));

            // Clear state
            if ($keys === null) {
                $this->state = [];
                $this->accessPatterns = [];
                $this->stateSegments = [];
            } else {
                foreach ($keys as $key) {
                    unset($this->state[$key]);
                    unset($this->accessPatterns[$key]);
                }
            }

            // Notify observers
            foreach ($oldState as $key => $value) {
                $this->notifyStateChange($key, $value, null);
            }

            // Clear caches
            if ($this->cacheManager) {
                $keys === null ? 
                    $this->cacheManager->flush('state') :
                    $this->cacheManager->deleteMultiple($keys, 'state');
            }

            $this->commitTransaction($transactionId);

        } catch (\Exception $e) {
            $this->rollbackTransaction($transactionId);
            throw $e;
        }
    }

    // Private helper methods

    /**
     * Run validation chain for state change
     */
    private function runValidationChain(string $key, $value): void {
        if (!isset($this->validationChains[$key])) {
            return;
        }

        foreach ($this->validationChains[$key] as $validator) {
            if (!$validator($value)) {
                throw new \RuntimeException("Validation failed for key: {$key}");
            }
        }
    }

    /**
     * Process state value through middleware chain
     */
    private function runMiddlewareChain(string $operation, array $data): array {
        foreach ($this->middleware as $middleware) {
            $data = $middleware($operation, $data);
        }
        return $data;
    }

    /**
     * Check if value should be compressed
     */
    private function shouldCompress($value): bool {
        if (!is_string($value) && !is_array($value)) {
            return false;
        }

        $size = is_array($value) ? strlen(serialize($value)) : strlen($value);
        return $size > $this->compressionSettings['threshold'];
    }

    /**
     * Compress state value
     */
    private function compressState($value): string {
        if (is_array($value)) {
            $value = serialize($value);
        }

        return zstd_compress($value, $this->compressionSettings['level']);
    }

    /**
     * Decompress state value
     */
    private function decompressState(string $value) {
        $decompressed = zstd_uncompress($value);
        
        // Check if serialized
        if ($this->isSerialized($decompressed)) {
            return unserialize($decompressed);
        }

        return $decompressed;
    }

    /**
     * Track state access patterns
     */
    private function trackStateAccess(string $key, string $type): void {
        if (!isset($this->accessPatterns[$key])) {
            $this->accessPatterns[$key] = [
                'reads' => 0,
                'writes' => 0,
                'checks' => 0,
                'last_access' => 0
            ];
        }

        $this->accessPatterns[$key][$type . 's']++;
        $this->accessPatterns[$key]['last_access'] = time();
    }

    /**
     * Optimize state storage based on access patterns
     */
    private function optimizeStateStorage(string $key): void {
        if (!isset($this->accessPatterns[$key])) {
            return;
        }

        $pattern = $this->accessPatterns[$key];
        
        // Heavy read optimization
        if ($pattern['reads'] > $pattern['writes'] * 3) {
            $this->optimizeForReads($key);
        }
        // Heavy write optimization
        elseif ($pattern['writes'] > $pattern['reads'] * 2) {
            $this->optimizeForWrites($key);
        }
    }

    /**
     * Optimize state for read operations
     */
    private function optimizeForReads(string $key): void {
        if ($this->cacheManager) {
            // Increase cache TTL
            $this->cacheManager->setTtl($key, 'state', HOUR_IN_SECONDS * 2);
            
            // Consider preloading related states
            $this->preloadRelatedStates($key);
        }
    }

    /**
     * Optimize state for write operations
     */
    private function optimizeForWrites(string $key): void {
        if ($this->cacheManager) {
            // Decrease cache TTL
            $this->cacheManager->setTtl($key, 'state', MINUTE_IN_SECONDS * 5);
            
            // Consider write buffering
            $this->enableWriteBuffer($key);
        }
    }

    /**
     * Record detailed performance metrics
     */
    private function recordPerformanceMetrics(string $key, int $startTime): void {
        $duration = hrtime(true) - $startTime;
        
        $this->metrics['operations'][] = [
            'key' => $key,
            'type' => 'setState',
            'duration' => $duration,
            'memory_delta' => memory_get_usage(true) - $this->metrics['memory']['initial'],
            'timestamp' => microtime(true)
        ];

        // Maintain metrics limit
        if (count($this->metrics['operations']) > 1000) {
            array_shift($this->metrics['operations']);
        }
    }

    /**
     * Handle state errors with logging and recovery
     */
    private function handleStateError(string $type, \Exception $error, array $context): void {
        $this->errorManager->logError($type, array_merge($context, [
            'error' => $error->getMessage(),
            'trace' => $error->getTraceAsString()
        ]));

        // Attempt recovery if configured
        if ($this->config['auto_recovery'] ?? false) {
            $this->attemptStateRecovery($context['key']);
        }
    }
}