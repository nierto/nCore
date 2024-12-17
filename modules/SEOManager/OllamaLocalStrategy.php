<?php
/**
 * Ollama Local Strategy
 * 
 * Implements AI strategy using locally hosted Ollama server
 * for SEO content enhancement.
 * 
 * @package     nCore
 * @subpackage  SEO\AIStrategies
 * @version     2.0.0
 */

namespace nCore\SEO\AIStrategies;

class OllamaLocalStrategy implements AIStrategyInterface {
    /** @var array Configuration */
    private $config = [];
    
    /** @var bool Initialization state */
    private $initialized = false;
    
    /** @var array Performance metrics */
    private $metrics = [
        'requests' => 0,
        'errors' => 0,
        'total_time' => 0
    ];

    /**
     * Constructor
     */
    public function __construct(array $config = []) {
        $this->config = array_merge([
            'model' => 'mixtral',
            'endpoint' => 'http://localhost:11434/api/generate',
            'timeout' => 30,
            'context_length' => 4096,
            'temperature' => 0.7
        ], $config);
    }

    /**
     * Initialize strategy
     */
    public function initialize(): void {
        if ($this->initialized) {
            return;
        }

        // Verify Ollama availability
        if (!$this->checkOllamaConnection()) {
            throw new \RuntimeException('Cannot connect to local Ollama server');
        }

        $this->initialized = true;
    }

    /**
     * Enhance meta tags using AI
     */
    public function enhanceMeta(array $context): array {
        $startTime = microtime(true);
        $this->metrics['requests']++;

        try {
            // Prepare prompt for meta enhancement
            $prompt = $this->buildMetaPrompt($context);

            // Get AI response
            $response = $this->generateResponse($prompt);

            // Parse AI response
            $enhanced = $this->parseMetaResponse($response);

            $this->metrics['total_time'] += microtime(true) - $startTime;
            return $enhanced;

        } catch (\Exception $e) {
            $this->metrics['errors']++;
            throw $e;
        }
    }

    /**
     * Enhance schema data using AI
     */
    public function enhanceSchema(array $context): array {
        $startTime = microtime(true);
        $this->metrics['requests']++;

        try {
            // Prepare prompt for schema enhancement
            $prompt = $this->buildSchemaPrompt($context);

            // Get AI response
            $response = $this->generateResponse($prompt);

            // Parse AI response
            $enhanced = $this->parseSchemaResponse($response);

            $this->metrics['total_time'] += microtime(true) - $startTime;
            return $enhanced;

        } catch (\Exception $e) {
            $this->metrics['errors']++;
            throw $e;
        }
    }

    /**
     * Generate response from Ollama
     */
    private function generateResponse(string $prompt): string {
        $data = [
            'model' => $this->config['model'],
            'prompt' => $prompt,
            'stream' => false,
            'options' => [
                'temperature' => $this->config['temperature']
            ]
        ];

        $response = wp_remote_post($this->config['endpoint'], [
            'timeout' => $this->config['timeout'],
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode($data)
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException('Ollama request failed: ' . $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!isset($data['response'])) {
            throw new \RuntimeException('Invalid Ollama response');
        }

        return $data['response'];
    }

    /**
     * Build meta enhancement prompt
     */
    private function buildMetaPrompt(array $context): string {
        return <<<PROMPT
        Analyze the following content and enhance the meta tags for optimal SEO:

        Title: {$context['title']}
        Content: {$context['content']}

        Current Meta Tags:
        {$this->formatCurrentMeta($context['current_meta'])}

        Please provide enhanced meta tags in JSON format focusing on:
        - Natural, engaging descriptions
        - Keyword optimization
        - Click-through optimization
        - Social media appeal
        PROMPT;
    }

    /**
     * Build schema enhancement prompt
     */
    private function buildSchemaPrompt(array $context): string {
        return <<<PROMPT
        Analyze the following content and enhance the schema markup:

        Type: {$context['type']}
        Content: {$context['content']}

        Current Schema:
        {$this->formatCurrentSchema($context['current_schema'])}

        Please provide enhanced schema properties in JSON format focusing on:
        - Comprehensive property coverage
        - Rich snippet optimization
        - Search relevance
        - Semantic accuracy
        PROMPT;
    }

    /**
     * Parse meta response
     */
    private function parseMetaResponse(string $response): array {
        $data = json_decode($response, true);
        if (!$data) {
            throw new \RuntimeException('Failed to parse meta response');
        }

        return array_intersect_key(
            $data,
            array_flip(['description', 'og_description', 'twitter_description'])
        );
    }

    /**
     * Parse schema response
     */
    private function parseSchemaResponse(string $response): array {
        $data = json_decode($response, true);
        if (!$data) {
            throw new \RuntimeException('Failed to parse schema response');
        }

        return $data;
    }

    /**
     * Format current meta for prompt
     */
    private function formatCurrentMeta(array $meta): string {
        return wp_json_encode($meta, JSON_PRETTY_PRINT);
    }

    /**
     * Format current schema for prompt
     */
    private function formatCurrentSchema(array $schema): string {
        return wp_json_encode($schema, JSON_PRETTY_PRINT);
    }

    /**
     * Check Ollama connection
     */
    private function checkOllamaConnection(): bool {
        $response = wp_remote_get(str_replace('/generate', '/version', $this->config['endpoint']), [
            'timeout' => 5
        ]);

        return !is_wp_error($response) && 
               wp_remote_retrieve_response_code($response) === 200;
    }

    /**
     * Get configuration
     */
    public function getConfig(): array {
        return $this->config;
    }

    /**
     * Update configuration
     */
    public function updateConfig(array $config): void {
        $this->config = array_merge($this->config, $config);
    }

    /**
     * Check availability
     */
    public function isAvailable(): bool {
        return $this->initialized && $this->checkOllamaConnection();
    }

    /**
     * Get status
     */
    public function getStatus(): array {
        return [
            'initialized' => $this->initialized,
            'model' => $this->config['model'],
            'available' => $this->isAvailable(),
            'metrics' => [
            'total_requests' => $this->metrics['requests'],
            'error_count' => $this->metrics['errors'],
            'average_time' => $this->metrics['requests'] > 0 
                ? $this->metrics['total_time'] / $this->metrics['requests']
                : 0,
            'success_rate' => $this->metrics['requests'] > 0
                ? (($this->metrics['requests'] - $this->metrics['errors']) / $this->metrics['requests']) * 100
                : 0
            ]
        ];
    }
}