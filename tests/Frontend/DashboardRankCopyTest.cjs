const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const {test} = require('node:test');
const source = fs.readFileSync(process.env.PANEL_ADMIN_BUNDLE || path.resolve(__dirname, '../../public/assets/admin/umi.js'), 'utf8');
const start = source.indexOf('class O extends l.a.Component {');
const end = source.indexOf('t["default"] = Object(u["c"])', start);
assert(start > 0 && end > start);

function fixture({clipboard, legacy = true, secure = true} = {}) {
    const events = new Map(), copied = [], notices = [];
    const chart = {getWidth: () => 500, getHeight: () => 400, convertToPixel: (_, index) => 80 + index * 25,
        setOption(value) { this.option = value; }, on(name, fn) { events.set(fn, name); }, off(name, fn) { events.delete(fn); }};
    const root = () => ({listeners: new Set(), contains: () => true,
        addEventListener(name, fn, capture) { assert.equal(name, 'click'); assert.equal(capture, true); this.listeners.add(fn); },
        removeEventListener(name, fn, capture) { assert.equal(capture, true); this.listeners.delete(fn); }});
    const dashboard = vm.runInNewContext('(' + source.slice(start, end).trim() + ')', {
        l: {a: {Component: class {}, createRef: () => ({current: root()})}},
        g: {b: () => chart},
        n: id => {
            if (id === 'tsqr') return {a: {success: value => notices.push(['success', value]), error: value => notices.push(['error', value])}};
            assert.equal(id, '+QRC');
            return (email, options) => { copied.push({email, options}); if (legacy instanceof Error) throw legacy; return legacy; };
        },
        navigator: {clipboard}, window: {isSecureContext: secure, removeEventListener() {}}
    });
    const instance = new dashboard({});
    return {instance, chart, events, copied, notices, click: event => [...events.keys()][0](event)};
}

test('today keeps all ranking values and displays 14px clickable email labels', () => {
    const f = fixture();
    const rows = [{email: 'first+tag@example.invalid', total: 300}, {email: 'second@example.invalid', total: 12}];
    f.instance.userTodayRankChartRender(rows);
    assert.equal(f.chart.option.yAxis.triggerEvent, true);
    assert.equal(f.chart.option.yAxis.axisLabel.fontSize, 14);
    assert.equal(f.chart.option.yAxis.axisLabel.width, 250);
    assert.equal(f.chart.option.yAxis.axisLabel.overflow, 'truncate');
    assert.equal(f.chart.option.xAxis.splitNumber, 3);
    assert.equal(f.chart.option.grid.right, 20);
    assert.deepEqual(Array.from(f.chart.option.yAxis.data), rows.map(row => row.email).reverse());
    assert.deepEqual(Array.from(f.chart.option.series[0].data), [12, 300]);
    assert.equal(rows[0].total, 300);
    f.click({componentType: 'yAxis', targetType: 'axisLabel', value: rows[0].email});
    assert.equal(f.copied[0].email, rows[0].email);
    assert.equal(f.copied[0].options.format, 'text/plain');
    assert.equal(f.notices[0][0], 'success');
});

test('responsive email label width keeps room for the traffic bars', () => {
    const f = fixture();
    f.instance.userTodayRankChartRender([]);
    for (const [width, expected] of [[350, 175], [500, 250], [900, 280], [180, 100]]) {
        f.chart.getWidth = () => width;
        assert.equal(f.instance.userTodayRankLabelWidth(), expected);
    }
});

test('non-email chart clicks, absent users and invalid labels do not copy', () => {
    const f = fixture();
    f.instance.userTodayRankChartRender([{email: 'null', total: 1}]);
    for (const event of [
        {componentType: 'series', targetType: 'axisLabel', value: 'null'},
        {componentType: 'xAxis', targetType: 'axisLabel', value: 'null'},
        {componentType: 'yAxis', targetType: 'axisLabel', value: 'other@example.invalid'},
        {componentType: 'yAxis', targetType: 'axisLabel', value: 'null'},
        {componentType: 'yAxis', targetType: 'axisName', value: 'null'}
    ]) f.click(event);
    for (const value of [null, undefined, '', 'null', '@invalid']) f.instance.copyRankEmail(value);
    assert.equal(f.copied.length, 0);
    assert.equal(f.notices.length, 0);
});

