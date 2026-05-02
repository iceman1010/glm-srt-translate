<?php

namespace ZAiSrt;

class ZAiClient
{
    private string $apiKey;
    private int $timeout;

    public function __construct(string $apiKey, int $timeout = 300)
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
                throw new \RuntimeException("408 Timeout: {$curlError}");
            }
            throw new \RuntimeException("cURL error: {$curlError}");
        }

        if ($httpCode === 429) {
            $preview = mb_substr($response, 0, 500);
            throw new \RuntimeException("429 Rate Limited: waiting 60s\nResponse: {$preview}");
        }

        if ($httpCode === 401 || $httpCode === 403) {
            $preview = mb_substr($response, 0, 500);
            throw new \RuntimeException("{$httpCode} Authentication error: {$preview}");
        }

        if ($httpCode >= 500) {
            $preview = mb_substr($response, 0, 200);
            throw new \RuntimeException("{$httpCode} Server error: {$preview}");
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Failed to parse API response JSON: " . json_last_error_msg());
        }

        if (isset($data['error'])) {
            $errorMsg = $data['error']['message'] ?? json_encode($data['error']);
            $errorCode = $data['error']['code'] ?? $httpCode;
            throw new \RuntimeException("API error ({$errorCode}): {$errorMsg}");
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
