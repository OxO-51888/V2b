const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('trayMenu', {
  getState: () => ipcRenderer.invoke('tray:getState'),
  connect: () => ipcRenderer.invoke('tray:connect'),
  disconnect: () => ipcRenderer.invoke('tray:disconnect'),
  setMode: (mode) => ipcRenderer.invoke('tray:setMode', mode),
  getTraffic: () => ipcRenderer.invoke('tray:traffic'),
  open: () => ipcRenderer.invoke('tray:open'),
  hide: () => ipcRenderer.invoke('tray:hide'),
  closeWindow: () => ipcRenderer.invoke('tray:closeWindow'),
  exit: () => ipcRenderer.invoke('tray:exit'),
  onState: (callback) => ipcRenderer.on('tray-menu:state', (_event, state) => callback(state))
});
