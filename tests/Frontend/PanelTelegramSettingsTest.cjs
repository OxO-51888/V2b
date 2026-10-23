const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { test } = require('node:test');

const root = path.resolve(__dirname, '../..');
const bundlePath = process.env.PANEL_ADMIN_BUNDLE || path.join(root, 'public/assets/admin/umi.js');
const bundle = fs.readFileSync(bundlePath, 'utf8');
const modulePath = process.env.PANEL_MODULE_SCRIPT || path.join(root, 'public/assets/admin/panel-modules.js');
const createElement = (type, props, ...children) => ({ type, props: props || {}, children: children.flat(Infinity) });
const React = { createElement, Fragment: 'fragment', Component: class {
    setState(next, callback) { Object.assign(this.state, next); if (callback) callback(); }
} };
function nodes(tree) {
    if (!tree || typeof tree !== 'object') return [];
    return [tree, ...(tree.children || []).flatMap(nodes)];
}
function tab(integrated) {
    const marker = bundle.indexOf('tab: "Telegram",');
    assert.ok(marker > 0);
    const start = bundle.lastIndexOf('f.a.createElement(s["a"].TabPane, {', marker);
    const next = bundle.indexOf('tab: "APP",', marker);
    const end = bundle.lastIndexOf(', f.a.createElement(s["a"].TabPane, {', next);
    const context = { f: { a: React }, s: { a: { TabPane: 'tab' } }, m: 'setting',
        o: { a: 'button' }, l: { a: 'switch' }, b: false,
        y: { telegram_bot_token: 'fixture-old-token', telegram_bot_enable: 1, telegram_discuss_link: 'https://t.me/fixture' },
        window: { PanelModules: integrated ? { section: () => createElement('module') } : undefined } };
    return vm.runInNewContext(bundle.slice(start, end), context);
}
function moduleView(kind = 'telegram', response = () => ({})) {
    const requests = [];
    const context = { window: { settings: { secure_path: 'fixture' } },
        localStorage: { getItem: () => 'fixture-auth' },
        fetch: async (url, options) => { requests.push({ url, options }); return { ok: true, json: async () => ({ data: response(url, options) }) }; } };
    vm.runInNewContext(fs.readFileSync(modulePath, 'utf8'), context);
    const component = context.window.PanelModules.section(React, kind);
    const view = new component.type({});
    view.state = { ...view.state, loading: false, data: { bot_token_masked: '1234567890:************************', group: { chat_id: -123 } },
        form: { chat_id: -123, title: 'Not the group ID', enabled: true, verify_join: true, verify_message: true, revision: 'a'.repeat(64) } };
    view.state.data.panels = [{ ...view.state.form, entryDomain: '' }];
    return { view, requests };
}

test('integrated Telegram tab removes legacy token, webhook and notification controls', () => {
    const tree = tab(true);
    const settings = nodes(tree).filter(n => n.type === 'setting');
    assert.deepEqual(settings.map(n => n.props.title), ['\u7fa4\u7ec4\u5730\u5740']);
    assert.equal(nodes(tree).find(n => n.type === 'input').props.defaultValue, 'https://t.me/fixture');
    assert.equal(tree.children.at(-1).type, 'module');
});

test('nonintegrated panels keep their existing Telegram controls', () => {
    const settings = nodes(tab(false)).filter(n => n.type === 'setting');
    assert.deepEqual(settings.map(n => n.props.title), ['\u673a\u5668\u4ebaToken', '\u8bbe\u7f6eWebhook', '\u5f00\u542f\u673a\u5668\u4eba\u901a\u77e5', '\u7fa4\u7ec4\u5730\u5740']);
});

test('native gateway uses the panel identity without website enrollment controls', () => {
    const { view } = moduleView('gateway');
    view.state.form.id = 'panel_06';
    view.state.data.public_url = 'https://panel.example.invalid/api/v1/client-gateway';
    const labels = nodes(view.render()).filter(n => n.props.className === 'pm-field');
    assert.deepEqual(labels.map(n => n.children[0].children[0]), ['网站名称', '客户端名称', '本站网关地址', '本站标识', '反代域名', '客户端入口上游']);
    assert.equal(labels[3].children[1].props.value, 'panel_06');
    assert.equal(labels[3].children[1].props.readOnly, true);
    assert.doesNotMatch(fs.readFileSync(modulePath, 'utf8'), /gateway\/connect|GatewayEntry|EntryConnection/);
});

test('module renders editable credentials without exposing the saved Token', () => {
    const { view } = moduleView();
    const labels = nodes(view.render()).filter(n => n.props.className === 'pm-field');
    assert.deepEqual(labels.map(n => n.children[0].children[0]), ['Token', '\u7fa4\u7ec4 ID']);
    assert.deepEqual(labels.map(n => n.children[1].props.value), ['', -123]);
    assert.ok(labels.every(n => !n.children[1].props.readOnly));
    assert.equal(labels[0].children[1].props.type, 'password');
    assert.equal(labels[0].children[1].props.placeholder, '1234567890:************************');
    assert.equal(nodes(view.render()).filter(n => n.props.type === 'checkbox').length, 3);
});

