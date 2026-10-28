const { DatabaseSync } = require('node:sqlite');
const fs = require('node:fs');
const path = require('node:path');

// This database is private to the new gateway, never the panel's customer database.
class Store {
  constructor(config) {
    fs.mkdirSync(config.dataDir, { recursive: true, mode: 0o700 });
    this.db = new DatabaseSync(path.join(config.dataDir, 'gateway.sqlite3'));
    this.tail = Promise.resolve();
  }

  async init() {
    this.db.exec(`PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000;
      CREATE TABLE IF NOT EXISTS devices (id TEXT PRIMARY KEY, data TEXT NOT NULL);
      CREATE TABLE IF NOT EXISTS sessions (id TEXT PRIMARY KEY, device_id TEXT NOT NULL, expires_at TEXT NOT NULL, data TEXT NOT NULL);
      CREATE INDEX IF NOT EXISTS session_expiry ON sessions(expires_at);
      CREATE TABLE IF NOT EXISTS panel_overrides (id TEXT PRIMARY KEY, data TEXT NOT NULL);
      CREATE TABLE IF NOT EXISTS audit_log (id INTEGER PRIMARY KEY, event TEXT NOT NULL, payload TEXT NOT NULL, created_at TEXT NOT NULL);`);
  }

  execute(fn, conn) {
    if (conn === this.db) return Promise.resolve().then(fn);
    const result = this.tail.then(fn);
    this.tail = result.catch(() => {});
    return result;
  }

  withTransaction(fn) {
    return this.execute(async () => {
      this.db.exec('BEGIN IMMEDIATE');
      try {
        const result = await fn(this.db);
        this.db.exec('COMMIT');
        return result;
      } catch (error) {
        this.db.exec('ROLLBACK');
        throw error;
      }
    });
  }

  readPanelState(conn) {
    return this.execute(() => ({ version: 1, devices: {}, sessions: {},
      panelOverrides: Object.fromEntries(this.db.prepare('SELECT id,data FROM panel_overrides').all().map(r => [r.id, JSON.parse(r.data)]))
    }), conn);
  }

  getDevice(id, conn) {
    return this.execute(() => {
      const row = this.db.prepare('SELECT data FROM devices WHERE id=?').get(id);
      return row ? JSON.parse(row.data) : null;
    }, conn);
  }

  saveDevice(device, conn) {
    return this.execute(() => {
      const data = { ...device };
      delete data.deviceName;
      delete data.os;
      this.db.prepare('INSERT INTO devices VALUES (?,?) ON CONFLICT(id) DO UPDATE SET data=excluded.data').run(data.deviceId, JSON.stringify(data));
    }, conn);
  }

  getSession(id, conn) {
    return this.execute(() => {
      const row = this.db.prepare('SELECT data FROM sessions WHERE id=?').get(id);
      return row ? JSON.parse(row.data) : null;
    }, conn);
  }

  saveSession(session, conn) {
    return this.execute(() => this.db.prepare('INSERT INTO sessions VALUES (?,?,?,?) ON CONFLICT(id) DO UPDATE SET device_id=excluded.device_id,expires_at=excluded.expires_at,data=excluded.data')
      .run(session.tokenHash, session.deviceId, session.expiresAt, JSON.stringify(session)), conn);
  }

  deleteSession(id, conn) {
    return this.execute(() => this.db.prepare('DELETE FROM sessions WHERE id=?').run(id), conn);
  }

  pruneExpiredSessions(time = Date.now(), conn) {
    return this.execute(() => this.db.prepare('DELETE FROM sessions WHERE expires_at<=?').run(new Date(time).toISOString()), conn);
  }

  getOnlineDeviceIds(time = Date.now(), conn) {
    return this.execute(() => new Set(this.db.prepare('SELECT DISTINCT device_id FROM sessions WHERE expires_at>?').all(new Date(time).toISOString()).map(r => r.device_id)), conn);
  }

  savePanelOverride(id, override, conn) {
    return this.execute(() => this.db.prepare('INSERT INTO panel_overrides VALUES (?,?) ON CONFLICT(id) DO UPDATE SET data=excluded.data').run(id, JSON.stringify(override)), conn);
  }

  audit(event, payload = {}, conn) {
    return this.execute(() => this.db.prepare('INSERT INTO audit_log(event,payload,created_at) VALUES (?,?,?)').run(event, JSON.stringify(payload), new Date().toISOString()), conn);
  }

  listDevices(offset = 0, limit = 30) {
    return this.execute(() => ({
      total: this.db.prepare('SELECT COUNT(*) AS n FROM devices').get().n,
      items: this.db.prepare('SELECT data FROM devices ORDER BY rowid DESC LIMIT ? OFFSET ?').all(limit, offset).map(r => {
        const d = JSON.parse(r.data);
        return { deviceId: d.deviceId, clientName: d.clientName, userEmail: d.userEmail,
          version: d.version, status: d.status, firstSeenAt: d.firstSeenAt, lastSeenAt: d.lastSeenAt };
      })
    }));
  }
}

module.exports = { Store };
