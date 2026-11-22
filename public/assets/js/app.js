import {StateGraph} from './graph.js';
import {
    $, api, apiUrl, button, categoryLabel, chip, detailBlock, details, empty, formatDate, limits, link, node, pretty, table,
} from './verification.js';

const pages = {
    overview: ['Overview', 'Security overview', 'Verify security workflows and explore the evidence behind each result.'],
    models: ['Security models', 'Security models', 'Inspect the bundled workflows before verification.'],
    graph: ['State graph', 'Security state graph', 'Inspect model states, guard outcomes, and witness transitions.'],
    counterexamples: ['Counterexamples', 'Counterexamples', 'Follow the shortest computed witness for each violated property.'],
    math: ['Mathematics', 'Mathematical analysis', 'Inspect the fixed-point iterations and policy evaluations from a verification run.'],
    compare: ['Compare models', 'Compare security models', 'Examine outcomes and declared properties side by side.'],
    reports: ['Reports', 'Verification reports', 'Review the runs recorded in this browser session.'],
    docs: ['Documentation', 'Documentation', 'Interpret model and fixture evidence within its scope.'],
};

const all = selector => [...document.querySelectorAll(selector)];
const graph = new StateGraph($('#graph-svg'), selectGraphItem);
let catalog = [];
let inspected = null;
let activeRun = null;
let graphData = null;
let findingIndex = 0;
let pathStep = null;
let selectionVersion = 0;
let neighborhoodVersion = 0;
let busy = false;

function notice(message = '', isError = false) {
    const element = $('#notice');
    element.hidden = !message;
    element.textContent = message;
    element.classList.toggle('error', isError);
}

function setBusy(value) {
    busy = value;
    $('#run-button').disabled = value || !inspected;
    $('#compare-button').disabled = value || catalog.length < 2;
    $('#model-select').disabled = value;
    $('#run-button span').textContent = value ? 'Verifying…' : 'Run verification';
    $('#verify-form').setAttribute('aria-busy', String(value));
}

function navOpen(value) {
    $('#sidebar').classList.toggle('open', value);
    $('#nav-toggle').setAttribute('aria-expanded', String(value));
}

function route() {
    const hash = location.hash.slice(1);
    const page = Object.hasOwn(pages, hash) ? hash : 'overview';
    const [crumb, title, description] = pages[page];
    document.body.dataset.page = page;
    $('#breadcrumb').textContent = crumb;
    $('#page-title').textContent = title;
    $('#page-description').textContent = description;
    $('#top-models').hidden = page !== 'overview';
    for (const item of all('[data-nav]')) {
        if (item.dataset.nav === page) item.setAttribute('aria-current', 'page');
        else item.removeAttribute('aria-current');
    }
    for (const section of all('[data-view]')) {
        section.hidden = !section.dataset.view.split(' ').includes(page);
    }
    navOpen(false);
    if (!busy) notice();
    window.scrollTo(0, 0);
}

function fillSelectors() {
    for (const selector of ['#model-select', '#compare-left', '#compare-right']) {
        const select = $(selector);
        select.replaceChildren();
        for (const item of catalog) {
            const option = node('option', item.category + ' · ' + item.variant);
            option.value = item.id;
            select.append(option);
        }
    }
    if (catalog[1]) $('#compare-right').value = catalog[1].id;
    $('#metric-models').textContent = catalog.length;
}

function renderCatalog() {
    const query = $('#model-search').value.trim().toLowerCase();
    const list = $('#model-list');
    list.replaceChildren();
    for (const item of catalog) {
        if (!Object.values(item).join(' ').toLowerCase().includes(query)) continue;
        const card = button('', () => {
            if (!busy) void inspectModel(item.id);
        }, 'model-card');
        card.setAttribute('aria-pressed', String($('#model-select').value === item.id));
        const line = node('span', '', 'card-line');
        line.append(node('strong', item.category), chip(item.variant, item.variant));
        card.append(line, node('small', item.description));
        list.append(card);
    }
    if (!list.children.length) list.append(empty('No matching models.'));
}

