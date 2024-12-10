<?php

namespace nCore\StateManager\Traits;

use nCore\Core\EventEmitter;
use nCore\Core\Observable;
use nCore\Core\PerformanceMetrics;
use nCore\Cache\CacheInterface;

/**
 * Advanced State Observation System
 * 
 * Provides real-time state observation with performance optimization,
 * batched notifications, and intelligent middleware processing.
 */
trait StateObservation {
    /** @var array<string, array<string, callable>> Observer registry */
    private $observers = [];

    /** @var array<string, callable> Middleware stack */
    private $middleware = [];

    /** @var array<string, array> Observer metadata */
    private $observerMeta = [];

    /** @var array Event batching queue */
    private $eventQueue = [];

    /** @var bool Notification batching status */
    private $batchingEnabled = false;

    /** @var int Maximum batch size */
    private const MAX_BATCH_SIZE = 100;

    /** @var int Observer cleanup threshold */
    private const OBSERVER_CLEANUP_THRESHOLD = 1000;

    /** @var array Performance metrics */
    private $observerMetrics = [
        'notifications_sent' => 0,
        'batches_processed' => 0,
        'observers_cleaned' => 0
    ];

    /** @var array Priority levels */
    private const PRIORITY_LEVELS = [
        'CRITICAL' => 0,
        'HIGH' => 1,
        'NORMAL' => 2,
        'LOW' => 3,
        'BACKGROUND' => 4
    ];

    /**
     * Subscribe to state changes with advanced options
     *
     * @param string $key State key to observe
     * @param callable $callback Observer callback
     * @param array $options Subscription options
     * @return string Observer ID
     * @throws \InvalidArgumentException
     */
    public function subscribe(string $key, callable $callback, array $options = []): string {
        $this->validateKey($key);
        $this->validateCallback($callback);

        $observerId = $this->generateObserverId();
        $priority = $options['priority'] ?? self::PRIORITY_LEVELS['NORMAL'];
        
        // Initialize observer metadata
        $this->observerMeta[$observerId] = [
            'created' => microtime(true),
            'last_called' => null,
            'call_count' => 0,
            'error_count' => 0,
            'average_execution_time' => 0,
            'priority' => $priority
        ];

        // Register observer with priority
        if (!isset($this->observers[$key][$priority])) {
            $this->observers[$key][$priority] = [];
        }
        $this->observers[$key][$priority][$observerId] = $callback;

        // Setup conditional observation
        if (isset($options['condition'])) {
            $this->registerCondition($key, $observerId, $options['condition']);
        }

        // Initialize debouncing if requested
        if (isset($options['debounce'])) {
            $this->setupDebounce($key, $observerId, $options['debounce']);
        }

        // Cache observer configuration if persistence enabled
        if ($options['persistent'] ?? false) {
            $this->persistObserverConfig($key, $observerId, $options);
        }

        $this->recordMetric('observer_registered', [
            'key' => $key,
            'id' => $observerId,
            'priority' => $priority
        ]);

        return $observerId;
    }

