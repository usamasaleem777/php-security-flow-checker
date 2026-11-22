# Mathematical verification

## Reachability over enabled transitions

Let $S$ be a finite set of states with typed variable assignments, and let $I \subseteq S$ contain the initial states. A declared edge $s \xrightarrow{a} t$ is enabled when its Boolean guard holds on the assignment for $s$. An omitted guard is true. Guards depend only on the declared source state, so the enabled relation is fixed for a given model.

Define the reachability operator on the powerset of $S$ by

$$F(X) = I \cup \operatorname{Post}_{\mathrm{enabled}}(X).$$

If $X \subseteq Y$, every enabled successor of $X$ is also an enabled successor of $Y$. Thus $F$ is monotone on the complete powerset lattice. The Knaster–Tarski theorem gives a least fixed point. Starting from the empty set, iteration reaches that point in finitely many steps because the successive sets grow within $S$. [Tarski's original paper](https://msp.org/pjm/1955/5-2/pjm-v5-n2-p11-s.pdf)

The first iterate contains exactly the initial states. Inductively, the next iterate contains exactly the states reachable in one more transition or fewer. At convergence, the reached set $R$ is exactly the set of states accessible by a finite enabled path from $I$. Multi-source breadth-first search implements the iterations by expanding only the newly discovered states in each layer.

## Safety and the scope of induction

A state assertion $P$ is checked for each $s \in R$, including the initial states. A transition assertion $Q$ is checked for each applicable enabled edge from $s \in R$, using the facts of $s$ and $t$. A named operation rule is applicable when the action matches and the edge records successful completion. An enabled edge can falsify $Q$ even when both endpoints are already in $R$.

The BFS proof establishes exhaustive coverage of the finite reachable fragment. A stronger inductive proof of $P$ over all modeled states would require $I \subseteq P$ and closure of $P$ under every enabled transition from any state that satisfies $P$. An unreachable state can satisfy $P$ and have a successor that falsifies $P$. The reachable-state check can verify $P$ in that model, while the stronger closure obligation for $P$ fails. The checker evaluates reachability safety and does not attempt to synthesize stronger inductive assertions. [Clarke, Emerson, and Sistla on finite-state model checking](https://www.cs.cmu.edu/~emc/papers/Conference%20Papers/Automatic%20verification%20of%20finite%20state%20concurrent%20system%20using%20temporal%20logic%20specifications%20a%20practical%20approach.pdf)

## Shortest counterexamples and bounded checks

Every transition has unit cost. BFS discovers states in increasing minimum distance from $I$ and records the first predecessor. A state witness uses that predecessor chain. A transition witness uses a shortest chain to its source and appends the violating edge. Edges are examined in increasing source depth, so the first violation of a given transition property has minimum action count. Input order resolves ties.

The depth budget includes states at the maximum depth. Edges from that layer can still establish whether the reached set is closed, but checking them as transitions would require a witness one step beyond the budget. If a property applies to such an unchecked edge, analysis remains incomplete even when the reached state set is closed.

When a state budget rejects a discovery, traversal finishes examining the current source layer and stops before expanding the next layer. Present findings remain shortest. This preserves a witnessed violation even when other properties remain unsettled. A complete result establishes only the requested properties of the supplied model.