test('automatic configuration explicitly sends only this site credentials and flags', async () => {
    const { view, requests } = moduleView();
    view.state.form.token = 'fixture-secret';
    view.state.form.chat_id = '-789';
    await view.save(true);
    assert.equal(requests[0].url, '/api/v1/fixture/modules/telegram/configure');
    assert.deepEqual(JSON.parse(requests[0].options.body), {
        token: 'fixture-secret', chat_id: '-789', enabled: true, verify_join: true,
        verify_message: true, revision: 'a'.repeat(64)
    });
});

test('editing credentials enables automatic configuration but prevents accidental ordinary save', () => {
    const { view } = moduleView();
    view.state.form.chat_id = '-789'; view.state.dirty = true;
    const buttons = nodes(view.render()).filter(n => n.type === 'button');
    const configure = buttons.find(n => n.children.includes(' \u81ea\u52a8\u914d\u7f6e'));
    const save = buttons.find(n => n.children.includes(' \u4fdd\u5b58'));
    assert.equal(configure.props.disabled, false);
    assert.equal(save.props.disabled, true);
});

test('saving group flags never submits shared credentials or group mapping', async () => {
    const { view, requests } = moduleView();
    view.state.form.token = 'fixture-secret';
    view.state.form.bot_token_masked = 'masked';
    await view.save();
    assert.deepEqual(JSON.parse(requests[0].options.body), {
        enabled: true, verify_join: true, verify_message: true, revision: 'a'.repeat(64)
    });
});

