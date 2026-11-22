# Limitations

- The verifier establishes safety properties of the supplied finite model. Evidence about a real application depends on model fidelity and independent validation of implementation behavior.
- State, transition and depth budgets can leave coverage incomplete. Witnesses discovered inside those bounds remain valid model evidence. Unsupported or malformed expressions are rejected at validation.
- The checker covers typed finite facts, state invariants and transition predicates. It has no liveness proof, automatic PHP-source abstraction, or general inductiveness prover. Graph visualizations can display only a bounded portion of an exploration.
- The corrected PHP fixture suite measures direct fixture behavior. Phase 3 HTTP replay remains `NOT_RUN`. No current finding establishes HTTP reproduction.
- The dashboard serves an unauthenticated local developer session. Request, state, transition, graph and history caps bound its work. The underlying server manages request and execution timeouts. A timeout at that layer can end a response before the tool returns a verdict.
- The published benchmark archive uses two synthetic graph families and one Windows PHP 8.2.12 environment. It provides no OS comparison, production performance estimate or regression threshold.
- The configured GitHub Actions matrix still requires hosted execution. Workflow syntax validation and analysis performed locally are reported separately from hosted results.
- Minimum cut, SARIF export and PHP HTTP adapter files remain placeholders.
