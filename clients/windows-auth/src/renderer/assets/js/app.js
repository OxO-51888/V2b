const supportedProtocols = new Set(['vmess', 'vless', 'trojan', 'hysteria2']);
const STARTUP_LOADING_MS = 4000;

let dashboard = null;
let connected = false;
let connecting = false;
let selectedServer = null;
let connectedAt = null;
let localNetwork = { locationText: '--', ip: '--' };
let timer = null;
let trafficTimer = null;
let lastTrafficSnapshot = null;
let autoNodeSelecting = false;
let disconnectedHeartLastFrameAt = 0;
let disconnectedHeartPulse = 0;
let disconnectedHeartPeriodMs = 2000;
let disconnectedHeartTargetPeriodMs = 2000;
let connectedHeartTimer = null;
let connectedHeartAnimMs = 860;
let connectedHeartTargetAnimMs = 860;
let connectedHeartRhythmMs = 2000;
let connectedHeartTargetRhythmMs = 2000;
let connectedHeartPulseId = 0;
let connectVisualProgress = 0;
let connectProgressTimer = null;
localStorage.removeItem('xiaov2b.mode');
let currentMode = 'rule';
let startupEnabled = false;
let selectedPlanId = null;
let checkoutState = null;
let checkoutPollTimer = null;
let pendingPurchase = null;
let pendingAutoSelectionAfterModeApply = false;
let nodeLatencyMap = new Map();

const $ = (selector) => document.querySelector(selector);
const $$ = (selector) => Array.from(document.querySelectorAll(selector));

function wait(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function normalizeMode(mode) {
  return ['rule', 'global', 'auto'].includes(mode) ? mode : 'rule';
}

function bytes(value) {
  const size = Number(value || 0);
  if (size <= 0) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  const index = Math.min(Math.floor(Math.log(size) / Math.log(1024)), units.length - 1);
  return `${(size / Math.pow(1024, index)).toFixed(index < 3 ? 0 : 2)} ${units[index]}`;
}

function rateText(value) {
  const bytesPerSecond = Math.max(0, Number(value || 0));
  if (bytesPerSecond < 1000) return `${Math.round(bytesPerSecond)} B/s`;
  if (bytesPerSecond < 1000 * 1000) return `${(bytesPerSecond / 1000).toFixed(1)} KB/s`;
  return `${(bytesPerSecond / 1000 / 1000).toFixed(2)} MB/s`;
}

function money(value, symbol = '¥') {
  return `${symbol || '¥'}${(Number(value || 0) / 100).toFixed(2)}`;
}

function purchasePlan(planId = pendingPurchase?.planId) {
  return dashboard?.plans?.find((plan) => Number(plan.id) === Number(planId)) || null;
}

function couponDiscountAmount(coupon, plan, period) {
  const price = Number(plan?.[period] || 0);
  const type = Number(coupon?.type);
  const value = Number(coupon?.value || 0);
  if (!price || !type || !value) return null;
  const discount = type === 1 ? value : type === 2 ? price * (value / 100) : 0;
  if (!discount) return null;
  return Math.max(0, Math.min(price, Math.round(discount)));
}

function couponAllowedForPeriod(coupon, period) {
  const limitPeriod = coupon?.limit_period;
  if (!Array.isArray(limitPeriod) || !limitPeriod.length) return true;
  return limitPeriod.map(String).includes(String(period));
}

const periodLabels = {
  month_price: '月付',
  quarter_price: '季付',
  half_year_price: '半年付',
  year_price: '年付',
  two_year_price: '两年付',
  three_year_price: '三年付',
  onetime_price: '一次性',
  reset_price: '重置流量'
};

const orderStatusLabels = {
  0: '未支付',
  1: '开通中',
  2: '已取消',
  3: '已完成',
  4: '已折抵'
};

const ORDER_DISPLAY_LIMIT = 6;

function purchasablePeriods(plan) {
  return Object.entries(periodLabels).filter(([key]) => {
    const price = plan?.[key];
    return price !== null && price !== undefined && Number(price) > 0;
  });
}

function accountBalanceText() {
  return money(dashboard?.user?.balance || 0, dashboard?.config?.currency_symbol);
}

function blockingOrder() {
  return dashboard?.orders?.find((order) => [0, 1].includes(Number(order.status))) || null;
}

function blockPurchaseWhenOrderExists() {
  const order = blockingOrder();
  if (!order) return false;
  const status = Number(order.status);
  const message = status === 0
    ? '您有未支付订单，请先继续支付或取消订单'
    : '您有开通中的订单，请等待处理完成';
  toast(message, 'error');
  switchPage('orders');
  return true;
}

function orderPlanId(order) {
  return Number(order?.plan_id || order?.plan?.id || 0);
}

function orderSortValue(order) {
  const value = order?.created_at || order?.updated_at || order?.createdAt || order?.updatedAt || order?.id || 0;
  if (typeof value === 'number') return value;
  const numeric = Number(value);
  if (Number.isFinite(numeric) && numeric > 0) return numeric;
  const parsed = Date.parse(value);
  return Number.isFinite(parsed) ? parsed : 0;
}

function latestDisplayOrders(orders) {
  return [...(orders || [])]
    .sort((a, b) => orderSortValue(b) - orderSortValue(a))
    .slice(0, ORDER_DISPLAY_LIMIT);
}

function dateText(timestamp) {
  if (!timestamp) return '长期有效';
  return new Date(Number(timestamp) * 1000).toLocaleDateString('zh-CN');
}

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;'
  }[char]));
}

function cleanErrorMessage(error) {
  const raw = String(error?.message || error || '操作失败');
  const withoutInvoke = raw.replace(/^Error invoking remote method '[^']+':\s*/i, '');
  const withoutError = withoutInvoke.replace(/^Error:\s*/i, '');
  const message = withoutError.trim();
  const lower = message.toLowerCase();
  if (!message) return '操作失败';
  if (lower.includes('fetch failed')) return '网络请求失败，请检查本地网络后重试';
  if (lower.includes('network') || lower.includes('failed to fetch')) return '网络请求失败，请检查本地网络后重试';
  if (lower.includes('timeout') || lower.includes('timed out')) return '请求超时，请检查本地网络后重试';
  if (lower.includes('econnrefused')) return '连接被拒绝，请检查服务是否正常';
  if (lower.includes('enotfound') || lower.includes('getaddrinfo')) return '域名解析失败，请检查本地网络';
  if (lower.includes('certificate') || lower.includes('cert')) return '证书验证失败，请检查下载域名证书';
  return message;
}

function protocolOf(server) {
  if (server.type === 'v2node') return server.protocol || '';
  if (server.type === 'hysteria' && Number(server.version) === 2) return 'hysteria2';
  return server.type || '';
}

function supportedServers() {
  if (!dashboard) return [];
  return dashboard.servers
    .filter((server) => supportedProtocols.has(protocolOf(server)))
    .map((server, index) => ({ server, index }))
    .sort((left, right) => {
      const leftGlobal = serverFlagCode(left.server) === 'global';
      const rightGlobal = serverFlagCode(right.server) === 'global';
      if (leftGlobal !== rightGlobal) return leftGlobal ? 1 : -1;
      return left.index - right.index;
    })
    .map(({ server }) => server);
}

function autoNodeKey(server, index) {
  return `${protocolOf(server)}:${server?.id ?? 'node'}:${index}`;
}

function serverIdentity(server) {
  if (!server) return '';
  const protocol = protocolOf(server);
  const id = server?.id ?? server?.node_id ?? server?.server_id;
  if (id !== undefined && id !== null && id !== '') return `${protocol}:id:${id}`;
  return `${protocol}:node:${serverLabel(server)}:${latencySource(server)}:${server?.port || ''}`;
}

function latencySource(server) {
  return server?.host || server?.server || server?.address || server?.addr || server?.server_name || '';
}

function builtInLatency(server) {
  const fields = ['delay', 'latency', 'ping', 'avg_delay', 'last_delay', 'node_delay'];
  for (const field of fields) {
    const value = Number(server?.[field]);
    if (Number.isFinite(value) && value > 0) return value;
  }
  return null;
}

function latencyText(server, index) {
  const latency = builtInLatency(server) ?? nodeLatencyMap.get(autoNodeKey(server, index));
  if (Number.isFinite(latency) && latency > 0) return `${Math.round(latency)}ms`;
  return '--';
}

