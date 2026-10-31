# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