function renderModel() {
    const inspector = $('#model-inspector');
    inspector.replaceChildren();
    if (!inspected) {
        inspector.append(empty('Select a model to inspect.'));
        return;
    }
    const {meta, model} = inspected;
    $('#model-variant').textContent = meta.variant;
    $('#model-variant').className = 'chip ' + meta.variant;
    $('#model-description').textContent = meta.description;
    inspector.append(
        node('h3', model.name, 'inspector-title'),
        chip(meta.variant, meta.variant),
        node('p', meta.description, 'scope-note'),
        details({'Declared states': model.states, 'Declared transitions': model.transitions, 'Initial states': model.initial.join(', ')}),
        detailBlock('Variable domains', details(model.domains)),
        detailBlock('Action definitions', details(model.actions)),
    );
    const definitions = node('div');
    for (const property of model.properties) {
        const propertyCard = node('div', '', 'proof-card');
        propertyCard.append(
            node('strong', property.id),
            node('p', property.scope + (property.action ? ' · ' + property.action : ''), 'scope-note'),
            node('pre', pretty(property.assertion)),
        );
        definitions.append(propertyCard);
    }
    inspector.append(detailBlock('Security properties', definitions));
    const controls = node('div', '', 'inspector-actions');
    controls.append(button('Verify this model', () => {
        location.hash = 'overview';
        requestAnimationFrame(() => $('#run-button').focus());
    }, 'button primary'), link('Inspect graph', '#graph'));
    inspector.append(controls);
}

function setGraph(data) {
    graphData = data;
    $('#graph-selection').hidden = true;
    if (!data) {
        $('#graph-svg').replaceChildren();
        $('#graph-total').textContent = 'Graph unavailable';
        return;
    }
    const modelName = inspected?.model.name ?? activeRun?.report.model ?? 'Security model';
    $('#graph-caption').textContent = modelName;
    $('#graph-mode').textContent = activeRun ? 'Verification result' : 'Model preview';
    $('#graph-total').textContent = data.nodes.length + ' of ' + data.state_count + ' states · '
        + data.edges.length + ' of ' + data.transition_count + ' transitions' + (data.truncated ? ' · Truncated' : '');
    graph.setData(data);
    highlightPath();
}

function currentFinding() {
    return activeRun?.report.findings[findingIndex] ?? null;
}

function highlightPath() {
    if (!graphData) return;
    const finding = currentFinding();
    const trace = finding?.counterexample;
    const ids = graphData.paths[finding?.property_id] || [];
    const current = pathStep === null ? null : pathStep === 0 ? trace?.initial_state : trace?.transitions[pathStep - 1]?.to;
    graph.highlight(ids, trace?.initial_state, pathStep, current);
}

async function findNeighborhood(focus) {
    const ticket = ++neighborhoodVersion;
    const params = focus ? {focus} : {};
    try {
        const runId = activeRun?.id;
        const selected = currentFinding();
        const data = runId
            ? await api('graph', {id: runId, ...params, ...(selected ? {property: selected.property_id} : {})})
            : (await api('model', {id: inspected.meta.id, ...params})).graph;
        if (ticket !== neighborhoodVersion) return;
        setGraph(data);
        if (focus) {
            const focused = data.nodes.find(state => state.id === focus);
            if (focused) selectGraphItem('node', focused);
        }
    } catch (error) {
        if (ticket === neighborhoodVersion) notice(error.message, true);
    }
}

function selectGraphItem(type, item) {
    const panel = $('#graph-selection');
    panel.replaceChildren(node('h3', type === 'node' ? item.id : item.action));
    if (type === 'node') {
        panel.append(details({Assessment: item.status, Initial: item.initial, Depth: item.depth ?? '—'}));
        panel.append(detailBlock('State facts', details(item.facts)));
        if (graphData.truncated) panel.append(button('Explore neighborhood', () => void findNeighborhood(item.id)));
    } else {
        panel.append(details({
            From: item.from, To: item.to, Assessment: item.status,
            Guard: item.guard ?? 'Unconditional', 'Modeled success': item.succeeded,
        }));
    }
    if (graphData.focus) panel.append(button('Reset neighborhood', () => void findNeighborhood(null)));
    panel.hidden = false;
}

function renderResult() {
    const box = $('#last-result');
    box.replaceChildren();
    if (!activeRun) {
        const label = node('div');
        label.append(node('strong', 'Formal model evidence'), node('p', 'Results apply to the selected finite model.'));
        box.append(label);
        return;
    }
    const report = activeRun.report;
    const info = node('div');
    const reasons = (report.incomplete_reasons ?? []).map(reason => reason.replaceAll('_', ' ')).join(', ');
    const coverage = report.analysis_complete ? 'Complete model coverage'
        : 'Incomplete model coverage' + (reasons ? ' · ' + reasons : '');
    info.append(chip(report.status, report.status), node('p', report.error || coverage));
    if (report.payload_note) info.append(node('p', report.payload_note));
    if (report.findings.length) info.append(link('Inspect counterexamples', '#counterexamples', 'text-link'));
    box.append(info);
}

