const { app, BrowserWindow, ipcMain, screen, Tray, nativeImage, shell, session } = require('electron');
const path = require('path');
const { execFile, spawn } = require('child_process');
const QRCode = require('qrcode');
const { CoreManager } = require('./main/core-manager');
const { PANEL_URL } = require('./main/app-config');
const panelApi = require('./main/panel-api');
const { buildMihomoProfile } = require('./main/mihomo-profile');
const { setWindowsProxy } = require('./main/system-proxy');
const { getSystemTrafficSnapshot } = require('./main/system-traffic');
const { StateStore } = require('./main/state-store');
const { measureNodeLatency } = require('./main/node-latency');
const { getLocalLocation } = require('./main/local-location');

let mainWindow;
let store;
let core;
let tray;
let trayMenuWindow;
let trayConnecting = false;
const paymentWindows = new Set();
let aspectFitMode = false;
let aspectRestoreBounds = null;
let currentMode = 'rule';
let isQuitting = false;
let ownsSingleInstanceLock = false;
let latestDashboard = null;
let suppressTrayRestoreUntil = 0;
let trayMenuBlurArmedAt = 0;
let trayMenuShownAt = 0;
let trayOutsideClickWatcher = null;

const WINDOW_WIDTH = 1080;
const WINDOW_HEIGHT = 780;
const WINDOW_ASPECT_RATIO = WINDOW_WIDTH / WINDOW_HEIGHT;
const MIN_WINDOW_HEIGHT = 760;
const MIN_WINDOW_WIDTH = Math.round(MIN_WINDOW_HEIGHT * WINDOW_ASPECT_RATIO);
const SINGLE_INSTANCE_WAIT_MS = 12000;
const SINGLE_INSTANCE_RETRY_MS = 300;

const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function configureDirectSession(targetSession) {
  try {
    await targetSession.setProxy({ mode: 'direct' });
  } catch {
    await targetSession.setProxy({ proxyRules: 'direct://' });
  }
}

