const path = require('path');
const fs = require('fs/promises');

class StateStore {
  constructor(app) {
    this.statePath = path.join(app.getPath('userData'), 'state.json');
  }

  async read() {
    try {
      return JSON.parse(await fs.readFile(this.statePath, 'utf8'));
    } catch {
      return {};
    }
  }

  async write(state) {
    await fs.mkdir(path.dirname(this.statePath), { recursive: true });
    await fs.writeFile(this.statePath, JSON.stringify(state, null, 2), 'utf8');
  }

  async clear() {
    await this.write({});
  }
}

module.exports = { StateStore };
