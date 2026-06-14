const crypto = require('crypto');
const { AUTH_CLIENT_ID, AUTH_CLIENT_NAME, AUTH_LICENSE_PUBLIC_KEY } = require('./app-config');

function canonicalJson(value) {
  if (Array.isArray(value)) {
    return `[${value.map(canonicalJson).join(',')}]`;
  }
  if (value && typeof value === 'object') {
    return `{${Object.keys(value).sort().map((key) => {
      return `${JSON.stringify(key)}:${canonicalJson(value[key])}`;
    }).join(',')}}`;
  }
  return JSON.stringify(value);
}

function base64UrlDecode(value) {
  const normalized = String(value || '').replace(/-/g, '+').replace(/_/g, '/');
  const padded = normalized.padEnd(Math.ceil(normalized.length / 4) * 4, '=');
  return Buffer.from(padded, 'base64');
}

function namesMatch(value, expected) {
  return String(value || '').trim() === String(expected || '').trim();
}

function isExpired(value, skewMs = 15000) {
  const timestamp = Date.parse(value || '');
  return !Number.isFinite(timestamp) || timestamp <= Date.now() + skewMs;
}

function verifyLicensePayload({ license, signature, deviceId, runtimeName }) {
  if (!license || typeof license !== 'object') throw new Error('授权票据缺失');
  if (!signature) throw new Error('授权签名缺失');

  const verified = crypto.verify(
    null,
    Buffer.from(canonicalJson(license)),
    AUTH_LICENSE_PUBLIC_KEY,
    base64UrlDecode(signature)
  );
  if (!verified) throw new Error('授权签名无效');

  if (license.clientId !== AUTH_CLIENT_ID) throw new Error('授权客户端类型不匹配');
  if (!namesMatch(license.clientName, AUTH_CLIENT_NAME)) throw new Error('授权客户端名称不匹配');
  if (runtimeName && license.runtimeName && !namesMatch(license.runtimeName, runtimeName)) {
    throw new Error('授权运行名称不匹配');
  }
  if (deviceId && license.deviceId !== deviceId) throw new Error('授权设备不匹配');
  if (isExpired(license.expiresAt)) throw new Error('授权会话已过期');
  if (license.deviceExpiresAt && isExpired(license.deviceExpiresAt, 0)) throw new Error('设备授权已过期');
  if (!license.policy?.allowRun) throw new Error('授权策略禁止运行客户端');

  if (!license.policy?.allowCore) throw new Error('core authorization denied');

  return true;
}

module.exports = {
  canonicalJson,
  verifyLicensePayload
};
