<?php

namespace ZAiSrt;

use Done\Subtitles\Subtitles;

class Translator
{
    private string $apiKey;
    private string $targetLanguage;
    private string $inputFile;
    private string $modelKey;
    private string $configPath;
    private int $batchSize;
    private float $temperature;
    private ?string $description;
    private string $outputFile;
    private int $maxTokens;

    private array $modelConfig;
    private string $modelId;
    private ZAiClient $client;

    private bool $enableThinking;
    private int $contextWindow;
    private string $responseFormat;
    private bool $debugMode;

    private int $consecutiveErrors = 0;
    private int $maxConsecutiveErrors = 3;
    private int $rateLimitErrors = 0;

    private int $totalInputTokens = 0;
    private int $totalOutputTokens = 0;
    private int $totalThinkTokens = 0;
    private int $totalApiCalls = 0;
    private int $partialBatches = 0;
    private int $qualityIssues = 0;
    private int $retryCount = 1;
    private int $currentRetry = 0;
    private bool $restartMode = false;
    private int $batchDelay;
    private int $originalDelay;
    private int $consecutiveSuccesses = 0;
    private float $lastRequestTime = 0.0;

    public function __construct(array $options)
    {
        $this->apiKey = $options['api_key'];
        $this->targetLanguage = is_array($options['target_language']) ? reset($options['target_language']) : $options['target_language'];
        $this->inputFile = is_array($options['input_file']) ? reset($options['input_file']) : $options['input_file'];
        $this->modelKey = is_array($options['model'] ?? 'glm-4.7-flash') ? reset($options['model'] ?? 'glm-4.7-flash') : $options['model'] ?? 'glm-4.7-flash';
        $this->configPath = is_array($options['config_path'] ?? __DIR__ . '/../llm-models.json') ? reset($options['config_path'] ?? __DIR__ . '/../llm-models.json') : $options['config_path'] ?? __DIR__ . '/../llm-models.json';
        $this->description = isset($options['description']) ? (is_array($options['description']) ? reset($options['description']) : $options['description']) : null;
        $this->retryCount = max(0, (int)(is_array($options['retry'] ?? 1) ? reset($options['retry'] ?? 1) : $options['retry'] ?? 1));

        $configJson = file_get_contents($this->configPath);
        if ($configJson === false) {
            throw new \RuntimeException("Cannot read config file: {$this->configPath}");
        }
        $config = json_decode($configJson, true);
        if (!isset($config['models'][$this->modelKey])) {
            throw new \RuntimeException("Unknown model: {$this->modelKey}. Available: " . implode(', ', array_keys($config['models'])));
        }

        $this->modelConfig = $config['models'][$this->modelKey];
        $this->modelId = $this->modelConfig['model_id'];
        $this->batchSize = isset($options['batch_size']) ? (int)(is_array($options['batch_size']) ? reset($options['batch_size']) : $options['batch_size']) : $this->modelConfig['batch_size'];
        $this->temperature = isset($options['temperature']) ? (float)(is_array($options['temperature']) ? reset($options['temperature']) : $options['temperature']) : $config['api']['default_temperature'];
        $this->maxTokens = isset($options['max_tokens']) ? (int)(is_array($options['max_tokens']) ? reset($options['max_tokens']) : $options['max_tokens']) : $config['api']['default_max_tokens'];
        $this->contextWindow = $this->modelConfig['context_window'];
        $this->enableThinking = $options['think'] ?? false;
        $this->responseFormat = is_array($options['format'] ?? 'simple') ? reset($options['format'] ?? 'simple') : $options['format'] ?? 'simple';
        if (!in_array($this->responseFormat, ['json', 'simple'])) {
            throw new \RuntimeException("Invalid format: {$this->responseFormat}. Must be 'json' or 'simple'.");
        }
        $this->debugMode = $options['debug'] ?? false;
        $this->batchDelay = max(0, (int)(is_array($options['delay'] ?? 60) ? reset($options['delay'] ?? 60) : $options['delay'] ?? 60));
        $this->originalDelay = $this->batchDelay;
        $this->restartMode = $options['restart'] ?? false;

        if (isset($options['output_file'])) {
            $this->outputFile = is_array($options['output_file']) ? reset($options['output_file']) : $options['output_file'];
        } else {
            $pathInfo = pathinfo($this->inputFile);
            $this->outputFile = $pathInfo['dirname'] . '/' . $pathInfo['filename']
                . '.' . strtolower(str_replace(' ', '_', $this->targetLanguage))
                . '.' . ($pathInfo['extension'] ?? 'srt');
        }

        $logFile = isset($options['log']) && $options['log'] ? (string)$options['log'] : null;
        $this->client = new ZAiClient($this->apiKey, 600, $logFile);
    }

