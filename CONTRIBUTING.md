# Contributing

Start with a focused change and describe the affected model, bound or dashboard route in the pull request. Preserve existing CLI and JSON semantics unless the change explicitly addresses a documented defect. Include a small fixture or regression when correcting behavior.

Use Core PHP 8.2+, strict types and PSR-12 where practical. Keep web assets local. Model cases may use synthetic facts and transitions. Include the model or seed and the commands needed to reproduce test results.

```sh
php tests/run.php
php tests/corrected-controls.php
php tests/dashboard.php
php -n tests/reference.php
php -n tests/security.php
php -n tests/benchmark.php
php -n tools/lint.php --include-untracked
```

The GitHub workflows run every suite with the normal PHP configuration and with `php -n`. The lint workflow uses the Git index. Developer analysis uses the checksum-pinned PHPStan release described in the [security workflow](.github/workflows/security-checks.yml). [Research methodology](docs/RESEARCH_METHODOLOGY.md)

The configured test matrix covers PHP 8.2–8.5 on Ubuntu and Windows. Workflows use read-only repository permissions, disable retained checkout credentials and pin action commits. Local checks use actionlint and PHPStan 2.3.7 at level 3. Hosted compatibility results depend on the matrix running in GitHub Actions. [Checkout release](https://github.com/actions/checkout/releases/tag/v7.0.1) · [Setup PHP release](https://github.com/shivammathur/setup-php/releases/tag/2.40.0) · [PHPStan release](https://github.com/phpstan/phpstan/releases/tag/2.3.7)

Use the [security policy](SECURITY.md) to report sensitive implementation defects. Omit tokens, session IDs, personal data and private application models from issues and pull requests.
