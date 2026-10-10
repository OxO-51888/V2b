import os
from pathlib import Path
import sys
import tempfile
import json
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import urllib.request
import urllib.error
import unittest
from unittest.mock import patch, MagicMock


@unittest.skipUnless(sys.platform == 'linux', 'Production receiver uses Linux flock')
class PanelReceiverTests(unittest.TestCase):
    def test_shared_http_entry_keeps_legacy_ui_and_scoped_auth_separate(self):
        import panel_receiver
        class Legacy(BaseHTTPRequestHandler):
            def log_message(self, *args): pass
            def do_GET(self):
                self.send_response(200 if self.path == '/admin' else 404)
                self.end_headers()
                self.wfile.write(b'legacy')
        service = MagicMock(site='pianyi', token='a' * 64)
        service.snapshot.return_value = {'site': 'pianyi'}
        server = ThreadingHTTPServer(('127.0.0.1', 0), panel_receiver.integrated_handler(service, Legacy))
        worker = threading.Thread(target=server.serve_forever, daemon=True)
        worker.start()
        try:
            base = 'http://127.0.0.1:' + str(server.server_port)
            with urllib.request.urlopen(base + '/admin') as response:
                self.assertEqual(b'legacy', response.read())
            with self.assertRaises(urllib.error.HTTPError) as error:
                urllib.request.urlopen(base + '/panel-modules/pianyi/v1/state')
            self.assertEqual(401, error.exception.code)
            request = urllib.request.Request(base + '/panel-modules/pianyi/v1/state', headers={'Authorization': 'Bearer ' + service.token})
            with urllib.request.urlopen(request) as response:
                self.assertEqual('pianyi', json.load(response)['data']['site'])
            with self.assertRaises(urllib.error.HTTPError) as error:
                urllib.request.urlopen(base + '/panel-modules/ovo/v1/state')
            self.assertEqual(404, error.exception.code)
        finally:
            server.shutdown(); server.server_close(); worker.join()

    def test_uses_existing_state_and_only_one_receiver(self):
        import panel_receiver
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            config = root / 'management.json'
            config.write_text('{}')
            with patch.dict(os.environ, {'TG_DATA_DIR': str(root), 'TG_PANEL_ADMIN_CONFIG': str(config)}), \
                    patch.object(panel_receiver, 'PanelAdmin') as service, patch.object(panel_receiver.bot, 'AdminHandler') as legacy, \
                    patch.object(panel_receiver, 'integrated_handler', return_value=MagicMock()) as factory, \
                    patch.object(panel_receiver.bot, 'BASE_DIR'), patch.object(panel_receiver.bot, 'STATE_FILE'), \
                    patch.object(panel_receiver.bot, 'APP_DB_FILE'), patch.object(panel_receiver.bot, 'main') as run:
                panel_receiver.main()
                run.assert_called_once_with()
                self.assertEqual(root, panel_receiver.bot.BASE_DIR)
                self.assertEqual(root / 'state.json', panel_receiver.bot.STATE_FILE)
                self.assertEqual(root / 'bot_admin.sqlite3', panel_receiver.bot.APP_DB_FILE)
                factory.assert_called_once_with(service.return_value, legacy)

    def test_second_receiver_cannot_start(self):
        import fcntl
        import panel_receiver
        with tempfile.TemporaryDirectory() as directory:
            lock = (Path(directory) / 'panel-receiver.lock').open('a')
            try:
                fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
                with patch.dict(os.environ, {'TG_DATA_DIR': directory}), patch.object(panel_receiver.bot, 'main') as run:
                    with self.assertRaises(BlockingIOError): panel_receiver.main()
                    run.assert_not_called()
            finally: lock.close()

    def test_local_panel_mode_needs_no_remote_management_server(self):
        import panel_receiver
        import panel_supervisor
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            with patch.dict(os.environ, {'TG_DATA_DIR': directory, 'TG_PANEL_LOCAL': 'true'}), \
                    patch.object(panel_receiver, 'PanelAdmin') as remote, \
                    patch.object(panel_receiver.bot, 'BASE_DIR'), patch.object(panel_receiver.bot, 'STATE_FILE'), \
                    patch.object(panel_receiver.bot, 'APP_DB_FILE'), patch.object(panel_supervisor, 'run') as run:
                panel_receiver.main()
                run.assert_called_once_with(root)
                remote.assert_not_called()
                self.assertEqual('false', os.environ['WEB_ADMIN_ENABLE'])
                self.assertEqual(str(root / 'bot_admin.sqlite3'), os.environ['BOT_ADMIN_DB'])
                self.assertEqual(str(root / 'panel-sites.json'), os.environ['PANEL_EMAIL_BINDING_SITES_FILE'])
