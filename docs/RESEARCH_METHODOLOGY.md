# Research methodology

The unit of analysis is a finite transition system with explicit typed facts, initial states, guards and assertions. Ground truth comes from independently evaluated synthetic graphs, rather than application names or report labels.

| Question | Evidence | Scope of the conclusion |
| --- | --- | --- |
| RQ1. Does the engine find reachable safety violations? | Compare the production worklist with repeated full relation scans and evaluate predicates with native Boolean operations. | Agreement on the generated cases and bundled models. |
| RQ2. Are counterexamples valid and shortest? | Validate each transition against the input graph, then independently calculate minimum distances by synchronous edge relaxation. | Unit-cost state and transition witnesses. |
| RQ3. How does elapsed time vary with graph size? | Measure repeated runs of two deterministic model families across eight configured sizes. | Instrumented engine time on one Windows environment. |
| RQ4. How much memory does verification use? | Reset and read the PHP allocator peak in each fresh worker. | Allocator reservations and used PHP bytes during the interval. |
| RQ5. Which modeled violations can the checker express and detect? | Verify vulnerable and corrected in-memory model pairs for six named authorization and workflow categories. | Synthetic model coverage for those rule templates. |
| RQ6. Which claims need other evidence? | Separate finite-model findings, direct corrected fixture checks, and HTTP replay status. | Runtime behavior and production assurance require further evidence. |

## Validation procedure

Run `tests/run.php`, `tests/reference.php`, `tests/corrected-controls.php`, `tests/dashboard.php`, and `tests/security.php`. The randomized reference tests record seed `20261122`. They evaluate 160 base graphs with one to seven states, several guard and transition densities, multiple initial-state counts, and up to three properties. The checks also exercise transformations and resource bounds. [Mathematical validation](MATHEMATICAL_VALIDATION.md)

## Performance procedure

Run the benchmark with the recorded seed, workload options, repetition count and PHP limits. The worker parses the input before measuring verification with `hrtime`. It records graph facts and transition and assertion evaluation counts alongside timings and memory. Run on an otherwise quiet machine when comparing measurements. Repeated samples characterize variation within the tested environment. The runs do not establish asymptotic complexity, precision or recall on production code, or a performance comparison with external tools. [Benchmark protocol](../benchmark/README.md) · [Recorded results](BENCHMARK_RESULTS.md)