    public function translate(): void
    {
        if (!file_exists($this->inputFile)) {
            throw new \RuntimeException("Input file not found: {$this->inputFile}");
        }

        echo "Loading: {$this->inputFile}\n";
        $subtitles = Subtitles::loadFromFile($this->inputFile);
        $internalFormat = $subtitles->getInternalFormat();

        $total = count($internalFormat);
        echo "Subtitles loaded: {$total}\n";
        echo "Model: {$this->modelKey} ({$this->modelId})\n";
        echo "Target language: {$this->targetLanguage}\n";
        echo "Batch size: {$this->batchSize}\n";
        if ($this->modelConfig['reasoning'] ?? false) {
            echo "Reasoning: " . ($this->enableThinking ? "enabled (--think)" : "disabled") . "\n";
        }
        echo "Output: {$this->outputFile}\n\n";

        $startTime = microtime(true);

        if ($this->responseFormat === 'simple') {
            $systemPrompt = PromptBuilder::buildSimpleSystemPrompt($this->targetLanguage);
        } else {
            $systemPrompt = PromptBuilder::buildSystemPrompt($this->targetLanguage);
        }

        if ($this->debugMode) {
            echo "\n=== SYSTEM PROMPT ===\n";
            echo $systemPrompt . "\n";
            echo "=== END SYSTEM PROMPT ===\n\n";
        }

        $progressFile = $this->inputFile . '.progress';
        $startIndex = 0;
        $translatedFormat = $internalFormat;
        $progressData = null;

        if (file_exists($progressFile)) {
            if ($this->restartMode) {
                unlink($progressFile);
                echo "Progress file cleared (--restart).\n";
            } else {
                $progressData = json_decode(file_get_contents($progressFile), true);
                $isValidProgress = false;
                if ($progressData && isset($progressData['index'])) {
                    $modelMatch = ($progressData['model'] ?? '') === $this->modelKey;
                    $langMatch = ($progressData['target_language'] ?? '') === $this->targetLanguage;
                    if ($modelMatch && $langMatch) {
                        $isValidProgress = true;
                        $startIndex = $progressData['index'];
                        if (isset($progressData['translations'])) {
                            foreach ($progressData['translations'] as $idx => $text) {
                                $translatedFormat[$idx]['lines'] = $this->textToLines($text);
                            }
                        }
                        echo "Resuming from subtitle {$startIndex}/{$total}\n";
                    }
                }
                if (!$isValidProgress) {
                    unlink($progressFile);
                    echo "Progress file cleared (job parameters changed).\n";
                }
            }
        }

        $translations = [];
        if ($startIndex > 0 && isset($progressData['translations'])) {
            $translations = $progressData['translations'];
        }

        $i = $startIndex;
        while ($i < $total) {
            $effectiveBatchSize = $this->fitBatchToContext($internalFormat, $i, $this->batchSize, $systemPrompt);
            $batchEnd = min($i + $effectiveBatchSize, $total);
            $batch = [];

            $stripHtml = $this->modelConfig['strip_html'] ?? false;
            $htmlMap = [];

            for ($j = $i; $j < $batchEnd; $j++) {
                $sub = $internalFormat[$j];
                $text = $this->linesToText($sub['lines']);
                $text = preg_replace('/\[(\S[^]]*)\]/', '[ $1 ]', $text);

                if ($stripHtml) {
                    $htmlMap[(string)$j] = $this->extractHtmlTags($text);
                    $text = strip_tags($text);
                }

                $batch[] = [
                    'index' => (string)$j,
                    'text' => $text,
                ];
            }

            if ($this->responseFormat === 'simple') {
                $userMessage = PromptBuilder::formatBatchAsSimple($batch);
            } else {
                $userMessage = PromptBuilder::formatBatchAsJson($batch);
            }

            echo sprintf(
                "Translating batch %d-%d / %d (%d%%)...",
                $i + 1,
                $batchEnd,
                $total,
                (int)round($batchEnd / $total * 100)
            );

            try {
                $this->enforceMinInterval();

                $effectiveBatchCount = $batchEnd - $i;
                $dynamicMaxTokens = max($this->maxTokens, $effectiveBatchCount * 55);
                $dynamicMaxTokens = min($dynamicMaxTokens, 131072);

                $clientOptions = [
                    'temperature' => $this->temperature,
                    'max_tokens' => $dynamicMaxTokens,
                ];
                if ($this->modelConfig['reasoning'] ?? false) {
                    $clientOptions['thinking'] = $this->enableThinking;
                }

                $response = $this->client->chatCompletion(
                    $this->modelId,
                    $systemPrompt,
                    $userMessage,
                    $clientOptions
                );

                $this->lastRequestTime = microtime(true);

                $responseText = $response['result']['response'] ?? '';

                if (isset($response['result']['usage'])) {
                    $this->totalInputTokens += $response['result']['usage']['prompt_tokens'] ?? 0;
                    $this->totalOutputTokens += $response['result']['usage']['completion_tokens'] ?? 0;
                    $reasoningContent = $response['result']['reasoning_content'] ?? '';
                    if ($reasoningContent !== '') {
                        $this->totalThinkTokens += (int)ceil(mb_strlen($reasoningContent) / 3.5);
                    }
                }

                $this->totalApiCalls++;

                if ($this->responseFormat === 'simple') {
                    $translatedLines = $this->parseSimpleResponse($responseText);
                } else {
                    $translatedLines = $this->extractJson($responseText);
                }

                $validation = $this->validateBatch($translatedLines, $batch);
                $validLines = $validation['valid'];
                $issues = $validation['issues'];

                $finishReason = $response['result']['choices'][0]['finish_reason'] ?? 'unknown';
                $outputLen = strlen($responseText);

                $partialPrefix = "";
                if ($validation['validCount'] < $validation['expectedCount']) {
                    $this->partialBatches++;
                    $partialPrefix = sprintf(" Partial: %d/%d", $validation['validCount'], $validation['expectedCount']);
                }

                if (!empty($issues)) {
                    $issueStr = " [" . implode(', ', $issues) . "]";
                    echo "{$partialPrefix}{$issueStr} [stop: {$finishReason}, {$outputLen} chars].";
                } else {
                    echo "{$partialPrefix} [stop: {$finishReason}, {$outputLen} chars].";
                }

                $maxTranslatedIdx = $i;
                foreach ($validLines as $line) {
                    $idx = (int)$line['index'];
                    $text = $line['text'];
                    $text = preg_replace('/\[ (.*?) \]/', '[$1]', $text);

                    if ($stripHtml && isset($htmlMap[(string)$idx])) {
                        $text = $this->reinsertHtmlTags($text, $htmlMap[(string)$idx]);
                    }

                    if ($this->isDominantRtl($text)) {
                        $text = "\u{202B}" . $text . "\u{202C}";
                    }

                    $translatedFormat[$idx]['lines'] = $this->textToLines($text);
                    $translations[$idx] = $text;
                    $maxTranslatedIdx = max($maxTranslatedIdx, $idx + 1);
                }

                $i = $maxTranslatedIdx;
                $this->saveProgress($progressFile, $i, $translations);
                $this->consecutiveErrors = 0;
                $this->rateLimitErrors = 0;
                $this->consecutiveSuccesses++;

                if ($this->consecutiveSuccesses >= 5 && $this->batchDelay > $this->originalDelay) {
                    $this->batchDelay = max($this->originalDelay, $this->batchDelay - 5);
                    if ($this->debugMode) {
                        echo " (delay→{$this->batchDelay}s)";
                    }
                    $this->consecutiveSuccesses = 0;
                }

                echo " Done.\n";

                $missingIndexes = $validation['missingIndexes'] ?? [];
                if (!empty($missingIndexes) && count($missingIndexes) < $validation['expectedCount']) {
                    $stillMissing = $this->retryMissing($missingIndexes, $internalFormat, $systemPrompt, $stripHtml, $translatedFormat, $translations, $progressFile);
                    if (!empty($stillMissing)) {
                        $minMissing = min($stillMissing);
                        if ($minMissing < $i) {
                            $i = $minMissing;
                            echo "  " . count($stillMissing) . " subtitle(s) still missing, will retry in next batch starting at {$i}.\n";
                        }
                    }
                }

            } catch (\RuntimeException $e) {
                $msg = $e->getMessage();

                if (str_contains($msg, 'merged/duplicate content')) {
                    echo "\nAborted: {$msg}\n";
                    throw $e;
                }

                if (str_starts_with($msg, 'ZAI_AUTH_ERROR:')) {
                    echo "\nAuthentication failed. Check your API key.\n  {$msg}\n";
                    $this->saveProgress($progressFile, $i, $translations);
                    throw $e;
                }

                if (str_starts_with($msg, 'ZAI_RATE_LIMITED:')) {
                    $this->rateLimitErrors++;
                    $this->consecutiveSuccesses = 0;
                    $this->batchDelay = min($this->batchDelay + 5, 120);
                    $this->lastRequestTime = microtime(true);
                    $wait = min(30 * pow(2, $this->rateLimitErrors - 1), 300);
                    echo " Rate limited (#{$this->rateLimitErrors}). Backing off {$wait}s...\n";
                    if ($this->debugMode) {
                        echo "  API response: " . substr($msg, strlen('ZAI_RATE_LIMITED: ')) . "\n";
                        echo "  Delay increased to {$this->batchDelay}s between batches\n";
                    }
                    sleep($wait);
                    continue;
                }

                $isTimeout = str_starts_with($msg, 'ZAI_TIMEOUT:');
                $isServerError = str_starts_with($msg, 'ZAI_SERVER_ERROR:');
                $isReasoningOnly = str_starts_with($msg, 'ZAI_REASONING_ONLY:');
                $isJsonError = str_contains($msg, 'JSON') || str_contains($msg, 'Count mismatch');

                if (is_string($responseText ?? null) && $isJsonError) {
                    $debugFile = $this->inputFile . ".{$this->modelKey}.debug.txt";
                    $timestamp = date('H:i:s');
                    $batchInfo = "=== Batch starting at index {$i} @ {$timestamp} ===\n";
                    file_put_contents($debugFile, $batchInfo . $responseText . "\n\n", FILE_APPEND);
                    echo " (raw response appended to {$debugFile})\n";
                }

                if ($isTimeout) {
                    $this->consecutiveErrors++;
                    $this->consecutiveSuccesses = 0;
                    echo " Timeout. Retrying...\n";
                    sleep(10);
                } elseif ($isServerError) {
                    $this->consecutiveErrors++;
                    $this->consecutiveSuccesses = 0;
                    echo " Server error. Waiting 60s...\n";
                    sleep(60);
                } elseif ($isReasoningOnly) {
                    $this->consecutiveErrors++;
                    $this->consecutiveSuccesses = 0;
                    $reasoningDetail = substr($msg, strlen('ZAI_REASONING_ONLY: '));
                    echo " Reasoning-only response (attempt {$this->consecutiveErrors}/{$this->maxConsecutiveErrors}).\n";
                    if ($this->debugMode) {
                        echo "  --- Reasoning output ---\n";
                        $lines = explode("\n", $reasoningDetail);
                        foreach (array_slice($lines, 0, 20) as $rl) {
                            echo "  {$rl}\n";
                        }
                        if (count($lines) > 20) {
                            echo "  ... (" . count($lines) . " lines total)\n";
                        }
                        echo "  --- End reasoning ---\n";
                    } else {
                        echo "  Run with -v (debug) to see reasoning output.\n";
                    }
                    sleep(5);
                } elseif ($isJsonError) {
                    $this->consecutiveErrors++;
                    echo " JSON parse error (attempt {$this->consecutiveErrors}/{$this->maxConsecutiveErrors}). Retrying...\n";
                    sleep(2);
                } else {
                    $this->consecutiveErrors++;
                    echo " Error: {$msg}\nRetrying...\n";
                    sleep(5);
                }

                if ($this->consecutiveErrors >= $this->maxConsecutiveErrors) {
                    echo "Too many consecutive errors ({$this->consecutiveErrors}). Saving progress and aborting.\n";
                    $this->saveProgress($progressFile, $i, $translations);
                    throw $e;
                }

                continue;
            }
        }

        $outputSubtitles = new Subtitles();
        foreach ($translatedFormat as $sub) {
            $outputSubtitles->add(
                $sub['start'],
                $sub['end'],
                $this->linesToText($sub['lines'])
            );
        }
        $outputSubtitles->save($this->outputFile);

        if (file_exists($progressFile)) {
            unlink($progressFile);
        }

        echo "\nTranslation completed successfully!\n";
        $elapsed = round(microtime(true) - $startTime, 2);
        echo "Output saved to: {$this->outputFile}\n";
        echo "Time: {$elapsed}s\n";
        $this->logTokenUsage();

        if ($this->qualityIssues > 0) {
            echo "\n Model {$this->modelKey} had {$this->qualityIssues} quality issue(s).\n";
        }
    }