function isOnline(server) {
  return Number(server?.is_online ?? 1) === 1;
}

async function measureLatencyForServers(servers) {
  const payload = servers.map((server, index) => ({
    key: autoNodeKey(server, index),
    host: latencySource(server),
    server_name: server.server_name
  }));

  if (!window.xiaov2b.measureLatency) return new Map();
  const result = await window.xiaov2b.measureLatency(payload);
  const latencyMap = new Map((result || []).map((item) => {
    const latency = Number(item.latency);
    return [String(item.key), Number.isFinite(latency) && latency > 0 ? latency : null];
  }));
  nodeLatencyMap = latencyMap;
  return latencyMap;
}

function pickFastestServer(servers, latencyMap) {
  return servers
    .map((server, index) => ({
      server,
      index,
      latency: builtInLatency(server) ?? latencyMap.get(autoNodeKey(server, index)),
      online: isOnline(server),
      global: serverFlagCode(server) === 'global',
      sort: Number(server.sort ?? index)
    }))
    .sort((left, right) => {
      if (left.global !== right.global) return left.global ? 1 : -1;
      if (left.online !== right.online) return left.online ? -1 : 1;
      const leftHasLatency = Number.isFinite(left.latency);
      const rightHasLatency = Number.isFinite(right.latency);
      if (leftHasLatency !== rightHasLatency) return leftHasLatency ? -1 : 1;
      if (leftHasLatency && left.latency !== right.latency) return left.latency - right.latency;
      if (left.sort !== right.sort) return left.sort - right.sort;
      return left.index - right.index;
    })[0];
}

function showApp(show) {
  $('#startupView').hidden = true;
  pauseStartupVideo();
  $('#loginView').hidden = show;
  $('#appView').hidden = !show;
  if (show) $('#appView').classList.add('rail-collapsed');
  $('.shell').classList.toggle('app-active', show);
  syncRailChrome();
}

function syncRailChrome() {
  $('.shell').classList.toggle('rail-collapsed', $('.shell').classList.contains('app-active') && $('#appView').classList.contains('rail-collapsed'));
}

function isRailCollapsed() {
  return $('#appView').classList.contains('rail-collapsed');
}

function expandRail() {
  if ($('#appView').hidden) return;
  $('#appView').classList.remove('rail-collapsed');
  syncRailChrome();
}

function collapseRail() {
  if ($('#appView').hidden) return;
  $('#appView').classList.add('rail-collapsed');
  syncRailChrome();
}

function shouldCollapseRailFromBlankClick(event) {
  if (!event.target.closest('.locations-pane, .status-pane')) return false;
  if (event.target.closest([
    'button',
    'a',
    'input',
    'select',
    'textarea',
    'label',
    '[role="button"]'
  ].join(', '))) {
    return false;
  }
  return true;
}

function showStartupLoading() {
  $('#startupView').hidden = false;
  $('#loginView').hidden = true;
  $('#appView').hidden = true;
  $('.shell').classList.remove('app-active', 'rail-collapsed');
  $('#appView').classList.add('rail-collapsed');
  playStartupVideo();
}

function waitStartupLoading() {
  return wait(STARTUP_LOADING_MS);
}

function playStartupVideo() {
  const video = $('#startupView video');
  if (!video) return;
  video.currentTime = 0;
  const play = video.play();
  if (play && typeof play.catch === 'function') play.catch(() => {});
}

function pauseStartupVideo() {
  const video = $('#startupView video');
  if (video) video.pause();
}

function toast(message, type = 'ok') {
  const el = $('#toast');
  const text = el.querySelector('span') || el;
  text.textContent = type === 'error' ? cleanErrorMessage(message) : message;
  el.classList.toggle('error', type === 'error');
  el.hidden = false;
  if (text !== el) {
    text.style.animation = 'none';
    text.offsetHeight;
    text.style.animation = '';
  }
  clearTimeout(toast.timer);
  toast.timer = setTimeout(() => {
    el.hidden = true;
  }, 6200);
}

function showLogoutConfirm() {
  $('#logoutConfirm').hidden = false;
}

function hideLogoutConfirm() {
  $('#logoutConfirm').hidden = true;
}

function empty(message) {
  return `<article class="card-row"><span>${escapeHtml(message)}</span></article>`;
}

function serverLabel(server) {
  if (!server) return '智能选择节点';
  return server.name || '未命名节点';
}

function serverMeta(server) {
  if (!server) return '自动选择';
  const protocol = protocolOf(server).toUpperCase();
  return `${protocol} · ${server.rate ? `${server.rate}倍` : '默认倍率'}`;
}

function codeFromValue(code) {
  const normalized = String(code || '').trim().toUpperCase();
  if (!/^[A-Z]{2}$/.test(normalized)) return '';
  return normalized.toLowerCase();
}

function serverFlagCode(server) {
  const code = server?.country_code || server?.countryCode || server?.country || server?.region_code || server?.region;
  const countryCode = codeFromValue(code);
  if (countryCode) return countryCode;

  const name = `${server?.name || ''} ${server?.country || ''} ${server?.region || ''}`.toLowerCase();
  const matches = [
    [/香港|hong\s*kong|\bhk\b/, 'hk'],
    [/台湾|台灣|taiwan|\btw\b/, 'tw'],
    [/日本|东京|大阪|japan|tokyo|osaka|\bjp\b/, 'jp'],
    [/新加坡|singapore|\bsg\b/, 'sg'],
    [/美国|美國|洛杉矶|洛杉磯|纽约|紐約|西雅图|西雅圖|united\s*states|america|los\s*angeles|new\s*york|seattle|\bus\b|\busa\b/, 'us'],
    [/韩国|韓國|首尔|首爾|korea|seoul|\bkr\b/, 'kr'],
    [/英国|英國|伦敦|倫敦|united\s*kingdom|britain|london|\buk\b|\bgb\b/, 'gb'],
    [/德国|德國|法兰克福|法蘭克福|germany|frankfurt|\bde\b/, 'de'],
    [/法国|法國|巴黎|france|paris|\bfr\b/, 'fr'],
    [/加拿大|canada|toronto|vancouver|\bca\b/, 'ca'],
    [/澳大利亚|澳洲|澳大利亞|悉尼|australia|sydney|\bau\b/, 'au'],
    [/荷兰|荷蘭|netherlands|amsterdam|\bnl\b/, 'nl'],
    [/俄罗斯|俄羅斯|russia|moscow|\bru\b/, 'ru'],
    [/印度|india|mumbai|\bin\b/, 'in'],
    [/泰国|泰國|thailand|bangkok|\bth\b/, 'th'],
    [/越南|vietnam|\bvn\b/, 'vn'],
    [/菲律宾|菲律賓|philippines|\bph\b/, 'ph'],
    [/马来西亚|馬來西亞|malaysia|\bmy\b/, 'my'],
    [/印尼|印度尼西亚|印度尼西亞|indonesia|\bid\b/, 'id'],
    [/土耳其|turkey|istanbul|\btr\b/, 'tr'],
    [/巴西|brazil|\bbr\b/, 'br']
  ];

  return matches.find(([pattern]) => pattern.test(name))?.[1] || 'global';
}

function flagImage(server) {
  const code = serverFlagCode(server);
  const label = code === 'global' ? '未知地区' : code.toUpperCase();
  return `<img src="./assets/flags/${code}.svg" alt="${escapeHtml(label)}">`;
}

function reconcileSelectedServer(servers = supportedServers(), previous = selectedServer) {
  if (!servers.length) {
    selectedServer = null;
    return null;
  }

  if (previous) {
    const previousKey = serverIdentity(previous);
    const match = servers.find((server) => serverIdentity(server) === previousKey);
    if (match) {
      selectedServer = match;
      return match;
    }
  }

  selectedServer = servers[0] || null;
  return selectedServer;
}

function signalClass(server, index) {
  if (server?.is_online) return 'good';
  return index % 3 === 0 ? 'medium' : '';
}

