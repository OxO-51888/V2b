const http = require('http');
const crypto = require('crypto');
const config = require('./config');
const { base64Url, encryptSecurePayload, ensureKeyPair, randomToken, sha256, signLicense } = require('./crypto-utils');
const { Store } = require('./store');

const keys = ensureKeyPair(config);
const store = new Store(config);
const ORDER_PERIODS = new Set([
  'month_price',
  'quarter_price',
  'half_year_price',
  'year_price',
  'two_year_price',
  'three_year_price',
  'onetime_price',
  'reset_price'
]);
const PANEL_GATEWAY_ALLOWED = new Map([
  ['/passport/auth/login', new Set(['POST'])],
  ['/user/getSubscribe', new Set(['GET'])],
  ['/user/plan/fetch', new Set(['GET'])],
  ['/user/info', new Set(['GET'])],
  ['/user/server/fetch', new Set(['GET'])],
  ['/user/order/fetch', new Set(['GET'])],
  ['/user/comm/config', new Set(['GET'])],
  ['/user/coupon/check', new Set(['POST'])],
  ['/user/order/save', new Set(['POST'])],
  ['/user/order/detail', new Set(['GET'])],
  ['/user/order/getPaymentMethod', new Set(['GET'])],
  ['/user/order/checkout', new Set(['POST'])],
  ['/user/order/check', new Set(['GET'])],
  ['/user/order/cancel', new Set(['POST'])],
  ['/user/ticket/fetch', new Set(['GET'])],
  ['/user/ticket/save', new Set(['POST'])],
  ['/user/ticket/reply', new Set(['POST'])],
  ['/user/ticket/close', new Set(['POST'])],
  ['/user/stat/getTrafficLog', new Set(['GET'])]
]);

function nowMs() {
  return Date.now();
}

function iso(time = nowMs()) {
  return new Date(time).toISOString();
}

function addSeconds(time, seconds) {
  return time + Math.max(60, Number(seconds || 60)) * 1000;
}

function sendJson(res, statusCode, payload) {
  const body = JSON.stringify(payload);
  res.writeHead(statusCode, {
    'Content-Type': 'application/json; charset=utf-8',
    'Access-Control-Allow-Origin': '*',
    'Access-Control-Allow-Methods': 'GET,POST,PUT,PATCH,DELETE,OPTIONS',
    'Access-Control-Allow-Headers': 'Content-Type,Authorization,X-Admin-Token,X-Panel-Authorization,X-Bot-Secret',
    'Cache-Control': 'no-store',
    'X-Frame-Options': 'DENY',
    'X-Content-Type-Options': 'nosniff',
    'Referrer-Policy': 'no-referrer'
  });
  res.end(body);
}

function sendError(res, statusCode, code, message, extra = {}) {
  sendJson(res, statusCode, {
    ok: false,
    authorized: false,
    code,
    message,
    ...extra
  });
}

function sendRaw(res, statusCode, body, headers = {}) {
  res.writeHead(statusCode, {
    'Access-Control-Allow-Origin': '*',
    'Access-Control-Allow-Methods': 'GET,POST,PUT,PATCH,DELETE,OPTIONS',
    'Access-Control-Allow-Headers': 'Content-Type,Authorization,X-Admin-Token,X-Panel-Authorization,X-Bot-Secret',
    'Cache-Control': 'no-store',
    'X-Frame-Options': 'DENY',
    'X-Content-Type-Options': 'nosniff',
    'Referrer-Policy': 'no-referrer',
    ...headers
  });
  res.end(body);
}


function isJsonLikeContentType(contentType) {
  return /\bapplication\/json\b/i.test(contentType || '') || /\+json\b/i.test(contentType || '');
}

function previewText(bytes, limit = 2048) {
  return Buffer.from(bytes || Buffer.alloc(0))
    .subarray(0, limit)
    .toString('utf8')
    .trim();
}

function looksLikeHtml(bytes) {
  const text = previewText(bytes);
  return /^<!doctype\s+html/i.test(text) || /^<html[\s>]/i.test(text) || /<script[\s>]/i.test(text);
}

function looksLikeJson(bytes) {
  const text = previewText(bytes);
  return text.startsWith('{') || text.startsWith('[');
}

function isValidJsonBody(bytes) {
  const text = Buffer.from(bytes || Buffer.alloc(0)).toString('utf8').trim();
  if (!text) return true;
  try {
    JSON.parse(text);
    return true;
  } catch {
    return false;
  }
}

function readJson(req, limit = 1024 * 1024) {
  return new Promise((resolve, reject) => {
    let size = 0;
    const chunks = [];
    req.on('data', (chunk) => {
      size += chunk.length;
      if (size > limit) {
        reject(new Error('请求内容过大'));
        req.destroy();
        return;
      }
      chunks.push(chunk);
    });
    req.on('end', () => {
      const raw = Buffer.concat(chunks).toString('utf8').trim();
      if (!raw) {
        resolve({});
        return;
      }
      try {
        resolve(JSON.parse(raw));
      } catch {
        reject(new Error('请求格式不是有效 JSON'));
      }
    });
    req.on('error', reject);
  });
}

function readRaw(req, limit = 4 * 1024 * 1024) {
  return new Promise((resolve, reject) => {
    let size = 0;
    const chunks = [];
    req.on('data', (chunk) => {
      size += chunk.length;
      if (size > limit) {
        reject(new Error('请求内容过大'));
        req.destroy();
        return;
      }
      chunks.push(chunk);
    });
    req.on('end', () => resolve(Buffer.concat(chunks)));
    req.on('error', reject);
  });
}

function bearerToken(req) {
  const auth = String(req.headers.authorization || '');
  const match = auth.match(/^Bearer\s+(.+)$/i);
  return match ? match[1].trim() : '';
}

function cleanEmail(value) {
  const email = cleanString(value, 160).toLowerCase();
  if (!email) return '';
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return '';
  return email;
}

function sameOriginRequest(req) {
  const host = String(req.headers.host || '').toLowerCase();
  const origin = String(req.headers.origin || '').trim();
  const referer = String(req.headers.referer || '').trim();
  const allowedHosts = new Set([host]);

  for (const value of [config.publicBaseUrl, `https://${host}`, `http://${host}`]) {
    try {
      const parsed = new URL(value);
      if (parsed.host) allowedHosts.add(parsed.host.toLowerCase());
    } catch {}
  }

  for (const value of [origin, referer]) {
    if (!value) continue;
    try {
      const parsed = new URL(value);
      if (!allowedHosts.has(parsed.host.toLowerCase())) return false;
    } catch {
      return false;
    }
  }
  return true;
}

