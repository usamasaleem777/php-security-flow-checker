# v0.1.0 research preview draft

PHP Security Flow Checker models finite security workflows, verifies reachable state and transition assertions, and generates shortest model counterexamples. The dashboard provides model inspection, SVG graphs, policy evaluation, comparisons, and report downloads. CLI and dashboard runtime dependencies remain native PHP.

The engine uses least fixed-point reachability, multi-source BFS, typed Boolean expressions, and checks of reachable initial states and transitions. The bundled model pairs cover authorization, MFA, session revocation, permission revocation, single-use operations, and workflow prerequisites.

Local validation on XAMPP PHP 8.2.12 for Windows included the Phase 1–4 suites, seeded cross-checks against an independent mathematical reference, and 80 benchmark worker runs. All three GitHub workflows passed local syntax validation. The configured Ubuntu and Windows matrix awaits hosted execution. [Mathematical validation](MATHEMATICAL_VALIDATION.md) · [Benchmark results](BENCHMARK_RESULTS.md)

Deploy locally on loopback using PHP 8.2+ with sessions enabled and `public/` as document root. Finite-model results depend on the model and exploration bounds. The corrected PHP controls supply separately scoped evidence. Phase 3 HTTP replay remains `NOT_RUN`. [Setup](DASHBOARD.md) · [Limitations](LIMITATIONS.md)

This draft proposes the initial research preview. The release checklist records publication gates. [Release checklist](RELEASE_CHECKLIST.md)
