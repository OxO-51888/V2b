const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const base = path.resolve(__dirname, '../..');
const source = fs.readFileSync(path.join(base, 'public/community/resources.js'), 'utf8');
class Element {
    constructor(tag) { this.tagName = tag.toUpperCase(); this.children = []; this.dataset = {}; this.attributes = {}; }
    append(...items) { for (const item of items) this.children.push(...(item.tagName === '#FRAGMENT' ? item.children : [item])); }
    replaceChildren(...items) { this.children = []; this.append(...items); }
    setAttribute(key, value) { this.attributes[key] = value; }
    addEventListener() {}
}
async function render(config, options = {}) {
    const roots = new Map();
    const events = {};
    const requests = [];
    let auth = options.auth === false ? '' : 'local-only';
    const document = {
        body: new Element('body'),
        createElement: tag => new Element(tag),
        createDocumentFragment: () => new Element('#fragment'),
        getElementById(id) {
            if (!roots.has(id)) { const item = new Element('div'); item.parentElement = new Element('div'); roots.set(id, item); }
            return roots.get(id);
        }
    };
    vm.runInNewContext(source, {
        document, location: {origin: 'https://local.test', search: options.nini ? '?client=nini' : '?client=default'},
        localStorage: {getItem: () => auth},
        window: {addEventListener(name, handler) { events[name] = handler; }},
        URL, URLSearchParams, AbortController, Set, setTimeout, clearTimeout,
        async fetch(url, init) {
            requests.push({url, init});
            if (options.logoutDuringLoad) auth = 'changed';
            return {ok: !options.failed, status: options.failed ? 500 : 200,
                json: async () => ({data: url.includes('/comm/config') ? config : [], total: 0})};
        }
    });
    await new Promise(resolve => setImmediate(resolve));
    return {cards: roots.get('client-list').children, document, requests, events};
}
const platforms = ['windows', 'android', 'macos'];
const config = Object.fromEntries(platforms.flatMap(key => [
    [key + '_version', '1.2.3'], [key + '_download_url', 'https://primary.test/' + key],
    [key + '_download_url_2', 'https://mirror.test/' + key]
]));
test('changed JavaScript parses', () => {
    new vm.Script(source);
    new vm.Script(fs.readFileSync(path.join(base, 'public/assets/admin/umi.js'), 'utf8'));
});
for (const nini of [false, true]) {
    test('both independent links, platform mapping and safe new tab: ' + (nini ? 'NINI' : 'default'), async () => {
        const result = await render(config, {nini});
        assert.equal(result.document.body.dataset.theme, nini ? 'nini' : 'default');
        assert.equal(result.cards.length, 3);
        result.cards.forEach((card, i) => {
            const actions = card.children[1].children;
            assert.equal(actions.length, 2);
            actions.forEach((action, index) => {
                assert.equal(action.tagName, 'A');
                assert.equal(action.href, `https://${index ? 'mirror' : 'primary'}.test/${platforms[i]}`);
                assert.equal(action.children[1].textContent, '下载  ' + (index + 1));
                assert.equal(action.rel, 'noopener noreferrer');
                assert.equal(action.target, '_blank');
            });
        });
        assert.equal(result.requests.length, 2);
        assert.ok(result.requests.every(request => !request.init.body && request.init.cache === 'no-store'));
    });
}
test('empty mirror is disabled; original link is unchanged', async () => {
    const data = {...config};
    for (const key of platforms) delete data[key + '_download_url_2'];
    const {cards} = await render(data);
    cards.forEach((card, i) => {
        const [one, two] = card.children[1].children;
        assert.equal(one.href, config[platforms[i] + '_download_url']);
        assert.equal(two.tagName, 'BUTTON');
        assert.equal(two.disabled, true);
        assert.equal(two.href, undefined);
    });
});
test('mirror works with primary missing; clearing one does not change the other', async () => {
    const data = {...config, windows_download_url: null, android_download_url_2: ''};
    const {cards} = await render(data);
    assert.equal(cards[0].children[1].children[0].disabled, true);
    assert.equal(cards[0].children[1].children[1].href, config.windows_download_url_2);
    assert.equal(cards[1].children[1].children[1].disabled, true);
    assert.equal(cards[1].children[1].children[0].href, config.android_download_url);
});
test('missing configuration keeps all six controls disabled', async () => {
    const {cards} = await render({});
    assert.equal(cards.length, 3);
    assert.ok(cards.every(card => card.children[1].children.every(button => button.disabled && !button.href)));
});
test('script/data URLs and credential-bearing links never become clickable', async () => {
    const data = {...config, windows_download_url_2: 'javascript:alert(1)', android_download_url_2: 'data:text/html,bad', macos_download_url_2: 'https://user:secret@example.test/'};
    const {cards} = await render(data);
    assert.ok(cards.every(card => card.children[1].children[1].disabled));
});
test('Chinese filename and signed query remain semantically intact', async () => {
    const data = {...config, windows_download_url_2: 'https://mirror.test/便宜.exe?key=a%26b&sig=c%2Fd'};
    const {cards} = await render(data);
    assert.equal(cards[0].children[1].children[1].href, new URL(data.windows_download_url_2).href);
});
for (const options of [{auth: false}, {logoutDuringLoad: true}, {failed: true}]) {
    test('auth/load failure cannot show stale downloads: ' + JSON.stringify(options), async () => {
        const {cards, requests} = await render(config, options);
        assert.ok(cards.every(card => card.tagName === 'P'));
        if (options.auth === false) assert.equal(requests.length, 0);
    });
}
