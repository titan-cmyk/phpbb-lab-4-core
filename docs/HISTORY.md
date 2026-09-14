# PHPBB Lab 4 Core — Development History

This document records the reconstructed PHPBB Lab Core development sequence from the retained release packages and validation markers. The purpose is traceability, not to manufacture historical Git metadata that did not exist at the time.

## Baseline

The development installation was built from the phpBB 4 development line. The retained source state is consistent with upstream commit `53ec60f98ff8b5c23b41d39cb6628930f82a87a4` from 11 September 2026, which is used as the documented reference baseline.

## 12 September 2026 — architectural refactoring

### Viewforum refactoring

Retained packages: `PHPBB_LAB_CORE_VIEWFORUM_REFACTOR_1` through `_5`.

The work progressively separated the historical viewforum execution path into controller/orchestration, repository, forum-page, topic retrieval and rendering responsibilities. The final retained state was validated against normal forum browsing.

### Viewtopic refactoring

Retained packages: `PHPBB_LAB_CORE_VIEWTOPIC_REFACTOR_1` through `_6`.

The viewtopic path was progressively decomposed into dedicated services/components while preserving normal topic behaviour. Validation included reading topics and the normal post actions exercised later in the full workflow pass.

### Dynamic extension services

Retained package: `PHPBB_LAB_CORE_DYNAMIC_EXTENSION_SERVICES_1`.

A dedicated test extension, `PHPBB_LAB_EXT_DYNAMIC_SERVICES_TEST_1.0.0`, validated ordered discovery of multiple extension service configuration files and cross-file service dependencies.

### Lightweight maintenance

Retained package: `PHPBB_LAB_CORE_LIGHTWEIGHT_MAINTENANCE_1`.

The maintenance path was moved early enough in startup to return a minimal maintenance response without a normal full application bootstrap. The development environment returned HTTP 503 while enabled and immediately resumed normal forum operation when disabled.

### Template/Twig refactoring

Retained packages: `PHPBB_LAB_CORE_TEMPLATE_REFACTOR_1` through `_8`.

Responsibilities were progressively extracted from historical template/Twig/context classes. The retained final state separates path management, context construction, rendering, environment management, handles, root/scalar data, blocks, nested-block selection and row metadata. Validation covered the index, categories/forums, topics, user profiles, ACP and MCP.

### Doctrine DBAL integration

Retained development packages include `PHPBB_LAB_CORE_DOCTRINE_DBAL_1` through `_18`, targeted test/factory/complete fixes, and `PHPBB_LAB_CORE_DOCTRINE_DBAL_FINAL`.

The final retained state is the authoritative project state; intermediate diagnostic-only instrumentation is not treated as part of the final source. The track explored Doctrine DBAL as the primary MySQL/MariaDB execution layer while preserving the historical phpBB database API and compatibility semantics.

## 13 September 2026 — upstream adaptations

The final server archive retained application markers and pre-change backups for the following changes.

### PHPBB-17609 / PR #6927 — Remove jumpbox

Applied to the refactored development tree. Upstream provenance is retained; PHPBB Lab-specific work consists of adaptation to the experimental branch where required.

### PHPBB-17600 / PR #6923 — Mark-read controllers

Applied and validated against the refactored viewforum/viewtopic architecture. The adaptation preserves the intent of upstream controller work while accounting for the changed execution structure.

### PHPBB3-15556 / PR #5122 — Translate AM/PM

Applied to the development tree, modifying the datetime/language path required by the upstream change. Syntax checks and a targeted probe were retained as part of validation.

### PHPBB3-15190 / PR #4807 — Metadata manager

Applied to the extension metadata/manager and ACP extension path. The retained package and server marker identify the files affected by the validated adaptation.

## 14 September 2026 — public source reconstruction

The original beta installation did not contain a `.git` directory. Consequently, the public repository does not claim that reconstructed documentation or commit grouping is the original Git history.

The public repository is assembled from:

1. the documented upstream reference baseline;
2. retained PHPBB Lab release packages;
3. the final beta source archive;
4. retained application markers and pre-change backups;
5. validation results recorded during development.

Where a package merely represents an intermediate troubleshooting state, it is documented but not promoted as an independently supported release.

## Historical integrity policy

The project follows these rules:

- Never claim an upstream phpBB change as original PHPBB Lab authorship.
- Never infer that a retained script was deployed merely because it exists in an archive.
- Treat the final validated package/state of a development track as authoritative over temporary diagnostics.
- Keep reconstructed history explicitly labelled as reconstructed when original Git metadata is unavailable.
- Preserve upstream copyright and GPL licensing.
