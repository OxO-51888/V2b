const { execFile } = require('child_process');

function parseNetstatEthernet(stdout) {
  const lines = String(stdout || '').split(/\r?\n/);

  for (const line of lines) {
    const parts = line.trim().split(/\s+/);
    const numbers = parts.filter((part) => /^\d+$/.test(part)).map(Number);
    if (numbers.length >= 2 && !/packets|discards|errors|protocols/i.test(line)) {
      return {
        downloadTotal: numbers[0],
        uploadTotal: numbers[1]
      };
    }
  }

  throw new Error('本机网络速率读取失败');
}

function parseAdapterStatistics(stdout) {
  const text = String(stdout || '').trim();
  if (!text) throw new Error('本机网络速率读取失败');

  const parsed = JSON.parse(text);
  const adapters = Array.isArray(parsed) ? parsed : [parsed];
  const totals = adapters.reduce((sum, adapter) => {
    return {
      downloadTotal: sum.downloadTotal + Math.max(0, Number(adapter.ReceivedBytes || 0)),
      uploadTotal: sum.uploadTotal + Math.max(0, Number(adapter.SentBytes || 0))
    };
  }, { downloadTotal: 0, uploadTotal: 0 });

  if (totals.downloadTotal <= 0 && totals.uploadTotal <= 0) {
    throw new Error('本机网络速率读取失败');
  }

  return totals;
}

function getPowerShellTrafficSnapshot() {
  const command = [
    "$ErrorActionPreference = 'Stop'",
    "$adapters = Get-NetAdapter | Where-Object { $_.Status -eq 'Up' -and $_.HardwareInterface }",
    "if (-not $adapters) {",
    "  $adapters = Get-NetAdapter | Where-Object { $_.Status -eq 'Up' -and $_.InterfaceDescription -notmatch 'Loopback|Virtual|TAP|TUN|VPN|Hyper-V|VMware|VirtualBox|Bluetooth' }",
    '}',
    "$stats = $adapters | Get-NetAdapterStatistics | Select-Object Name,ReceivedBytes,SentBytes",
    '$stats | ConvertTo-Json -Compress'
  ].join('; ');

  return new Promise((resolve, reject) => {
    execFile('powershell.exe', ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-Command', command], {
      windowsHide: true,
      timeout: 2500
    }, (error, stdout) => {
      if (error) {
        reject(error);
        return;
      }

      try {
        resolve({
          running: true,
          source: 'system-adapter',
          ...parseAdapterStatistics(stdout)
        });
      } catch (parseError) {
        reject(parseError);
      }
    });
  });
}

function getNetstatTrafficSnapshot() {
  return new Promise((resolve, reject) => {
    execFile('netstat.exe', ['-e'], { windowsHide: true, timeout: 1500 }, (error, stdout) => {
      if (error) {
        reject(error);
        return;
      }

      try {
        resolve({
          running: true,
          source: 'system-netstat',
          ...parseNetstatEthernet(stdout)
        });
      } catch (parseError) {
        reject(parseError);
      }
    });
  });
}

async function getSystemTrafficSnapshot() {
  try {
    return await getPowerShellTrafficSnapshot();
  } catch (_error) {
    return getNetstatTrafficSnapshot();
  }
}

module.exports = { getSystemTrafficSnapshot };
