const mysql = require('mysql2/promise');

function initialState() {
  return {
    version: 1,
    devices: {},
    panelOverrides: {},
    sessions: {}
  };
}

function parseJson(value, fallback = {}) {
  try {
    return value ? JSON.parse(value) : fallback;
  } catch {
    return fallback;
  }
}

function toMysqlDate(value) {
  if (!value) return null;
  const date = value instanceof Date ? value : new Date(value);
  if (!Number.isFinite(date.getTime())) return null;
  return date.toISOString().slice(0, 19).replace('T', ' ');
}

function fromMysqlDate(value) {
  if (!value) return '';
  if (value instanceof Date) return value.toISOString();
  const text = String(value);
  return text.includes('T') ? text : `${text.replace(' ', 'T')}Z`;
}

class Store {
  constructor(config) {
    this.config = config;
    if (config.dbDriver !== 'mysql') {
      throw new Error(`Unsupported AUTH_DB_DRIVER: ${config.dbDriver}`);
    }
    this.pool = mysql.createPool({
      host: config.db.host,
      port: config.db.port,
      database: config.db.database,
      user: config.db.user,
      password: config.db.password,
      waitForConnections: true,
      connectionLimit: config.db.connectionLimit,
      charset: 'utf8mb4'
    });
  }

  async init() {
    await this.pool.query(`
      CREATE TABLE IF NOT EXISTS auth_state (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        data LONGTEXT NOT NULL,
        updated_at DATETIME NOT NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    `);

    await this.pool.query(`
      CREATE TABLE IF NOT EXISTS auth_devices (
        device_id VARCHAR(180) NOT NULL PRIMARY KEY,
        status VARCHAR(32) NOT NULL,
        panel_id VARCHAR(80) NOT NULL DEFAULT '',
        user_email VARCHAR(160) NOT NULL DEFAULT '',
        local_ip VARCHAR(80) NOT NULL DEFAULT '',
        request_ip VARCHAR(80) NOT NULL DEFAULT '',
        client_id VARCHAR(64) NOT NULL DEFAULT '',
        client_name VARCHAR(120) NOT NULL DEFAULT '',
        runtime_name VARCHAR(120) NOT NULL DEFAULT '',
        version VARCHAR(32) NOT NULL DEFAULT '',
        first_seen_at DATETIME NULL,
        last_seen_at DATETIME NULL,
        device_expires_at DATETIME NULL,
        data LONGTEXT NOT NULL,
        updated_at DATETIME NOT NULL,
        KEY idx_status (status),
        KEY idx_panel_id (panel_id),
        KEY idx_user_email (user_email),
        KEY idx_last_seen_at (last_seen_at),
        KEY idx_device_expires_at (device_expires_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    `);

    await this.pool.query(`
      CREATE TABLE IF NOT EXISTS auth_sessions (
        token_hash CHAR(64) NOT NULL PRIMARY KEY,
        device_id VARCHAR(180) NOT NULL,
        client_id VARCHAR(64) NOT NULL DEFAULT '',
        panel_id VARCHAR(80) NOT NULL DEFAULT '',
        issued_at DATETIME NULL,
        expires_at DATETIME NOT NULL,
        data LONGTEXT NOT NULL,
        updated_at DATETIME NOT NULL,
        KEY idx_device_id (device_id),
        KEY idx_panel_id (panel_id),
        KEY idx_expires_at (expires_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    `);

    await this.pool.query(`
      CREATE TABLE IF NOT EXISTS auth_panel_overrides (
        panel_id VARCHAR(80) NOT NULL PRIMARY KEY,
        name VARCHAR(120) NOT NULL DEFAULT '',
        status VARCHAR(32) NOT NULL DEFAULT '',
        gateway_target VARCHAR(220) NOT NULL DEFAULT '',
        allowed_client_names LONGTEXT NOT NULL,
        data LONGTEXT NOT NULL,
        updated_at DATETIME NOT NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    `);

    await this.pool.query(`
      CREATE TABLE IF NOT EXISTS audit_log (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        event VARCHAR(120) NOT NULL,
        payload LONGTEXT NOT NULL,
        created_at DATETIME NOT NULL,
        KEY idx_event (event),
        KEY idx_created_at (created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    `);

    await this.migrateLegacyState();
  }

