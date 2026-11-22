# Graph visualization

The native SVG shows the model's directed states and transitions. A preview shows declared states and edges. After a run, the graph uses the depths, evaluated guards, and exact witness edges recorded by the PHP engine. Separate transitions with identical endpoints and action names retain distinct graph IDs.

| Item | Meaning |
| --- | --- |
| Initial state | The model declares the state as an initial state. |
| Reachable | The completed portion of BFS discovered the state. |
| Verified state | Every state invariant was observed to hold at this state. Models with only transition properties can have reachable states without a verified-state label. |
| Violation | A state or transition was identified as a witness for a false security assertion. |
| Unreachable | The state was undiscovered after reachability closed. |
| Disabled | The guard evaluated to false in the explored source state. |
| Boundary | The guard enabled the edge, and the depth limit left its transition assertion unexamined. |
| Unassessed | The preview or bounded run has no observation for the item. |

Drag the background to pan or use the arrow keys with graph focus. Use the zoom controls, or focus the graph and press `+` or `-`. The reset button and `0` restore the original viewport. Select a node or edge by click, Enter, or Space to inspect facts, guard expressions, and assessment.

The counterexample viewer highlights the selected property witness. Step zero is its actual initial state. Subsequent steps show the recorded actions and resulting states, with changed facts in the details. The final detail shows where the assertion failed.

The footer gives the displayed and declared totals. When a model exceeds 48 nodes or 96 transitions in a single view, the graph shows a truncated subgraph. Select a visible node and choose **Explore neighborhood** to prioritize that node and its adjacent edges. The path component retains step positions even when a graph edge is omitted. An earlier run's neighborhood view uses its saved observations and checks the current model's fingerprint before rendering.
