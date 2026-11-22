<?php

declare(strict_types=1);

if (!isset($csrf)) {
    http_response_code(404);
    return;
}

$icons = [
    'overview' => 'M3 3h7v7H3zM14 3h7v4h-7zM14 11h7v10h-7zM3 14h7v7H3z',
    'models' => 'M3 20V6l4-2h5l2 3h7v13zM3 10h18',
    'verify' => 'M9 3h6m-5 0v7l-5 8a2 2 0 0 0 2 3h10a2 2 0 0 0 2-3l-5-8V3M8 15h8',
    'graph' => 'M5 5m-2 0a2 2 0 1 0 4 0a2 2 0 1 0-4 0M19 7m-2 0a2 2 0 1 0 4 0a2 2 0 1 0-4 0M10 19m-2 0a2 2 0 1 0 4 0a2 2 0 1 0-4 0M7 5l10 2M6 7l3 10m8-8-6 8',
    'counterexamples' => 'M4 5h6m4 14h6M7 5v10a4 4 0 0 0 4 4h3m2-3 3 3-3 3',
    'math' => 'M17 4H7l5 8-5 8h10M16 8h4m-2-2v4',
    'compare' => 'M4 3h6v18H4zM14 3h6v18h-6zM6 8h2m8 8h2',
    'reports' => 'M5 3h9l5 5v13H5zM14 3v5h5M8 12h8m-8 4h6',
    'docs' => 'M3 4h6l3 2 3-2h6v15h-6l-3 2-3-2H3zm9 2v15',
    'check' => 'M5 12l4 4L19 6',
    'alert' => 'M12 6v6m0 4h.01M12 2l10 18H2z',
    'play' => 'm8 4 12 8-12 8z',
    'activity' => 'M2 12h5l3-7 4 14 3-7h5',
    'menu' => 'M4 6h16M4 12h16M4 18h16',
    'reset' => 'M4 11a8 8 0 1 1 2 6M4 5v6h6',
    'expand' => 'M8 4H4v4m12-4h4v4m0 8v4h-4m-8 0H4v-4',
    'plus' => 'M5 12h14m-7-7v14',
    'minus' => 'M5 12h14',
    'terminal' => 'm5 7 5 5-5 5m7 0h7',
    'shield' => 'm12 3 8 3v5c0 5-4 8-8 10-4-2-8-5-8-10V6z',
];
$icon = static function (string $name) use ($icons): string {
    return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.65" ' .
        'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' . $icons[$name] . '"/></svg>';
};
$links = [
    'overview' => 'Overview',
    'models' => 'Security models',
    'graph' => 'State graph',
    'counterexamples' => 'Counterexamples',
    'math' => 'Mathematics',
    'compare' => 'Compare models',
    'reports' => 'Reports',
    'docs' => 'Documentation',
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= SecurityFlow\Web\Response::escape($csrf) ?>">
    <meta name="color-scheme" content="dark">
    <title>StateAxiom · Security Flow Checker</title>
    <link rel="stylesheet" href="assets/css/app.css">
    <script type="module" src="assets/js/app.js"></script>
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<div class="shell">
    <aside class="sidebar" id="sidebar">
        <a class="brand" href="#overview">
            <span class="brand-mark">
                <svg viewBox="0 0 36 36" fill="none" aria-hidden="true">
                    <path d="M18 3 31 10.5v15L18 33 5 25.5v-15L18 3Z" stroke="currentColor" stroke-width="1.7"/>
                    <path d="M11 22.5 18 12l7 10.5M11 22.5h14" stroke="currentColor" stroke-width="1.5"/>
                    <circle cx="18" cy="12" r="2" fill="currentColor"/>
                    <circle cx="11" cy="22.5" r="2" fill="currentColor"/>
                    <circle cx="25" cy="22.5" r="2" fill="currentColor"/>
                </svg>
            </span>
            <span><strong>StateAxiom</strong><small>Security Flow Checker</small></span>
        </a>
        <nav aria-label="Workspace">
            <p class="nav-label">Workspace</p>
            <?php foreach ($links as $key => $label): ?>
                <?php if ($key === 'reports'): ?><p class="nav-label nav-break">Resources</p><?php endif; ?>
                <a href="#<?= $key ?>" data-nav="<?= $key ?>"<?= $key === 'overview' ? ' aria-current="page"' : '' ?>>
                    <?= $icon($key) ?><span><?= $label ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-footer">
            <span class="engine-icon"><?= $icon('terminal') ?></span>
            <span><strong>Local workspace</strong><small>Core PHP verifier</small></span>
        </div>
    </aside>

    <div class="workspace">
        <header class="topbar">
            <div class="topbar-start">
                <button type="button" id="nav-toggle" class="icon-button nav-toggle" aria-expanded="false"
                        aria-controls="sidebar" aria-label="Toggle navigation"><?= $icon('menu') ?></button>
                <span class="breadcrumb"><span class="breadcrumb-root">Workspace <span aria-hidden="true">/</span></span><strong id="breadcrumb">Overview</strong></span>
            </div>
            <div class="evidence-badge" title="HTTP replay has not been performed for model findings">
                <?= $icon('shield') ?><span>HTTP replay</span><strong>NOT_RUN</strong>
            </div>
        </header>

        <main id="main" tabindex="-1">
            <div class="page-heading">
                <div>
                    <h1 id="page-title">Security overview</h1>
                    <p id="page-description">Verify security workflows and explore the evidence behind each result.</p>
                </div>
                <a class="button secondary" id="top-models" href="#models"><?= $icon('models') ?> Browse models</a>
            </div>
            <div id="notice" class="notice" role="status" aria-live="polite" hidden></div>
            <noscript><p class="notice">Enable JavaScript to select models and inspect verification results.</p></noscript>

            <section class="metrics" data-view="overview" aria-label="Session metrics">
                <article class="metric"><span class="metric-icon teal"><?= $icon('models') ?></span>
                    <div><span>Available models</span><strong id="metric-models">—</strong></div></article>
                <article class="metric"><span class="metric-icon blue"><?= $icon('activity') ?></span>
                    <div><span>Verification runs</span><strong id="metric-runs">0</strong></div></article>
                <article class="metric"><span class="metric-icon green"><?= $icon('check') ?></span>
                    <div><span>Verified properties</span><strong id="metric-verified">0</strong></div></article>
                <article class="metric"><span class="metric-icon rose"><?= $icon('alert') ?></span>
                    <div><span>Violated properties</span><strong id="metric-violated">0</strong></div></article>
            </section>

            <section class="workbench" data-view="overview graph" aria-label="Verification workbench">
                <div class="panel graph-panel">
                    <div class="panel-heading graph-heading">
                        <div><h2>State graph</h2><p id="graph-caption">Select a model to inspect its declared transitions.</p></div>
                        <span class="chip" id="graph-mode">Model preview</span>
                    </div>
                    <div class="graph-stage" id="graph-stage">
                        <svg id="graph-svg" role="group" aria-label="Interactive security state graph" viewBox="0 0 760 350">
                            <text x="380" y="175" class="graph-placeholder" text-anchor="middle">Loading model…</text>
                        </svg>
                        <div class="graph-tools" aria-label="Graph controls">
                            <button type="button" class="icon-button" data-graph="zoom-in" aria-label="Zoom in" title="Zoom in"><?= $icon('plus') ?></button>
                            <button type="button" class="icon-button" data-graph="zoom-out" aria-label="Zoom out" title="Zoom out"><?= $icon('minus') ?></button>
                            <button type="button" class="icon-button" data-graph="reset" aria-label="Reset graph view" title="Reset graph view"><?= $icon('reset') ?></button>
                        </div>
                    </div>
                    <div class="graph-footer">
                        <div class="legend" aria-label="Graph legend">
                            <span><i class="initial"></i>Initial</span><span><i class="reachable"></i>Reachable</span>
                            <span><i class="verified"></i>Verified</span><span><i class="violating"></i>Violation</span>
                        </div>
                        <span class="muted" id="graph-total">Loading graph</span>
                    </div>
                    <div class="graph-selection" id="graph-selection" hidden></div>
                </div>

                <div class="panel verify-panel">
                    <div class="panel-heading">
                        <div class="panel-title-with-icon"><span class="metric-icon teal"><?= $icon('verify') ?></span>
                            <div><h2>Run verification</h2><p>Explore reachable security states.</p></div>
                        </div>
                    </div>
                    <form id="verify-form">
                        <label for="model-select">Security model</label>
                        <select id="model-select" required><option value="">Loading models…</option></select>
                        <div class="model-selection">
                            <span id="model-variant" class="chip">Model</span>
                            <p id="model-description">Choose a bundled workflow.</p>
                        </div>
                        <div class="limits">
                            <label>Max states<input name="max_states" type="number" value="500" min="1" max="500" required></label>
                            <label>Max transitions<input name="max_transitions" type="number" value="2000" min="1" max="2000" required></label>
                        </div>
                        <details class="advanced">
                            <summary>Depth limit</summary>
                            <label>Max depth<input name="max_depth" type="number" value="64" min="0" max="64" required></label>
                        </details>
                        <button type="submit" class="button primary" id="run-button" disabled><?= $icon('play') ?> <span>Run verification</span></button>
                    </form>
                    <div class="last-result" id="last-result" aria-live="polite">
                        <span class="quiet-icon"><?= $icon('shield') ?></span>
                        <div><strong>Formal model evidence</strong><p>Results apply to the selected finite model.</p></div>
                    </div>
                </div>
            </section>

            <section class="overview-bottom" data-view="overview" aria-label="Recent results and finding categories">
                <div class="panel" id="recent-panel">
                    <div class="panel-heading"><div><h2>Recent verifications</h2><p>Results in this browser session.</p></div>
                        <a href="#reports" class="text-link">View reports</a></div>
                    <div id="recent-runs"></div>
                </div>
                <div class="panel">
                    <div class="panel-heading"><div><h2>Findings by category</h2><p>Counterexamples from completed runs.</p></div></div>
                    <div id="categories"></div>
                </div>
            </section>

            <section data-view="models" class="split-view" hidden>
                <div class="panel">
                    <div class="panel-heading"><div><h2>Model catalog</h2><p>Approved models bundled with the project.</p></div></div>
                    <div class="panel-body">
                        <label for="model-search">Search models</label>
                        <input type="search" id="model-search" placeholder="Search category or model">
                        <div id="model-list" class="model-list"></div>
                    </div>
                </div>
                <div class="panel">
                    <div class="panel-heading"><div><h2>Model details</h2><p>Initial states, domains, actions and policy definitions.</p></div></div>
                    <div id="model-inspector" class="panel-body"></div>
                </div>
            </section>

            <section data-view="counterexamples" hidden>
                <div class="panel">
                    <div class="panel-heading"><div><h2>Model findings</h2><p id="findings-context">Run a model to inspect shortest witnesses.</p></div>
                        <a id="witness-export" class="button secondary" hidden>Export counterexamples</a></div>
                    <div id="finding-browser" class="panel-body"></div>
                </div>
                <div class="panel witness-panel" id="witness-panel" hidden>
                    <div class="panel-heading"><div><h2>Shortest counterexample</h2><p id="path-title"></p></div>
                        <a href="#graph" class="button secondary">View in graph</a></div>
                    <div class="panel-body" id="witness-body"></div>
                </div>
            </section>

            <section data-view="math" class="math-layout" hidden>
                <div class="panel">
                    <div class="panel-heading"><div><h2>Knaster–Tarski fixed point</h2><p>Reachability grows by one breadth-first layer per iteration.</p></div></div>
                    <div class="panel-body" id="iteration-panel"></div>
                </div>
                <div class="panel">
                    <div class="panel-heading"><div><h2>Induction and Boolean logic</h2><p>Base cases, preservation, and actual policy evaluations.</p></div></div>
                    <div class="panel-body" id="proof-panel"></div>
                </div>
                <div class="panel">
                    <div class="panel-heading"><div><h2>Directed graph and BFS</h2><p>Each enabled transition contributes one action to a path.</p></div></div>
                    <div class="panel-body" id="bfs-panel"></div>
                </div>
            </section>

            <section data-view="compare" class="panel" hidden>
                <div class="panel-heading"><div><h2>Model comparison</h2><p>Verify both models using the current execution limits.</p></div></div>
                <div class="panel-body">
                    <form id="compare-form" class="compare-form">
                        <label>First model<select id="compare-left" required></select></label>
                        <label>Second model<select id="compare-right" required></select></label>
                        <button type="submit" class="button primary" id="compare-button" disabled><?= $icon('compare') ?> Compare models</button>
                    </form>
                    <div id="comparison"></div>
                </div>
            </section>

            <section data-view="reports" class="panel" hidden>
                <div class="panel-heading"><div><h2>Verification reports</h2><p>Open a run or download its actual analysis.</p></div></div>
                <div id="report-metrics" class="report-metrics"></div>
                <div id="all-runs"></div>
            </section>
            <section data-view="reports" class="panel witness-panel" hidden>
                <div class="panel-heading"><div><h2>Corrected fixture evidence</h2><p>Archived PHP control checks from a separate CLI run.</p></div></div>
                <div id="fixture-evidence" class="panel-body"></div>
            </section>

            <section data-view="docs" class="panel docs-panel" hidden>
                <div class="panel-heading"><div><h2>Using the dashboard</h2><p>Local mathematical verification and interpretation.</p></div></div>
                <div class="panel-body prose">
                    <h3>Select and verify</h3>
                    <p>Choose a bundled model and set state and transition limits. Verification calls the shared PHP analyzer.
                        The run report records any incomplete coverage independently of witnessed violations.</p>
                    <h3>Explore the graph</h3>
                    <p>Select a state or edge for facts and guard information. Drag the background to pan, use the zoom
                        controls for scale, and open a neighborhood if the view is truncated.</p>
                    <h3>Read the evidence</h3>
                    <p>Model counterexamples show reachable assertion failures in the supplied finite graph.
                        Corrected fixture checks are a separate CLI suite. HTTP replay is <code>NOT_RUN</code>.</p>
                    <pre><code>php tests/corrected-controls.php</code></pre>
                    <h3>Export a result</h3>
                    <p>Download JSON, HTML or shortest counterexample data from a run in Reports.</p>
                </div>
            </section>

            <footer class="main-footer">
                <span>StateAxiom</span><span>Finite-state verification · Local workspace</span>
            </footer>
        </main>
    </div>
</div>
</body>
</html>