function setConnected(next) {
  connected = next;
  connecting = false;
  stopConnectProgressSmoothing();
  $('#connectBtn').classList.remove('is-loading');
  $('#connectBtn').disabled = false;
  setConnectProgress(connected ? 100 : 0);
  document.body.classList.remove('is-connecting');
  document.body.classList.toggle('is-connected', connected);
  if (connected && !connectedAt) connectedAt = Date.now();
  if (!connected) connectedAt = null;
  updateTimer();
  clearInterval(timer);
  if (connected) {
    timer = setInterval(updateTimer, 1000);
    startConnectedHeartbeat();
  } else {
    stopConnectedHeartbeat();
  }
  resetTrafficRates();
  startTrafficPolling();
}

function setConnectProgress(value, allowDecrease = false) {
  let progress = Math.max(0, Math.min(100, Number(value || 0)));
  if (connecting && !allowDecrease && progress < connectVisualProgress) {
    progress = connectVisualProgress;
  }
  connectVisualProgress = progress;
  $('#connectBtn').style.setProperty('--connect-progress', `${progress}%`);
  document.documentElement.style.setProperty('--heart-fill-offset', `${(218 - progress * 2.18).toFixed(2)}px`);
}

function stopConnectProgressSmoothing() {
  if (connectProgressTimer) clearInterval(connectProgressTimer);
  connectProgressTimer = null;
}

function startConnectProgressSmoothing() {
  stopConnectProgressSmoothing();
  connectProgressTimer = setInterval(() => {
    if (!connecting) {
      stopConnectProgressSmoothing();
      return;
    }
    const cap = 88;
    if (connectVisualProgress >= cap) return;
    const step = Math.max(0.45, (cap - connectVisualProgress) * 0.045);
    setConnectProgress(Math.min(cap, connectVisualProgress + step));
  }, 140);
}

function setConnecting(next) {
  connecting = next;
  $('#connectBtn').classList.toggle('is-loading', connecting);
  $('#connectBtn').disabled = connecting;
  document.body.classList.toggle('is-connecting', connecting);
  setConnectProgress(connecting ? 8 : (connected ? 100 : 0), true);
  if (connecting) {
    startConnectProgressSmoothing();
  } else {
    stopConnectProgressSmoothing();
  }
  $('#connectBtn').textContent = connecting ? '正在连接' : (connected ? '断开连接' : '一键连接');
}

function updateTimer() {
  if (!connectedAt) {
    $('#connectionTime').textContent = '--';
    return;
  }
  const diff = Math.floor((Date.now() - connectedAt) / 1000);
  const h = String(Math.floor(diff / 3600)).padStart(2, '0');
  const m = String(Math.floor((diff % 3600) / 60)).padStart(2, '0');
  const s = String(diff % 60).padStart(2, '0');
  $('#connectionTime').textContent = `${h}:${m}:${s}`;
}

function trafficLevel(bytesPerSecond) {
  if (!Number.isFinite(bytesPerSecond) || bytesPerSecond <= 0) return 1;
  const megabytesPerSecond = bytesPerSecond / 1000 / 1000;
  return Math.max(1, Math.min(50, Math.round(megabytesPerSecond)));
}

function connectedTrafficLevel(bytesPerSecond) {
  if (!Number.isFinite(bytesPerSecond) || bytesPerSecond <= 0) return 1;
  const megabytesPerSecond = bytesPerSecond / 1000 / 1000;
  return Math.max(1, Math.min(25, Math.round(megabytesPerSecond)));
}

function easeSpeedPeriod(current, target, slowDownFactor) {
  if (target < current) return target;
  return current + (target - current) * slowDownFactor;
}

function setHeartbeatLevel(level) {
  const previousTargetRhythmMs = connectedHeartTargetRhythmMs;
  const normalized = Math.max(1, Math.min(25, Number(level || 1)));
  connectedHeartTargetAnimMs = Math.round(Math.max(520, 860 - (normalized - 1) * (340 / 24)));
  connectedHeartTargetRhythmMs = Math.round(Math.max(500, 2000 - (normalized - 1) * (1500 / 24)));
  document.documentElement.style.setProperty('--heartbeat-speed', `${(connectedHeartTargetAnimMs / 1000).toFixed(2)}s`);
  if (connected && !connecting && connectedHeartTargetRhythmMs < previousTargetRhythmMs) {
    connectedHeartAnimMs = connectedHeartTargetAnimMs;
    connectedHeartRhythmMs = connectedHeartTargetRhythmMs;
    scheduleConnectedHeartbeat(80);
  }
}

function triggerConnectedHeartbeat() {
  if (!connected || connecting) return;
  connectedHeartPulseId += 1;
  const thisPulse = connectedHeartPulseId;
  const duration = Math.round(Math.max(520, Math.min(860, connectedHeartAnimMs)));
  $$('.heart-effect').forEach((stage) => {
    stage.style.setProperty('--connected-heart-anim-ms', `${duration}ms`);
    stage.classList.remove('heart-pulse-on');
    void stage.offsetWidth;
    stage.classList.add('heart-pulse-on');
  });
  setTimeout(() => {
    if (thisPulse === connectedHeartPulseId) {
      $$('.heart-effect').forEach((stage) => stage.classList.remove('heart-pulse-on'));
    }
  }, duration + 40);
}

function scheduleConnectedHeartbeat(delayMs = 180) {
  clearTimeout(connectedHeartTimer);
  if (!connected) return;
  connectedHeartTimer = setTimeout(() => {
    connectedHeartAnimMs = easeSpeedPeriod(connectedHeartAnimMs, connectedHeartTargetAnimMs, .08);
    connectedHeartRhythmMs = easeSpeedPeriod(connectedHeartRhythmMs, connectedHeartTargetRhythmMs, .08);
    triggerConnectedHeartbeat();
    scheduleConnectedHeartbeat(Math.round(connectedHeartRhythmMs));
  }, delayMs);
}

function startConnectedHeartbeat() {
  clearTimeout(connectedHeartTimer);
  connectedHeartAnimMs = connectedHeartTargetAnimMs;
  connectedHeartRhythmMs = connectedHeartTargetRhythmMs;
  connectedHeartPulseId += 1;
  scheduleConnectedHeartbeat(220);
}

function stopConnectedHeartbeat() {
  clearTimeout(connectedHeartTimer);
  connectedHeartTimer = null;
  connectedHeartPulseId += 1;
  $$('.heart-effect').forEach((stage) => stage.classList.remove('heart-pulse-on'));
}

function setDisconnectedHeartLevel(level) {
  const normalized = Math.max(1, Math.min(50, Number(level || 1)));
  disconnectedHeartTargetPeriodMs = Math.round(Math.max(300, 2000 - (normalized - 1) * (1700 / 49)));
}

function resetTrafficRates() {
  lastTrafficSnapshot = null;
  $('#uploadText').textContent = rateText(0);
  $('#downloadText').textContent = rateText(0);
  setHeartbeatLevel(1);
  setDisconnectedHeartLevel(1);
}

function animateDisconnectedHeart(now) {
  if (!disconnectedHeartLastFrameAt) disconnectedHeartLastFrameAt = now;
  const elapsed = Math.min(80, Math.max(0, now - disconnectedHeartLastFrameAt));
  disconnectedHeartLastFrameAt = now;
  disconnectedHeartPeriodMs = easeSpeedPeriod(disconnectedHeartPeriodMs, disconnectedHeartTargetPeriodMs, .005);
  disconnectedHeartPulse = (disconnectedHeartPulse + elapsed / Math.max(300, disconnectedHeartPeriodMs)) % 1;

  $$('.heart-effect').forEach((stage) => {
    const measure = stage.querySelector('.heart-disconnect-measure');
    const strips = stage.querySelectorAll('.heart-disconnect-strip, .heart-disconnect-strip-glow');
    if (!measure || !strips.length) return;

    const total = measure.getTotalLength();
    const stripLength = total * .11;
    const stripRest = Math.max(1, total - stripLength);
    const stripOffset = -disconnectedHeartPulse * total;

    strips.forEach((path) => {
      path.style.setProperty('--disconnect-strip-length', stripLength.toFixed(2));
      path.style.setProperty('--disconnect-strip-rest', stripRest.toFixed(2));
      path.style.setProperty('--disconnect-strip-offset', stripOffset.toFixed(2));
    });
  });

  requestAnimationFrame(animateDisconnectedHeart);
}

