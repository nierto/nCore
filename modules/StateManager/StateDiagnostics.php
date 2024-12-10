<?php

namespace nCore\StateManager\Traits;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Advanced State Diagnostics System
 * 
 * Provides comprehensive debugging, tracing, and diagnostic capabilities for the StateManager.
 * Features include:
 * - Real-time performance monitoring
 * - Memory profiling
 * - Operation tracing
 * - State change history
 * - Advanced logging integration
 * 
 * @package     nCore\StateManager
 * @subpackage  Diagnostics
 * @version     2.0.0
 */
trait StateDiagnostics {
    /** @var array<LoggerInterface> Registered loggers */
    private $loggers = [];

    /** @var array Performance metrics storage */
    private $diagnosticMetrics = [
        'operations' => [],
        'memory' => [
            'baseline' => 0,
            'peak' => 0,
            'current' => 0
        ],
        'timing' => [
            'start_time' => 0,
            'operations' => []
        ],
        'state_changes' => []
    ];

    /** @var array Debug mode configuration */
    private $debugConfig = [
        'trace_depth' => 50,
        'memory_tracking' => true,
        'performance_tracking' => true,
        'operation_tracing' => true,
        'log_level' => LogLevel::DEBUG
    ];

    /**
     * Get comprehensive debug snapshot
     * 
     * Captures current state, performance metrics, memory usage, and system health
     * 
     * @return array Debug information snapshot
     */
    public function getDebugSnapshot(): array {
        $currentMemory = memory_get_usage(true);
        $peakMemory = memory_get_peak_usage(true);
        
        // Capture system state
        $snapshot = [
            'timestamp' => microtime(true),
            'state' => [
                'count' => count($this->state),
                'keys' => array_keys($this->state),
                'observers' => $this->getObserverStats(),
                'transactions' => $this->getTransactionStats()
            ],
            'performance' => [
                'uptime' => microtime(true) - $this->diagnosticMetrics['timing']['start_time'],
                'operation_count' => count($this->diagnosticMetrics['operations']),
                'average_operation_time' => $this->calculateAverageOperationTime(),
                'peak_operation_time' => $this->getPeakOperationTime()
            ],
            'memory' => [
                'current' => $this->formatBytes($currentMemory),
                'peak' => $this->formatBytes($peakMemory),
                'baseline' => $this->formatBytes($this->diagnosticMetrics['memory']['baseline']),
                'delta' => $this->formatBytes($currentMemory - $this->diagnosticMetrics['memory']['baseline'])
            ],
            'health' => [
                'status' => $this->getSystemHealth(),
                'warnings' => $this->getSystemWarnings(),
                'error_rate' => $this->calculateErrorRate()
            ],
            'system' => [
                'php_version' => PHP_VERSION,
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'opcache_enabled' => function_exists('opcache_get_status') && opcache_get_status() !== false
            ]
        ];

        // Log snapshot creation if debugging enabled
        if ($this->debugConfig['operation_tracing']) {
            $this->logDebugOperation('snapshot_created', $snapshot);
        }

        return $snapshot;
    }

    /**
     * Get detailed metadata for specific state key
     * 
     * @param string $key State key to analyze
     * @return array Detailed state metadata
     */
    public function getStateMetadata(string $key): array {
        if (!isset($this->state[$key])) {
            return ['exists' => false];
        }

        $value = $this->state[$key];
        $type = gettype($value);
        $size = $this->calculateStateSize($value);

        $metadata = [
            'exists' => true,
            'type' => $type,
            'size' => $this->formatBytes($size),
            'created_at' => $this->getStateCreationTime($key),
            'last_modified' => $this->getLastModificationTime($key),
            'modification_count' => $this->getModificationCount($key),
            'observers' => count($this->observers[$key] ?? []),
            'history_entries' => count($this->history[$key]['stack'] ?? []),
            'serializable' => $this->isSerializable($value),
            'references' => $this->findStateReferences($key),
            'validation_rules' => $this->getValidationRules($key),
            'dependencies' => $this->getStateDependencies($key)
        ];

        // Add type-specific metadata
        switch ($type) {
            case 'object':
                $metadata['object_info'] = [
                    'class' => get_class($value),
                    'methods' => get_class_methods($value),
                    'properties' => get_object_vars($value),
                    'interfaces' => class_implements($value)
                ];
                break;
            case 'array':
                $metadata['array_info'] = [
                    'count' => count($value),
                    'depth' => $this->calculateArrayDepth($value),
                    'is_associative' => $this->isAssociativeArray($value)
                ];
                break;
        }

        return $metadata;
    }

