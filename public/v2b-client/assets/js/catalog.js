export async function loadClientCatalog() {
  const response = await fetch('./assets/data/client-catalog.json', { cache: 'no-store' });
  if (!response.ok) throw new Error('客户端目录加载失败');
  return response.json();
}

export function filterCatalog(catalog, platform) {
  if (!platform || platform === 'all') return catalog;
  return catalog.filter((item) => item.platform === platform);
}

export function getServerProtocols(servers = []) {
  return [...new Set(servers.map(normalizeServerProtocol).filter(Boolean))]
    .filter((protocol) => supportedProtocols.has(protocol));
}

export function getClientProtocolScore(client, protocols = []) {
  if (!protocols.length) return 1;
  const supported = new Set(client.protocols || []);
  return protocols.filter((protocol) => supported.has(protocol)).length;
}

export function isClientCompatible(client, protocols = []) {
  return getClientProtocolScore(client, protocols) > 0;
}

function normalizeServerProtocol(server) {
  if (!server) return '';
  if (server.type === 'v2node') return server.protocol || '';
  if (server.type === 'hysteria' && Number(server.version) === 2) return 'hysteria2';
  return server.type || '';
}

const supportedProtocols = new Set(['vmess', 'vless', 'trojan', 'hysteria2']);