function stopTrafficPolling() {
  clearInterval(trafficTimer);
  trafficTimer = null;
  lastTrafficSnapshot = null;
  setHeartbeatLevel(1);
}

async function pollTraffic() {
  if (!window.xiaov2b.getTraffic) return;
  try {
    const snapshot = await window.xiaov2b.getTraffic();
    const now = Date.now();
    const uploadTotal = Number(snapshot.uploadTotal || 0);
    const downloadTotal = Number(snapshot.downloadTotal || 0);
    const source = String(snapshot.source || '');
    let uploadRate = 0;
    let downloadRate = 0;
    const hasRealtimeRate = Number.isFinite(Number(snapshot.uploadRate)) || Number.isFinite(Number(snapshot.downloadRate));

    if (hasRealtimeRate) {
      uploadRate = Math.max(0, Number(snapshot.uploadRate || 0));
      downloadRate = Math.max(0, Number(snapshot.downloadRate || 0));
      const totalRate = uploadRate + downloadRate;
      setHeartbeatLevel(connectedTrafficLevel(totalRate));
      setDisconnectedHeartLevel(connected ? 1 : trafficLevel(totalRate));
    } else if (lastTrafficSnapshot && lastTrafficSnapshot.source === source) {
      const elapsed = Math.max((now - lastTrafficSnapshot.at) / 1000, 0.5);
      uploadRate = Math.max(0, uploadTotal - lastTrafficSnapshot.uploadTotal) / elapsed;
      downloadRate = Math.max(0, downloadTotal - lastTrafficSnapshot.downloadTotal) / elapsed;
      const totalRate = uploadRate + downloadRate;
      setHeartbeatLevel(connectedTrafficLevel(totalRate));
      setDisconnectedHeartLevel(connected ? 1 : trafficLevel(totalRate));
    }

    $('#uploadText').textContent = rateText(uploadRate);
    $('#downloadText').textContent = rateText(downloadRate);

    lastTrafficSnapshot = { uploadTotal, downloadTotal, uploadRate, downloadRate, source, at: now };
  } catch (_error) {
    setHeartbeatLevel(1);
    setDisconnectedHeartLevel(1);
  }
}

function startTrafficPolling() {
  stopTrafficPolling();
  setHeartbeatLevel(8);
  setDisconnectedHeartLevel(1);
  pollTraffic();
  trafficTimer = setInterval(pollTraffic, 1000);
}

function modeText(mode) {
  return { rule: '规则', global: '全局', auto: '自动' }[mode] || '规则';
}

function renderMode() {
  $$('.mode-segment button[data-mode]').forEach((button) => {
    button.classList.toggle('active', button.dataset.mode === currentMode);
    button.disabled = autoNodeSelecting;
  });
  const latencyButton = $('#latencyTestBtn');
  if (latencyButton) {
    latencyButton.disabled = autoNodeSelecting || currentMode === 'auto';
    latencyButton.classList.toggle('is-disabled', currentMode === 'auto');
    latencyButton.title = currentMode === 'auto' ? '自动模式下不可手动测速' : '测速';
  }
}

function renderModeState() {
  renderMode();
  if ($('#autoConnectText')) $('#autoConnectText').textContent = modeText(currentMode);
  if (dashboard) renderNodes();
}

function scrollPaymentQrToCenter() {
  requestAnimationFrame(() => {
    requestAnimationFrame(() => {
      const qrWrap = document.querySelector('.payment-qr-wrap');
      if (!qrWrap || !$('#plansPage')?.classList.contains('active')) return;
      qrWrap.scrollIntoView({
        behavior: 'smooth',
        block: 'center',
        inline: 'nearest'
      });
    });
  });
}

function scrollConfirmPurchaseIntoView() {
  requestAnimationFrame(() => {
    requestAnimationFrame(() => {
      const button = document.querySelector('.confirm-purchase-button');
      if (!button || !$('#plansPage')?.classList.contains('active')) return;
      button.scrollIntoView({
        behavior: 'smooth',
        block: 'nearest',
        inline: 'nearest'
      });
    });
  });
}

async function refreshDashboardForModeSwitch() {
  const previousServer = selectedServer;
  dashboard = await window.xiaov2b.refresh();
  reconcileSelectedServer(supportedServers(), previousServer);
  render(dashboard);
  return dashboard;
}

async function setMode(mode, silent = false, force = false) {
  const nextMode = normalizeMode(mode);
  if (!force && nextMode === currentMode && !autoNodeSelecting) {
    renderModeState();
    return { mode: currentMode, applied: false };
  }

  currentMode = nextMode;
  renderModeState();
  resetTrafficRates();
  try {
    await refreshDashboardForModeSwitch();
    if (currentMode === 'auto' && !force) await selectFastestNode({ silent });
    const result = await window.xiaov2b.setMode(currentMode);
    if (connected && selectedServer) await applySelectedServer(true);
    if (!silent) toast(result.applied ? `已切换为${modeText(currentMode)}模式` : `已保存${modeText(currentMode)}模式，连接后生效`);
    return result;
  } catch (error) {
    if (!silent) toast(error.message, 'error');
    return { mode: currentMode, applied: false, error: error.message };
  }
}

async function applySelectedServer(silent = false) {
  if (!connected || !selectedServer || !window.xiaov2b.selectProxy) return { applied: false };
  try {
    const result = await window.xiaov2b.selectProxy(serverLabel(selectedServer));
    if (!silent && result.applied) toast(`已切换到 ${serverLabel(selectedServer)}`);
    if (!silent && result.applied === false && result.reason === 'not_found') {
      toast('节点切换未生效，内核未找到对应代理组', 'error');
    }
    return result;
  } catch (error) {
    if (!silent) toast(error.message, 'error');
    return { applied: false, error: error.message };
  }
}

async function selectFastestNode({ silent = false } = {}) {
  if (autoNodeSelecting) return selectedServer;
  const servers = supportedServers();
  if (!servers.length) {
    if (!silent) toast('暂无可用节点', 'error');
    return null;
  }

  autoNodeSelecting = true;
  renderMode();
  try {
    const latencyMap = await measureLatencyForServers(servers);
    const best = pickFastestServer(servers, latencyMap);
    if (!best?.server) return selectedServer;

    selectedServer = best.server;
    if (dashboard) render(dashboard);
    if (connected) await applySelectedServer(true);
    if (!silent) {
      const suffix = Number.isFinite(best.latency) ? ` · ${Math.round(best.latency)}ms` : '';
      toast(`已自动选择 ${serverLabel(best.server)}${suffix}`);
    }
    return selectedServer;
  } catch (error) {
    if (!silent) toast(error.message, 'error');
    return selectedServer;
  } finally {
    autoNodeSelecting = false;
    renderMode();
  }
}

async function testNodeLatency() {
  const button = $('#latencyTestBtn');
  if (currentMode === 'auto') {
    toast('自动模式下不可手动测速', 'error');
    return;
  }
  const servers = supportedServers();
  if (!servers.length) {
    toast('暂无可测速节点', 'error');
    return;
  }

  if (button) {
    button.disabled = true;
    button.classList.add('is-testing');
  }
  try {
    await measureLatencyForServers(servers);
    renderNodes();
    toast('节点延迟已更新');
  } catch (error) {
    toast(error.message, 'error');
  } finally {
    if (button) {
      button.classList.remove('is-testing');
    }
    renderMode();
  }
}

function expireState(timestamp) {
  if (!timestamp) return { text: '长期有效', hint: '当前套餐没有到期限制' };
  const expireAt = Number(timestamp) * 1000;
  const days = Math.ceil((expireAt - Date.now()) / 86400000);
  if (days < 0) return { text: dateText(timestamp), hint: '套餐已到期' };
  if (days === 0) return { text: dateText(timestamp), hint: '今天到期' };
  return { text: dateText(timestamp), hint: `剩余 ${days} 天` };
}

function renderLocalNetwork() {
  const locationEl = $('#localLocationText');
  const ipEl = $('#localIpText');
  if (!locationEl || !ipEl) return;
  locationEl.textContent = localNetwork.locationText || '--';
  ipEl.textContent = localNetwork.ip || '--';
}

