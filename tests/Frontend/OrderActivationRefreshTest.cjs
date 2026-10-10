'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const {JSDOM} = require('jsdom');

const source = process.env.NINI_SOURCE_ROOT || path.resolve(__dirname, '../../public/theme/NINI/assets/v1');
const flush = () => new Promise(resolve => setTimeout(resolve, 10));
const deferred = () => {
  let resolve;
  const promise = new Promise(done => {resolve = done;});
  return {promise, resolve};
};

async function app(t, options = {}) {
  const dom = new JSDOM('<div id="nini-root"></div>', {
    url: 'https://isolated.invalid/#/' + (options.route || 'order/test-order'),
    runScripts: 'outside-only', pretendToBeVisual: true
  });
  const w = dom.window, calls = [], timers = new Map(), errors = [], intercept = new Map();
  let elapsed = 0, timerId = 0;
  t.after(() => dom.window.close());
  w.Date.now = () => 1791088680000 + elapsed;
  w.setTimeout = (fn, delay = 0) => {const id = ++timerId; timers.set(id, {fn, due: elapsed + delay}); return id;};
  w.clearTimeout = id => timers.delete(id);
  w.matchMedia = () => ({matches: false, addEventListener() {}, removeEventListener() {}});
  w.scrollTo = () => {};
  w.structuredClone = structuredClone;
  w.settings = {title: 'Isolated', theme_config: {landing_enabled: 'off'}};
  w.localStorage.setItem('authorization', 'account-one');
  w.NINI_PREVIEW = true;
  w.fetch = () => {throw new Error('Network access is forbidden');};
  const status = () => options.status ? options.status(elapsed) : elapsed >= 2000 ? 3 : 0;
  const order = (trade = 'test-order') => ({
    id: trade === 'test-order' ? 1 : 2, trade_no: trade,
    status: trade === 'test-order' ? status() : 3, plan_id: 1,
    plan: {id: 1, name: 'Test plan'}, period: 'month_price',
    total_amount: 0, created_at: 1791088680
  });
  w.NINI_TRANSPORT = async (method, url, payload) => {
    assert.equal(method, 'GET', 'Refresh must never submit or modify an order');
    const call = {url, payload, at: elapsed, account: w.Nini.state.auth};
    calls.push(call);
    const active = status() === 3;
    const responses = {
      '/user/info': {data: {email: call.account + '@example.invalid', plan_id: active ? 1 : null}},
      '/user/getSubscribe': {data: active ? {plan_id: 1, plan: {id: 1, name: 'Test plan'}, expired_at: null, transfer_enable: 1073741824} : {plan_id: null}},
      '/user/getStat': {data: []}, '/user/comm/config': {data: {}}, '/guest/comm/config': {data: {}},
      '/user/notice/fetch': {data: []}, '/user/order/fetch': {data: options.noOrders ? [] : [order()]},
      '/user/order/detail': {data: order(payload.trade_no)}, '/user/order/check': {data: status()}
    };
    assert.ok(Object.hasOwn(responses, url), 'Unexpected endpoint: ' + url);
    const response = structuredClone(responses[url]);
    return intercept.has(url) ? intercept.get(url)(call, response) : response;
  };
  for (const file of ['core.js', 'commerce.js']) w.eval(fs.readFileSync(path.join(source, file), 'utf8'));
  const N = w.Nini;
  N.error = error => errors.push(error.message);
  N.pages.ticket = {load: async () => [], render: () => '<input id="draft" value="Original draft">'};
  await N.boot(); await flush();
  assert.equal(w.document.querySelector('[role="alert"]')?.textContent, undefined);
  async function tick(ms) {
    const end = elapsed + ms;
    for (let count = 0; count < 500; count++) {
      const next = [...timers].filter(([, value]) => value.due <= end).sort((a, b) => a[1].due - b[1].due)[0];
      if (!next) break;
      const [id, timer] = next; timers.delete(id); elapsed = timer.due;
      Promise.resolve(timer.fn()).catch(error => errors.push(error.message));
      await flush();
      assert.notEqual(count, 499, 'Unexpected timer loop');
    }
    elapsed = end; await flush();
  }
  async function navigate(route) {N.go(route); await flush(); await flush();}
  return {w, N, calls, errors, intercept, tick, navigate, now: () => elapsed,
    checks: () => calls.filter(call => call.url === '/user/order/check'),
    assertActive: () => {
      assert.equal(N.state.sub.plan_id, 1);
      assert.match(w.document.getElementById('account-header').textContent, /Test plan/);
      assert.deepEqual(errors, []);
    }
  };
}

