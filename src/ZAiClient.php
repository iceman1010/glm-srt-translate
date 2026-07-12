<?php

namespace ZAiSrt;

class ZAiClient
{
    private string $apiKey;
    private int $timeout;
    private ?string $logFile;

    public function __construct(string $apiKey, int $timeout = 600, ?string $logFile = null)
    {
        $this->apiKey = $apiKey;
        $this->timeout = $timeout;
        $this->logFile = $logFile;
    }

    public function chatCompletion(
        string $modelId,
        string $systemPrompt,
        string $userMessage,
        array $options = []
    ): array {
        $url = 'https://api.z.ai/api/paas/v4/chat/completions';

        $body = [
            'model' => $modelId,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userMessage],
            ],
        ];

        if (isset($options['temperature'])) {
            $body['temperature'] = $options['temperature'];
        }
        if (isset($options['max_tokens'])) {
            $body['max_tokens'] = $options['max_tokens'];
        }
        if (isset($options['thinking'])) {
            if ($options['thinking']) {
                $body['thinking'] = ['type' => 'enabled'];
            } else {
                $body['thinking'] = ['type' => 'disabled'];
            }
        }

        // When set (e.g. 'json_object'), the API enforces valid JSON output at the
        // sampler level. This is what makes the JSON subtitle contract reliable.
        if (isset($options['response_format'])) {
            $body['response_format'] = ['type' => $options['response_format']];
        }

        $jsonBody = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $responseHeaders = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonBody,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$responseHeaders) {
                $responseHeaders .= $header;
                return strlen($header);
            },
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
        ]);

        $startTime = microtime(true);
        $response = curl_exec($ch);
        $elapsed = round(microtime(true) - $startTime, 3);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $this->logRequest($url, $body, $jsonBody, $httpCode, $responseHeaders, $response, $elapsed, $curlError);

        if ($response === false) {
            if (str_contains($curlError, 'timed out') || str_contains($curlError, 'Timeout')) {
                throw new \RuntimeException("ZAI_TIMEOUT: {$curlError}");
            }
            throw new \RuntimeException("ZAI_CURL_ERROR: {$curlError}");
        }

        $data = json_decode($response, true);

        if ($httpCode === 429) {
            $zaiCode = $data['error']['code'] ?? '';
            $zaiMsg = $data['error']['message'] ?? '';
            // code 1113 = insufficient balance / no resource package — NOT a rate
            // limit. Retrying is pointless; abort so we don't burn time and tokens.
            if ($zaiCode === '1113' || preg_match('/balance|recharge|resource package/i', $zaiMsg)) {
                throw new \RuntimeException("ZAI_BALANCE_ERROR: [{$zaiCode}] {$zaiMsg}");
            }
            throw new \RuntimeException("ZAI_RATE_LIMITED: [{$zaiCode}] {$zaiMsg}");
        }

        if ($httpCode === 401 || $httpCode === 403) {
            $zaiCode = $data['error']['code'] ?? '';
            $zaiMsg = $data['error']['message'] ?? mb_substr($response, 0, 200);
            throw new \RuntimeException("ZAI_AUTH_ERROR: [{$zaiCode}] {$zaiMsg}");
        }

        if ($httpCode >= 500) {
            throw new \RuntimeException("ZAI_SERVER_ERROR: HTTP {$httpCode} - " . mb_substr($response, 0, 200));
        }

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("ZAI_PARSE_ERROR: " . json_last_error_msg());
        }

        if (isset($data['error'])) {
            $errorMsg = $data['error']['message'] ?? json_encode($data['error']);
            $errorCode = $data['error']['code'] ?? $httpCode;
            throw new \RuntimeException("ZAI_API_ERROR: [{$errorCode}] {$errorMsg}");
        }

        $responseText = $data['choices'][0]['message']['content'] ?? null;
        $reasoningContent = $data['choices'][0]['message']['reasoning_content'] ?? null;

        if ($responseText === null || $responseText === '') {
            if ($reasoningContent) {
                $preview = mb_substr($reasoningContent, 0, 500);
                throw new \RuntimeException("ZAI_REASONING_ONLY: Model returned reasoning only, no content. Reasoning preview: {$preview}");
            }
            $debug = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            throw new \RuntimeException("No response content in API result. Response structure:\n{$debug}");
        }

        return [
            'result' => [
                'response' => $responseText,
                'reasoning_content' => $reasoningContent,
                'usage' => $data['usage'] ?? [],
                'choices' => $data['choices'] ?? [],
            ],
        ];
    }

    private function logRequest(
        string $url,
        array $body,
        string $jsonBody,
        int $httpCode,
        string $responseHeaders,
        $response,
        float $elapsed,
        string $curlError
    ): void {
        if ($this->logFile === null) {
            return;
        }

        $ts = date('Y-m-d H:i:s');
        $sep = str_repeat('=', 80);

        $logBody = $body;
        foreach ($logBody['messages'] as &$msg) {
            if (strlen($msg['content']) > 500) {
                $msg['content'] = mb_substr($msg['content'], 0, 500) . '... [' . strlen($msg['content']) . ' chars total]';
            }
        }
        unset($msg);

        $out = $sep . "\n";
        $out .= "[{$ts}] REQUEST\n";
        $out .= $sep . "\n";
        $out .= "URL: {$url}\n";
        $out .= "HTTP Code: {$httpCode}\n";
        $out .= "Time: {$elapsed}s\n";
        $out .= "Request body size: " . strlen($jsonBody) . " bytes\n\n";
        $out .= "--- Request Body (truncated) ---\n";
        $out .= json_encode($logBody, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
        $out .= "--- Response Headers ---\n";
        $out .= trim($responseHeaders) . "\n";

        if ($curlError) {
            $out .= "\n--- cURL Error ---\n{$curlError}\n";
        }

        if (is_string($response)) {
            $out .= "\n--- Response Body (" . strlen($response) . " bytes) ---\n";
            $decoded = json_decode($response, true);
            if ($decoded) {
                $out .= json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
            } else {
                $out .= $response . "\n";
            }
        } else {
            $out .= "\n--- Response: (none) ---\n";
        }

        $out .= "\n";

        file_put_contents($this->logFile, $out, FILE_APPEND);
    }
}
