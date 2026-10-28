import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
FAKE = '''#!/usr/bin/python3
import json, os, sys
from pathlib import Path
name=Path(sys.argv[0]).name
args=sys.argv[1:]
with open(os.environ['CALLS'], 'a') as f: f.write(json.dumps([name,*args])+'\\n')
if name=='php-fixture':
    if args==['bootstrap/runtime.php'] and os.getenv('OLD_PHP'): sys.exit(255)
    if os.getenv('FAIL_INSTALL') and 'install' in args: sys.exit(1)
    if os.getenv('FAIL_PLATFORM') and 'check-platform-reqs' in args: sys.exit(1)
elif name=='git':
    if args==['branch','--show-current']: print('test/native85')
    if args and args[0]=='diff' and os.getenv('DIRTY'): sys.exit(1)
    if args and args[0]=='merge' and os.getenv('DIVERGED'): sys.exit(1)
elif name=='curl': sys.exit(99)
'''


class Php85ScriptsTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name)
        self.root = self.base / 'panel with spaces'
        self.root.mkdir()
        for name in ['init.sh', 'update.sh', 'scripts/runtime.sh', 'bootstrap/runtime.php']:
            target = self.root / name
            target.parent.mkdir(exist_ok=True)
            shutil.copyfile(ROOT / name, target)
        (self.root / 'composer.lock').write_text('{"fixture":true}')
        (self.root / 'composer.phar').write_text('fixture')
        (self.root / '.git').mkdir()
        self.bin = self.base / 'bin'
        self.bin.mkdir()
        for name in ['php-fixture', 'git', 'curl', 'chown']:
            path = self.bin / name
            path.write_text(FAKE)
            path.chmod(0o755)
        self.calls = self.base / 'calls.jsonl'
        self.env = {**os.environ, 'PATH': str(self.bin) + ':' + os.environ['PATH'],
                    'PHP_BIN': str(self.bin / 'php-fixture'), 'CALLS': str(self.calls)}

    def execute(self, script, **extra):
        result = subprocess.run(['bash', str(self.root / script)], cwd=self.base,
                                env={**self.env, **extra}, capture_output=True, text=True, timeout=10)
        calls = [json.loads(line) for line in self.calls.read_text().splitlines()] if self.calls.exists() else []
        return result, calls

    def test_install_uses_lock_and_explicit_php_without_adding_packages(self):
        result, calls = self.execute('init.sh')
        self.assertEqual(0, result.returncode, result.stderr)
        commands = [c for c in calls if c[0] == 'php-fixture']
        install = next(c for c in commands if 'install' in c)
        self.assertIn('--no-dev', install)
        self.assertIn('--no-interaction', install)
        self.assertFalse(any('require' in c or 'update' in c for c in commands))
        self.assertIn(['php-fixture', 'artisan', 'v2board:install'], commands)
        self.assertEqual('{"fixture":true}', (self.root / 'composer.lock').read_text())

    def test_update_is_fast_forward_only_and_keeps_existing_configuration(self):
        (self.root / '.env').write_text('fixture=preserved')
        result, calls = self.execute('update.sh')
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn(['git', 'merge', '--ff-only', 'origin/test/native85'], calls)
        self.assertFalse(any('reset' in c for c in calls))
        self.assertFalse(any('webman.php' in c for c in calls))
        self.assertIn(['php-fixture', 'artisan', 'v2board:update'], calls)
        self.assertEqual('fixture=preserved', (self.root / '.env').read_text())

    def test_unsupported_php_stops_before_network_dependencies_or_database(self):
        for script in ['init.sh', 'update.sh']:
            with self.subTest(script=script):
                self.calls.unlink(missing_ok=True)
                result, calls = self.execute(script, OLD_PHP='1')
                self.assertNotEqual(0, result.returncode)
                self.assertEqual([['php-fixture', 'bootstrap/runtime.php']], calls)

    def test_missing_lock_never_resolves_new_dependencies(self):
        (self.root / 'composer.lock').unlink()
        result, calls = self.execute('init.sh')
        self.assertNotEqual(0, result.returncode)
        self.assertIn('composer.lock is required', result.stderr)
        self.assertEqual(1, len(calls))

    def test_dirty_or_diverged_checkout_never_runs_database_update(self):
        for name in ['DIRTY', 'DIVERGED']:
            with self.subTest(condition=name):
                self.calls.unlink(missing_ok=True)
                result, calls = self.execute('update.sh', **{name: '1'})
                self.assertNotEqual(0, result.returncode)
                self.assertFalse(any('artisan' in c for c in calls))
                self.assertFalse(any('install' in c for c in calls))

    def test_dependency_or_platform_failure_stops_before_artisan(self):
        for name in ['FAIL_INSTALL', 'FAIL_PLATFORM']:
            with self.subTest(condition=name):
                self.calls.unlink(missing_ok=True)
                result, calls = self.execute('init.sh', **{name: '1'})
                self.assertNotEqual(0, result.returncode)
                self.assertFalse(any('artisan' in c for c in calls))

    def test_shell_syntax(self):
        for script in ['init.sh', 'update.sh', 'scripts/runtime.sh']:
            self.assertEqual(0, subprocess.run(['bash', '-n', str(ROOT / script)], capture_output=True).returncode)


if __name__ == '__main__':
    unittest.main()