async function inspectModel(id) {
    const ticket = ++selectionVersion;
    ++neighborhoodVersion;
    $('#model-select').value = id;
    $('#run-button').disabled = true;
    activeRun = null;
    inspected = null;
    setGraph(null);
    renderCatalog();
    renderResult();
    renderFindings();
    renderMath();
    notice();
    try {
        const response = await api('model', {id});
        if (ticket !== selectionVersion) return;
        inspected = response;
        renderModel();
        setGraph(response.graph);
    } catch (error) {
        if (ticket === selectionVersion) {
            setGraph(null);
            notice(error.message, true);
        }
    } finally {
        if (ticket === selectionVersion) setBusy(false);
    }
}

function activateRun(run) {
    ++selectionVersion;
    ++neighborhoodVersion;
    activeRun = run;
    findingIndex = 0;
    pathStep = null;
    $('#model-select').value = run.catalog_id;
    if (run.model) inspected = {model: run.model, meta: run.meta};
    renderCatalog();
    renderModel();
    setGraph(run.graph ?? null);
    renderResult();
    renderFindings();
    renderMath();
}

function renderHistory({runs, metrics}) {
    $('#metric-runs').textContent = metrics.runs;
    $('#metric-verified').textContent = metrics.verified_properties;
    $('#metric-violated').textContent = metrics.violated_properties;
    $('#report-metrics').replaceChildren(
        chip(metrics.runs + ' runs'), chip(metrics.incomplete + ' incomplete', 'incomplete'), chip(metrics.errors + ' errors', 'error'),
    );
    const recent = $('#recent-runs');
    const expanded = $('#all-runs');
    if (!runs.length) {
        recent.replaceChildren(empty('No verification runs yet.', 'Select a model and run verification to see results here.'));
        expanded.replaceChildren(empty('No reports in this session.', 'Each verification run creates a downloadable report.'));
    } else {
        recent.replaceChildren(table(['Model', 'Result', 'States', 'Findings'], runs.slice(0, 5).map(run => [
            button(run.model, () => void openRun(run.id), 'table-link mono'), chip(run.status, run.status), run.states, run.findings,
        ])));
        expanded.replaceChildren(table(['Model', 'Result', 'Run time', 'Exports'], runs.map(run => {
            const exports = node('div', '', 'table-actions');
            exports.append(
                link('JSON', apiUrl('report', {id: run.id, format: 'json'})),
                link('HTML', apiUrl('report', {id: run.id, format: 'html'})),
            );
            if (run.findings) exports.append(link('Trace', apiUrl('report', {id: run.id, format: 'counterexamples'})));
            return [
                button(run.model, () => void openRun(run.id), 'table-link mono'), chip(run.status, run.status),
                formatDate(run.created_at), exports,
            ];
        })));
    }
    const categories = Object.entries(metrics.categories);
    if (!categories.length) {
        $('#categories').replaceChildren(empty('No model findings yet.', 'Violations appear after a model run reports them.'));
        return;
    }
    const list = node('div', '', 'category-list');
    const max = Math.max(...categories.map(([, count]) => count));
    for (const [category, count] of categories) {
        const row = node('div', '', 'category-row');
        const label = categoryLabel(category);
        const meter = node('meter');
        meter.max = max;
        meter.value = count;
        meter.setAttribute('aria-label', label + ': ' + count);
        row.append(node('span', label), node('strong', count), meter);
        list.append(row);
    }
    $('#categories').replaceChildren(list);
}

