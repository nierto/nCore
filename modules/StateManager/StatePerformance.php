<?php

namespace nCore\StateManager\Traits;

/**
 * Advanced Performance Management Trait for StateManager
 * 
 * Provides comprehensive performance optimization, monitoring, and metrics
 * collection for the state management system. Implements sophisticated
 * batching, compression, and structure optimization techniques.
 * 
 * Features:
 * - Batch operation optimization
 * - Memory usage optimization
 * - Performance metrics collection
 * - Real-time monitoring
 * - Automatic optimization triggers
 * 
 * @package     nCore\StateManager
 * @subpackage  Traits
 * @version     2.0.0
 */
trait StatePerformance {
    /** @var array Performance thresholds */
    private $performanceThresholds = [
        'memory_limit' => 67108864, // 64MB
        'batch_size' => 100,
        'history_compression' => 1000,
        'observer_cleanup' => 50,
        'structure_optimization' => 500
    ];

    /** @var array Performance metrics storage */
    private $performanceMetrics = [
        'operations' => [
            'total' => 0,
            'batched' => 0,
            'optimizations' => 0,
            'compressions' => 0
        ],
        'memory' => [
            'peak' => 0,
            'current' => 0,
            'optimized' => 0
        ],
        'timing' => [
            'total_time' => 0,
            'batch_time' => 0,
            'optimization_time' => 0
        ],
        'batch_stats' => [
            'total_batches' => 0,
            'average_size' => 0,
            'success_rate' => 100
        ]
    ];

    /** @var array Operation timings */
    private $operationTimings = [];

    /** @var int Last optimization timestamp */
    private $lastOptimization = 0;

    /**
     * Execute multiple state updates in an optimized batch
     *
     * @param callable $updates Function containing state updates
     * @throws \RuntimeException If batch operation fails
     */
    public function batchUpdates(callable $updates): void {
        if (!$this->initialized) {
            throw new \RuntimeException('StateManager not initialized');
        }

        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        $batchCount = 0;
        $successCount = 0;

        try {
            // Start transaction for atomic batch
            $transactionId = $this->beginTransaction();
            
            // Disable notifications during batch
            $this->suspendNotifications();
            
            // Create batch context
            $batchContext = new class($this) {
                private $manager;
                private $updates = [];
                
                public function __construct($manager) {
                    $this->manager = $manager;
                }
                
                public function setState($key, $value): void {
                    $this->updates[$key] = $value;
                }
                
                public function getUpdates(): array {
                    return $this->updates;
                }
            };

            // Execute updates in batch context
            call_user_func($updates, $batchContext);
            $updates = $batchContext->getUpdates();
            $batchCount = count($updates);

            // Process updates in optimized chunks
            foreach (array_chunk($updates, $this->performanceThresholds['batch_size'], true) as $chunk) {
                foreach ($chunk as $key => $value) {
                    try {
                        $this->setState($key, $value, ['track_metrics' => false]);
                        $successCount++;
                    } catch (\Exception $e) {
                        $this->errorManager->logError('batch_update_failed', [
                            'key' => $key,
                            'error' => $e->getMessage()
                        ]);
                    }
                }

                // Allow other operations between chunks
                if ($this->exceedsMemoryThreshold()) {
                    $this->optimizeStateStructure();
                }
            }

            // Commit transaction
            $this->commitTransaction($transactionId);
            
            // Resume and batch notify observers
            $this->resumeNotifications();
            $this->batchNotifyObservers($updates);

            // Update performance metrics
            $this->updateBatchMetrics([
                'duration' => microtime(true) - $startTime,
                'memory_delta' => memory_get_usage(true) - $startMemory,
                'total_updates' => $batchCount,
                'successful_updates' => $successCount
            ]);

        } catch (\Exception $e) {
            $this->rollbackTransaction($transactionId);
            $this->resumeNotifications();
            throw new \RuntimeException('Batch operation failed: ' . $e->getMessage());
        }
    }

    /**
     * Optimize state structure for improved performance
     */
    public function optimizeStateStructure(): void {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        try {
            // Prevent concurrent optimizations
            if (time() - $this->lastOptimization < 60) {
                return;
            }

            $this->lastOptimization = time();

            // Optimize state array structure
            $optimizedState = [];
            foreach ($this->state as $key => $value) {
                if ($value !== null) {
                    $optimizedState[$key] = $this->optimizeValue($value);
                }
            }
            $this->state = $optimizedState;

            // Clean up empty histories
            foreach ($this->history as $key => $history) {
                if (empty($history['stack'])) {
                    unset($this->history[$key]);
                }
            }

            // Optimize observer structure
            $this->pruneObservers();

            // Update performance metrics
            $this->performanceMetrics['operations']['optimizations']++;
            $this->performanceMetrics['memory']['optimized'] += 
                $startMemory - memory_get_usage(true);
            
            $this->performanceMetrics['timing']['optimization_time'] += 
                microtime(true) - $startTime;

        } catch (\Exception $e) {
            $this->errorManager->logError('optimization_failed', [
                'error' => $e->getMessage(),
                'memory_usage' => memory_get_usage(true)
            ]);
        }
    }

