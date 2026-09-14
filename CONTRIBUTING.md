# Contributing to PHPBB Lab 4 Core

Thank you for reviewing PHPBB Lab Core.

This repository is an experimental engineering branch. Contributions are most useful when they make an architectural idea easier to understand, validate or reduce into a focused upstream-quality change.

## Before proposing a change

- Confirm whether the behaviour already exists or is being changed upstream in `phpbb/phpbb`.
- Keep the scope narrow; avoid mixing unrelated refactors and behavioural changes.
- Explain compatibility implications for the historical phpBB API, extensions, styles and supported databases where relevant.
- Preserve phpBB events and extension-facing contracts unless changing them is the explicit subject of the proposal.
- Preserve upstream copyright, licence and authorship information.

## Pull requests

A useful pull request should contain:

1. **Problem** — what concrete issue or coupling is being addressed.
2. **Approach** — why the proposed boundary/design was chosen.
3. **Compatibility** — expected impact on existing phpBB behaviour and extension APIs.
4. **Validation** — tests run or exact manual steps used to validate the change.
5. **Upstream relationship** — relevant phpBB ticket, pull request or discussion if one exists.

Prefer small reviewable commits. Do not include generated caches, local configuration, credentials, database dumps, server backups or packaged release archives.

## Coding direction

Follow the conventions of the phpBB development branch being targeted. Avoid PHPBB Lab-specific branding inside generic core runtime code unless it is strictly documentation or experimental metadata.

## Upstreaming

Where possible, a mature change should ultimately be proposed to the official phpBB project as a focused contribution against current upstream code. PHPBB Lab Core is a place to develop and validate ideas, not a replacement for upstream review.
