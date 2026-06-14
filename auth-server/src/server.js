const http = require('http');
const crypto = require('crypto');
const fs = require('fs');
const pathModule = require('path');
const config = require('./config');
const { base64Url, ensureKeyPair, randomToken, sha256, signLicense } = require('./crypto-utils');
const { Store } = require('./store');

const keys = ensureKeyPair(config);
const store = new Store(config);

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
    'Access-Control-Allow-Headers': 'Content-Type,Authorization,X-Admin-Token,X-Panel-Authorization',
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
    'Access-Control-Allow-Headers': 'Content-Type,Authorization,X-Admin-Token,X-Panel-Authorization',
    'Cache-Control': 'no-store',
    'X-Frame-Options': 'DENY',
    'X-Content-Type-Options': 'nosniff',
    'Referrer-Policy': 'no-referrer',
    ...headers
  });
  res.end(body);
}

function sendAdminHtml(res, body, nonce) {
  sendRaw(res, 200, body, {
    'Content-Type': 'text/html; charset=utf-8',
    'Content-Security-Policy': [
      "default-src 'none'",
      `script-src 'nonce-${nonce}'`,
      `style-src 'nonce-${nonce}' 'unsafe-inline'`,
      "connect-src 'self'",
      "img-src 'self' data:",
      "base-uri 'none'",
      "form-action 'none'",
      "frame-ancestors 'none'"
    ].join('; ')
  });
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

function cleanIp(value) {
  let ip = cleanString(value, 80);
  if (!ip) return '';
  ip = ip.replace(/^::ffff:/i, '');
  if (ip.startsWith('[')) ip = ip.slice(1, ip.indexOf(']') > 0 ? ip.indexOf(']') : undefined);
  if (/^\d{1,3}(\.\d{1,3}){3}:\d+$/.test(ip)) ip = ip.slice(0, ip.lastIndexOf(':'));
  return /^[0-9a-f:.]+$/i.test(ip) ? ip : '';
}

function getRequestIp(req) {
  const candidates = [
    req.headers['cf-connecting-ip'],
    req.headers['true-client-ip'],
    req.headers['x-real-ip'],
    String(req.headers['x-forwarded-for'] || '').split(',')[0],
    req.socket?.remoteAddress
  ];
  for (const candidate of candidates) {
    const ip = cleanIp(candidate);
    if (ip) return ip;
  }
  return '';
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
  if (token !== config.adminToken) {
    sendError(res, 401, 'ADMIN_UNAUTHORIZED', '管理权限校验失败');
    return false;
  }
  return true;
}

function readAdminPage(nonce, fileName = 'admin.html') {
  const filePath = pathModule.join(config.rootDir, 'public', fileName);
  const html = fs.readFileSync(filePath, 'utf8');
  return html
    .replace(/__ADMIN_PATH__/g, config.adminPath)
    .replace(/__CSP_NONCE__/g, nonce);
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

function publicDevice(device, onlineDeviceIds = null) {
  return {
    deviceId: device.deviceId,
    status: device.status,
    online: onlineDeviceIds ? onlineDeviceIds.has(device.deviceId) : false,
    userEmail: device.userEmail || '',
    localIp: device.localIp || '',
    localLocation: device.localLocation || '',
    requestIp: device.requestIp || '',
    clientId: device.clientId,
    clientName: device.clientName || '',
    runtimeName: device.runtimeName || '',
    panelId: device.panelId || '',
    version: device.version,
    deviceName: device.deviceName,
    os: device.os,
    licensePreview: device.licensePreview || '',
    expiresAt: device.expiresAt || '',
    firstSeenAt: device.firstSeenAt,
    lastSeenAt: device.lastSeenAt,
    approvedAt: device.approvedAt || '',
    blockedAt: device.blockedAt || '',
    note: device.note || ''
  };
}

function hasActiveTargets(state) {
  return panelRegistry(state).activePanels.some((panel) => panel.gatewayTarget);
}

function isTargetAllowed(state, target) {
  if (!target) return false;
  return panelRegistry(state).activePanels.some((panel) => panel.gatewayTarget === target);
}

function isDeviceAllowed(device, time = nowMs()) {
  if (!device || device.status === 'blocked') return false;
  if (config.deviceApprovalRequired && device.status !== 'approved') return false;
  if (config.deviceApprovalRequired && isExpired(device.expiresAt, time)) return false;
  return true;
}

function deriveLicenseDecryptKey(device, session, target) {
  const secret = config.licenseKeySecret || keys.privateKey;
  const payload = [
    device.deviceId,
    session.tokenHash,
    target,
    session.expiresAt
  ].join('\n');
  return base64Url(crypto.createHmac('sha256', secret).update(payload).digest());
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

  if (!activePanels.length && config.panelUrl) {
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
  const byTarget = {};
  const byClientName = {};
  const duplicateClientNames = new Set();
  for (const panel of panels) {
    byAllId[panel.id] = panel;
  }
  for (const panel of activePanels) {
    byId[panel.id] = panel;
    if (panel.gatewayTarget) byTarget[panel.gatewayTarget] = panel;
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
    byTarget,
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

function resolvePanelForClient({ panelId = '', requestedTarget = '', clientName = '', runtimeName = '' } = {}, state = null) {
  const registry = panelRegistry(state);
  const explicitPanel = getPanelById(panelId, state);
  if (explicitPanel) return explicitPanel;

  const targetPanel = requestedTarget ? registry.byTarget[requestedTarget] : null;
  if (targetPanel) return targetPanel;

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

function resolveGatewayTarget(state = null) {
  const panel = getDefaultPanel(state);
  return normalizeAuthorizedTarget(panel?.gatewayTarget || config.gatewayTarget);
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
  return { ok: true, session, device, state };
}

function buildPanelGatewayUrl(requestUrl, path, panel) {
  const panelOrigin = normalizePanelUrl(panel?.panelUrl || config.panelUrl);
  if (!panelOrigin) return '';
  const prefix = '/api/gateway/panel';
  const endpoint = path.slice(prefix.length) || '/';
  const safeEndpoint = endpoint.startsWith('/') ? endpoint : `/${endpoint}`;
  const target = new URL(`/api/v1${safeEndpoint}${requestUrl.search}`, panelOrigin);
  return target.toString();
}

function createSignedLicense(device, session, time, target = '') {
  const license = {
    issuer: config.issuer,
    product: 'XiaoV2B',
    clientId: device.clientId,
    clientName: device.clientName || '',
    runtimeName: device.runtimeName || '',
    version: device.version,
    deviceId: device.deviceId,
    deviceName: device.deviceName || '',
    panelId: session.panelId || device.panelId || '',
    issuedAt: iso(time),
    expiresAt: session.expiresAt,
    deviceExpiresAt: device.expiresAt || '',
    target,
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

async function handleClientVerify(req, res) {
  const body = await readJson(req);
  const time = nowMs();
  const clientId = cleanString(body.clientId, 64);
  const version = cleanString(body.version, 32);
  const deviceId = cleanDeviceId(body.deviceId);
  const clientName = cleanString(body.clientName || body.name, 120);
  const runtimeName = cleanString(body.runtimeName || body.appName || body.productName || clientName, 120);
  const appHash = cleanString(body.appHash, 160);
  const userEmail = cleanEmail(body.userEmail || body.email);
  const localIp = cleanIp(body.localIp || body.ip);
  const localLocation = cleanString(body.localLocation || body.locationText, 120);
  const requestIp = getRequestIp(req);
  const requestedTarget = normalizeAuthorizedTarget(
    body.requestedTarget || body.target || body.panelTarget || body.serviceTarget
  );
  let resolvedTarget = requestedTarget || '';
  let resolvedPanelId = '';

  if (!config.allowedClients.includes(clientId)) {
    sendError(res, 403, 'CLIENT_NOT_ALLOWED', '\u5ba2\u6237\u7aef\u7c7b\u578b\u4e0d\u5141\u8bb8\u4f7f\u7528');
    return;
  }
  if (!version || compareVersion(version, config.minVersion) < 0) {
    sendError(res, 426, 'CLIENT_VERSION_TOO_OLD', '\u5ba2\u6237\u7aef\u7248\u672c\u8fc7\u4f4e\uff0c\u8bf7\u5347\u7ea7\u540e\u518d\u4f7f\u7528');
    return;
  }
  if (!deviceId) {
    sendError(res, 400, 'DEVICE_INVALID', '\u8bbe\u5907\u6307\u7eb9\u65e0\u6548');
    return;
  }

  let output;
  await store.withTransaction(async (conn) => {
    await store.pruneExpiredSessions(time, conn);
    const existed = await store.getDevice(deviceId, conn);
    const device = existed || {
      deviceId,
      status: config.deviceApprovalRequired ? (config.autoApprove ? 'approved' : 'pending') : 'approved',
      firstSeenAt: iso(time)
    };

    device.clientId = clientId;
    device.version = version;
    device.clientName = clientName;
    device.runtimeName = runtimeName;
    device.deviceName = cleanString(body.deviceName, 120);
    device.os = cleanString(body.os, 120);
    device.appHash = appHash;
    if (userEmail) device.userEmail = userEmail;
    if (localIp) device.localIp = localIp;
    if (localLocation) device.localLocation = localLocation;
    if (requestIp) device.requestIp = requestIp;
    device.lastSeenAt = iso(time);

    if (device.status === 'blocked') {
      await store.saveDevice(device, conn);
      output = { status: 403, code: 'DEVICE_BLOCKED', message: '\u5f53\u524d\u8bbe\u5907\u5df2\u88ab\u7981\u6b62\u4f7f\u7528' };
      return;
    }
    if (!config.deviceApprovalRequired) device.status = 'approved';

    const state = await store.readPanelState(conn);
    const panel = resolvePanelForClient({ requestedTarget, clientName, runtimeName }, state);
    if (!panel) {
      await store.saveDevice(device, conn);
      output = { status: 503, code: 'PANEL_NOT_CONFIGURED', message: '\u6388\u6743\u7f51\u7ad9\u672a\u914d\u7f6e' };
      return;
    }
    device.panelId = panel.id;
    resolvedPanelId = panel.id;
    resolvedTarget = requestedTarget || panel.gatewayTarget || resolveGatewayTarget(state);

    if (requestedTarget && panel.gatewayTarget && requestedTarget !== panel.gatewayTarget) {
      await store.saveDevice(device, conn);
      output = { status: 403, code: 'TARGET_PANEL_MISMATCH', message: '\u5ba2\u6237\u7aef\u8bf7\u6c42\u76ee\u6807\u4e0e\u6388\u6743\u7f51\u7ad9\u4e0d\u4e00\u81f4' };
      return;
    }

    const nameCheck = validateClientName(panel, clientName, runtimeName);
    if (!nameCheck.ok) {
      await store.saveDevice(device, conn);
      output = { status: 403, code: nameCheck.code, message: nameCheck.message };
      return;
    }

    if (config.deviceApprovalRequired && isExpired(device.expiresAt, time)) {
      await store.saveDevice(device, conn);
      output = { status: 403, code: 'DEVICE_EXPIRED', message: '\u8bbe\u5907\u6388\u6743\u5df2\u8fc7\u671f' };
      return;
    }

    if (hasActiveTargets(state)) {
      if (!resolvedTarget) {
        await store.saveDevice(device, conn);
        output = { status: 403, code: 'TARGET_REQUIRED', message: '\u5ba2\u6237\u7aef\u670d\u52a1\u5730\u5740\u672a\u4e0a\u62a5\uff0c\u65e0\u6cd5\u4e0b\u53d1\u8bb8\u53ef\u89e3\u5bc6\u5bc6\u94a5' };
        return;
      }
      if (!isTargetAllowed(state, resolvedTarget)) {
        await store.saveDevice(device, conn);
        output = { status: 403, code: 'TARGET_NOT_ALLOWED', message: '\u5ba2\u6237\u7aef\u8bf7\u6c42\u7684\u670d\u52a1\u5730\u5740\u672a\u6388\u6743' };
        return;
      }
    }

    if (config.deviceApprovalRequired && device.status !== 'approved') {
      await store.saveDevice(device, conn);
      output = {
        status: 403,
        code: 'DEVICE_PENDING',
        message: '\u5f53\u524d\u8bbe\u5907\u672a\u6388\u6743\uff0c\u8bf7\u8054\u7cfb\u7ba1\u7406\u5458\u653e\u884c',
        device: publicDevice(device)
      };
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

    const signed = createSignedLicense(device, session, time, resolvedTarget);
    const licenseDecryptKey = resolvedTarget
      ? deriveLicenseDecryptKey(device, session, resolvedTarget)
      : '';
    output = {
      status: 200,
      body: {
        ok: true,
        authorized: true,
        data: {
          sessionToken,
          expiresAt: session.expiresAt,
          heartbeatAfter: 60,
          device: publicDevice(device),
          panelId: resolvedPanelId,
          target: resolvedTarget,
          gateway: {
            panelBaseUrl: '/api/gateway/panel'
          },
          licenseDecryptKey,
          licenseDecryptKeyExpiresAt: licenseDecryptKey ? session.expiresAt : '',
          ...signed
        }
      }
    };
  });

  if (output?.status !== 200) {
    await store.audit('client.verify.denied', {
      code: output.code,
      deviceId,
      clientId,
      version,
      userEmail,
      localIp,
      requestIp,
      requestedTarget,
      resolvedTarget,
      resolvedPanelId
    });
    sendError(res, output.status, output.code, output.message, output.device ? { device: output.device } : {});
    return;
  }

  await store.audit('client.verify.allowed', { deviceId, clientId, version, userEmail, localIp, requestIp, requestedTarget, resolvedTarget, resolvedPanelId });
  sendJson(res, 200, output.body);
}

async function handleClientHeartbeat(req, res) {
  const body = await readJson(req);
  const token = bearerToken(req) || cleanString(body.sessionToken, 200);
  const clientName = cleanString(body.clientName || body.name, 120);
  const runtimeName = cleanString(body.runtimeName || body.appName || body.productName || clientName, 120);
  const appHash = cleanString(body.appHash, 160);
  const userEmail = cleanEmail(body.userEmail || body.email);
  const localIp = cleanIp(body.localIp || body.ip);
  const localLocation = cleanString(body.localLocation || body.locationText, 120);
  const requestIp = getRequestIp(req);
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
    device.clientName = clientName || device.clientName || '';
    device.runtimeName = runtimeName || device.runtimeName || '';
    device.appHash = appHash || device.appHash || '';
    if (userEmail) device.userEmail = userEmail;
    if (localIp) device.localIp = localIp;
    if (localLocation) device.localLocation = localLocation;
    if (requestIp) device.requestIp = requestIp;
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
          serverTime: iso(time)
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
  const auth = await getAuthorizedSession(req);
  if (!auth.ok) {
    sendError(res, auth.status, auth.code, auth.message);
    return;
  }

  const panel = resolvePanel(auth.session.panelId || auth.device.panelId, auth.state);
  if (!panel) {
    sendError(res, 503, 'PANEL_NOT_CONFIGURED', '授权网站未配置');
    return;
  }

  const targetUrl = buildPanelGatewayUrl(requestUrl, path, panel);
  if (!targetUrl) {
    sendError(res, 503, 'PANEL_GATEWAY_NOT_CONFIGURED', '授权网关未配置网站地址');
    return;
  }

  const method = String(req.method || 'GET').toUpperCase();
  const headers = {
    Accept: String(req.headers.accept || 'application/json, text/plain, */*'),
    'User-Agent': `XiaoV2BAuthGateway/${panel.apiSign.clientVersion || config.panelClientVersion}`,
    ...panelSignatureHeaders(method, targetUrl, panel)
  };
  const contentType = req.headers['content-type'];
  if (contentType) headers['Content-Type'] = String(contentType);
  const panelAuthorization = cleanString(req.headers['x-panel-authorization'], 500);
  if (panelAuthorization) headers.Authorization = panelAuthorization;

  const body = ['GET', 'HEAD'].includes(method) ? undefined : await readRaw(req);
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
  sendRaw(res, upstream.status, bytes, {
    'Content-Type': contentTypeOut
  });
}

async function handleListDevices(req, res) {
  if (!requireAdmin(req, res)) return;
  const time = nowMs();
  await store.pruneExpiredSessions(time);
  const onlineDeviceIds = await store.getOnlineDeviceIds(time);
  const devices = (await store.listDevices())
    .map((device) => publicDevice(device, onlineDeviceIds));
  sendJson(res, 200, { ok: true, data: devices });
}

async function handleListPanels(req, res) {
  if (!requireAdmin(req, res)) return;
  const state = await store.readPanelState();
  const registry = panelRegistry(state);
  sendJson(res, 200, {
    ok: true,
    data: {
      defaultPanelId: registry.defaultPanelId,
      panels: registry.panels.map(panelPublic)
    }
  });
}

async function handleUpdatePanel(req, res) {
  if (!requireAdmin(req, res)) return;
  const body = await readJson(req);
  const panelId = cleanPanelId(body.panelId || body.id);
  if (!panelId) {
    sendError(res, 400, 'PANEL_INVALID', '\u7f51\u7ad9 ID \u65e0\u6548');
    return;
  }
  const name = cleanString(body.name, 120);
  const status = cleanString(body.status, 32);
  const gatewayTarget = normalizeAuthorizedTarget(body.gatewayTarget || body.target);
  const clientName = cleanString(body.clientName || body.allowedClientName, 120);
  if (!name) {
    sendError(res, 400, 'PANEL_NAME_REQUIRED', '\u7f51\u7ad9\u540d\u5b57\u4e0d\u80fd\u4e3a\u7a7a');
    return;
  }
  if (!gatewayTarget) {
    sendError(res, 400, 'PANEL_TARGET_INVALID', '\u53cd\u4ee3 IP \u7aef\u53e3\u6216\u57df\u540d\u65e0\u6548');
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
    if (!getAnyPanelById(panelId, state)) {
      output = { status: 404, code: 'PANEL_NOT_FOUND', message: '\u7f51\u7ad9\u4e0d\u5b58\u5728' };
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
  });
  if (output) {
    sendError(res, output.status, output.code, output.message);
    return;
  }
  await store.audit('admin.panel.update', { panelId, name, status, gatewayTarget, clientName });
  sendJson(res, 200, { ok: true, data: panelPublic(panel) });
}

async function handleApproveDevice(req, res) {
  if (!requireAdmin(req, res)) return;
  const body = await readJson(req);
  const deviceId = cleanDeviceId(body.deviceId);
  if (!deviceId) {
    sendError(res, 400, 'DEVICE_INVALID', '\u8bbe\u5907\u6307\u7eb9\u65e0\u6548');
    return;
  }
  const time = nowMs();
  const requestedPanelId = cleanPanelId(body.panelId);
  let device;
  let panelId = '';
  let output;
  await store.withTransaction(async (conn) => {
    const state = await store.readPanelState(conn);
    if (requestedPanelId && !getPanelById(requestedPanelId, state)) {
      output = { status: 400, code: 'PANEL_INVALID', message: '\u6388\u6743\u7f51\u7ad9\u65e0\u6548' };
      return;
    }
    device = await store.getDevice(deviceId, conn) || {
      deviceId,
      firstSeenAt: iso(time),
      lastSeenAt: iso(time)
    };
    const panel = resolvePanelForClient({
      panelId: requestedPanelId || device.panelId,
      clientName: device.clientName,
      runtimeName: device.runtimeName
    }, state);
    device.status = 'approved';
    if (panel) device.panelId = panel.id;
    panelId = device.panelId || '';
    device.approvedAt = iso(time);
    device.blockedAt = '';
    device.expiresAt = cleanString(body.expiresAt, 40);
    device.note = cleanString(body.note, 240);
    await store.saveDevice(device, conn);
  });
  if (output) {
    sendError(res, output.status, output.code, output.message);
    return;
  }
  await store.audit('admin.device.approve', { deviceId, panelId });
  sendJson(res, 200, { ok: true, data: publicDevice(device) });
}

async function handleBlockDevice(req, res) {
  if (!requireAdmin(req, res)) return;
  const body = await readJson(req);
  const deviceId = cleanDeviceId(body.deviceId);
  if (!deviceId) {
    sendError(res, 400, 'DEVICE_INVALID', '\u8bbe\u5907\u6307\u7eb9\u65e0\u6548');
    return;
  }
  const time = nowMs();
  let device;
  await store.withTransaction(async (conn) => {
    device = await store.getDevice(deviceId, conn) || {
      deviceId,
      firstSeenAt: iso(time)
    };
    device.status = 'blocked';
    device.blockedAt = iso(time);
    device.note = cleanString(body.reason || body.note, 240);
    await store.saveDevice(device, conn);
    await store.deleteSessionsByDevice(deviceId, conn);
  });
  await store.audit('admin.device.block', { deviceId });
  sendJson(res, 200, { ok: true, data: publicDevice(device) });
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
      service: 'xiaov2b-auth-server',
      time: iso()
    });
    return;
  }

  if (req.method === 'GET' && path === `${config.adminPath}/dashboard`) {
    const nonce = randomToken(18);
    sendAdminHtml(res, readAdminPage(nonce, 'dashboard.html'), nonce);
    return;
  }

  if (req.method === 'GET' && path === config.adminPath) {
    const nonce = randomToken(18);
    sendAdminHtml(res, readAdminPage(nonce, 'admin.html'), nonce);
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

  if (req.method === 'GET' && path === '/api/admin/devices') {
    await handleListDevices(req, res);
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

  if (req.method === 'POST' && path === '/api/admin/devices/approve') {
    await handleApproveDevice(req, res);
    return;
  }

  if (req.method === 'POST' && path === '/api/admin/devices/block') {
    await handleBlockDevice(req, res);
    return;
  }

  sendError(res, 404, 'NOT_FOUND', '接口不存在');
}

const server = http.createServer((req, res) => {
  route(req, res).catch((error) => {
    sendError(res, 500, 'SERVER_ERROR', error.message || '授权服务器异常');
  });
});

async function start() {
  await store.init();
  server.listen(config.port, config.host, () => {
    console.log(`XiaoV2B auth server listening on http://${config.host}:${config.port}`);
    if (!config.adminToken) {
      console.warn('AUTH_ADMIN_TOKEN is empty. Admin APIs are disabled until it is configured.');
    }
  });
}

start().catch((error) => {
  console.error(error);
  process.exit(1);
});