    /**
     * Compress history entries to reduce memory usage
     */
    public function compressHistory(): void {
        $startTime = microtime(true);
        
        try {
            foreach ($this->history as $key => &$history) {
                if (count($history['stack']) > $this->performanceThresholds['history_compression']) {
                    // Keep important points in history
                    $significant_points = array_filter($history['stack'], function($entry) {
                        return isset($entry['significant']) && $entry['significant'];
                    });

                    // Retain regular interval snapshots
                    $interval = max(1, floor(count($history['stack']) / 100));
                    for ($i = 0; $i < count($history['stack']); $i += $interval) {
                        $significant_points[] = $history['stack'][$i];
                    }

                    // Always keep latest state
                    $significant_points[] = end($history['stack']);

                    // Update history stack
                    $history['stack'] = array_values($significant_points);
                    $history['position'] = count($history['stack']) - 1;
                    $history['max_position'] = $history['position'];
                }
            }

            $this->performanceMetrics['operations']['compressions']++;
            $this->performanceMetrics['timing']['total_time'] += microtime(true) - $startTime;

        } catch (\Exception $e) {
            $this->errorManager->logError('history_compression_failed', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Remove disconnected observers and optimize observer structure
     */
    public function pruneObservers(): void {
        $startTime = microtime(true);
        $initialCount = 0;
        $finalCount = 0;

        try {
            foreach ($this->observers as $key => $observers) {
                $initialCount += count($observers);
                
                // Remove invalid callbacks
                $this->observers[$key] = array_filter($observers, function($callback) {
                    return is_callable($callback);
                });

                // Remove empty observer arrays
                if (empty($this->observers[$key])) {
                    unset($this->observers[$key]);
                } else {
                    $finalCount += count($this->observers[$key]);
                }
            }

            // Update metrics
            $this->performanceMetrics['operations']['optimizations']++;
            $this->performanceMetrics['timing']['total_time'] += microtime(true) - $startTime;

            $this->errorManager->logDebug('observers_pruned', [
                'removed' => $initialCount - $finalCount,
                'remaining' => $finalCount
            ]);

        } catch (\Exception $e) {
            $this->errorManager->logError('observer_pruning_failed', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get comprehensive performance metrics
     *
     * @return array Performance metrics and statistics
     */
    public function getPerformanceMetrics(): array {
        // Update current metrics
        $this->performanceMetrics['memory']['current'] = memory_get_usage(true);
        $this->performanceMetrics['memory']['peak'] = memory_get_peak_usage(true);

        // Calculate averages and rates
        $metrics = $this->performanceMetrics;
        
        // Add derived metrics
        $metrics['derived'] = [
            'operations_per_second' => $this->calculateOperationRate(),
            'memory_efficiency' => $this->calculateMemoryEfficiency(),
            'optimization_effectiveness' => $this->calculateOptimizationEffectiveness(),
            'response_times' => $this->calculateResponseTimes()
        ];

        // Add system metrics
        $metrics['system'] = [
            'load_average' => sys_getloadavg(),
            'memory_limit' => ini_get('memory_limit'),
            'time_limit' => ini_get('max_execution_time')
        ];

        return $metrics;
    }

    /**
     * Helper method to calculate operation rate
     */
    private function calculateOperationRate(): float {
        $duration = microtime(true) - $this->operationTimings[0] ?? 0;
        if ($duration === 0) {
            return 0.0;
        }
        return $this->performanceMetrics['operations']['total'] / $duration;
    }

    /**
     * Helper method to calculate memory efficiency
     */
    private function calculateMemoryEfficiency(): float {
        $idealMemory = count($this->state) * 100; // Baseline memory estimation
        $actualMemory = memory_get_usage(true);
        return ($idealMemory / $actualMemory) * 100;
    }

    /**
     * Helper method to calculate optimization effectiveness
     */
    private function calculateOptimizationEffectiveness(): float {
        if ($this->performanceMetrics['memory']['current'] === 0) {
            return 0.0;
        }
        return ($this->performanceMetrics['memory']['optimized'] / 
                $this->performanceMetrics['memory']['current']) * 100;
    }

    /**
     * Helper method to calculate response time statistics
     */
    private function calculateResponseTimes(): array {
        if (empty($this->operationTimings)) {
            return ['min' => 0, 'max' => 0, 'avg' => 0];
        }

        $times = array_values($this->operationTimings);
        return [
            'min' => min($times),
            'max' => max($times),
            'avg' => array_sum($times) / count($times)
        ];
    }

    /**
     * Check if memory usage exceeds threshold
     */
    private function exceedsMemoryThreshold(): bool {
        return memory_get_usage(true) > $this->performanceThresholds['memory_limit'];
    }

    /**
     * Optimize individual value storage
     */
    private function optimizeValue($value) {
        if (is_array($value)) {
            return array_filter($value, function($v) {
                return $v !== null;
            });
        }
        return $value;
    }

    /**
     * Update batch operation metrics
     */
    private function updateBatchMetrics(array $metrics): void {
        $this->performanceMetrics['operations']['batched']++;
        $this->performanceMetrics['timing']['batch_time'] += $metrics['duration'];
        $this->performanceMetrics['batch_stats']['total_batches']++;
        
        // Update running averages
        $totalBatches = $this->performanceMetrics['batch_stats']['total_batches'];
        $this->performanceMetrics['batch_stats']['average_size'] = 
            (($totalBatches - 1) * $this->performanceMetrics['batch_stats']['average_size'] + 
             $metrics['total_updates']) / $totalBatches;
        
        $this->performanceMetrics['batch_stats']['success_rate'] = 
            (($totalBatches - 1) * $this->performanceMetrics['batch_stats']['success_rate'] + 
             ($metrics['successful_updates'] / $metrics['total_updates'] * 100)) / $totalBatches;
    }
}