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

$model = $argv[1] ?? 'glm-4.7-flash';
$lang = $argv[2] ?? 'sk';

$client = new ZAiClient($_ENV['ZAI_API_KEY']);
$systemPrompt = PromptBuilder::buildSimpleSystemPrompt($lang);

function makeBatch(int $size): array {
    $samples = [
        'THE QUICK BROWN FOX JUMPS OVER THE LAZY DOG.',
        "I CAN'T\nBELIEVE THIS IS HAPPENING.",
        '[ DOOR OPENS ]',
        "WHERE IS <i>HE</i>?\nI NEED TO FIND HIM.",
        'WE HAVE TO GO NOW.',
        'THE MATRIX HAS YOU.',
        'FOLLOW THE WHITE RABBIT.',
        "THERE IS NO\nSPOON.",
        'FREE YOUR MIND.',
        "I KNOW <b>KUNG FU</b>.\nSHOW ME.",
        'YOU HAVE TO SEE IT FOR YOURSELF.',
        "DON'T THINK YOU ARE.\nKNOW YOU ARE.",
        'WHAT IS REAL?',
        '[ GUNSHOTS ]',
        'STOP TRYING TO HIT ME AND HIT ME.',
        'HE IS THE ONE.',
        "[ PHONE RINGS ]\nHELLO?",
        'EVERYONE FALLS THE FIRST TIME.',
        'NOTHING I SAY WILL BE BELIEVED.',
        "THESE ARE NOT THE DROIDS\nYOU'RE LOOKING FOR.",
    ];
    $batch = [];
    for ($i = 0; $i < $size; $i++) {
        $batch[] = [
            'index' => (string)$i,
            'text' => $samples[$i % count($samples)],
        ];
    }
    return $batch;
}

function sendRequest(ZAiClient $client, string $model, string $systemPrompt, array $batch, bool $log = false): array {
    $userMessage = PromptBuilder::formatBatchAsSimple($batch);
    $start = microtime(true);
    try {
        $response = $client->chatCompletion($model, $systemPrompt, $userMessage, [
            'temperature' => 0.6,
            'max_tokens' => max(2048, count($batch) * 55),
            'thinking' => false,
        ]);
        $elapsed = round(microtime(true) - $start, 1);
        $text = $response['result']['response'] ?? '';
        preg_match_all('/^\[(\d+)\]:/m', $text, $matches);
        $got = count(array_unique($matches[1] ?? []));
        return ['ok' => true, 'elapsed' => $elapsed, 'got' => $got, 'expected' => count($batch), 'chars' => strlen($text)];
    } catch (\RuntimeException $e) {
        $elapsed = round(microtime(true) - $start, 1);
        return ['ok' => false, 'elapsed' => $elapsed, 'error' => $e->getMessage()];
    }
}

echo "=== API Rate Calibration Tool ===\n";
echo "Model: {$model}\n";
echo "Language: {$lang}\n\n";

echo "=== Phase 1: Finding minimum delay between requests ===\n";
echo "Starting at 5s delay, binary searching for minimum safe delay.\n\n";

$delays = [30, 60, 90, 120, 180];
$batchSize = 20;
$results = [];

foreach ($delays as $delay) {
    $successes = 0;
    $failures = 0;
    $totalTime = 0;

    echo "Testing {$delay}s delay (3 requests, batch {$batchSize})...";

    for ($i = 0; $i < 3; $i++) {
        $batch = makeBatch($batchSize);
        $result = sendRequest($client, $model, $systemPrompt, $batch);
        if ($result['ok']) {
            $successes++;
            $totalTime += $result['elapsed'];
            echo ".";
        } else {
            $failures++;
            echo "x";
        }

        if ($i < 2) {
            sleep($delay);
        }
    }

    $avgTime = $successes > 0 ? round($totalTime / $successes, 1) : '?';
    $results[$delay] = ['successes' => $successes, 'failures' => $failures, 'avg_time' => $successes > 0 ? $avgTime : '-'];
    echo " {$successes}/3 ok (avg {$avgTime}s)\n";
}

$minSafeDelay = null;
foreach ($results as $delay => $r) {
    if ($r['successes'] >= 2 && ($minSafeDelay === null || $delay < $minSafeDelay)) {
        $minSafeDelay = $delay;
    }
}

