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
  if (fs.existsSync(config.privateKeyPath) || fs.existsSync(config.publicKeyPath)) {
    throw new Error('Incomplete gateway signing key pair; refusing to rotate keys');
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

function signCanonical(privateKey, payload) {
  return base64Url(crypto.sign(null, Buffer.from(canonicalJson(payload)), privateKey));
}

function encryptSecurePayload(clientPublicKey, payload, signingPrivateKey) {
  const aesKey = crypto.randomBytes(32);
  const iv = crypto.randomBytes(12);
  const aad = Buffer.from('appgate-secure-auth-v1');
  const cipher = crypto.createCipheriv('aes-256-gcm', aesKey, iv);
  cipher.setAAD(aad);
  const encryptedPayload = Buffer.concat([
    cipher.update(Buffer.from(JSON.stringify(payload), 'utf8')),
    cipher.final()
  ]);
  const encryptedKey = crypto.publicEncrypt({
    key: clientPublicKey,
    padding: crypto.constants.RSA_PKCS1_OAEP_PADDING,
    oaepHash: 'sha256'
  }, aesKey);

  const envelope = {
    ok: true,
    secure: true,
    version: 1,
    alg: 'RSA-OAEP-SHA256+A256GCM+Ed25519',
    aad: base64Url(aad),
    key: base64Url(encryptedKey),
    iv: base64Url(iv),
    tag: base64Url(cipher.getAuthTag()),
    payload: base64Url(encryptedPayload),
    issuedAt: new Date().toISOString()
  };
  return {
    ...envelope,
    signature: signCanonical(signingPrivateKey, envelope)
  };
}

module.exports = {
  base64Url,
  canonicalJson,
  encryptSecurePayload,
  ensureKeyPair,
  randomToken,
  sha256,
  signCanonical,
  signLicense
};
