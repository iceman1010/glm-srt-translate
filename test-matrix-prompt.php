#!/usr/bin/env php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use ZAiSrt\ZAiClient;
use ZAiSrt\PromptBuilder;

$envDir = __DIR__;
if (file_exists($envDir . '/.env')) {
    Dotenv\Dotenv::createImmutable($envDir)->load();
}

$globalEnvDir = getenv('HOME') . '/.zai-srt-translate';
if (empty($_ENV['ZAI_API_KEY']) && file_exists($globalEnvDir . '/.env')) {
    Dotenv\Dotenv::createImmutable($globalEnvDir)->load();
}

if (empty($_ENV['ZAI_API_KEY'])) {
    echo "Error: ZAI_API_KEY not found.\n";
    exit(1);
}

$srtFile = getenv('HOME') . '/Desktop/cf-llm-test/The.Matrix.1999.Tubi.CC.en.srt';
if (!file_exists($srtFile)) {
    echo "Error: {$srtFile} not found.\n";
    exit(1);
}

$content = file_get_contents($srtFile);
$blocks = preg_split('/\n\s*\n/', trim($content));

$batch = [];
$count = 0;
foreach ($blocks as $block) {
    $lines = explode("\n", trim($block));
    if (count($lines) < 3) {
        continue;
    }
    $textLines = array_slice($lines, 2);
    $text = implode("\n", $textLines);
    $batch[] = ['index' => (string)$count, 'text' => $text];
    $count++;
    if ($count >= 20) {
        break;
    }
}

echo "Loaded " . count($batch) . " subtitles from Matrix\n\n";

$client = new ZAiClient($_ENV['ZAI_API_KEY']);
$systemPrompt = PromptBuilder::buildSimpleSystemPrompt('de');

echo "=== System Prompt ===\n";
echo $systemPrompt . "\n";
echo "=== End Prompt ===\n\n";

$userMessage = PromptBuilder::formatBatchAsSimple($batch);
echo "=== User Message (first 500 chars) ===\n";
echo mb_substr($userMessage, 0, 500) . "\n";
echo "=== End User Message ===\n\n";

echo "Sending to glm-4.7-flash...\n";

try {
    $response = $client->chatCompletion(
        'glm-4.7-flash',
        $systemPrompt,
        $userMessage,
        [
            'temperature' => 0.6,
            'max_tokens' => 4096,
            'thinking' => false,
        ]
    );

    $responseText = $response['result']['response'] ?? '';
    echo "\n=== Raw Response ===\n";
    echo $responseText . "\n";
    echo "=== End Response ===\n\n";

    preg_match_all('/^\[(\d+)\]:/m', $responseText, $matches);
    $got = count(array_unique($matches[1] ?? []));
    $expected = count($batch);

    echo "Expected: {$expected}, Got: {$got}\n";

    if ($got === $expected) {
        echo "PASS\n";
    } else {
        echo "FAIL - missing indexes: ";
        $gotIndexes = array_unique($matches[1] ?? []);
        $expectedIndexes = array_column($batch, 'index');
        $missing = array_diff($expectedIndexes, $gotIndexes);
        echo implode(', ', $missing) . "\n";
    }
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
