<?php

namespace nCore\StateManager\Traits;

use nCore\Security\Encryption;
use nCore\Security\Sanitizer;
use nCore\Security\AccessControl;

trait StateSecurity {
    /** @var array Registered validators by key */
    private $validators = [];

    /** @var array Type validators */
    private const TYPE_VALIDATORS = [
        'string' => 'is_string',
        'int' => 'is_int',
        'float' => 'is_float',
        'bool' => 'is_bool',
        'array' => 'is_array',
        'object' => 'is_object'
    ];

    /** @var array Sensitive data patterns */
    private const SENSITIVE_PATTERNS = [
        'password' => '/password/i',
        'token' => '/token/i',
        'key' => '/key$/i',
        'secret' => '/secret/i',
        'auth' => '/auth/i',
        'credit_card' => '/^(?:4[0-9]{12}(?:[0-9]{3})?|[25][1-7][0-9]{14}|6(?:011|5[0-9][0-9])[0-9]{12}|3[47][0-9]{13}|3(?:0[0-5]|[68][0-9])[0-9]{11}|(?:2131|1800|35\d{3})\d{11})$/'
    ];

    /** @var array Security metrics */
    private $securityMetrics = [
        'validation_failures' => 0,
        'access_denials' => 0,
        'sanitization_counts' => 0
    ];

    /**
     * Validate state change with comprehensive checks
     */
    public function validateStateChange(string $key, $value): bool {
        try {
            // Check for state initialization
            if (!$this->initialized) {
                throw new \RuntimeException('StateManager not initialized');
            }

            // Enforce access control
            if (!$this->enforceAccessControl($key)) {
                $this->securityMetrics['access_denials']++;
                return false;
            }

            // Check custom validators
            if (isset($this->validators[$key])) {
                foreach ($this->validators[$key] as $validator) {
                    if (!$validator($value)) {
                        throw new \InvalidArgumentException("Custom validation failed for key: {$key}");
                    }
                }
            }

            // Check for sensitive data patterns
            foreach (self::SENSITIVE_PATTERNS as $pattern) {
                if (preg_match($pattern, (string)$value)) {
                    if (!$this->handleSensitiveData($key, $value)) {
                        throw new \RuntimeException("Sensitive data handling failed for key: {$key}");
                    }
                }
            }

            // Type validation
            if (isset($this->state[$key])) {
                $currentType = gettype($this->state[$key]);
                if (gettype($value) !== $currentType) {
                    throw new \TypeError("Type mismatch for key {$key}: expected {$currentType}, got " . gettype($value));
                }
            }

            // Size validation for memory management
            if (is_string($value) && strlen($value) > ($this->config['max_string_length'] ?? 1048576)) {
                throw new \LengthException("Value exceeds maximum string length for key: {$key}");
            }

            if (is_array($value) && count($value) > ($this->config['max_array_size'] ?? 10000)) {
                throw new \LengthException("Array exceeds maximum size for key: {$key}");
            }

            return true;

        } catch (\Exception $e) {
            $this->securityMetrics['validation_failures']++;
            $this->errorManager->logError('validation_failed', [
                'key' => $key,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Sanitize state value with type-specific handling
     */
    public function sanitizeStateValue($value, ?string $type = null) {
        $this->securityMetrics['sanitization_counts']++;

        if ($type === null) {
            $type = gettype($value);
        }

        switch ($type) {
            case 'string':
                return $this->sanitizeString($value);
            
            case 'array':
                return $this->sanitizeArray($value);
            
            case 'object':
                return $this->sanitizeObject($value);
            
            case 'int':
                return filter_var($value, FILTER_SANITIZE_NUMBER_INT);
            
            case 'float':
                return filter_var($value, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
            
            case 'bool':
                return (bool)$value;
            
            default:
                throw new \InvalidArgumentException("Unsupported type for sanitization: {$type}");
        }
    }

    /**
     * Register custom validator for state key
     */
    public function registerValidator(string $key, callable $validator): void {
        if (!isset($this->validators[$key])) {
            $this->validators[$key] = [];
        }
        
        $validatorId = spl_object_hash((object)$validator);
        $this->validators[$key][$validatorId] = $validator;
        
        // Log validator registration
        $this->errorManager->logDebug('validator_registered', [
            'key' => $key,
            'validator_id' => $validatorId
        ]);
    }

    /**
     * Remove validator for state key
     */
    public function removeValidator(string $key): bool {
        if (!isset($this->validators[$key])) {
            return false;
        }

        unset($this->validators[$key]);
        return true;
    }

    /**
     * Enforce access control for state operations
     */
    public function enforceAccessControl(string $key): bool {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            foreach ($this->getSensitiveKeys() as $pattern) {
                if (preg_match($pattern, $key)) {
                    return false;
                }
            }
        }

        // Check rate limiting
        if (!$this->checkRateLimit($key)) {
            return false;
        }

        // Check environment restrictions
        if (defined('WP_ENVIRONMENT_TYPE') && WP_ENVIRONMENT_TYPE === 'production') {
            if ($this->isRestrictedInProduction($key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Handle sensitive data encryption and storage
     */
    private function handleSensitiveData(string $key, $value): bool {
        try {
            // Get encryption service
            $encryption = Encryption::getInstance();

            // Encrypt sensitive value
            $encryptedValue = $encryption->encrypt(
                $value,
                $this->config['encryption_key']
            );

            // Store encryption metadata
            $this->storeSensitiveMetadata($key, [
                'encrypted' => true,
                'algorithm' => $encryption->getAlgorithm(),
                'timestamp' => time()
            ]);

            return true;

        } catch (\Exception $e) {
            $this->errorManager->logError('sensitive_data_handling_failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Sanitize string value
     */
    private function sanitizeString(string $value): string {
        // Basic XSS prevention
        $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        
        // Remove null bytes
        $value = str_replace(chr(0), '', $value);
        
        // Normalize whitespace
        $value = preg_replace('/\s+/', ' ', trim($value));
        
        return $value;
    }

    /**
     * Sanitize array value recursively
     */
    private function sanitizeArray(array $value): array {
        return array_map(function($item) {
            return $this->sanitizeStateValue($item);
        }, $value);
    }

    /**
     * Sanitize object value
     */
    private function sanitizeObject(object $value): object {
        // Convert to array, sanitize, and convert back
        $array = json_decode(json_encode($value), true);
        $sanitized = $this->sanitizeArray($array);
        return (object)$sanitized;
    }

    /**
     * Get security metrics
     */
    public function getSecurityMetrics(): array {
        return array_merge($this->securityMetrics, [
            'total_validators' => count($this->validators),
            'sensitive_keys' => count($this->getSensitiveKeys()),
            'encrypted_values' => $this->countEncryptedValues()
        ]);
    }

    /**
     * Check rate limiting for state operations
     */
    private function checkRateLimit(string $key): bool {
        $limits = $this->config['rate_limits'] ?? [
            'default' => ['limit' => 100, 'window' => 60],
            'sensitive' => ['limit' => 10, 'window' => 60]
        ];

        $type = $this->isSensitiveKey($key) ? 'sensitive' : 'default';
        $limit = $limits[$type];

        $rateKey = "rate_limit_{$key}_" . get_current_user_id();
        $current = get_transient($rateKey) ?: 0;

        if ($current >= $limit['limit']) {
            return false;
        }

        set_transient($rateKey, $current + 1, $limit['window']);
        return true;
    }
}