async function refreshLocalNetwork() {
  if (!window.xiaov2b.localLocation) return;
  localNetwork = { locationText: '获取中', ip: '获取中' };
  renderLocalNetwork();
  try {
    const result = await window.xiaov2b.localLocation();
    localNetwork = {
      locationText: result?.locationText || '--',
      ip: result?.ip || '--'
    };
  } catch {
    localNetwork = { locationText: '--', ip: '--' };
  }
  renderLocalNetwork();
}

function render(data) {
  dashboard = data;
  const { user, subscribe, servers, protocols, config } = dashboard;
  const visibleServers = supportedServers();
  reconcileSelectedServer(visibleServers);

  const used = Number(subscribe.u || 0) + Number(subscribe.d || 0);
  const total = Number(subscribe.transfer_enable || 0);
  const remain = Math.max(total - used, 0);
  const protocolText = protocols.length ? protocols.map((item) => item.toUpperCase()).join(' / ') : '暂无匹配协议';

  $('#protocols').textContent = connected ? protocolOf(selectedServer).toUpperCase() : protocolText;
  $('#uploadText').textContent = rateText(lastTrafficSnapshot?.uploadRate || 0);
  $('#downloadText').textContent = rateText(lastTrafficSnapshot?.downloadRate || 0);
  $('#ipAddress').textContent = connected ? '127.0.0.1' : '--';
  $('#statusTitle').innerHTML = connected ? '已连接<br>正在代理' : '连接后<br>开始使用';
  $('#selectedNode').textContent = serverLabel(selectedServer);
  $('#selectedMeta').textContent = serverMeta(selectedServer);
  $('#selectedMark').innerHTML = flagImage(selectedServer);
  $('#nodeCount').textContent = visibleServers.length;
  $('#autoConnectText').textContent = modeText(currentMode);
  renderLocalNetwork();
  $('#connectBtn').disabled = connecting;
  $('#connectBtn').textContent = connecting ? '正在连接' : (connected ? '断开连接' : '一键连接');

  renderAccountOverview(subscribe);
  renderSettingsControls();
  renderMode();
  renderNodes();
  renderPlans();
  renderOrders();
}

function renderAccountOverview(subscribe) {
  const used = Number(subscribe.u || 0) + Number(subscribe.d || 0);
  const total = Number(subscribe.transfer_enable || 0);
  const remain = Math.max(total - used, 0);
  const remainPercent = total > 0 ? Math.max(0, Math.min(100, (remain / total) * 100)) : 100;
  const expire = expireState(subscribe.expired_at);
  const usedPercent = total > 0 ? Math.max(0, Math.min(100, (used / total) * 100)) : 0;

  $('#accountOverview').innerHTML = `
    <article class="overview-card metric-card traffic-overview" style="--remain:${remainPercent}%;--used:${usedPercent}%">
      <div class="metric-ring" aria-hidden="true">
        <span>${Math.round(remainPercent)}%</span>
      </div>
      <div class="metric-copy">
        <small>剩余流量</small>
        <strong>${bytes(remain)}</strong>
        <span>已用 ${bytes(used)} / ${total > 0 ? bytes(total) : '不限量'}</span>
      </div>
      <div class="traffic-bar"><span></span></div>
    </article>
    <article class="overview-card metric-card expire-overview">
      <div class="expire-date">
        <small>到期时间</small>
      </div>
      <div class="expire-status">
        <span>${escapeHtml(expire.hint)}</span>
      </div>
      <p>请在到期前续费，避免节点连接中断</p>
      <strong class="expire-date-value">${escapeHtml(expire.text)}</strong>
    </article>
  `;
}

function renderSettingsControls() {
  const controls = $('#settingsControls');
  if (!controls) return;
  controls.innerHTML = `
    <article class="setting-row startup-setting-card">
      <div>
        <strong>开机启动</strong>
        <p>打开电脑后自动启动 XiaoV2B 客户端</p>
      </div>
      <label class="switch" aria-label="开机启动">
        <input id="startupToggle" type="checkbox" ${startupEnabled ? 'checked' : ''}>
        <span></span>
      </label>
    </article>
  `;
}

async function refreshStartupSetting() {
  if (!window.xiaov2b.getStartup) return;
  try {
    const result = await window.xiaov2b.getStartup();
    startupEnabled = Boolean(result.enabled);
  } catch {
    startupEnabled = false;
  }
  renderSettingsControls();
}

function renderNodes() {
  const servers = supportedServers();
  const filtered = servers;
  const lockManualNode = currentMode === 'auto';

  $('#nodeList').innerHTML = filtered.length ? filtered.map((server, index) => {
    const originalIndex = servers.indexOf(server);
    return `
      <button class="location-row ${server === selectedServer ? 'selected' : ''}" data-server="${originalIndex}" ${lockManualNode ? 'disabled' : ''}>
        <span class="location-badge">${flagImage(server)}</span>
        <span>
          <strong>${escapeHtml(serverLabel(server))}</strong>
          <span>${escapeHtml(serverMeta(server))}</span>
        </span>
        <span class="node-latency">${escapeHtml(latencyText(server, index))}</span>
        <span class="signal ${signalClass(server, index)}"><i></i><i></i><i></i><i></i></span>
      </button>
    `;
  }).join('') : empty('暂无可用节点');
}



function paymentQrHtml(state) {
  if (!state?.qr) return '';
  const fallback = state.qrFallback ? ` data-fallback="${escapeHtml(state.qrFallback)}"` : '';
  return `
    <div class="payment-qr-wrap">
      <img class="payment-qr-img" src="${escapeHtml(state.qr)}"${fallback} alt="付款二维码">
      <span>${escapeHtml(paymentQrLabel(state))}</span>
    </div>
  `;
}

function paymentMethodName(method) {
  return String(method?.name || method?.payment || method?.title || '支付');
}

function paymentQrLabel(state) {
  const name = String(state?.paymentMethodName || '').trim();
  const lowerName = name.toLowerCase();
  if (/支付宝|alipay/.test(lowerName)) return '支付宝付款二维码';
  if (/微信|wechat|weixin|wxpay|wx/.test(lowerName)) return '微信付款二维码';
  if (name) return `${name}付款二维码`;
  return state?.directQr ? '支付平台付款二维码' : '付款二维码';
}

function renderPlanCheckout(plan) {
  if (!checkoutState || Number(checkoutState.planId) !== Number(plan.id)) return '';
  const order = checkoutState.order || {};
  const methods = checkoutState.paymentMethods || [];
  const status = orderStatusLabels[checkoutState.status ?? order.status] || '待支付';
  const amount = money(order.total_amount, dashboard.config.currency_symbol);
  const methodButtons = methods.length ? methods.map((method) => {
    const methodName = paymentMethodName(method);
    const selected = String(checkoutState.paymentMethodId ?? '') === String(method.id);
    return `
    <button class="outline payment-method-button ${selected ? 'selected' : ''}" data-pay-method="${escapeHtml(method.id)}" data-pay-name="${escapeHtml(methodName)}" type="button">
      ${escapeHtml(methodName)}
    </button>
  `;
  }).join('') : `
    <button class="outline payment-method-button ${String(checkoutState.paymentMethodId ?? '') === '0' ? 'selected' : ''}" data-pay-method="0" data-pay-name="余额支付" type="button">余额支付/免费开通</button>
  `;
  return `
    <section class="plan-checkout">
      <div class="checkout-head">
        <div>
          <strong>${escapeHtml(order.plan?.name || plan.name)}</strong>
          <span>订单 ${escapeHtml(order.trade_no || checkoutState.tradeNo || '')} · ${status}</span>
        </div>
        <strong>${amount}</strong>
      </div>
      <div class="payment-methods">${methodButtons}</div>
      ${paymentQrHtml(checkoutState)}
      ${checkoutState.paymentLink ? `
        <button class="primary payment-link-button" data-open-payment="${escapeHtml(checkoutState.paymentLink)}" type="button">打开付款链接</button>
      ` : ''}
      <div class="payment-tools">
        <button class="outline" data-check-order="${escapeHtml(order.trade_no || checkoutState.tradeNo || '')}" type="button">刷新支付状态</button>
        <button class="outline danger-outline" data-cancel-payment="${escapeHtml(order.trade_no || checkoutState.tradeNo || '')}" type="button">取消支付</button>
      </div>
    </section>
  `;
}