async function renderFixtureEvidence() {
    const host = $('#fixture-evidence');
    host.replaceChildren(empty('Loading corrected fixture evidence…'));
    try {
        const {evidence} = await api('fixture-evidence');
        if (!evidence) {
            host.replaceChildren(empty('No recorded fixture evidence is available.'));
            return;
        }
        const checks = evidence.checks.map(check => {
            const observation = node('details');
            observation.append(node('summary', 'Expected and observed'), node('pre', pretty({
                expected: check.expected, observed: check.observed,
            })));
            return [check.id, chip(check.status, check.status === 'PASS' ? 'verified' : 'error'), observation];
        });
        host.replaceChildren(
            chip(evidence.evidence_type),
            node('p', 'PHP ' + evidence.php_version + ' · ' + evidence.passed + ' passed · ' + evidence.failed + ' failed', 'scope-note'),
            table(['Control', 'Result', 'Observation'], checks),
        );
    } catch (error) {
        host.replaceChildren(empty('Fixture evidence could not be loaded.', error.message));
    }
}

async function openRun(id) {
    if (busy) return;
    setBusy(true);
    try {
        const run = await api('run', {id});
        if (!run.model && inspected?.meta.id !== run.catalog_id) await inspectModel(run.catalog_id);
        activateRun(run);
        location.hash = 'graph';
        notice();
    } catch (error) {
        notice(error.message, true);
    } finally {
        setBusy(false);
    }
}

function renderFindings() {
    const browser = $('#finding-browser');
    const exportLink = $('#witness-export');
    const findings = activeRun?.report.findings ?? [];
    browser.replaceChildren();
    exportLink.hidden = !findings.length;
    if (!findings.length) {
        $('#findings-context').textContent = activeRun?.report.model || 'Run a model to inspect shortest witnesses.';
        browser.append(empty(activeRun ? 'No findings in this run.' : 'No verification selected.'));
        $('#witness-panel').hidden = true;
        return;
    }
    $('#findings-context').textContent = activeRun.report.model;
    exportLink.href = apiUrl('report', {id: activeRun.id, format: 'counterexamples'});
    for (const [index, finding] of findings.entries()) {
        const card = button('', () => {
            findingIndex = index;
            pathStep = null;
            for (const item of browser.children) item.setAttribute('aria-pressed', String(item === card));
            renderWitness();
            renderMath();
            highlightPath();
            if (graphData?.truncated) void findNeighborhood(null);
        }, 'finding-card');
        const line = node('span', '', 'card-line');
        line.append(node('strong', categoryLabel(finding.category)), chip(finding.evidence_type));
        card.setAttribute('aria-pressed', String(index === findingIndex));
        card.append(line, node('small', finding.id + ' · ' + finding.counterexample.length + ' actions'));
        browser.append(card);
    }
    renderWitness();
}

function renderWitness() {
    const finding = currentFinding();
    if (!finding) return;
    const trace = finding.counterexample;
    $('#witness-panel').hidden = false;
    $('#path-title').textContent = trace.length + ' actions from ' + trace.initial_state;
    const host = $('#witness-body');
    const steps = node('ol', '', 'path-steps');
    const setStep = (step, selector) => {
        pathStep = step;
        renderWitness();
        highlightPath();
        if (selector) host.querySelector(selector)?.focus();
    };
    const labels = ['Initial state · ' + trace.initial_state, ...trace.transitions.map(edge => edge.action + ' · ' + edge.to)];
    for (const [index, label] of labels.entries()) {
        const item = node('li');
        const stepButton = button(index + '  ' + label, () => setStep(index, '[data-step="' + index + '"]'));
        stepButton.dataset.step = index;
        if (index === pathStep) stepButton.setAttribute('aria-current', 'step');
        item.append(stepButton);
        steps.append(item);
    }
    const controls = node('div', '', 'path-controls');
    const actions = [
        ['restart', 'Start over', 0, false],
        ['prev', 'Previous step', Math.max(0, (pathStep ?? 0) - 1), pathStep === null || pathStep === 0],
        ['next', 'Next step', Math.min(trace.length, (pathStep ?? -1) + 1), pathStep === trace.length],
        ['all', 'Show entire path', null, false],
    ];
    for (const [name, label, step, disabled] of actions) {
        const control = button(label, () => setStep(step, '[data-command="' + name + '"]:enabled'));
        control.dataset.command = name;
        control.disabled = disabled;
        controls.append(control);
    }
    const summary = node('div');
    summary.append(
        details({Property: finding.property_id, Category: categoryLabel(finding.category), Evidence: finding.evidence_type}),
        node('p', finding.explanation, 'scope-note'),
        node('p', finding.remediation, 'scope-note'),
        detailBlock('Failed Boolean assertion', finding.assertion === null
            ? node('p', 'The expression was omitted from this compact report.') : node('pre', pretty(finding.assertion))),
    );
    const violation = trace.violation;
    if (pathStep === null || pathStep === trace.length) {
        const observed = violation.scope === 'state'
            ? {state: violation.state, facts: violation.facts}
            : {transition: violation.transition, pre: violation.pre, post: violation.post, succeeded: violation.succeeded};
        summary.append(detailBlock('Violating ' + violation.scope, node('pre', pretty(observed))));
    }
    if (pathStep > 0) {
        const edge = trace.transitions[pathStep - 1];
        const changes = edge.changes_omitted ? node('p', 'The fact changes were omitted from this compact report.')
            : Object.keys(edge.changes).length ? node('pre', pretty(edge.changes))
                : node('p', 'This transition changes no modeled facts.');
        summary.append(detailBlock('Changed facts', changes));
    }
    host.replaceChildren(summary, controls, steps);
}