    /**
     * Trace state changes for specific key
     * 
     * @param string $key State key to trace
     * @return array Chronological trace of state changes
     */
    public function traceStateChanges(string $key): array {
        if (!isset($this->diagnosticMetrics['state_changes'][$key])) {
            return [];
        }

        $trace = [
            'key' => $key,
            'changes' => array_map(function($change) {
                return [
                    'timestamp' => $change['timestamp'],
                    'old_value' => $this->sanitizeValueForLog($change['old_value']),
                    'new_value' => $this->sanitizeValueForLog($change['new_value']),
                    'transaction_id' => $change['transaction_id'] ?? null,
                    'trigger' => $change['trigger'] ?? 'unknown',
                    'stack_trace' => $change['stack_trace'] ?? [],
                    'memory_impact' => $change['memory_impact'] ?? null
                ];
            }, $this->diagnosticMetrics['state_changes'][$key]),
            'statistics' => [
                'total_changes' => count($this->diagnosticMetrics['state_changes'][$key]),
                'first_change' => reset($this->diagnosticMetrics['state_changes'][$key])['timestamp'] ?? null,
                'last_change' => end($this->diagnosticMetrics['state_changes'][$key])['timestamp'] ?? null,
                'average_frequency' => $this->calculateChangeFrequency($key)
            ]
        ];

        // Add performance impact analysis
        $trace['performance_impact'] = $this->analyzePerformanceImpact($key);

        return $trace;
    }

    /**
     * Get comprehensive system diagnostics
     * 
     * @return array Complete system diagnostic information
     */
    public function getDiagnostics(): array {
        $startTime = microtime(true);

        $diagnostics = [
            'timestamp' => date('Y-m-d H:i:s'),
            'runtime' => [
                'memory' => $this->getMemoryDiagnostics(),
                'performance' => $this->getPerformanceDiagnostics(),
                'errors' => $this->getErrorDiagnostics()
            ],
            'state' => [
                'size' => $this->getTotalStateSize(),
                'complexity' => $this->analyzeStateComplexity(),
                'health_score' => $this->calculateStateHealthScore(),
                'optimization_suggestions' => $this->generateOptimizationSuggestions()
            ],
            'operations' => [
                'throughput' => $this->calculateOperationThroughput(),
                'patterns' => $this->analyzeOperationPatterns(),
                'bottlenecks' => $this->identifyBottlenecks()
            ],
            'system_impact' => [
                'cpu_usage' => $this->measureCpuUsage(),
                'memory_efficiency' => $this->analyzeMemoryEfficiency(),
                'io_operations' => $this->trackIOOperations()
            ]
        ];

        // Add execution time of diagnostics
        $diagnostics['meta'] = [
            'diagnostic_time' => microtime(true) - $startTime,
            'diagnostic_memory' => memory_get_usage(true) - $this->diagnosticMetrics['memory']['current']
        ];

        return $diagnostics;
    }

    /**
     * Register PSR-3 compatible logger
     * 
     * @param LoggerInterface $logger Logger implementation
     * @param array $options Logger configuration options
     */
    public function registerLogger(LoggerInterface $logger, array $options = []): void {
        $loggerId = spl_object_hash($logger);
        
        $this->loggers[$loggerId] = [
            'instance' => $logger,
            'options' => array_merge([
                'level' => $this->debugConfig['log_level'],
                'context_depth' => 3,
                'include_trace' => true
            ], $options)
        ];

        // Test logger
        try {
            $logger->debug('Logger registered with StateDiagnostics', [
                'id' => $loggerId,
                'options' => $options
            ]);
        } catch (\Exception $e) {
            // Remove logger if test fails
            unset($this->loggers[$loggerId]);
            throw new \RuntimeException('Logger registration failed: ' . $e->getMessage());
        }
    }

    /**
     * Calculate total state size
     * 
     * @return int Total size in bytes
     */
    private function getTotalStateSize(): int {
        $size = 0;
        foreach ($this->state as $value) {
            $size += $this->calculateStateSize($value);
        }
        return $size;
    }

    /**
     * Calculate size of state value
     * 
     * @param mixed $value Value to measure
     * @return int Size in bytes
     */
    private function calculateStateSize($value): int {
        return strlen(serialize($value));
    }

    /**
     * Format bytes to human readable string
     * 
     * @param int $bytes Bytes to format
     * @return string Formatted string
     */
    private function formatBytes(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        return round($bytes / (1024 ** $pow), 2) . ' ' . $units[$pow];
    }