function renderCouponPanel(plan) {
  if (!pendingPurchase || Number(pendingPurchase.planId) !== Number(plan.id)) return '';
  if (pendingPurchase.readOnly) return '';
  const label = periodLabels[pendingPurchase.period] || '周期';
  const price = money(plan[pendingPurchase.period], dashboard.config.currency_symbol);
  const checked = pendingPurchase.useCoupon ? 'checked' : '';
  const couponMessageClass = pendingPurchase.couponStatus ? ` ${pendingPurchase.couponStatus}` : '';
  const couponMessage = pendingPurchase.couponMessage || '';
  return `
    <section class="coupon-panel">
      <div class="coupon-summary">
        <strong>${escapeHtml(label)} · ${price}</strong>
        <span>确认后创建订单</span>
      </div>
      <div class="purchase-options">
        <div class="balance-row">
          <span class="balance-line">
            <span>当前余额</span>
            <strong>${accountBalanceText()}</strong>
          </span>
          <em>付款时优先使用余额抵扣</em>
        </div>
        <label class="coupon-toggle">
          <input type="checkbox" data-coupon-toggle ${checked}>
          <span>使用优惠码</span>
        </label>
        <div class="coupon-code-row" ${pendingPurchase.useCoupon ? '' : 'hidden'}>
          <input data-coupon-code type="text" value="${escapeHtml(pendingPurchase.couponCode || '')}" placeholder="输入优惠码">
          <button class="outline coupon-check-button" data-check-coupon type="button">验证</button>
        </div>
        <span class="coupon-message${couponMessageClass}" data-coupon-message ${couponMessage ? '' : 'hidden'}>${escapeHtml(couponMessage)}</span>
      </div>
      <button class="primary confirm-purchase-button" data-confirm-purchase="${escapeHtml(plan.id)}" data-period="${escapeHtml(pendingPurchase.period)}" type="button">
        确认购买
      </button>
    </section>
  `;
}

function renderPlans() {
  $('#planList').innerHTML = dashboard.plans.length ? dashboard.plans.map((plan) => {
    const isSelected = Number(selectedPlanId) === Number(plan.id);
    const periods = purchasablePeriods(plan).map(([key, label]) => {
      const isPeriodSelected = pendingPurchase
        && Number(pendingPurchase.planId) === Number(plan.id)
        && pendingPurchase.period === key;
      return `
        <button class="outline plan-buy-button ${isPeriodSelected ? 'selected' : ''}" data-buy-plan="${escapeHtml(plan.id)}" data-period="${escapeHtml(key)}" type="button">
          ${escapeHtml(label)} ${money(plan[key], dashboard.config.currency_symbol)}
        </button>
      `;
    }).join('');
    return `
      <article class="card-row plan-card ${isSelected ? 'selected' : ''}">
        <button class="plan-select" data-select-plan="${escapeHtml(plan.id)}" type="button">
          <span>
            <strong>${escapeHtml(plan.name)}</strong>
            <span>${bytes(Number(plan.transfer_enable || 0) * 1073741824)} 流量 · ${plan.device_limit || '不限'} 设备</span>
          </span>
          <b>${isSelected ? '已选择' : '选择套餐'}</b>
        </button>
        ${isSelected ? `
          <div class="plan-actions">
            ${periods || '<span>暂无可购买周期</span>'}
          </div>
          ${renderCouponPanel(plan)}
          ${renderPlanCheckout(plan)}
        ` : ''}
      </article>
    `;
  }).join('') : empty('暂无套餐');
}

function renderOrders() {
  const orders = latestDisplayOrders(dashboard.orders);
  $('#orderList').innerHTML = orders.length ? orders.map((order) => {
    const status = Number(order.status);
    const tradeNo = order.trade_no || '';
    return `
      <article class="card-row split order-row">
        <span class="order-main">
          <strong>${escapeHtml(order.plan?.name || order.period || '订单')}</strong>
          <span>${money(order.total_amount, dashboard.config.currency_symbol)} · ${orderStatusLabels[status] || '待处理'}</span>
          <span class="order-trade">
            <em>订单号</em>
            <code>${escapeHtml(tradeNo)}</code>
          </span>
        </span>
        ${status === 0 ? `
          <span class="order-actions">
            <button class="outline" data-cancel="${escapeHtml(tradeNo)}">取消</button>
            <button class="primary order-continue-button" data-continue-payment="${escapeHtml(tradeNo)}" type="button">继续支付</button>
          </span>
        ` : '<span class="order-done">已处理</span>'}
      </article>
    `;
  }).join('') : empty('暂无订单');
}

async function restore() {
  let startupDelay = null;
  try {
    showStartupLoading();
    startupDelay = waitStartupLoading();
    const status = await window.xiaov2b.authStatus();
    if (!status.authenticated) {
      await startupDelay;
      showApp(false);
      return;
    }
    const result = await window.xiaov2b.restore();
    if (!result.authenticated) {
      await startupDelay;
      showApp(false);
      return;
    }
    render(result.dashboard);
    refreshStartupSetting();
    refreshLocalNetwork();
    startTrafficPolling();
    await startupDelay;
    showApp(true);
  } catch (error) {
    if (startupDelay) await startupDelay;
    showApp(false);
    toast(error.message, 'error');
  }
}

async function connect() {
  if (connecting) return;
  if (!Array.isArray(dashboard?.servers) || !dashboard.servers.length) {
    toast('没有可用节点', 'error');
    return;
  }
  setConnecting(true);
  try {
    await window.xiaov2b.connect();
    setConnectProgress(92);
    await setMode(currentMode, true, true);
    setConnectProgress(100);
    await new Promise((resolve) => setTimeout(resolve, 180));
    setConnected(true);
    await applySelectedServer(true);
    render(dashboard);
    refreshLocalNetwork();
    toast('已连接');
  } catch (error) {
    setConnectProgress(0, true);
    setConnecting(false);
    toast(error.message, 'error');
  }
}

async function disconnect() {
  await window.xiaov2b.disconnect();
  setConnected(false);
  if (dashboard) render(dashboard);
  refreshLocalNetwork();
  toast('已断开');
}


function stopCheckoutPolling() {
  if (checkoutPollTimer) clearInterval(checkoutPollTimer);
  checkoutPollTimer = null;
}

function startCheckoutPolling(tradeNo) {
  stopCheckoutPolling();
  if (!tradeNo) return;
  checkoutPollTimer = setInterval(() => refreshCheckoutStatus(tradeNo, true), 5000);
}

