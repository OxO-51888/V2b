const { collectProtocols } = require('./protocols');
const crypto = require('crypto');
const { CLIENT_ID, CLIENT_VERSION, CLIENT_SIGN_SECRET } = require('./app-config');

function normalizeBaseUrl(panelUrl) {
  const url = new URL(panelUrl);
  return `${url.origin}/api/v1`;
}

async function fetchWithTimeout(url, options = {}, timeoutMs = 30000, label = '请求') {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    return await fetch(url, {
      ...options,
      signal: controller.signal
    });
  } catch (error) {
    if (error.name === 'AbortError') {
      throw new Error(`${label}超时，请检查网络后重试`);
    }
    throw new Error(`${label}失败：${friendlyNetworkError(error)}`);
  } finally {
    clearTimeout(timer);
  }
}

function friendlyNetworkError(error) {
  const message = String(error?.message || error || '').toLowerCase();
  const code = String(error?.code || '').toUpperCase();
  if (code === 'ECONNREFUSED' || message.includes('econnrefused')) return '连接被拒绝，请检查网络或服务器状态';
  if (code === 'ETIMEDOUT' || message.includes('timeout') || message.includes('timed out')) return '连接超时，请检查本地网络';
  if (code === 'ENOTFOUND' || message.includes('enotfound') || message.includes('getaddrinfo')) return '域名解析失败，请检查本地网络';
  if (code === 'ECONNRESET' || message.includes('socket hang up') || message.includes('econnreset')) return '连接被中断，请稍后重试';
  if (message.includes('certificate') || message.includes('cert') || code.includes('CERT')) return '证书验证失败，请检查下载域名证书';
  if (message.includes('fetch failed')) return '网络请求失败，请检查本地网络';
  return error?.message || '网络不可用';
}

function extractAuthData(payload) {
  if (!payload) return '';
  if (typeof payload.data === 'string') return payload.data;
  if (payload.data?.auth_data) return payload.data.auth_data;
  if (payload.auth_data) return payload.auth_data;
  if (payload.data?.token) return payload.data.token;
  return '';
}

async function request(state, endpoint, options = {}) {
  const method = String(options.method || 'GET').toUpperCase();
  const headers = {
    ...(options.headers || {}),
    ...clientSignatureHeaders(method, `${state.baseUrl}${endpoint}`)
  };
  if (state.auth) headers.Authorization = state.auth;
  if (options.body && typeof options.body !== 'string') {
    headers['Content-Type'] = 'application/json';
    options.body = JSON.stringify(options.body);
  }

  const response = await fetchWithTimeout(`${state.baseUrl}${endpoint}`, {
    ...options,
    method,
    headers
  }, options.timeoutMs || 30000, options.label || '面板请求');
  const text = await response.text();
  const payload = parsePayload(text);

  if (!response.ok) {
    throw new Error(payload?.message || payload?.error || text || `请求失败：${response.status}`);
  }

  return payload;
}

function clientSignatureHeaders(method, url) {
  const target = new URL(url);
  const timestamp = Math.floor(Date.now() / 1000).toString();
  const nonce = crypto.randomBytes(16).toString('hex');
  const payload = [
    method,
    target.pathname,
    timestamp,
    nonce,
    CLIENT_ID,
    CLIENT_VERSION
  ].join('\n');
  const sign = crypto.createHmac('sha256', CLIENT_SIGN_SECRET).update(payload).digest('hex');
  return {
    'X-Xiao-Client': CLIENT_ID,
    'X-Xiao-Version': CLIENT_VERSION,
    'X-Xiao-Timestamp': timestamp,
    'X-Xiao-Nonce': nonce,
    'X-Xiao-Sign': sign
  };
}

async function login(panelUrl, email, password) {
  const baseUrl = normalizeBaseUrl(panelUrl);
  const result = await request({ baseUrl }, '/passport/auth/login', {
    method: 'POST',
    body: { email, password }
  });
  const auth = extractAuthData(result);
  if (!auth) {
    throw new Error('登录成功但没有返回授权信息');
  }
  return {
    baseUrl,
    panelUrl: new URL(panelUrl).origin,
    auth
  };
}

async function fetchDashboard(state) {
  const subscribe = await request(state, '/user/getSubscribe');
  const plans = await request(state, '/user/plan/fetch');
  const [user, servers, orders, config] = await Promise.all([
    request(state, '/user/info'),
    request(state, '/user/server/fetch'),
    request(state, '/user/order/fetch'),
    request(state, '/user/comm/config')
  ]);

  const serverList = servers.data || [];
  return {
    user: user.data,
    subscribe: subscribe.data,
    servers: serverList,
    protocols: collectProtocols(serverList),
    plans: plans.data || [],
    orders: orders.data || [],
    config: config.data || {}
  };
}

function parsePayload(text) {
  try {
    return text ? JSON.parse(text) : null;
  } catch {
    return text;
  }
}

module.exports = {
  fetchDashboard,
  login,
  request
};
