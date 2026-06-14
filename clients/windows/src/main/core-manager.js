const path = require('path');
const fs = require('fs/promises');
const { existsSync } = require('fs');
const { spawn } = require('child_process');
const crypto = require('crypto');
const net = require('net');

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

function friendlyCoreError(error) {
  const message = String(error?.message || error || '').toLowerCase();
  const code = String(error?.code || '').toUpperCase();
  if (code === 'ECONNREFUSED' || message.includes('econnrefused')) return '内核控制接口未启动';
  if (code === 'ETIMEDOUT' || message.includes('timeout') || message.includes('timed out')) return '内核控制接口响应超时';
  if (code === 'ECONNRESET' || message.includes('econnreset') || message.includes('socket hang up')) return '内核控制接口连接中断';
  if (message.includes('fetch failed')) return '本地内核请求失败';
  return error?.message || '未知错误';
}

async function fetchWithTimeout(url, options = {}, timeoutMs = 2500, label = '内核请求') {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    return await fetch(url, {
      ...options,
      signal: controller.signal
    });
  } catch (error) {
    if (error.name === 'AbortError') {
      throw new Error(`${label}超时`);
    }
    throw new Error(`${label}失败：${friendlyCoreError(error)}`);
  } finally {
    clearTimeout(timer);
  }
}

function websocketFrame(opcode, payload = Buffer.alloc(0)) {
  const data = Buffer.isBuffer(payload) ? payload : Buffer.from(payload);
  const headerLength = data.length < 126 ? 2 : (data.length <= 0xffff ? 4 : 10);
  const frame = Buffer.alloc(headerLength + 4 + data.length);
  frame[0] = 0x80 | opcode;

  if (data.length < 126) {
    frame[1] = 0x80 | data.length;
    crypto.randomBytes(4).copy(frame, 2);
    for (let index = 0; index < data.length; index += 1) frame[6 + index] = data[index] ^ frame[2 + (index % 4)];
    return frame;
  }

  if (data.length <= 0xffff) {
    frame[1] = 0x80 | 126;
    frame.writeUInt16BE(data.length, 2);
    crypto.randomBytes(4).copy(frame, 4);
    for (let index = 0; index < data.length; index += 1) frame[8 + index] = data[index] ^ frame[4 + (index % 4)];
    return frame;
  }

  frame[1] = 0x80 | 127;
  frame.writeBigUInt64BE(BigInt(data.length), 2);
  crypto.randomBytes(4).copy(frame, 10);
  for (let index = 0; index < data.length; index += 1) frame[14 + index] = data[index] ^ frame[10 + (index % 4)];
  return frame;
}

class CoreManager {
  constructor(app, notifyStatus) {
    this.app = app;
    this.notifyStatus = notifyStatus;
    this.process = null;
    this.lastOutput = [];
    this.trafficSocket = null;
    this.trafficRetryTimer = null;
    this.trafficRate = null;
  }

  getProfilePath() {
    return path.join(this.app.getPath('userData'), 'profiles', 'panel.yaml');
  }

  getRuntimeDir() {
    return path.join(this.app.getPath('userData'), 'mihomo');
  }

  getLogPath() {
    return path.join(this.getRuntimeDir(), 'mihomo.log');
  }

  resolveCorePath() {
    const productionPath = path.join(process.resourcesPath, 'mihomo.exe');
    const developmentPath = path.join(__dirname, '..', '..', 'resources', 'mihomo.exe');
    if (existsSync(productionPath)) return productionPath;
    if (existsSync(developmentPath)) return developmentPath;
    return '';
  }

  resolveBundledResource(name) {
    const productionPath = path.join(process.resourcesPath, name);
    const developmentPath = path.join(__dirname, '..', '..', 'resources', name);
    if (existsSync(productionPath)) return productionPath;
    if (existsSync(developmentPath)) return developmentPath;
    return '';
  }

  async ensureGeoData(runtimeDir) {
    for (const name of ['geoip.metadb', 'GeoSite.dat']) {
      const source = this.resolveBundledResource(name);
      const target = path.join(runtimeDir, name);
      if (source && !existsSync(target)) {
        await fs.copyFile(source, target);
      }
    }
  }

  async writeProfile(content) {
    const profilePath = this.getProfilePath();
    await fs.mkdir(path.dirname(profilePath), { recursive: true });
    await fs.writeFile(profilePath, this.withLocalController(content), 'utf8');
    return profilePath;
  }

  withLocalController(content) {
    const cleaned = String(content)
      .replace(/^external-controller\s*:.*$/gmi, '')
      .replace(/^secret\s*:.*$/gmi, '')
      .replace(/^allow-lan\s*:.*$/gmi, '')
      .replace(/^mode\s*:.*$/gmi, '')
      .trim();

    return `${cleaned}

allow-lan: false
external-controller: 127.0.0.1:9090
secret: ""
mode: rule
`;
  }

