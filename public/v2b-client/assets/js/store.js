const recentClientKey = 'xiaov2b.recentClients';

export function readRecentClients() {
  try {
    const value = JSON.parse(localStorage.getItem(recentClientKey) || '[]');
    return Array.isArray(value) ? value : [];
  } catch {
    return [];
  }
}

export function rememberClient(clientId) {
  if (!clientId) return;
  const next = [clientId, ...readRecentClients().filter((id) => id !== clientId)].slice(0, 6);
  localStorage.setItem(recentClientKey, JSON.stringify(next));
}

export function sortByRecentAndRecommended(catalog, protocols = []) {
  const recent = readRecentClients();
  return [...catalog].sort((a, b) => {
    const scoreA = getProtocolScore(a, protocols);
    const scoreB = getProtocolScore(b, protocols);
    if (scoreA !== scoreB) return scoreB - scoreA;
    const recentA = recent.indexOf(a.id);
    const recentB = recent.indexOf(b.id);
    if (recentA !== -1 || recentB !== -1) {
      if (recentA === -1) return 1;
      if (recentB === -1) return -1;
      return recentA - recentB;
    }
    if (a.recommended !== b.recommended) return a.recommended ? -1 : 1;
    return a.name.localeCompare(b.name);
  });
}

function getProtocolScore(client, protocols) {
  if (!protocols?.length) return 0;
  const supported = new Set(client.protocols || []);
  return protocols.filter((protocol) => supported.has(protocol)).length;
}