async function paymentVisual(type, data) {
  const value = String(data || '');
  if (!value) return { qr: '', qrFallback: '', directQr: false, paymentLink: '' };
  if (Number(type) === 0 && (/^https?:\/\//i.test(value) || /^data:image\//i.test(value))) {
    const platformImage = await window.xiaov2b.paymentImageDataUrl(value);
    if (platformImage) {
      return {
        qr: platformImage,
        qrFallback: '',
        directQr: true,
        paymentLink: /^https?:\/\//i.test(value) ? value : ''
      };
    }
    return {
      qr: await window.xiaov2b.makeQrCode(value),
      qrFallback: '',
      directQr: false,
      paymentLink: /^https?:\/\//i.test(value) ? value : ''
    };
  }
  return {
    qr: await window.xiaov2b.makeQrCode(value),
    qrFallback: '',
    directQr: false,
    paymentLink: Number(type) === 1 && /^https?:\/\//i.test(value) ? value : ''
  };
}

async function openCheckoutInPlans(planId, tradeNo) {
  const [detail, methods] = await Promise.all([
    window.xiaov2b.orderDetail(tradeNo),
    window.xiaov2b.paymentMethods()
  ]);
  const order = detail.data || {};
  selectedPlanId = Number(planId);
  pendingPurchase = {
    planId: Number(planId),
    period: order.period || '',
    useCoupon: false,
    couponCode: '',
    couponStatus: '',
    couponMessage: '',
    readOnly: true
  };
  checkoutState = {
    planId: Number(planId),
    tradeNo,
    order,
    paymentMethods: methods.data || [],
    status: order.status ?? 0,
    qr: '',
    qrFallback: '',
    directQr: false,
    paymentLink: ''
  };
  startCheckoutPolling(tradeNo);
}

async function continuePayment(tradeNo, button) {
  const order = dashboard?.orders?.find((item) => String(item.trade_no) === String(tradeNo));
  const planId = orderPlanId(order);
  if (!planId) {
    toast('该订单无法在套餐页继续支付', 'error');
    return;
  }

  const originalText = button.textContent;
  button.disabled = true;
  button.textContent = '加载中...';
  try {
    await openCheckoutInPlans(planId, tradeNo);
    dashboard = await window.xiaov2b.refresh();
    render(dashboard);
    switchPage('plans');
    requestAnimationFrame(() => {
      document.querySelector('.plan-checkout')?.scrollIntoView({
        behavior: 'smooth',
        block: 'center',
        inline: 'nearest'
      });
    });
  } catch (error) {
    toast(error.message, 'error');
  } finally {
    button.disabled = false;
    button.textContent = originalText;
  }
}

async function buyPlan(planId, period, button) {
  if (blockPurchaseWhenOrderExists()) return;
  const originalText = button.textContent;
  button.disabled = true;
  button.textContent = '创建订单中...';
  try {
    const result = await window.xiaov2b.createOrder({
      plan_id: Number(planId),
      period,
      coupon_code: undefined
    });
    await openCheckoutInPlans(planId, result.data);
    dashboard = await window.xiaov2b.refresh();
    render(dashboard);
    toast('订单已创建，请选择支付方式');
  } catch (error) {
    toast(error.message, 'error');
  } finally {
    button.disabled = false;
    button.textContent = originalText;
  }
}

async function payCheckout(method, button) {
  if (!checkoutState?.tradeNo) return;
  const originalText = button.textContent;
  const paymentMethodId = String(method);
  const paymentMethodName = button.dataset.payName || originalText.trim();
  button.disabled = true;
  button.textContent = '获取付款码...';
  try {
    checkoutState = {
      ...checkoutState,
      paymentMethodId,
      paymentMethodName,
      qr: '',
      qrFallback: '',
      directQr: false,
      paymentLink: ''
    };
    renderPlans();
    const result = await window.xiaov2b.checkoutOrder({
      trade_no: checkoutState.tradeNo,
      method: Number(method),
      token: ''
    });
    if (Number(result.type) === -1) {
      checkoutState.status = 3;
      checkoutState.qr = '';
      checkoutState.paymentLink = '';
      stopCheckoutPolling();
      dashboard = await window.xiaov2b.refresh();
      render(dashboard);
      toast('订单已完成');
      return;
    }
    const visual = await paymentVisual(result.type, result.data);
    checkoutState = { ...checkoutState, paymentMethodId, paymentMethodName, ...visual };
    renderPlans();
    if (checkoutState.qr) scrollPaymentQrToCenter();
  } catch (error) {
    toast(error.message, 'error');
  } finally {
    button.disabled = false;
    button.textContent = originalText;
  }
}

async function refreshCheckoutStatus(tradeNo, silent = false) {
  if (!tradeNo) return;
  try {
    const result = await window.xiaov2b.checkOrder(tradeNo);
    if (checkoutState?.tradeNo === tradeNo) {
      checkoutState.status = result.data;
      if (Number(result.data) !== 0) stopCheckoutPolling();
      renderPlans();
    }
    if (Number(result.data) === 3) {
      dashboard = await window.xiaov2b.refresh();
      render(dashboard);
      if (!silent) toast('订单已完成');
    } else if (!silent) {
      toast(orderStatusLabels[result.data] || '订单状态未变化');
    }
  } catch (error) {
    if (!silent) toast(error.message, 'error');
  }
}

async function cancelCheckoutPayment(tradeNo, button) {
  if (!tradeNo) return;
  const originalText = button.textContent;
  button.disabled = true;
  button.textContent = '取消中...';
  try {
    await window.xiaov2b.cancelOrder(tradeNo);
    stopCheckoutPolling();
    checkoutState = null;
    pendingPurchase = null;
    dashboard = await window.xiaov2b.refresh();
    render(dashboard);
    toast('支付已取消');
  } catch (error) {
    toast(error.message, 'error');
  } finally {
    button.disabled = false;
    button.textContent = originalText;
  }
}

function selectPurchasePeriod(planId, period) {
  if (blockPurchaseWhenOrderExists()) return;
  if (checkoutState?.tradeNo && Number(checkoutState.status) === 0) {
    toast('请先取消当前支付，再重新选择周期', 'error');
    return;
  }
  selectedPlanId = Number(planId);
  pendingPurchase = {
    planId: Number(planId),
    period,
    useCoupon: false,
    couponCode: '',
    couponStatus: '',
    couponMessage: ''
  };
  renderPlans();
}

async function checkCoupon(button) {
  if (!pendingPurchase?.useCoupon) return;
  const couponInput = document.querySelector('[data-coupon-code]');
  const code = String(couponInput?.value || '').trim();
  pendingPurchase.couponCode = code;

  if (!code) {
    pendingPurchase.couponStatus = 'error';
    pendingPurchase.couponMessage = '请输入优惠码';
    renderPlans();
    return;
  }

  const originalText = button.textContent;
  button.disabled = true;
  button.textContent = '验证中...';
  try {
    const result = await window.xiaov2b.checkCoupon({
      code,
      plan_id: Number(pendingPurchase.planId)
    });
    const coupon = result?.data || {};
    const plan = purchasePlan();
    if (!couponAllowedForPeriod(coupon, pendingPurchase.period)) {
      pendingPurchase.couponStatus = 'error';
      pendingPurchase.couponMessage = '该优惠码不能用于当前周期';
      renderPlans();
      return;
    }

    const discountAmount = couponDiscountAmount(coupon, plan, pendingPurchase.period);
    pendingPurchase.couponStatus = 'ok';
    pendingPurchase.couponMessage = discountAmount !== null
      ? `验证成功，已抵扣金额 ${money(discountAmount, dashboard.config.currency_symbol)}`
      : '验证成功，下单时按后台规则抵扣';
    renderPlans();
    scrollConfirmPurchaseIntoView();
  } catch (error) {
    pendingPurchase.couponStatus = 'error';
    pendingPurchase.couponMessage = cleanErrorMessage(error) || '优惠码不可用';
    renderPlans();
  } finally {
    button.disabled = false;
    button.textContent = originalText;
  }
}

async function confirmPurchase(button) {
  if (!pendingPurchase) return;
  if (blockPurchaseWhenOrderExists()) return;
  const couponInput = document.querySelector('[data-coupon-code]');
  const couponToggle = document.querySelector('[data-coupon-toggle]');
  const couponCode = couponToggle?.checked ? String(couponInput?.value || '').trim() : '';
  const originalText = button.textContent;
  button.disabled = true;
  button.textContent = '创建订单中...';
  try {
    const result = await window.xiaov2b.createOrder({
      plan_id: Number(pendingPurchase.planId),
      period: pendingPurchase.period,
      coupon_code: couponCode || undefined
    });
    await openCheckoutInPlans(pendingPurchase.planId, result.data);
    dashboard = await window.xiaov2b.refresh();
    render(dashboard);
    toast('订单已创建，请选择支付方式');
  } catch (error) {
    toast(error.message, 'error');
  } finally {
    button.disabled = false;
    button.textContent = originalText;
  }
}

function switchPage(page) {
  $$('.rail-tab').forEach((item) => item.classList.toggle('active', item.dataset.page === page));
  $$('.content-page').forEach((item) => item.classList.toggle('active', item.id === `${page}Page`));
}

$('#loginForm').addEventListener('submit', async (event) => {
  event.preventDefault();
  const form = event.currentTarget;
  const email = String(form.elements.email?.value || '').trim();
  const password = String(form.elements.password?.value || '');
  form.querySelectorAll('input, button').forEach((element) => {
    element.disabled = true;
  });
  showStartupLoading();
  const startupDelay = waitStartupLoading();
  try {
    const result = await window.xiaov2b.login({
      email,
      password
    });
    await startupDelay;
    if (!result.dashboard) {
      throw new Error('登录成功，但用户数据加载失败');
    }
    render(result.dashboard);
    refreshStartupSetting();
    refreshLocalNetwork();
    startTrafficPolling();
    await startupDelay;
    showApp(true);
  } catch (error) {
    await startupDelay;
    showApp(false);
    toast(error.message, 'error');
  } finally {
    form.querySelectorAll('input, button').forEach((element) => {
      element.disabled = false;
    });
  }
});

$('#connectBtn').addEventListener('click', () => {
  if (connecting) return;
  if (connected) disconnect();
  else connect();
});

$('#latencyTestBtn').addEventListener('click', testNodeLatency);

$('#logoutBtn').addEventListener('click', async (event) => {
  event.stopImmediatePropagation();
  if (isRailCollapsed()) {
    expandRail();
    return;
  }
  showLogoutConfirm();
});

$('#confirmLogoutBtn').addEventListener('click', async () => {
  hideLogoutConfirm();
  await window.xiaov2b.logout();
  setConnected(false);
  stopCheckoutPolling();
  checkoutState = null;
  stopTrafficPolling();
  showApp(false);
});

$('#cancelLogoutBtn').addEventListener('click', hideLogoutConfirm);

$('#logoutConfirm').addEventListener('click', (event) => {
  if (event.target.id === 'logoutConfirm') hideLogoutConfirm();
});

$$('.rail-tab').forEach((button) => {
  if (button.id === 'logoutBtn') return;
  button.addEventListener('click', (event) => {
    event.stopPropagation();
    if (isRailCollapsed()) {
      expandRail();
      return;
    }
    switchPage(button.dataset.page || 'locations');
  });
});

$('.rail').addEventListener('click', (event) => {
  if (event.target.closest('.rail-tab')) return;
  if (isRailCollapsed()) {
    expandRail();
  } else {
    collapseRail();
  }
});

$$('.mode-segment button[data-mode]').forEach((button) => {
  button.addEventListener('click', () => setMode(button.dataset.mode));
});

document.addEventListener('input', (event) => {
  const couponInput = event.target.closest('[data-coupon-code]');
  if (!couponInput || !pendingPurchase) return;
  pendingPurchase.couponCode = couponInput.value;
  pendingPurchase.couponStatus = '';
  pendingPurchase.couponMessage = '';
  const couponMessage = document.querySelector('[data-coupon-message]');
  if (couponMessage) {
    couponMessage.hidden = true;
    couponMessage.textContent = '';
    couponMessage.classList.remove('ok', 'error');
  }
});

document.addEventListener('click', async (event) => {
  if ($('#appView').hidden === false && !isRailCollapsed() && shouldCollapseRailFromBlankClick(event)) {
    collapseRail();
  }

  const selectPlanButton = event.target.closest('[data-select-plan]');
  if (selectPlanButton) {
    const nextPlanId = Number(selectPlanButton.dataset.selectPlan);
    if (selectedPlanId !== nextPlanId) pendingPurchase = null;
    selectedPlanId = nextPlanId;
    if (checkoutState && Number(checkoutState.planId) !== selectedPlanId) {
      checkoutState = null;
      stopCheckoutPolling();
    }
    renderPlans();
    return;
  }

  const couponToggle = event.target.closest('[data-coupon-toggle]');
  if (couponToggle && pendingPurchase) {
    pendingPurchase.useCoupon = couponToggle.checked;
    pendingPurchase.couponStatus = '';
    pendingPurchase.couponMessage = '';
    if (!couponToggle.checked) pendingPurchase.couponCode = '';
    renderPlans();
    return;
  }

  const serverButton = event.target.closest('[data-server]');
  if (serverButton) {
    if (currentMode === 'auto') {
      toast('自动模式下不能手动选择节点');
      return;
    }
    selectedServer = supportedServers()[Number(serverButton.dataset.server)] || selectedServer;
    render(dashboard);
    await applySelectedServer();
    return;
  }

  const buyButton = event.target.closest('[data-buy-plan]');
  if (buyButton) {
    selectPurchasePeriod(buyButton.dataset.buyPlan, buyButton.dataset.period);
    return;
  }

  const checkCouponButton = event.target.closest('[data-check-coupon]');
  if (checkCouponButton) {
    await checkCoupon(checkCouponButton);
    return;
  }

  const confirmPurchaseButton = event.target.closest('[data-confirm-purchase]');
  if (confirmPurchaseButton) {
    await confirmPurchase(confirmPurchaseButton);
    return;
  }

  const payButton = event.target.closest('[data-pay-method]');
  if (payButton) {
    await payCheckout(payButton.dataset.payMethod, payButton);
    return;
  }

  const openPaymentButton = event.target.closest('[data-open-payment]');
  if (openPaymentButton) {
    await window.xiaov2b.openExternal(openPaymentButton.dataset.openPayment);
    return;
  }

  const checkOrderButton = event.target.closest('[data-check-order]');
  if (checkOrderButton) {
    await refreshCheckoutStatus(checkOrderButton.dataset.checkOrder);
    return;
  }

  const cancelPaymentButton = event.target.closest('[data-cancel-payment]');
  if (cancelPaymentButton) {
    await cancelCheckoutPayment(cancelPaymentButton.dataset.cancelPayment, cancelPaymentButton);
    return;
  }

  const continuePaymentButton = event.target.closest('[data-continue-payment]');
  if (continuePaymentButton) {
    await continuePayment(continuePaymentButton.dataset.continuePayment, continuePaymentButton);
    return;
  }

  const cancelButton = event.target.closest('[data-cancel]');
  if (cancelButton) {
    try {
      await window.xiaov2b.cancelOrder(cancelButton.dataset.cancel);
      if (checkoutState?.tradeNo === cancelButton.dataset.cancel) {
        stopCheckoutPolling();
        checkoutState = null;
        pendingPurchase = null;
      }
      dashboard = await window.xiaov2b.refresh();
      render(dashboard);
      toast('订单已取消');
    } catch (error) {
      toast(error.message, 'error');
    }
  }
});

document.addEventListener('change', async (event) => {
  const startupToggle = event.target.closest('#startupToggle');
  if (!startupToggle) return;

  startupToggle.disabled = true;
  const nextEnabled = startupToggle.checked;
  try {
    const result = await window.xiaov2b.setStartup(nextEnabled);
    startupEnabled = Boolean(result.enabled);
    renderSettingsControls();
    toast(startupEnabled ? '已开启开机启动' : '已关闭开机启动');
  } catch (error) {
    startupToggle.checked = startupEnabled;
    toast(error.message, 'error');
  } finally {
    const nextToggle = $('#startupToggle');
    if (nextToggle) nextToggle.disabled = false;
  }
});

$('#minBtn').addEventListener('click', () => window.xiaov2b.windowMinimize());
$('#maxBtn').addEventListener('click', () => window.xiaov2b.windowMaximize());
$('#closeBtn').addEventListener('click', () => window.xiaov2b.windowHideToTray());
document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape' && !$('#logoutConfirm').hidden) hideLogoutConfirm();
});

