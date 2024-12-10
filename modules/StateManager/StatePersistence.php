<?php

namespace nCore\StateManager\Traits;

use nCore\StateManager\Interfaces\PersistenceDriverInterface;
use nCore\StateManager\Persistence\Drivers\{CacheDriver, FileDriver, DatabaseDriver};
use nCore\Exceptions\PersistenceException;

trait StatePersistence {
    /** @var PersistenceDriverInterface Current persistence driver */
    private $persistenceDriver;

    /** @var array Registered persistence drivers */
    private $persistenceDrivers = [];

    /**
     * Initialize persistence system
     */
    private function initializePersistence(): void {
        // Register default drivers
        $this->registerPersistenceDriver('cache', new CacheDriver($this->cacheManager));
        $this->registerPersistenceDriver('file', new FileDriver($this->config['storage_path']));
        $this->registerPersistenceDriver('database', new DatabaseDriver());

        // Set default driver
        $this->persistenceDriver = $this->persistenceDrivers[$this->config['persistence_driver'] ?? 'cache'];
    }

    /**
     * Register new persistence driver
     */
    private function registerPersistenceDriver(string $name, PersistenceDriverInterface $driver): void {
        $this->persistenceDrivers[$name] = $driver;
    }

    /**
     * Persist state to storage
     * 
     * @param array $keys Specific keys to persist, empty for all
     * @return bool Success status
     * @throws PersistenceException
     */
    public function persistState(array $keys = []): bool {
        try {
            $start = microtime(true);
            $this->recordMetric('operation', 'persist_start');

            // Prepare state data for persistence
            $stateData = [
                'version' => '2.0',
                'timestamp' => time(),
                'state' => $keys ? array_intersect_key($this->state, array_flip($keys)) : $this->state,
                'metadata' => [
                    'checksum' => $this->calculateStateChecksum($keys),
                    'compressed' => false,
                    'keys_count' => count($keys ?: $this->state)
                ]
            ];

            // Compress if beyond threshold
            if (strlen(serialize($stateData)) > $this->config['compression_threshold']) {
                $stateData['state'] = $this->compressState($stateData['state']);
                $stateData['metadata']['compressed'] = true;
            }

            // Encrypt if enabled
            if ($this->config['encrypt_state']) {
                $stateData = $this->encryptState($stateData);
            }

            $success = $this->persistenceDriver->persist($stateData);

            $this->recordMetric('operation', 'persist_complete', [
                'duration' => microtime(true) - $start,
                'keys_count' => $stateData['metadata']['keys_count']
            ]);

            return $success;

        } catch (\Exception $e) {
            throw new PersistenceException(
                'State persistence failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Restore state from storage
     * 
     * @return bool Success status
     * @throws PersistenceException
     */
    public function restoreState(): bool {
        try {
            $start = microtime(true);
            $this->recordMetric('operation', 'restore_start');

            $stateData = $this->persistenceDriver->restore();
            if (!$stateData) {
                return false;
            }

            // Decrypt if encrypted
            if ($this->config['encrypt_state']) {
                $stateData = $this->decryptState($stateData);
            }

            // Validate checksum
            if (!$this->validateStateChecksum($stateData)) {
                throw new PersistenceException('State checksum validation failed');
            }

            // Decompress if compressed
            if ($stateData['metadata']['compressed']) {
                $stateData['state'] = $this->decompressState($stateData['state']);
            }

            // Merge with current state
            $this->state = array_merge($this->state, $stateData['state']);

            $this->recordMetric('operation', 'restore_complete', [
                'duration' => microtime(true) - $start,
                'keys_count' => count($stateData['state'])
            ]);

            return true;

        } catch (\Exception $e) {
            throw new PersistenceException(
                'State restoration failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Get current persistence driver
     */
    public function getPersistenceDriver(): PersistenceDriverInterface {
        return $this->persistenceDriver;
    }

    /**
     * Set persistence driver
     */
    public function setPersistenceDriver(PersistenceDriverInterface $driver): void {
        $previousDriver = $this->persistenceDriver;

        try {
            $this->persistenceDriver = $driver;
            $this->recordMetric('operation', 'driver_change');

        } catch (\Exception $e) {
            $this->persistenceDriver = $previousDriver;
            throw new PersistenceException(
                'Failed to set persistence driver: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Clear all persisted state
     */
    public function clearPersistedState(): bool {
        try {
            $this->recordMetric('operation', 'clear_persisted');
            return $this->persistenceDriver->clear();

        } catch (\Exception $e) {
            throw new PersistenceException(
                'Failed to clear persisted state: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Export state to portable format
     * 
     * @param array $keys Specific keys to export, empty for all
     * @return string JSON encoded state
     * @throws PersistenceException
     */
    public function exportState(array $keys = []): string {
        try {
            $exportData = [
                'version' => '2.0',
                'timestamp' => time(),
                'state' => $keys ? array_intersect_key($this->state, array_flip($keys)) : $this->state,
                'metadata' => [
                    'exported_by' => get_current_user_id(),
                    'environment' => $this->config['environment'],
                    'checksum' => $this->calculateStateChecksum($keys)
                ]
            ];

            $json = json_encode($exportData, JSON_PRETTY_PRINT);
            if ($json === false) {
                throw new \RuntimeException('JSON encoding failed');
            }

            $this->recordMetric('operation', 'state_export', [
                'keys_count' => count($exportData['state'])
            ]);

            return $json;

        } catch (\Exception $e) {
            throw new PersistenceException(
                'State export failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Import state from exported format
     * 
     * @param string $exportedState JSON encoded state
     * @return bool Success status
     * @throws PersistenceException
     */
    public function importState(string $exportedState): bool {
        try {
            $importData = json_decode($exportedState, true);
            if (!$importData) {
                throw new \RuntimeException('Invalid import data format');
            }

            // Validate version compatibility
            if (version_compare($importData['version'], '2.0', '<')) {
                throw new \RuntimeException('Incompatible state version');
            }

            // Validate checksum
            if ($importData['metadata']['checksum'] !== $this->calculateStateChecksum($importData['state'])) {
                throw new \RuntimeException('Import data checksum validation failed');
            }

            // Begin transaction
            $transactionId = $this->beginTransaction();

            try {
                // Merge imported state
                foreach ($importData['state'] as $key => $value) {
                    $this->setState($key, $value, [
                        'track_history' => true,
                        'metadata' => [
                            'imported' => true,
                            'import_timestamp' => $importData['timestamp']
                        ]
                    ]);
                }

                $this->commitTransaction($transactionId);

                $this->recordMetric('operation', 'state_import', [
                    'keys_count' => count($importData['state'])
                ]);

                return true;

            } catch (\Exception $e) {
                $this->rollbackTransaction($transactionId);
                throw $e;
            }

        } catch (\Exception $e) {
            throw new PersistenceException(
                'State import failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Compress state data
     */
    private function compressState(array $state): string {
        return gzcompress(serialize($state), 9);
    }

    /**
     * Decompress state data
     */
    private function decompressState(string $compressed): array {
        return unserialize(gzuncompress($compressed));
    }

    /**
     * Encrypt state data
     */
    private function encryptState(array $state): string {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $encrypted = sodium_crypto_secretbox(
            serialize($state),
            $nonce,
            $this->config['encryption_key']
        );
        return base64_encode($nonce . $encrypted);
    }

    /**
     * Decrypt state data
     */
    private function decryptState(string $encrypted): array {
        $decoded = base64_decode($encrypted);
        $nonce = mb_substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, '8bit');
        $ciphertext = mb_substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, null, '8bit');
        
        $plaintext = sodium_crypto_secretbox_open(
            $ciphertext,
            $nonce,
            $this->config['encryption_key']
        );

        if ($plaintext === false) {
            throw new \RuntimeException('State decryption failed');
        }

        return unserialize($plaintext);
    }

    /**
     * Calculate state checksum
     */
    private function calculateStateChecksum(array $state = []): string {
        return hash('xxh3', serialize($state ?: $this->state));
    }

    /**
     * Validate state checksum
     */
    private function validateStateChecksum(array $stateData): bool {
        return $stateData['metadata']['checksum'] === 
               $this->calculateStateChecksum($stateData['state']);
    }
}