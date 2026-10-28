import copy
import hashlib
import hmac
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import threading
import time
import unittest
import urllib.parse
import urllib.request
from unittest.mock import Mock

from panel_binding import BindingError, NoRedirect
import test_site_action_isolation as isolation_fixtures


class HttpSiteRouteTests(unittest.TestCase):
    setUp = isolation_fixtures.SiteActionIsolationTests.setUp
    response = isolation_fixtures.SiteActionIsolationTests.response
    dispatch = isolation_fixtures.SiteActionIsolationTests.dispatch
    select = isolation_fixtures.SiteActionIsolationTests.select
    reset_calls = isolation_fixtures.SiteActionIsolationTests.reset_calls
    button = isolation_fixtures.SiteActionIsolationTests.button
    callback = isolation_fixtures.SiteActionIsolationTests.callback

    def test_signed_http_reads_and_confirmations_use_only_target_site(self):
        fixture = self
        requests = []
        nonces = set()
        lock = threading.Lock()
        expected_secrets = {key: client.secret for key, client in self.sites.clients.items()}

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *args):
                pass

            def do_POST(self):
                try:
                    _, site, path = self.path.split('/', 2)
                    path = '/' + path
                    body = self.rfile.read(int(self.headers['Content-Length']))
                    timestamp, nonce = self.headers['X-Tg-Timestamp'], self.headers['X-Tg-Nonce']
                    canonical = '\n'.join([timestamp, nonce, 'POST', path, hashlib.sha256(body).hexdigest()])
                    signature = hmac.new(expected_secrets[site].encode(), canonical.encode(), hashlib.sha256).hexdigest()
                    if not hmac.compare_digest(signature, self.headers['X-Tg-Signature']) or abs(int(timestamp) - time.time()) > 90:
                        status, result = 401, {'message': 'Signature rejected'}
                    else:
                        with lock:
                            if (site, nonce) in nonces:
                                raise AssertionError('Unexpected nonce replay')
                            nonces.add((site, nonce))
                            action = path.removeprefix('/api/v1/bot/telegram/')
                            values = json.loads(body)
                            requests.append((site, action, values['telegram_id']))
                            result = {'data': fixture.response(site, action, values)}
                        status = 200
                except Exception:
                    status, result = 500, {'message': 'Isolated fixture failed'}
                raw = json.dumps(result).encode()
                self.send_response(status)
                self.send_header('Content-Type', 'application/json')
                self.send_header('Content-Length', str(len(raw)))
                self.end_headers()
                self.wfile.write(raw)

        server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        worker = threading.Thread(target=server.serve_forever, daemon=True)
        worker.start()
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())

        class LoopbackTransport:
            def open(self, request, timeout):
                parsed = urllib.parse.urlsplit(request.full_url)
                site = parsed.hostname.split('.')[0]
                fixture.assertIn(site, expected_secrets)
                fixture.assertEqual(site + '.example.invalid', parsed.hostname)
                url = f'http://127.0.0.1:{server.server_port}/{site}' + parsed.path
                local = urllib.request.Request(url, data=request.data, headers=dict(request.headers), method='POST')
                return opener.open(local, timeout=timeout)

        try:
            for client in self.sites.clients.values():
                del client.call
                client.opener = LoopbackTransport()
                client.call = Mock(wraps=client.call)
            keys = list(self.sites.sites)
            for index, target in enumerate(keys):
                for account in self.accounts.values():
                    account['bound'] = True
                self.sites.discoveries.clear()
                self.select(keys[(index + 1) % len(keys)])
                self.dispatch('重置订阅'); previous = self.button()
                self.dispatch('切换机场'); self.dispatch(self.sites.sites[target]['label'])
                self.dispatch('获取订阅')
                self.assertIn(f'https://{target}.example.invalid/sub/7/', self.api.send_message.call_args.args[1])
                self.dispatch('重置订阅'); current = self.button()
                before = copy.deepcopy(self.accounts)
                count = len(requests)
                self.callback(previous)
                self.assertEqual(count, len(requests))
                self.assertEqual(before, self.accounts)
                self.callback(current)
                self.assertEqual((target, 7, 'reset'), self.writes[-1])
                self.dispatch('解绑邮箱'); self.callback(self.button())
                self.assertEqual((target, 7, 'unbind'), self.writes[-1])
                self.assertEqual({(target, 7)}, {key for key in before if before[key] != self.accounts[key]})
            client = self.sites.clients['gm']
            client.secret = expected_secrets['ovo']
            count = len(requests)
            with self.assertRaises(BindingError):
                client.get_subscription(7)
            self.assertEqual(count, len(requests))
            self.assertEqual(12, len(self.writes))
            self.assertTrue(all(user == 7 for _, _, user in requests))
        finally:
            server.shutdown()
            server.server_close()
            worker.join(timeout=5)


if __name__ == '__main__':
    unittest.main()
