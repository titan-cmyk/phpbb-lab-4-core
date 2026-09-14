# Public Source Policy

The public repository is intentionally **not** a byte-for-byte copy of the PHPBB Lab beta server.

## Included

- phpBB source required to review the experimental core work;
- PHPBB Lab core changes;
- upstream attribution and licence material;
- technical documentation and validation notes.

## Excluded

- `phpBB/config.php` and any database credentials;
- caches, sessions and runtime-generated files;
- user uploads and private board data;
- deployment backups and `store/` recovery material;
- development ZIP/TAR packages;
- temporary diagnostic traces;
- server-specific scripts that were not established as part of the validated Core state;
- vendored dependencies where they can be restored by the normal project dependency process.

## Historical packages

Retained development packages are evidence used to reconstruct the development sequence. They are not themselves public source releases. `docs/HISTORY.md` records the relevant sequence without publishing server artefacts or implying that every troubleshooting package was a supported milestone.

## Credential handling

The beta server archive used during reconstruction contained a real local `config.php`; that file is deliberately excluded from this repository. Public-source reconstruction must never copy secrets from deployment archives into Git history.
