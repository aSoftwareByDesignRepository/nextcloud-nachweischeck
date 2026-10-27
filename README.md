# NachweisCheck

Verwendungsnachweise for publicly funded projects — directly inside Nextcloud.

NachweisCheck captures project data as it happens (time bookings, receipts, work
reports) and assembles the funder-facing evidence package at period close:
zahlenmäßiger Nachweis, Belegliste (ANBest-P Nr. 6.4 layout), Sachbericht
skeleton, and a "Fehlende Angaben" gap report — immutable, with a manifest hash.

- Versioned Finanzierungsplan (bewilligt vs. verwendet stays comparable)
- Arbeitspakete mapped to Einzelansätze; dated-rate time capture
- Receipts with position splits and file links (never byte copies)
- Mandatory-reason deviations; 20-% Einzelansatz rule per funder template
- Incoming payments, carryover, Umschichtung workflow, deadline engine
- Auditor seats: account-bound, scoped, expiring — every read logged
- Fully standalone. No other app required. CSV import covers external data.

License: AGPL-3.0-or-later. Platform: Nextcloud 32–35, PHP 8.2+, MariaDB or PostgreSQL.
