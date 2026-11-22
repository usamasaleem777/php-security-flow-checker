# PHP Security Flow Checker

Phase 1 is a Core PHP command-line model checker for finite security workflows. It verifies a Boolean safety invariant over all reachable states and returns a shortest counterexample when the invariant fails.

## Installation and usage

Use PHP 8.2 or later with the CLI available on your `PATH`. Open a terminal in the repository root and run these commands.

```sh
php -v
php tests/run.php
php bin/stateaxiom.php examples/mfa-bypass/mfa-vulnerable.json
php bin/stateaxiom.php examples/mfa-bypass/mfa-corrected.json
php bin/stateaxiom.php examples/mfa-bypass/mfa-corrected.json --max-depth 1
```

On XAMPP for Windows, use `C:\xampp\php\php.exe` if `php` is unavailable on your `PATH`. The verifier uses PHP's built-in JSON support. The test runner uses `proc_open` to check the CLI. Phase 1 runs in memory without a database or package installation. The project has selected [ThingEngineer's `MysqliDb`](https://github.com/ThingEngineer/PHP-MySQLi-Database-Class) for future MySQL persistence.

The verifier writes one JSON report to standard output. In Bash or Command Prompt, use `php bin/stateaxiom.php examples/mfa-bypass/mfa-corrected.json > mfa-report.json` to save it. In Windows PowerShell, use these commands to save UTF-8 JSON.

```powershell
$report = php bin/stateaxiom.php examples/mfa-bypass/mfa-corrected.json
[IO.File]::WriteAllLines((Join-Path $PWD 'mfa-report.json'), [string[]]$report)
```

| JSON `status` | Exit code | Meaning |
| --- | ---: | --- |
| `VERIFIED` | 0 | Reachability reached a fixed point and the invariant held in every reachable state. |
| `VIOLATED` | 1 | A reachable state falsified the invariant. The report includes a shortest trace from an initial state. |
| `INCOMPLETE` | 2 | The depth limit excluded reachable states and the explored states satisfied the invariant. |
| `ERROR` | 3 | A CLI, model, or verification error prevented a result. The report includes an `error` field. |

The optional depth limit is inclusive and counts transitions from the closest initial state. Initial states have depth zero. If the explored states already form a fixed point, the limit still allows a `VERIFIED` result. A witnessed violation returns `VIOLATED` even when the search is bounded.

## Model format

Supply a UTF-8 JSON file up to 1 MiB. The top-level object has six fields.

| Field | Meaning |
| --- | --- |
| `name` | A model identifier. |
| `facts` | A list of unique Boolean fact identifiers. |
| `initial` | A nonempty list of unique initial state IDs. |
| `states` | A list of objects with a unique `id` and a `facts` object assigning a JSON Boolean to each declared fact. |
| `transitions` | A list of objects with `from` and `to` state IDs and an `action` identifier. Every listed transition is possible. |
| `invariant` | A Boolean expression evaluated in each reachable state. |

Identifiers are case-sensitive strings of up to 64 ASCII letters, digits, underscores, or hyphens and start with a letter. Use unique JSON object member names. PHP's native JSON decoder keeps the last value when an object member name is repeated.

An expression can be `true`, `false`, a declared fact name, or one of these prefix forms. Operands can themselves be expressions.

| Form | Meaning |
| --- | --- |
| `["not", a]` | Negation. |
| `["and", a, b, ...]` | Conjunction of at least two operands. |
| `["or", a, b, ...]` | Disjunction of at least two operands. |
| `["implies", a, b]` | Material implication, equivalent to `["or", ["not", a], b]`. |

The MFA fixtures encode the invariant `protected_access → (primary_verified ∧ mfa_verified)`. The vulnerable abstract graph reaches a violating state in two transitions. The corrected graph reaches four states, all satisfying the invariant. See the generated [vulnerable report](examples/mfa-bypass/reports/mfa-vulnerable.json) and [corrected report](examples/mfa-bypass/reports/mfa-corrected.json).

## Verification method

Let $S$ be the finite state set, $I$ the set of initial states, and $\operatorname{Post}(X)$ the successors of states in $X$. The reachability operator is

$$F(X) = I \cup \operatorname{Post}(X).$$

If $X \subseteq Y$, then $F(X) \subseteq F(Y)$. By the Knaster–Tarski theorem, this monotone operator on the powerset lattice has a least fixed point. Iteration from $\varnothing$ reaches it in finitely many steps because the sets only grow and $S$ is finite. [Tarski's original paper](https://msp.org/pjm/1955/5-2/pjm-v5-n2-p11-s.pdf)

After the first iteration the set is $I$. By induction, each further iteration adds exactly the states reachable by one more transition. At the fixed point, the set contains $I$ and is closed under successors, so it contains every state on every finite path from $I$. Checking the invariant on the fixed point therefore checks it on all reachable states.

The verifier implements these iterations using multi-source BFS. Each layer expands only newly reached states. The first discovery of a state records a predecessor on a shortest path, and the first state that falsifies the invariant gives a shortest counterexample across all initial states. Input order breaks ties. The traversal scans each reached state and its outgoing edges once, plus the cost of evaluating the Boolean invariant. The tests cover implication and both De Morgan identities across all Boolean valuations of two facts.

## Architecture and scope

| Module | Responsibility |
| --- | --- |
| `src/Models/` | Represent states and actions, validate model JSON, and index successors. |
| `src/Mathematics/` | Evaluate Boolean expressions, compute reachability to a fixed point, and reconstruct shortest paths. |
| `src/Security/` | Check invariants, build counterexamples, and classify outcomes. |
| `src/Reporting/JsonReporter.php` | Encode verification results as JSON. |
| `bin/stateaxiom.php` | Run verification from the command line and return the outcome's exit code. |

The minimum-cut, PHP HTTP adapter, and SARIF files are reserved for future implementations. The password-reset, session-revocation, and approval-workflow directories are reserved for additional examples.

A `VERIFIED` result applies to the stated invariant and the supplied finite model. Its relevance to a real application depends on how faithfully the model captures that application's behavior. The verifier checks state safety properties. Time and concurrency matter only to the extent that the model encodes them in its states and transitions. Liveness properties such as eventual progress are outside this verification scope.
