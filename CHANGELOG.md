# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 0.4.3 - 2026-11-24

### Fixed

- Include all six public screenshots in the new App Store release metadata.

- drop no-op ReflectionMethod::setAccessible in uninstall repair test
- use the store-supported MySQL identifier for MariaDB
- restore color-scheme pins in synced check-shell tokens
- de license-signature cell to formal register (donor sync)
- pl Missing-attachment cell + tr pluralForm header arity
- normalize sibling catalog key order to en.json (atlas intake nachweischeck-intake-20261121T152902Z)
- heal plural-arity + register drift, resync transfer pack
- re-sync config-transfer pack; register n() plural msgids in l10n catalogs
- re-sync license-page pack — [hidden] display guard
- heal license-page pack drift + canonical n() plural catalogs — seat display names
- promote mobile sheet override to [open] specificity — pack resync
- close untranslated gaps — license-page pack propagation, hand-translated headers, sbdlicenseops zh_TW catalog
- directory pickers — visible states + display-name merge
- pack dialog sheet beats app dialog chrome — :not(:modal) + body> scope
- margin:auto on pack dialog — NC core reset uncenters top-layer dialogs
- overlay close-button armor + %d sprintf + transfer l10n catalog
- sync config-transfer pack — schema cache, file-input specificity, sheet clip
- sync config-transfer pack — positional t() interpolation
- zh_CN — drop 31 phantom transfer keys
- tr percent strings — single % for JS-rendered catalog values
- restore field rhythm in dialogs/settings + switch hint

### Added

- add Russian (ru) catalog
- adopt shared license-page pack + seat admin API
- config & data transfer — encrypted test→prod pack

### Changed

- rewrite store description and refresh screenshots
- define --nw-mono + muted-on-tint tokens (check-shell sync)
- re-sync shared packs (config-transfer, view-components) + l10n catalog regen
- l10n: formal register + identical-gap cleanup, zh_TW vocabulary flip
- allow reflection invoke of dropAllTablesAndMetadata
- rendered-surface contract — cover /settings/transfer section
- pack re-sync — replace-confirm token width (cramped_form_controls)
- l10n: zh-Hant/zh_TW catalog + locale wiring
- l10n: add zh_CN catalog
- l10n: complete translations across the app language set

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
