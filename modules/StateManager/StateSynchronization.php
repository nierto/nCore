<?php

namespace nCore\StateManager\Traits;

trait StateSynchronization {
    /** @var array Sync handlers registry */
    private $syncHandlers = [];
    
    /** @var bool Active listening state */
    private $isListening = false;
    
    /** @var array Sync queue for batched operations */
    private $syncQueue = [];
    
    /** @var int Queue processing interval in milliseconds */
    private const QUEUE_PROCESS_INTERVAL = 100;
    
    /** @var int Maximum conflict resolution attempts */
    private const MAX_RESOLUTION_ATTEMPTS = 3;
    
    /** @var array Conflict resolution strategies */
    private const RESOLUTION_STRATEGIES = [
        'TIMESTAMP' => 'resolveByTimestamp',
        'VERSION' => 'resolveByVersion',
        'MERGE' => 'resolveByMerge',
        'CUSTOM' => 'resolveByCustom'
    ];

    /**
     * Synchronize state with remote sources
     */
    public function synchronizeState(): void {
        if (!$this->initialized) {
            throw new \RuntimeException('StateManager not initialized');
        }

        $this->recordMetric('sync', 'start_sync');
        $syncStart = microtime(true);

        try {
            // Get remote states from all sync handlers
            $remoteStates = [];
            foreach ($this->syncHandlers as $handler) {
                $remoteState = $handler['callback']('GET_STATE');
                if ($remoteState) {
                    $remoteStates[] = $remoteState;
                }
            }

            // Begin transaction for atomic sync
            $transactionId = $this->beginTransaction();

            // Resolve conflicts and merge states
            $resolvedState = $this->state;
            foreach ($remoteStates as $remoteState) {
                $resolvedState = $this->resolveConflicts($remoteState);
                
                // Apply resolved changes
                foreach ($resolvedState as $key => $value) {
                    if (!isset($this->state[$key]) || $this->state[$key] !== $value) {
                        $this->setState($key, $value, [
                            'atomic' => false,
                            'sync' => true
                        ]);
                    }
                }
            }

            // Commit transaction
            $this->commitTransaction($transactionId);

            // Broadcast final state
            $this->broadcastChange('FULL_STATE', $this->state);

            $this->recordMetric('sync', 'complete_sync', [
                'duration' => microtime(true) - $syncStart,
                'changes' => count($resolvedState)
            ]);

        } catch (\Exception $e) {
            if (isset($transactionId)) {
                $this->rollbackTransaction($transactionId);
            }
            
            $this->errorManager->logError('sync_failed', [
                'error' => $e->getMessage(),
                'duration' => microtime(true) - $syncStart
            ]);
            
            throw $e;
        }
    }

    /**
     * Resolve conflicts between local and remote state
     */
    public function resolveConflicts(array $remoteState): array {
        $resolvedState = [];
        $conflicts = [];
        $attempt = 0;

        while ($attempt < self::MAX_RESOLUTION_ATTEMPTS) {
            try {
                foreach ($remoteState as $key => $value) {
                    if (!isset($this->state[$key])) {
                        // New key from remote - accept
                        $resolvedState[$key] = $value;
                        continue;
                    }

                    if ($this->state[$key] === $value) {
                        // No conflict - keep current
                        $resolvedState[$key] = $this->state[$key];
                        continue;
                    }

                    // Conflict detected - try resolution strategies
                    foreach (self::RESOLUTION_STRATEGIES as $strategy => $method) {
                        $resolution = $this->$method($key, $this->state[$key], $value);
                        if ($resolution !== null) {
                            $resolvedState[$key] = $resolution;
                            break 2;
                        }
                    }

                    // If no resolution found, mark as conflict
                    $conflicts[$key] = [
                        'local' => $this->state[$key],
                        'remote' => $value
                    ];
                }

                // If no conflicts remain, break
                if (empty($conflicts)) {
                    break;
                }

                $attempt++;

            } catch (\Exception $e) {
                $this->errorManager->logError('conflict_resolution_failed', [
                    'attempt' => $attempt,
                    'error' => $e->getMessage()
                ]);
                $attempt++;
            }
        }

        // Log unresolved conflicts
        if (!empty($conflicts)) {
            $this->errorManager->logWarning('unresolved_conflicts', [
                'conflicts' => $conflicts
            ]);
        }

        return $resolvedState;
    }

    /**
     * Broadcast state change to all sync handlers
     */
    public function broadcastChange(string $key, $value): void {
        if (empty($this->syncHandlers)) {
            return;
        }

        $this->recordMetric('sync', 'broadcast');

        $change = [
            'key' => $key,
            'value' => $value,
            'timestamp' => microtime(true),
            'source' => 'local',
            'version' => $this->getStateVersion($key)
        ];

        // Add to sync queue for batched processing
        $this->syncQueue[] = $change;

        // Process queue if beyond threshold
        if (count($this->syncQueue) >= ($this->config['sync_batch_size'] ?? 10)) {
            $this->processSyncQueue();
        }
    }

