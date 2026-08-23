# Changelog

All notable changes to this package are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and releases follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

### Added

- Document the optional `evidence_sufficiency` and structured `blocker_reason` fields for automated evidence, and pin the updated reconciliation and verification-exception input schema digests.

### Changed

- Update the bundled compatibility identity and request metadata to Acceptora Agent Skill `1.1.0`; package, integration, contract, and server identities remain `1.0.0`.
- Document that verified Google-authenticated owners can create project credentials without configuring a local password.
- Remove the obsolete password-confirmation error code from the published compatibility metadata.

## [1.0.0] - 2026-08-11

### Added

- Laravel 12 and 13 integration for the Acceptora Verification API v1.
- A configurable client for health, contract-version, completion-gate, REST, and MCP requests.
- Named methods for all eight Acceptora Verification operations over MCP and versioned REST.
- Compatibility preflight checks and structured transport, API, and contract exceptions.
- Fail-closed URL and token validation, forced TLS certificate verification, redirect blocking, explicit timeouts, request and response byte caps, and bounded MCP pagination.
- Secret-safe structured logging and error redaction that does not retain authenticated Guzzle requests in exception chains.
- JSON and SSE MCP transport support for protocol `2025-11-25`, including session propagation and initialization notifications.
- Public OpenAPI 3.1 discovery through `GET /api/v1/integrations/openapi.json` without bearer authentication.
- Credential-bound project metadata through `GET /api/v1/integrations/project`.
- Generic and named typed REST clients for all eight versioned Acceptora verification operations while preserving the MCP workflow and legacy completion-gate hook.
- Canonical REST endpoint manifest metadata, facade annotations, and focused REST transport coverage.

### Fixed

- Reject control characters, backslashes, malformed URL authorities, and unsafe or oversized user-agent values before the HTTP client receives them.
- Pin compatibility digests to the canonical public contract identifiers under `https://www.acceptora.com/contracts/v1/`.
- Reject newline-terminated bearer tokens and token-shaped base URL origins before any network request.
- Prevent rejected configuration and outbound arguments, decoded HTTP or MCP errors, and malformed, oversized, unreadable, or semantically invalid success responses from retaining token values in native exception traces or serialized exceptions, including calls through the documented facade.
- Disable ambient HTTP proxies for permitted plain-HTTP loopback origins so bearer credentials cannot be redirected to a proxy.
- Redact recognized Acceptora and GitHub token forms, private-key markers, credential-named values, and secret-bearing array keys from bounded remote error surfaces.