test('Telegram always reserves one feedback slot for empty, success and error states', () => {
    const { view } = moduleView();
    for (const state of [{ notice: '', error: '' }, { notice: 'Success', error: '' }, { notice: '', error: 'Error' }]) {
        Object.assign(view.state, state);
        const tree = view.render();
        const slot = nodes(tree).filter(n => n.props.className === 'pm-feedback');
        assert.equal(slot.length, 1);
        assert.equal(slot[0].props['aria-live'], 'polite');
        assert.equal(tree.children.indexOf(slot[0]), 1);
        assert.equal(nodes(tree).filter(n => n.props.className === 'pm-form').length, 1);
        assert.equal(nodes(slot[0]).filter(n => n.props.className?.startsWith('alert ')).length, state.notice || state.error ? 1 : 0);
    }
    const css = fs.readFileSync(path.join(root, 'public/assets/admin/panel-modules.css'), 'utf8');
    assert.match(css, /\.pm-feedback\s*\{\s*height:\s*48px;/);
    assert.match(css, /@media\s*\(max-width:\s*700px\)[\s\S]*?\.pm-feedback\s*\{\s*height:\s*88px;/);
});

test('Telegram retains the form while refreshing existing configuration', () => {
    const { view } = moduleView();
    view.state.loading = true;
    assert.equal(nodes(view.render()).filter(n => n.props.className === 'pm-form').length, 1);
    view.state.form = null;
    assert.equal(nodes(view.render()).filter(n => n.props.className === 'pm-form').length, 0);
});

test('gateway reserves feedback space and keeps the form while loading', () => {
    const { view } = moduleView('gateway');
    assert.equal(nodes(view.render()).filter(n => n.props.className === 'pm-feedback').length, 1);
    view.state.loading = true;
    assert.equal(nodes(view.render()).filter(n => n.props.className === 'pm-form').length, 1);
});

test('gateway omits the device list, empty placeholder and pagination', () => {
    const { view } = moduleView('gateway');
    view.state.rows = [{ deviceId: 'fixture-device', clientName: 'Fixture', version: '1.0.0' }];
    view.state.rowError = 'Fixture device lookup failed';
    const tree = nodes(view.render());
    assert.equal(tree.filter(n => ['pm-list-heading', 'pm-table-wrap', 'pm-pagination'].includes(n.props.className)).length, 0);
    assert.equal(tree.filter(n => n.type === 'table').length, 0);
    assert.equal(tree.filter(n => n.children.includes(view.state.rowError)).length, 0);
    assert.equal(tree.filter(n => n.props.className === 'pm-form').length, 1);
    assert.equal(tree.filter(n => n.props.className === 'pm-actions').length, 1);
});

test('gateway initial load, refresh and row loading never query device records', async () => {
    const { view, requests } = moduleView('gateway', () => ({ panels: [{ id: 'panel_06', entryDomain: '' }] }));
    view.alive = true;
    await view.load();
    await view.load();
    await view.loadRows(30);
    assert.deepEqual(requests.map(r => r.url), ['/api/v1/fixture/modules/gateway', '/api/v1/fixture/modules/gateway']);
    assert.ok(requests.every(r => r.options.method === 'GET'));
    assert.equal(view.state.form.id, 'panel_06');
});

test('Telegram still loads operation records and paginates independently', async () => {
    const event = { id: 8, created_at: 1, display_name: 'Fixture', action: 'verify', reason: 'Fixture event' };
    const { view, requests } = moduleView('telegram', url => url.includes('/events?')
        ? { items: [event], next_cursor: url.endsWith('=0') ? 8 : null }
        : { group: { chat_id: -123, enabled: true, verify_join: true, verify_message: true } });
    view.alive = true;
    await view.load();
    await new Promise(setImmediate);
    let tree = nodes(view.render());
    assert.equal(tree.filter(n => n.type === 'table').length, 1);
    assert.ok(tree.some(n => n.children.includes('Fixture event')));
    const next = tree.find(n => n.props['aria-label'] === '\u4e0b\u4e00\u9875');
    assert.equal(next.props.disabled, false);
    next.props.onClick();
    await new Promise(setImmediate);
    tree = nodes(view.render());
    assert.equal(view.state.offset, 8);
    assert.equal(tree.find(n => n.props['aria-label'] === '\u4e0b\u4e00\u9875').props.disabled, true);
    assert.equal(tree.find(n => n.props['aria-label'] === '\u4e0a\u4e00\u9875').props.disabled, false);
    assert.deepEqual(requests.map(r => r.url), ['/api/v1/fixture/modules/telegram', '/api/v1/fixture/modules/telegram/events?before=0', '/api/v1/fixture/modules/telegram/events?before=8']);
});

test('gateway domain uses configuration endpoint without sending another site identity', async () => {
    const { view, requests } = moduleView('gateway');
    view.state.form.entryDomain = 'entry.example.com';
    await view.save(true);
    assert.equal(requests[0].url, '/api/v1/fixture/modules/gateway/configure');
    assert.deepEqual(JSON.parse(requests[0].options.body), {domain: 'entry.example.com', revision: 'a'.repeat(64)});
});

test('domain-only changes enable configure and cannot be accidentally saved as basic settings', () => {
    const { view } = moduleView('gateway');
    view.change('entryDomain', 'entry.example.com');
    let buttons = nodes(view.render()).filter(n => n.type === 'button');
    assert.equal(buttons.find(n => n.children.includes(' 配置')).props.disabled, false);
    assert.equal(buttons.find(n => n.children.includes(' 保存')).props.disabled, true);
    view.change('name', 'Changed name');
    buttons = nodes(view.render()).filter(n => n.type === 'button');
    assert.equal(buttons.find(n => n.children.includes(' 配置')).props.disabled, true);
    assert.equal(buttons.find(n => n.children.includes(' 保存')).props.disabled, false);
});

test('download URL display decodes Chinese without changing escaped URL separators', () => {
    const context = {window: {}};
    vm.runInNewContext(fs.readFileSync(modulePath, 'utf8'), context);
    const display = context.window.PanelModules.displayDownloadUrl;
    const raw = 'https://download.invalid/%E4%BE%BF%E5%AE%9C%E6%9C%BA%E5%9C%BA-Windows.exe?file=a%2Fb%3Fc%23d%26e%3Df%25g%20h';
    const shown = display(raw);
    assert.ok(shown.includes('便宜机场-Windows.exe'));
    assert.ok(!shown.includes('%E4'));
    assert.equal(new URL(shown).href, new URL(raw).href);
    assert.equal(display('https://download.invalid/%E4%BE%ZZ.exe'), 'https://download.invalid/%E4%BE%ZZ.exe');
    assert.equal(display('https://download.invalid/便宜机场.exe'), 'https://download.invalid/便宜机场.exe');
    assert.equal(display(null), null);
    assert.equal(display(undefined), undefined);
    assert.equal(display(''), '');
});

test('all three download fields display readable URLs but keep existing save handlers', () => {
    const context = {window: {}};
    vm.runInNewContext(fs.readFileSync(modulePath, 'utf8'), context);
    const w = {}, calls = [];
    for (const key of ['windows', 'macos', 'android']) {
        w[key + '_version'] = '1.0.0';
        w[key + '_download_url'] = 'https://download.invalid/%E4%BE%BF%E5%AE%9C.exe';
    }
    const rows = [...bundle.matchAll(/f\.a\.createElement\("div", \{ className: "pm-app-downloads" \}, f\.a\.createElement\("input", \{[\s\S]*?\n                \}\)\)/g)];
    assert.equal(rows.length, 3);
    rows.forEach((row, index) => {
        const tree = vm.runInNewContext('(' + row[0] + ')', {window: context.window, f: {a: React}, w, set: (...args) => calls.push(args)});
        const input = tree.children[1];
        assert.equal(input.props.defaultValue, 'https://download.invalid/便宜.exe');
        input.props.onChange({target: {value: 'https://download.invalid/新版.exe'}});
        assert.deepEqual(calls.at(-1), ['app', ['windows', 'macos', 'android'][index] + '_download_url', 'https://download.invalid/新版.exe']);
    });
});
