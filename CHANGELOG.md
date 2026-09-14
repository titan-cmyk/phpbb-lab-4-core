# PHPBB Lab 4 Core — Changelog

This changelog summarises validated engineering milestones. For package-level reconstruction details, see `docs/HISTORY.md`.

## 2026-09-13

### Upstream integration/adaptation

- Integrated PHPBB-17609 / PR #6927 jumpbox-removal work into the experimental branch.
- Adapted PHPBB-17600 / PR #6923 mark-read controller work to the refactored forum/topic architecture.
- Integrated PHPBB3-15556 / PR #5122 AM/PM translation support.
- Integrated PHPBB3-15190 / PR #4807 extension metadata-manager work.

## 2026-09-12

### Doctrine DBAL bridge

- Added an experimental Doctrine-backed MySQL/MariaDB execution path while preserving the historical phpBB DBAL API.
- Added compatibility for normal query execution, transactions, nested transaction semantics, bulk operations, caching and prepared parameters.
- Progressively migrated real viewforum/viewtopic and poll-related paths to parameter-aware execution.
- Retained compatibility fallback behaviour for uncovered cases.

### Template/Twig architecture

- Extracted style/template path resolution.
- Extracted Twig context preparation and rendering/environment responsibilities.
- Separated handle, scalar/root, block, nested-block and row-metadata responsibilities.
- Validated the refactored template path across public pages, profiles, ACP and MCP.

### Lightweight maintenance

- Added an early minimal maintenance response path returning HTTP 503 without normally bootstrapping the full application.

### Dynamic extension services

- Added deterministic discovery of ordered extension service configuration files.
- Validated cross-file dependencies with a dedicated test extension.

### Viewtopic architecture

- Decomposed the historical viewtopic flow into dedicated data, navigation, page, post, author, rendering, poll and final-processing responsibilities.

### Viewforum architecture

- Decomposed the historical viewforum flow into clearer controller/orchestration, repository, page, topic retrieval and rendering responsibilities.

## Status

All entries above describe experimental development work and validation milestones. They do not constitute an official phpBB release or a production-readiness statement.