  async start(profilePath) {
    if (this.process) return { running: true };
    const corePath = this.resolveCorePath();
    if (!corePath) {
      throw new Error('缺少 mihomo.exe，请放到 clients/windows/resources/mihomo.exe');
    }

    const runtimeDir = this.getRuntimeDir();
    await fs.mkdir(runtimeDir, { recursive: true });
    await this.ensureGeoData(runtimeDir);
    this.lastOutput = [];
    await fs.writeFile(this.getLogPath(), `[${new Date().toISOString()}] start mihomo\n`, 'utf8').catch(() => {});
    this.process = spawn(corePath, ['-d', runtimeDir, '-f', profilePath], {
      windowsHide: true,
      stdio: ['ignore', 'pipe', 'pipe']
    });
    this.process.stdout?.on('data', (data) => this.appendLog(data));
    this.process.stderr?.on('data', (data) => this.appendLog(data));
    this.process.on('error', (error) => {
      this.appendLog(`process error: ${error.message}`);
      this.process = null;
      this.notifyStatus({ running: false });
    });
    this.process.on('exit', () => {
      this.appendLog('process exited');
      this.stopTrafficStream();
      this.process = null;
      this.notifyStatus({ running: false });
    });
    try {
      await this.waitForController();
    } catch (error) {
      const tail = this.lastOutput.slice(-5).join(' ');
      throw new Error(`${error.message}${tail ? `，内核日志：${tail}` : ''}`);
    }
    this.startTrafficStream();
    this.notifyStatus({ running: true });
    return { running: true };
  }

  appendLog(data) {
    const text = String(data || '').replace(/\s+/g, ' ').trim();
    if (!text) return;
    this.lastOutput.push(text);
    if (this.lastOutput.length > 40) this.lastOutput.shift();
    fs.appendFile(this.getLogPath(), `[${new Date().toISOString()}] ${text}\n`, 'utf8').catch(() => {});
  }

  async stop() {
    this.stopTrafficStream();
    if (this.process) {
      this.process.kill();
      this.process = null;
    }
    this.notifyStatus({ running: false });
    return { running: false };
  }