    private function retryMissing(
        array $missingIndexes,
        array $internalFormat,
        string $systemPrompt,
        bool $stripHtml,
        array &$translatedFormat,
        array &$translations,
        string $progressFile
    ): array {
        $remaining = $missingIndexes;
        $totalMissing = count($remaining);
        $maxAttempts = max(3, $this->retryCount * 3);

        for ($attempt = 1; $attempt <= $maxAttempts && !empty($remaining); $attempt++) {
            $count = count($remaining);
            if ($attempt === 1) {
                echo "  Retrying {$count} missing subtitle(s)...";
            } else {
                echo "  Retry attempt {$attempt}/{$maxAttempts} for {$count} still missing...";
            }

            $retryBatch = [];
            $retryHtmlMap = [];
            foreach ($remaining as $idx) {
                $sub = $internalFormat[$idx];
                $text = $this->linesToText($sub['lines']);
                $text = preg_replace('/\[(\S[^]]*)\]/', '[ $1 ]', $text);

                if ($stripHtml) {
                    $retryHtmlMap[(string)$idx] = $this->extractHtmlTags($text);
                    $text = strip_tags($text);
                }

                $retryBatch[] = [
                    'index' => (string)$idx,
                    'text' => $text,
                ];
            }

            $retryUserMessage = PromptBuilder::formatBatchAsSimple($retryBatch);
            $retryMaxTokens = max(4096, $count * 55);

            try {
                $this->enforceMinInterval();

                $retryOptions = [
                    'temperature' => $this->temperature,
                    'max_tokens' => $retryMaxTokens,
                ];
                if ($this->modelConfig['reasoning'] ?? false) {
                    $retryOptions['thinking'] = $this->enableThinking;
                }

                $response = $this->client->chatCompletion(
                    $this->modelId,
                    $systemPrompt,
                    $retryUserMessage,
                    $retryOptions
                );

                $this->lastRequestTime = microtime(true);

                $responseText = $response['result']['response'] ?? '';
                $translatedLines = $this->parseSimpleResponse($responseText);

                $retryValidation = $this->validateBatch($translatedLines, $retryBatch);
                $recovered = 0;
                $recoveredIndexes = [];
                foreach ($retryValidation['valid'] as $line) {
                    $idx = (int)$line['index'];
                    $text = $line['text'];
                    $text = preg_replace('/\[ (.*?) \]/', '[$1]', $text);

                    if ($stripHtml && isset($retryHtmlMap[(string)$idx])) {
                        $text = $this->reinsertHtmlTags($text, $retryHtmlMap[(string)$idx]);
                    }

                    if ($this->isDominantRtl($text)) {
                        $text = "\u{202B}" . $text . "\u{202C}";
                    }

                    $translatedFormat[$idx]['lines'] = $this->textToLines($text);
                    $translations[$idx] = $text;
                    $recoveredIndexes[] = $idx;
                    $recovered++;
                }

                $this->saveProgress($progressFile, max(array_keys($translations)) + 1, $translations);

                $remaining = array_values(array_diff($remaining, $recoveredIndexes));
                $stillMissing = count($remaining);

                if ($stillMissing === 0) {
                    echo " all {$totalMissing} recovered.\n";
                    return [];
                }

                if ($attempt < $maxAttempts) {
                    echo " recovered {$recovered}/{$count}, {$stillMissing} still missing. Retrying...\n";
                } else {
                    echo " recovered {$recovered}/{$count}, {$stillMissing} still missing after {$maxAttempts} attempt(s).\n";
                }

            } catch (\RuntimeException $e) {
                $msg = $e->getMessage();

                if (str_starts_with($msg, 'ZAI_RATE_LIMITED:')) {
                    $this->rateLimitErrors++;
                    $wait = min(30 * pow(2, min($this->rateLimitErrors - 1, 3)), 300);
                    echo " rate limited (#{$this->rateLimitErrors}). Backing off {$wait}s...\n";
                    if ($this->debugMode) {
                        echo "  API response: " . substr($msg, strlen('ZAI_RATE_LIMITED: ')) . "\n";
                    }
                    sleep($wait);
                    $attempt--;
                    continue;
                }

                $isTimeout = str_starts_with($msg, 'ZAI_TIMEOUT:');
                $isServerError = str_starts_with($msg, 'ZAI_SERVER_ERROR:');
                $isReasoningOnly = str_starts_with($msg, 'ZAI_REASONING_ONLY:');

                if ($isTimeout || $isServerError) {
                    echo " " . ($isTimeout ? "timeout" : "server error") . ". Retrying in 30s...\n";
                    sleep(30);
                    $attempt--;
                    continue;
                }

                if ($isReasoningOnly && $this->debugMode) {
                    $reasoningDetail = substr($msg, strlen('ZAI_REASONING_ONLY: '));
                    echo " reasoning-only response.\n";
                    echo "  --- Reasoning output ---\n";
                    $lines = explode("\n", $reasoningDetail);
                    foreach (array_slice($lines, 0, 20) as $rl) {
                        echo "  {$rl}\n";
                    }
                    if (count($lines) > 20) {
                        echo "  ... (" . count($lines) . " lines total)\n";
                    }
                    echo "  --- End reasoning ---\n";
                }

                if ($attempt < $maxAttempts) {
                    echo " failed: " . ($isReasoningOnly ? "reasoning-only, no content" : $msg) . ". Retrying...\n";
                    sleep(5);
                } else {
                    echo " failed after {$maxAttempts} attempt(s): {$msg}\n";
                }
            }
        }

        return $remaining;
    }

