<?php

namespace ZAiSrt;

class ZAiClient
{
    private string $apiKey;
    private int $timeout;

    public function __construct(string $apiKey, int $timeout = 600)
    {
        $this->apiKey = $apiKey;
        $this->timeout = $timeout;
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
        if (isset($options['thinking']) && $options['thinking']) {
            $body['thinking'] = ['type' => 'enabled'];
        }

        $jsonBody = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonBody,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

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
                throw new \RuntimeException("Model returned reasoning only, no content. May need more max_tokens or disable thinking.");
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
}
