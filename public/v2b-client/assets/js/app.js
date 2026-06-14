import { V2BoardApi } from './api.js';
import {
  filterCatalog,
  getClientProtocolScore,
  getServerProtocols,
  isClientCompatible,
  loadClientCatalog
} from './catalog.js';
import { buildImportUrl, openExternalUrl } from './subscription.js';
import { rememberClient, sortByRecentAndRecommended } from './store.js';
import {
  bytes,
  dateText,
  escapeHtml,
  money,
  orderStatus,
  periodLabels,
  platformLabels,
  subscribeWithFlag
} from './format.js';

const api = new V2BoardApi();
const state = {
  user: null,
  subscribe: null,
  config: { currency_symbol: '¥' },
  catalog: [],
  servers: [],
  protocols: [],
  orderFilter: '',
  platformFilter: 'all'
};

const $ = (selector) => document.querySelector(selector);
const $$ = (selector) => Array.from(document.querySelectorAll(selector));

function showAlert(message, type = 'error') {
  const alert = $('#alert');
  alert.textContent = message;
  alert.classList.toggle('ok', type === 'ok');
  alert.hidden = false;
  clearTimeout(showAlert.timer);
  showAlert.timer = setTimeout(() => {
    alert.hidden = true;
  }, 4500);
}

function setLoading(button, loading) {
  if (!button) return;
  button.disabled = loading;
  if (loading) {
    button.dataset.label = button.textContent;
    button.textContent = '处理中...';
  } else if (button.dataset.label) {
    button.textContent = button.dataset.label;
    delete button.dataset.label;
  }
}

function showAuthed(authed) {
  $('#authView').hidden = authed;
  $('#clientView').hidden = !authed;
  $('#logoutBtn').hidden = !authed;
  $$('.nav-item').forEach((item) => item.disabled = !authed);
}

async function copyText(value, successMessage = '已复制') {
  if (!value) throw new Error('没有可复制的内容');
  await navigator.clipboard.writeText(value);
  showAlert(successMessage, 'ok');
}

function renderStatus() {
  const user = state.user;
  const subscribe = state.subscribe;
  if (!user || !subscribe) return;

  const used = Number(subscribe.u || 0) + Number(subscribe.d || 0);
  const total = Number(subscribe.transfer_enable || 0);
  const remain = Math.max(total - used, 0);
  const expired = subscribe.expired_at && Number(subscribe.expired_at) < Date.now() / 1000;
  const lowTraffic = total > 0 && remain / total < 0.15;

  $('#statusLabel').textContent = expired ? '套餐已到期' : lowTraffic ? '流量即将用尽' : '订阅可用';
  $('#statusTitle').textContent = expired ? '需要续费套餐' : lowTraffic ? '建议关注剩余流量' : '连接准备就绪';
  const protocolText = state.protocols.length ? ` · ${state.protocols.map((item) => item.toUpperCase()).join(' / ')}` : '';
  $('#statusSummary').textContent = `${user.email} · ${subscribe.plan?.name || '暂无套餐'} · ${bytes(remain)} 剩余${protocolText}`;
}

async function loadDashboard() {
  const [userRes, subRes, statRes, configRes, serverRes] = await Promise.all([
    api.userInfo(),
    api.subscribeInfo(),
    api.userStat(),
    api.userConfig(),
    api.servers()
  ]);

  state.user = userRes.data;
  state.subscribe = subRes.data;
  state.config = configRes.data || state.config;
  state.servers = serverRes.data || [];
  state.protocols = getServerProtocols(state.servers);

  $('#sessionEmail').textContent = state.user.email;
  document.title = `XiaoV2B Client · ${state.user.email}`;

  const used = Number(state.subscribe.u || 0) + Number(state.subscribe.d || 0);
  const total = Number(state.subscribe.transfer_enable || 0);
  $('#trafficRemain').textContent = bytes(Math.max(total - used, 0));
  $('#trafficUsed').textContent = `已用 ${bytes(used)} / ${bytes(total)}`;
  $('#expireAt').textContent = dateText(state.subscribe.expired_at);
  $('#resetDay').textContent = state.subscribe.reset_day ? `${state.subscribe.reset_day} 天后重置` : '无重置周期';
  $('#balance').textContent = money(state.user.balance, state.config.currency_symbol);
  $('#commission').textContent = `佣金 ${money(state.user.commission_balance, state.config.currency_symbol)}`;
  $('#aliveIp').textContent = state.subscribe.alive_ip ?? 0;
  $('#deviceLimit').textContent = state.subscribe.device_limit ? `设备上限 ${state.subscribe.device_limit}` : '不限制设备';
  $('#subscribeUrl').value = state.subscribe.subscribe_url || '';
  $('#remindExpire').checked = Boolean(Number(state.user.remind_expire));
  $('#remindTraffic').checked = Boolean(Number(state.user.remind_traffic));
  $('#autoRenewal').checked = Boolean(Number(state.user.auto_renewal));

  renderStatus();
  state.stat = statRes.data || null;
}

