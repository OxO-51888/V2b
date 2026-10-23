const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { test } = require('node:test');
const file = process.env.PANEL_ADMIN_BUNDLE || path.resolve(__dirname, '../../public/assets/admin/umi.js');
const source = fs.readFileSync(file, 'utf8');
const marker = source.indexOf('className: "block border-bottom mb-0 v2board-stats-bar"');
const start = source.lastIndexOf('l.a.createElement("div", {', marker);
const ending = '}, t.day_register_total ? t.day_register_total : "0")))))';
const end = source.indexOf(ending, start) + ending.length;
assert(start >= 0 && end > start);
const expression = source.slice(start, end);
const element = (type, props, ...children) => ({ type, props: props || {}, children });
function render(values) { return vm.runInNewContext(expression, { l: { a: { createElement: element } }, t: values, n: { site: { currency: 'CNY' } } }); }
function nodes(node) { return node && typeof node === 'object' ? [node, ...node.children.flatMap(nodes)] : []; }

test('online devices follows online users and keeps existing dashboard values', () => {
    const tree = nodes(render({ online_user: 130, day_income: 7500, day_register_total: 1, online_device: 8, online_device_window_seconds: 420 }));
    const labels = tree.filter(n => n.props.className?.includes('text-muted') && n.children.length === 1).map(n => n.children[0]);
    assert.deepEqual(labels, ['在线人数', '在线设备', '今日收入', 'CNY', '实时注册']);
    assert.deepEqual(tree.filter(n => n.props.className?.includes('display-4')).map(n => n.children[0]), [130, 8, '75.00', 1]);
    assert.match(tree.find(n => n.props['data-stat'] === 'online-device').props.title, /7/);
});

test('zero is a real count while missing statistics remain unknown', () => {
    for (const [value, shown] of [[0, 0], [null, '--'], [undefined, '--']]) {
        const tree = nodes(render({ online_device: value, online_device_window_seconds: 420 }));
        const card = tree.find(n => n.props['data-stat'] === 'online-device');
        assert.equal(nodes(card).find(n => n.props.className?.includes('display-4')).children[0], shown);
    }
});

test('dashboard row wraps on narrow screens and device metric has no mutation controls', () => {
    const tree = nodes(render({ online_device: 100000, online_device_window_seconds: 420 }));
    assert.match(tree.find(n => n.props['data-dashboard-live-stats']).props.className, /flex-wrap/);
    const card = tree.find(n => n.props['data-stat'] === 'online-device');
    assert.equal(nodes(card).filter(n => ['a', 'button', 'input'].includes(n.type) || n.props.onClick).length, 0);
});
