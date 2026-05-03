# zai-srt-translate

A PHP CLI tool that uses [Z.AI](https://z.ai) LLMs (GLM series) to translate subtitle files (SRT, VTT, ASS, and more) into 100+ languages.

This is a port of [cf-llm-srt-translator](https://github.com/iceman1010/cf-llm-srt-translator), adapted from Cloudflare Workers AI to the Z.AI API platform while preserving the same prompt logic, batch processing, and error handling approach.

## Features

- **13 GLM models** available — from flagship `glm-5.1` to lightweight `glm-4.5-flash`
- **102 languages** supported (ISO 639 codes and full language names)
- **Smart batching** — sends subtitles in large batches for fast throughput
- **Automatic retry for partial batches** — missing subtitles from incomplete responses are immediately retried
- **Dynamic token scaling** — output token limit adjusts automatically based on batch size
- **Progress resume** — translation state saved to `.progress` file; resume after interruption
- **Rate limit handling** — exponential backoff (30s → 300s cap) without counting against abort limit
- **RTL support** — automatic BiDi Unicode wrapping for Arabic, Hebrew, Persian, etc.
- **HTML tag preservation** — `<i>`, `<b>` tags in subtitles are preserved through translation
- **Subtitle format support** — SRT, VTT, ASS, and any format supported by [mantas-done/subtitles](https://github.com/mantas-done/subtitles)
- **PHAR build** — single-file executable, self-update via GitHub releases

## Requirements

- PHP >= 8.1
- ext-curl, ext-json, ext-mbstring
- A Z.AI API key (create one at [z.ai/manage-apikey](https://z.ai/manage-apikey/apikey-list))

## Installation

### Download PHAR (recommended)

Grab the latest release from [GitHub Releases](https://github.com/iceman1010/glm-srt-translate/releases):

```bash
chmod +x zai-srt-translate.phar
sudo mv zai-srt-translate.phar /usr/local/bin/zai-srt-translate
```

### From Source

```bash
git clone https://github.com/iceman1010/glm-srt-translate.git
cd glm-srt-translate
composer install
```

## Setup

### Quick Setup (interactive)

```bash
php translate.php --setup-api
```

This prompts for your Z.AI API key and saves it to `~/.zai-srt-translate/.env`.

### Manual Setup

Create a `.env` file in the project directory:

```
ZAI_API_KEY=your_api_key_here
```

Or set the environment variable directly:

```bash
export ZAI_API_KEY=your_api_key_here
```

## Usage

### Basic Translation

```bash
# Using a PHAR install
zai-srt-translate --input=movie.srt --language=German

# From source
php translate.php --input=movie.srt --language=German
```

This creates `movie.german.srt` in the same directory.

### Language Input

Languages can be specified as:
- Full name: `German`, `French`, `Japanese`
- ISO 639-1 code: `de`, `fr`, `ja`
- ISO 639-2 code: `deu`, `fra`, `jpn`

### Options

| Option | Short | Description |
|--------|-------|-------------|
| `--input=<file>` | `-i` | Input subtitle file (required) |
| `--language=<lang>` | `-l` | Target language (required) |
| `--output=<file>` | `-o` | Output file path (default: auto-generated) |
| `--model=<key>` | `-m` | Model to use (default: `glm-4.7-flash`) |
| `--batch-size=<n>` | `-b` | Override batch size |
| `--temperature=<f>` | `-t` | Sampling temperature 0.0–1.0 (default: 0.6) |
| `--max-tokens=<n>` | `-M` | Override max output tokens |
| `--description=<text>` | `-d` | Additional context for the translator |
| `--think` | `-r` | Force reasoning/thinking mode on supported models |
| `--retry=<n>` | `-R` | Retry count for merged content (default: 1) |
| `--format=<fmt>` | `-f` | Response format: `simple` or `json` (default: `simple`) |
| `--debug` | `-v` | Show system prompt and first user message |
| `--list-models` | | List all available models and exit |
| `--list-languages` | | List supported languages for a model |
| `--setup-api` | | Configure API key interactively |
| `--update` | | Self-update to latest release (PHAR only) |
| `--version` | | Show version |

### Examples

```bash
# Translate to German with the default model
php translate.php -i movie.srt -l de

# Translate to Japanese using glm-5.1 with reasoning enabled
php translate.php -i movie.srt -l Japanese -m glm-5.1 --think

# Translate to French with custom batch size
php translate.php -i movie.srt -l fr -b 100

# Translate to Arabic with explicit output file
php translate.php -i movie.srt -l ar -o movie.arabic.srt

# Translate to Spanish with additional context
php translate.php -i documentary.srt -l es -d "Nature documentary about marine life"

# List available models
php translate.php --list-models

# List languages for a specific model
php translate.php --list-languages -m glm-4.5-flash
```

## Available Models

| Model Key | Model ID | Context | Reasoning | Notes |
|-----------|----------|---------|-----------|-------|
| `glm-5.1` | glm-5.1 | 128K | Yes | Flagship model |
| `glm-5-turbo` | glm-5-turbo | 128K | Yes | Fast reasoning |
| `glm-5` | glm-5 | 128K | Yes | GLM-5 series |
| `glm-4.7` | glm-4.7 | 128K | Yes | GLM-4.7 |
| `glm-4.7-flash` | glm-4.7-flash | 128K | Yes | **Recommended** — fast, good quality |
| `glm-4.7-flashx` | glm-4.7-flashx | 128K | No | Fastest, no reasoning overhead |
| `glm-4.6` | glm-4.6 | 128K | No | GLM-4.6 |
| `glm-4.5` | glm-4.5 | 128K | Auto | Automatic thinking |
| `glm-4.5-air` | glm-4.5-air | 128K | Auto | Lightweight |
| `glm-4.5-x` | glm-4.5-x | 128K | Auto | Extended |
| `glm-4.5-airx` | glm-4.5-airx | 128K | Auto | Air extended |
| `glm-4.5-flash` | glm-4.5-flash | 128K | Auto | Flash model |
| `glm-4-32b` | glm-4-32b-0414-128k | 128K | No | 32B parameter model |

> **Tip:** `glm-4.7-flash` is the recommended default — it offers a good balance of speed, quality, and reliability. Use `glm-5.1` with `--think` for maximum quality on difficult translations.

## Supported Languages

102 languages are supported. Here are some commonly used ones:

`af` Afrikaans, `ar` Arabic, `az` Azerbaijani, `be` Belarusian, `bg` Bulgarian, `bn` Bengali, `bs` Bosnian, `ca` Catalan, `cs` Czech, `cy` Welsh, `da` Danish, `de` German, `el` Greek, `en` English, `es` Spanish, `et` Estonian, `eu` Basque, `fa` Persian, `fi` Finnish, `fr` French, `ga` Irish, `gl` Galician, `gu` Gujarati, `he` Hebrew, `hi` Hindi, `hr` Croatian, `hu` Hungarian, `hy` Armenian, `id` Indonesian, `is` Icelandic, `it` Italian, `ja` Japanese, `ka` Georgian, `kk` Kazakh, `km` Khmer, `ko` Korean, `lo` Lao, `lt` Lithuanian, `lv` Latvian, `mk` Macedonian, `ml` Malayalam, `mn` Mongolian, `mr` Marathi, `ms` Malay, `mt` Maltese, `my` Burmese, `nl` Dutch, `no` Norwegian, `pa` Panjabi, `pl` Polish, `pt` Portuguese, `ro` Romanian, `ru` Russian, `sk` Slovak, `sl` Slovenian, `sq` Albanian, `sr` Serbian, `sv` Swedish, `sw` Swahili, `ta` Tamil, `te` Telugu, `tg` Tajik, `th` Thai, `tk` Turkmen, `tl` Tagalog, `tr` Turkish, `uk` Ukrainian, `ur` Urdu, `uz` Uzbek, `vi` Vietnamese, `yi` Yiddish, `zh` Chinese, `zu` Zulu

Run `--list-languages` with a model to see the full list.

## How It Works

1. Parses the input subtitle file using `mantas-done/subtitles`
2. Groups subtitles into batches (default 150–250 depending on model)
3. Sends each batch to the Z.AI Chat Completion API with a translation prompt
4. Parses the response (simple text format or JSON), validates index coverage
5. If subtitles are missing from the response, immediately retries them in a follow-up request
6. Applies RTL BiDi wrapping for right-to-left languages
7. Saves progress after each batch — safe to interrupt and resume

## Error Handling

| Scenario | Behavior |
|----------|----------|
| Rate limit (HTTP 429) | Exponential backoff (30s → 60s → 120s → 240s → 300s cap). Does not count toward abort limit. |
| Partial batch response | Missing subtitles are retried immediately in a separate request. |
| Timeout | Batch size halved, retry after 10s. Counts toward 3-strike abort. |
| Server error (500/503) | Retry after 60s. Counts toward 3-strike abort. |
| Auth error (401/403) | Abort immediately with clear message. |
| 3 consecutive non-rate-limit errors | Save progress and abort. Resume with same command. |

## Resume Support

If a translation is interrupted (Ctrl+C, network error, etc.), a `.progress` file is saved next to the input file. Re-running the same command resumes from where it left off:

```bash
# First run (gets interrupted)
php translate.php -i movie.srt -l de

# Resume (picks up from last completed batch)
php translate.php -i movie.srt -l de
```

The progress file tracks the model and target language — changing either starts fresh.

## Self-Update (PHAR only)

```bash
zai-srt-translate --update
```

Checks GitHub for the latest release and replaces the PHAR in-place.

## Building from Source

```bash
composer install
./install_local.sh
```

This produces `zai-srt-translate.phar` in the project root.

## Architecture

```
glm-srt-translate/
├── bin/translate          # CLI entry point
├── src/
│   ├── ZAiClient.php      # Z.AI Chat Completion API client
│   ├── PromptBuilder.php  # Translation prompts (simple + JSON formats)
│   └── Translator.php     # Main orchestrator (batching, retry, progress)
├── llm-models.json        # Model registry and API configuration
├── VERSION                # Current version (read by PHAR)
├── box.json               # PHAR build configuration
└── translate.php          # Convenience wrapper → bin/translate
```

## Credits

- Ported from [cf-llm-srt-translator](https://github.com/iceman1010/cf-llm-srt-translator) (Cloudflare Workers AI version)
- Powered by [Z.AI](https://z.ai) GLM models
- Subtitle parsing by [mantas-done/subtitles](https://github.com/mantas-done/subtitles)

## License

MIT
