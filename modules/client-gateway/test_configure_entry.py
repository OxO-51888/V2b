import importlib.util
import pathlib
import subprocess
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('entry', pathlib.Path(__file__).with_name('configure_entry.py'))
entry = importlib.util.module_from_spec(spec)
spec.loader.exec_module(entry)


class EntryProvisionTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.base = pathlib.Path(self.temp.name)
        self.site = {'root': '/www/wwwroot/fixture.example', 'panel_id': 'panel_06'}
        self.ext = self.base / 'extension' / 'entry.example.com'
        self.ext.mkdir(parents=True)
        self.vhost = self.base / 'entry.example.com.conf'
        self.vhost.write_text('server_name entry.example.com;\nlisten 443 ssl;\n'
                              f'include {self.ext}/*.conf;\nssl_certificate fixture;\n')
        self.old = self.ext / 'auth_proxy.conf'
        self.old.write_text('location / { proxy_pass http://127.0.0.1:8787; }')
        self.patch = patch.object(entry, 'BASE', self.base)
        self.patch.start()
        self.addCleanup(self.patch.stop)

    def test_render_has_only_bounded_gateway_routes(self):
        config = entry.render(self.site).decode()
        self.assertIn('panel_06', config)
        self.assertIn('/api/v1/client-entry/', config)
        self.assertNotIn('location / {', config)
        self.assertNotIn('proxy_pass', config)
        self.assertNotIn('private', config)

    def test_rejects_domain_and_policy_injection(self):
        for value in ['../example.com', 'x.com;include', '127.0.0.1', 'x.com:443', 'localhost', 'a..com']:
            self.assertFalse(entry.valid_domain(value))
        with self.assertRaises(ValueError):
            entry.render({'root': '/tmp/evil;include', 'panel_id': 'panel_06'})

    @patch.object(entry.subprocess, 'run')
    def test_apply_is_idempotent_and_preserves_legacy(self, run):
        result = entry.configure(self.site, 'entry.example.com')
        self.assertTrue(result['ok'])
        self.assertTrue(result['changed'])
        self.assertEqual(2, run.call_count)
        self.assertFalse(entry.configure(self.site, 'entry.example.com')['changed'])
        self.assertEqual(2, run.call_count)
        self.assertEqual('location / { proxy_pass http://127.0.0.1:8787; }', self.old.read_text())

    @patch.object(entry.subprocess, 'run')
    def test_nginx_failure_rolls_back_only_new_file(self, run):
        run.side_effect = [subprocess.CalledProcessError(1, ['nginx']), None, None]
        self.assertFalse(entry.configure(self.site, 'entry.example.com')['ok'])
        self.assertFalse((self.ext / 'panel-native-gateway.conf').exists())
        self.assertTrue(self.old.exists())

    def test_foreign_managed_file_and_unprepared_host_are_rejected(self):
        target = self.ext / 'panel-native-gateway.conf'
        target.write_text('# owned by somebody else')
        self.assertEqual('HOST_CONFLICT', entry.configure(self.site, 'entry.example.com')['code'])
        self.assertEqual('# owned by somebody else', target.read_text())
        self.assertEqual('HOST_NOT_READY', entry.configure(self.site, 'missing.example.com')['code'])

    @patch.object(entry.subprocess, 'run')
    def test_six_sites_share_entry_and_reconfigure_without_overwriting_each_other(self, run):
        policy = {'dispatcher': 'site6', 'sites': {f'site{i}': {
            'root': f'/www/wwwroot/site{i}.example', 'panel_id': f'panel_{i:02d}'} for i in range(1, 7)}}
        target = self.ext / 'panel-native-gateway.conf'
        target.write_bytes(entry.render(policy['sites']['site6']))
        for site in policy['sites'].values():
            self.assertTrue(entry.configure(site, 'entry.example.com', policy)['ok'])
        self.assertEqual(2, run.call_count)
        text = target.read_text()
        self.assertEqual(6, text.count('    internal;'))
        self.assertEqual(6, text.count('location = /.well-known/panel-gateway/'))
        self.assertEqual(1, text.count('PANEL_GATEWAY_DISPATCH 1;'))
        self.assertEqual(1, text.count('set $panel_gateway_original_method $request_method;'))
        self.assertEqual(6, text.count('fastcgi_param REQUEST_METHOD $panel_gateway_original_method;'))
        self.assertNotIn('8787', text)
        self.assertNotIn('proxy_pass', text)

    def test_shared_policy_rejects_duplicate_ids_and_bad_roots(self):
        policy = {'dispatcher': 'one', 'sites': {'one': self.site, 'two': self.site}}
        with self.assertRaises(ValueError):
            entry.render_shared(policy)
        policy['sites']['two'] = {'root': '/tmp/bad;', 'panel_id': 'panel_01'}
        with self.assertRaises(ValueError):
            entry.render_shared(policy)


if __name__ == '__main__':
    unittest.main()
