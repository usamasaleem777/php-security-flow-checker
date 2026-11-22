# Evidence model

The finite-state CLI retains `VERIFIED`, `VIOLATED`, `INCOMPLETE`, and `ERROR` for model analysis. A model counterexample contains a shortest path to a false state or transition assertion in the declared graph. The runtime behavior of a deployed implementation requires independent evidence.

The corrected PHP control suite emits JSON with these fields.

| Field | Meaning |
| --- | --- |
| `fixture` | Identifier of the corrected PHP fixture suite. |
| `evidence_type` | `PHP_CONTROL_ASSERTIONS` for direct execution of fixture methods. |
| `http_replay_status` | `NOT_RUN` for this suite. |
| `runtime_reproduced` | `false` because a reproduction claim requires independent HTTP observations and operation effects. |
| `checks` | Each control's identifier and status, with expected and observed values or an error reason. |
| `status` | `PASS` when all checks match, `FAIL` on an assertion or cleanup failure, `ERROR` when setup or observation fails, or `SKIPPED` when PHP lacks the session extension. |
| `passed` and `failed` | Computed observation counts when the suite executes. |

The fixture suite exits `0` for `PASS` or `SKIPPED`, `1` for `FAIL`, and `3` for `ERROR`. No session IDs or token values appear in the evidence.

For HTTP evidence, `NOT_REPRODUCED` would require a completed test with valid setup and observations showing that the expected unauthorized effect did not occur. `INCONCLUSIVE` applies when the observations cannot establish the effect, such as when setup fails or the response is ambiguous. A successful control test applies only to the exercised fixture behavior.
