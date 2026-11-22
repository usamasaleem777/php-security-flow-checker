# Mathematical visualization

## Fixed-point reachability

The view shows the declared initial set and lists the breadth-first layers observed by PHP. For each layer it displays the number of newly discovered states and the cumulative reached count. The least fixed point of `F(X) = I ∪ Postₑ(X)` is reported as reached when enabled successors close the reached set. A separate coverage flag accounts for applicable transitions that exploration limits left unchecked.

## Induction and Boolean logic

State properties show base-case checks over explored initial states and preservation checks over explored enabled edges with a source satisfying the policy. A failed preservation check means an explored edge led from a state satisfying the policy to one falsifying it. A general inductiveness proof also tests the implication over states outside the reachable fragment.

Transition properties show the number of applicable cases actually evaluated through the first failure or completion. The Boolean example uses the failure when present and the first evaluated case otherwise. It includes the expression result and up to 32 actually evaluated operands. Short-circuiting can leave some operands unevaluated.

## Directed paths and counterexamples

The real multi-source BFS assigns minimum action depths to reached states. A transition failure appends the failing action to a shortest path to the source. The dashboard shows the selected witness's initial state, length, action sequence, and failure context. A limited run with a genuine witness remains `VIOLATED` while incomplete coverage is explained separately.

The figures and tables describe execution on the supplied finite model. [Formal derivation and proof scope](verification.md) · [Runtime evidence semantics](EVIDENCE_MODEL.md)
