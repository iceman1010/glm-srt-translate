<?php

require_once __DIR__ . '/vendor/autoload.php';

define('VERSION', 'v1.0.0');

use ZAiSrt\Translator;
use Dotenv\Dotenv;
use WhiteCube\Lingua\Service as Lingua;

$shortOpts = 'i:o:l:m:b:t:M:drR:f:v';
$longOpts = [
    'input:',
    'output::',
    'language:',
    'model::',
    'batch-size::',
    'temperature::',
    'max-tokens::',
    'description::',
    'think',
    'retry::',
    'list-models',
    'list-languages',
    'update',
    'setup-api',
    'version',
    'format:',
    'debug',
];
$options = getopt($shortOpts, $longOpts);

$shortToLong = [
    'i' => 'input',
    'o' => 'output',
    'l' => 'language',
    'm' => 'model',
    'b' => 'batch-size',
    't' => 'temperature',
    'M' => 'max-tokens',
    'd' => 'description',
    'r' => 'think',
    'R' => 'retry',
    'f' => 'format',
    'v' => 'debug',
];
foreach ($shortToLong as $short => $long) {
    if (isset($options[$short])) {
        $options[$long] = $options[$short];
        unset($options[$short]);
    }
}

if (isset($options['version'])) {
    echo VERSION . "\n";
    exit(0);
}

if (isset($options['setup-api'])) {
    $globalDir = getenv('HOME') . '/.zai-srt-translate';
    $globalEnv = $globalDir . '/.env';

    $currentKey = getenv('ZAI_API_KEY');

    if (empty($currentKey)) {
        $envDir = str_starts_with(__DIR__, 'phar://') ? getcwd() : __DIR__;
        if (file_exists($envDir . '/.env')) {
            Dotenv::createImmutable($envDir)->load();
            $currentKey = $currentKey ?: ($_ENV['ZAI_API_KEY'] ?? '');
        }
    }
    if (empty($currentKey)) {
        if (file_exists($globalEnv)) {
            Dotenv::createImmutable($globalDir)->load();
            $currentKey = $currentKey ?: ($_ENV['ZAI_API_KEY'] ?? '');
        }
    }

    $hasCredentials = !empty($currentKey);

    if ($hasCredentials) {
        $masked = substr($currentKey, 0, 8) . '****' . substr($currentKey, -4);
        echo "\nCurrent API key found:\n";
        echo "  API Key: {$masked}\n\n";

        echo "Change credentials? [y/N]: ";
        $answer = trim(fgets(STDIN));
        if (strtolower($answer) !== 'y') {
            echo "Credentials kept as-is.\n";
            exit(0);
        }
        echo "\n";
    } else {
        echo "\nNo credentials found.\n\n";
    }

    $maxAttempts = 3;
    $apiKey = '';

    for ($i = 0; $i < $maxAttempts; $i++) {
        $apiKey = trim(readline("Z.AI API Key: "));

        if (!empty($apiKey)) {
            break;
        }
        echo "API key is required. Please try again.\n\n";
        if ($i === $maxAttempts - 1) {
            echo "Error: Too many invalid attempts.\n";
            exit(1);
        }
    }

    if (!is_dir($globalDir)) {
        if (!mkdir($globalDir, 0700, true)) {
            echo "Error: Cannot create directory {$globalDir}\n";
            exit(1);
        }
    }

    $content = "ZAI_API_KEY={$apiKey}\n";
    if (file_put_contents($globalEnv, $content) === false) {
        echo "Error: Cannot write to {$globalEnv}\n";
        exit(1);
    }
    chmod($globalEnv, 0600);

    echo "\nCredentials saved to: {$globalEnv}\n";
    exit(0);
}

if (isset($options['list-models'])) {
    $modelsPath = str_starts_with(__DIR__, 'phar://') ? 'phar://' . Phar::running(false) . '/llm-models.json' : __DIR__ . '/llm-models.json';
    $config = json_decode(file_get_contents($modelsPath), true);
    $models = $config['models'];

    echo "Available models:\n\n";
    foreach ($models as $key => $model) {
        $reasoning = $model['reasoning'] ? 'yes' : 'no';
        $langs = count($model['languages']);
        echo sprintf(
            "  %-20s context: %6dK  batch: %4d  reasoning: %-3s  languages: %d\n",
            $key,
            (int)($model['context_window'] / 1000),
            $model['batch_size'],
            $reasoning,
            $langs
        );
    }
    echo "\n";
    foreach ($models as $key => $model) {
        if (!empty($model['notes'])) {
            echo sprintf("  %-20s %s\n", $key, $model['notes']);
        }
    }
    exit(0);
}

