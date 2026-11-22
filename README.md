# PHP Security Flow Checker

PHP Security Flow Checker is a Core PHP command-line model checker for finite security workflows. Phase 2 adds typed state domains, guarded transitions, operation-time security rules, and deterministic findings with shortest counterexamples.

## Installation and usage

Use PHP 8.2 or later with the CLI available on your `PATH`. Open a terminal in the repository root and run these commands.

```sh
php -v
php tests/run.php
php bin/stateaxiom.php analyze examples/mfa-bypass/export-vulnerable.json --verbose
php bin/stateaxiom.php analyze examples/mfa-bypass/export-corrected.json --format=json
php bin/stateaxiom.php analyze examples/mfa-bypass/export-vulnerable.json --format=json --max-states 1
php bin/stateaxiom.php verify examples/mfa-bypass/mfa-corrected.json
php bin/stateaxiom.php examples/mfa-bypass/mfa-vulnerable.json
php bin/stateaxiom.php examples/mfa-bypass/mfa-corrected.json --max-depth 1
```

On XAMPP for Windows, use `C:\xampp\php\php.exe` if `php` is unavailable on your `PATH`. The analyzer uses built-in PHP functions and runs in memory. The test runner uses `proc_open` to exercise the CLI. The project has selected [ThingEngineer's `MysqliDb`](https://github.com/ThingEngineer/PHP-MySQLi-Database-Class) for future MySQL persistence.

`analyze` defaults to readable text. `--verbose` adds state changes, the failed assertion, and its evidence. Use `--format=json` for machine-readable output. `verify` and the original command without a subcommand default to JSON. For a Phase 1 model, `verify` retains the original report format when no state limit is specified.

In Bash or Command Prompt, append `> mfa-report.json` to the JSON command to save the report. In Windows PowerShell, use the following commands to save UTF-8 JSON.

```powershell
$report = php bin/stateaxiom.php analyze examples/mfa-bypass/export-corrected.json --format=json
[IO.File]::WriteAllLines((Join-Path $PWD 'mfa-report.json'), [string[]]$report)
```

## Reading results

| JSON `status` | Exit code | Meaning |
| --- | ---: | --- |
| `VERIFIED` | 0 | Reachability converged and the configured properties held on all applicable states and transitions. |
| `VIOLATED` | 1 | The analyzer found a reachable assertion failure. Each violated property has a shortest trace. |
| `INCOMPLETE` | 2 | A budget left coverage incomplete and the explored portion yielded no violation. |
| `ERROR` | 3 | A CLI, model, or analysis error prevented a result. JSON errors include an `error` field. |

Set `--max-depth N` to limit witness length to `N` transitions. Initial states have depth zero. Set `--max-states N` to cap state discovery. A limit can allow `VERIFIED` if reachable states and applicable transitions were fully covered. A witnessed violation returns `VIOLATED` even when `analysis_complete` is false. Check `incomplete_reasons` for skipped coverage.

Phase 2 JSON includes actual counts. `states_explored` counts discovered states. `transitions_considered` counts examined outgoing edges, including edges disabled by guards and edges examined at the depth boundary. `transitions_explored` counts enabled edges within the trace depth budget. At the state limit, a checked transition can supply a witness even if its destination could not be queued for further exploration.

Each finding identifies the model, rule, and property. Its counterexample gives the initial state, ordered transitions, before-and-after values for changed facts, and the state or edge where the assertion failed. Rules check successful operations against the source state's prerequisites. A later logout or revocation leaves earlier authorized actions valid. Evidence is labeled `MODEL_COUNTEREXAMPLE`, and `runtime_reproduced` is `false`. See the generated [MFA violation report](examples/mfa-bypass/reports/export-vulnerable.json) and [corrected MFA report](examples/mfa-bypass/reports/export-corrected.json).

## Phase 2 model format

Supply a local UTF-8 JSON file up to 1 MiB. JSON nesting is limited to 128 levels by the parser. Every object member name must be unique. Identifiers start with an ASCII letter and may contain up to 64 ASCII letters, digits, underscores, or hyphens.

| Field | Meaning |
| --- | --- |
| `name` | A model identifier. |
| `domains` | An object mapping variables to `"boolean"` or nonempty lists of unique Boolean, integer, or string values of one type per variable. |
| `initial` | A nonempty list of unique initial state IDs. |
| `states` | Objects with unique `id` values and a `facts` object assigning one in-domain value to every variable. |
| `transitions` | Objects with `from`, `to`, and `action`. An optional `guard` is a source-state assertion. An optional `succeeded` marks the outcome of that operation on this edge. |
| `properties` | Between 1 and 32 uniquely identified assertions or rule instances. |

A state property declares `id`, `scope: "state"`, and `assert`. A transition property declares `id`, `scope: "transition"`, and `assert`, with optional `action` to restrict its scope. A named rule declares `id`, `rule`, `action`, and the bindings or prerequisite listed below. Every edge with an action referenced by a named rule must explicitly declare Boolean `succeeded`. Named rules check enabled successful edges for that action.

### Named rules and example models

| Rule | Prerequisites on the source state | Models |
| --- | --- | --- |
| `authorization-bypass` | `authenticated` and `has_permission` must be true. | [Vulnerable](examples/authorization/permission-vulnerable.json), [corrected](examples/authorization/permission-corrected.json) |
| `mfa-bypass` | `authenticated` and `mfa_verified` must be true. | [Vulnerable](examples/mfa-bypass/export-vulnerable.json), [corrected](examples/mfa-bypass/export-corrected.json) |
| `session-revocation` | `session_status` must be the string `"active"`. | [Vulnerable](examples/session-revocation/session-vulnerable.json), [corrected](examples/session-revocation/session-corrected.json) |
| `permission-revocation` | `permission_revoked` must be false. | [Vulnerable](examples/authorization/revocation-vulnerable.json), [corrected](examples/authorization/revocation-corrected.json) |
| `one-time-operation-replay` | `consumed` must be false. | [Vulnerable](examples/password-reset/replay-vulnerable.json), [corrected](examples/password-reset/replay-corrected.json) |
| `workflow-precondition` | A model-defined `requires` expression must hold. | [Vulnerable](examples/approval-workflow/approval-vulnerable.json), [corrected](examples/approval-workflow/approval-corrected.json) |

For the first five rules, `bindings` maps each listed role name to a variable in `domains`. For instance, the MFA rule can bind `authenticated` to `signed_in` and `mfa_verified` to `factor_confirmed`. The workflow rule takes a `requires` expression in place of `bindings`.

### Policy expressions

Expressions are prefix arrays with explicit nesting. JSON Boolean and integer literals are accepted. A bare string references a variable in the current state, or in the source state during a transition check.

| Form | Meaning |
| --- | --- |
| `["literal", "name"]` | A string literal. |
| `["not", a]` | Negation. |
| `["and", a, b, ...]` | Conjunction of at least two operands. |
| `["or", a, b, ...]` | Disjunction of at least two operands. |
| `["implies", a, b]` | Material implication, equivalent to `["or", ["not", a], b]`. |
| `["equals", a, b]` and `["not-equals", a, b]` | Strict comparison between two values of the same type. |
| `["lt", a, b]`, `["lte", a, b]`, `["gt", a, b]`, `["gte", a, b]` | Ordered comparison between integers. |
| `["pre", "name"]` and `["post", "name"]` | Source and destination values in a custom transition assertion. |

Guards, workflow prerequisites, and state assertions use current facts. The expression validator checks every branch, including operands skipped by Boolean short-circuiting. A top-level assertion or guard must have Boolean type.

Phase 1 models use a `facts` list in place of `domains` and a single `invariant` in place of `properties`. Their unguarded transitions are enabled. See the original [MFA state-invariant example](examples/mfa-bypass/mfa-vulnerable.json) and its [corrected model](examples/mfa-bypass/mfa-corrected.json).

## Verification method

The engine computes a least fixed point over enabled edges and uses multi-source BFS to reconstruct shortest witnesses. State properties are checked from depth zero onward. Transition properties are checked against pre- and post-state values, including on edges between states that were already reached. [Mathematical verification notes](docs/verification.md)

| Module | Responsibility |
| --- | --- |
| `src/Models/` | Validate the JSON model and typed facts, then index its transitions. |
| `src/Mathematics/` | Evaluate typed expressions, compute reachability, and reconstruct shortest paths. |
| `src/Security/` | Analyze state and transition properties, classify findings, and explain counterexamples. |
| `src/Reporting/` | Stream JSON and readable text. |
| `bin/stateaxiom.php` | Read a local model, run the analyzer, and return the outcome's exit code. |

Model adequacy determines what a result says about an application. A finding demonstrates an assertion failure in the supplied graph. Runtime confirmation requires independent evidence. The analyzer covers explicit finite state and transition safety. Time and concurrency are considered only where encoded in the model, and liveness properties such as eventual progress are outside its scope.
