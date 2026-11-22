# Corrected PHP control verification

`stateaxiom.php verify` and `stateaxiom.php analyze` check finite graphs. The corrected control suite executes PHP fixture methods with native, file-backed sessions and checks operation results and side effects.

From the repository root, run the model and fixture commands independently.

```sh
php tests/run.php
php -n tests/run.php
php bin/stateaxiom.php verify examples/mfa-bypass/mfa-corrected.json
php tests/corrected-controls.php
php -n tests/corrected-controls.php
```

The corrected suite requires PHP 8.2+, the PHP session extension, and a writable OS temporary directory. The suite sets up synthetic authenticated and MFA states directly. Native sessions persist those states across calls and isolate them between session IDs. The suite uses the session files only for its own fixtures. [PHP session options](https://www.php.net/manual/en/function.session-start.php)

| Check | Required behavior |
| --- | --- |
| MFA enforcement | Deny export before verified MFA and return the synthetic record after verification. |
| Session revocation | Allow a protected export before revocation. Reopen the same session and deny further export. |
| Permission revocation | Allow the first update and keep the revision unchanged after permission is removed. |
| Single-use token | Reject an incorrect token, accept the issued token once, and preserve the revision on reuse. |
| Session isolation | Keep the authenticated fixture state confined to its original session. |
| Cleanup | Remove the temporary session directory after the checks. |

The test fixture generates one-time tokens with `random_bytes` and stores digests in the active test session. The comparison uses `hash_equals`. [PHP random_bytes](https://www.php.net/manual/en/function.random-bytes.php) · [PHP hash_equals](https://www.php.net/manual/en/function.hash-equals.php)

The suite uses fixed PHP call sites and accepts no model, action mapping, or HTTP target input. Its coverage is limited to the corrected fixture methods. See [the evidence format](EVIDENCE_MODEL.md) for outcome definitions.
