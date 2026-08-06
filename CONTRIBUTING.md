# Contributing

Thanks for considering a contribution to `lmsomeco/laravel-ai-models`.

## Dev setup

```bash
git clone https://github.com/lmandcreative/laravel-ai-models.git
cd laravel-ai-models
composer install
```

No `.env`, database, or API keys are required to run the test suite — tests
fake HTTP calls and use an in-memory SQLite database where needed.

## Commands

| Command                | Does |
|--------------------------|------|
| `composer test`            | Run the Pest test suite. |
| `composer lint`            | Check code style with Pint (no changes made). |
| `composer lint:fix`        | Auto-fix code style with Pint. |
| `composer analyse`         | Run static analysis with PHPStan/Larastan. |
| `composer check`           | Run `lint`, then `analyse`, then `test` — the full gate. |

**Windows note:** these all work as-is in PowerShell or Git Bash — Composer
resolves the `vendor\bin\*.bat` shims for `pest`/`pint`/`phpstan`
automatically, no extra flags needed.

## Test suite layout

- `tests/Unit/` — pure unit tests (DTO, individual resolvers via
  `Http::fake()`), no database.
- `tests/Feature/` — `ModelRegistry`, the `ai:models` command, and dynamic
  (non-database) connector behavior.
- `tests/Database/` — anything touching `AiConnector`/`ConnectorManager`,
  backed by an in-memory SQLite connection (`DatabaseTestCase`).

See `resources/boost/skills/ai-models-development/SKILL.md` for a fuller
architecture walkthrough if you're adding a provider resolver.

## Before opening a PR

- Add or update tests for any behavior change.
- `composer check` passes locally.
- Add an entry under `## [Unreleased]` in `CHANGELOG.md`.
- Update `docs/` (and `README.md` if the public API changed).

## Good first contributions

Several providers are mapped in `laravel/ai` but have no resolver here yet —
see the table in [`docs/extending.md`](docs/extending.md) for the list
(Gemini, Ollama, Azure, Cohere, ElevenLabs, Bedrock, Jina, VoyageAI) and the
per-provider quirks each one needs handled. Each is a self-contained,
well-scoped PR: implement `ProviderResolver` (or extend
`OpenAiCompatibleResolver` where it fits), map it in
`config/ai-models.php`, and add resolver tests following the existing
`tests/Unit/Resolvers/*Test.php` pattern.

## Questions / bugs

Open an issue at
[github.com/lmandcreative/laravel-ai-models/issues](https://github.com/lmandcreative/laravel-ai-models/issues).
For security issues, see [SECURITY.md](SECURITY.md) instead of a public issue.