function renderMath() {
    const layer = $('#iteration-panel');
    const proof = $('#proof-panel');
    const bfs = $('#bfs-panel');
    const math = activeRun?.mathematics;
    if (!math) {
        layer.replaceChildren(empty('Run a model to see reachability layers.'));
        proof.replaceChildren(empty('No policy evaluations to display.'));
        bfs.replaceChildren(empty('No traversal to inspect.'));
        return;
    }
    const iterations = node('ol', '', 'iterations');
    const total = activeRun.report.states_explored;
    for (const item of math.iterations) {
        const li = node('li', 'Depth ' + item.depth + ' · +' + item.new_states + ' states · ' + item.reached + ' reached');
        const meter = node('meter');
        meter.max = total;
        meter.value = item.reached;
        meter.setAttribute('aria-label', 'States reached at depth ' + item.depth);
        li.append(meter);
        iterations.append(li);
    }
    layer.replaceChildren(
        node('p', 'F(X) = I ∪ Postₑ(X)', 'formula'),
        details({'Initial set': math.initial_states.join(', '), 'Fixed point': math.fixed_point_reached ? 'Reached' : 'Coverage limited'}),
        iterations,
    );
    proof.replaceChildren(node('p', 'Preservation is checked over explored edges whose source satisfies the assertion. A general inductiveness proof also considers unreachable states.', 'scope-note'));
    for (const property of math.properties) {
        const card = node('div', '', 'proof-card');
        card.append(
            node('strong', property.id),
            details({Scope: property.scope, 'Evaluated cases': property.evaluated, 'Failure observed': property.violated}),
            node('pre', pretty(property.assertion)),
        );
        if (property.induction) {
            const {base, preservation} = property.induction;
            card.append(details({
                'Base states checked': base.checked + ' of ' + base.total,
                'Base failures': base.failed,
                'Preservation checks': preservation.checked,
                'Preservation failures': preservation.failed,
            }));
        }
        const example = property.example;
        if (example) {
            const explanation = node('details', '', 'detail-block');
            const location = example.state ?? (example.transition.from + ' · ' + example.transition.action + ' · ' + example.transition.to);
            explanation.append(
                node('summary', 'Boolean example · ' + String(example.result)),
                node('p', location, 'scope-note'),
                table(['Evaluated operand', 'Value'], example.operands.map(operand => [
                    JSON.stringify(operand.expression), JSON.stringify(operand.value),
                ])),
            );
            if (example.truncated) explanation.append(node('p', 'Showing the first 32 evaluated operands.', 'scope-note'));
            card.append(explanation);
        } else {
            card.append(node('p', 'No applicable case was evaluated.', 'scope-note'));
        }
        proof.append(card);
    }
    const finding = currentFinding();
    const witness = finding?.counterexample;
    bfs.replaceChildren(
        details({'Reached states': total, 'Explored enabled edges': activeRun.report.transitions_explored,
            'Edges considered': activeRun.report.transitions_considered}),
        node('p', witness ? 'BFS found a ' + witness.length + '-action witness starting at ' + witness.initial_state + '.'
            : 'No shortest violating path is available for this run.', 'scope-note'),
        link(witness ? 'Inspect witness' : 'Inspect graph', witness ? '#counterexamples' : '#graph', 'text-link'),
    );
}

