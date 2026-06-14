const crypto = require('crypto');
const fs = require('fs/promises');
const os = require('os');
const path = require('path');
const { AUTH_CLIENT_ID, AUTH_CLIENT_NAME, AUTH_SERVER_URL, CLIENT_VERSION } = require('./app-config');
const { canonicalJson, verifyLicensePayload } = require('./license-verifier');

const AUTH_TIMEOUT_MS = 10000;
const MIN_HEARTBEAT_MS = 30000;

function cleanBaseUrl(value) {
  const raw = String(value || '').trim().replace(/\/+$/, '');
  if (!raw) return '';
  const url = new URL(raw);
  if (!/^https?:$/.test(url.protocol)) throw new Error('授权服务器地址无效');
  return url.toString().replace(/\/+$/, '');
}

function joinUrl(baseUrl, value) {
  const raw = String(value || '').trim();
  if (!raw) return `${baseUrl}/api/gateway/panel`;
  if (/^https?:\/\//i.test(raw)) return raw.replace(/\/+$/, '');
  return `${baseUrl}${raw.startsWith('/') ? raw : `/${raw}`}`.replace(/\/+$/, '');
}

function sha256(value) {
  return crypto.createHash('sha256').update(value).digest('hex');
}

function normalizeMessage(error) {
  const message = String(error?.message || error || '').trim();
  if (!message) return '授权校验失败，客户端即将关闭';
  if (message.includes('fetch failed')) return '授权服务器连接失败，客户端即将关闭';
  if (message.includes('aborted') || message.includes('timeout')) return '授权服务器请求超时，客户端即将关闭';
  return message;
}

async function fetchJson(url, options = {}) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), options.timeoutMs || AUTH_TIMEOUT_MS);
  try {
    const response = await fetch(url, {
      ...options,
      signal: controller.signal,
      headers: {
        'Content-Type': 'application/json',
        ...(options.headers || {})
      }
    });
    const text = await response.text();
    let payload = null;
    try {
      payload = text ? JSON.parse(text) : null;
    } catch {
      payload = { message: text };
    }
    if (!response.ok || payload?.ok === false) {
      throw new Error(payload?.message || `授权服务器返回异常：${response.status}`);
    }
    return payload;
  } finally {
    clearTimeout(timer);
  }
}

class AuthorizationManager {
  constructor(app, options = {}) {
    this.app = app;
    this.onFailure = typeof options.onFailure === 'function' ? options.onFailure : null;
    this.serverUrl = cleanBaseUrl(AUTH_SERVER_URL);
    this.session = null;
    this.clientContext = {};
    this.verifyPromise = null;
    this.heartbeatTimer = null;
    this.devicePath = path.join(app.getPath('userData'), 'auth-device.json');
  }

  isAuthorized() {
    return Boolean(
      this.session?.sessionToken
      && this.session?.licenseVerified
      && Date.parse(this.session.expiresAt || '') > Date.now() + 15000
    );
  }

  getSession() {
    return this.session ? { ...this.session } : null;
  }

  getCoreEnvironment() {
    if (!this.isAuthorized() || !this.session?.license || !this.session?.signature) {
      throw new Error('core authorization session is not ready');
    }
    return {
      XIAOV2B_CORE_LICENSE_PAYLOAD: Buffer.from(canonicalJson(this.session.license), 'utf8').toString('base64'),
      XIAOV2B_CORE_LICENSE_SIGNATURE: this.session.signature,
      XIAOV2B_CORE_SESSION_TOKEN: this.session.sessionToken
    };
  }

  getGatewayPanelBaseUrl() {
    return this.session?.gatewayPanelBaseUrl || joinUrl(this.serverUrl, '');
  }

  setClientContext(context = {}) {
    const next = { ...this.clientContext };
    if (typeof context.userEmail === 'string') next.userEmail = context.userEmail.trim().toLowerCase();
    if (typeof context.localIp === 'string') next.localIp = context.localIp.trim();
    if (typeof context.localLocation === 'string') next.localLocation = context.localLocation.trim();
    this.clientContext = next;
  }

  contextPayload() {
    return {
      ...(this.clientContext.userEmail ? { userEmail: this.clientContext.userEmail } : {}),
      ...(this.clientContext.localIp ? { localIp: this.clientContext.localIp } : {}),
      ...(this.clientContext.localLocation ? { localLocation: this.clientContext.localLocation } : {})
    };
  }

  async ensureAuthorized() {
    if (this.isAuthorized()) return this.getSession();
    if (!this.verifyPromise) {
      this.verifyPromise = this.verify().finally(() => {
        this.verifyPromise = null;
      });
    }
    return this.verifyPromise;
  }