    /**
     * Start listening for remote changes
     */
    public function listenForChanges(): void {
        if ($this->isListening) {
            return;
        }

        $this->isListening = true;
        $this->recordMetric('sync', 'start_listening');

        // Set up WebSocket connection if available
        if ($this->hasWebSocketSupport()) {
            $this->initializeWebSocket();
        }

        // Set up polling fallback
        $this->initializePolling();

        // Start queue processing interval
        add_action('wp_loaded', function() {
            wp_schedule_single_event(
                time() + (self::QUEUE_PROCESS_INTERVAL / 1000),
                'process_sync_queue'
            );
        });
    }

    /**
     * Stop listening for remote changes
     */
    public function stopListening(): void {
        if (!$this->isListening) {
            return;
        }

        $this->isListening = false;
        $this->recordMetric('sync', 'stop_listening');

        // Clean up WebSocket connection
        if ($this->webSocket) {
            $this->webSocket->close();
        }

        // Clear polling interval
        wp_clear_scheduled_hook('process_sync_queue');
    }

    /**
     * Register sync handler
     */
    public function registerSyncHandler(callable $handler): string {
        $handlerId = uniqid('sync_handler_', true);
        
        $this->syncHandlers[$handlerId] = [
            'callback' => $handler,
            'registered' => time()
        ];

        $this->recordMetric('sync', 'handler_registered');
        
        return $handlerId;
    }

    /**
     * Unregister sync handler
     */
    public function unregisterSyncHandler(string $handlerId): bool {
        if (!isset($this->syncHandlers[$handlerId])) {
            return false;
        }

        unset($this->syncHandlers[$handlerId]);
        $this->recordMetric('sync', 'handler_unregistered');
        
        return true;
    }

    /**
     * Process sync queue
     */
    private function processSyncQueue(): void {
        if (empty($this->syncQueue)) {
            return;
        }

        $processStart = microtime(true);

        try {
            foreach ($this->syncHandlers as $handler) {
                $handler['callback']('BATCH_UPDATE', $this->syncQueue);
            }

            $this->recordMetric('sync', 'queue_processed', [
                'count' => count($this->syncQueue),
                'duration' => microtime(true) - $processStart
            ]);

            $this->syncQueue = [];

        } catch (\Exception $e) {
            $this->errorManager->logError('queue_processing_failed', [
                'error' => $e->getMessage(),
                'queue_size' => count($this->syncQueue)
            ]);
        }
    }

    /**
     * Resolution strategy: Compare timestamps
     */
    private function resolveByTimestamp(string $key, $localValue, $remoteValue): ?mixed {
        $localMeta = $this->getStateMetadata($key);
        $remoteMeta = $this->getRemoteMetadata($key);

        if (!isset($localMeta['timestamp']) || !isset($remoteMeta['timestamp'])) {
            return null;
        }

        return $remoteMeta['timestamp'] > $localMeta['timestamp'] ? $remoteValue : $localValue;
    }

    /**
     * Resolution strategy: Compare versions
     */
    private function resolveByVersion(string $key, $localValue, $remoteValue): ?mixed {
        $localMeta = $this->getStateMetadata($key);
        $remoteMeta = $this->getRemoteMetadata($key);

        if (!isset($localMeta['version']) || !isset($remoteMeta['version'])) {
            return null;
        }

        return version_compare($remoteMeta['version'], $localMeta['version'], '>') ? $remoteValue : $localValue;
    }

    /**
     * Resolution strategy: Attempt to merge values
     */
    private function resolveByMerge(string $key, $localValue, $remoteValue): ?mixed {
        if (!is_array($localValue) || !is_array($remoteValue)) {
            return null;
        }

        return array_replace_recursive($localValue, $remoteValue);
    }

    /**
     * Resolution strategy: Use custom resolver if registered
     */
    private function resolveByCustom(string $key, $localValue, $remoteValue): ?mixed {
        if (!isset($this->config['custom_resolver'])) {
            return null;
        }

        return call_user_func(
            $this->config['custom_resolver'],
            $key,
            $localValue,
            $remoteValue
        );
    }

    /**
     * Initialize WebSocket connection
     */
    private function initializeWebSocket(): void {
        if (!$this->config['websocket_url']) {
            return;
        }

        // WebSocket initialization code...
        $this->webSocket = new \WebSocket\Client($this->config['websocket_url']);
        
        $this->webSocket->on('message', function($data) {
            $this->handleRemoteChange(json_decode($data, true));
        });
    }

    /**
     * Initialize polling fallback
     */
    private function initializePolling(): void {
        if (!$this->config['enable_polling']) {
            return;
        }

        add_action('wp_ajax_state_poll', [$this, 'handleStatePolling']);
        add_action('wp_ajax_nopriv_state_poll', [$this, 'handleStatePolling']);
    }

    /**
     * Handle incoming remote change
     */
    private function handleRemoteChange(array $change): void {
        if (!isset($change['key']) || !isset($change['value'])) {
            return;
        }

        try {
            $this->setState($change['key'], $change['value'], [
                'sync' => false,
                'remote' => true
            ]);
        } catch (\Exception $e) {
            $this->errorManager->logError('remote_change_failed', [
                'change' => $change,
                'error' => $e->getMessage()
            ]);
        }
    }
}