const namespace = 'http://www.w3.org/2000/svg';

function svgElement(name, attributes = {}, text) {
    const node = document.createElementNS(namespace, name);
    for (const [key, value] of Object.entries(attributes)) {
        node.setAttribute(key, String(value));
    }
    if (text !== undefined) node.textContent = text;
    return node;
}

function shorten(label, limit) {
    return label.length > limit ? label.slice(0, limit - 1) + '…' : label;
}

export class StateGraph {
    constructor(svg, onSelect) {
        this.svg = svg;
        this.onSelect = onSelect;
        this.nodeElements = new Map();
        this.edgeElements = new Map();
        this.base = [0, 0, 760, 350];
        this.view = [...this.base];
        this.drag = null;
        svg.setAttribute('tabindex', '0');
        svg.addEventListener('wheel', event => {
            if (event.ctrlKey || event.metaKey) {
                event.preventDefault();
                this.zoom(event.deltaY < 0 ? 1.18 : 1 / 1.18, this.position(event));
            }
        }, {passive: false});
        svg.addEventListener('pointerdown', event => {
            if (event.button !== 0 || event.target.closest('[data-graph-item]')) return;
            this.drag = {id: event.pointerId, x: event.clientX, y: event.clientY};
            svg.setPointerCapture(event.pointerId);
            svg.classList.add('panning');
        });
        svg.addEventListener('pointermove', event => {
            if (this.drag?.id !== event.pointerId) return;
            const scale = this.svg.getScreenCTM()?.a || 1;
            this.pan((this.drag.x - event.clientX) / scale, (this.drag.y - event.clientY) / scale);
            this.drag = {id: event.pointerId, x: event.clientX, y: event.clientY};
        });
        for (const type of ['pointerup', 'pointercancel', 'lostpointercapture']) {
            svg.addEventListener(type, () => {
                this.drag = null;
                svg.classList.remove('panning');
            });
        }
        svg.addEventListener('keydown', event => {
            const pans = {ArrowLeft: [-35, 0], ArrowRight: [35, 0], ArrowUp: [0, -35], ArrowDown: [0, 35]};
            if (event.key in pans) {
                event.preventDefault();
                this.pan(...pans[event.key]);
            } else if (['+', '=', '-', '0'].includes(event.key)) {
                event.preventDefault();
                this.control(event.key === '0' ? 'reset' : event.key === '-' ? 'zoom-out' : 'zoom-in');
            }
        });
    }

    position(event) {
        const matrix = this.svg.getScreenCTM();
        return matrix ? new DOMPoint(event.clientX, event.clientY).matrixTransform(matrix.inverse()) : null;
    }

    updateView() {
        this.svg.setAttribute('viewBox', this.view.join(' '));
    }

    pan(dx, dy) {
        this.view[0] += dx;
        this.view[1] += dy;
        this.updateView();
    }

    zoom(factor, origin = null) {
        const [x, y, width, height] = this.view;
        const scale = this.base[2] / width;
        const target = Math.min(6, Math.max(.65, scale * factor));
        const next = scale / target;
        const anchor = origin || {x: x + width / 2, y: y + height / 2};
        this.view = [anchor.x + (x - anchor.x) * next, anchor.y + (y - anchor.y) * next, width * next, height * next];
        this.updateView();
    }

    control(command) {
        if (command === 'reset') {
            this.view = [...this.base];
            this.updateView();
        } else {
            this.zoom(command === 'zoom-in' ? 1.22 : 1 / 1.22);
        }
    }

    layout(nodes) {
        const columns = new Map();
        const hasDepth = nodes.some(node => node.depth !== null);
        const lastDepth = hasDepth ? Math.max(...nodes.filter(node => node.depth !== null).map(node => node.depth)) : -1;
        for (const [index, node] of nodes.entries()) {
            const column = hasDepth ? (node.depth ?? lastDepth + 1) : index % Math.min(4, nodes.length);
            if (!columns.has(column)) columns.set(column, []);
            columns.get(column).push(node.id);
        }
        const groups = [...columns.entries()].sort((a, b) => a[0] - b[0]).map(entry => entry[1]);
        const width = Math.max(760, groups.length * 216 + 70);
        const height = Math.max(350, ...groups.map(group => group.length * 126 + 130));
        const coordinates = new Map();
        for (const [column, ids] of groups.entries()) {
            const x = groups.length === 1 ? width / 2 : 132 + column * (width - 264) / (groups.length - 1);
            for (const [row, id] of ids.entries()) {
                coordinates.set(id, {x, y: height / 2 + 20 + 126 * (row - (ids.length - 1) / 2)});
            }
        }
        this.base = [0, 0, width, height];
        return coordinates;
    }