async function loadClients() {
  if (!state.catalog.length) {
    state.catalog = await loadClientCatalog();
  }
  const platformItems = filterCatalog(state.catalog, state.platformFilter);
  const compatibleItems = state.protocols.length
    ? platformItems.filter((item) => isClientCompatible(item, state.protocols))
    : platformItems;
  const items = sortByRecentAndRecommended(compatibleItems, state.protocols);
  $('#clientCatalog').innerHTML = items.length
    ? items.map(renderClientCard).join('')
    : empty('暂无客户端');
}

function renderClientCard(item) {
  const subUrl = subscribeWithFlag(state.subscribe?.subscribe_url || '', item.flag);
  const importUrl = buildImportUrl(item, subUrl, state.subscribe?.plan?.name || 'XiaoV2B');
  const score = getClientProtocolScore(item, state.protocols);
  const supportText = state.protocols.length ? `匹配 ${score}/${state.protocols.length} 种当前协议` : '等待节点协议';
  return `
    <article class="panel client-card">
      <div>
        <span class="tag">${platformLabels[item.platform] || item.platform}</span>
        ${(item.protocols || []).map((protocol) => `<span class="tag">${escapeHtml(protocol.toUpperCase())}</span>`).join('')}
        ${item.recommended ? '<span class="tag">推荐</span>' : ''}
      </div>
      <div>
        <h3>${escapeHtml(item.name)}</h3>
        <p class="client-meta">${escapeHtml(item.kind)} · ${escapeHtml(supportText)} · ${escapeHtml(item.description)}</p>
      </div>
      <div class="client-actions">
        ${importUrl ? `<button class="primary" data-import-client="${escapeHtml(item.id)}" data-import-url="${escapeHtml(importUrl)}" type="button">一键导入</button>` : ''}
        <button class="${importUrl ? 'ghost' : 'primary'}" data-copy-client="${escapeHtml(item.id)}" data-copy-value="${escapeHtml(subUrl)}" type="button">复制订阅</button>
        <a class="ghost as-button" href="${escapeHtml(subUrl)}" target="_blank" rel="noreferrer">打开订阅</a>
        ${item.downloadUrl ? `<a class="ghost as-button" href="${escapeHtml(item.downloadUrl)}" target="_blank" rel="noreferrer">下载客户端</a>` : ''}
      </div>
    </article>
  `;
}

async function loadPlans() {
  const result = await api.plans();
  const plans = result.data || [];
  $('#planList').innerHTML = plans.length ? plans.map(renderPlan).join('') : empty('暂无可购买套餐');
}

function renderPlan(plan) {
  const prices = Object.keys(periodLabels)
    .filter((key) => plan[key] !== null && plan[key] !== undefined)
    .map((key) => `
      <div class="price-row">
        <span>${periodLabels[key]}</span>
        <strong>${money(plan[key], state.config.currency_symbol)}</strong>
        <button class="primary" data-buy-plan="${plan.id}" data-period="${key}" type="button">购买</button>
      </div>
    `).join('');

  return `
    <article class="panel plan-card">
      <div>
        <h3>${escapeHtml(plan.name)}</h3>
        <p class="muted">${bytes(Number(plan.transfer_enable || 0) * 1073741824)} 流量 · ${plan.device_limit || '不限'} 设备</p>
      </div>
      <div class="price-list">${prices || '<span class="muted">暂无可购买周期</span>'}</div>
    </article>
  `;
}

async function buyPlan(planId, period, button) {
  setLoading(button, true);
  try {
    const created = await api.createOrder(planId, period);
    showAlert('订单已创建', 'ok');
    await openCheckout(created.data);
    await loadOrders();
  } finally {
    setLoading(button, false);
  }
}

async function openCheckout(tradeNo) {
  const [detail, methods] = await Promise.all([
    api.orderDetail(tradeNo),
    api.paymentMethods()
  ]);
  const order = detail.data;
  const paymentMethods = methods.data || [];
  $('#checkoutBody').innerHTML = `
    <p><strong>${escapeHtml(order.plan?.name || '订单')}</strong></p>
    <p class="muted">订单号：${escapeHtml(order.trade_no)}</p>
    <p>应付：<strong>${money(order.total_amount, state.config.currency_symbol)}</strong></p>
    <div class="price-list">
      ${paymentMethods.length ? paymentMethods.map((method) => `
        <button class="primary" data-pay-method="${method.id}" data-trade-no="${escapeHtml(order.trade_no)}" type="button">
          ${escapeHtml(method.name)}
        </button>
      `).join('') : `<button class="primary" data-pay-method="0" data-trade-no="${escapeHtml(order.trade_no)}" type="button">余额支付/免费开通</button>`}
    </div>
  `;
  $('#checkoutDialog').showModal();
}