    /**
     * Calculate state health score
     * 
     * @return float Health score between 0 and 100
     */
    private function calculateStateHealthScore(): float {
        $metrics = [
            'memory_usage' => $this->getMemoryHealthScore(),
            'error_rate' => $this->getErrorHealthScore(),
            'performance' => $this->getPerformanceHealthScore(),
            'complexity' => $this->getComplexityHealthScore()
        ];

        return array_sum($metrics) / count($metrics);
    }

    /**
     * Generate optimization suggestions
     * 
     * @return array List of optimization suggestions
     */
    private function generateOptimizationSuggestions(): array {
        $suggestions = [];

        // Memory optimizations
        if ($this->diagnosticMetrics['memory']['current'] > $this->diagnosticMetrics['memory']['baseline'] * 2) {
            $suggestions[] = [
                'type' => 'memory',
                'priority' => 'high',
                'message' => 'Consider implementing state cleanup for unused keys',
                'impact' => 'Reduce memory usage by removing stale state data'
            ];
        }

        // Performance optimizations
        $avgOperationTime = $this->calculateAverageOperationTime();
        if ($avgOperationTime > 0.1) { // 100ms threshold
            $suggestions[] = [
                'type' => 'performance',
                'priority' => 'medium',
                'message' => 'Consider batch operations for frequent state changes',
                'impact' => 'Improve operation throughput and reduce overhead'
            ];
        }

        return $suggestions;
    }

    /**
     * Log debug operation
     * 
     * @param string $operation Operation name
     * @param array $context Operation context
     */
    private function logDebugOperation(string $operation, array $context = []): void {
        foreach ($this->loggers as $logger) {
            try {
                $logger['instance']->debug($operation, array_merge($context, [
                    'timestamp' => microtime(true),
                    'memory' => memory_get_usage(true)
                ]));
            } catch (\Exception $e) {
                // Silently fail if logging fails
            }
        }
    }

    /**
     * Calculate array depth
     * 
     * @param array $array Array to analyze
     * @return int Maximum depth
     */
    private function calculateArrayDepth(array $array): int {
        $maxDepth = 1;
        foreach ($array as $value) {
            if (is_array($value)) {
                $depth = $this->calculateArrayDepth($value) + 1;
                $maxDepth = max($maxDepth, $depth);
            }
        }
        return $maxDepth;
    }

