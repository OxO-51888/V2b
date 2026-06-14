const supportedProtocols = new Set(['vmess', 'vless', 'trojan', 'hysteria2']);

function normalizeProtocol(server) {
  if (!server) return '';
  if (server.type === 'v2node') return server.protocol || '';
  if (server.type === 'hysteria' && Number(server.version) === 2) return 'hysteria2';
  return server.type || '';
}

function collectProtocols(servers = []) {
  return [...new Set(servers.map(normalizeProtocol).filter((item) => supportedProtocols.has(item)))];
}

module.exports = {
  collectProtocols,
  normalizeProtocol,
  supportedProtocols
};