function secretsMatch(value, expected) {
  const left = Buffer.from(String(value || ''));
  const right = Buffer.from(String(expected || ''));
  if (!left.length || left.length !== right.length) return false;
  return crypto.timingSafeEqual(left, right);
}

function requireAdmin(req, res) {
  if (!config.adminToken) {
    sendError(res, 503, 'ADMIN_NOT_CONFIGURED', '授权服务器管理密钥未配置');
    return false;
  }
  if (!sameOriginRequest(req)) {
    sendError(res, 403, 'ADMIN_ORIGIN_DENIED', '管理请求来源不允许');
    return false;
  }
  const token = String(req.headers['x-admin-token'] || bearerToken(req) || '');
  if (!secretsMatch(token, config.adminToken)) {
    sendError(res, 401, 'ADMIN_UNAUTHORIZED', '管理权限校验失败');
    return false;
  }
  return true;
}


function compareVersion(a, b) {
  const left = String(a || '').split(/[.\-_]/).map((item) => Number.parseInt(item, 10) || 0);
  const right = String(b || '').split(/[.\-_]/).map((item) => Number.parseInt(item, 10) || 0);
  const length = Math.max(left.length, right.length);
  for (let index = 0; index < length; index += 1) {
    const delta = (left[index] || 0) - (right[index] || 0);
    if (delta !== 0) return delta;
  }
  return 0;
}

function cleanString(value, maxLength = 160) {
  return String(value || '').trim().slice(0, maxLength);
}

function cleanPositiveInteger(value, label) {
  const number = Number(value);
  if (!Number.isInteger(number) || number <= 0 || number > 2147483647) {
    throw new Error(`${label}无效`);
  }
  return number;
}

const BOT_VERIFY_TOKEN_PATTERN = /^[A-Za-z0-9]{8,128}$/;










function cleanPaymentMethod(value) {
  const number = Number(value);
  if (!Number.isInteger(number) || number < 0 || number > 2147483647) {
    throw new Error('支付方式无效');
  }
  return number;
}

function cleanTradeNo(value) {
  const tradeNo = cleanString(value, 100);
  if (!/^[A-Za-z0-9_-]{6,100}$/.test(tradeNo)) {
    throw new Error('订单号无效');
  }
  return tradeNo;
}

function cleanCouponCode(value, required = false) {
  const code = cleanString(value, 80);
  if (!code) {
    if (required) throw new Error('优惠码无效');
    return '';
  }
  if (/[\x00-\x1f\x7f]/.test(code)) {
    throw new Error('优惠码无效');
  }
  return code;
}

function cleanTicketText(value, label, maxLength) {
  const text = String(value || '').trim();
  if (!text) throw new Error(`${label}不能为空`);
  if (text.length > maxLength) throw new Error(`${label}过长`);
  if (/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/.test(text)) {
    throw new Error(`${label}包含无效字符`);
  }
  return text;
}

function cleanTicketLevel(value) {
  const number = Number(value);
  if (![0, 1, 2].includes(number)) throw new Error('工单等级无效');
  return number;
}

function cleanTicketId(value) {
  return cleanPositiveInteger(value, '工单');
}

function cleanOrderPeriod(value) {
  const period = cleanString(value, 40);
  if (!ORDER_PERIODS.has(period)) {
    throw new Error('套餐周期无效');
  }
  return period;
}

function panelGatewayEndpoint(path) {
  const prefix = '/api/gateway/panel';
  const endpoint = String(path || '').slice(prefix.length) || '/';
  return endpoint.startsWith('/') ? endpoint : `/${endpoint}`;
}

function buildPanelApiUrl(panel, endpoint, search = '') {
  const panelOrigin = normalizePanelUrl(panel?.panelUrl || config.panelUrl);
  if (!panelOrigin) return '';
  const safeEndpoint = String(endpoint || '/').startsWith('/') ? endpoint : `/${endpoint}`;
  return new URL(`/api/v1${safeEndpoint}${search || ''}`, panelOrigin).toString();
}

function cleanGatewaySearch(endpoint, search = '') {
  if (!search) return '';
  const source = new URLSearchParams(search);
  const output = new URLSearchParams();
  if (endpoint === '/user/order/detail' || endpoint === '/user/order/check') {
    output.set('trade_no', cleanTradeNo(source.get('trade_no')));
  }
  if (endpoint === '/user/ticket/fetch') {
    const id = source.get('id');
    if (id) output.set('id', cleanTicketId(id));
  }
  const value = output.toString();
  return value ? `?${value}` : '';
}

function isPanelGatewayAllowed(method, endpoint) {
  const allowedMethods = PANEL_GATEWAY_ALLOWED.get(endpoint);
  return Boolean(allowedMethods && allowedMethods.has(method));
}

function parseGatewayJsonBody(rawBody) {
  const text = Buffer.isBuffer(rawBody) ? rawBody.toString('utf8').trim() : '';
  if (!text) return {};
  try {
    return JSON.parse(text);
  } catch {
    throw new Error('请求格式不是有效 JSON');
  }
}

function jsonBuffer(value) {
  return Buffer.from(JSON.stringify(value));
}

function sanitizePanelGatewayBody(endpoint, rawBody) {
  const body = parseGatewayJsonBody(rawBody);
  if (endpoint === '/passport/auth/login') {
    const email = cleanEmail(body.email);
    const password = cleanString(body.password, 512);
    if (!email || !password) throw new Error('登录参数无效');
    return jsonBuffer({ email, password });
  }
  if (endpoint === '/user/coupon/check') {
    return jsonBuffer({
      code: cleanCouponCode(body.code, true),
      plan_id: cleanPositiveInteger(body.plan_id, '套餐')
    });
  }
  if (endpoint === '/user/order/save') {
    const next = {
      plan_id: cleanPositiveInteger(body.plan_id, '套餐'),
      period: cleanOrderPeriod(body.period)
    };
    const couponCode = cleanCouponCode(body.coupon_code);
    if (couponCode) next.coupon_code = couponCode;
    return jsonBuffer(next);
  }
  if (endpoint === '/user/order/checkout') {
    return jsonBuffer({
      trade_no: cleanTradeNo(body.trade_no),
      method: cleanPaymentMethod(body.method),
      token: ''
    });
  }
  if (endpoint === '/user/order/cancel') {
    return jsonBuffer({ trade_no: cleanTradeNo(body.trade_no) });
  }
  if (endpoint === '/user/ticket/save') {
    return jsonBuffer({
      subject: cleanTicketText(body.subject, '工单标题', 120),
      level: cleanTicketLevel(body.level),
      message: cleanTicketText(body.message, '工单内容', 2000)
    });
  }
  if (endpoint === '/user/ticket/reply') {
    return jsonBuffer({
      id: cleanTicketId(body.id),
      message: cleanTicketText(body.message, '回复内容', 2000)
    });
  }
  if (endpoint === '/user/ticket/close') {
    return jsonBuffer({ id: cleanTicketId(body.id) });
  }
  return rawBody;
}

