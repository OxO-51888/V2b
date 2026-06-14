const { normalizeProtocol, supportedProtocols } = require('./protocols');

function asObject(value) {
  if (!value) return {};
  if (typeof value === 'object') return value;
  try {
    return JSON.parse(value);
  } catch {
    return {};
  }
}

function firstPort(value) {
  const text = String(value || '').split(',')[0].trim();
  const first = text.includes('-') ? text.split('-')[0] : text;
  return Number(first || 0);
}

function isTruthy(value) {
  return value === true || value === 1 || value === '1' || value === 'true';
}

function isEmpty(value) {
  if (value === undefined || value === null || value === '') return true;
  if (Array.isArray(value)) return value.length === 0;
  if (typeof value === 'object') return Object.keys(value).length === 0;
  return false;
}

function clean(value) {
  if (Array.isArray(value)) {
    return value.map(clean).filter((item) => !isEmpty(item));
  }
  if (value && typeof value === 'object') {
    const next = {};
    for (const [key, item] of Object.entries(value)) {
      const cleaned = clean(item);
      if (!isEmpty(cleaned)) next[key] = cleaned;
    }
    return next;
  }
  return value;
}

function quote(value) {
  if (typeof value === 'number' || typeof value === 'boolean') return String(value);
  const text = String(value ?? '');
  if (/^[A-Za-z0-9_.:/@-]+$/.test(text)) return text;
  return `'${text.replace(/'/g, "''")}'`;
}

function dumpYaml(value, indent = 0) {
  const space = ' '.repeat(indent);
  if (Array.isArray(value)) {
    if (!value.length) return `${space}[]`;
    return value.map((item) => {
      if (item && typeof item === 'object') {
        const dumped = dumpYaml(item, indent + 2).trimStart();
        return `${space}- ${dumped}`;
      }
      return `${space}- ${quote(item)}`;
    }).join('\n');
  }
  if (value && typeof value === 'object') {
    return Object.entries(value).map(([key, item]) => {
      if (item && typeof item === 'object') {
        return `${space}${key}:\n${dumpYaml(item, indent + 2)}`;
      }
      return `${space}${key}: ${quote(item)}`;
    }).join('\n');
  }
  return `${space}${quote(value)}`;
}

function tlsSettings(server) {
  return asObject(server.tls_settings || server.tlsSettings);
}

function networkSettings(server) {
  return asObject(server.network_settings || server.networkSettings);
}

function applyTransport(proxy, server) {
  const network = server.network;
  const settings = networkSettings(server);
  if (network === 'tcp' && settings.header?.type === 'http') {
    proxy.network = 'http';
    if (settings.header.request?.headers?.Host) proxy['http-opts'] = { headers: { Host: settings.header.request.headers.Host } };
    if (settings.header.request?.path) proxy['http-opts'] = { ...(proxy['http-opts'] || {}), path: settings.header.request.path };
  }
  if (network === 'ws') {
    proxy.network = 'ws';
    proxy['ws-opts'] = {};
    if (settings.path) proxy['ws-opts'].path = settings.path;
    if (settings.headers?.Host) proxy['ws-opts'].headers = { Host: settings.headers.Host };
    if (settings.security && proxy.type === 'vmess') proxy.cipher = settings.security;
  }
  if (network === 'grpc') {
    proxy.network = 'grpc';
    proxy['grpc-opts'] = {};
    if (settings.serviceName) proxy['grpc-opts']['grpc-service-name'] = settings.serviceName;
  }
  if (network === 'xhttp') {
    proxy.network = 'xhttp';
    proxy['xhttp-opts'] = {};
    if (settings.path) proxy['xhttp-opts'].path = settings.path;
    if (settings.host) proxy['xhttp-opts'].host = settings.host;
    if (settings.mode) proxy['xhttp-opts'].mode = settings.mode;
  }
}

function applyTls(proxy, server) {
  const tls = tlsSettings(server);
  if (!server.tls && !server.server_name && !tls.server_name && !tls.serverName) return;
  if (server.tls) proxy.tls = true;
  proxy['skip-cert-verify'] = isTruthy(tls.allow_insecure || tls.allowInsecure || server.insecure || server.allow_insecure);
  proxy.servername = tls.server_name || tls.serverName || server.server_name || '';
  if (tls.fingerprint) proxy['client-fingerprint'] = tls.fingerprint;
  if (Number(server.tls) === 2) {
    proxy['reality-opts'] = {
      'public-key': tls.public_key,
      'short-id': tls.short_id
    };
  }
}