  async withTransaction(fn) {
    const conn = await this.pool.getConnection();
    try {
      await conn.beginTransaction();
      const result = await fn(conn);
      await conn.commit();
      return result;
    } catch (error) {
      await conn.rollback();
      throw error;
    } finally {
      conn.release();
    }
  }

  async migrateLegacyState() {
    const [[deviceCount]] = await this.pool.query('SELECT COUNT(*) AS count FROM auth_devices');
    const [[sessionCount]] = await this.pool.query('SELECT COUNT(*) AS count FROM auth_sessions');
    const [[overrideCount]] = await this.pool.query('SELECT COUNT(*) AS count FROM auth_panel_overrides');
    if ((deviceCount.count || 0) + (sessionCount.count || 0) + (overrideCount.count || 0) > 0) return;

    const [rows] = await this.pool.query('SELECT data FROM auth_state WHERE id = 1 LIMIT 1');
    if (!rows.length) return;
    const state = this.normalize(parseJson(rows[0].data, initialState()));
    await this.withTransaction(async (conn) => {
      for (const device of Object.values(state.devices)) {
        await this.saveDevice(device, conn);
      }
      for (const session of Object.values(state.sessions)) {
        await this.saveSession(session, conn);
      }
      for (const [panelId, override] of Object.entries(state.panelOverrides)) {
        await this.savePanelOverride(panelId, override, conn);
      }
    });
  }

  deviceFromRow(row) {
    if (!row) return null;
    const device = parseJson(row.data, {});
    return {
      ...device,
      deviceId: row.device_id,
      status: device.status || row.status || '',
      panelId: device.panelId || row.panel_id || '',
      userEmail: device.userEmail || row.user_email || '',
      localIp: device.localIp || row.local_ip || '',
      requestIp: device.requestIp || row.request_ip || '',
      clientId: device.clientId || row.client_id || '',
      clientName: device.clientName || row.client_name || '',
      runtimeName: device.runtimeName || row.runtime_name || '',
      version: device.version || row.version || '',
      firstSeenAt: device.firstSeenAt || fromMysqlDate(row.first_seen_at),
      lastSeenAt: device.lastSeenAt || fromMysqlDate(row.last_seen_at),
      expiresAt: device.expiresAt || fromMysqlDate(row.device_expires_at)
    };
  }

  sessionFromRow(row) {
    if (!row) return null;
    const session = parseJson(row.data, {});
    return {
      ...session,
      tokenHash: row.token_hash,
      deviceId: session.deviceId || row.device_id || '',
      clientId: session.clientId || row.client_id || '',
      panelId: session.panelId || row.panel_id || '',
      issuedAt: session.issuedAt || fromMysqlDate(row.issued_at),
      expiresAt: session.expiresAt || fromMysqlDate(row.expires_at)
    };
  }

  panelOverrideFromRow(row) {
    if (!row) return null;
    const data = parseJson(row.data, {});
    return {
      ...data,
      name: data.name || row.name || '',
      status: data.status || row.status || '',
      gatewayTarget: data.gatewayTarget || row.gateway_target || '',
      allowedClientNames: Array.isArray(data.allowedClientNames)
        ? data.allowedClientNames
        : parseJson(row.allowed_client_names, [])
    };
  }

