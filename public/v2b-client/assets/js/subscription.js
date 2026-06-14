export function buildImportUrl(client, subscribeUrl, profileName = 'XiaoV2B') {
  if (!client?.scheme || !subscribeUrl) return '';
  const encodedUrl = encodeURIComponent(subscribeUrl);
  const encodedName = encodeURIComponent(profileName);

  if (client.scheme === 'clash') {
    return `clash://install-config?url=${encodedUrl}`;
  }

  if (client.scheme === 'stash') {
    return `stash://install-config?url=${encodedUrl}`;
  }

  if (client.scheme === 'mihomo') {
    return `mihomo://install-config?url=${encodedUrl}&name=${encodedName}`;
  }

  if (client.scheme === 'v2rayng') {
    return `v2rayng://install-config?url=${encodedUrl}&name=${encodedName}`;
  }

  return '';
}

export function openExternalUrl(url) {
  if (!url) throw new Error('没有可打开的链接');
  window.location.href = url;
}
