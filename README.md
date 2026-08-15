# zai-srt-translate

> ## ⚠️ Project discontinued
>
> This project is **no longer maintained**. Extensive testing concluded that GLM models are not usable for production subtitle translation.
>
> **Why it failed:** Subtitle cues are segmented by timing and reading speed, not by grammar — consecutive cues often form a single sentence split across 2–3 cues. A translator model deterministically merges such cues, because splitting a sentence across separate output units is linguistically unnatural. The result is translated text silently shifted against the original timestamps. Every separator strategy (`|||`, indexed markers, newlines, paragraphs), prompt instruction, and retry/fallback scheme was tested; none made batch translation reliable, and retries always hit the same merge. The failure is structural, not stochastic.
>
> The only known fix is schema-enforced structured output (index-keyed JSON constrained at the token level, e.g. OpenAI's `json_schema strict` or Gemini's `response_schema`) — which the Z.AI API does not offer. Its `json_object` mode guarantees valid JSON but leaves the schema prompt-guided, which is not enough.
>
> **Successor:** `llm-srt-translate` — the same tooling generalized to any OpenAI-compatible API (via a LiteLLM proxy), using schema-enforced structured output where the upstream model supports it.

A PHP CLI tool that uses [Z.AI](https://z.ai) LLMs (GLM series) to translate subtitle files (SRT, VTT, ASS, and more) into 100+ languages.

This is a port of [cf-llm-srt-translator](https://github.com/iceman1010/cf-llm-srt-translator), adapted from Cloudflare Workers AI to the Z.AI API platform while preserving the same prompt logic, batch processing, and error handling approach.

## Features

- **14 GLM models** available — from flagship `glm-5.2` to lightweight `glm-4-32b`
- **Up to 103 languages** supported (varies by model quality tier)
- **Smart batching** — sends subtitles in large batches for fast throughput
- **Parallel batch mode** — `--parallel=N` runs N batches concurrently via curl_multi for dramatic speedups
- **Checkpoint/resume** — parallel mode auto-saves checkpoints per-batch; `--resume` recovers from interruptions with zero wasted tokens
- **Automatic retry for partial batches** — missing subtitles from incomplete responses are immediately retried
- **Dynamic token scaling** — output token limit adjusts automatically based on batch size
- **Progress resume** — sequential mode saves state to `.progress` file; resume after interruption
- **Rate limit handling** — exponential backoff (30s → 300s cap) without counting against abort limit
- **Stale checkpoint cleanup** — orphaned checkpoints older than 24h auto-removed at start of each parallel run
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
| `--delay=<secs>` | `-D` | Delay between batches in seconds (sequential mode, default: 60) |
| `--log=<file>` | `-L` | Log full request/response to file for debugging |
| `--parallel[=N]` | | Parallel batch mode: N concurrent requests (default: 3) |
| `--resume` | | Resume from checkpoints (requires `--parallel`) |
| `--restart` | | Start from beginning, ignore saved progress/checkpoints |
| `--log-issues` | | Dump batch diagnostics when entries are skipped/missing/duplicate to `<input>.<model>.issues.log` (see [Troubleshooting](#troubleshooting)) |
| `--debug` | `-v` | Show system prompt and first user message |
| `--list-models` | | List all available models and exit |
| `--list-languages` | | List supported languages for a model |
| `--setup-api` | | Configure API key interactively |
| `--update[=version]` | | Self-update to latest or a specific version (PHAR only) |
| `--version` | | Show version |

### Parallel Mode

For faster translations, use `--parallel` to run multiple batches concurrently:

```bash
# 3 concurrent batches (default)
zai-srt-translate -i movie.srt -l German -m glm-5.2 --parallel

# 5 concurrent batches
zai-srt-translate -i movie.srt -l German -m glm-5.2 --parallel=5
```

Parallel mode automatically saves checkpoints to `/tmp/zai-translate-{hash}/` after each completed batch. If the process is interrupted (crash, kill, server reboot), re-run the same command with `--resume` to pick up exactly where it left off — completed batches are loaded from disk with zero token cost:

```bash
# Original run was interrupted:
zai-srt-translate -i movie.srt -l German -m glm-5.2 --parallel=3

# Resume:
zai-srt-translate -i movie.srt -l German -m glm-5.2 --parallel=3 --resume
```

Stale checkpoint directories older than 24 hours are automatically cleaned up at the start of each parallel run.

> **Note:** Free models (`glm-4.7-flash`, `glm-4.5-flash`) have strict rate limits that effectively prevent parallel execution. Use a paid model for `--parallel` mode.

### Examples

```bash
# Translate to German with the default model
php translate.php -i movie.srt -l de

# Translate to Japanese using glm-5.2 with reasoning enabled
php translate.php -i movie.srt -l Japanese -m glm-5.2 --think

# Translate to French with custom batch size
php translate.php -i movie.srt -l fr -b 100

# Translate to Arabic with explicit output file
php translate.php -i movie.srt -l ar -o movie.arabic.srt

# Translate to Spanish with additional context
php translate.php -i documentary.srt -l es -d "Nature documentary about marine life"

# Fast parallel translation (paid model)
php translate.php -i movie.srt -l de -m glm-5.2 --parallel=5

# List available models
php translate.php --list-models

# List languages for a specific model
php translate.php --list-languages -m glm-5.2
```

## Available Models

| Model Key | Model ID | Context | Reasoning | Languages | Cost (in/out per M) | Notes |
|-----------|----------|---------|-----------|-----------|-------------------|-------|
| `glm-5.2` | glm-5.2 | 128K | Yes | 103 | $1.40 / $4.40 | **Recommended** — best quality |
| `glm-5.1` | glm-5.1 | 128K | Yes | 103 | $1.40 / $4.40 | High quality |
| `glm-5` | glm-5 | 128K | Yes | 103 | $1.00 / $3.20 | GLM-5 series |
| `glm-5-turbo` | glm-5-turbo | 128K | Yes | 103 | $1.20 / $4.00 | Fast reasoning |
| `glm-4.7` | glm-4.7 | 128K | Yes | 85 | $0.60 / $2.20 | Good quality, lower cost |
| `glm-4.7-flash` | glm-4.7-flash | 128K | Yes | 85 | Free | Fast, rate-limited |
| `glm-4.7-flashx` | glm-4.7-flashx | 128K | No | 30 | $0.07 / $0.40 | Cheapest paid |
| `glm-4.6` | glm-4.6 | 128K | No | 85 | $0.60 / $2.20 | GLM-4.6 |
| `glm-4.5` | glm-4.5 | 128K | Auto | 30 | $0.60 / $2.20 | |
| `glm-4.5-air` | glm-4.5-air | 128K | Auto | 30 | $0.20 / $1.10 | Lightweight |
| `glm-4.5-x` | glm-4.5-x | 128K | Auto | 30 | $2.20 / $8.90 | Extended |
| `glm-4.5-airx` | glm-4.5-airx | 128K | Auto | 30 | $1.10 / $4.50 | Air extended |
| `glm-4.5-flash` | glm-4.5-flash | 128K | Auto | 30 | Free | Rate-limited |
| `glm-4-32b` | glm-4-32b-0414-128k | 128K | No | 30 | $0.10 / $0.10 | Cheapest, low quality |

> **Tip:** `glm-5.2` is recommended for production use — best quality, supports `--parallel`. Use `--think` for maximum quality on difficult translations. Free models (`glm-4.7-flash`, `glm-4.5-flash`) are rate-limited and not suitable for parallel mode.

## Supported Languages

Language support varies by model quality tier:

- **GLM-5.x** (glm-5.2, glm-5.1, glm-5, glm-5-turbo): **103 languages**
- **GLM-4.7/4.6** (glm-4.7, glm-4.7-flash, glm-4.6): **85 languages**
- **GLM-4.5 and below** (7 models): **30 core languages**

Core languages (all models): English, Chinese, Japanese, Korean, French, German, Spanish, Portuguese, Italian, Dutch, Russian, Arabic, Hindi, Turkish, Vietnamese, Thai, Indonesian, Malay, Polish, Swedish, Danish, Norwegian, Finnish, Czech, Greek, Hebrew, Romanian, Hungarian, Ukrainian, Bulgarian.

Run `--list-languages -m <model>` to see the full list for a specific model.

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

### Sequential Mode

If a translation is interrupted (Ctrl+C, network error, etc.), a `.progress` file is saved next to the input file. Re-running the same command resumes from where it left off:

```bash
# First run (gets interrupted)
php translate.php -i movie.srt -l de

# Resume (picks up from last completed batch)
php translate.php -i movie.srt -l de
```

The progress file tracks the model and target language — changing either starts fresh. Use `--restart` to clear progress and start over.

### Parallel Mode

Parallel mode (`--parallel`) saves checkpoints per-batch to `/tmp/`. If interrupted, use `--resume`:

```bash
# First run (gets interrupted)
php translate.php -i movie.srt -l de -m glm-5.2 --parallel=3

# Resume (loads completed batches from disk, zero tokens re-spent)
php translate.php -i movie.srt -l de -m glm-5.2 --parallel=3 --resume
```

Checkpoints are auto-cleaned on successful completion. Stale checkpoints (>24h) are removed automatically.

## Troubleshooting

### Translated subtitles appear at the wrong timestamps (text/timestamp misalignment)

If a translated file plays but the visible text doesn't match the timing of the original — e.g. the line shown at `00:10:04` is the translation of a line the original showed at `00:10:07` — the model has most likely **merged two or more adjacent cues** in a single batch response and renumbered the subsequent entries, causing a silent cumulative shift. The timestamps in the file are byte-identical to the source; only the text is misaligned.

To diagnose, run with `--log-issues` (and ideally `--log` for the full request/response trail):

```bash
php translate.php -i movie.srt -l nl -m glm-5.2 --log-issues --log /tmp/apilog.txt
```

This writes `<input>.<model>.issues.log` whenever any batch comes back with skipped, missing, or duplicate entries. Each entry shows the expected index range, a side-by-side `expected-text` vs `returned-text` table, and the first 3000 chars of the raw model response — so you can pinpoint the exact cue where the model drifted.

For the most complete forensic trail, combine `--log-issues` with `--log <file>` (logs every API request and response) and `-v` (prints the system prompt). Note that `--log-issues` only catches the *detectable* cases (missing/duplicate indices); a pure text-shift without index loss is not catchable without semantic comparison of source and translation.

### Numbering gaps in the source file

If the input SRT has a numbering gap (e.g. entry `377` is missing, the file jumps `376` → `378`), the output will be renumbered contiguously (`1, 2, 3, ...`). This is cosmetic — subtitle players schedule cues by timestamp, not by index — and is **not** a cause of drift. Pair the original and the translated file by timestamp value to verify alignment.

## Self-Update (PHAR only)

```bash
zai-srt-translate --update
zai-srt-translate --update=1.8.0
```

Checks GitHub for the latest release (or a specific version) and replaces the PHAR in-place.

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