async function openDirectPaymentWindow(url) {
  const targetUrl = String(url || '');
  if (!/^https?:\/\//i.test(targetUrl)) return;
  const paymentSession = session.fromPartition('payment-direct');
  await configureDirectSession(paymentSession);
  const paymentWindow = new BrowserWindow({
    width: 980,
    height: 720,
    show: true,
    title: 'XiaoV2B 支付',
    webPreferences: {
      partition: 'payment-direct',
      nodeIntegration: false,
      contextIsolation: true
    }
  });
  paymentWindows.add(paymentWindow);
  paymentWindow.on('closed', () => paymentWindows.delete(paymentWindow));
  await paymentWindow.loadURL(targetUrl);
}

async function fetchImageAsDataUrl(url) {
  const targetUrl = String(url || '');
  if (/^data:image\//i.test(targetUrl)) return targetUrl;
  if (!/^https?:\/\//i.test(targetUrl)) return '';
  const response = await fetch(targetUrl, {
    redirect: 'follow',
    headers: {
      'Accept': 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
      'User-Agent': 'XiaoV2BClient/0.1'
    }
  });
  if (!response.ok) return '';
  const contentType = response.headers.get('content-type') || '';
  if (!/^image\//i.test(contentType)) return '';
  const bytes = Buffer.from(await response.arrayBuffer());
  return `data:${contentType.split(';')[0]};base64,${bytes.toString('base64')}`;
}
const TRAY_HEART_IDLE_PNG = 'iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAJcEhZcwAADsMAAA7DAcdvqGQAAAHuSURBVFhH7ZatUwMxEMUrKyuRlUgkf0IlEpUgkUhkHRJZl8hKJLISiaysrETWbZh3s3ezfZdw1+sxIPqbyUyneZf9yGaTyeTCfyb5eCUurMSFjbjwlXxM4sJeXHgXH++tVny8FRfW4sIWOtXiuzXmrLaT5OM0+bgUFw71YrkhLnwkH2dwkud4wBEExLayIEJeoDTqzPQZ4sKu0wnx8THzIdKOdEaNurW40W5VB32zHWZ+zTYbdM+P0i4+PrFOfFywTseyoN2T7o51FSgs8vaFNTUZJ1rGa6CldVesqRAXXsnTa9ZYUN1Vsfq44DnGZkFc+OT5Clt8KC6ePwdx4c2sfeD5CnJgx/PngMLs40Aj0jFlzVDs6SkGh4onB/LVeiJoVnZdbAdrKpKPcxJuWDMEFCoF9sCaBlTokRPU808FQdnjqr/L3TD5eENZwPEZXAu2+jWgVmNrwZcLipM1fUCqaR1ktzsYbcl8yRQ7XY5Mp0T0nQ2rIXsp9UmfdsiW8Z8uoRKZ1oxRrmCtIc6e9oDu1OfINKeiEwXj2PcZa3sDzwsPlGer0z1n43iAzK1uECUn6muVq31U4zXqxNGZVkNHjUv/w0toPOOWQk1Y49VDlb8blUxvr40jQ8Oq/VR434vPrN9EGw56xSjX9oU/4Ruu35FC1hxFaAAAAABJRU5ErkJggg==';
const TRAY_HEART_GLOW_PNGS = [
  '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'
];
let trayGlowTimer = null;
let trayGlowFrame = 0;
let trayTrafficTimer = null;
let lastTrayTrafficSnapshot = null;
let trayGlowIntervalMs = 520;

function notifyCoreStatus(status) {
  if (hasMainWindow()) mainWindow.webContents.send('core:status', status);
  updateTrayIcon(Boolean(status.running));
}

function hasMainWindow() {
  return Boolean(mainWindow && !mainWindow.isDestroyed());
}

function destroyTrayArtifacts() {
  stopTrayTrafficPolling();
  stopTrayOutsideClickWatcher();
  if (trayGlowTimer) {
    clearInterval(trayGlowTimer);
    trayGlowTimer = null;
  }
  if (trayMenuWindow && !trayMenuWindow.isDestroyed()) {
    trayMenuWindow.destroy();
  }
  trayMenuWindow = null;
  if (tray) {
    tray.removeAllListeners();
    tray.destroy();
    tray = null;
  }
}

async function quitApp() {
  isQuitting = true;
  hideTrayMenu();
  await setWindowsProxy(false).catch(() => {});
  if (core) await core.stop().catch(() => {});
  for (const paymentWindow of Array.from(paymentWindows)) {
    if (!paymentWindow.isDestroyed()) paymentWindow.destroy();
  }
  destroyTrayArtifacts();
  app.quit();
}

async function waitForSingleInstanceLock() {
  ownsSingleInstanceLock = app.requestSingleInstanceLock({ replaceExisting: true });
  if (ownsSingleInstanceLock) {
    await terminateSiblingClientProcesses();
    return true;
  }

  const startedAt = Date.now();
  while (Date.now() - startedAt < SINGLE_INSTANCE_WAIT_MS) {
    await delay(SINGLE_INSTANCE_RETRY_MS);
    ownsSingleInstanceLock = app.requestSingleInstanceLock({ replaceExisting: true });
    if (ownsSingleInstanceLock) {
      await terminateSiblingClientProcesses();
      return true;
    }
  }

  app.exit(0);
  return false;
}

function terminateSiblingClientProcesses() {
  if (!app.isPackaged || process.platform !== 'win32') return Promise.resolve();
  const command = [
    `$currentPid = ${process.pid}`,
    "Get-Process | Where-Object { $_.Id -ne $currentPid -and ($_.ProcessName -eq 'XiaoV2B' -or $_.ProcessName -like 'XiaoV2B*') } | Stop-Process -Force -ErrorAction SilentlyContinue"
  ].join('; ');

  return new Promise((resolve) => {
    const child = execFile('powershell.exe', ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-Command', command], {
      windowsHide: true,
      timeout: 3500
    }, () => resolve());
    child.on('error', () => resolve());
  });
}

app.on('second-instance', () => {
  quitApp();
});

const singleInstanceReady = waitForSingleInstanceLock();

function trayIconImage(connected = false, frame = 0) {
  const icon = connected
    ? TRAY_HEART_GLOW_PNGS[frame % TRAY_HEART_GLOW_PNGS.length]
    : TRAY_HEART_IDLE_PNG;
  return nativeImage.createFromBuffer(Buffer.from(icon, 'base64')).resize({ width: 16, height: 16 });
}

function updateTrayIcon(connected = Boolean(core?.process)) {
  if (!tray) return;
  tray.setImage(trayIconImage(connected, trayGlowFrame));
  tray.setToolTip(connected ? 'XiaoV2B - 已连接' : 'XiaoV2B - 未连接');
}

function trafficLevel(bytesPerSecond) {
  if (!Number.isFinite(bytesPerSecond) || bytesPerSecond <= 0) return 1;
  const megabytesPerSecond = bytesPerSecond / 1000 / 1000;
  return Math.max(1, Math.min(50, Math.round(megabytesPerSecond)));
}

async function getTrafficSnapshotForCurrentMode() {
  if (core?.process) return core.getTrafficSnapshot();
  return getSystemTrafficSnapshot();
}

function trayGlowIntervalForLevel(level) {
  return Math.round(Math.max(170, 680 - Number(level || 1) * 9.8));
}

function getAutoLaunchSettings() {
  return app.getLoginItemSettings({
    path: process.execPath
  });
}

function setAutoLaunch(enabled) {
  const openAtLogin = Boolean(enabled);
  app.setLoginItemSettings({
    openAtLogin,
    openAsHidden: false,
    path: process.execPath
  });
  return getAutoLaunchSettings().openAtLogin;
}

function restartTrayGlowTimer(intervalMs = trayGlowIntervalMs) {
  trayGlowIntervalMs = intervalMs;
  if (trayGlowTimer) clearInterval(trayGlowTimer);
  trayGlowTimer = setInterval(() => {
    trayGlowFrame += 1;
    updateTrayIcon(true);
  }, trayGlowIntervalMs);
}

async function pollTrayTraffic() {
  if (!core?.process) return;
  try {
    const snapshot = await getTrafficSnapshotForCurrentMode();
    const now = Date.now();
    const uploadTotal = Number(snapshot.uploadTotal || 0);
    const downloadTotal = Number(snapshot.downloadTotal || 0);
    const source = String(snapshot.source || '');
    const hasRealtimeRate = Number.isFinite(Number(snapshot.uploadRate)) || Number.isFinite(Number(snapshot.downloadRate));

    if (hasRealtimeRate) {
      const totalRate = Math.max(0, Number(snapshot.uploadRate || 0)) + Math.max(0, Number(snapshot.downloadRate || 0));
      const nextInterval = trayGlowIntervalForLevel(trafficLevel(totalRate));
      if (Math.abs(nextInterval - trayGlowIntervalMs) > 28) restartTrayGlowTimer(nextInterval);
    } else if (lastTrayTrafficSnapshot && lastTrayTrafficSnapshot.source === source) {
      const elapsed = Math.max((now - lastTrayTrafficSnapshot.at) / 1000, 0.5);
      const delta = Math.max(0, uploadTotal - lastTrayTrafficSnapshot.uploadTotal) + Math.max(0, downloadTotal - lastTrayTrafficSnapshot.downloadTotal);
      const nextInterval = trayGlowIntervalForLevel(trafficLevel(delta / elapsed));
      if (Math.abs(nextInterval - trayGlowIntervalMs) > 28) restartTrayGlowTimer(nextInterval);
    }

    lastTrayTrafficSnapshot = { uploadTotal, downloadTotal, source, at: now };
  } catch (_error) {
    restartTrayGlowTimer(trayGlowIntervalForLevel(1));
  }
}

function stopTrayTrafficPolling() {
  clearInterval(trayTrafficTimer);
  trayTrafficTimer = null;
  lastTrayTrafficSnapshot = null;
  trayGlowIntervalMs = trayGlowIntervalForLevel(1);
}

function startTrayTrafficPolling() {
  stopTrayTrafficPolling();
  pollTrayTraffic();
  trayTrafficTimer = setInterval(pollTrayTraffic, 1000);
}

function syncTrayGlowAnimation() {
  const connected = Boolean(core?.process);
  if (!tray || !connected) {
    if (trayGlowTimer) {
      clearInterval(trayGlowTimer);
      trayGlowTimer = null;
    }
    trayGlowFrame = 0;
    updateTrayIcon(false);
    stopTrayTrafficPolling();
    return;
  }

  updateTrayIcon(true);
  if (!trayGlowTimer) restartTrayGlowTimer(trayGlowIntervalMs);
  if (!trayTrafficTimer) startTrayTrafficPolling();
}

function restoreWindowFromTray() {
  if (Date.now() < suppressTrayRestoreUntil) return;
  if (!hasMainWindow()) {
    hideTrayMenu();
    if (!isQuitting) destroyTrayArtifacts();
    return;
  }
  hideTrayMenu();
  mainWindow.setSkipTaskbar(false);
  mainWindow.show();
  if (mainWindow.isMinimized()) mainWindow.restore();
  mainWindow.focus();
}

function handleTrayClick(event) {
  if (event?.button && event.button !== 'left') return;
  restoreWindowFromTray();
}

function normalizeMode(mode) {
  return ['rule', 'global', 'auto'].includes(mode) ? mode : 'rule';
}

function notifyModeChanged(result) {
  if (hasMainWindow()) mainWindow.webContents.send('mode:changed', result);
}

function trayState() {
  return {
    mode: currentMode,
    connecting: trayConnecting,
    running: Boolean(core?.process),
    visible: hasMainWindow() && mainWindow.isVisible()
  };
}

function notifyTrayState() {
  syncTrayGlowAnimation();
  if (trayMenuWindow && !trayMenuWindow.isDestroyed()) {
    trayMenuWindow.webContents.send('tray-menu:state', trayState());
  }
}

function getTrayMenuPosition(width, height) {
  const point = screen.getCursorScreenPoint();
  const display = screen.getDisplayNearestPoint(point);
  const area = display.workArea;
  const x = Math.min(Math.max(point.x - width + 16, area.x), area.x + area.width - width);
  const y = Math.min(Math.max(point.y - height, area.y), area.y + area.height - height);
  return { x, y };
}

function createTrayMenuWindow() {
  if (trayMenuWindow && !trayMenuWindow.isDestroyed()) return trayMenuWindow;
  trayMenuWindow = new BrowserWindow({
    width: 260,
    height: 308,
    show: false,
    frame: false,
    resizable: false,
    movable: false,
    skipTaskbar: true,
    alwaysOnTop: true,
    backgroundColor: '#00000000',
    transparent: true,
    webPreferences: {
      preload: path.join(__dirname, 'tray-preload.js'),
      contextIsolation: true,
      nodeIntegration: false
    }
  });
  trayMenuWindow.loadFile(path.join(__dirname, 'renderer', 'tray-menu.html'));
  trayMenuWindow.on('closed', () => {
    stopTrayOutsideClickWatcher();
    trayMenuWindow = null;
  });
  return trayMenuWindow;
}

function hideTrayMenu() {
  stopTrayOutsideClickWatcher();
  if (trayMenuWindow && !trayMenuWindow.isDestroyed()) trayMenuWindow.hide();
}

function isPointInsideBounds(point, bounds, padding = 0) {
  return point.x >= bounds.x - padding
    && point.x <= bounds.x + bounds.width + padding
    && point.y >= bounds.y - padding
    && point.y <= bounds.y + bounds.height + padding;
}

function handleTrayOutsideMouseDown() {
  if (Date.now() < trayMenuShownAt + 220) return;
  if (!trayMenuWindow || trayMenuWindow.isDestroyed() || !trayMenuWindow.isVisible()) return;
  const point = screen.getCursorScreenPoint();
  const bounds = trayMenuWindow.getBounds();
  if (!isPointInsideBounds(point, bounds, 4)) hideTrayMenu();
}

function startTrayOutsideClickWatcher() {
  if (trayOutsideClickWatcher || process.platform !== 'win32') return;
  const script = [
    'Add-Type -TypeDefinition @\'',
    'using System;',
    'using System.Runtime.InteropServices;',
    'public static class MouseHookLite {',
    '  [DllImport("user32.dll")] public static extern short GetAsyncKeyState(int vKey);',
    '}',
    '\'@',
    '$lastLeft = 0',
    '$lastRight = 0',
    'while ($true) {',
    '  $left = [MouseHookLite]::GetAsyncKeyState(0x01)',
    '  $right = [MouseHookLite]::GetAsyncKeyState(0x02)',
    '  if (($left -band 0x8000) -and -not ($lastLeft -band 0x8000)) { [Console]::Out.WriteLine("left"); [Console]::Out.Flush() }',
    '  if (($right -band 0x8000) -and -not ($lastRight -band 0x8000)) { [Console]::Out.WriteLine("right"); [Console]::Out.Flush() }',
    '  $lastLeft = $left',
    '  $lastRight = $right',
    '  Start-Sleep -Milliseconds 28',
    '}'
  ].join('\n');

  trayOutsideClickWatcher = spawn('powershell.exe', [
    '-NoProfile',
    '-ExecutionPolicy',
    'Bypass',
    '-Command',
    script
  ], {
    windowsHide: true,
    stdio: ['ignore', 'pipe', 'ignore']
  });

  trayOutsideClickWatcher.stdout.on('data', (chunk) => {
    if (String(chunk).trim()) handleTrayOutsideMouseDown();
  });
  trayOutsideClickWatcher.on('error', () => {
    trayOutsideClickWatcher = null;
  });
  trayOutsideClickWatcher.on('exit', () => {
    trayOutsideClickWatcher = null;
  });
}

function stopTrayOutsideClickWatcher() {
  if (!trayOutsideClickWatcher) return;
  const watcher = trayOutsideClickWatcher;
  trayOutsideClickWatcher = null;
  watcher.kill();
}

function showTrayMenu() {
  if (isQuitting) return;
  suppressTrayRestoreUntil = Date.now() + 350;
  trayMenuBlurArmedAt = Date.now() + 260;
  trayMenuShownAt = Date.now();
  const menu = createTrayMenuWindow();
  const bounds = getTrayMenuPosition(260, 336);
  menu.setBounds({ ...bounds, width: 260, height: 336 });
  menu.webContents.send('tray-menu:state', trayState());
  if (menu.isVisible()) {
    menu.moveTop();
    startTrayOutsideClickWatcher();
    return;
  }
  if (typeof menu.showInactive === 'function') {
    menu.showInactive();
  } else {
    menu.show();
  }
  startTrayOutsideClickWatcher();
}

function hasUsableDashboard(dashboard) {
  return Boolean(dashboard?.user?.uuid && Array.isArray(dashboard?.servers) && dashboard.servers.length);
}

async function fetchDashboardAndRemember(state = null) {
  const currentState = state || await store.read();
  latestDashboard = await panelApi.fetchDashboard(currentState);
  return latestDashboard;
}

async function refreshDashboardForModeSwitch() {
  const state = await store.read();
  if (!state.auth || !state.baseUrl) throw new Error('请先登录客户端');
  return fetchDashboardAndRemember(state);
}

async function getDashboardForCore() {
  if (hasUsableDashboard(latestDashboard)) return latestDashboard;
  const state = await store.read();
  if (!state.auth || !state.baseUrl) throw new Error('请先登录客户端');
  return fetchDashboardAndRemember(state);
}

async function startCoreWithPanelProfile(notifyProgress = () => {}) {
  notifyProgress({ value: 18 });
  let content;
  try {
    const dashboard = await getDashboardForCore();
    notifyProgress({ value: 42 });
    content = buildMihomoProfile(dashboard);
  } catch (error) {
    const message = String(error.message || '');
    throw new Error(message.startsWith('配置生成失败') ? message : `配置生成失败：${message}`);
  }
  notifyProgress({ value: 52 });
  let profilePath;
  try {
    profilePath = await core.writeProfile(content);
  } catch (error) {
    throw new Error(`内核配置写入失败：${error.message}`);
  }
  notifyProgress({ value: 72 });
  let result;
  try {
    result = await core.start(profilePath);
  } catch (error) {
    throw new Error(`代理内核启动失败：${error.message}`);
  }
  notifyProgress({ value: 82 });
  try {
    await setWindowsProxy(true);
  } catch (error) {
    await core.stop().catch(() => {});
    throw new Error(`系统代理启动失败：${error.message}`);
  }
  notifyProgress({ value: 86 });
  return { ...result, profilePath };
}

function ensureTray() {
  if (tray) return tray;
  tray = new Tray(trayIconImage(false));
  updateTrayIcon(Boolean(core?.process));
  tray.on('click', handleTrayClick);
  tray.on('right-click', showTrayMenu);
  syncTrayGlowAnimation();
  return tray;
}

function hideWindowToTray() {
  if (!hasMainWindow()) return;
  ensureTray();
  mainWindow.setSkipTaskbar(true);
  mainWindow.hide();
}

function fitBoundsToAspect(bounds, previousBounds) {
  const widthDelta = previousBounds ? Math.abs(bounds.width - previousBounds.width) : bounds.width;
  const heightDelta = previousBounds ? Math.abs(bounds.height - previousBounds.height) : bounds.height;
  const resizedByWidth = widthDelta >= heightDelta;
  const next = { ...bounds };

  if (resizedByWidth) {
    next.height = Math.round(next.width / WINDOW_ASPECT_RATIO);
  } else {
    next.width = Math.round(next.height * WINDOW_ASPECT_RATIO);
  }

  if (next.height < MIN_WINDOW_HEIGHT) {
    next.height = MIN_WINDOW_HEIGHT;
    next.width = MIN_WINDOW_WIDTH;
  }
  if (next.width < MIN_WINDOW_WIDTH) {
    next.width = MIN_WINDOW_WIDTH;
    next.height = MIN_WINDOW_HEIGHT;
  }

  if (previousBounds && bounds.x !== previousBounds.x) {
    next.x = bounds.x + bounds.width - next.width;
  }
  if (previousBounds && bounds.y !== previousBounds.y) {
    next.y = bounds.y + bounds.height - next.height;
  }

  return next;
}

function fitToWorkArea(bounds) {
  let width = bounds.width;
  let height = Math.round(width / WINDOW_ASPECT_RATIO);
  if (height > bounds.height) {
    height = bounds.height;
    width = Math.round(height * WINDOW_ASPECT_RATIO);
  }

  return {
    x: bounds.x + Math.round((bounds.width - width) / 2),
    y: bounds.y + Math.round((bounds.height - height) / 2),
    width,
    height
  };
}

function lockWindowAspectRatio(win) {
  let applyingBounds = false;
  let lastBounds = win.getBounds();

  win.setAspectRatio(WINDOW_ASPECT_RATIO);
  win.on('resize', () => {
    if (applyingBounds || win.isFullScreen()) return;
    const bounds = win.getBounds();
    const ratioDiff = Math.abs((bounds.width / bounds.height) - WINDOW_ASPECT_RATIO);
    if (ratioDiff < 0.003) {
      lastBounds = bounds;
      return;
    }

    applyingBounds = true;
    const nextBounds = fitBoundsToAspect(bounds, lastBounds);
    win.setBounds(nextBounds);
    lastBounds = nextBounds;
    applyingBounds = false;
  });

  win.on('move', () => {
    if (!applyingBounds) lastBounds = win.getBounds();
  });
}

function createWindow() {
  mainWindow = new BrowserWindow({
    width: WINDOW_WIDTH,
    height: WINDOW_HEIGHT,
    minWidth: MIN_WINDOW_WIDTH,
    minHeight: MIN_WINDOW_HEIGHT,
    title: 'XiaoV2B',
    frame: false,
    maximizable: false,
    backgroundColor: '#00000000',
    transparent: true,
    hasShadow: true,
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false
    }
  });
  lockWindowAspectRatio(mainWindow);
  mainWindow.on('close', (event) => {
    if (isQuitting) return;
    event.preventDefault();
    hideWindowToTray();
  });
  mainWindow.on('closed', () => {
    mainWindow = null;
    aspectFitMode = false;
    aspectRestoreBounds = null;
    if (isQuitting) destroyTrayArtifacts();
  });
  mainWindow.loadFile(path.join(__dirname, 'renderer', 'index.html'));
}

app.whenReady().then(async () => {
  const hasSingleInstanceLock = await singleInstanceReady;
  if (!hasSingleInstanceLock) return;
  store = new StateStore(app);
  core = new CoreManager(app, notifyCoreStatus);
  createWindow();
  ensureTray();
});

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin' && !isQuitting) {
    quitApp();
  }
});

