<?php

namespace nCore\StateManager\Traits;

trait HistoryManagement {
    /** @var array History storage */
    private $history = [];

    /**
     * Push state change to history
     */
    public function pushToHistory(string $key): void {
        if (!isset($this->history[$key])) {
            $this->history[$key] = [
                'stack' => [],
                'position' => -1,
                'max_position' => -1
            ];
        }

        $historyEntry = [
            'value' => $this->state[$key] ?? null,
            'timestamp' => microtime(true),
            'transaction_id' => $this->currentTransaction
        ];

        // Truncate future history if not at end
        if ($this->history[$key]['position'] < $this->history[$key]['max_position']) {
            array_splice(
                $this->history[$key]['stack'],
                $this->history[$key]['position'] + 1
            );
            $this->history[$key]['max_position'] = $this->history[$key]['position'];
        }

        // Add new entry
        $this->history[$key]['stack'][] = $historyEntry;
        $this->history[$key]['position']++;
        $this->history[$key]['max_position']++;

        // Maintain history depth limit
        while (count($this->history[$key]['stack']) > $this->config['history_depth']) {
            array_shift($this->history[$key]['stack']);
            $this->history[$key]['position']--;
            $this->history[$key]['max_position']--;
        }

        $this->recordMetric('operation', 'history_push');
    }

    /**
     * Get history for specific key
     */
    public function getHistoryForKey(string $key): array {
        return $this->history[$key] ?? [
            'stack' => [],
            'position' => -1,
            'max_position' => -1
        ];
    }

    /**
     * Clear history for specific or all keys
     */
    public function clearHistory(?string $key = null): void {
        if ($key === null) {
            $this->history = [];
        } else {
            unset($this->history[$key]);
        }
        $this->recordMetric('operation', 'history_clear');
    }

    /**
     * Check if undo is available
     */
    public function canUndo(string $key): bool {
        return isset($this->history[$key]) && 
               $this->history[$key]['position'] > 0;
    }

    /**
     * Check if redo is available
     */
    public function canRedo(string $key): bool {
        return isset($this->history[$key]) &&
               $this->history[$key]['position'] < $this->history[$key]['max_position'];
    }

    /**
     * Undo last state change
     */
    public function undo(string $key): bool {
        if (!$this->canUndo($key)) {
            return false;
        }

        try {
            $this->history[$key]['position']--;
            $previousEntry = $this->history[$key]['stack'][$this->history[$key]['position']];
            
            $this->setState($key, $previousEntry['value'], [
                'track_history' => false
            ]);

            $this->recordMetric('operation', 'undo');
            return true;

        } catch (\Exception $e) {
            $this->errorManager->logError('undo_failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Redo previously undone change
     */
    public function redo(string $key): bool {
        if (!$this->canRedo($key)) {
            return false;
        }

        try {
            $this->history[$key]['position']++;
            $nextEntry = $this->history[$key]['stack'][$this->history[$key]['position']];
            
            $this->setState($key, $nextEntry['value'], [
                'track_history' => false
            ]);

            $this->recordMetric('operation', 'redo');
            return true;

        } catch (\Exception $e) {
            $this->errorManager->logError('redo_failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}