    private function extractJson(string $text): array
    {
        $text = preg_replace('/<environment_details>.*?<\/environment_details>/s', '', $text);
        $text = preg_replace('/<[a-z_]+>.*?<\/[a-z_]+>/s', '', $text);
        $text = preg_replace('/<[a-z_]+>.*$/s', '', $text);
        $text = trim($text);

        $result = json_decode($text, true);
        if (is_array($result) && $this->isListOfDicts($result)) {
            return $result;
        }

        $text = preg_replace('/<think.*?<\/think>/s', '', $text);
        $text = trim($text);

        $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
        $text = preg_replace('/```\s*$/m', '', $text);
        $text = trim($text);

        $result = json_decode($text, true);
        if (is_array($result) && $this->isListOfDicts($result)) {
            return $result;
        }

        $firstBracket = strpos($text, '[');
        $lastBracket = strrpos($text, ']');
        if ($firstBracket !== false && $lastBracket !== false && $lastBracket > $firstBracket) {
            $extracted = substr($text, $firstBracket, $lastBracket - $firstBracket + 1);
            $result = json_decode($extracted, true);
            if (is_array($result) && $this->isListOfDicts($result)) {
                return $result;
            }
        }

        $repaired = $this->repairJson($text);
        if ($repaired !== null) {
            return $repaired;
        }

        $preview = mb_substr($text, 0, 300);
        throw new \RuntimeException("Failed to extract valid JSON from response. Preview: {$preview}");
    }