app.on('before-quit', () => {
  isQuitting = true;
  destroyTrayArtifacts();
});

ipcMain.handle('auth:restore', async () => {
  const state = await store.read();
  if (!state.auth || !state.baseUrl) return { authenticated: false };
  if (typeof state.auth !== 'string') {
    await store.clear();
    return { authenticated: false };
  }
  return { authenticated: true, dashboard: await fetchDashboardAndRemember(state) };
});

ipcMain.handle('auth:status', async () => {
  const state = await store.read();
  if (!state.auth || !state.baseUrl) return { authenticated: false };
  if (typeof state.auth !== 'string') {
    await store.clear();
    return { authenticated: false };
  }
  return { authenticated: true };
});

ipcMain.handle('auth:login', async (_event, credentials) => {
  const state = await panelApi.login(PANEL_URL, String(credentials?.email || '').trim(), String(credentials?.password || ''));
  await store.write(state);
  return { authenticated: true, dashboard: await fetchDashboardAndRemember(state) };
});

ipcMain.handle('auth:logout', async () => {
  await core.stop();
  await store.clear();
  latestDashboard = null;
  return { authenticated: false };
});

ipcMain.handle('dashboard:refresh', async () => {
  return fetchDashboardAndRemember(await store.read());
});

ipcMain.handle('core:connect', async (event) => {
  const result = await startCoreWithPanelProfile((progress) => event.sender.send('core:progress', progress));
  notifyTrayState();
  return result;
});