async function validateGatewayPlanPayload(panel, authorization, payload) {
  if (!payload?.plan_id) throw new Error('套餐无效');
  const planUrl = buildPanelApiUrl(panel, '/user/plan/fetch');
  if (!planUrl) throw new Error('网站地址未配置');
  const headers = {
    Accept: 'application/json, text/plain, */*',
    'User-Agent': `AppGateAuthGateway/${panel.apiSign.clientVersion || config.panelClientVersion}`,
    ...panelSignatureHeaders('GET', planUrl, panel)
  };
  if (authorization) headers.Authorization = authorization;
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), config.panelRequestTimeoutMs);
  let response;
  try {
    response = await fetch(planUrl, {
      method: 'GET',
      headers,
      redirect: 'follow',
      signal: controller.signal
    });
  } catch {
    throw new Error('套餐校验失败');
  } finally {
    clearTimeout(timer);
  }
  if (!response.ok) throw new Error('套餐校验失败');
  let data;
  try {
    data = JSON.parse(Buffer.from(await response.arrayBuffer()).toString('utf8') || '{}');
  } catch {
    throw new Error('套餐校验失败');
  }
  const plans = Array.isArray(data?.data) ? data.data : [];
  const plan = plans.find((item) => Number(item?.id) === Number(payload.plan_id));
  if (!plan) throw new Error('套餐无效');
  if (payload.period) {
    if (!Object.prototype.hasOwnProperty.call(plan, payload.period)) throw new Error('套餐周期无效');
    const price = Number(plan[payload.period]);
    if (!Number.isFinite(price) || price < 0) throw new Error('套餐周期无效');
  }
}

function gatewayOrderPaymentId(order) {
  const value = Number(order?.payment_id);
  return Number.isInteger(value) && value > 0 ? value : 0;
}

async function fetchGatewayOrderDetail(panel, authorization, tradeNo) {
  const detailUrl = buildPanelApiUrl(panel, '/user/order/detail', `?trade_no=${encodeURIComponent(cleanTradeNo(tradeNo))}`);
  if (!detailUrl) throw new Error('网站地址未配置');
  const headers = {
    Accept: 'application/json, text/plain, */*',
    'User-Agent': `AppGateAuthGateway/${panel.apiSign.clientVersion || config.panelClientVersion}`,
    ...panelSignatureHeaders('GET', detailUrl, panel)
  };
  if (authorization) headers.Authorization = authorization;
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), config.panelRequestTimeoutMs);
  let response;
  try {
    response = await fetch(detailUrl, {
      method: 'GET',
      headers,
      redirect: 'follow',
      signal: controller.signal
    });
  } catch {
    throw new Error('订单校验失败');
  } finally {
    clearTimeout(timer);
  }
  if (!response.ok) throw new Error('订单校验失败');
  try {
    const data = JSON.parse(Buffer.from(await response.arrayBuffer()).toString('utf8') || '{}');
    return data?.data || {};
  } catch {
    throw new Error('订单校验失败');
  }
}

async function validateGatewayCheckoutPayload(panel, authorization, payload) {
  const order = await fetchGatewayOrderDetail(panel, authorization, payload.trade_no);
  const paymentId = gatewayOrderPaymentId(order);
  if (paymentId && paymentId !== Number(payload.method)) {
    throw new Error('订单已绑定支付方式，请取消后重新下单');
  }
}

