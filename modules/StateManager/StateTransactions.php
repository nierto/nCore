<?php

namespace nCore\StateManager\Traits;

trait StateTransactions {
    /** @var array Active transactions */
    private $transactions = [];

    /** @var string|null Current transaction ID */
    private $currentTransaction = null;

    /** @var bool Transaction state */
    private $inTransaction = false;

    /** @var array Transaction snapshots */
    private $snapshots = [];

    /** @var array Transaction locks */
    private $transactionLocks = [];

    /** @var int Transaction timeout in seconds */
    private const TRANSACTION_TIMEOUT = 30;

    /**
     * Begin new transaction with snapshot
     */
    public function beginTransaction(): string {
        if (!$this->initialized) {
            throw new \RuntimeException('StateManager not initialized');
        }

        try {
            // Generate unique transaction ID
            $transactionId = hash('xxh3', uniqid('tx_', true) . microtime(true));
            
            // Take state snapshot
            $snapshot = [
                'state' => clone (object)$this->state,
                'history' => clone (object)$this->history,
                'timestamp' => microtime(true),
                'modified_keys' => [],
                'locks' => [],
                'parent' => $this->currentTransaction,
                'status' => 'active'
            ];

            // Store snapshot
            $this->snapshots[$transactionId] = $snapshot;
            
            // Set current transaction
            $this->currentTransaction = $transactionId;
            $this->inTransaction = true;

            // Register transaction
            $this->transactions[$transactionId] = [
                'id' => $transactionId,
                'started' => microtime(true),
                'timeout' => microtime(true) + self::TRANSACTION_TIMEOUT,
                'modified_keys' => [],
                'locks' => [],
                'status' => 'active'
            ];

            $this->recordMetric('transaction', 'begin');
            return $transactionId;

        } catch (\Exception $e) {
            $this->errorManager->logError('transaction_begin_failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Commit transaction changes
     */
    public function commitTransaction(string $transactionId): bool {
        if (!isset($this->transactions[$transactionId])) {
            throw new \RuntimeException('Invalid transaction ID');
        }

        if ($this->transactions[$transactionId]['status'] !== 'active') {
            throw new \RuntimeException('Transaction already completed');
        }

        try {
            // Verify transaction timeout
            if (microtime(true) > $this->transactions[$transactionId]['timeout']) {
                throw new \RuntimeException('Transaction timeout');
            }

            // Release locks
            foreach ($this->transactions[$transactionId]['locks'] as $key) {
                unset($this->transactionLocks[$key]);
            }

            // Update transaction status
            $this->transactions[$transactionId]['status'] = 'committed';
            
            // Clear snapshot
            unset($this->snapshots[$transactionId]);

            // Reset transaction state if this was the root transaction
            if ($this->currentTransaction === $transactionId) {
                $this->currentTransaction = $this->transactions[$transactionId]['parent'] ?? null;
                $this->inTransaction = $this->currentTransaction !== null;
            }

            $this->recordMetric('transaction', 'commit');
            return true;

        } catch (\Exception $e) {
            $this->errorManager->logError('transaction_commit_failed', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage()
            ]);
            
            // Attempt rollback on commit failure
            $this->rollbackTransaction($transactionId);
            
            throw $e;
        }
    }

    /**
     * Rollback transaction changes
     */
    public function rollbackTransaction(string $transactionId): bool {
        if (!isset($this->transactions[$transactionId])) {
            throw new \RuntimeException('Invalid transaction ID');
        }

        try {
            // Restore snapshot if exists
            if (isset($this->snapshots[$transactionId])) {
                $snapshot = $this->snapshots[$transactionId];
                
                // Restore state
                $this->state = (array)$snapshot->state;
                $this->history = (array)$snapshot->history;
                
                // Clear snapshot
                unset($this->snapshots[$transactionId]);
            }

            // Release locks
            foreach ($this->transactions[$transactionId]['locks'] as $key) {
                unset($this->transactionLocks[$key]);
            }

            // Update transaction status
            $this->transactions[$transactionId]['status'] = 'rolled_back';

            // Reset transaction state if this was the root transaction
            if ($this->currentTransaction === $transactionId) {
                $this->currentTransaction = $this->transactions[$transactionId]['parent'] ?? null;
                $this->inTransaction = $this->currentTransaction !== null;
            }

            // Notify observers of rollback
            if (!empty($this->transactions[$transactionId]['modified_keys'])) {
                $this->batchNotify([
                    'type' => 'transaction_rollback',
                    'transaction_id' => $transactionId,
                    'modified_keys' => $this->transactions[$transactionId]['modified_keys']
                ]);
            }

            $this->recordMetric('transaction', 'rollback');
            return true;

        } catch (\Exception $e) {
            $this->errorManager->logError('transaction_rollback_failed', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Check if currently in transaction
     */
    public function isInTransaction(): bool {
        return $this->inTransaction;
    }

    /**
     * Get transaction state and metadata
     */
    public function getTransactionState(string $transactionId): array {
        if (!isset($this->transactions[$transactionId])) {
            throw new \RuntimeException('Invalid transaction ID');
        }

        $transaction = $this->transactions[$transactionId];
        $snapshot = $this->snapshots[$transactionId] ?? null;

        return [
            'id' => $transaction['id'],
            'status' => $transaction['status'],
            'started' => $transaction['started'],
            'duration' => microtime(true) - $transaction['started'],
            'timeout' => $transaction['timeout'],
            'modified_keys' => $transaction['modified_keys'],
            'locks' => $transaction['locks'],
            'has_snapshot' => $snapshot !== null,
            'parent_transaction' => $transaction['parent'] ?? null,
            'depth' => $this->getTransactionDepth($transactionId),
            'is_current' => $transactionId === $this->currentTransaction
        ];
    }

    /**
     * Get transaction nesting depth
     */
    private function getTransactionDepth(string $transactionId): int {
        $depth = 0;
        $current = $transactionId;

        while (isset($this->transactions[$current]['parent'])) {
            $depth++;
            $current = $this->transactions[$current]['parent'];
        }

        return $depth;
    }

    /**
     * Acquire lock for state key in transaction
     */
    private function acquireTransactionLock(string $transactionId, string $key): bool {
        if (isset($this->transactionLocks[$key]) && 
            $this->transactionLocks[$key] !== $transactionId) {
            return false;
        }

        $this->transactionLocks[$key] = $transactionId;
        $this->transactions[$transactionId]['locks'][] = $key;
        
        return true;
    }

    /**
     * Track modified key in transaction
     */
    private function trackModifiedKey(string $transactionId, string $key): void {
        if (!in_array($key, $this->transactions[$transactionId]['modified_keys'])) {
            $this->transactions[$transactionId]['modified_keys'][] = $key;
        }
    }

    /**
     * Clean up expired transactions
     */
    private function cleanupExpiredTransactions(): void {
        $now = microtime(true);

        foreach ($this->transactions as $transactionId => $transaction) {
            if ($transaction['status'] === 'active' && $now > $transaction['timeout']) {
                try {
                    $this->rollbackTransaction($transactionId);
                } catch (\Exception $e) {
                    $this->errorManager->logError('transaction_cleanup_failed', [
                        'transaction_id' => $transactionId,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
    }
}