    private function repairJson(string $text): ?array
    {
        $firstBracket = strpos($text, '[');
        $lastBracket = strrpos($text, ']');
        if ($firstBracket !== false && $lastBracket !== false) {
            $text = substr($text, $firstBracket, $lastBracket - $firstBracket + 1);
        }

        if (preg_match('/},\s*\{/', $text)) {
            $parts = preg_split('/\},\{/', $text);
            if (count($parts) > 1) {
                $fixed = '';
                for ($i = 0; $i < count($parts) - 1; $i++) {
                    $fixed .= $parts[$i] . '},';
                }
                $fixed .= $parts[count($parts) - 1];
                $fixed = rtrim($fixed, ',') . ']';
                $result = json_decode($fixed, true);
                if (is_array($result) && $this->isListOfDicts($result)) {
                    return $result;
                }
            }
        }

        if (preg_match('/\{\},\s*\]?$/', $text)) {
            $fixed = preg_replace('/\{\},\s*\]?$/', ']', $text);
            $result = json_decode($fixed, true);
            if (is_array($result) && $this->isListOfDicts($result)) {
                return $result;
            }
        }

        $fixed = preg_replace('/,\s*]/', ']', $text);
        $result = json_decode($fixed, true);
        if (is_array($result) && $this->isListOfDicts($result)) {
            return $result;
        }

        $fixed = preg_replace('/"text","/', '"text":"', $text);
        $result = json_decode($fixed, true);
        if (is_array($result) && $this->isListOfDicts($result)) {
            return $result;
        }

        $fixed = preg_replace('/"\s*]$/', '"}]', $text);
        $result = json_decode($fixed, true);
        if (is_array($result) && $this->isListOfDicts($result)) {
            return $result;
        }

        if (str_ends_with(rtrim($text), ']}')) {
            $fixed = substr(rtrim($text), 0, -2) . '}]';
            $result = json_decode($fixed, true);
            if (is_array($result) && $this->isListOfDicts($result)) {
                return $result;
            }
        }

        if ($firstBracket !== false && $lastBracket === false) {
            $result = json_decode($text . '"}]', true);
            if (is_array($result) && $this->isListOfDicts($result)) {
                return $result;
            }
            $result = json_decode($text . ']', true);
            if (is_array($result) && $this->isListOfDicts($result)) {
                return $result;
            }
        }

        return null;
    }

