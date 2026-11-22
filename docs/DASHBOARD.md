# Local dashboard

The browser workspace runs with PHP 8.2+, the PHP session extension, HTML, CSS, and vanilla JavaScript. It uses the same Core PHP analyzer as the CLI and serves assets locally.

## XAMPP and local setup

From the repository root, use the PHP binary included with XAMPP.

```powershell
& 'C:\xampp\php\php.exe' -S 127.0.0.1:8080 -t public
```

Open [the dashboard on loopback](http://127.0.0.1:8080/). If `php` is already on your `PATH`, use `php -S 127.0.0.1:8080 -t public`. The PHP development server is intended for local development. [PHP web server documentation](https://www.php.net/manual/en/features.commandline.webserver.php)

For XAMPP Apache, configure a local-only virtual host and point its document root directly at `public/`. Add a loopback listener for the virtual host and use `Require local` for that directory. Adjust the paths and port to your setup.

```apache
Listen 127.0.0.1:8090
<VirtualHost 127.0.0.1:8090>
    ServerName localhost
    DocumentRoot "C:/xampp/htdocs/php-security-flow-checker/public"
    <Directory "C:/xampp/htdocs/php-security-flow-checker/public">
        Require local
        Options -Indexes
        DirectoryIndex index.php
    </Directory>
</VirtualHost>
```

Use [the Apache local URL](http://127.0.0.1:8090/) after applying the virtual host configuration.

## Operations

| View | Tasks |
| --- | --- |
| Overview | Select a model, set exploration limits, run verification, and review session metrics. |
| Security models | Search the 14 approved models and inspect their initial states, variable domains, actions, and property definitions. Model variants describe the fixtures. Actual outcomes come from verification. |
| State graph | Pan, zoom, select nodes and transitions, and inspect coverage and witnesses. |
| Counterexamples | Select a finding and step through its shortest witness, changed facts, and failed assertion. |
| Mathematics | Inspect BFS layers, the fixed point, induction checks over explored states and transitions, and sampled policy evaluation. |
| Compare models | Run two selected models under the current limits. Compare outcomes and the actual property definitions independently of model filenames. |
| Reports | Restore a run, download JSON, HTML or counterexamples, and inspect archived corrected fixture checks. |
| Documentation | Review result interpretation and evidence scope. |

The first visit shows the declared model graph as a preview. Metrics for runs and findings begin at zero until a run exists in the current session. History retains the most recent 12 runs for that browser session. Those runs supply the metrics and reports.

## Request handling and limits

`public/index.php` loads `src/Web/` for loopback checks, catalog lookup, session history, verification routing and downloads. `src/Visualization/` serializes the engine observations for the graph and mathematics views. The browser renders those observations without computing reachability again.

Only catalog identifiers can select models, and each entry is checked against its canonical file path. The server checks the loopback client address and Host header. Verification requires a same-session CSRF token, a matching browser origin when supplied, and a JSON POST body of at most 4 KiB. Each endpoint accepts specific scalar query parameters, up to 2 KiB. Set `session.auto_start=0` so the dashboard can establish its own cookie policy. HTML exports are escaped and the browser receives a Content Security Policy. The web catalog reads bundled JSON files up to 96 KiB. [Security audit](SECURITY_AUDIT.md)

| Limit | Maximum |
| --- | ---: |
| Discovered states | 500 |
| Considered transitions, including guard-disabled edges | 2,000 |
| BFS depth | 64 |
| Visible nodes in one SVG view | 48 |
| Visible transitions in one SVG view | 96 |
| Stored run payload | 256 KiB of JSON |

The handler runs synchronously within these limits. When a run reaches a graph exploration budget, it records incomplete coverage and preserves any witness already found. A separate response size limit can omit visualization details or retain a compact first witness. The original result and finding counts remain visible with a payload notice. Use the CLI for the full large report.

Phase 3 corrected fixture checks remain available from `php tests/corrected-controls.php`. The Reports view labels the bundled CLI archive `CORRECTED_FIXTURE_CHECK` and keeps its counts separate from model-run metrics. HTTP replay displays `NOT_RUN`. Automated HTTP replay remains an outstanding Phase 3 milestone. [Recorded corrected fixture checks](../examples/runtime-evidence/corrected-controls.json)

## Checks

```sh
php tests/run.php
php -n tests/run.php
php tests/corrected-controls.php
php -n tests/corrected-controls.php
php tests/dashboard.php
php -n tests/dashboard.php
```
