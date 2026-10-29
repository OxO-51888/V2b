const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const crypto = require('node:crypto');
const root = path.resolve(__dirname, '../../public/community');
const source = fs.readFileSync(path.join(root, 'stickers.js'), 'utf8');
const chat = fs.readFileSync(path.join(root, 'chat.js'), 'utf8');
const files = fs.readdirSync(path.join(root, 'stickers'));
const ids = files.filter(name => name.endsWith('.png')).map(name => name.slice(0, -4));

function setup() {
    const document = {
        currentScript: {src: 'https://local.test/community/stickers.js'},
        createElement: tag => ({tag, children: [], events: {},
            append(...items) { this.children.push(...items); },
            addEventListener(name, callback) { this.events[name] = callback; }})
    };
    const context = vm.createContext({window: {}, document, URL});
    vm.runInContext(source, context);
    return context.window.ChatStickers;
}

test('all sticker images and thumbnails are present and content-addressed', () => {
    const api = setup();
    assert.equal(ids.length, 32);
    assert.equal(files.length, 64);
    for (const id of ids) {
        const item = api.resolve(api.encode(id));
        assert.equal(item.id, id);
        for (const name of [item.file, item.thumb]) {
            assert.match(name, /^[a-f0-9]{24}\.(png|webp)$/);
            const bytes = fs.readFileSync(path.join(root, 'stickers', name));
            assert.equal(crypto.createHash('sha256').update(bytes).digest('hex').slice(0, 24), name.slice(0, 24));
        }
        assert.ok(item.width <= 512 && item.height <= 512);
    }
    assert.doesNotMatch(source, /api\.telegram\.org|bot\d+:/);
});

test('invalid markers and arbitrary URLs cannot render as stickers', () => {
    const api = setup(), marker = api.encode(ids[0]);
    for (const input of [null, {}, '', marker + ' suffix', 'prefix ' + marker, marker + '\n',
        marker.replace(ids[0], '../secret'), 'https://other.test/image.png', '<img onerror=alert(1)>']) {
        assert.equal(api.resolve(input), null);
    }
    assert.equal(api.encode('../secret'), null);
    assert.equal(api.summary('text'), 'text');
});

test('rendering is same-origin, has a failure fallback, and preserves file attachments', () => {
    const api = setup(), body = {children: [], append(...items) {this.children.push(...items);}};
    const row = {message: api.encode(ids[0]), file_ids: []};
    assert.equal(api.render(body, row), true);
    assert.equal(body.children[0].src, 'https://local.test/community/stickers/' + ids[0] + '.png');
    body.children[0].events.error();
    assert.equal(body.children[0].hidden, true);
    assert.equal(body.children[1].hidden, false);
    assert.equal(api.render(body, {...row, file_ids: ['attachment']}), false);
});

function composer() {
    const api = setup(), calls = [], reply = {hidden: false};
    const state = {me: {id: 'local-user'}, attachments: [{id: 'upload'}], reply: {id: 'reply'},
        pending: 'draft-id', failedSend: null, busy: false};
    const context = vm.createContext({state, window: {ChatStickers: api}, $: () => reply,
        composerReady: () => !state.busy && !state.me.muted,
        crypto: {randomUUID: () => 'sticker-id'}, postSubmission: data => calls.push(data)});
    const start = chat.indexOf('  function sendSticker(');
    const end = chat.indexOf('  async function sendMessage(', start);
    assert.ok(start > 0 && end > start);
    vm.runInContext(chat.slice(start, end), context);
    return {context, state, calls, reply, marker: api.encode(ids[0])};
}

test('sticker submission preserves draft identity and existing uploads', () => {
    const {context, state, calls, reply, marker} = composer();
    context.sendSticker(marker);
    assert.equal(calls.length, 1);
    assert.equal(calls[0].data.message, marker);
    assert.equal(calls[0].data.root_id, 'reply');
    assert.equal(calls[0].data.client_id, 'sticker-id');
    assert.equal(calls[0].data.file_ids.length, 0);
    assert.equal(state.attachments[0].id, 'upload');
    assert.equal(state.pending, 'draft-id');
    assert.equal(reply.hidden, true);
});

test('muted, busy, failed or invalid submissions cannot send', () => {
    for (const flag of ['muted', 'busy', 'failed', 'invalid']) {
        const {context, state, calls, marker} = composer();
        if (flag === 'muted') state.me.muted = true;
        if (flag === 'busy') state.busy = true;
        if (flag === 'failed') state.failedSend = {};
        context.sendSticker(flag === 'invalid' ? 'invalid' : marker);
        assert.equal(calls.length, 0);
    }
});

test('sticker UI keeps square bubbles and loads before chat', () => {
    const css = fs.readFileSync(path.join(root, 'stickers.css'), 'utf8');
    const html = fs.readFileSync(path.join(root, 'index.html'), 'utf8');
    assert.match(css, /width:200px;height:200px/);
    assert.match(css, /width:180px;height:180px/);
    assert.doesNotMatch(source, /emojiTab|emoji-choice|tablist/);
    assert.ok(html.indexOf('stickers.js') < html.indexOf('chat.js'));
    assert.match(chat, /await api\("post", submission.data\)/);
});
