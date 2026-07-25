<?php
/**
 * OpenAI-compatible LLM API Client
 * Supports both streaming (SSE) and non-streaming requests
 */

class LLMClient {
    private string $apiUrl;
    private string $apiKey;
    private string $defaultModel;
    private bool $isSenseNova;

    /**
     * @param string $apiUrl   OpenAI-compatible endpoint URL
     * @param string $apiKey   API key (sk-...)
     * @param string $defaultModel  Default model if none specified per request
     */
    public function __construct(string $apiUrl, string $apiKey, string $defaultModel = 'gpt-4o') {
        $this->apiUrl = $apiUrl;
        $this->apiKey = $apiKey;
        $this->defaultModel = $defaultModel ?: LLM_DEFAULT_MODEL;
        $this->isSenseNova = str_contains(strtolower($apiUrl), 'token.sensenova.cn');
    }
    
    /**
     * Send a non-streaming chat completion request
     * Returns the full response text
     */
    public function chat(array $messages, ?string $model = null, float $temperature = 0.7, int $maxTokens = 4096): array {
        $payload = $this->buildPayload($messages, $model, $temperature, $maxTokens, false);
        
        $body = json_encode($payload);
        $ch = curl_init($this->apiUrl);
        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
                'Accept: application/json',
                'User-Agent: phpchatgpt/1.0',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HEADERFUNCTION => function($ch, $header) use (&$responseHeaders) {
                $separator = strpos($header, ':');
                if ($separator !== false) {
                    $name = strtolower(trim(substr($header, 0, $separator)));
                    $responseHeaders[$name] = trim(substr($header, $separator + 1));
                }
                return strlen($header);
            },
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $remoteIp = curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new LLMApiException('LLM API connection error: ' . $error, $this->buildConnectionErrorDetails($error, $remoteIp));
        }
        
        $data = json_decode($response, true);
        
        $content = $this->extractContent($data);
        if ($httpCode < 200 || $httpCode >= 300 || $content === null) {
            $errMsg = $this->extractErrorMessage($data);
            if (!$errMsg && !empty($response)) {
                $errMsg = substr($response, 0, 500);
            }
            $details = $this->buildApiErrorDetails($httpCode, $errMsg, $response, $responseHeaders, $remoteIp, $model);
            throw new LLMApiException($details['summary'], $details);
        }
        