test('staying on the order page refreshes and stops after completion', async t => {
  const a = await app(t); await a.tick(5000); a.assertActive();
  await a.tick(180000); assert.equal(a.checks().length, 1);
});

test('leaving the order page before activation still refreshes the dashboard', async t => {
  const a = await app(t); await a.tick(1000); await a.navigate('dashboard');
  await a.tick(4000); a.assertActive();
  assert.match(a.w.document.querySelector('.plan-name').textContent, /Test plan/);
});

for (const pending of [0, 1]) test('dashboard entry discovers pending status ' + pending, async t => {
  const a = await app(t, {route: 'dashboard', status: ms => ms >= 2000 ? 3 : pending});
  await a.tick(5000); a.assertActive();
});

test('one failed status request is retried automatically', async t => {
  const a = await app(t);
  a.intercept.set('/user/order/check', (_, response) => {
    a.intercept.delete('/user/order/check'); throw new Error('Transient failure');
  });
  await a.tick(5000); assert.equal(a.N.state.sub.plan_id, null);
  await a.tick(5000); a.assertActive(); assert.equal(a.checks().length, 2);
});

test('persistent failures back off to thirty seconds, then recover', async t => {
  const a = await app(t);
  a.intercept.set('/user/order/check', () => {throw new Error('Offline');});
  await a.tick(100000);
  assert.deepEqual(a.checks().map(call => call.at), [5000, 10000, 20000, 40000, 70000, 100000]);
  a.intercept.delete('/user/order/check'); await a.tick(30000); a.assertActive();
});

test('failed order detail remains retryable', async t => {
  const a = await app(t);
  a.intercept.set('/user/order/detail', () => {a.intercept.delete('/user/order/detail'); throw new Error('Detail unavailable');});
  await a.tick(10000); a.assertActive(); assert.equal(a.checks().length, 2);
});

test('failed account refresh does not prematurely stop completion tracking', async t => {
  const a = await app(t);
  a.intercept.set('/user/getSubscribe', () => {a.intercept.delete('/user/getSubscribe'); throw new Error('Account unavailable');});
  await a.tick(5000); assert.equal(a.N.state.sub.plan_id, null);
  await a.tick(5000); a.assertActive(); assert.equal(a.checks().length, 2);
});

test('completion waits for an older account read and then requests fresh data', async t => {
  const a = await app(t), held = deferred();
  a.intercept.set('/user/getSubscribe', async (_, response) => {a.intercept.delete('/user/getSubscribe'); await held.promise; return response;});
  await a.tick(1000); const oldRead = a.N.refreshCore();
  await a.tick(4000); assert.equal(a.N.state.sub.plan_id, null);
  held.resolve(); await oldRead; await flush(); await flush(); a.assertActive();
  assert.equal(a.calls.filter(call => call.url === '/user/getSubscribe').length, 3);
});

test('hidden tabs pause checks and resume immediately when visible', async t => {
  const a = await app(t);
  Object.defineProperty(a.w.document, 'hidden', {value: true, configurable: true});
  a.w.document.dispatchEvent(new a.w.Event('visibilitychange'));
  await a.tick(30000); assert.equal(a.checks().length, 0);
  Object.defineProperty(a.w.document, 'hidden', {value: false, configurable: true});
  a.w.document.dispatchEvent(new a.w.Event('visibilitychange'));
  await a.tick(0); a.assertActive(); assert.equal(a.checks().length, 1);
});

