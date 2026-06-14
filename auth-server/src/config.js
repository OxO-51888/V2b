const fs = require('fs');
const path = require('path');

const ROOT_DIR = path.resolve(__dirname, '..');

function loadDotEnv(filePath = path.join(ROOT_DIR, '.env')) {
  if (!fs.existsSync(filePath)) return;
  const content = fs.readFileSync(filePath, 'utf8');
  for (const line of content.split(/\r?\n/)) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith('#')) continue;
    const index = trimmed.indexOf('=');
    if (index <= 0) continue;
    const key = trimmed.slice(0, index).trim();
    let value = trimmed.slice(index + 1).trim();
    if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) {
      value = value.slice(1, -1);
    }
    if (!Object.prototype.hasOwnProperty.call(process.env, key)) {
      process.env[key] = value;
    }
  }
}

function boolEnv(name, fallback = false) {
  const value = process.env[name];
  if (value == null || value === '') return fallback;
  return ['1', 'true', 'yes', 'on'].includes(String(value).toLowerCase());
}

function intEnv(name, fallback) {
  const value = Number.parseInt(process.env[name], 10);
  return Number.isFinite(value) ? value : fallback;
}

function listEnv(name, fallback = []) {
  const value = process.env[name];
  if (!value) return fallback;
  return value.split(',').map((item) => item.trim()).filter(Boolean);
}

function pathEnv(name, fallback) {
  const value = String(process.env[name] || fallback || '').trim();
  const prefixed = value.startsWith('/') ? value : `/${value}`;
  return prefixed.replace(/\/+$/g, '') || fallback;
}

function stripJsonComments(content) {
  let output = '';
  let inString = false;
  let quote = '';
  let escaped = false;
  for (let index = 0; index < content.length; index += 1) {
    const char = content[index];
    const next = content[index + 1];
    if (inString) {
      output += char;
      if (escaped) {
        escaped = false;
      } else if (char === '\\') {
        escaped = true;
      } else if (char === quote) {
        inString = false;
        quote = '';
      }
      continue;
    }
    if (char === '"' || char === "'") {
      inString = true;
      quote = char;
      output += char;
      continue;
    }
    if (char === '/' && next === '/') {
      while (index < content.length && content[index] !== '\n') index += 1;
      output += '\n';
      continue;
    }
    if (char === '/' && next === '*') {
      index += 2;
      while (index < content.length && !(content[index] === '*' && content[index + 1] === '/')) {
        if (content[index] === '\n') output += '\n';
        index += 1;
      }
      index += 1;
      continue;
    }
    output += char;
  }
  return output;
}

function jsonFile(filePath, fallback = {}) {
  if (!filePath || !fs.existsSync(filePath)) return fallback;
  try {
    return JSON.parse(stripJsonComments(fs.readFileSync(filePath, 'utf8')));
  } catch {
    return fallback;
  }
}

loadDotEnv();

const panelsConfigPath = process.env.AUTH_PANELS_CONFIG || path.join(ROOT_DIR, 'config', 'panels.json');

const config = {
  rootDir: ROOT_DIR,
  dataDir: path.join(ROOT_DIR, 'data'),
  host: process.env.AUTH_HOST || '127.0.0.1',
  port: intEnv('AUTH_PORT', 8787),
  issuer: process.env.AUTH_ISSUER || 'XiaoV2B Authorization',
  adminToken: process.env.AUTH_ADMIN_TOKEN || '',
  adminPath: pathEnv('AUTH_ADMIN_PATH', '/shouquan'),
  allowedClients: listEnv('AUTH_ALLOWED_CLIENTS', ['windows-auth']),
  minVersion: process.env.AUTH_MIN_VERSION || '0.1.0',
  sessionTtlSeconds: intEnv('AUTH_SESSION_TTL_SECONDS', 900),
  autoApprove: boolEnv('AUTH_AUTO_APPROVE', false),
  deviceApprovalRequired: boolEnv('AUTH_DEVICE_APPROVAL_REQUIRED', false),
  publicBaseUrl: process.env.AUTH_PUBLIC_BASE_URL || '',
  allowedClientNames: listEnv('AUTH_ALLOWED_CLIENT_NAMES', ['XiaoV2B', 'XiaoV2B Auth']),
  strictClientName: boolEnv('AUTH_BIND_CLIENT_NAME', true),
  gatewayTarget: process.env.AUTH_GATEWAY_TARGET || '',
  licenseKeySecret: process.env.AUTH_LICENSE_KEY_SECRET || '',
  panelClientId: process.env.AUTH_PANEL_CLIENT_ID || 'windows',
  panelClientVersion: process.env.AUTH_PANEL_CLIENT_VERSION || '0.1.0',
  panelSignSecret: process.env.AUTH_PANEL_SIGN_SECRET || [
    'xiao-v2b',
    '-windows-client',
    '@2026-06',
    '-official-signature-v1'
  ].join(''),
  panelRequestTimeoutMs: intEnv('AUTH_PANEL_REQUEST_TIMEOUT_MS', 30000),
  dbDriver: process.env.AUTH_DB_DRIVER || 'mysql',
  db: {
    host: process.env.AUTH_DB_HOST || '127.0.0.1',
    port: intEnv('AUTH_DB_PORT', 3306),
    database: process.env.AUTH_DB_NAME || 'xiaov2b_auth',
    user: process.env.AUTH_DB_USER || 'xiaov2b_auth',
    password: process.env.AUTH_DB_PASSWORD || '',
    connectionLimit: intEnv('AUTH_DB_CONNECTION_LIMIT', 10)
  },
  panelsConfigPath,
  panelsConfig: jsonFile(panelsConfigPath, {}),
  panelUrl: process.env.AUTH_PANEL_URL || '',
  privateKeyPath: path.join(ROOT_DIR, 'data', 'license-private.pem'),
  publicKeyPath: path.join(ROOT_DIR, 'data', 'license-public.pem'),
  statePath: path.join(ROOT_DIR, 'data', 'state.json'),
  auditPath: path.join(ROOT_DIR, 'data', 'audit.log')
};

if (process.env.NODE_ENV === 'production' && !config.adminToken) {
  throw new Error('AUTH_ADMIN_TOKEN must be configured in production');
}

module.exports = config;