echo "\n--- Phase 1 Results ---\n";
foreach ($results as $delay => $r) {
    $marker = ($delay === $minSafeDelay) ? ' <-- MINIMUM SAFE' : '';
    echo " {$delay}s: {$r['successes']}/3 success, avg {$r['avg_time']}s{$marker}\n";
}

if ($minSafeDelay === null) {
    echo "\nNo safe delay found. Try starting with higher delays.\n";
    exit(1);
}

echo "\nRecommended minimum delay: {$minSafeDelay}s\n\n";

echo "=== Phase 2: Finding max batch size at {$minSafeDelay}s delay ===\n";

$sizes = [25, 50, 75, 100, 150, 200];
$sizeResults = [];

foreach ($sizes as $size) {
    echo "Testing batch size {$size} (2 requests, {$minSafeDelay}s delay)...";

    $successes = 0;
    $partialOk = 0;

    for ($i = 0; $i < 2; $i++) {
        $batch = makeBatch($size);
        $result = sendRequest($client, $model, $systemPrompt, $batch);
        if ($result['ok']) {
            if ($result['got'] === $result['expected']) {
                $successes++;
                echo ".";
            } else {
                $partialOk++;
                echo "~({$result['got']}/{$result['expected']})";
            }
        } else {
            echo "x";
        }

        if ($i < 1) {
            sleep($minSafeDelay);
        }
    }

    $total = $successes + $partialOk;
    $sizeResults[$size] = ['full' => $successes, 'partial' => $partialOk, 'total' => $total];
    echo " {$successes} full, {$partialOk} partial, " . (2 - $total) . " failed\n";
}

echo "\n--- Phase 2 Results ---\n";
$bestSize = null;
foreach ($sizeResults as $size => $r) {
    $marker = '';
    if ($r['full'] >= 2 && ($bestSize === null || $size > $bestSize)) {
        $bestSize = $size;
    }
    if ($size === $bestSize) {
        $marker = ' <-- BEST';
    }
    echo "  batch {$size}: {$r['full']}/2 full, {$r['partial']}/2 partial{$marker}\n";
}

echo "\n=== Phase 3: Large batches with long delays ===\n";
$combos = [
    ['batch' => 100, 'delay' => 120],
    ['batch' => 150, 'delay' => 120],
    ['batch' => 150, 'delay' => 180],
    ['batch' => 200, 'delay' => 180],
];
$comboResults = [];

foreach ($combos as $combo) {
    $size = $combo['batch'];
    $delay = $combo['delay'];
    echo "Testing batch {$size}, delay {$delay}s (1 request)...";

    $batch = makeBatch($size);
    $result = sendRequest($client, $model, $systemPrompt, $batch);
    if ($result['ok']) {
        if ($result['got'] === $result['expected']) {
            echo " OK ({$result['elapsed']}s, {$result['got']}/{$result['expected']})\n";
            $comboResults[] = ['batch' => $size, 'delay' => $delay, 'ok' => true, 'full' => true, 'elapsed' => $result['elapsed']];
        } else {
            echo " PARTIAL ({$result['elapsed']}s, {$result['got']}/{$result['expected']})\n";
            $comboResults[] = ['batch' => $size, 'delay' => $delay, 'ok' => true, 'full' => false, 'got' => $result['got'], 'expected' => $result['expected'], 'elapsed' => $result['elapsed']];
        }
    } else {
        $short = mb_substr($result['error'], 0, 80);
        echo " FAILED ({$result['elapsed']}s): {$short}\n";
        $comboResults[] = ['batch' => $size, 'delay' => $delay, 'ok' => false, 'error' => $short];
    }

    sleep($delay);
}

echo "\n--- Phase 3 Results ---\n";
foreach ($comboResults as $r) {
    if ($r['ok']) {
        if ($r['full']) {
            echo "  batch {$r['batch']}, delay {$r['delay']}s: FULL success ({$r['elapsed']}s)\n";
        } else {
            echo "  batch {$r['batch']}, delay {$r['delay']}s: PARTIAL ({$r['got']}/{$r['expected']}) ({$r['elapsed']}s)\n";
        }
    } else {
        echo "  batch {$r['batch']}, delay {$r['delay']}s: FAILED\n";
    }
}

echo "\n=== Recommendation ===\n";
echo "Delay: -D {$minSafeDelay}\n";
echo "Batch: -b " . ($bestSize ?? 'unknown') . "\n";
echo "Example: zai-srt-translate -i file.srt -l {$lang} -m {$model} -b " . ($bestSize ?? 50) . " -D {$minSafeDelay}\n";
