export const $ = selector => document.querySelector(selector);

export function node(tag, text = '', className = '') {
    const element = document.createElement(tag);
    if (className) element.className = className;
    element.textContent = text;
    return element;
}

export function chip(text, type = '') {
    return node('span', text, 'chip ' + type.toLowerCase());
}

export function button(label, handler, className = 'button secondary small') {
    const element = node('button', label, className);
    element.type = 'button';
    element.addEventListener('click', handler);
    return element;
}

export function link(label, href, className = 'button secondary small') {
    const element = node('a', label, className);
    element.href = href;
    return element;
}

export function empty(title, description = '') {
    const element = node('div', '', 'empty');
    element.append(node('strong', title));
    if (description) element.append(node('p', description));
    return element;
}

export function pretty(value) {
    return JSON.stringify(value, null, 2);
}

export function details(values) {
    const element = node('dl', '', 'details');
    for (const [key, value] of Object.entries(values)) {
        const text = typeof value === 'string' ? value : (JSON.stringify(value) ?? '—');
        element.append(node('dt', key), node('dd', text));
    }
    return element;
}

export function detailBlock(title, content) {
    const element = node('div', '', 'detail-block');
    element.append(node('h3', title), content);
    return element;
}

export function table(headers, rows) {
    const head = node('thead');
    const headRow = node('tr');
    for (const header of headers) {
        const cell = node('th', header);
        cell.scope = 'col';
        headRow.append(cell);
    }
    head.append(headRow);
    const body = node('tbody');
    for (const row of rows) {
        const tr = node('tr');
        for (const value of row) {
            const cell = node('td');
            if (value instanceof Node) cell.append(value);
            else cell.textContent = value ?? '—';
            tr.append(cell);
        }
        body.append(tr);
    }
    const wrapper = node('div', '', 'table-wrap');
    const element = node('table');
    element.append(head, body);
    wrapper.append(element);
    return wrapper;
}

export function apiUrl(route, params = {}) {
    const url = new URL(location.href);
    url.search = new URLSearchParams({api: route, ...params}).toString();
    url.hash = '';
    return url;
}

export async function api(route, params = {}, payload = undefined) {
    const options = {credentials: 'same-origin', redirect: 'error', headers: {'Accept': 'application/json'}};
    if (payload !== undefined) {
        options.method = 'POST';
        options.headers['Content-Type'] = 'application/json';
        options.headers['X-CSRF-Token'] = $('meta[name="csrf-token"]').content;
        options.body = JSON.stringify(payload);
    }
    const response = await fetch(apiUrl(route, params), options);
    const result = await response.json().catch(() => null);
    if (!response.ok || result === null) {
        throw new Error(result?.error || 'The local dashboard returned an unreadable response.');
    }
    return result;
}

export function limits() {
    const values = new FormData($('#verify-form'));
    return Object.fromEntries(['max_states', 'max_transitions', 'max_depth'].map(key => [key, Number(values.get(key))]));
}

export const categoryLabel = category => category.toLowerCase().replaceAll('_', ' ').replace(/^./, letter => letter.toUpperCase());

export function formatDate(date) {
    return new Intl.DateTimeFormat(undefined, {dateStyle: 'medium', timeStyle: 'short'}).format(new Date(date));
}
