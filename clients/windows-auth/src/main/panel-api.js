const { collectProtocols } = require('./protocols');

function normalizeBaseUrl(value) {
  const raw = String(value || '').trim().replace(/\/+$/, '');
  if (!raw) throw new Error('授权网关地址未配置');
  const url = new URL(raw);
  if (!/^https?:$/.test(url.protocol)) throw new Error('授权网关地址无效');
  return url.toString().replace(/\/+$/, '');
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
  if (message.includes('certificate') || message.includes('cert') || code.includes('CERT')) return '证书验证失败，请检查授权域名证书';
  if (message.includes('fetch failed')) return '网络请求失败，请检查本地网络';
  return error?.message || '网络不可用';
}

function parsePayload(text) {
  try {
    return text ? JSON.parse(text) : null;
  } catch {
    return text;
  }
}

function extractAuthData(payload) {
  if (!payload) return '';
  if (typeof payload.data === 'string') return payload.data;
  if (payload.data?.auth_data) return payload.data.auth_data;
  if (payload.auth_data) return payload.auth_data;
  if (payload.data?.token) return payload.data.token;
  return '';
}

function endpointPath(endpoint) {
  const value = String(endpoint || '/');
  return value.startsWith('/') ? value : `/${value}`;
}

async function request(state, endpoint, options = {}) {
  const method = String(options.method || 'GET').toUpperCase();
  const baseUrl = normalizeBaseUrl(state.baseUrl);
  const gatewaySessionToken = String(state.gatewaySessionToken || '').trim();
  if (!gatewaySessionToken) throw new Error('授权会话不存在，请重新启动客户端');

  const headers = {
    ...(options.headers || {}),
    Authorization: `Bearer ${gatewaySessionToken}`
  };
  if (state.auth) headers['X-Panel-Authorization'] = state.auth;
  if (options.body && typeof options.body !== 'string') {
    headers['Content-Type'] = 'application/json';
    options.body = JSON.stringify(options.body);
  }

  const response = await fetchWithTimeout(`${baseUrl}${endpointPath(endpoint)}`, {
    ...options,
    method,
    headers
  }, options.timeoutMs || 30000, options.label || '面板网关请求');
  const text = await response.text();
  const payload = parsePayload(text);

  if (!response.ok) {
    throw new Error(payload?.message || payload?.error || text || `请求失败：${response.status}`);
  }

  return payload;
}

async function login(gateway, email, password) {
  const baseUrl = normalizeBaseUrl(gateway.baseUrl);
  const state = {
    baseUrl,
    gatewaySessionToken: gateway.sessionToken
  };
  const result = await request(state, '/passport/auth/login', {
    method: 'POST',
    body: { email, password }
  });
  const auth = extractAuthData(result);
  if (!auth) {
    throw new Error('登录成功但没有返回授权信息');
  }
  return {
    baseUrl,
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

module.exports = {
  fetchDashboard,
  login,
  request
};
