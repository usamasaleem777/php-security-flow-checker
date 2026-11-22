# Dashboard security audit

The review covered the local PHP entry point, routing, strict JSON decoding, model catalog, session-backed report storage, report downloads, and SVG and text rendering. Changes focus on behaviors confirmed by code or configuration inspection and regression checks.

| Confirmed issue | Component and impact | Fix and evidence |
| --- | --- | --- |
| Route parameters were implicitly accepted or ignored. PHP could collapse duplicate query names. | Dashboard routing accepted ambiguous requests and unnecessary input. | Route-specific scalar fields, consistent raw decoding, and a 2 KiB query cap. `tests/security.php` exercises unexpected, duplicate, array-valued and oversized parameters. |
| A valid token was accepted independently of a supplied browser origin. | The existing CSRF token protected verification. The endpoint lacked the additional origin check available on browser requests. | Require the supplied origin to match the local host and scheme, alongside the session token. Tests exercise original-origin success and foreign-origin rejection with the same token. |
| The session could already be active before the entry point set cookie policy. | With `session.auto_start` enabled, dashboard state could inherit a session and cookie configuration chosen outside the app. | Fail closed with HTTP 503 when a session is already active. Use host-only, session-lifetime cookies and retain strict IDs, HttpOnly, SameSite Strict and Secure on HTTPS. A child PHP process verifies the `session.auto_start` error. |
| The default XAMPP document root included the repository parent and allowed overrides. | A PHP route check cannot protect repository files the web server serves directly. | Add an Apache default deny at the checkout root and a loopback exception under `public/`. The test suite checks the declarations. The supported setup points the document root at `public/`. |
| Canonical membership alone allowed one catalog entry to resolve to a different file inside the same catalog. | A filesystem alias could change the selected fixture while preserving its displayed catalog identity. | Require each allowed file to resolve to its expected canonical path. A filesystem regression exercises a symlink where the host permits creating one. |
| Stored compact report bytes were bounded separately from pretty-print expansion on export. | Deeply nested JSON could expand substantially when formatted for download or embedded in HTML. | Format compactly above 16 KiB and fall back to compact JSON if formatted output exceeds the storage byte cap. The regression checks size and decoded content. |

## Reviewed controls

- Model identifiers resolve through a fixed allowlist, and the catalog reads bounded local files. JSON model objects reject duplicate members. Dashboard requests have no write path into the catalog or repository.
- Verification state lives in the PHP session and each browser session retains at most 12 runs. The decision to save a report preserves the model result and at least one compact witness when larger visual evidence is omitted.
- The dashboard accepts loopback connections and approved Host values. Requests use method checks and a 4 KiB JSON body cap. Expected errors are controlled, and PHP diagnostic display is disabled at the web entry point.
- HTML reports escape values before rendering. Browser views use DOM construction, `textContent` and fixed attribute names. The CSP allows scripts, styles and connections to the local origin, with framing and form navigation blocked.
- Analysis bounds discovered states, considered transitions and depth. The UI caps graph nodes and edges, and observers record truncation. Dashboard request and execution timeouts remain the web server's responsibility.

## Local verification

XAMPP Apache with a temporary loopback-only configuration and the wider `htdocs` document root served a public stylesheet with HTTP 200 and denied a nonpublic document with HTTP 403. The PHP development server emitted a host-only session cookie with HttpOnly and SameSite Strict. Windows PHP rejected a test directory junction that aliased an approved catalog file. The portable file-symlink test explicitly skipped because the Windows test process lacked permission to create that link.

The dashboard is an unauthenticated local developer tool. Use a loopback listener with `public/` as its document root. Apache checkout rules depend on the server honoring `.htaccess` authorization directives. PHPStan level 3 checks types and common code defects within its configured PHP paths. Its findings have a different scope from a security audit or model counterexample. [Apache authorization](https://httpd.apache.org/docs/2.4/mod/mod_authz_core.html#authmerging) · [Dashboard setup](DASHBOARD.md) · [Limitations](LIMITATIONS.md)