  async setMode(mode) {
    const mapped = { rule: 'rule', global: 'global', auto: 'rule' };
    const nextMode = mapped[mode] || 'rule';
    if (!this.process) return { mode: nextMode, applied: false };

    let lastError;
    for (let attempt = 0; attempt < 8; attempt += 1) {
      try {
        const response = await fetchWithTimeout('http://127.0.0.1:9090/configs', {
          method: 'PATCH',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ mode: nextMode })
        }, 2500, '模式切换');
        if (response.ok) return { mode: nextMode, applied: true };
        lastError = new Error(`模式切换失败：${response.status}`);
      } catch (error) {
        lastError = error;
      }
      await sleep(180);
    }
    throw lastError || new Error('模式切换失败');
  }

  async selectProxy(proxyName) {
    const selected = String(proxyName || '').trim();
    if (!selected) return { applied: false, reason: 'empty' };
    if (!this.process) return { applied: false, running: false };

    const response = await fetchWithTimeout('http://127.0.0.1:9090/proxies', {}, 2500, '代理组读取');
    if (!response.ok) {
      throw new Error(`代理组读取失败：${response.status}`);
    }

    const data = await response.json();
    const proxies = data.proxies || {};
    const candidates = Object.entries(proxies).filter(([, proxy]) => {
      return Array.isArray(proxy?.all) && proxy.all.includes(selected);
    });
    const appliedGroups = [];
    const errors = [];

    for (const [groupName] of candidates) {
      try {
        const result = await fetchWithTimeout(`http://127.0.0.1:9090/proxies/${encodeURIComponent(groupName)}`, {
          method: 'PUT',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ name: selected })
        }, 2500, '节点切换');
        if (result.ok || result.status === 204) {
          appliedGroups.push(groupName);
        } else {
          errors.push(`${groupName}:${result.status}`);
        }
      } catch (error) {
        errors.push(`${groupName}:${error.message}`);
      }
    }

    return {
      applied: appliedGroups.length > 0,
      proxy: selected,
      groups: appliedGroups,
      reason: appliedGroups.length ? undefined : 'not_found',
      errors
    };
  }

  async getTrafficSnapshot() {
    if (!this.process) {
      return { running: false, source: 'core', uploadTotal: 0, downloadTotal: 0 };
    }

    if (this.trafficRate && Date.now() - this.trafficRate.at < 2500) {
      return {
        running: true,
        source: 'core-realtime',
        uploadRate: this.trafficRate.uploadRate,
        downloadRate: this.trafficRate.downloadRate,
        uploadTotal: this.trafficRate.uploadTotal,
        downloadTotal: this.trafficRate.downloadTotal
      };
    }

    const response = await fetchWithTimeout('http://127.0.0.1:9090/connections', {}, 1500, '流量读取');
    if (!response.ok) {
      throw new Error(`流量读取失败：${response.status}`);
    }

    const data = await response.json();
    return {
      running: true,
      source: 'core',
      uploadTotal: Number(data.uploadTotal || 0),
      downloadTotal: Number(data.downloadTotal || 0)
    };
  }

  startTrafficStream() {
    this.stopTrafficStream(false);
    if (!this.process) return;

    let upgraded = false;
    let buffer = Buffer.alloc(0);
    const key = crypto.randomBytes(16).toString('base64');
    const socket = net.createConnection({ host: '127.0.0.1', port: 9090 });
    this.trafficSocket = socket;

    const scheduleReconnect = () => {
      if (this.trafficSocket === socket) this.trafficSocket = null;
      if (!this.process || this.trafficRetryTimer) return;
      this.trafficRetryTimer = setTimeout(() => {
        this.trafficRetryTimer = null;
        this.startTrafficStream();
      }, 1500);
    };

    const handleTrafficPayload = (payload) => {
      try {
        const data = JSON.parse(payload.toString('utf8'));
        const uploadRate = Math.max(0, Number(data.up ?? data.upload ?? data.uploadRate ?? 0));
        const downloadRate = Math.max(0, Number(data.down ?? data.download ?? data.downloadRate ?? 0));
        const now = Date.now();
        const previous = this.trafficRate;
        const elapsed = previous?.at ? Math.max(0, (now - previous.at) / 1000) : 0;
        this.trafficRate = {
          at: now,
          uploadRate,
          downloadRate,
          uploadTotal: Math.max(0, Number(previous?.uploadTotal || 0) + uploadRate * elapsed),
          downloadTotal: Math.max(0, Number(previous?.downloadTotal || 0) + downloadRate * elapsed)
        };
      } catch (_error) {}
    };

    const parseFrames = () => {
      while (buffer.length >= 2) {
        const first = buffer[0];
        const second = buffer[1];
        const opcode = first & 0x0f;
        const masked = Boolean(second & 0x80);
        let payloadLength = second & 0x7f;
        let offset = 2;

        if (payloadLength === 126) {
          if (buffer.length < offset + 2) return;
          payloadLength = buffer.readUInt16BE(offset);
          offset += 2;
        } else if (payloadLength === 127) {
          if (buffer.length < offset + 8) return;
          payloadLength = Number(buffer.readBigUInt64BE(offset));
          offset += 8;
        }

        let mask;
        if (masked) {
          if (buffer.length < offset + 4) return;
          mask = buffer.subarray(offset, offset + 4);
          offset += 4;
        }

        if (buffer.length < offset + payloadLength) return;
        let payload = buffer.subarray(offset, offset + payloadLength);
        buffer = buffer.subarray(offset + payloadLength);

        if (masked) {
          payload = Buffer.from(payload);
          for (let index = 0; index < payload.length; index += 1) payload[index] ^= mask[index % 4];
        }

        if (opcode === 0x1) handleTrafficPayload(payload);
        if (opcode === 0x8) {
          socket.end();
          return;
        }
        if (opcode === 0x9) socket.write(websocketFrame(0xA, payload));
      }
    };

    socket.setNoDelay(true);
    socket.on('connect', () => {
      socket.write([
        'GET /traffic HTTP/1.1',
        'Host: 127.0.0.1:9090',
        'Connection: Upgrade',
        'Upgrade: websocket',
        `Sec-WebSocket-Key: ${key}`,
        'Sec-WebSocket-Version: 13',
        '\r\n'
      ].join('\r\n'));
    });

    socket.on('data', (chunk) => {
      buffer = Buffer.concat([buffer, chunk]);
      if (!upgraded) {
        const headerEnd = buffer.indexOf('\r\n\r\n');
        if (headerEnd === -1) return;
        const header = buffer.subarray(0, headerEnd).toString('utf8');
        buffer = buffer.subarray(headerEnd + 4);
        if (!/^HTTP\/1\.[01] 101\b/i.test(header)) {
          socket.destroy();
          return;
        }
        upgraded = true;
      }
      parseFrames();
    });

    socket.on('error', () => {});
    socket.on('close', scheduleReconnect);
  }

  stopTrafficStream(clearRate = true) {
    if (this.trafficRetryTimer) {
      clearTimeout(this.trafficRetryTimer);
      this.trafficRetryTimer = null;
    }
    if (this.trafficSocket) {
      this.trafficSocket.removeAllListeners('close');
      this.trafficSocket.destroy();
      this.trafficSocket = null;
    }
    if (clearRate) this.trafficRate = null;
  }

  async waitForController() {
    let lastError;
    for (let attempt = 0; attempt < 80; attempt += 1) {
      if (!this.process) break;
      try {
        const response = await fetchWithTimeout('http://127.0.0.1:9090/configs', {}, 1200, '内核控制接口');
        if (response.ok) return;
        lastError = new Error(`内核控制接口未就绪：${response.status}`);
      } catch (error) {
        lastError = error;
      }
      await sleep(200);
    }
    throw lastError || new Error('内核控制接口未响应');
  }
}

module.exports = { CoreManager };
