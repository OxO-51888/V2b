const { execFile } = require('child_process');
const net = require('net');

function normalizeHost(value) {
  const raw = String(value || '').trim();
  if (!raw) return '';

  try {
    return new URL(raw.includes('://') ? raw : `https://${raw}`).hostname;
  } catch {
    return raw.replace(/^\[|\]$/g, '').split(':')[0];
  }
}

function parsePingLatency(stdout) {
  const text = String(stdout || '');
  const values = [...text.matchAll(/(\d+)\s*ms/gi)].map((match) => Number(match[1]));
  return values.length ? Math.min(...values) : null;
}

function pingHost(host, timeoutMs = 1200) {
  const target = normalizeHost(host);
  if (!target) return Promise.resolve(null);

  return new Promise((resolve) => {
    execFile('ping.exe', ['-n', '1', '-w', String(timeoutMs), target], {
      windowsHide: true,
      timeout: timeoutMs + 600
    }, (_error, stdout) => {
      resolve(parsePingLatency(stdout));
    });
  });
}

function firstPort(value) {
  const text = String(value || '').split(',')[0].trim();
  const first = text.includes('-') ? text.split('-')[0] : text;
  const port = Number(first || 0);
  return Number.isFinite(port) && port > 0 ? port : 0;
}

function tcpLatency(host, port, timeoutMs = 1200) {
  const target = normalizeHost(host);
  const targetPort = firstPort(port);
  if (!target || !targetPort) return Promise.resolve(null);

  return new Promise((resolve) => {
    const startedAt = Date.now();
    const socket = net.createConnection({ host: target, port: targetPort });
    let settled = false;

    const finish = (latency) => {
      if (settled) return;
      settled = true;
      socket.destroy();
      resolve(latency);
    };

    socket.setTimeout(timeoutMs);
    socket.once('connect', () => finish(Date.now() - startedAt));
    socket.once('timeout', () => finish(null));
    socket.once('error', () => finish(null));
  });
}

async function measureNodeLatency(nodes = []) {
  const hostCache = new Map();
  const prepared = nodes.map((node) => {
    const key = String(node.key ?? node.id ?? '');
    const host = normalizeHost(node.host || node.server || node.address || node.addr || node.server_name);
    const port = firstPort(node.port || node.server_port);
    const cacheKey = `${host}:${port || 0}`;
    return { key, host, port, cacheKey };
  });

  prepared.forEach(({ key, host, port, cacheKey }) => {
    if (!key || !host || hostCache.has(cacheKey)) return;
    hostCache.set(cacheKey, pingHost(host).then((latency) => latency ?? tcpLatency(host, port)));
  });

  return Promise.all(prepared.map(async ({ key, host, cacheKey }) => ({
    key,
    latency: key && host ? await hostCache.get(cacheKey) : null
  })));
}

module.exports = { measureNodeLatency };