    private function parseSimpleResponse(string $text): array
    {
        $text = preg_replace('/^```(?:txt|text)?\s*/m', '', $text);
        $text = preg_replace('/```\s*$/m', '', $text);
        $text = trim($text);

        $result = [];
        $currentIndex = null;
        $currentText = [];

        $lines = explode("\n", $text);

        foreach ($lines as $line) {
            if (preg_match('/^\[(\d+)\]:\s*(.*)$/', $line, $matches)) {
                if ($currentIndex !== null) {
                    $t = trim(implode("\n", $currentText));
                    if ($t !== '') {
                        $result[] = ['index' => $currentIndex, 'text' => $t];
                    }
                }
                $currentIndex = $matches[1];
                $currentText = $matches[2] !== '' ? [$matches[2]] : [];
            } elseif ($currentIndex !== null) {
                $currentText[] = $line;
            }
        }

        if ($currentIndex !== null) {
            $t = trim(implode("\n", $currentText));
            if ($t !== '') {
                $result[] = ['index' => $currentIndex, 'text' => $t];
            }
        }

        if (empty($result)) {
            $preview = mb_substr($text, 0, 300);
            throw new \RuntimeException("Failed to parse simple format from response. Preview: {$preview}");
        }

        return $result;
    }

    private function isListOfDicts(array $data): bool
    {
        if (empty($data)) {
            return false;
        }
        foreach ($data as $item) {
            if (!is_array($item) || array_keys($item) === range(0, count($item) - 1)) {
                return false;
            }
        }
        return true;
    }

