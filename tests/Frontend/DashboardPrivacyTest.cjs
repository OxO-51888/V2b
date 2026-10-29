const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '../..');
const code = fs.readFileSync(path.join(root, 'public/assets/admin/dashboard-privacy.js'), 'utf8');
const bundle = fs.readFileSync(path.join(root, 'public/assets/admin/umi.js'), 'utf8');
function setup(value = 0, post = async () => ({code: 200, data: true})) {
    const window = {settings: {admin_dashboard_privacy: value, secure_path: 'local-only'}};
    const React = {
        Component: class {
            constructor(props) { this.props = props; this.updates = 0; }
            setState(value) { this.state = {...this.state, ...value}; this.updates++; }
            forceUpdate() { this.updates++; }
        },
        createElement(type, props, ...children) { return {type, props, children}; }
    };
    vm.runInNewContext(code, {window, Set, Promise});
    const api = window.AdminDashboardPrivacy;
    const Control = api.control(React, 'switch', post);
    const control = new Control({});
    control.componentDidMount();
    return {api, React, control, window};
}
test('both shipped scripts parse', () => {
    new vm.Script(code);
    new vm.Script(bundle);
});
test('disabled is the backwards-compatible default', () => {
    for (const value of [undefined, null, 0, '0', 'false']) assert.equal(setup(value).api.enabled(), false);
    for (const value of [1, '1']) assert.equal(setup(value).api.enabled(), true);
});
test('guard blocks first render before dashboard can mount or request totals', () => {
    const {api, React} = setup(1);
    const routes = [];
    const Guard = api.guard(React, 'dashboard', {replace: p => routes.push(p)});
    const guard = new Guard({});
    assert.equal(guard.render(), null);
    guard.componentDidMount();
    assert.deepEqual(routes, ['/queue']);
    api.apply(0);
    assert.equal(guard.render().type, 'dashboard');
    const updates = guard.updates;
    guard.componentWillUnmount();
    api.apply(1);
    assert.equal(guard.updates, updates);
});
test('normal dashboard props and mounting are preserved when disabled', () => {
    const {api, React} = setup();
    const Guard = api.guard(React, 'dashboard', {replace: () => assert.fail('unexpected redirect')});
    const props = {location: {pathname: '/dashboard'}};
    const guard = new Guard(props);
    assert.equal(guard.render().props, props);
    guard.componentDidMount();
    guard.componentWillUnmount();
});
test('switch saves only privacy, publishes only on success and can restore', async () => {
    const calls = [];
    const {api, control} = setup(0, async (url, data) => {
        calls.push({url, data: {...data}});
        return {code: 200, data: true};
    });
    const pending = control.save(true);
    assert.equal(api.enabled(), false);
    assert.equal(control.state.saving, true);
    await pending;
    assert.deepEqual(calls, [{url: '/local-only/config/save', data: {admin_dashboard_privacy: 1}}]);
    assert.equal(api.enabled(), true);
    assert.equal(control.state.saved, true);
    await control.save(false);
    assert.equal(api.enabled(), false);
    assert.equal(control.state.saving, false);
});
test('rapid double activation produces just one save', async () => {
    let release, count = 0;
    const {control} = setup(0, () => {count++; return new Promise(resolve => {release = resolve;});});
    const first = control.save(true);
    control.save(true);
    await Promise.resolve();
    assert.equal(count, 1);
    release({code: 200, data: true});
    await first;
});
test('failed, unauthorized and malformed saves retain the original state', async () => {
    for (const response of [{code: 500}, {code: 403}, {code: 200, data: false}, undefined]) {
        const {api, control} = setup(1, async () => response);
        await control.save(false);
        assert.equal(api.enabled(), true);
        assert.ok(control.state.error);
        assert.equal(control.state.saving, false);
    }
});
test('network failure can be retried without falsely hiding dashboard', async () => {
    let count = 0;
    const {api, control} = setup(0, async () => {
        if (!count++) throw new Error('offline');
        return {code: 200, data: true};
    });
    await control.save(true);
    assert.equal(api.enabled(), false);
    assert.equal(control.state.error, 'offline');
    await control.save(true);
    assert.equal(api.enabled(), true);
    assert.equal(control.state.error, '');
});
test('save completing after navigation updates guard without updating unmounted control', async () => {
    let release;
    const {api, control} = setup(0, () => new Promise(resolve => {release = resolve;}));
    const pending = control.save(true);
    await Promise.resolve();
    control.componentWillUnmount();
    const updates = control.updates;
    release({code: 200, data: true});
    await pending;
    assert.equal(api.enabled(), true);
    assert.equal(control.updates, updates);
});
test('switch has an accessible label, busy state and stable status space', () => {
    const {control} = setup();
    const node = control.render();
    assert.equal(node.children[1].props['aria-label'], '仪表盘隐私模式');
    assert.equal(node.children[0].props.style.minHeight, '22px');
    assert.equal(node.children[0].props.style.width, '180px');
});
test('bundle replaces only terms row and protects both route and menu', () => {
    assert.ok(!bundle.includes('defaultValue: t.tos_url'));
    assert.ok(bundle.includes('window.AdminDashboardPrivacy.guard(i.a, n("sFYk").default, s["default"])'));
    assert.ok(bundle.includes('if (n === "/dashboard" && window.AdminDashboardPrivacy.enabled()) return null;'));
    assert.ok(bundle.includes('window.AdminDashboardPrivacy.control(f.a, l["a"], q["b"])'));
    assert.ok(bundle.includes('key !== "admin_dashboard_privacy"'));
});
test('server bootstrap precedes app boot and uses site-owned config', () => {
    const blade = fs.readFileSync(path.join(root, 'resources/views/admin.blade.php'), 'utf8');
    assert.ok(blade.includes("admin_dashboard_privacy: {{ (int) config('v2board.admin_dashboard_privacy', 0) }}"));
    assert.ok(blade.indexOf('dashboard-privacy.js') < blade.indexOf('/assets/admin/umi.js'));
});