    setData(data) {
        this.svg.replaceChildren();
        this.nodeElements.clear();
        this.edgeElements.clear();
        const coordinates = this.layout(data.nodes);
        const defs = svgElement('defs');
        for (const [kind, color] of Object.entries({default: '#63819e', path: '#54e1bd', violation: '#ff91a3', current: '#f4ca7f'})) {
            const marker = svgElement('marker', {
                id: 'arrow-' + kind, markerWidth: 7, markerHeight: 7, refX: 6, refY: 3.5, orient: 'auto', markerUnits: 'userSpaceOnUse',
            });
            marker.append(svgElement('path', {d: 'M0 0L7 3.5 0 7Z', fill: color}));
            defs.append(marker);
        }
        const edges = svgElement('g');
        const nodes = svgElement('g');
        this.svg.append(defs, edges, nodes);
        const parallels = new Map();
        for (const edge of data.edges) {
            const key = edge.from + ':' + edge.to;
            const count = parallels.get(key) || 0;
            parallels.set(key, count + 1);
            edges.append(this.edge(edge, coordinates.get(edge.from), coordinates.get(edge.to), count));
        }
        for (const node of data.nodes) {
            nodes.append(this.node(node, coordinates.get(node.id)));
        }
        this.control('reset');
    }

    interactive(node, item, type, label) {
        node.setAttribute('data-graph-item', item.id);
        node.setAttribute('role', 'button');
        node.setAttribute('tabindex', '0');
        node.setAttribute('aria-label', label);
        node.setAttribute('aria-pressed', 'false');
        node.prepend(svgElement('title', {}, label));
        const activate = () => {
            this.svg.querySelector('[aria-pressed="true"]')?.setAttribute('aria-pressed', 'false');
            node.setAttribute('aria-pressed', 'true');
            this.onSelect(type, item);
        };
        node.addEventListener('click', activate);
        node.addEventListener('keydown', event => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                activate();
            }
        });
        return node;
    }

    node(node, {x, y}) {
        const status = node.initial && node.status !== 'violating' ? 'Initial state' : ({
            unassessed: 'Model state', unreachable: 'Unreachable', reachable: 'Reachable',
            verified: 'Assertions hold', violating: 'Violation',
        }[node.status] || node.status);
        const group = svgElement('g', {class: 'graph-node', 'data-initial': node.initial, 'data-status': node.status});
        group.append(
            svgElement('rect', {class: 'node-box', x: x - 82, y: y - 28, width: 164, height: 56, rx: 9}),
            svgElement('circle', {class: 'node-dot', cx: x - 62, cy: y - 6, r: 4}),
            svgElement('text', {class: 'node-name', x: x - 50, y: y - 2}, shorten(node.id, 15)),
            svgElement('text', {class: 'node-status', x: x - 50, y: y + 15}, status.toUpperCase()),
        );
        this.nodeElements.set(node.id, group);
        return this.interactive(group, node, 'node', node.id + ', ' + status);
    }

    edge(edge, from, to, parallel) {
        const group = svgElement('g', {class: 'graph-edge', 'data-status': edge.status});
        let d;
        let labelX;
        let labelY;
        if (edge.from === edge.to) {
            const rise = 100 + parallel * 48;
            const top = from.y - 28;
            const width = 35 + parallel * 15;
            d = 'M' + (from.x - width) + ' ' + top + ' C' + (from.x - width - 35) + ' ' + (from.y - rise)
                + ' ' + (from.x + width + 35) + ' ' + (from.y - rise) + ' ' + (from.x + width) + ' ' + top;
            labelX = from.x;
            labelY = from.y - (rise * .75 + 8);
        } else if (from.x === to.x) {
            const direction = Math.sign(to.y - from.y) || 1;
            const x = from.x + 82;
            const bend = x + 55 + parallel * 22;
            d = 'M' + x + ' ' + from.y + ' C' + bend + ' ' + (from.y + 23 * direction)
                + ' ' + bend + ' ' + (to.y - 23 * direction) + ' ' + x + ' ' + to.y;
            labelX = bend + 5;
            labelY = (from.y + to.y) / 2;
        } else {
            const direction = Math.sign(to.x - from.x);
            const start = from.x + direction * 82;
            const end = to.x - direction * 82;
            const middle = (start + end) / 2;
            const bend = parallel * 42 + (direction < 0 ? 42 : 0);
            d = 'M' + start + ' ' + from.y + ' C' + middle + ' ' + (from.y - bend)
                + ' ' + middle + ' ' + (to.y - bend) + ' ' + end + ' ' + to.y;
            labelX = middle;
            labelY = (from.y + to.y) / 2 - 9 - bend * .75;
        }
        group.append(
            svgElement('path', {class: 'edge-hit', d}),
            svgElement('path', {class: 'edge-stroke', d}),
            svgElement('text', {class: 'edge-label', x: labelX, y: labelY, 'text-anchor': 'middle'}, shorten(edge.action, 22)),
        );
        this.edgeElements.set(edge.id, {element: group, edge});
        return this.interactive(group, edge, 'edge', edge.from + ' to ' + edge.to + ', ' + edge.action + ', ' + edge.status);
    }

    highlight(ids, initial, step, currentState) {
        const edges = new Set(ids.filter(Boolean));
        const nodes = new Set(initial ? [initial] : []);
        for (const [id, {element, edge}] of this.edgeElements) {
            element.dataset.path = edges.has(id);
            element.dataset.current = step > 0 && ids[step - 1] === id;
            if (edges.has(id)) {
                nodes.add(edge.from);
                nodes.add(edge.to);
            }
        }
        for (const [id, element] of this.nodeElements) {
            element.dataset.path = nodes.has(id);
            element.dataset.current = currentState === id;
        }
    }
}
