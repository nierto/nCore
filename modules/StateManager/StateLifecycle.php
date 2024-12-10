<?php

namespace nCore\StateManager\Traits;

/**
 * Advanced State Lifecycle Management
 * 
 * Extends basic ModuleInterface lifecycle with sophisticated state-specific
 * lifecycle management including pause/resume functionality, graceful shutdown,
 * and comprehensive status tracking.
 */
trait StateLifecycle {
    /** @var string Current lifecycle status */
    private $lifecycleStatus = 'inactive';

    /** @var array Lifecycle event timestamps */
    private $lifecycleEvents = [];

    /** @var array Suspended state storage */
    private $suspendedState = [];

    /** @var bool Lifecycle lock to prevent concurrent transitions */
    private $lifecycleLock = false;

    /** @var array Registered shutdown tasks */
    private $shutdownTasks = [];

    /**
     * Enhanced initialization with state-specific setup
     * 
     * @param array $config Configuration options
     * @throws \RuntimeException If initialization fails
     */
    public function initialize(array $config = []): void {
        if ($this->lifecycleLock) {
            throw new \RuntimeException('Lifecycle transition already in progress');
        }

        try {
            $this->lifecycleLock = true;
            $startTime = microtime(true);

            // Call parent initialization if not already done
            if (!$this->initialized && method_exists(get_parent_class($this), 'initialize')) {
                parent::initialize($config);
            }

            // State-specific initialization
            $this->lifecycleEvents['init_start'] = $startTime;
            $this->lifecycleStatus = 'initializing';

            // Register shutdown handler
            register_shutdown_function([$this, 'handleShutdown']);

            // Initialize state monitoring
            $this->initializeStateMonitoring();

            // Set up automatic state persistence if configured
            if ($config['auto_persist'] ?? false) {
                $this->setupAutoPersistence();
            }

            // Register cleanup handler
            if ($config['cleanup_on_shutdown'] ?? true) {
                $this->registerShutdownTask('cleanup', [$this, 'cleanup']);
            }

            $this->lifecycleStatus = 'active';
            $this->lifecycleEvents['init_complete'] = microtime(true);

            // Record initialization metrics
            $this->recordMetric('lifecycle', 'initialization', [
                'duration' => $this->lifecycleEvents['init_complete'] - $startTime,
                'config' => $config
            ]);

        } catch (\Exception $e) {
            $this->lifecycleStatus = 'failed';
            $this->lifecycleEvents['init_failed'] = microtime(true);
            
            if ($this->errorManager) {
                $this->errorManager->logError('lifecycle_init_failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
            
            throw new \RuntimeException('Lifecycle initialization failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->lifecycleLock = false;
        }
    }

    /**
     * Gracefully destroy state management system
     * 
     * @throws \RuntimeException If destruction fails
     */
    public function destroy(): void {
        if ($this->lifecycleLock) {
            throw new \RuntimeException('Lifecycle transition already in progress');
        }

        try {
            $this->lifecycleLock = true;
            $startTime = microtime(true);
            
            $this->lifecycleStatus = 'destroying';
            $this->lifecycleEvents['destroy_start'] = $startTime;

            // Execute registered shutdown tasks
            foreach ($this->shutdownTasks as $task) {
                try {
                    call_user_func($task['callback']);
                } catch (\Exception $e) {
                    $this->errorManager?->logError('shutdown_task_failed', [
                        'task' => $task['name'],
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Persist final state if configured
            if ($this->config['persist_on_destroy'] ?? true) {
                $this->persistState();
            }

            // Clear internal state
            $this->state = [];
            $this->observers = [];
            $this->history = [];
            $this->metrics = [];

            $this->lifecycleStatus = 'destroyed';
            $this->lifecycleEvents['destroy_complete'] = microtime(true);

            // Record final metrics
            $this->recordMetric('lifecycle', 'destruction', [
                'duration' => $this->lifecycleEvents['destroy_complete'] - $startTime
            ]);

        } catch (\Exception $e) {
            $this->lifecycleStatus = 'failed';
            $this->lifecycleEvents['destroy_failed'] = microtime(true);
            throw new \RuntimeException('Lifecycle destruction failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->lifecycleLock = false;
        }
    }

    /**
     * Pause state management operations
     * 
     * @throws \RuntimeException If pause fails
     */
    public function pause(): void {
        if ($this->lifecycleLock || $this->lifecycleStatus !== 'active') {
            throw new \RuntimeException('Cannot pause: invalid state');
        }

        try {
            $this->lifecycleLock = true;
            $startTime = microtime(true);

            // Store current state
            $this->suspendedState = [
                'state' => $this->state,
                'observers' => $this->observers,
                'metrics' => $this->metrics,
                'timestamp' => $startTime
            ];

            // Clear active state
            $this->state = [];
            $this->observers = [];

            $this->lifecycleStatus = 'paused';
            $this->lifecycleEvents['pause'] = $startTime;

            $this->recordMetric('lifecycle', 'pause', [
                'timestamp' => $startTime
            ]);

        } catch (\Exception $e) {
            $this->lifecycleStatus = 'failed';
            throw new \RuntimeException('Failed to pause: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->lifecycleLock = false;
        }
    }

    /**
     * Resume state management operations
     * 
     * @throws \RuntimeException If resume fails
     */
    public function resume(): void {
        if ($this->lifecycleLock || $this->lifecycleStatus !== 'paused') {
            throw new \RuntimeException('Cannot resume: invalid state');
        }

        try {
            $this->lifecycleLock = true;
            $startTime = microtime(true);

            // Restore suspended state
            $this->state = $this->suspendedState['state'] ?? [];
            $this->observers = $this->suspendedState['observers'] ?? [];
            $this->metrics = $this->suspendedState['metrics'] ?? [];

            // Clear suspended state
            $this->suspendedState = [];

            $this->lifecycleStatus = 'active';
            $this->lifecycleEvents['resume'] = $startTime;

            $this->recordMetric('lifecycle', 'resume', [
                'duration' => microtime(true) - $startTime
            ]);

        } catch (\Exception $e) {
            $this->lifecycleStatus = 'failed';
            throw new \RuntimeException('Failed to resume: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->lifecycleLock = false;
        }
    }

    /**
     * Get comprehensive lifecycle status
     * 
     * @return string Current lifecycle status
     */
    public function getStatus(): string {
        return $this->lifecycleStatus;
    }

    /**
     * Get detailed lifecycle status information
     * 
     * @return array Detailed status information
     */
    public function getLifecycleStatus(): array {
        return [
            'status' => $this->lifecycleStatus,
            'events' => $this->lifecycleEvents,
            'metrics' => [
                'state_count' => count($this->state),
                'observer_count' => count($this->observers),
                'history_depth' => count($this->history),
                'uptime' => $this->lifecycleEvents['init_complete'] 
                    ? microtime(true) - $this->lifecycleEvents['init_complete']
                    : 0
            ],
            'memory' => [
                'current' => memory_get_usage(true),
                'peak' => memory_get_peak_usage(true)
            ],
            'suspended' => !empty($this->suspendedState),
            'lock_status' => $this->lifecycleLock
        ];
    }

    /**
     * Register shutdown task
     * 
     * @param string $name Task identifier
     * @param callable $callback Task callback
     */
    protected function registerShutdownTask(string $name, callable $callback): void {
        $this->shutdownTasks[$name] = [
            'name' => $name,
            'callback' => $callback,
            'registered_at' => microtime(true)
        ];
    }

    /**
     * Initialize state monitoring
     */
    private function initializeStateMonitoring(): void {
        if ($this->config['monitoring_enabled'] ?? true) {
            // Monitor memory usage
            $this->registerShutdownTask('memory_check', function() {
                if (memory_get_peak_usage(true) > ($this->config['memory_threshold'] ?? 67108864)) {
                    $this->errorManager?->logWarning('high_memory_usage', [
                        'peak_memory' => memory_get_peak_usage(true)
                    ]);
                }
            });

            // Monitor state size
            $this->registerShutdownTask('state_size_check', function() {
                if (count($this->state) > ($this->config['state_size_threshold'] ?? 1000)) {
                    $this->errorManager?->logWarning('large_state_size', [
                        'state_count' => count($this->state)
                    ]);
                }
            });
        }
    }

    /**
     * Handle emergency shutdown
     */
    private function handleShutdown(): void {
        if ($error = error_get_last()) {
            $this->errorManager?->logError('fatal_shutdown', [
                'error' => $error,
                'state_status' => $this->getLifecycleStatus()
            ]);
        }

        // Attempt to save state if configured
        if ($this->config['persist_on_shutdown'] ?? true) {
            try {
                $this->persistState();
            } catch (\Exception $e) {
                $this->errorManager?->logError('shutdown_persist_failed', [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
}