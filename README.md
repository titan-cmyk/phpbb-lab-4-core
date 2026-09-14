# PHPBB Lab 4 Core

> **Independent experimental development branch based on phpBB 4. Not an official phpBB release and not intended for production use.**

PHPBB Lab 4 Core is an independent engineering project maintained by **phpbb-lab** to explore architectural refactoring, modularisation, compatibility-preserving modernisation and deeper use of contemporary PHP infrastructure in the phpBB 4 development codebase.

The project deliberately works inside the core rather than presenting these experiments as extensions or themes. Its purpose is technical: isolate responsibilities, reduce coupling in historically large execution paths, preserve compatibility where practical, validate changes through real board workflows, and make the resulting work reviewable.

## Project status

This repository is a **development and research branch**. It is not an official phpBB branch, is not endorsed by phpBB Limited, and should not be deployed to production systems.

The current validated development state is based on the phpBB 4 development line and contains work completed and exercised on the PHPBB Lab development environment during September 2026.

## Upstream provenance

Upstream project: `phpbb/phpbb`

Reference baseline used for reconstruction: `53ec60f98ff8b5c23b41d39cb6628930f82a87a4` (11 September 2026).

PHPBB Lab preserves upstream copyright and licensing. Changes originating from upstream phpBB tickets or pull requests are identified as such in the project history; PHPBB Lab-specific adaptations are documented separately.

## Main engineering tracks

### Viewforum architecture

The historical `viewforum.php` flow was decomposed into clearer responsibilities, including controller/application orchestration, forum data access, page preparation, topic retrieval and topic rendering. Existing extension-facing behaviour was preserved where applicable.

### Viewtopic architecture

The large `viewtopic.php` execution path was split into dedicated components for topic/forum data, navigation, page preparation, post retrieval, author data, rendering, polls and final page processing. The public entry point delegates substantially more work to the application layer.

### Dynamic extension service loading

Extensions can expose ordered service configuration files such as `services_10_repository.yml`, `services_20_controller.yml` and `services_30_listener.yml` without manually importing every service file from a single root configuration. The mechanism was validated with a dedicated dependency-chain test extension.

### Lightweight maintenance bootstrap

Maintenance handling can return a minimal HTTP `503 Service Unavailable` response early in startup, avoiding a normal full phpBB application bootstrap while maintenance mode is active.

### Template and Twig refactoring

Template responsibilities were separated across dedicated components covering style/template path resolution, context construction, rendering, Twig environment management, handles, root/scalar data, block data, nested block selection and row metadata. The refactored path was exercised on public pages, profiles, ACP and MCP.

### Doctrine DBAL integration

The MySQL/MariaDB development path explores Doctrine DBAL as the primary SQL execution layer while retaining the historical phpBB database API as a compatibility surface. Work includes shared connection handling, query compatibility, transactions, nested transaction semantics, bulk inserts, SQL cache integration, positional and named parameters, typed/NULL parameters, parameter-aware LIMIT/OFFSET and SQL builders, plus migration of real viewforum/viewtopic and poll paths.

### Upstream adaptations

The development branch also integrates or adapts selected upstream phpBB work against the refactored architecture, including:

- PHPBB-17609 / PR #6927 — jumpbox removal.
- PHPBB-17600 / PR #6923 — mark-read controllers.
- PHPBB3-15556 / PR #5122 — AM/PM translation support.
- PHPBB3-15190 / PR #4807 — extension metadata manager work.

These items remain attributed to their upstream origin; adaptations required by PHPBB Lab architecture are documented as project work rather than represented as original upstream authorship.

## Validation approach

Development has been exercised through normal phpBB workflows rather than isolated probes alone. Validated paths include topic creation, replies, quick reply, quoting, editing, deletion, private messages, forum creation, forum/topic browsing, profiles, ACP and MCP. Individual tracks also used targeted probes and syntax checks where appropriate.

Passing those checks does **not** make this a production release. Broader automated test coverage, multi-database validation, upgrade-path testing, security review and upstream review remain necessary before any work should be considered for production or upstream integration.

## Repository policy

This repository contains source code and project documentation only. Server-specific runtime material is intentionally excluded: database credentials, `config.php`, caches, uploaded/runtime data, deployment backups, local ZIP packages, temporary diagnostics and development-environment secrets are not part of the public source tree.

## Documentation

- `STATUS.md` — current project status, confidence level and near-term roadmap.
- `docs/ARCHITECTURE.md` — architectural overview and design goals.
- `docs/HISTORY.md` — reconstructed and validated PHPBB Lab development history.
- `docs/UPSTREAM.md` — provenance and relationship with upstream phpBB work.
- `docs/VALIDATION.md` — validation scope and known limitations.
- `docs/SOURCE_POLICY.md` — public-source inclusion and exclusion rules.
- `SECURITY.md` — responsible security reporting and production-use warning.

## Contributing

Technical review is welcome. Contributions should be narrowly scoped, explain compatibility impact, preserve upstream attribution, and include a reproducible validation plan. Changes intended for phpBB itself should ultimately be reduced to focused upstream-quality contributions rather than requiring adoption of this entire experimental branch.

## Licensing and trademarks

phpBB is free software distributed under the GNU General Public License version 2. This repository preserves the upstream licensing and copyright notices.

**PHPBB Lab** is an independent project. It is not affiliated with or endorsed by phpBB Limited. The phpBB name and related marks belong to their respective owners.

---

Maintained by **phpbb-lab** for PHPBB Lab.
