import { V2BoardApi } from './api.js';
import { getServerProtocols } from './catalog.js';
import { bytes, dateText, escapeHtml, money, orderStatus, periodLabels, subscribeWithFlag } from './format.js';
import { buildImportUrl, openExternalUrl } from './subscription.js';

const api = new V2BoardApi('/api/v1', 'xiaov2b.windows.auth');
const state = {
  user: null,
  subscribe: null,
  servers: [],
  protocols: [],
  plans: [],
  orders: [],
  config: { currency_symbol: '¥' }
};

const windowsClients = [
  {
    id: 'clash-party',
    name: 'Clash Party',
    flag: 'clashmeta',
    scheme: 'mihomo',
    primary: true,
    protocols: ['vmess', 'vless', 'trojan', 'hysteria2'],
    downloadUrl: 'https://github.com/mihomo-party-org/clash-party/releases/latest',
    description: 'Windows 首推客户端，适合 Mihomo/Clash Meta 订阅。'
  },
  {
    id: 'sing-box',
    name: 'Sing-box',
    flag: 'sing-box',
    scheme: null,
    primary: true,
    protocols: ['vmess', 'vless', 'trojan', 'hysteria2'],
    description: '核心配置格式，适合高级用户或图形壳。'
  },
  {
    id: 'clash-verge',
    name: 'Clash Verge',
    flag: 'clashmeta',
    scheme: 'clash',
    primary: false,
    protocols: ['vmess', 'vless', 'trojan', 'hysteria2'],
    description: '备用 Mihomo 图形客户端。'
  },
  {
    id: 'v2rayn',
    name: 'V2rayN',
    flag: 'v2rayn',
    scheme: null,
    primary: false,
    protocols: ['vmess', 'vless', 'trojan', 'hysteria2'],
    description: '备用通用订阅入口，兼容性取决于客户端版本。'
  }
];

const $ = (selector) => document.querySelector(selector);
const $$ = (selector) => Array.from(document.querySelectorAll(selector));

function toast(message, type = 'ok') {
  const el = $('#toast');
  el.textContent = message;
  el.classList.toggle('error', type === 'error');
  el.hidden = false;
  clearTimeout(toast.timer);
  toast.timer = setTimeout(() => {
    el.hidden = true;
  }, 4500);
}