if (isset($options['list-languages'])) {
    if (empty($options['model'])) {
        echo "Error: --model is required when using --list-languages.\n";
        echo "Usage: php translate.php --list-languages --model=<model_key>\n";
        exit(1);
    }
    try {
        $languages = Translator::listLanguages($options['model']);
        echo json_encode($languages) . "\n";
    } catch (\RuntimeException $e) {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
    exit(0);
}

if (isset($options['update'])) {
    $pharPath = Phar::running(false);
    if (empty($pharPath)) {
        echo "Error: --update can only be used with the PHAR build.\n";
        exit(1);
    }

    echo "Current version: " . VERSION . "\n";
    echo "Self-update is not yet available for this project.\n";
    exit(0);
}

if (empty(getenv('ZAI_API_KEY'))) {
    $envDir = str_starts_with(__DIR__, 'phar://') ? getcwd() : __DIR__;
    $loaded = false;

    if (file_exists($envDir . '/.env')) {
        Dotenv::createImmutable($envDir)->load();
        $loaded = true;
    }
    if (!$loaded) {
        $globalEnv = getenv('HOME') . '/.zai-srt-translate/.env';
        if (file_exists($globalEnv)) {
            Dotenv::createImmutable(dirname($globalEnv))->load();
            $loaded = true;
        }
    }
    if (!$loaded) {
        echo "Error: Z.AI API key not found.\n";
        echo "Run: php " . (Phar::running(false) ?: 'translate.php') . " --setup-api\n";
        exit(1);
    }
}

if (empty($options['input']) || empty($options['language'])) {
    echo "Usage: php translate.php -i <file> -l <language> [options]\n";
    echo "   or: php translate.php --input=<file> --language=<language> [options]\n\n";
    echo "Required:\n";
    echo "  -i <file>   --input=<file>          Input subtitle file (.srt, .vtt, .ass, etc.)\n";
    echo "  -l <lang>   --language=<lang>        Target language (e.g., German, de, DE)\n\n";
    echo "Optional:\n";
    echo "  -o <file>   --output=<file>          Output file path (default: auto-generated)\n";
    echo "  -m <key>    --model=<key>           Model key (default: glm-4.7-flash)\n";
    echo "              --list-models            List available models and exit\n";
    echo "  -b <n>      --batch-size=<n>         Override batch size from model config\n";
    echo "  -t <float>  --temperature=<float>    Override temperature (default: 0.6)\n";
    echo "  -M <n>      --max-tokens=<n>         Override max tokens (default: 8192)\n";
    echo "  -d <text>   --description=<text>     Additional context for translation\n";
    echo "  -r          --think                  Enable reasoning for reasoning models (higher quality, higher cost)\n";
    echo "  -R <n>      --retry=<n>              Number of retries on merged content (default: 1)\n";
    echo "  -f <fmt>    --format=<fmt>            Response format: json|simple (default: simple)\n";
    echo "  -v          --debug                 Show system prompt and first user message\n";
    exit(1);
}

$langInput = $options['language'];
if (preg_match('/^[a-z]{2,3}$/i', $langInput)) {
    $langInput = strtoupper($langInput);
}

try {
    $lingua = Lingua::create($langInput);
    $targetLanguage = ucfirst($lingua->toName());
} catch (\Exception $e) {
    echo "Error: Unknown language \"{$options['language']}\". Use a language name (e.g., French) or ISO code (e.g., de, DE, deu).\n";
    exit(1);
}

echo "Target language: {$targetLanguage}\n";

function resolveModelKey(string $input, array $availableModels): string
{
    $inputLower = strtolower($input);

    foreach ($availableModels as $key) {
        if (strtolower($key) === $inputLower) {
            return $key;
        }
    }

    $matches = [];
    foreach ($availableModels as $key) {
        if (str_starts_with(strtolower($key), $inputLower)) {
            $matches[] = $key;
        }
    }

    if (count($matches) === 1) {
        return $matches[0];
    }

    if (count($matches) > 1) {
        sort($matches);
        echo "Error: Ambiguous model \"{$input}\". Matches: " . implode(', ', $matches) . "\n";
        exit(1);
    }

    echo "Error: Unknown model \"{$input}\". Available models: " . implode(', ', $availableModels) . "\n";
    exit(1);
}

$modelsPath = str_starts_with(__DIR__, 'phar://') ? 'phar://' . Phar::running(false) . '/llm-models.json' : __DIR__ . '/llm-models.json';
$config = json_decode(file_get_contents($modelsPath), true);
$availableModels = array_keys($config['models']);

$modelInput = $options['model'] ?? 'glm-4.7-flash';
$resolvedModel = resolveModelKey($modelInput, $availableModels);

if ($resolvedModel !== $modelInput) {
    echo "Model resolved: {$modelInput} → {$resolvedModel}\n";
}

try {
    $translator = new Translator([
        'api_key' => $_ENV['ZAI_API_KEY'],
        'target_language' => $targetLanguage,
        'input_file' => $options['input'],
        'model' => $resolvedModel,
        'output_file' => $options['output'] ?? null,
        'batch_size' => isset($options['batch-size']) ? (int)$options['batch-size'] : null,
        'temperature' => isset($options['temperature']) ? (float)$options['temperature'] : null,
        'max_tokens' => isset($options['max-tokens']) ? (int)$options['max-tokens'] : null,
        'description' => $options['description'] ?? null,
        'think' => isset($options['think']),
        'retry' => isset($options['retry']) ? (int)$options['retry'] : 1,
        'format' => $options['format'] ?? 'simple',
        'debug' => isset($options['debug']),
    ]);

    $translator->translate();
} catch (\Exception $e) {
    echo "\nError: " . $e->getMessage() . "\n";
    exit(1);
}