ipcMain.handle('core:disconnect', async () => {
  await setWindowsProxy(false).catch(() => {});
  const result = await core.stop();
  notifyTrayState();
  return result;
});

ipcMain.handle('core:setMode', async (_event, mode) => {
  const nextMode = normalizeMode(mode);
  await refreshDashboardForModeSwitch();
  currentMode = nextMode;
  const result = await core.setMode(currentMode);
  notifyTrayState();
  return { ...result, coreMode: result.mode, mode: currentMode };
});

ipcMain.handle('core:selectProxy', async (_event, proxyName) => {
  return core.selectProxy(proxyName);
});

ipcMain.handle('node:latency', async (_event, nodes) => {
  return measureNodeLatency(Array.isArray(nodes) ? nodes : []);
});

ipcMain.handle('mode:sync', async (_event, mode) => {
  currentMode = normalizeMode(mode);
  notifyTrayState();
  return { mode: currentMode };
});

ipcMain.handle('tray:getState', async () => trayState());

ipcMain.handle('tray:setMode', async (_event, mode) => {
  const nextMode = normalizeMode(mode);
  currentMode = nextMode;
  notifyModeChanged({ mode: currentMode, applied: false, pending: true });
  notifyTrayState();

  let result;
  try {
    await refreshDashboardForModeSwitch();
    result = await core.setMode(currentMode);
    result = { ...result, coreMode: result.mode, mode: currentMode };
  } catch (error) {
    result = { mode: currentMode, applied: false, error: error.message };
  }
  notifyModeChanged(result);
  notifyTrayState();
  return { ...result, running: Boolean(core?.process) };
});

