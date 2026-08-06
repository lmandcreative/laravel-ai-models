# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| 1.x     | ✅        |

Only the latest `1.x` release receives security fixes.

## Reporting a Vulnerability

Please **do not** open a public GitHub issue for security vulnerabilities.

Report privately instead, using either of:

- Email: [niko.peltoniemi@lmsomeco.fi](mailto:niko.peltoniemi@lmsomeco.fi)
- [GitHub Security Advisories](https://github.com/lmandcreative/laravel-ai-models/security/advisories/new)
  for this repository.

Include as much detail as you can (affected version, reproduction steps,
impact) so the report can be triaged quickly. You should expect an initial
response within a few business days.

## Notes on Data Handling

- Database connector credentials (`AiConnector::$api_key`) are stored using
  Laravel's `encrypted` cast — the column is encrypted at rest using the
  application's `APP_KEY`, and decrypted only in memory when a resolver needs
  it to make a request.
- This package does not store or duplicate credentials from `config/ai.php`;
  it reads them at runtime and only caches provider **model lists** (never
  credentials) via the configured cache store.
