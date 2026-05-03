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

$client = new ZAiClient($_ENV['ZAI_API_KEY']);
$systemPrompt = PromptBuilder::buildSimpleSystemPrompt('sk');

echo "=== System Prompt ===\n";
echo $systemPrompt . "\n";
echo "=== End Prompt ===\n\n";

$tests = [
    [
        'name' => 'Test 1: 5 short subtitles',
        'batch' => [
            ['index' => '0', 'text' => 'Hello.'],
            ['index' => '1', 'text' => 'How are you?'],
            ['index' => '2', 'text' => 'I am fine.'],
            ['index' => '3', 'text' => '[ DOOR OPENS ]'],
            ['index' => '4', 'text' => "Where is <i>he</i>?"],
        ],
    ],
    [
        'name' => 'Test 2: 3 multiline subtitles',
        'batch' => [
            ['index' => '0', 'text' => "I CAN'T\nBELIEVE THIS."],
            ['index' => '1', 'text' => "WE NEED TO\nGET OUT OF HERE."],
            ['index' => '2', 'text' => "NOW.\nGO!"],
        ],
    ],
    [
        'name' => 'Test 3: 5 longer subtitles',
        'batch' => [
            ['index' => '0', 'text' => 'THE MATRIX HAS YOU.'],
            ['index' => '1', 'text' => 'FOLLOW THE WHITE RABBIT.'],
            ['index' => '2', 'text' => "THERE IS NO\nSPOON."],
            ['index' => '3', 'text' => 'FREE YOUR MIND.'],
            ['index' => '4', 'text' => "I KNOW <b>KUNG FU</b>.\nSHOW ME."],
        ],
    ],
];

$passed = 0;
$failed = 0;

foreach ($tests as $test) {
    echo "--- {$test['name']} ---\n";

    $userMessage = PromptBuilder::formatBatchAsSimple($test['batch']);
    echo "User message:\n{$userMessage}\n";

    try {
        $response = $client->chatCompletion(
            'glm-4.7-flash',
            $systemPrompt,
            $userMessage,
            [
                'temperature' => 0.6,
                'max_tokens' => 2048,
                'thinking' => false,
            ]
        );

        $responseText = $response['result']['response'] ?? '';
        echo "Response:\n{$responseText}\n";

        $expected = count($test['batch']);
        preg_match_all('/^\[(\d+)\]:/m', $responseText, $matches);
        $got = count(array_unique($matches[1] ?? []));

        if ($got === $expected) {
            echo "PASS: Got {$got}/{$expected} translations\n\n";
            $passed++;
        } else {
            echo "FAIL: Got {$got}/{$expected} translations\n\n";
            $failed++;
        }
    } catch (\Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n\n";
        $failed++;
    }

    echo "Waiting 3s...\n\n";
    sleep(3);
}

echo "=== Results: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