  async read(conn = this.pool) {
    const state = initialState();
    const [devices] = await conn.query('SELECT * FROM auth_devices');
    const [sessions] = await conn.query('SELECT * FROM auth_sessions');
    const [overrides] = await conn.query('SELECT * FROM auth_panel_overrides');
    for (const row of devices) {
      const device = this.deviceFromRow(row);
      if (device?.deviceId) state.devices[device.deviceId] = device;
    }
    for (const row of sessions) {
      const session = this.sessionFromRow(row);
      if (session?.tokenHash) state.sessions[session.tokenHash] = session;
    }
    for (const row of overrides) {
      const override = this.panelOverrideFromRow(row);
      if (override) state.panelOverrides[row.panel_id] = override;
    }
    return this.normalize(state);
  }

  async readPanelState(conn = this.pool) {
    const state = initialState();
    const [rows] = await conn.query('SELECT * FROM auth_panel_overrides');
    for (const row of rows) {
      const override = this.panelOverrideFromRow(row);
      if (override) state.panelOverrides[row.panel_id] = override;
    }
    return this.normalize(state);
  }

  async write(state, conn = this.pool) {
    const nextState = this.normalize(state);
    await conn.query('DELETE FROM auth_devices');
    await conn.query('DELETE FROM auth_sessions');
    await conn.query('DELETE FROM auth_panel_overrides');
    for (const device of Object.values(nextState.devices)) {
      await this.saveDevice(device, conn);
    }
    for (const session of Object.values(nextState.sessions)) {
      await this.saveSession(session, conn);
    }
    for (const [panelId, override] of Object.entries(nextState.panelOverrides)) {
      await this.savePanelOverride(panelId, override, conn);
    }
  }

  async update(mutator) {
    return this.withTransaction(async (conn) => {
      const state = await this.read(conn);
      const result = mutator(state);
      await this.write(state, conn);
      return result;
    });
  }

  async getDevice(deviceId, conn = this.pool) {
    const [rows] = await conn.query('SELECT * FROM auth_devices WHERE device_id = ? LIMIT 1', [deviceId]);
    return this.deviceFromRow(rows[0]);
  }

  async saveDevice(device, conn = this.pool) {
    if (!device?.deviceId) return;
    const firstSeenAt = toMysqlDate(device.firstSeenAt);
    const lastSeenAt = toMysqlDate(device.lastSeenAt);
    const deviceExpiresAt = toMysqlDate(device.expiresAt);
    await conn.query(`
      INSERT INTO auth_devices (
        device_id, status, panel_id, user_email, local_ip, request_ip,
        client_id, client_name, runtime_name, version,
        first_seen_at, last_seen_at, device_expires_at, data, updated_at
      )
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
      ON DUPLICATE KEY UPDATE
        status = VALUES(status),
        panel_id = VALUES(panel_id),
        user_email = VALUES(user_email),
        local_ip = VALUES(local_ip),
        request_ip = VALUES(request_ip),
        client_id = VALUES(client_id),
        client_name = VALUES(client_name),
        runtime_name = VALUES(runtime_name),
        version = VALUES(version),
        first_seen_at = VALUES(first_seen_at),
        last_seen_at = VALUES(last_seen_at),
        device_expires_at = VALUES(device_expires_at),
        data = VALUES(data),
        updated_at = VALUES(updated_at)
    `, [
      device.deviceId,
      device.status || '',
      device.panelId || '',
      device.userEmail || '',
      device.localIp || '',
      device.requestIp || '',
      device.clientId || '',
      device.clientName || '',
      device.runtimeName || '',
      device.version || '',
      firstSeenAt,
      lastSeenAt,
      deviceExpiresAt,
      JSON.stringify(device)
    ]);
  }

  async listDevices(conn = this.pool) {
    const [rows] = await conn.query('SELECT * FROM auth_devices ORDER BY last_seen_at DESC, updated_at DESC');
    return rows.map((row) => this.deviceFromRow(row)).filter(Boolean);
  }

  async getSession(tokenHash, conn = this.pool) {
    const [rows] = await conn.query('SELECT * FROM auth_sessions WHERE token_hash = ? LIMIT 1', [tokenHash]);
    return this.sessionFromRow(rows[0]);
  }