function normalizeAuthorizedTarget(value) {
  const raw = cleanString(value, 220)
    .replace(/^https?:\/\//i, '')
    .split(/[/?#]/)[0]
    .toLowerCase();
  if (!raw) return '';

  let host = raw;
  let port = '';
  const lastColon = raw.lastIndexOf(':');
  if (lastColon > 0 && raw.indexOf(':') === lastColon) {
    host = raw.slice(0, lastColon);
    port = raw.slice(lastColon + 1);
  }

  if (!port) port = '443';
  const portNumber = Number.parseInt(port, 10);
  if (!Number.isInteger(portNumber) || portNumber < 1 || portNumber > 65535) return '';
  if (!/^[a-z0-9.-]+$/.test(host) && !/^(\d{1,3}\.){3}\d{1,3}$/.test(host)) return '';
  return `${host}:${portNumber}`;
}

function cleanDeviceId(value) {
  const id = cleanString(value, 160);
  if (!/^[a-zA-Z0-9._:-]{12,160}$/.test(id)) return '';
  return id;
}

function isExpired(expiresAt, time = nowMs()) {
  if (!expiresAt) return false;
  const target = Date.parse(expiresAt);
  return Number.isFinite(target) && target <= time;
}

function isDeviceAllowed(device, time = nowMs()) {
  return Boolean(device);
}

function normalizePanelUrl(value) {
  const raw = cleanString(value, 500).replace(/\/+$/g, '');
  if (!raw) return '';
  const url = new URL(raw);
  if (!/^https?:$/.test(url.protocol)) return '';
  return url.origin;
}

function panelBackendDomain(panelUrl) {
  try {
    return new URL(panelUrl).host.toLowerCase();
  } catch {
    return '';
  }
}

function cleanPanelId(value) {
  const id = cleanString(value, 80);
  return /^[a-zA-Z0-9._-]{1,80}$/.test(id) ? id : '';
}

function listValue(value, fallback = []) {
  if (Array.isArray(value)) return value.map((item) => cleanString(item, 120)).filter(Boolean);
  if (typeof value === 'string') return value.split(',').map((item) => cleanString(item, 120)).filter(Boolean);
  return fallback;
}

function normalizePanelRecord(raw = {}, index = 0) {
  const id = cleanPanelId(raw.id) || `panel_${String(index + 1).padStart(2, '0')}`;
  const panelUrl = normalizePanelUrl(raw.panelUrl || raw.url || raw.baseUrl || config.panelUrl);
  const gatewayTarget = normalizeAuthorizedTarget(raw.gatewayTarget || raw.target || config.gatewayTarget);
  const allowedClientNames = listValue(raw.allowedClientNames || raw.clientNames, []);
  const fallbackStatus = index === 0 ? 'active' : 'disabled';
  const status = cleanString(raw.status || fallbackStatus, 32);
  return {
    id,
    name: cleanString(raw.name || `网站 ${index + 1}`, 120),
    status: status === 'active' ? 'active' : 'disabled',
    panelUrl,
    backendDomain: panelBackendDomain(panelUrl),
    gatewayTarget,
    allowedClientNames,
    apiSign: {
      clientId: cleanString(raw.apiSign?.clientId || raw.panelClientId || config.panelClientId, 64),
      clientVersion: cleanString(raw.apiSign?.clientVersion || raw.panelClientVersion || config.panelClientVersion, 32),
      signSecret: String(raw.apiSign?.signSecret || raw.panelSignSecret || config.panelSignSecret || '')
    },
    database: raw.database && typeof raw.database === 'object' ? {
      host: cleanString(raw.database.host, 120),
      port: Number.parseInt(raw.database.port, 10) || 3306,
      name: cleanString(raw.database.name || raw.database.database, 120),
      user: cleanString(raw.database.user, 120),
      password: String(raw.database.password || ''),
      readonly: raw.database.readonly !== false
    } : null
  };
}

function applyPanelOverride(panel, override = {}) {
  if (!override || typeof override !== 'object') return panel;
  const gatewayTarget = normalizeAuthorizedTarget(override.gatewayTarget || override.target);
  const allowedClientNames = listValue(override.allowedClientNames || override.clientNames || override.clientName, []);
  const status = cleanString(override.status, 32);
  return {
    ...panel,
    name: cleanString(override.name, 120) || panel.name,
    status: status === 'active' ? 'active' : (status === 'disabled' ? 'disabled' : panel.status),
    gatewayTarget: gatewayTarget || panel.gatewayTarget,
    allowedClientNames: allowedClientNames.length ? allowedClientNames : panel.allowedClientNames
  };
}

function panelRegistry(state = null) {
  const raw = config.panelsConfig && typeof config.panelsConfig === 'object' ? config.panelsConfig : {};
  const rawPanels = Array.isArray(raw.panels) ? raw.panels : [];
  const overrides = state?.panelOverrides && typeof state.panelOverrides === 'object' ? state.panelOverrides : {};
  const panels = rawPanels
    .map(normalizePanelRecord)
    .map((panel) => applyPanelOverride(panel, overrides[panel.id]))
    .filter((panel) => panel.panelUrl);

  let activePanels = panels.filter((panel) => panel.status === 'active');

  if (!activePanels.length && config.panelUrl && !config.sitePanelId) {
    panels.push(normalizePanelRecord({
      id: 'default',
      name: '默认网站',
      panelUrl: config.panelUrl,
      gatewayTarget: config.gatewayTarget,
      allowedClientNames: config.allowedClientNames
    }, 0));
    activePanels = panels.filter((panel) => panel.status === 'active');
  }

  const byId = {};
  const byAllId = {};
  const byClientName = {};
  const duplicateClientNames = new Set();
  for (const panel of panels) {
    byAllId[panel.id] = panel;
  }
  for (const panel of activePanels) {
    byId[panel.id] = panel;
    for (const name of panel.allowedClientNames || []) {
      const key = cleanString(name, 120).toLowerCase();
      if (!key) continue;
      if (byClientName[key] && byClientName[key].id !== panel.id) duplicateClientNames.add(key);
      byClientName[key] = panel;
    }
  }
  const defaultPanelId = cleanPanelId(raw.defaultPanelId) || activePanels[0]?.id || '';
  return {
    defaultPanelId,
    panels,
    activePanels,
    byId,
    byAllId,
    byClientName,
    duplicateClientNames
  };
}

function getDefaultPanel(state = null) {
  const registry = panelRegistry(state);
  return registry.byId[registry.defaultPanelId] || registry.panels[0] || null;
}

function getPanelById(panelId, state = null) {
  const id = cleanPanelId(panelId);
  if (!id) return null;
  return panelRegistry(state).byId[id] || null;
}

function getAnyPanelById(panelId, state = null) {
  const id = cleanPanelId(panelId);
  if (!id) return null;
  return panelRegistry(state).byAllId[id] || null;
}

function resolvePanel(panelId, state = null) {
  return getPanelById(panelId, state) || getDefaultPanel(state);
}

function resolveActiveSessionPanel(session, device, state = null) {
  const panel = getPanelById(session?.panelId || device?.panelId || '', state);
  if (!panel) return null;
  const nameCheck = validateClientName(panel, device?.clientName || '', device?.runtimeName || '');
  return nameCheck.ok ? panel : null;
}

function resolvePanelForClient({ panelId = '', clientName = '', runtimeName = '' } = {}, state = null) {
  if (panelId !== config.sitePanelId) return null;
  const registry = panelRegistry(state);
  const explicitPanel = getPanelById(panelId, state);
  if (explicitPanel) return explicitPanel;

  const names = [clientName, runtimeName]
    .map((name) => cleanString(name, 120).toLowerCase())
    .filter(Boolean);
  for (const name of names) {
    if (registry.duplicateClientNames.has(name)) continue;
    if (registry.byClientName[name]) return registry.byClientName[name];
  }

  if (registry.activePanels.length === 1) return registry.activePanels[0];
  return null;
}

function panelPublic(panel) {
  return {
    id: panel.id,
    name: panel.name,
    status: panel.status,
    backendDomain: panel.backendDomain,
    gatewayTarget: panel.gatewayTarget,
    allowedClientNames: panel.allowedClientNames,
    clientName: panel.allowedClientNames?.[0] || '',
    hasPanelUrl: Boolean(panel.panelUrl),
    hasDatabase: Boolean(panel.database?.name)
  };
}

function panelSignatureHeaders(method, url, panel = null) {
  const target = new URL(url);
  const sign = panel?.apiSign || {};
  const clientId = sign.clientId || config.panelClientId;
  const clientVersion = sign.clientVersion || config.panelClientVersion;
  const signSecret = sign.signSecret || config.panelSignSecret;
  const timestamp = Math.floor(Date.now() / 1000).toString();
  const nonce = crypto.randomBytes(16).toString('hex');
  const payload = [
    String(method || 'GET').toUpperCase(),
    target.pathname,
    timestamp,
    nonce,
    clientId,
    clientVersion
  ].join('\n');
  const signature = crypto.createHmac('sha256', signSecret).update(payload).digest('hex');
  return {
    'X-Xiao-Client': clientId,
    'X-Xiao-Version': clientVersion,
    'X-Xiao-Timestamp': timestamp,
    'X-Xiao-Nonce': nonce,
    'X-Xiao-Sign': signature
  };
}

async function getAuthorizedSession(req) {
  const token = bearerToken(req);
  if (!token) return { ok: false, status: 401, code: 'SESSION_REQUIRED', message: '授权会话不存在' };
  const time = nowMs();
  const tokenHash = sha256(token);
  await store.pruneExpiredSessions(time);
  const session = await store.getSession(tokenHash);
  if (!session || isExpired(session.expiresAt, time)) {
    if (session) await store.deleteSession(tokenHash);
    return { ok: false, status: 401, code: 'SESSION_EXPIRED', message: '授权会话已过期，请重新启动客户端' };
  }
  const device = await store.getDevice(session.deviceId);
  if (!isDeviceAllowed(device, time)) {
    await store.deleteSession(tokenHash);
    return { ok: false, status: 403, code: 'DEVICE_NOT_ALLOWED', message: '当前设备授权已失效' };
  }
  const state = await store.readPanelState();
  const panel = resolveActiveSessionPanel(session, device, state);
  if (!panel) {
    await store.deleteSession(tokenHash);
    return { ok: false, status: 403, code: 'PANEL_DISABLED', message: '未授权' };
  }
  return { ok: true, session, device, state, panel };
}

function buildPanelGatewayUrl(requestUrl, path, panel) {
  const safeEndpoint = panelGatewayEndpoint(path);
  return buildPanelApiUrl(panel, safeEndpoint, cleanGatewaySearch(safeEndpoint, requestUrl.search));
}

function createSignedLicense(device, session, time) {
  const license = {
    issuer: config.issuer,
    product: device.clientId,
    clientId: device.clientId,
    clientName: device.clientName || '',
    runtimeName: device.runtimeName || '',
    version: device.version,
    deviceId: device.deviceId,
    panelId: session.panelId || device.panelId || '',
    issuedAt: iso(time),
    expiresAt: session.expiresAt,
    policy: {
      allowRun: true,
      allowCore: true,
      allowTray: true
    }
  };
  return {
    license,
    signature: signLicense(keys.privateKey, license)
  };
}

function createSignedInstallerGrant(payload, time) {
  const expiresAt = iso(addSeconds(time, 600));
  const grant = {
    issuer: config.issuer,
    requestType: 'installer',
    product: payload.clientId,
    clientId: payload.clientId,
    clientName: payload.clientName || '',
    runtimeName: payload.runtimeName || '',
    version: payload.version,
    panelId: payload.panelId || '',
    installerId: payload.installerId || '',
    issuedAt: iso(time),
    expiresAt,
    policy: {
      allowInstall: true
    }
  };
  return {
    grant,
    signature: signLicense(keys.privateKey, grant)
  };
}

function namesMatch(value, allowed) {
  const name = cleanString(value, 120).toLowerCase();
  if (!name) return false;
  return allowed.map((item) => cleanString(item, 120).toLowerCase()).includes(name);
}

function validateClientName(panel, clientName, runtimeName) {
  if (!config.strictClientName) return { ok: true };
  const allowed = panel?.allowedClientNames || [];
  if (!allowed.length) return { ok: false, code: 'CLIENT_NAME_NOT_CONFIGURED', message: '客户端名称未配置' };
  if (!namesMatch(clientName, allowed)) {
    return { ok: false, code: 'CLIENT_NAME_NOT_ALLOWED', message: '客户端名称未授权' };
  }
  if (!namesMatch(runtimeName, allowed)) {
    return { ok: false, code: 'CLIENT_RUNTIME_NAME_NOT_ALLOWED', message: '客户端运行名称未授权' };
  }
  return { ok: true };
}

async function buildInstallerVerifyResult(req, providedBody = null) {
  const body = providedBody || await readJson(req);
  const time = nowMs();
  const clientId = cleanString(body.clientId, 64);
  const version = cleanString(body.version, 32);
  const clientName = cleanString(body.clientName || body.name, 120);
  const runtimeName = cleanString(body.runtimeName || body.appName || body.productName || clientName, 120);
  const installerId = cleanString(body.installerId, 160);
  const appHash = cleanString(body.appHash, 160);
  let resolvedTarget = '';
  let resolvedPanelId = '';

  const auditBase = {
    requestType: 'installer',
    installerId,
    clientId,
    version,
    resolvedTarget,
    resolvedPanelId
  };

  if (!version || compareVersion(version, config.minVersion) < 0) {
    return {
      output: { status: 426, code: 'CLIENT_VERSION_TOO_OLD', message: '未授权' },
      audit: auditBase
    };
  }

  let output;
  await store.withTransaction(async (conn) => {
    const state = await store.readPanelState(conn);
    const explicitPanel = getAnyPanelById(clientId, state);
    if (explicitPanel && explicitPanel.status !== 'active') {
      resolvedPanelId = explicitPanel.id;
      resolvedTarget = explicitPanel.gatewayTarget || '';
      output = { status: 403, code: 'PANEL_DISABLED', message: '\u672a\u6388\u6743' };
      return;
    }
    const panel = resolvePanelForClient({ panelId: clientId, clientName, runtimeName }, state);
    if (!panel) {
      output = { status: 503, code: 'PANEL_NOT_CONFIGURED', message: '未授权' };
      return;
    }
    resolvedPanelId = panel.id;
    resolvedTarget = panel.gatewayTarget || '';

    const nameCheck = validateClientName(panel, clientName, runtimeName);
    if (!nameCheck.ok) {
      output = { status: 403, code: nameCheck.code, message: '未授权' };
      return;
    }

    const signed = createSignedInstallerGrant({
      clientId,
      clientName,
      runtimeName,
      version,
      panelId: resolvedPanelId,
      installerId
    }, time);
    output = {
      status: 200,
      body: {
        ok: true,
        authorized: true,
        data: {
          requestType: 'installer',
          panelId: resolvedPanelId,
          expiresAt: signed.grant.expiresAt,
          ...signed
        }
      }
    };
  });

  return {
    output,
    audit: {
      ...auditBase,
      appHash,
      resolvedTarget,
      resolvedPanelId
    }
  };
}

async function buildClientVerifyResult(req, providedBody = null) {
  const body = providedBody || await readJson(req);
  const time = nowMs();
  const clientId = cleanString(body.clientId, 64);
  const version = cleanString(body.version, 32);
  const deviceId = cleanDeviceId(body.deviceId);
  const clientName = cleanString(body.clientName || body.name, 120);
  const runtimeName = cleanString(body.runtimeName || body.appName || body.productName || clientName, 120);
  const appHash = cleanString(body.appHash, 160);
  const userEmail = cleanEmail(body.userEmail || body.email);
  let resolvedTarget = '';
  let resolvedPanelId = '';

  if (!version || compareVersion(version, config.minVersion) < 0) {
    return {
      output: { status: 426, code: 'CLIENT_VERSION_TOO_OLD', message: '\u5ba2\u6237\u7aef\u7248\u672c\u8fc7\u4f4e\uff0c\u8bf7\u5347\u7ea7\u540e\u518d\u4f7f\u7528' },
      audit: { deviceId, clientId, version, userEmail, resolvedTarget, resolvedPanelId }
    };
  }
  if (!deviceId) {
    return {
      output: { status: 400, code: 'DEVICE_INVALID', message: '\u8bbe\u5907\u6307\u7eb9\u65e0\u6548' },
      audit: { deviceId, clientId, version, userEmail, resolvedTarget, resolvedPanelId }
    };
  }

  let output;
  await store.withTransaction(async (conn) => {
    await store.pruneExpiredSessions(time, conn);
    const existed = await store.getDevice(deviceId, conn);
    const device = existed || {
      deviceId,
      status: 'approved',
      firstSeenAt: iso(time)
    };

    device.clientId = clientId;
    device.version = version;
    device.clientName = clientName;
    device.runtimeName = runtimeName;
    delete device.deviceName;
    delete device.os;
    device.appHash = appHash;
    if (userEmail) device.userEmail = userEmail;
    device.lastSeenAt = iso(time);

    device.status = 'approved';

    const state = await store.readPanelState(conn);
    const explicitPanel = getAnyPanelById(clientId, state);
    if (explicitPanel && explicitPanel.status !== 'active') {
      await store.saveDevice(device, conn);
      resolvedPanelId = explicitPanel.id;
      resolvedTarget = explicitPanel.gatewayTarget || '';
      output = { status: 403, code: 'PANEL_DISABLED', message: '\u672a\u6388\u6743' };
      return;
    }
    const panel = resolvePanelForClient({ panelId: clientId, clientName, runtimeName }, state);
    if (!panel) {
      await store.saveDevice(device, conn);
      output = { status: 503, code: 'PANEL_NOT_CONFIGURED', message: '\u6388\u6743\u7f51\u7ad9\u672a\u914d\u7f6e' };
      return;
    }
    device.panelId = panel.id;
    resolvedPanelId = panel.id;
    resolvedTarget = panel.gatewayTarget || '';

    const nameCheck = validateClientName(panel, clientName, runtimeName);
    if (!nameCheck.ok) {
      await store.saveDevice(device, conn);
      output = { status: 403, code: nameCheck.code, message: nameCheck.message };
      return;
    }

    const sessionToken = randomToken(32);
    const session = {
      tokenHash: sha256(sessionToken),
      deviceId,
      clientId,
      panelId: resolvedPanelId,
      issuedAt: iso(time),
      expiresAt: iso(addSeconds(time, config.sessionTtlSeconds))
    };
    await store.saveDevice(device, conn);
    await store.saveSession(session, conn);

    const signed = createSignedLicense(device, session, time);
    output = {
      status: 200,
      body: {
        ok: true,
        authorized: true,
        data: {
          sessionToken,
          expiresAt: session.expiresAt,
          heartbeatAfter: config.heartbeatAfterSeconds,
          panelId: resolvedPanelId,
          gateway: {
            panelBaseUrl: config.publicPath + '/api/gateway/panel'
          },
          ...signed
        }
      }
    };
  });

  return {
    output,
    audit: {
      deviceId,
      clientId,
      version,
      userEmail,
      resolvedTarget,
      resolvedPanelId
    }
  };
}

async function handleClientVerify(req, res) {
  const body = await readJson(req);
  if (!body || body.clientId !== config.sitePanelId) {
    sendError(res, 403, 'SITE_MISMATCH', '客户端不属于本站');
    return;
  }
  const clientPublicKey = cleanString(body?.secure?.publicKey || body?.publicKey, 4096);
  const wantsSecure = Boolean(clientPublicKey);
  const requestType = cleanString(body.requestType || body.type, 32) === 'installer' ? 'installer' : 'runtime';
  if (!wantsSecure) {
    await store.audit('client.verify.denied', {
      code: 'SECURE_KEY_REQUIRED',
      requestType,
      clientId: cleanString(body.clientId, 64),
      version: cleanString(body.version, 32),
      userEmail: cleanEmail(body.userEmail || body.email)
    });
    sendError(res, 400, 'SECURE_KEY_REQUIRED', '\u672a\u6388\u6743');
    return;
  }

  const { output, audit } = requestType === 'installer'
    ? await buildInstallerVerifyResult(req, body)
    : await buildClientVerifyResult(req, body);

  if (output?.status !== 200) {
    await store.audit(requestType === 'installer' ? 'client.verify.installer.secure.denied' : 'client.verify.secure.denied', {
      code: output.code,
      ...audit
    });
    sendError(res, output.status || 403, 'AUTH_DENIED', '\u672a\u6388\u6743');
    return;
  }

  try {
    const envelope = encryptSecurePayload(clientPublicKey, output.body, keys.privateKey);
    await store.audit(requestType === 'installer' ? 'client.verify.installer.secure.allowed' : 'client.verify.secure.allowed', audit);
    sendJson(res, 200, envelope);
  } catch (error) {
    await store.audit(requestType === 'installer' ? 'client.verify.installer.secure.failed' : 'client.verify.secure.failed', {
      code: 'SECURE_ENCRYPT_FAILED',
      ...audit
    });
    sendError(res, 400, 'SECURE_ENCRYPT_FAILED', '\u672a\u6388\u6743');
  }
}

async function handleClientHeartbeat(req, res) {
  const body = await readJson(req);
  const token = bearerToken(req) || cleanString(body.sessionToken, 200);
  const clientName = cleanString(body.clientName || body.name, 120);
  const runtimeName = cleanString(body.runtimeName || body.appName || body.productName || clientName, 120);
  const appHash = cleanString(body.appHash, 160);
  const userEmail = cleanEmail(body.userEmail || body.email);
  if (!token) {
    sendError(res, 401, 'SESSION_REQUIRED', '\u6388\u6743\u4f1a\u8bdd\u4e0d\u5b58\u5728');
    return;
  }

  const time = nowMs();
  const tokenHash = sha256(token);
  let output;
  await store.withTransaction(async (conn) => {
    await store.pruneExpiredSessions(time, conn);
    const session = await store.getSession(tokenHash, conn);
    if (!session) {
      output = { status: 401, code: 'SESSION_EXPIRED', message: '\u6388\u6743\u4f1a\u8bdd\u5df2\u8fc7\u671f\uff0c\u8bf7\u91cd\u65b0\u542f\u52a8\u5ba2\u6237\u7aef' };
      return;
    }
    const device = await store.getDevice(session.deviceId, conn);
    if (!isDeviceAllowed(device, time)) {
      await store.deleteSession(tokenHash, conn);
      output = { status: 403, code: 'DEVICE_NOT_ALLOWED', message: '\u5f53\u524d\u8bbe\u5907\u6388\u6743\u5df2\u5931\u6548' };
      return;
    }
    const state = await store.readPanelState(conn);
    const panel = resolveActiveSessionPanel(session, device, state);
    if (!panel) {
      await store.deleteSession(tokenHash, conn);
      output = { status: 403, code: 'PANEL_DISABLED', message: '\u672a\u6388\u6743' };
      return;
    }
    device.clientName = clientName || device.clientName || '';
    device.runtimeName = runtimeName || device.runtimeName || '';
    device.appHash = appHash || device.appHash || '';
    if (userEmail) device.userEmail = userEmail;
    session.expiresAt = iso(addSeconds(time, config.sessionTtlSeconds));
    device.lastSeenAt = iso(time);
    await store.saveDevice(device, conn);
    await store.saveSession(session, conn);
    output = {
      status: 200,
      body: {
        ok: true,
        authorized: true,
        data: {
          expiresAt: session.expiresAt,
          serverTime: iso(time),
          heartbeatAfter: config.heartbeatAfterSeconds
        }
      }
    };
  });

  if (output?.status !== 200) {
    sendError(res, output.status, output.code, output.message);
    return;
  }
  sendJson(res, 200, output.body);
}

async function handleClientDeactivate(req, res) {
  const body = await readJson(req);
  const token = bearerToken(req) || cleanString(body.sessionToken, 200);
  if (token) {
    await store.deleteSession(sha256(token));
  }
  sendJson(res, 200, { ok: true, message: '\u5df2\u9000\u51fa\u6388\u6743\u4f1a\u8bdd' });
}


async function handlePanelGateway(req, res, requestUrl, path) {
  const method = String(req.method || 'GET').toUpperCase();
  const endpoint = panelGatewayEndpoint(path);
  if (!isPanelGatewayAllowed(method, endpoint)) {
    sendError(res, 403, 'PANEL_GATEWAY_ENDPOINT_DENIED', '当前接口不允许通过客户端访问');
    return;
  }

  const auth = await getAuthorizedSession(req);
  if (!auth.ok) {
    sendError(res, auth.status, auth.code, auth.message);
    return;
  }

  const panel = auth.panel;
  if (!panel) {
    sendError(res, 403, 'PANEL_DISABLED', '未授权');
    return;
  }

  let targetUrl = '';
  try {
    targetUrl = buildPanelGatewayUrl(requestUrl, path, panel);
  } catch {
    sendError(res, 400, 'PANEL_GATEWAY_QUERY_INVALID', '请求参数无效');
    return;
  }
  if (!targetUrl) {
    sendError(res, 503, 'PANEL_GATEWAY_NOT_CONFIGURED', '授权网关未配置网站地址');
    return;
  }

  const headers = {
    Accept: String(req.headers.accept || 'application/json, text/plain, */*'),
    'User-Agent': `AppGateAuthGateway/${panel.apiSign.clientVersion || config.panelClientVersion}`,
    ...panelSignatureHeaders(method, targetUrl, panel)
  };
  const panelAuthorization = cleanString(req.headers['x-panel-authorization'], 500);
  if (panelAuthorization) headers.Authorization = panelAuthorization;

  let body;
  let sanitizedPayload = null;
  try {
    const rawBody = ['GET', 'HEAD'].includes(method) ? undefined : await readRaw(req);
    body = rawBody ? sanitizePanelGatewayBody(endpoint, rawBody) : undefined;
    if (body && ['/user/order/save', '/user/coupon/check', '/user/order/checkout'].includes(endpoint)) {
      sanitizedPayload = parseGatewayJsonBody(body);
    }
  } catch (error) {
    sendError(res, 400, 'PANEL_GATEWAY_BODY_INVALID', error.message || '请求参数无效');
    return;
  }
  if (sanitizedPayload) {
    try {
      if (endpoint === '/user/order/checkout') {
        await validateGatewayCheckoutPayload(panel, panelAuthorization, sanitizedPayload);
      } else {
        await validateGatewayPlanPayload(panel, panelAuthorization, sanitizedPayload);
      }
    } catch (error) {
      sendError(res, 400, endpoint === '/user/order/checkout' ? 'PANEL_GATEWAY_ORDER_INVALID' : 'PANEL_GATEWAY_PLAN_INVALID', error.message || '请求参数无效');
      return;
    }
  }
  if (body && body.length) headers['Content-Type'] = 'application/json';
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), config.panelRequestTimeoutMs);
  let upstream;
  try {
    upstream = await fetch(targetUrl, {
      method,
      headers,
      body: body && body.length ? body : undefined,
      redirect: 'follow',
      signal: controller.signal
    });
  } catch (error) {
    const message = error?.name === 'AbortError'
      ? '网站网关请求超时'
      : '网站网关请求失败';
    sendError(res, 502, 'PANEL_GATEWAY_FAILED', message);
    return;
  } finally {
    clearTimeout(timer);
  }

  const contentTypeOut = upstream.headers.get('content-type') || 'application/json; charset=utf-8';
  const bytes = Buffer.from(await upstream.arrayBuffer());
  const contentTypeIsJson = isJsonLikeContentType(contentTypeOut);
  if (looksLikeHtml(bytes) || !isValidJsonBody(bytes) || (!contentTypeIsJson && !looksLikeJson(bytes))) {
    sendError(res, 502, 'PANEL_GATEWAY_INVALID_RESPONSE', '网站网关返回异常，请稍后重试');
    return;
  }
  sendRaw(res, upstream.status, bytes, {
    'Content-Type': contentTypeIsJson ? contentTypeOut : 'application/json; charset=utf-8'
  });
}

async function handleAdminStats(req, res) {
  if (!requireAdmin(req, res)) return;
  const time = nowMs();
  const onlineDeviceIds = await store.getOnlineDeviceIds(time);
  sendJson(res, 200, {
    ok: true,
    data: {
      onlineDevices: onlineDeviceIds.size
    }
  });
}

async function handleListPanels(req, res) {
  if (!requireAdmin(req, res)) return;
  const state = await store.readPanelState();
  const registry = panelRegistry(state);
  sendJson(res, 200, {
    ok: true,
    data: {
      defaultPanelId: registry.defaultPanelId,
      panels: registry.panels.map(panel => ({ ...panelPublic(panel), revision: panelRevision(panel) }))
    }
  });
}

function panelRevision(panel) {
  return sha256(JSON.stringify(panelPublic(panel)));
}

async function handleUpdatePanel(req, res) {
  if (!requireAdmin(req, res)) return;
  const body = await readJson(req);
  if (Object.keys(body).some(k => !['panelId', 'name', 'status', 'gatewayTarget', 'clientName', 'revision'].includes(k)) || body.panelId !== config.sitePanelId) {
    sendError(res, 422, 'INVALID_SCOPE', '仅允许修改本站新网关');
    return;
  }
  const panelId = cleanPanelId(body.panelId || body.id);
  if (!panelId) {
    sendError(res, 400, 'PANEL_INVALID', '\u7f51\u7ad9 ID \u65e0\u6548');
    return;
  }
  const name = cleanString(body.name, 120);
  const status = cleanString(body.status, 32);
  let gatewayTarget = normalizeAuthorizedTarget(body.gatewayTarget || body.target);
  const clientName = cleanString(body.clientName || body.allowedClientName, 120);
  if (!name) {
    sendError(res, 400, 'PANEL_NAME_REQUIRED', '\u7f51\u7ad9\u540d\u5b57\u4e0d\u80fd\u4e3a\u7a7a');
    return;
  }
  if (!clientName) {
    sendError(res, 400, 'PANEL_CLIENT_NAME_REQUIRED', '\u5ba2\u6237\u7aef\u540d\u4e0d\u80fd\u4e3a\u7a7a');
    return;
  }
  if (!['active', 'disabled'].includes(status)) {
    sendError(res, 400, 'PANEL_STATUS_INVALID', '\u7f51\u7ad9\u542f\u7528\u72b6\u6001\u65e0\u6548');
    return;
  }

  let panel;
  let output;
  await store.withTransaction(async (conn) => {
    const state = await store.readPanelState(conn);
    const existingPanel = getAnyPanelById(panelId, state);
    if (!existingPanel) {
      output = { status: 404, code: 'PANEL_NOT_FOUND', message: '\u7f51\u7ad9\u4e0d\u5b58\u5728' };
      return;
    }
    if (!secretsMatch(body.revision, panelRevision(existingPanel))) {
      output = { status: 409, code: 'STALE_CONFIG', message: '设置已被修改，请刷新后重试' };
      return;
    }
    if (gatewayTarget && gatewayTarget !== existingPanel.gatewayTarget) {
      output = { status: 422, code: 'TARGET_PINNED', message: '连接目标由本站部署配置固定' };
      return;
    }
    if (!gatewayTarget) gatewayTarget = existingPanel.gatewayTarget || '';
    if (name === existingPanel.name && status === existingPanel.status && clientName === existingPanel.allowedClientNames[0]) {
      panel = existingPanel;
      return;
    }
    const override = {
      ...(state.panelOverrides[panelId] || {}),
      name,
      status,
      gatewayTarget,
      allowedClientNames: [clientName],
      updatedAt: iso()
    };
    await store.savePanelOverride(panelId, override, conn);
    const nextState = {
      ...state,
      panelOverrides: {
        ...state.panelOverrides,
        [panelId]: override
      }
    };
    panel = getAnyPanelById(panelId, nextState);
    await store.audit('admin.panel.update', { panelId, name, status, gatewayTarget, clientName }, conn);
  });
  if (output) {
    sendError(res, output.status, output.code, output.message);
    return;
  }
  sendJson(res, 200, { ok: true, data: { ...panelPublic(panel), revision: panelRevision(panel) } });
}

async function route(req, res) {
  if (req.method === 'OPTIONS') {
    sendJson(res, 204, {});
    return;
  }

  const url = new URL(req.url, `http://${req.headers.host || 'localhost'}`);
  const path = url.pathname.replace(/\/+$/g, '') || '/';

  if (req.method === 'GET' && path === '/health') {
    sendJson(res, 200, {
      ok: true,
      service: 'client-auth-server',
      time: iso()
    });
    return;
  }

  if (req.method === 'GET' && path === '/api/public-key') {
    sendJson(res, 200, {
      ok: true,
      data: {
        publicKey: keys.publicKey,
        issuer: config.issuer
      }
    });
    return;
  }

  if (req.method === 'POST' && path === '/api/client/verify') {
    await handleClientVerify(req, res);
    return;
  }

  if (req.method === 'POST' && path === '/api/client/heartbeat') {
    await handleClientHeartbeat(req, res);
    return;
  }

  if (req.method === 'POST' && path === '/api/client/deactivate') {
    await handleClientDeactivate(req, res);
    return;
  }

  if (path === '/api/gateway/panel' || path.startsWith('/api/gateway/panel/')) {
    await handlePanelGateway(req, res, url, path);
    return;
  }

  if (req.method === 'GET' && path === '/api/admin/stats') {
    await handleAdminStats(req, res);
    return;
  }

  if (req.method === 'GET' && path === '/api/admin/devices') {
    if (!requireAdmin(req, res)) return;
    const offset = Number(url.searchParams.get('offset') || 0);
    if (!Number.isSafeInteger(offset) || offset < 0 || offset > 1000000) {
      sendError(res, 422, 'INVALID_OFFSET', '分页参数不正确');
      return;
    }
    sendJson(res, 200, { ok: true, data: await store.listDevices(offset) });
    return;
  }

  if (req.method === 'GET' && path === '/api/admin/panels') {
    await handleListPanels(req, res);
    return;
  }

  if (req.method === 'POST' && path === '/api/admin/panels/update') {
    await handleUpdatePanel(req, res);
    return;
  }

  sendError(res, 404, 'NOT_FOUND', '接口不存在');
}

const server = http.createServer((req, res) => {
  route(req, res).catch((error) => {
    console.error('Gateway request failed:', error.message);
    if (!res.headersSent) sendError(res, 500, 'SERVER_ERROR', '授权服务器异常');
  });
});

async function start() {
  await store.init();
  const registry = panelRegistry();
  if (registry.panels.length !== 1 || registry.panels[0].id !== config.sitePanelId) {
    throw new Error('Gateway configuration must contain exactly the pinned site');
  }
  server.listen(config.port, config.host, () => {
    console.log(`Client auth server listening on http://${config.host}:${config.port}`);
    if (!config.adminToken) {
      console.warn('AUTH_ADMIN_TOKEN is empty. Admin APIs are disabled until it is configured.');
    }
  });
}

start().catch((error) => {
  console.error(error);
  process.exit(1);
});