function showLoggedIn(loggedIn) {
  $('#loginScreen').hidden = loggedIn;
  $('#appScreen').hidden = !loggedIn;
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

async function copyText(value, message = '已复制') {
  if (!value) throw new Error('没有可复制的内容');
  await navigator.clipboard.writeText(value);
  toast(message);
}

async function loadAll() {
  const [userRes, subscribeRes, configRes, serverRes, planRes, orderRes] = await Promise.all([
    api.userInfo(),
    api.subscribeInfo(),
    api.userConfig(),
    api.servers(),
    api.plans(),
    api.orders()
  ]);

  state.user = userRes.data;
  state.subscribe = subscribeRes.data;
  state.config = configRes.data || state.config;
  state.servers = serverRes.data || [];
  state.protocols = getServerProtocols(state.servers);
  state.plans = planRes.data || [];
  state.orders = orderRes.data || [];

  renderAll();
}

function renderAll() {
  renderHome();
  renderSetupSteps();
  renderClients();
  renderServers();
  renderPlans();
  renderOrders();
}

function renderHome() {
  $('#accountEmail').textContent = state.user.email;
  const used = Number(state.subscribe.u || 0) + Number(state.subscribe.d || 0);
  const total = Number(state.subscribe.transfer_enable || 0);
  const remain = Math.max(total - used, 0);
  const expired = state.subscribe.expired_at && Number(state.subscribe.expired_at) < Date.now() / 1000;
  const lowTraffic = total > 0 && remain / total < 0.15;
  const protocolText = state.protocols.length ? state.protocols.map((item) => item.toUpperCase()).join(' / ') : '暂无匹配协议';

  $('#statusDot').classList.toggle('warn', Boolean(expired || lowTraffic));
  $('#statusLabel').textContent = expired ? '套餐已到期' : lowTraffic ? '流量即将用尽' : '订阅可用';
  $('#statusTitle').textContent = expired ? '需要续费套餐' : '连接准备就绪';
  $('#statusText').textContent = `${state.subscribe.plan?.name || '暂无套餐'} · ${protocolText}`;
  $('#trafficRemain').textContent = bytes(remain);
  $('#trafficUsed').textContent = `已用 ${bytes(used)} / ${bytes(total)}`;
  $('#expireAt').textContent = dateText(state.subscribe.expired_at);
  $('#resetDay').textContent = state.subscribe.reset_day ? `${state.subscribe.reset_day} 天后重置` : '无重置周期';
  $('#balance').textContent = money(state.user.balance, state.config.currency_symbol);
  $('#commission').textContent = `佣金 ${money(state.user.commission_balance, state.config.currency_symbol)}`;
  $('#aliveIp').textContent = state.subscribe.alive_ip ?? 0;
  $('#deviceLimit').textContent = state.subscribe.device_limit ? `设备上限 ${state.subscribe.device_limit}` : '不限制设备';
  $('#subscribeUrl').value = state.subscribe.subscribe_url || '';
}

function renderClients() {
  const compatible = windowsClients
    .filter((client) => !state.protocols.length || client.protocols.some((protocol) => state.protocols.includes(protocol)))
    .sort((a, b) => Number(b.primary) - Number(a.primary));

  $('#windowsClients').innerHTML = compatible.length
    ? compatible.map(renderClient).join('')
    : empty('当前节点没有 Windows 客户端可匹配的协议');
}

function renderSetupSteps() {
  const party = windowsClients[0];
  const subUrl = subscribeWithFlag(state.subscribe?.subscribe_url || '', party.flag);
  const importUrl = buildImportUrl(party, subUrl, state.subscribe?.plan?.name || 'XiaoV2B');
  $('#setupSteps').innerHTML = `
    <article class="step-card">
      <span class="step-index">1</span>
      <h3>安装 Clash Party</h3>
      <p>先安装 Windows 客户端，再导入你的面板订阅。</p>
      <a class="ghost as-button" href="${escapeHtml(party.downloadUrl)}" target="_blank" rel="noreferrer">下载客户端</a>
    </article>
    <article class="step-card">
      <span class="step-index">2</span>
      <h3>导入订阅</h3>
      <p>使用 Mihomo/Clash Meta 格式，匹配当前面板节点。</p>
      <button class="primary" data-import-url="${escapeHtml(importUrl)}" type="button">一键导入</button>
    </article>
    <article class="step-card">
      <span class="step-index">3</span>
      <h3>选择节点</h3>
      <p>导入后在客户端里选择节点并连接。</p>
      <button class="ghost" data-tab-target="servers" type="button">查看节点</button>
    </article>
  `;
}

function renderClient(client) {
  const subUrl = subscribeWithFlag(state.subscribe?.subscribe_url || '', client.flag);
  const importUrl = buildImportUrl(client, subUrl, state.subscribe?.plan?.name || 'XiaoV2B');
  const matched = client.protocols.filter((protocol) => state.protocols.includes(protocol));
  return `
    <article class="client-card">
      <div class="chips">
        ${client.primary ? '<span class="chip">推荐</span>' : '<span class="chip">备用</span>'}
        ${matched.map((protocol) => `<span class="chip">${escapeHtml(protocol.toUpperCase())}</span>`).join('')}
      </div>
      <div>
        <h3>${escapeHtml(client.name)}</h3>
        <p class="client-meta">${escapeHtml(client.description)}</p>
      </div>
      <div class="actions">
        ${importUrl ? `<button class="primary" data-import-url="${escapeHtml(importUrl)}" type="button">一键导入</button>` : ''}
        <button class="${importUrl ? 'ghost' : 'primary'}" data-copy="${escapeHtml(subUrl)}" type="button">复制订阅</button>
        <a class="ghost as-button" href="${escapeHtml(subUrl)}" target="_blank" rel="noreferrer">打开订阅</a>
        ${client.downloadUrl ? `<a class="ghost as-button" href="${escapeHtml(client.downloadUrl)}" target="_blank" rel="noreferrer">下载</a>` : ''}
      </div>
    </article>
  `;
}

function renderServers() {
  $('#serverList').innerHTML = state.servers.length
    ? state.servers.map((server) => `
      <article class="list-item">
        <div>
          <h3>${escapeHtml(server.name || '未命名节点')}</h3>
          <p>${escapeHtml(normalizeProtocolLabel(server))} · ${escapeHtml(server.rate ? `${server.rate}x` : '默认倍率')}</p>
        </div>
        <span class="chip">${server.is_online ? '在线' : '离线'}</span>
      </article>
    `).join('')
    : empty('暂无可用节点');
}

function renderPlans() {
  $('#planList').innerHTML = state.plans.length
    ? state.plans.map((plan) => {
      const prices = Object.entries(periodLabels)
        .filter(([key]) => plan[key] !== null && plan[key] !== undefined)
        .map(([key, label]) => `
          <button class="ghost" data-buy-plan="${plan.id}" data-period="${key}" type="button">
            ${label} ${money(plan[key], state.config.currency_symbol)}
          </button>
        `).join('');
      return `
        <article class="client-card">
          <h3>${escapeHtml(plan.name)}</h3>
          <p class="client-meta">${bytes(Number(plan.transfer_enable || 0) * 1073741824)} 流量 · ${plan.device_limit || '不限'} 设备</p>
          <div class="actions">${prices || '<span class="muted">暂无可购买周期</span>'}</div>
        </article>
      `;
    }).join('')
    : empty('暂无可购买套餐');
}

function renderOrders() {
  $('#orderList').innerHTML = state.orders.length
    ? state.orders.map((order) => {
      const canPay = Number(order.status) === 0;
      return `
        <article class="list-item">
          <div>
            <h3>${escapeHtml(order.plan?.name || order.period || '订单')}</h3>
            <p>${escapeHtml(order.trade_no)} · ${orderStatus[order.status] || order.status} · ${money(order.total_amount, state.config.currency_symbol)}</p>
          </div>
          <div class="actions">
            ${canPay ? `<button class="primary" data-open-checkout="${escapeHtml(order.trade_no)}" type="button">支付</button>` : ''}
            ${canPay ? `<button class="ghost" data-cancel-order="${escapeHtml(order.trade_no)}" type="button">取消</button>` : ''}
          </div>
        </article>
      `;
    }).join('')
    : empty('暂无订单');
}

function normalizeProtocolLabel(server) {
  if (server.type === 'v2node') return server.protocol || 'v2node';
  if (server.type === 'hysteria' && Number(server.version) === 2) return 'hysteria2';
  return server.type || 'unknown';
}

async function buyPlan(planId, period, button) {
  setLoading(button, true);
  try {
    const created = await api.createOrder(planId, period);
    toast('订单已创建');
    await openCheckout(created.data);
    await reloadOrders();
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
    <div class="actions">
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
      toast('订单已完成');
      $('#checkoutDialog').close();
      await loadAll();
      return;
    }
    const data = result.data;
    if (typeof data === 'string' && /^https?:\/\//.test(data)) {
      window.open(data, '_blank', 'noopener');
      $('#checkoutBody').insertAdjacentHTML('beforeend', `<p><a class="ghost as-button" href="${escapeHtml(data)}" target="_blank" rel="noreferrer">打开支付链接</a></p>`);
    } else {
      $('#checkoutBody').insertAdjacentHTML('beforeend', `<pre class="readonly">${escapeHtml(JSON.stringify(result, null, 2))}</pre>`);
    }
  } finally {
    setLoading(button, false);
  }
}