function buildVmess(uuid, server) {
  const proxy = {
    name: server.name,
    type: 'vmess',
    server: server.host,
    port: firstPort(server.port),
    uuid,
    alterId: 0,
    cipher: 'auto',
    udp: true
  };
  applyTls(proxy, server);
  applyTransport(proxy, server);
  return clean(proxy);
}

function buildVless(uuid, server) {
  const proxy = {
    name: server.name,
    type: 'vless',
    server: server.host,
    port: firstPort(server.port),
    uuid,
    udp: true
  };
  if (server.flow) proxy.flow = server.flow;
  applyTls(proxy, server);
  applyTransport(proxy, server);
  return clean(proxy);
}

function buildTrojan(uuid, server) {
  const tls = tlsSettings(server);
  const proxy = {
    name: server.name,
    type: 'trojan',
    server: server.host,
    port: firstPort(server.port),
    password: uuid,
    udp: true,
    sni: server.server_name || tls.server_name || '',
    'skip-cert-verify': isTruthy(server.allow_insecure || server.insecure || tls.allow_insecure)
  };
  applyTransport(proxy, server);
  return clean(proxy);
}

function buildHysteria2(uuid, server) {
  const tls = tlsSettings(server);
  const proxy = {
    name: server.name,
    type: 'hysteria2',
    server: server.host,
    port: firstPort(server.port),
    password: uuid,
    udp: true,
    'skip-cert-verify': isTruthy(server.insecure || tls.allow_insecure),
    sni: server.server_name || tls.server_name || ''
  };
  const portText = String(server.port || '');
  if (portText.includes(',') || portText.includes('-')) {
    proxy.ports = portText;
    proxy.mport = portText;
  }
  if (server.obfs) {
    proxy.obfs = server.obfs;
    proxy['obfs-password'] = server.obfs_password;
  }
  return clean(proxy);
}

function buildProxy(uuid, server) {
  const protocol = normalizeProtocol(server);
  if (!supportedProtocols.has(protocol)) return null;
  if (protocol === 'vmess') return buildVmess(uuid, server);
  if (protocol === 'vless') return buildVless(uuid, server);
  if (protocol === 'trojan') return buildTrojan(uuid, server);
  if (protocol === 'hysteria2') return buildHysteria2(uuid, server);
  return null;
}

function buildMihomoProfile(dashboard) {
  const uuid = dashboard?.user?.uuid;
  if (!uuid) throw new Error('面板没有返回用户连接密钥');
  const servers = Array.isArray(dashboard?.servers) ? dashboard.servers : [];
  const proxies = servers.map((server) => buildProxy(uuid, server)).filter(Boolean);
  if (!proxies.length) throw new Error('没有可用节点');
  const names = proxies.map((proxy) => proxy.name);
  const config = {
    'mixed-port': 7890,
    'bind-address': '*',
    'log-level': 'info',
    dns: {
      enable: true,
      ipv6: false,
      'default-nameserver': ['223.5.5.5', '119.29.29.29', '114.114.114.114'],
      'enhanced-mode': 'fake-ip',
      'fake-ip-range': '198.18.0.1/16',
      'use-hosts': true,
      nameserver: ['223.5.5.5', '119.29.29.29', '114.114.114.114'],
      fallback: ['1.1.1.1', '8.8.8.8']
    },
    proxies,
    'proxy-groups': [
      {
        name: '节点选择',
        type: 'select',
        proxies: ['自动选择', ...names]
      },
      {
        name: '自动选择',
        type: 'url-test',
        proxies: names,
        url: 'http://www.gstatic.com/generate_204',
        interval: 300,
        tolerance: 50
      }
    ],
    rules: [
      'DOMAIN-SUFFIX,local,DIRECT',
      'IP-CIDR,127.0.0.0/8,DIRECT,no-resolve',
      'IP-CIDR,10.0.0.0/8,DIRECT,no-resolve',
      'IP-CIDR,172.16.0.0/12,DIRECT,no-resolve',
      'IP-CIDR,192.168.0.0/16,DIRECT,no-resolve',
      'GEOIP,CN,DIRECT',
      'MATCH,节点选择'
    ]
  };
  return `${dumpYaml(clean(config))}\n`;
}

module.exports = {
  buildMihomoProfile
};
