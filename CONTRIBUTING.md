# Contributing

Thank you for contributing to Acceptora Verification for Laravel.

## Development setup

The package supports Laravel 12 on PHP 8.2 through 8.5 and Laravel 13 on PHP 8.3 through 8.5. Install dependencies with Composer 2:

```bash
composer install
```

Run the complete package check before submitting a change:

```bash
composer check
```

Tests must use deterministic mock HTTP handlers and must not make requests to a live Acceptora instance.

## Contract compatibility

When changing support for an Acceptora API or MCP contract version, keep this repository's client methods, bundled contract metadata, request and response handling, tests and fixtures, README, and changelog consistent.

To verify manifest digests against an authoritative Acceptora contract checkout, set `ACCEPTORA_CONTRACTS_V1_PATH` to its `contracts/v1` directory and run `vendor/bin/phpunit tests/ContractParityTest.php`. The parity test is skipped when that separate contract source is not available.

Backward-incompatible public API changes require a new major package version. Additive public API changes require a new minor version, and backward-compatible fixes require a patch version.

## Pull requests

- Keep each pull request focused on one coherent change.
- Add tests for successful responses, transport failures, API failures, and relevant edge cases.
- Preserve safe handling of bearer tokens, payloads, and error context.
- Document user-visible behavior and compatibility changes.
- Do not commit credentials, environment files, generated coverage, or Composer vendor files.
