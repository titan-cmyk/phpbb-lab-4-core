# PHPBB Lab 4 Core — Project Status

Last updated: 14 September 2026

## Current stage

PHPBB Lab 4 Core is an **independent experimental development branch based on phpBB 4**.

The current public repository represents the latest validated state recovered from the PHPBB Lab development environment and reconstructed on top of the documented upstream phpBB baseline.

It is **not an official phpBB release** and is **not intended for production use**.

## Validated engineering tracks

The following areas have been implemented and exercised on the PHPBB Lab development environment:

- Viewforum architectural refactoring.
- Viewtopic architectural refactoring.
- Dynamic extension service-file discovery.
- Lightweight early maintenance response.
- Template/Twig responsibility separation.
- Doctrine DBAL bridge for the MySQL/MariaDB experimental path.
- Adaptation of selected upstream phpBB work to the refactored branch.

For validation details, see `docs/VALIDATION.md`.

## Upstream adaptations currently represented

The repository includes or adapts the following upstream work:

- PHPBB-17609 / PR #6927 — jumpbox removal.
- PHPBB-17600 / PR #6923 — mark-read controllers.
- PHPBB3-15556 / PR #5122 — AM/PM translation support.
- PHPBB3-15190 / PR #4807 — extension metadata manager work.

Upstream authorship and provenance remain explicitly preserved. See `docs/UPSTREAM.md`.

## Current confidence level

### Confirmed

- The repository is based on a real upstream phpBB Git history.
- The public source tree excludes the deployment `config.php`, runtime caches, user files, server backups and retained release packages.
- Normal forum workflows were exercised on the development environment after the main refactors.
- The public documentation records known validation scope and historical reconstruction limits.

### Still experimental

- Doctrine DBAL integration remains an experimental compatibility bridge rather than a completed upstream-ready database-layer replacement.
- Multi-database parity has not been established for the PHPBB Lab changes.
- Full third-party extension/style compatibility has not been established.
- The branch has not undergone upstream phpBB review or approval.
- Production readiness has not been established.

## Near-term priorities

1. Keep all future development in Git from the beginning, with one focused branch/commit series per engineering change.
2. Maintain automated CI on the `main` branch for syntax, metadata and source-hygiene checks.
3. Expand targeted automated tests around refactored Viewforum/Viewtopic and Doctrine paths.
4. Identify individual changes that can be reduced into focused upstream-quality contributions.
5. Track upstream phpBB 4 changes and rebase/adapt deliberately rather than allowing the experimental branch to drift silently.
6. Add reproducible performance measurements before making performance claims.

## Contribution readiness

This repository is suitable for technical review and experimentation.

A change should only be described as an upstream candidate when it has been reduced to a focused scope, rebased against current upstream phpBB, supplied with an explicit compatibility analysis and accompanied by reproducible validation or tests.

## Historical note

The original beta installation did not retain its own `.git` directory. The public Git history therefore preserves the real upstream phpBB history and then records the validated PHPBB Lab state as a reconstructed import. The detailed package chronology is documented in `docs/HISTORY.md` rather than being represented as fabricated historical commits.