async function checkout(tradeNo, method, button) {
  setLoading(button, true);
  try {
    const result = await api.checkout(tradeNo, method);
    if (result.type === -1) {
      showAlert('订单已完成', 'ok');
      $('#checkoutDialog').close();
      await loadDashboard();
      return;
    }
    renderPaymentResult(result);
  } finally {
    setLoading(button, false);
  }
}

function renderPaymentResult(result) {
  const data = result.data;
  if (typeof data === 'string' && /^https?:\/\//.test(data)) {
    $('#checkoutBody').insertAdjacentHTML('beforeend', `<p><a class="tag" href="${escapeHtml(data)}" target="_blank" rel="noreferrer">打开支付链接</a></p>`);
    window.open(data, '_blank', 'noopener');
    return;
  }
  $('#checkoutBody').insertAdjacentHTML('beforeend', `<pre class="readonly-input">${escapeHtml(JSON.stringify(result, null, 2))}</pre>`);
}

async function loadOrders() {
  const result = await api.orders(state.orderFilter);
  const orders = result.data || [];
  $('#orderList').innerHTML = orders.length ? orders.map(renderOrder).join('') : empty('暂无订单');
}

function renderOrder(order) {
  const canPay = Number(order.status) === 0;
  return `
    <article class="list-item">
      <div>
        <h3>${escapeHtml(order.plan?.name || order.period || '订单')}</h3>
        <p>${escapeHtml(order.trade_no)} · ${orderStatus[order.status] || order.status} · ${money(order.total_amount, state.config.currency_symbol)}</p>
      </div>
      <div class="top-actions">
        ${canPay ? `<button class="primary" data-open-checkout="${escapeHtml(order.trade_no)}" type="button">支付</button>` : ''}
        ${canPay ? `<button class="ghost" data-cancel-order="${escapeHtml(order.trade_no)}" type="button">取消</button>` : ''}
      </div>
    </article>
  `;
}

async function loadServers() {
  if (!state.servers.length) {
    const result = await api.servers();
    state.servers = result.data || [];
    state.protocols = getServerProtocols(state.servers);
  }
  const servers = state.servers;
  $('#serverList').innerHTML = servers.length ? servers.map((server) => `
    <article class="list-item">
      <div>
        <h3>${escapeHtml(server.name || '未命名节点')}</h3>
        <p>${escapeHtml(server.type || 'unknown')} · ${escapeHtml(server.rate ? `${server.rate}x` : '默认倍率')}</p>
      </div>
      <span class="tag">${escapeHtml(server.host || server.server || server.address || '地址已隐藏')}</span>
    </article>
  `).join('') : empty('暂无可用节点');
}

async function savePrefs() {
  await api.updateUser({
    remind_expire: $('#remindExpire').checked ? 1 : 0,
    remind_traffic: $('#remindTraffic').checked ? 1 : 0,
    auto_renewal: $('#autoRenewal').checked ? 1 : 0
  });
  showAlert('偏好已保存', 'ok');
  await loadDashboard();
}

async function resetToken() {
  if (!confirm('确定要重置订阅链接吗？')) return;
  const result = await api.resetSecurity();
  $('#subscribeUrl').value = result.data;
  showAlert('订阅链接已重置', 'ok');
  await loadDashboard();
}

async function refreshCurrentView() {
  const view = $('.nav-item.is-active')?.dataset.view || 'dashboard';
  if (view === 'dashboard' || view === 'settings') await loadDashboard();
  if (view === 'clients') await loadClients();
  if (view === 'plans') await loadPlans();
  if (view === 'orders') await loadOrders();
  if (view === 'servers') await loadServers();
}

function switchView(view) {
  $$('.nav-item').forEach((item) => item.classList.toggle('is-active', item.dataset.view === view));
  $$('.view').forEach((item) => item.classList.toggle('is-active', item.id === view));
  $('#viewTitle').textContent = $('.nav-item.is-active')?.textContent || '首页';
  refreshCurrentView().catch((error) => showAlert(error.message));
}

function empty(message) {
  return `<div class="panel muted">${message}</div>`;
}

