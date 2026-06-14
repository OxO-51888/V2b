const CLIENT_ID = 'windows';
const AUTH_CLIENT_ID = 'windows-auth';
const AUTH_CLIENT_NAME = '\u4fbf\u5b9c\u673a\u573a';
const CLIENT_VERSION = '0.1.0';
const AUTH_SERVER_URL = process.env.XIAOV2B_AUTH_SERVER_URL || 'https://5188777.xyz';
const AUTH_CORE_SHA256 = 'ba852f76773bed45ba7a3a3ffe093b336cd2fb816697b52a4e67904c953466e2';
const AUTH_LICENSE_PUBLIC_KEY = [
  '-----BEGIN PUBLIC KEY-----',
  'MCowBQYDK2VwAyEAbicAe0hvZe/EH5VKh1cVUqm0oaehwQKf38nH6NsbmlA=',
  '-----END PUBLIC KEY-----'
].join('\n');

module.exports = {
  CLIENT_ID,
  AUTH_CLIENT_ID,
  AUTH_CLIENT_NAME,
  CLIENT_VERSION,
  AUTH_SERVER_URL,
  AUTH_CORE_SHA256,
  AUTH_LICENSE_PUBLIC_KEY
};