        return [
            'content'       => $content,
            'model'         => $data['model'] ?? ($model ?? $this->defaultModel),
            'finish_reason' => $data['choices'][0]['finish_reason'] ?? $data['stop_reason'] ?? 'stop',
            'usage'         => $data['usage'] ?? null,
        ];
    }
    
    /**
     * Send a streaming chat completion request via SSE
     * Calls $onToken callback for each token chunk
     * Calls $onComplete callback when finished
     */
    public function chatStream(array $messages, callable $onToken, callable $onComplete, ?string $model = null, float $temperature = 0.7, int $maxTokens = 4096): void {
        // Ensure output buffering is off for SSE
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        
        $payload = $this->buildPayload($messages, $model, $temperature, $maxTokens, true);
        
        $body = json_encode($payload);
        $ch = curl_init($this->apiUrl);
        $buffer = '';
        $responseBody = '';  // Capture full response for error reporting
        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
                'Accept: text/event-stream',
                'User-Agent: phpchatgpt/1.0',
            ],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HEADERFUNCTION => function($ch, $header) use (&$responseHeaders) {
                $separator = strpos($header, ':');
                if ($separator !== false) {
                    $name = strtolower(trim(substr($header, 0, $separator)));
                    $responseHeaders[$name] = trim(substr($header, $separator + 1));
                }
                return strlen($header);
            },
            // Process each chunk as it arrives
            CURLOPT_WRITEFUNCTION   => function($ch, $data) use ($onToken, &$buffer, &$responseBody) {
                $responseBody .= $data;
                $buffer .= $data;
                // Process complete SSE lines
                while (($pos = strpos($buffer, "\n\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 2);
                    
                    $lines = explode("\n", $line);
                    foreach ($lines as $l) {
                        if (strpos($l, 'data: ') === 0) {
                            $json = substr($l, 6);
                            if ($json === '[DONE]') continue;
                            $chunk = json_decode($json, true);
                            $delta = $this->extractStreamDelta($chunk);
                            if ($delta !== '') {
                                $onToken($delta);
                                // Flush each token to the browser
                                echo "data: " . json_encode(['token' => $delta]) . "\n\n";
                                if (ob_get_level()) ob_flush();
                                flush();
                            }
                        }
                    }
                }
                return strlen($data);
            },
        ]);
        
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $remoteIp = curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        $error = curl_error($ch);
        curl_close($ch);
        
        // Handle HTTP errors from the API
        if ($httpCode < 200 || $httpCode >= 300) {
            $errBody = '';
            if (!empty($responseBody)) {
                $errData = json_decode(trim($responseBody), true);
                $errBody = $this->extractErrorMessage($errData) ?: substr(trim($responseBody), 0, 300);
            }
            $details = $this->buildApiErrorDetails($httpCode, $errBody, $responseBody, $responseHeaders, $remoteIp, $model);
            echo "data: " . json_encode(['error' => $details['summary'], 'error_details' => $details]) . "\n\n";
            echo "data: [DONE]\n\n";
            if (ob_get_level()) ob_flush();
            flush();
            return;
        }
        
        // Process any remaining buffer
        if (!empty($buffer) && $buffer !== 'data: [DONE]') {
            $lines = explode("\n", $buffer);
            foreach ($lines as $l) {
                if (strpos($l, 'data: ') === 0) {
                    $json = substr($l, 6);
                    if ($json === '[DONE]') break;
                    $chunk = json_decode($json, true);
                    $delta = $this->extractStreamDelta($chunk);
                    if ($delta !== '') {
                        $onToken($delta);
                        echo "data: " . json_encode(['token' => $delta]) . "\n\n";
                        if (ob_get_level()) ob_flush();
                        flush();
                    }
                }
            }
        }
        
        // Signal completion
        $onComplete();
        echo "data: [DONE]\n\n";
        if (ob_get_level()) ob_flush();
        flush();
    }

    private function buildPayload(array $messages, ?string $model, float $temperature, int $maxTokens, bool $stream): array {
        $payload = [
            'model' => $model ?? $this->defaultModel,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
            'stream' => $stream,
        ];

        if ($this->isSenseNova) {
            $systemMessages = array_filter($messages, static fn(array $message): bool => $message['role'] === 'system');
            $payload['system'] = implode("\n", array_map(static fn(array $message): string => is_string($message['content']) ? $message['content'] : '', $systemMessages));
            $payload['messages'] = array_values(array_filter($messages, static fn(array $message): bool => $message['role'] !== 'system'));
        } else {
            $payload['messages'] = $messages;
        }

        return $payload;
    }

    private function extractContent(array $data): ?string {
        if ($this->isSenseNova) {
            $content = $data['content'] ?? null;
            if (is_string($content)) return $content;
            if (is_array($content)) {
                return implode('', array_map(static fn(array $block): string => $block['text'] ?? '', $content));
            }
            return null;
        }

        return $data['choices'][0]['message']['content'] ?? null;
    }

    private function extractStreamDelta(array $chunk): string {
        if ($this->isSenseNova) {
            return $chunk['delta']['text'] ?? $chunk['content_block']['text'] ?? '';
        }

        return $chunk['choices'][0]['delta']['content'] ?? '';
    }

    private function extractErrorMessage(?array $data): string {
        if (!$data) return '';

        $error = $data['error'] ?? $data['detail'] ?? $data['message'] ?? '';
        if (is_array($error)) {
            return $error['message'] ?? $error['detail'] ?? $error['code'] ?? json_encode($error);
        }

        return is_string($error) ? $error : '';
    }

    private function buildApiErrorDetails(int $httpCode, string $message, string $response, array $headers, string $remoteIp, ?string $model): array {
        $requestId = $headers['x-request-id'] ?? $headers['request-id'] ?? $headers['x-correlation-id'] ?? $headers['cf-ray'] ?? '';
        $endpointHost = parse_url($this->apiUrl, PHP_URL_HOST) ?: 'API server';
        $safeBody = $this->redactSensitiveData($response);
        $safeHeaders = [];
        foreach (['content-type', 'retry-after', 'x-request-id', 'request-id', 'x-correlation-id', 'cf-ray'] as $name) {
            if (isset($headers[$name])) $safeHeaders[$name] = $headers[$name];
        }
        $summary = 'LLM API HTTP ' . $httpCode . ($message ? ': ' . $message : '');
        $details = [
            'summary' => $summary,
            'http_status' => $httpCode,
            'endpoint' => $endpointHost,
            'model' => $model ?? $this->defaultModel,
            'provider_ip' => $remoteIp ?: null,
            'request_id' => $requestId ?: null,
            'provider_message' => $message ?: null,
            'response_body' => $safeBody !== '' ? mb_substr($safeBody, 0, 4000) : null,
            'response_headers' => $safeHeaders,
            'possible_causes' => $this->possibleCauses($httpCode),
        ];

        error_log('[LLM API] ' . json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $details;
    }

    private function buildConnectionErrorDetails(string $error, string $remoteIp): array {
        $endpointHost = parse_url($this->apiUrl, PHP_URL_HOST) ?: 'API server';
        $details = [
            'summary' => 'LLM API connection error: ' . $error,
            'http_status' => null,
            'endpoint' => $endpointHost,
            'provider_ip' => $remoteIp ?: null,
            'request_id' => null,
            'provider_message' => $error,
            'response_body' => null,
            'response_headers' => [],
            'possible_causes' => [
                'The hosting provider blocks outbound HTTPS or cURL connections.',
                'The provider endpoint is unavailable, misspelled, or blocked by DNS/firewall rules.',
                'The hosting server cannot negotiate the provider TLS requirements.',
            ],
        ];

        error_log('[LLM API] ' . json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $details;
    }

    private function possibleCauses(int $httpCode): array {
        return match ($httpCode) {
            401 => [
                'The API key is missing, expired, malformed, or belongs to another provider.',
                'Regenerate the provider API key and save it again in API Settings.',
            ],
            403 => [
                'The API key/account is not permitted to use the selected model.',
                'The provider has blocked the VistaPanel server outbound IP, region, or shared-hosting network.',
                'Model terms, billing, quota, or account activation have not been completed.',
            ],
            404 => [
                'The API endpoint URL is incorrect or does not include the required path.',
                'The selected model ID is unavailable or misspelled.',
            ],
            408, 504 => [
                'The provider did not respond before the request timeout.',
                'The model is overloaded or the hosting network is too slow.',
            ],
            429 => [
                'The provider rate limit, quota, or concurrent-request limit was reached.',
                'Wait for the retry interval, reduce request frequency, or review account billing.',
            ],
            default => [
                'Review the provider message and request ID, then verify endpoint, model, API key, and account status.',
            ],
        };
    }

    private function redactSensitiveData(string $value): string {
        $value = preg_replace('/Bearer\\s+[A-Za-z0-9._-]+/i', 'Bearer [REDACTED]', $value);
        $value = preg_replace('/("?(?:api[_-]?key|token|secret)"?\\s*[:=]\\s*")[^"]+("?)/i', '$1[REDACTED]$2', $value);
        return $value;
    }
}

class LLMApiException extends RuntimeException {
    public array $details;

    public function __construct(string $message, array $details) {
        parent::__construct($message);
        $this->details = $details;
    }
}