ipcMain.handle('tray:connect', async () => {
  if (trayConnecting) return trayState();
  if (core?.process) return { ...trayState(), running: true };

  trayConnecting = true;
  notifyTrayState();
  try {
    const state = await store.read();
    if (!state.auth || !state.baseUrl) throw new Error('请先登录客户端');
    await fetchDashboardAndRemember(state);

    const result = await startCoreWithPanelProfile((progress) => {
      if (hasMainWindow()) mainWindow.webContents.send('core:progress', progress);
    });
    let modeResult;
    try {
      modeResult = await core.setMode(currentMode);
      modeResult = { ...modeResult, coreMode: modeResult.mode, mode: currentMode };
    } catch (error) {
      modeResult = { mode: currentMode, applied: false, error: error.message };
    }
    if (modeResult.error) notifyModeChanged(modeResult);
    return { ...result, ...modeResult, running: true };
  } catch (error) {
    return { ...trayState(), error: error.message };
  } finally {
    trayConnecting = false;
    notifyTrayState();
  }
});

ipcMain.handle('tray:disconnect', async () => {
  await setWindowsProxy(false).catch(() => {});
  const result = await core.stop();
  notifyTrayState();
  return result;
});

ipcMain.handle('tray:traffic', async () => {
  return getTrafficSnapshotForCurrentMode();
});

