# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

### Fixed

- Directory picker (access settings): shows "Searching…/No matching people found./Search failed" states instead of a silently hidden list; user directory search also matches display names now.

## [0.4.2] - 2026-11-09

### Added

- Eleven new locale catalogs: da, es, fr, it, nb, nl, pl, pt, pt_BR, sv, tr.

### Fixed

- API uid-target write paths now reject nonexistent/deactivated accounts and require live project members.
- Directory user picker skips deactivated accounts.
- Dialog-scoped edit grids, fieldsets and split-sum controls reach the top layer; receipt flags no longer clipped.
- Projects-first sidebar order restored.
- App URLs routed through `OC.generateUrl` with index.php fallback.
- l10n coverage for the filter panel; hover-first row-action clicks.

## [0.4.1] - 2026-11-01

### Fixed

- Packaging refresh: clean rebuild of the production upload archive (no code changes).

## [0.4.0] - 2026-10-31

### Added

- NWC2 Pro licensing stack (I-07/ADR-08): Ed25519-verified `NWC2.<payload>.<signature>` keys (`lib/License/Nwc2Codec` + embedded vendor pubkey), singleton `nw_license_state` (migration `Version1004`), license admin API (`GET/POST/DELETE /api/v1/license`), Settings → License section (status badge, apply/remove with confirm dialog, purchase link), deterministic seat-rank downgrade handling, `licensing.envelope` in the mobile bootstrap, and appconfig `nw_license_mode` dev override.
- Mobile seats are now capped by the license `mobileSeats` entitlement under an exclusive lock; over-limit seats stay assigned but lose access (flagged in the seats list).
- sbdlicenseops: `NachweisCheckProductDefinition` (`NWC2`, Family B) + wire-prefix registration + `license_nwc2_golden.json` fixture and rejection-matrix coverage.

### Changed

- `LicenseService` now resolves Pro from the stored, in-date NWC2 key (override wins) instead of the aspirational sbdlicenseops appconfig probe; expired licenses degrade to free tier while all data stays readable (spec grace semantics). License apply/remove is audit-logged.

## [0.3.1] - 2026-10-31

### Added

- Production upload packaging: `appinfo/version` and `composer/autoload.php` bridge so the bundled `horstoeko/zugferd` runtime autoloads on server installs (re-registered appended so dev OCP stubs can never shadow the server autoloader).

### Fixed

- Atlas farm r1–r3 closures: API hardening, chrome, l10n, and e2e contract gates.

### Changed

- Bachus simplification pass: progressive disclosure, file picker, duration input, receipt filters.

## [0.3.0] - 2026-10-27

### Added

- Initial release: Verwendungsnachweise for publicly funded projects — versioned Finanzierungsplan, Arbeitspakete mapped to Einzelansätze, dated-rate time capture, receipts with position splits, mandatory-reason deviations, the 20-% Einzelansatz rule, incoming payments, carryover, deadline engine, and an immutable Nachweis package (zahlenmäßiger Nachweis, Belegliste, Sachbericht skeleton, gaps report) with manifest hash.