    private function validateBatch(array $translated, array $original): array
    {
        $originalIndexes = array_column($original, 'index');
        $originalByIndex = [];
        foreach ($original as $item) {
            $originalByIndex[$item['index']] = $item;
        }

        $expectedMinIndex = min(array_map('intval', $originalIndexes));
        $expectedMaxIndex = max(array_map('intval', $originalIndexes));
        $expectedCount = count($original);

        $valid = [];
        $seenIndexes = [];
        $duplicates = [];
        $returnedIndexes = [];

        foreach ($translated as $line) {
            if (!isset($line['index']) || !isset($line['text'])) {
                continue;
            }
            if (!in_array($line['index'], $originalIndexes, true)) {
                continue;
            }

            $idx = (int)$line['index'];
            $returnedIndexes[] = $idx;

            if (isset($seenIndexes[$line['index']])) {
                $duplicates[] = $line['index'];
            }
            $seenIndexes[$line['index']] = true;

            if ($line['text'] === '' && ($originalByIndex[$line['index']]['text'] ?? '') !== '') {
                $line['text'] = $originalByIndex[$line['index']]['text'];
            }
            $valid[] = $line;
        }

        if (empty($valid)) {
            throw new \RuntimeException("No valid translations in response");
        }

        $issues = [];

        if (!empty($duplicates)) {
            $issues[] = "duplicates: " . implode(',', array_unique($duplicates));
        }

        sort($returnedIndexes);
        $returnedMin = $returnedIndexes[0] ?? $expectedMinIndex;
        if ($returnedMin > $expectedMinIndex) {
            $skipped = $returnedMin - $expectedMinIndex;
            $issues[] = "skipped first {$skipped}";
        }

        $returnedSet = array_flip($returnedIndexes);
        $expectedSet = range($expectedMinIndex, $expectedMaxIndex);
        $missingIndexes = [];
        foreach ($expectedSet as $idx) {
            if (!isset($returnedSet[$idx])) {
                $missingIndexes[] = $idx;
            }
        }
        if (count($missingIndexes) > 0 && count($missingIndexes) < $expectedCount) {
            $issues[] = "missing: " . implode(',', array_slice($missingIndexes, 0, 5)) . (count($missingIndexes) > 5 ? "..." : "");
        }

        return [
            'valid' => $valid,
            'issues' => $issues,
            'validCount' => count($valid),
            'expectedCount' => $expectedCount,
            'missingIndexes' => $missingIndexes ?? [],
        ];
    }

    private function isDominantRtl(string $text): bool
    {
        $rtlCount = 0;
        $ltrCount = 0;

        $len = mb_strlen($text);
        for ($k = 0; $k < $len; $k++) {
            $char = mb_substr($text, $k, 1);
            $code = mb_ord($char);

            if (($code >= 0x0590 && $code <= 0x05FF) ||
                ($code >= 0x0600 && $code <= 0x06FF) ||
                ($code >= 0x0750 && $code <= 0x077F) ||
                ($code >= 0x08A0 && $code <= 0x08FF) ||
                ($code >= 0xFB1D && $code <= 0xFB4F) ||
                ($code >= 0xFB50 && $code <= 0xFDFF) ||
                ($code >= 0xFE70 && $code <= 0xFEFF)) {
                $rtlCount++;
            } elseif (($code >= 0x0041 && $code <= 0x005A) ||
                      ($code >= 0x0061 && $code <= 0x007A) ||
                      ($code >= 0x00C0 && $code <= 0x024F)) {
                $ltrCount++;
            }
        }

        return $rtlCount > $ltrCount;
    }

    private function linesToText(array $lines): string
    {
        return implode("\n", $lines);
    }

    private function enforceMinInterval(): void
    {
        if ($this->lastRequestTime <= 0) {
            return;
        }

        $elapsed = microtime(true) - $this->lastRequestTime;
        $needed = $this->batchDelay;

        if ($elapsed < $needed) {
            $wait = ceil($needed - $elapsed);
            if ($this->debugMode) {
                echo "  [throttle: {$elapsed}s since last request, waiting {$wait}s]\n";
            }
            sleep((int)$wait);
        }
    }

