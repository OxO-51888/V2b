const { execFile } = require('child_process');

function setWindowsProxy(enabled, host = '127.0.0.1', port = '7890') {
  const internetSettings = 'HKCU\\Software\\Microsoft\\Windows\\CurrentVersion\\Internet Settings';
  const commands = enabled
    ? [
        ['add', internetSettings, '/v', 'ProxyEnable', '/t', 'REG_DWORD', '/d', '1', '/f'],
        ['add', internetSettings, '/v', 'ProxyServer', '/t', 'REG_SZ', '/d', `${host}:${port}`, '/f']
      ]
    : [
        ['add', internetSettings, '/v', 'ProxyEnable', '/t', 'REG_DWORD', '/d', '0', '/f']
      ];

  return Promise.all(commands.map((args) => new Promise((resolve, reject) => {
    execFile('reg.exe', args, { windowsHide: true, timeout: 2500 }, (error) => {
      if (error) reject(error);
      else resolve();
    });
  })));
}

module.exports = { setWindowsProxy };