async function boot() {
  $('#apiOrigin').textContent = api.baseUrl;
  state.catalog = await loadClientCatalog().catch(() => []);

  const verify = new URLSearchParams(location.search).get('verify')
    || new URLSearchParams(location.hash.replace(/^#\??/, '')).get('verify');
  if (verify) {
    const result = await api.tokenLogin(verify);
    api.setAuth(result.data);
    history.replaceState(null, '', location.pathname);
  }

  if (!api.auth) {
    showAuthed(false);
    return;
  }

  showAuthed(true);
  try {
    await loadDashboard();
  } catch (error) {
    api.setAuth('');
    showAuthed(false);
    showAlert(error.message);
  }
}

$('#loginForm').addEventListener('submit', async (event) => {
  event.preventDefault();
  const button = event.submitter;
  setLoading(button, true);
  try {
    const form = new FormData(event.currentTarget);
    const result = await api.login(form.get('email'), form.get('password'));
    api.setAuth(result.data);
    showAuthed(true);
    await loadDashboard();
    showAlert('登录成功', 'ok');
  } catch (error) {
    showAlert(error.message);
  } finally {
    setLoading(button, false);
  }
});

$('#registerForm').addEventListener('submit', async (event) => {
  event.preventDefault();
  const button = event.submitter;
  setLoading(button, true);
  try {
    const form = new FormData(event.currentTarget);
    const payload = Object.fromEntries(form.entries());
    const result = await api.register(payload);
    api.setAuth(result.data);
    showAuthed(true);
    await loadDashboard();
    showAlert('注册成功', 'ok');
  } catch (error) {
    showAlert(error.message);
  } finally {
    setLoading(button, false);
  }
});

$('#showRegisterBtn').addEventListener('click', () => {
  $('#loginForm').hidden = true;
  $('#registerForm').hidden = false;
});

$('#showLoginBtn').addEventListener('click', () => {
  $('#registerForm').hidden = true;
  $('#loginForm').hidden = false;
});

$('#logoutBtn').addEventListener('click', () => {
  api.setAuth('');
  state.user = null;
  state.subscribe = null;
  $('#sessionEmail').textContent = '未登录';
  showAuthed(false);
});

$('#refreshBtn').addEventListener('click', () => refreshCurrentView().catch((error) => showAlert(error.message)));
$('#copySubBtn').addEventListener('click', () => copyText($('#subscribeUrl').value, '订阅链接已复制').catch((error) => showAlert(error.message)));
$('#savePrefsBtn').addEventListener('click', () => savePrefs().catch((error) => showAlert(error.message)));
$('#resetTokenBtn').addEventListener('click', () => resetToken().catch((error) => showAlert(error.message)));
$('#resetTokenShortcutBtn').addEventListener('click', () => resetToken().catch((error) => showAlert(error.message)));

$$('.nav-item').forEach((item) => item.addEventListener('click', () => switchView(item.dataset.view)));

document.addEventListener('click', async (event) => {
  const target = event.target.closest('button');
  if (!target) return;
  try {
    if (target.dataset.viewTarget) switchView(target.dataset.viewTarget);
    if (target.dataset.copyValue) await copyText(target.dataset.copyValue, '订阅链接已复制');
    if (target.dataset.copyClient) {
      rememberClient(target.dataset.copyClient);
      await loadClients();
    }
    if (target.dataset.importClient) {
      rememberClient(target.dataset.importClient);
      openExternalUrl(target.dataset.importUrl);
    }
    if (target.dataset.buyPlan) await buyPlan(target.dataset.buyPlan, target.dataset.period, target);
    if (target.dataset.openCheckout) await openCheckout(target.dataset.openCheckout);
    if (target.dataset.payMethod) await checkout(target.dataset.tradeNo, target.dataset.payMethod, target);
    if (target.dataset.cancelOrder) {
      await api.cancelOrder(target.dataset.cancelOrder);
      showAlert('订单已取消', 'ok');
      await loadOrders();
    }
  } catch (error) {
    showAlert(error.message);
  }
});

$$('[data-order-filter]').forEach((button) => {
  button.addEventListener('click', async () => {
    $$('[data-order-filter]').forEach((item) => item.classList.remove('is-active'));
    button.classList.add('is-active');
    state.orderFilter = button.dataset.orderFilter;
    await loadOrders().catch((error) => showAlert(error.message));
  });
});

$$('[data-platform-filter]').forEach((button) => {
  button.addEventListener('click', async () => {
    $$('[data-platform-filter]').forEach((item) => item.classList.remove('is-active'));
    button.classList.add('is-active');
    state.platformFilter = button.dataset.platformFilter;
    await loadClients().catch((error) => showAlert(error.message));
  });
});

$('#giftcardForm').addEventListener('submit', async (event) => {
  event.preventDefault();
  const form = new FormData(event.currentTarget);
  await api.redeemGiftcard(form.get('giftcard'));
  event.currentTarget.reset();
  showAlert('礼品卡已兑换', 'ok');
  await loadDashboard();
});

boot().catch((error) => showAlert(error.message));