    private function textToLines(string $text): array
    {
        $text = stripcslashes($text);
        return explode("\n", $text);
    }

    private function extractHtmlTags(string $text): array
    {
        $tags = [];
        if (preg_match_all('/<\/?[a-zA-Z][^>]*>/', $text, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $tags[] = [
                    'tag' => $match[0],
                    'offset' => $match[1],
                ];
            }
        }
        return $tags;
    }

    private function reinsertHtmlTags(string $translatedText, array $tags): string
    {
        if (empty($tags)) {
            return $translatedText;
        }

        $openTags = [];
        $closeTags = [];
        foreach ($tags as $t) {
            if (str_starts_with($t['tag'], '</')) {
                $closeTags[] = $t['tag'];
            } else {
                $openTags[] = $t['tag'];
            }
        }

        return implode('', $openTags) . $translatedText . implode('', $closeTags);
    }

    private function estimateTokens(string $text): int
    {
        return (int)ceil(mb_strlen($text) / 4);
    }

    private function fitBatchToContext(array $allSubs, int $startIdx, int $requestedBatchSize, string $systemPrompt): int
    {
        $availableContext = (int)($this->contextWindow * 0.9);
        $systemTokens = $this->estimateTokens($systemPrompt);
        $budgetForInput = $availableContext - $systemTokens - $this->maxTokens;

        if ($budgetForInput <= 0) {
            echo "Warning: System prompt + max_tokens already exceeds context window. Using batch size 1.\n";
            return 1;
        }

        $total = count($allSubs);
        $batch = [];
        for ($j = $startIdx; $j < min($startIdx + $requestedBatchSize, $total); $j++) {
            $sub = $allSubs[$j];
            $text = $this->linesToText($sub['lines']);
            $batch[] = ['index' => (string)$j, 'text' => $text];

            $batchJson = PromptBuilder::formatBatchAsJson($batch);
            $inputTokens = $this->estimateTokens($batchJson);

            if ($inputTokens > $budgetForInput) {
                array_pop($batch);
                break;
            }
        }

        $fitted = count($batch);
        if ($fitted === 0) {
            return 1;
        }

        if ($fitted < $requestedBatchSize && $fitted < ($total - $startIdx)) {
            echo "Batch size reduced from {$requestedBatchSize} to {$fitted} to fit context window ({$this->contextWindow} tokens).\n";
        }

        return $fitted;
    }

    private function saveProgress(string $path, int $index, array $translations): void
    {
        $data = [
            'index' => $index,
            'model' => $this->modelKey,
            'target_language' => $this->targetLanguage,
            'translations' => $translations,
        ];
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    public static function listLanguages(string $modelKey, ?string $configPath = null): array
    {
        $configPath = $configPath ?? __DIR__ . '/../llm-models.json';
        $configJson = file_get_contents($configPath);
        if ($configJson === false) {
            throw new \RuntimeException("Cannot read config file: {$configPath}");
        }
        $config = json_decode($configJson, true);
        if (!isset($config['models'][$modelKey])) {
            throw new \RuntimeException("Unknown model: {$modelKey}. Available: " . implode(', ', array_keys($config['models'])));
        }
        return $config['models'][$modelKey]['languages'] ?? [];
    }

    private function logTokenUsage(): void
    {
        if ($this->totalInputTokens === 0 && $this->totalOutputTokens === 0) {
            echo "Token usage: not available from API\n";
            return;
        }

        $inputCost = ($this->totalInputTokens / 1_000_000) * $this->modelConfig['input_cost_per_million'];
        $outputCost = ($this->totalOutputTokens / 1_000_000) * $this->modelConfig['output_cost_per_million'];
        $thinkCost = ($this->totalThinkTokens / 1_000_000) * $this->modelConfig['output_cost_per_million'];
        $totalCost = $inputCost + $outputCost + $thinkCost;

        $outputParts = [];
        $costParts = [];
        $outputParts[] = sprintf("%d output", $this->totalOutputTokens);
        $costParts[] = sprintf("\$%.4f output", $outputCost);

        if ($this->totalThinkTokens > 0) {
            $outputParts[] = sprintf("%d think", $this->totalThinkTokens);
            $costParts[] = sprintf("\$%.4f think", $thinkCost);
        }

        echo sprintf(
            "Token usage: %d input, %s\n",
            $this->totalInputTokens,
            implode(", ", $outputParts)
        );
        echo sprintf(
            "Estimated cost: \$%.4f (input: \$%.4f, %s)\n",
            $totalCost,
            $inputCost,
            implode(", ", $costParts)
        );

        echo sprintf(
            "API calls: %d total, %d partial\n",
            $this->totalApiCalls,
            $this->partialBatches
        );
    }
}
