# Architecture

## Goals

PHPBB Lab 4 Core explores whether historically large phpBB execution paths can be decomposed without forcing an immediate rewrite of the public phpBB API or extension ecosystem.

The guiding principles are:

- **Incremental modernisation** — introduce clearer boundaries without requiring a flag-day rewrite.
- **Compatibility first** — retain established APIs and extension-facing behaviour where practical.
- **Explicit responsibilities** — move data access, orchestration, rendering and infrastructure concerns into dedicated components.
- **Reviewability** — prefer focused components and changes that can later be evaluated independently.
- **Runtime validation** — exercise changes through normal board behaviour, not only synthetic examples.
- **Reversibility** — experimental infrastructure should retain a practical compatibility/fallback path while it is being evaluated.

## Viewforum

The refactor reduces the responsibility of the historical public entry path by separating concerns into dedicated controller/application, repository, page preparation, topic retrieval and rendering components.

The intent is not simply to move lines between files. The architectural goal is to create boundaries where forum data access, page-state preparation and presentation can evolve independently and become easier to test.

## Viewtopic

Viewtopic historically combines many concerns in one request path. PHPBB Lab separates topic/forum data, navigation, page preparation, post retrieval, author preparation, post rendering, poll handling and final page processing.

This decomposition also creates clearer integration points for later database-layer experiments, because query-heavy responsibilities can be migrated without rewriting the entire request path at once.

## Extension service discovery

The dynamic service loader recognises ordered extension service files. Ordering is deliberate: it allows repositories/infrastructure to be defined before controllers/listeners that depend on them while retaining deterministic loading.

The feature was validated with a purpose-built extension whose services depend on services declared in different files.

## Maintenance bootstrap

The maintenance experiment moves the decision far enough forward in startup that a disabled board can return a small HTTP 503 response without normally constructing the complete application graph. This reduces work performed precisely when the application is intentionally unavailable.

## Template subsystem

The template track separates several responsibilities historically concentrated in template/Twig/context classes:

- style and template path resolution;
- context construction;
- Twig environment management;
- rendering;
- template handles;
- root/scalar values;
- block data;
- nested block selection;
- row metadata.

The objective is smaller stateful surfaces and clearer ownership of mutations while retaining phpBB template semantics.

## Doctrine DBAL bridge

The Doctrine work is deliberately a bridge rather than an immediate replacement of phpBB's database API.

For the MySQL/MariaDB experimental path, Doctrine DBAL can act as the underlying execution layer while existing phpBB call sites continue to use familiar database methods. This allows real application paths to migrate progressively.

Areas explored include shared physical connection handling, normal CRUD and DDL/administrative statements, transactions and nested transaction semantics, bulk insert behaviour, SQL caching, prepared parameters, typed and NULL values, LIMIT/OFFSET handling, SQL builders and real forum/topic/poll paths.

A native compatibility path is retained for cases not yet covered by the experimental layer.

## Upstream compatibility

PHPBB Lab Core is intentionally close enough to upstream phpBB that individual ideas can be reduced to focused contributions. Upstream changes applied to this branch retain their original ticket/PR attribution, and adaptations caused by the experimental architecture are treated separately.

The long-term measure of success is not whether upstream adopts this branch wholesale. It is whether the experiments produce understandable, testable improvements that can inform or become focused upstream work.