    /**
     * Check if array is associative
     * 
     * @param array $array Array to check
     * @return bool True if associative
     */
    private function isAssociativeArray(array $array): bool {
        if (empty($array)) return false;
        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * Analyze state complexity
     * 
     * @return array Complexity metrics
     */
    private function analyzeStateComplexity(): array {
        $complexity = [
            'total_keys' => count($this->state),
            'nested_structures' => 0,
            'circular_references' => 0,
            'deep_nesting' => 0
        ];

        foreach ($this->state as $key => $value) {
            if (is_array($value)) {
                $depth = $this->calculateArrayDepth($value);
                if ($depth > 3) {
                    $complexity['deep_nesting']++;
                }
                $complexity['nested_structures']++;
            }
        }

        return $complexity;
    }

    /**
     * Analyze memory efficiency
     * 
     * @return array Memory efficiency metrics
     */
    private function analyzeMemoryEfficiency(): array {
        $baseline = $this->diagnosticMetrics['memory']['baseline'];
        $current = memory_get_usage(true);
        $peak = memory_get_peak_usage(true);

        return [
            'efficiency_score' => ($baseline / $current) * 100,
            'memory_growth' => $current - $baseline,
            'peak_efficiency' => ($current / $peak) * 100,
            'recommended_limit' => $baseline * 2,
            'optimization_potential' => [
                'reducible_memory' => $current - $baseline,
                'savings_percentage' => (($current - $baseline) / $current) * 100
            ],
            'warnings' => [
                'excessive_growth' => ($current > $baseline * 2),
                'approaching_limit' => ($current > ini_get('memory_limit') * 0.8),
                'inefficient_usage' => ($peak > $current * 1.5)
            ]
        ];
    }

    /**
     * Measure CPU usage for state operations
     * 
     * @return array CPU usage metrics
     */
    private function measureCpuUsage(): array {
        if (!function_exists('getrusage')) {
            return ['supported' => false];
        }

        $usage = getrusage();
        return [
            'supported' => true,
            'user_time' => $usage['ru_utime.tv_sec'] + ($usage['ru_utime.tv_usec'] / 1000000),
            'system_time' => $usage['ru_stime.tv_sec'] + ($usage['ru_stime.tv_usec'] / 1000000),
            'memory_faults' => [
                'minor' => $usage['ru_minflt'],
                'major' => $usage['ru_majflt']
            ],
            'context_switches' => [
                'voluntary' => $usage['ru_nvcsw'],
                'involuntary' => $usage['ru_nivcsw']
            ]
        ];
    }

    /**
     * Track I/O operations
     * 
     * @return array I/O operation metrics
     */
    private function trackIOOperations(): array {
        return [
            'cache' => [
                'reads' => $this->diagnosticMetrics['operations']['cache_reads'] ?? 0,
                'writes' => $this->diagnosticMetrics['operations']['cache_writes'] ?? 0,
                'hits' => $this->diagnosticMetrics['operations']['cache_hits'] ?? 0,
                'misses' => $this->diagnosticMetrics['operations']['cache_misses'] ?? 0
            ],
            'persistence' => [
                'saves' => $this->diagnosticMetrics['operations']['state_saves'] ?? 0,
                'loads' => $this->diagnosticMetrics['operations']['state_loads'] ?? 0,
                'failures' => $this->diagnosticMetrics['operations']['persistence_failures'] ?? 0
            ],
            'efficiency' => [
                'cache_hit_ratio' => $this->calculateCacheHitRatio(),
                'persistence_success_rate' => $this->calculatePersistenceSuccessRate()
            ]
        ];
    }

    /**
     * Calculate cache hit ratio
     * 
     * @return float Cache hit ratio percentage
     */
    private function calculateCacheHitRatio(): float {
        $hits = $this->diagnosticMetrics['operations']['cache_hits'] ?? 0;
        $total = $hits + ($this->diagnosticMetrics['operations']['cache_misses'] ?? 0);
        
        return $total > 0 ? ($hits / $total) * 100 : 0;
    }

    /**
     * Calculate persistence success rate
     * 
     * @return float Persistence success rate percentage
     */
    private function calculatePersistenceSuccessRate(): float {
        $saves = $this->diagnosticMetrics['operations']['state_saves'] ?? 0;
        $failures = $this->diagnosticMetrics['operations']['persistence_failures'] ?? 0;
        
        return $saves > 0 ? (($saves - $failures) / $saves) * 100 : 0;
    }

    /**
     * Identify performance bottlenecks
     * 
     * @return array Identified bottlenecks with recommendations
     */
    private function identifyBottlenecks(): array {
        $bottlenecks = [];
        
        // Memory bottlenecks
        if ($this->analyzeMemoryEfficiency()['efficiency_score'] < 70) {
            $bottlenecks[] = [
                'type' => 'memory',
                'severity' => 'high',
                'description' => 'Memory usage efficiency is below optimal levels',
                'recommendation' => 'Consider implementing memory cleanup or garbage collection',
                'metrics' => $this->analyzeMemoryEfficiency()
            ];
        }

        // Cache bottlenecks
        if ($this->calculateCacheHitRatio() < 80) {
            $bottlenecks[] = [
                'type' => 'cache',
                'severity' => 'medium',
                'description' => 'Cache hit ratio is below optimal threshold',
                'recommendation' => 'Review cache strategy and consider preloading frequently accessed states',
                'metrics' => [
                    'current_ratio' => $this->calculateCacheHitRatio(),
                    'target_ratio' => 80
                ]
            ];
        }

        // Operation bottlenecks
        $avgOpTime = $this->calculateAverageOperationTime();
        if ($avgOpTime > 0.1) {
            $bottlenecks[] = [
                'type' => 'operation',
                'severity' => 'medium',
                'description' => 'Average operation time exceeds optimal threshold',
                'recommendation' => 'Consider implementing batch operations or optimizing state access patterns',
                'metrics' => [
                    'current_time' => $avgOpTime,
                    'target_time' => 0.1
                ]
            ];
        }

        return $bottlenecks;
    }

    /**
     * Calculate average operation time
     * 
     * @return float Average operation time in seconds
     */
    private function calculateAverageOperationTime(): float {
        if (empty($this->diagnosticMetrics['timing']['operations'])) {
            return 0.0;
        }

        $total = array_sum($this->diagnosticMetrics['timing']['operations']);
        return $total / count($this->diagnosticMetrics['timing']['operations']);
    }

    /**
     * Sanitize value for logging
     * 
     * @param mixed $value Value to sanitize
     * @return mixed Sanitized value
     */
    private function sanitizeValueForLog($value) {
        if (is_object($value)) {
            return [
                'type' => 'object',
                'class' => get_class($value),
                'hash' => spl_object_hash($value)
            ];
        }

        if (is_array($value)) {
            return [
                'type' => 'array',
                'count' => count($value),
                'sample' => array_slice($value, 0, 3)
            ];
        }

        return $value;
    }
}