  async saveSession(session, conn = this.pool) {
    if (!session?.tokenHash) return;
    await conn.query(`
      INSERT INTO auth_sessions (
        token_hash, device_id, client_id, panel_id, issued_at, expires_at, data, updated_at
      )
      VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
      ON DUPLICATE KEY UPDATE
        device_id = VALUES(device_id),
        client_id = VALUES(client_id),
        panel_id = VALUES(panel_id),
        issued_at = VALUES(issued_at),
        expires_at = VALUES(expires_at),
        data = VALUES(data),
        updated_at = VALUES(updated_at)
    `, [
      session.tokenHash,
      session.deviceId || '',
      session.clientId || '',
      session.panelId || '',
      toMysqlDate(session.issuedAt),
      toMysqlDate(session.expiresAt),
      JSON.stringify(session)
    ]);
  }

  async deleteSession(tokenHash, conn = this.pool) {
    await conn.query('DELETE FROM auth_sessions WHERE token_hash = ?', [tokenHash]);
  }

  async deleteSessionsByDevice(deviceId, conn = this.pool) {
    await conn.query('DELETE FROM auth_sessions WHERE device_id = ?', [deviceId]);
  }

  async pruneExpiredSessions(time = Date.now(), conn = this.pool) {
    await conn.query('DELETE FROM auth_sessions WHERE expires_at <= ?', [toMysqlDate(time)]);
  }

  async getOnlineDeviceIds(time = Date.now(), conn = this.pool) {
    const params = [toMysqlDate(time)];
    const expiryFilter = this.config.deviceApprovalRequired
      ? 'AND (d.device_expires_at IS NULL OR d.device_expires_at > ?)'
      : '';
    if (this.config.deviceApprovalRequired) params.push(toMysqlDate(time));
    const [rows] = await conn.query(`
      SELECT DISTINCT s.device_id
      FROM auth_sessions s
      INNER JOIN auth_devices d ON d.device_id = s.device_id
      WHERE s.expires_at > ?
        AND d.status <> 'blocked'
        ${expiryFilter}
    `, params);
    return new Set(rows.map((row) => row.device_id).filter(Boolean));
  }

  async savePanelOverride(panelId, override, conn = this.pool) {
    if (!panelId || !override) return;
    const allowed = Array.isArray(override.allowedClientNames) ? override.allowedClientNames : [];
    await conn.query(`
      INSERT INTO auth_panel_overrides (
        panel_id, name, status, gateway_target, allowed_client_names, data, updated_at
      )
      VALUES (?, ?, ?, ?, ?, ?, NOW())
      ON DUPLICATE KEY UPDATE
        name = VALUES(name),
        status = VALUES(status),
        gateway_target = VALUES(gateway_target),
        allowed_client_names = VALUES(allowed_client_names),
        data = VALUES(data),
        updated_at = VALUES(updated_at)
    `, [
      panelId,
      override.name || '',
      override.status || '',
      override.gatewayTarget || '',
      JSON.stringify(allowed),
      JSON.stringify(override)
    ]);
  }

  async audit(event, payload = {}) {
    await this.pool.query(`
      INSERT INTO audit_log (event, payload, created_at)
      VALUES (?, ?, NOW())
    `, [event, JSON.stringify(payload)]);
  }

  normalize(state) {
    const next = state && typeof state === 'object' ? state : initialState();
    next.version = Number(next.version || 1);
    next.devices = next.devices && typeof next.devices === 'object' ? next.devices : {};
    next.panelOverrides = next.panelOverrides && typeof next.panelOverrides === 'object' ? next.panelOverrides : {};
    next.sessions = next.sessions && typeof next.sessions === 'object' ? next.sessions : {};
    delete next.licenses;
    delete next.targets;
    return next;
  }
}

module.exports = {
  Store
};
