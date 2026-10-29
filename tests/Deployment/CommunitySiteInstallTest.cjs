const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const crypto = require('node:crypto');
const {spawnSync} = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const php = process.env.PHP_BIN || 'php';

test('six-site installation keeps deployed pages, sticker assets and filemtime cache busting', t => {
    const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'panel-site-install-'));
    t.after(() => fs.rmSync(temp, {recursive: true, force: true}));
    const sites = ['pianyi', 'gm', 'nnm', 'ovo', 'clash', 'yiyuan'];
    const shared = ['scripts/apply-community-site.php', 'public/community/index.html',
        'public/theme/NINI/assets/v1/support.js', 'public/theme/default/assets/umi.js',
        'public/theme/NINI/dashboard.blade.php'];
    for (const file of [...shared, ...sites.map(site => 'resources/community/sites/' + site + '.html')]) {
        const target = path.join(temp, file);
        fs.mkdirSync(path.dirname(target), {recursive: true});
        fs.copyFileSync(path.join(root, file), target);
    }
    const dashboard = fs.readFileSync(path.join(temp, shared[4]));
    assert.match(dashboard.toString(), /filemtime\(public_path\('theme\/'.*support\.js/);
    for (const site of sites) {
        for (let repeat = 0; repeat < 2; repeat++) {
            const result = spawnSync(php, ['-n', path.join(temp, shared[0]), '--site=' + site], {encoding: 'utf8'});
            assert.equal(result.status, 0, result.stdout + result.stderr);
            const expected = fs.readFileSync(path.join(root, 'resources/community/sites/' + site + '.html'));
            const actual = fs.readFileSync(path.join(temp, shared[1]));
            assert.deepEqual(actual, expected);
            assert.match(actual.toString(), /stickers.js\?v=/);
            assert.match(actual.toString(), /stickers.css\?v=/);
            const version = crypto.createHash('sha256').update(actual).digest('hex').slice(0, 12);
            for (const file of [shared[2], shared[3]]) {
                assert.ok(fs.readFileSync(path.join(temp, file), 'utf8').includes('embedded=1&v=' + version));
            }
            assert.deepEqual(fs.readFileSync(path.join(temp, shared[4])), dashboard);
        }
    }
});