ipcMain.handle('tray:open', async () => {
  restoreWindowFromTray();
});

ipcMain.handle('tray:hide', async () => {
  hideTrayMenu();
});

ipcMain.handle('tray:closeWindow', async () => {
  hideTrayMenu();
  hideWindowToTray();
  notifyTrayState();
  return trayState();
});

ipcMain.handle('tray:exit', async () => {
  await quitApp();
});

ipcMain.handle('core:traffic', async () => {
  return getTrafficSnapshotForCurrentMode();
});

ipcMain.handle('network:localLocation', async () => {
  return getLocalLocation();
});

ipcMain.handle('settings:getStartup', async () => {
  return { enabled: getAutoLaunchSettings().openAtLogin };
});

ipcMain.handle('settings:setStartup', async (_event, enabled) => {
  return { enabled: setAutoLaunch(enabled) };
});

ipcMain.handle('order:create', async (_event, payload) => {
  return panelApi.request(await store.read(), '/user/order/save', {
    method: 'POST',
    body: payload
  });
});

ipcMain.handle('coupon:check', async (_event, payload) => {
  return panelApi.request(await store.read(), '/user/coupon/check', {
    method: 'POST',
    body: payload
  });
});

ipcMain.handle('order:detail', async (_event, tradeNo) => {
  return panelApi.request(await store.read(), `/user/order/detail?trade_no=${encodeURIComponent(tradeNo)}`);
});

