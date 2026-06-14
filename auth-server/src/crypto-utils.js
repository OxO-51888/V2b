const crypto = require('crypto');
const fs = require('fs');

function ensureKeyPair(config) {
  fs.mkdirSync(config.dataDir, { recursive: true });
  if (fs.existsSync(config.privateKeyPath) && fs.existsSync(config.publicKeyPath)) {
    return {
      privateKey: fs.readFileSync(config.privateKeyPath, 'utf8'),
      publicKey: fs.readFileSync(config.publicKeyPath, 'utf8')
    };
  }

  const { privateKey, publicKey } = crypto.generateKeyPairSync('ed25519');
  const privatePem = privateKey.export({ type: 'pkcs8', format: 'pem' });
  const publicPem = publicKey.export({ type: 'spki', format: 'pem' });
  fs.writeFileSync(config.privateKeyPath, privatePem, { mode: 0o600 });
  fs.writeFileSync(config.publicKeyPath, publicPem);
  return {
    privateKey: privatePem,
    publicKey: publicPem
  };
}

function base64Url(buffer) {
  return Buffer.from(buffer)
    .toString('base64')
    .replace(/\+/g, '-')
    .replace(/\//g, '_')
    .replace(/=+$/g, '');
}

function sha256(value) {
  return crypto.createHash('sha256').update(String(value)).digest('hex');
}

function randomToken(bytes = 32) {
  return base64Url(crypto.randomBytes(bytes));
}

function randomLicenseCode() {
  const raw = crypto.randomBytes(15).toString('base64url').toUpperCase().replace(/[^A-Z0-9]/g, '');
  const body = raw.padEnd(20, 'X').slice(0, 20).match(/.{1,4}/g).join('-');
  return `XV2B-${body}`;
}

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

function signLicense(privateKey, license) {
  const payload = canonicalJson(license);
  return base64Url(crypto.sign(null, Buffer.from(payload), privateKey));
}

module.exports = {
  base64Url,
  canonicalJson,
  ensureKeyPair,
  randomLicenseCode,
  randomToken,
  sha256,
  signLicense
};