function renderComparison(left, right) {
    const policy = run => run.model ? [...run.model.properties].sort((a, b) => a.id.localeCompare(b.id)) : null;
    const [a, b] = [policy(left), policy(right)];
    const same = a && b ? JSON.stringify(a) === JSON.stringify(b) : null;
    const propertyText = entry => entry?.map(property => property.id + ' ' + JSON.stringify(property.assertion)).join('\n') ?? 'Unavailable';
    const result = table(['Measure', left.report.model, right.report.model], [
        ['Model result', chip(left.report.status, left.report.status), chip(right.report.status, right.report.status)],
        ['Coverage', left.report.analysis_complete ? 'Complete' : 'Incomplete', right.report.analysis_complete ? 'Complete' : 'Incomplete'],
        ['Reachable states', left.report.states_explored, right.report.states_explored],
        ['Explored transitions', left.report.transitions_explored, right.report.transitions_explored],
        ['Counterexamples', left.report.finding_count ?? left.report.findings.length, right.report.finding_count ?? right.report.findings.length],
        ['HTTP replay', left.report.http_replay_status, right.report.http_replay_status],
        ['Assertions', node('pre', propertyText(a)), node('pre', propertyText(b))],
        ['Reports', link('JSON report', apiUrl('report', {id: left.id})), link('JSON report', apiUrl('report', {id: right.id}))],
    ]);
    const text = same === null ? 'Property definitions are unavailable for one or both runs.'
        : same ? 'The declared property definitions match. Each graph was verified independently.'
            : 'The declared property definitions differ. Review their assertions and scopes.';
    $('#comparison').replaceChildren(node('p', text, 'scope-note'), result);
}

$('#nav-toggle').addEventListener('click', () => navOpen($('#nav-toggle').getAttribute('aria-expanded') === 'false'));
document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && $('#sidebar').classList.contains('open')) {
        navOpen(false);
        $('#nav-toggle').focus();
    }
});
for (const item of all('[data-nav]')) item.addEventListener('click', () => navOpen(false));
$('.skip-link').addEventListener('click', event => {
    event.preventDefault();
    $('#main').focus();
});
for (const control of all('[data-graph]')) control.addEventListener('click', () => graph.control(control.dataset.graph));
$('#model-search').addEventListener('input', renderCatalog);
$('#model-select').addEventListener('change', event => void inspectModel(event.currentTarget.value));
$('#verify-form').addEventListener('invalid', event => {
    const advanced = event.target.closest('details');
    if (advanced) advanced.open = true;
}, true);
$('#verify-form').addEventListener('submit', async event => {
    event.preventDefault();
    if (busy || !inspected) return;
    const options = {model: inspected.meta.id, ...limits()};
    setBusy(true);
    notice('Running finite-state verification…');
    try {
        const run = await api('run', {}, options);
        activateRun(run);
        notice(run.report.error || 'Verification recorded · ' + run.report.status, Boolean(run.report.error));
        renderHistory(await api('runs'));
    } catch (error) {
        notice(error.message, true);
    } finally {
        setBusy(false);
    }
});
$('#compare-form').addEventListener('submit', async event => {
    event.preventDefault();
    if (busy) return;
    if (!$('#verify-form').checkValidity()) {
        notice('Correct the execution limits in the verification form before comparing.', true);
        return;
    }
    setBusy(true);
    notice('Verifying both selected models…');
    try {
        const options = limits();
        const left = await api('run', {}, {model: $('#compare-left').value, ...options});
        const right = await api('run', {}, {model: $('#compare-right').value, ...options});
        renderComparison(left, right);
        renderHistory(await api('runs'));
        notice('Both model runs were recorded.');
    } catch (error) {
        notice(error.message, true);
    } finally {
        setBusy(false);
    }
});
window.addEventListener('hashchange', route);
route();
renderFindings();
renderMath();
$('#recent-runs').replaceChildren(empty('Loading session results…'));
$('#categories').replaceChildren(empty('Loading session findings…'));

async function boot() {
    try {
        const [models, history] = await Promise.all([api('models'), api('runs')]);
        catalog = models.models;
        fillSelectors();
        renderHistory(history);
        if (history.runs.length) {
            const run = await api('run', {id: history.runs[0].id});
            if (!run.model && catalog.length) await inspectModel(run.catalog_id);
            activateRun(run);
            setBusy(false);
        } else if (catalog.length) {
            await inspectModel(catalog[0].id);
        } else {
            notice('No approved models are available.');
            setGraph(null);
        }
    } catch (error) {
        notice(error.message, true);
        setGraph(null);
    }
}

void boot();
void renderFixtureEvidence();