test('focus and navigation do not overlap an in-flight status request', async t => {
  const a = await app(t), held = deferred();
  a.intercept.set('/user/order/check', async (_, response) => {await held.promise; return response;});
  await a.tick(5000);
  for (let i = 0; i < 10; i++) a.w.dispatchEvent(new a.w.Event('focus'));
  await a.navigate('dashboard'); await a.tick(30000);
  assert.equal(a.checks().length, 1);
  held.resolve(); await flush(); await flush(); a.assertActive();
});

test('logout clears pending timers', async t => {
  const a = await app(t); a.N.authExpired(); await flush();
  await a.tick(30000); assert.equal(a.checks().length, 0); assert.equal(a.N.state.auth, '');
});

test('an old in-flight response cannot refresh a replacement account', async t => {
  const a = await app(t), held = deferred();
  a.intercept.set('/user/order/check', async (_, response) => {await held.promise; return response;});
  await a.tick(5000); a.N.saveAuth({auth_data: 'account-two'});
  const afterSwitch = a.calls.length; held.resolve(); await flush(); await a.tick(30000);
  assert.equal(a.calls.length, afterSwitch); assert.deepEqual(Object.keys(a.N.state.sub), []);
});

test('completion updates the header without replacing a ticket draft or modal', async t => {
  const a = await app(t); await a.tick(1000); await a.navigate('ticket');
  const draft = a.w.document.getElementById('draft'); draft.value = 'Unsaved message';
  const modal = a.N.modal('Draft', '<input value="Unsaved modal">');
  await a.tick(4000); a.assertActive();
  assert.equal(a.w.document.getElementById('draft'), draft);
  assert.equal(draft.value, 'Unsaved message'); assert.equal(modal.isConnected, true);
});

test('viewing another order does not abandon the pending purchase or replace selection', async t => {
  const a = await app(t); await a.tick(1000); await a.navigate('order/old-order');
  await a.tick(4000); a.assertActive();
  assert.equal(a.w.location.hash, '#/order/old-order');
  assert.match(a.w.document.querySelector('.commerce-order-detail').textContent, /old-order/);
});

test('cancelled orders stop being checked', async t => {
  const a = await app(t, {status: ms => ms >= 2000 ? 2 : 0});
  await a.tick(180000); assert.equal(a.N.state.sub.plan_id, null); assert.equal(a.checks().length, 1);
});

test('accounts without pending orders do not poll', async t => {
  const a = await app(t, {route: 'dashboard', noOrders: true});
  await a.tick(180000); assert.equal(a.checks().length, 0); assert.deepEqual(a.errors, []);
});

test('paid-but-opening state continues until completion', async t => {
  const a = await app(t, {status: ms => ms < 2000 ? 0 : ms < 8000 ? 1 : 3});
  await a.tick(5000); assert.equal(a.N.state.sub.plan_id, null);
  await a.tick(5000); a.assertActive(); assert.equal(a.checks().length, 2);
});

test('unauthorized requests stop when the account expires', async t => {
  const a = await app(t);
  a.intercept.set('/user/order/check', () => {a.N.authExpired(); throw new Error('Login expired');});
  await a.tick(180000); assert.equal(a.checks().length, 1); assert.equal(a.N.state.auth, '');
});

test('invalid status responses are retried rather than treated as completed', async t => {
  const a = await app(t);
  a.intercept.set('/user/order/check', () => {a.intercept.delete('/user/order/check'); return {data: null};});
  await a.tick(10000); a.assertActive(); assert.equal(a.checks().length, 2);
});

test('slow order discovery does not block the dashboard', async t => {
  const a = await app(t), held = deferred();
  await a.N.refreshCore();
  a.intercept.set('/user/order/fetch', async (_, response) => {await held.promise; return response;});
  await a.navigate('dashboard');
  assert.equal(a.w.document.getElementById('nini-root').dataset.page, 'dashboard');
  assert.equal(a.w.document.getElementById('page-content').hasAttribute('aria-busy'), false);
  held.resolve(); await flush(); await a.tick(5000); a.assertActive();
});
