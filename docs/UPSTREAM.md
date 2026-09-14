# Upstream Relationship and Provenance

PHPBB Lab 4 Core is an independent experimental branch based on the phpBB 4 development codebase.

## Upstream

Official phpBB repository: `phpbb/phpbb`

Documented reconstruction baseline: `53ec60f98ff8b5c23b41d39cb6628930f82a87a4` (11 September 2026).

This project does not represent itself as an official phpBB branch, release, roadmap or endorsement.

## Attribution model

Changes in this repository fall into three categories:

1. **Upstream phpBB source** — original phpBB code and history, retaining upstream copyright and licence notices.
2. **PHPBB Lab experimental work** — architectural experiments developed independently for this branch.
3. **Adapted upstream changes** — changes originating in phpBB tickets/pull requests that were applied or adapted to the PHPBB Lab architecture.

The third category is explicitly attributed to upstream. An adaptation does not transfer authorship of the underlying upstream work to PHPBB Lab.

## Tracked upstream adaptations

- **PHPBB-17609 / PR #6927** — jumpbox removal.
- **PHPBB-17600 / PR #6923** — mark-read controllers.
- **PHPBB3-15556 / PR #5122** — AM/PM translation.
- **PHPBB3-15190 / PR #4807** — extension metadata manager.

## Contribution direction

When an experiment is suitable for upstream consideration, the preferred route is a focused change against the current official phpBB development branch with:

- a narrowly defined problem statement;
- minimal unrelated changes;
- compatibility analysis;
- tests or a reproducible validation procedure;
- upstream coding/documentation conventions;
- references to relevant tracker tickets or previous discussions.

PHPBB Lab Core should therefore be understood as an engineering laboratory and reference implementation, not as a demand that upstream consume a monolithic fork.