test('secure clipboard copies complete Unicode and plus-addressed emails without navigating', async () => {
    const copied = [];
    const f = fixture({clipboard: {writeText: async value => copied.push(value)}});
    const email = '用户+long-tag@example.invalid';
    await f.instance.copyRankEmail(email);
    assert.deepEqual(copied, [email]);
    assert.equal(f.copied.length, 0);
    assert.equal(f.notices[0][0], 'success');
});

test('clipboard rejection uses existing fallback, never reports false success', async () => {
    for (const legacy of [true, false, new Error('denied')]) {
        const f = fixture({clipboard: {writeText: async () => {throw new Error('denied');}}, legacy});
        await f.instance.copyRankEmail('test@example.invalid');
        assert.equal(f.copied.length, 1);
        assert.equal(f.notices.length, 1);
        assert.equal(f.notices[0][0], legacy === true ? 'success' : 'error');
    }
});

test('HTTP and synchronous clipboard failure fall back safely', async () => {
    for (const secure of [true, false]) {
        const f = fixture({secure, clipboard: {writeText() {throw new Error('denied');}}});
        await f.instance.copyRankEmail('http@example.invalid');
        assert.equal(f.copied[0].email, 'http@example.invalid');
        assert.equal(f.notices[0][0], 'success');
    }
});

test('refresh replaces the click handler; unmount removes it; empty ranking stays empty', () => {
    const f = fixture();
    f.instance.userTodayRankChartRender([{email: 'old@example.invalid', total: 1}]);
    f.instance.userTodayRankChartRender([]);
    assert.equal(f.events.size, 1);
    assert.equal(f.chart.option.yAxis.data.length, 0);
    f.click({componentType: 'yAxis', targetType: 'axisLabel', value: 'old@example.invalid'});
    assert.equal(f.copied.length, 0);
    f.instance.componentWillUnmount();
    assert.equal(f.events.size, 0);
    assert.equal(f.instance.userTodayRankChart.current.listeners.size, 0);
});

test('yesterday uses the same 14px labels and independent copy binding', () => {
    const f = fixture();
    f.instance.userTodayRankChartRender([{email: 'today@example.invalid', total: 2}]);
    f.instance.userLastRankChartRender([{email: 'yesterday@example.invalid', total: 1}]);
    assert.equal(f.chart.option.yAxis.axisLabel.fontSize, 14);
    assert.equal(f.chart.option.yAxis.triggerEvent, true);
    assert.equal(f.events.size, 2);
    assert.equal(f.instance.userLastRankChart.current.listeners.size, 1);
    f.instance.componentWillUnmount();
    assert.equal(f.events.size, 0);
    assert.equal(f.instance.userLastRankChart.current.listeners.size, 0);
});

test('native SVG click copies without the ECharts hit-test and prevents a duplicate copy', () => {
    const f = fixture();
    const email = 'native+copy@example.invalid';
    f.instance.userTodayRankChartRender([{email, total: 4}]);
    const label = {textContent: email, closest: () => label};
    let prevented = 0, stopped = 0;
    const handler = [...f.instance.userTodayRankChart.current.listeners][0];
    handler({target: label, button: 0, preventDefault: () => prevented++, stopPropagation: () => stopped++});
    assert.equal(f.copied[0].email, email);
    assert.equal(f.copied.length, 1);
    assert.equal(prevented, 1);
    assert.equal(stopped, 1);
    handler({target: {closest: () => ({textContent: '150'})}, button: 0});
    handler({target: label, button: 2});
    assert.equal(f.copied.length, 1);
});

test('native SVG truncation resolves identical prefixes by row position', () => {
    const f = fixture();
    const emails = ['matching-prefix-one@example.invalid', 'matching-prefix-two@example.invalid'];
    f.instance.userTodayRankChartRender(emails.map(email => ({email, total: 4})));
    const svg = {getBoundingClientRect: () => ({top: 100, height: 400})};
    const label = {textContent: 'matching-prefix...', getBoundingClientRect: () => ({top: 195, height: 20}),
        closest: selector => selector === 'svg' ? svg : label};
    [...f.instance.userTodayRankChart.current.listeners][0]({target: label, button: 0, preventDefault() {}, stopPropagation() {}});
    assert.equal(f.copied[0].email, emails[0]);
});