document.addEventListener('error', (event) => {
  const image = event.target;
  if (!(image instanceof HTMLImageElement) || !image.matches('.payment-qr-img[data-fallback]')) return;
  image.src = image.dataset.fallback;
  image.removeAttribute('data-fallback');
}, true);

window.xiaov2b.onCoreStatus((status) => {
  if (connecting && status.running) return;
  setConnected(Boolean(status.running));
  if (dashboard) render(dashboard);
});

window.xiaov2b.onCoreProgress((progress) => {
  if (connecting) setConnectProgress(progress.value);
});

window.xiaov2b.onModeChanged((result) => {
  const previousMode = currentMode;
  currentMode = normalizeMode(result.mode);
  resetTrafficRates();
  renderModeState();
  if (result.pending) {
    pendingAutoSelectionAfterModeApply = currentMode === 'auto' && previousMode !== 'auto';
    return;
  }
  if (result.error) {
    pendingAutoSelectionAfterModeApply = false;
    toast(result.error, 'error');
    return;
  }
  if (currentMode === 'auto' && (previousMode !== 'auto' || pendingAutoSelectionAfterModeApply)) {
    pendingAutoSelectionAfterModeApply = false;
    refreshDashboardForModeSwitch()
      .then(() => selectFastestNode({ silent: true }))
      .catch(() => {});
  } else {
    pendingAutoSelectionAfterModeApply = false;
  }
  toast(result.applied ? `已切换为${modeText(currentMode)}模式` : `已保存${modeText(currentMode)}模式`);
});

renderMode();
requestAnimationFrame(animateDisconnectedHeart);
window.xiaov2b.syncMode(currentMode);
restore();