ipcMain.handle('order:paymentMethods', async () => {
  return panelApi.request(await store.read(), '/user/order/getPaymentMethod');
});

ipcMain.handle('order:checkout', async (_event, payload) => {
  return panelApi.request(await store.read(), '/user/order/checkout', {
    method: 'POST',
    body: payload
  });
});

ipcMain.handle('order:check', async (_event, tradeNo) => {
  return panelApi.request(await store.read(), `/user/order/check?trade_no=${encodeURIComponent(tradeNo)}`);
});

ipcMain.handle('order:cancel', async (_event, tradeNo) => {
  return panelApi.request(await store.read(), '/user/order/cancel', {
    method: 'POST',
    body: { trade_no: tradeNo }
  });
});

ipcMain.handle('qr:encode', async (_event, value) => {
  return QRCode.toDataURL(String(value || ''), {
    errorCorrectionLevel: 'M',
    margin: 1,
    width: 220,
    color: {
      dark: '#2a2025',
      light: '#ffffff'
    }
  });
});

ipcMain.handle('payment:imageDataUrl', async (_event, url) => {
  return fetchImageAsDataUrl(url);
});

ipcMain.handle('external:open', async (_event, url) => {
  try {
    await openDirectPaymentWindow(url);
  } catch {
    await shell.openExternal(String(url || ''));
  }
});

ipcMain.handle('window:minimize', () => {
  if (hasMainWindow()) mainWindow.minimize();
});

ipcMain.handle('window:hideToTray', () => {
  hideWindowToTray();
});

ipcMain.handle('window:maximize', () => {
  if (!hasMainWindow()) return;
  if (aspectFitMode && aspectRestoreBounds) {
    mainWindow.setBounds(aspectRestoreBounds);
    aspectFitMode = false;
    aspectRestoreBounds = null;
    return;
  }

  aspectRestoreBounds = mainWindow.getBounds();
  const display = screen.getDisplayMatching(aspectRestoreBounds);
  mainWindow.setBounds(fitToWorkArea(display.workArea));
  aspectFitMode = true;
});