    /**
     * Unsubscribe observer with cleanup
     *
     * @param string $key State key
     * @param string $observerId Observer ID
     * @return bool Success status
     */
    public function unsubscribe(string $key, string $observerId): bool {
        if (!$this->validateUnsubscribe($key, $observerId)) {
            return false;
        }

        try {
            // Find and remove observer across priority levels
            foreach ($this->observers[$key] as $priority => $observers) {
                if (isset($observers[$observerId])) {
                    unset($this->observers[$key][$priority][$observerId]);
                    unset($this->observerMeta[$observerId]);
                    
                    // Clean up empty priority levels
                    if (empty($this->observers[$key][$priority])) {
                        unset($this->observers[$key][$priority]);
                    }
                    
                    // Remove key if no observers left
                    if (empty($this->observers[$key])) {
                        unset($this->observers[$key]);
                    }

                    $this->recordMetric('observer_unsubscribed', [
                        'key' => $key,
                        'id' => $observerId
                    ]);

                    return true;
                }
            }

            return false;

        } catch (\Exception $e) {
            $this->errorManager->logError('unsubscribe_failed', [
                'key' => $key,
                'id' => $observerId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Notify observers with priority processing and performance optimization
     *
     * @param string $key State key
     * @param mixed $oldValue Previous state value
     * @param mixed $newValue New state value
     */
    public function notifyObservers(string $key, $oldValue, $newValue): void {
        if (!isset($this->observers[$key])) {
            return;
        }

        $notification = [
            'key' => $key,
            'oldValue' => $oldValue,
            'newValue' => $newValue,
            'timestamp' => microtime(true),
            'transaction_id' => $this->currentTransaction
        ];

        // Add to batch queue if batching enabled
        if ($this->batchingEnabled) {
            $this->queueNotification($notification);
            return;
        }

        $this->processNotification($notification);
    }

    /**
     * Process notification with priority handling
     *
     * @param array $notification Notification data
     */
    private function processNotification(array $notification): void {
        $startTime = microtime(true);
        $key = $notification['key'];

        try {
            // Process observers by priority level
            foreach (self::PRIORITY_LEVELS as $level => $priority) {
                if (!isset($this->observers[$key][$priority])) {
                    continue;
                }

                foreach ($this->observers[$key][$priority] as $observerId => $callback) {
                    $this->executeObserverCallback(
                        $observerId,
                        $callback,
                        $notification
                    );
                }
            }

            $this->updatePerformanceMetrics($startTime);

        } catch (\Exception $e) {
            $this->errorManager->logError('notification_processing_failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Execute observer callback with performance tracking
     *
     * @param string $observerId Observer ID
     * @param callable $callback Observer callback
     * @param array $notification Notification data
     */
    private function executeObserverCallback(
        string $observerId,
        callable $callback,
        array $notification
    ): void {
        $startTime = microtime(true);

        try {
            $result = call_user_func($callback, $notification);
            $executionTime = microtime(true) - $startTime;

            // Update observer metadata
            $this->updateObserverMetadata($observerId, $executionTime);

            // Handle callback result if needed
            if ($result instanceof \Generator) {
                $this->processGeneratorResult($result, $observerId);
            }

        } catch (\Exception $e) {
            $this->handleObserverError($observerId, $e);
        }
    }

    /**
     * Enable notification batching
     *
     * @param bool $enable Enable/disable batching
     * @return bool Previous batching state
     */
    public function enableBatching(bool $enable = true): bool {
        $previous = $this->batchingEnabled;
        $this->batchingEnabled = $enable;

        // Process pending notifications if disabling
        if (!$enable && !empty($this->eventQueue)) {
            $this->processBatchQueue();
        }

        return $previous;
    }

    /**
     * Add middleware for state change processing
     *
     * @param callable $middleware Middleware callback
     * @param int $priority Middleware priority
     * @return string Middleware ID
     */
    public function addMiddleware(callable $middleware, int $priority = 100): string {
        $middlewareId = $this->generateMiddlewareId();
        
        $this->middleware[$middlewareId] = [
            'callback' => $middleware,
            'priority' => $priority
        ];

        // Sort middleware by priority
        uasort($this->middleware, function($a, $b) {
            return $a['priority'] <=> $b['priority'];
        });

        return $middlewareId;
    }

    /**
     * Remove middleware
     *
     * @param string $middlewareId Middleware ID
     * @return bool Success status
     */
    public function removeMiddleware(string $middlewareId): bool {
        if (!isset($this->middleware[$middlewareId])) {
            return false;
        }

        unset($this->middleware[$middlewareId]);
        return true;
    }

    /**
     * Process batch notification queue
     */
    private function processBatchQueue(): void {
        if (empty($this->eventQueue)) {
            return;
        }

        $batch = array_splice($this->eventQueue, 0, self::MAX_BATCH_SIZE);
        
        foreach ($batch as $notification) {
            $this->processNotification($notification);
        }

        $this->observerMetrics['batches_processed']++;
    }

    /**
     * Update observer performance metadata
     *
     * @param string $observerId Observer ID
     * @param float $executionTime Execution time in seconds
     */
    private function updateObserverMetadata(string $observerId, float $executionTime): void {
        $meta = &$this->observerMeta[$observerId];
        
        $meta['last_called'] = microtime(true);
        $meta['call_count']++;
        
        // Update moving average execution time
        $meta['average_execution_time'] = (
            ($meta['average_execution_time'] * ($meta['call_count'] - 1)) + $executionTime
        ) / $meta['call_count'];
    }

    /**
     * Handle observer error with cleanup
     *
     * @param string $observerId Observer ID
     * @param \Exception $error Error instance
     */
    private function handleObserverError(string $observerId, \Exception $error): void {
        $meta = &$this->observerMeta[$observerId];
        $meta['error_count']++;

        $this->errorManager->logError('observer_execution_failed', [
            'observer_id' => $observerId,
            'error_count' => $meta['error_count'],
            'error' => $error->getMessage()
        ]);

        // Remove observer if error threshold exceeded
        if ($meta['error_count'] > ($this->config['max_observer_errors'] ?? 5)) {
            $this->removeFailedObserver($observerId);
        }
    }

    /**
     * Clean up disconnected observers
     *
     * @return int Number of observers cleaned
     */
    public function pruneObservers(): int {
        $cleaned = 0;
        $threshold = microtime(true) - ($this->config['observer_timeout'] ?? 3600);

        foreach ($this->observers as $key => $priorities) {
            foreach ($priorities as $priority => $observers) {
                foreach ($observers as $observerId => $callback) {
                    if (!is_callable($callback) || 
                        ($this->observerMeta[$observerId]['last_called'] < $threshold)) {
                        $this->unsubscribe($key, $observerId);
                        $cleaned++;
                    }
                }
            }
        }

        $this->observerMetrics['observers_cleaned'] += $cleaned;
        return $cleaned;
    }

    /**
     * Generate unique observer ID
     *
     * @return string Observer ID
     */
    private function generateObserverId(): string {
        return generateSecureId('obs_');
    }
    
    /**
     * Generate unique middleware ID
     *
     * @return string Middleware ID
     */
    private function generateMiddlewareId(): string {
        return generateSecureId('mid_');
    }

    private function generateSecureId(string $prefix = ''): string {
        try {
            // Generate 16 bytes of random data
            $randomBytes = random_bytes(16);
            
            // Create microsecond timestamp
            $timestamp = microtime(true);
            
            // Combine and hash components
            $data = $prefix . $timestamp . bin2hex($randomBytes);
            $hash = hash('sha256', $data);
            
            // Take first 32 chars for reasonable length
            return substr($prefix . $hash, 0, 32);
        } catch (\Exception $e) {
            // Fallback with less entropy if random_bytes fails
            return $prefix . uniqid() . substr(str_shuffle(MD5(microtime())), 0, 16);
        }
    }

    /**
     * Validate state key
     *
     * @param string $key State key
     * @throws \InvalidArgumentException
     */
    private function validateKey(string $key): void {
        if (empty($key)) {
            throw new \InvalidArgumentException('State key cannot be empty');
        }
    }

    /**
     * Validate observer callback
     *
     * @param callable $callback Observer callback
     * @throws \InvalidArgumentException
     */
    private function validateCallback(callable $callback): void {
        if (!is_callable($callback)) {
            throw new \InvalidArgumentException('Observer callback must be callable');
        }
    }

    /**
     * Validate unsubscribe parameters
     *
     * @param string $key State key
     * @param string $observerId Observer ID
     * @return bool Validation status
     */
    private function validateUnsubscribe(string $key, string $observerId): bool {
        return isset($this->observers[$key]) && isset($this->observerMeta[$observerId]);
    }

    /**
     * Get observer statistics
     *
     * @return array Observer statistics
     */
    public function getObserverStats(): array {
        return [
            'total_observers' => array_sum(array_map(
                fn($priorities) => array_sum(array_map('count', $priorities)),
                $this->observers
            )),
            'active_keys' => count($this->observers),
            'middleware_count' => count($this->middleware),
            'metrics' => $this->observerMetrics,
            'batch_queue_size' => count($this->eventQueue)
        ];
    }
}