async function reloadOrders() {
  const orderRes = await api.orders();
  state.orders = orderRes.data || [];
  renderOrders();
}

function switchTab(tab) {
  $$('.nav-item').forEach((item) => item.classList.toggle('is-active', item.dataset.tab === tab));
  $$('.tab').forEach((item) => item.classList.toggle('is-active', item.id === tab));
}

function empty(message) {
  return `<article class="panel muted">${message}</article>`;
}

async function boot() {
  if (!api.auth) {
    showLoggedIn(false);
    return;
  }
  showLoggedIn(true);
  try {
    await loadAll();
  } catch (error) {
    api.setAuth('');
    showLoggedIn(false);
    $('#loginMessage').hidden = false;
    $('#loginMessage').textContent = error.message;
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
    showLoggedIn(true);
    await loadAll();
    toast('登录成功');
  } catch (error) {
    $('#loginMessage').hidden = false;
    $('#loginMessage').textContent = error.message;
  } finally {
    setLoading(button, false);
  }
});

$('#logoutBtn').addEventListener('click', () => {
  api.setAuth('');
  showLoggedIn(false);
});

$('#refreshBtn').addEventListener('click', () => loadAll().then(() => toast('已刷新')).catch((error) => toast(error.message, 'error')));
$('#copySubBtn').addEventListener('click', () => copyText($('#subscribeUrl').value, '订阅已复制').catch((error) => toast(error.message, 'error')));
$('#importPartyBtn').addEventListener('click', () => {
  const client = windowsClients[0];
  const url = subscribeWithFlag(state.subscribe?.subscribe_url || '', client.flag);
  const importUrl = buildImportUrl(client, url, state.subscribe?.plan?.name || 'XiaoV2B');
  try {
    openExternalUrl(importUrl);
  } catch (error) {
    toast(error.message, 'error');
  }
});
$('#resetTokenBtn').addEventListener('click', async () => {
  if (!confirm('确定要重置订阅链接吗？')) return;
  try {
    const result = await api.resetSecurity();
    $('#subscribeUrl').value = result.data;
    await loadAll();
    toast('订阅链接已重置');
  } catch (error) {
    toast(error.message, 'error');
  }
});

$$('.nav-item').forEach((item) => item.addEventListener('click', () => switchTab(item.dataset.tab)));

document.addEventListener('click', async (event) => {
  const target = event.target.closest('button');
  if (!target) return;
  try {
    if (target.dataset.copy) await copyText(target.dataset.copy, '订阅已复制');
    if (target.dataset.importUrl) openExternalUrl(target.dataset.importUrl);
    if (target.dataset.tabTarget) switchTab(target.dataset.tabTarget);
    if (target.dataset.buyPlan) await buyPlan(target.dataset.buyPlan, target.dataset.period, target);
    if (target.dataset.openCheckout) await openCheckout(target.dataset.openCheckout);
    if (target.dataset.payMethod) await checkout(target.dataset.tradeNo, target.dataset.payMethod, target);
    if (target.dataset.cancelOrder) {
      await api.cancelOrder(target.dataset.cancelOrder);
      toast('订单已取消');
      await reloadOrders();
    }
  } catch (error) {
    toast(error.message, 'error');
  }
});

boot();