  async verify() {
    if (!this.serverUrl) throw new Error('授权服务器未配置，客户端无法运行');
    const deviceId = await this.getDeviceId();
    const runtimeName = AUTH_CLIENT_NAME;
    const payload = await fetchJson(`${this.serverUrl}/api/client/verify`, {
      method: 'POST',
      body: JSON.stringify({
        clientId: AUTH_CLIENT_ID,
        clientName: AUTH_CLIENT_NAME,
        runtimeName,
        version: CLIENT_VERSION,
        deviceId,
        deviceName: os.hostname(),
        os: `${os.platform()} ${os.release()} ${os.arch()}`,
        appHash: await this.getAppHash(),
        ...this.contextPayload()
      })
    });

    const data = payload?.data || {};
    if (!payload?.authorized || !data.sessionToken) {
      throw new Error(payload?.message || '当前客户端未授权');
    }
    verifyLicensePayload({
      license: data.license,
      signature: data.signature,
      deviceId,
      runtimeName
    });

    this.session = {
      sessionToken: data.sessionToken,
      expiresAt: data.expiresAt,
      heartbeatAfter: Number(data.heartbeatAfter || 60),
      target: data.target || '',
      gatewayPanelBaseUrl: joinUrl(this.serverUrl, data.gateway?.panelBaseUrl),
      licenseDecryptKey: data.licenseDecryptKey || '',
      licenseDecryptKeyExpiresAt: data.licenseDecryptKeyExpiresAt || '',
      license: data.license || null,
      signature: data.signature || '',
      licenseVerified: true
    };
    this.startHeartbeat();
    return this.getSession();
  }

  async heartbeat() {
    if (!this.session?.sessionToken || !this.serverUrl) return;
    const deviceId = await this.getDeviceId();
    const runtimeName = AUTH_CLIENT_NAME;
    const payload = await fetchJson(`${this.serverUrl}/api/client/heartbeat`, {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${this.session.sessionToken}`
      },
      body: JSON.stringify({
        clientId: AUTH_CLIENT_ID,
        clientName: AUTH_CLIENT_NAME,
        runtimeName,
        version: CLIENT_VERSION,
        deviceId,
        appHash: await this.getAppHash(),
        ...this.contextPayload()
      })
    });
    const data = payload?.data || {};
    this.session.expiresAt = data.expiresAt || this.session.expiresAt;
    this.session.heartbeatAfter = Number(data.heartbeatAfter || this.session.heartbeatAfter || 60);
    this.startHeartbeat();
  }

  startHeartbeat() {
    if (this.heartbeatTimer) clearTimeout(this.heartbeatTimer);
    const interval = Math.max(MIN_HEARTBEAT_MS, Number(this.session?.heartbeatAfter || 60) * 1000);
    this.heartbeatTimer = setTimeout(async () => {
      try {
        await this.heartbeat();
      } catch (error) {
        this.session = null;
        if (this.onFailure) this.onFailure(normalizeMessage(error));
      }
    }, interval);
    if (typeof this.heartbeatTimer.unref === 'function') this.heartbeatTimer.unref();
  }

  stop() {
    if (this.heartbeatTimer) {
      clearTimeout(this.heartbeatTimer);
      this.heartbeatTimer = null;
    }
    this.session = null;
  }

  async getDeviceId() {
    try {
      const saved = JSON.parse(await fs.readFile(this.devicePath, 'utf8'));
      if (typeof saved?.deviceId === 'string' && saved.deviceId.length >= 24) return saved.deviceId;
    } catch {}

    const seed = crypto.randomBytes(32).toString('hex');
    const deviceId = `win-${sha256([
      seed,
      os.hostname(),
      os.platform(),
      os.release(),
      os.arch()
    ].join('|')).slice(0, 48)}`;
    await fs.mkdir(path.dirname(this.devicePath), { recursive: true });
    await fs.writeFile(this.devicePath, JSON.stringify({ deviceId }, null, 2), 'utf8');
    return deviceId;
  }

  async getAppHash() {
    const targets = [
      process.execPath,
      this.app.getAppPath(),
      path.join(__dirname, '..', 'main.js'),
      path.join(__dirname, 'app-config.js')
    ];
    const hash = crypto.createHash('sha256');
    for (const target of targets) {
      try {
        const stat = await fs.stat(target);
        hash.update(`${target}:${stat.size}:${stat.mtimeMs}`);
      } catch {}
    }
    return hash.digest('hex');
  }
}

module.exports = {
  AuthorizationManager,
  normalizeMessage
};
