# Security Policy

## Experimental status

PHPBB Lab 4 Core is an experimental development branch and is **not intended for production use**.

Do not deploy this repository as a production forum on the assumption that it receives the same review, release engineering or security support as an official phpBB release.

## Reporting a vulnerability

Please do not publish exploit details or sensitive vulnerability information in a public issue.

For a vulnerability that also affects upstream phpBB, follow the official phpBB security reporting process so the phpBB project can coordinate a responsible fix.

For a vulnerability specific to PHPBB Lab experimental changes, contact the PHPBB Lab maintainer privately before public disclosure. A public issue may be opened after sensitive details have been removed or a fix is available.

## Secrets and runtime data

The public repository must never contain deployment credentials, database passwords, private keys, production configuration, user uploads, session/cache data or server backups. If such material is ever discovered in repository history, treat the credential as compromised and rotate it; deleting the visible file alone is not sufficient.
