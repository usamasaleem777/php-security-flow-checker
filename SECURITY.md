# Security policy

PHP Security Flow Checker is an open-source research tool for local, authorized assessment. Use a loopback listener and point the document root at `public/`. Keep the surrounding PHP and web server environment updated. [Local setup](docs/DASHBOARD.md)

## Reporting a vulnerability

If GitHub private vulnerability reporting is enabled, use **Security → Report a vulnerability** on the repository. Include the affected revision, an explanation, and a minimal synthetic example. If the option is unavailable, ask a maintainer for a private reporting channel before sharing sensitive details. [GitHub private vulnerability reporting](https://docs.github.com/en/code-security/security-advisories/working-with-repository-security-advisories/privately-reporting-a-security-vulnerability)

Use ordinary issues for non-sensitive model correctness defects and documentation. Avoid personal data, credentials, tokens and private models in all public reports.

## Evidence scope

Model counterexamples apply to the supplied finite model. The corrected PHP fixture suite checks its own method behavior, and HTTP replay remains `NOT_RUN`. The project currently targets an initial research preview and has no published security support schedule. [Security audit](docs/SECURITY_AUDIT.md) · [Limitations](docs/LIMITATIONS.md)
