import hashlib
import hmac
import io
import json
import os
import unittest
import urllib.error
from unittest.mock import Mock, patch

from panel_binding import BindingError, NoRedirect, PanelBindingClient, normalize_email


class PanelClientTests(unittest.TestCase):
    def setUp(self):
        env = patch.dict(os.environ, {'PANEL_EMAIL_BINDING_URL': 'https://example.invalid/api/v1/bot/telegram',
                                    'PANEL_EMAIL_BINDING_SECRET': 's' * 48})
        env.start(); self.addCleanup(env.stop)
        self.client = PanelBindingClient()
        self.client.opener = Mock()

    def response(self, data):
        response = Mock()
        response.read.return_value = json.dumps({'data': data}).encode()
        response.__enter__ = Mock(return_value=response)
        response.__exit__ = Mock(return_value=False)
        return response

    def test_normalization_rejects_subscriptions_and_header_injection(self):
        self.assertEqual('a+b@example.com', normalize_email(' A+B@Example.COM '))
        for value in ['https://site.invalid/sub?token=abcdefgh', 'abc..def@x.com', 'a@-x.com', 'a@x.com\r\nBcc:z@y.com', 'name <a@x.com>', 'a@x.com,a@y.com', 'a' * 65 + '@x.com']:
            self.assertFalse(normalize_email(value))
            with self.assertRaises(BindingError): self.client.request_code(7, value)
        self.client.opener.open.assert_not_called()

    def test_signed_request_has_expected_canonical_body(self):
        self.client.opener.open.return_value = self.response({'status': 'queued'})
        self.client.request_code(7, 'a@example.invalid')
        req = self.client.opener.open.call_args.args[0]
        h = {k.lower(): v for k, v in req.header_items()}
        canonical = '\n'.join([h['x-tg-timestamp'], h['x-tg-nonce'], 'POST', '/api/v1/bot/telegram/email/request', hashlib.sha256(req.data).hexdigest()])
        self.assertEqual(hmac.new(b's' * 48, canonical.encode(), hashlib.sha256).hexdigest(), h['x-tg-signature'])
        self.assertNotIn('s' * 48, str(h))
        self.assertEqual('a@example.invalid', json.loads(req.data)['email'])

    def test_retry_keeps_request_id_but_renews_signed_nonce(self):
        self.client.opener.open.side_effect = [TimeoutError(), self.response({'status': 'queued'})]
        with patch('panel_binding.time.sleep'):
            self.client.request_code(7, 'a@example.invalid')
        one, two = [call.args[0] for call in self.client.opener.open.call_args_list]
        self.assertEqual(one.data, two.data)
        self.assertNotEqual(one.get_header('X-tg-nonce'), two.get_header('X-tg-nonce'))

    def test_total_failure_is_bounded(self):
        self.client.opener.open.side_effect = TimeoutError()
        with patch('panel_binding.time.sleep'), self.assertRaises(BindingError): self.client.verify(7)
        self.assertEqual(2, self.client.opener.open.call_count)

    def test_errors_do_not_leak_html_or_claim_success(self):
        for status, body in [(401, b'secret detail'), (503, b'{"message":"sending disabled"}'), (422, b'<html>bad</html>')]:
            self.client.opener.reset_mock()
            self.client.opener.open.side_effect = urllib.error.HTTPError('https://example.invalid', status, '', {}, io.BytesIO(body))
            with self.assertRaises(BindingError) as error: self.client.request_code(7, 'a@example.invalid')
            self.assertNotIn('<html>', str(error.exception))
            self.assertNotIn('secret detail', str(error.exception))
            self.assertEqual(1, self.client.opener.open.call_count)

    def test_verification_requires_real_boolean_and_binding_status(self):
        for data in [{}, {'allowed': 'true'}, {'allowed': 1}]:
            self.client.opener.open.return_value = self.response(data)
            with self.assertRaises(BindingError): self.client.verify(7)
        self.client.opener.open.return_value = self.response({'allowed': True})
        self.assertTrue(self.client.verify(7)['allowed'])
        with self.assertRaises(BindingError): self.client.confirm_code(7, '123456')
        self.client.opener.open.return_value = self.response({'allowed': True, 'status': 'bound'})
        self.assertEqual('bound', self.client.confirm_code(7, '123456')['status'])

    def test_private_credentials_and_tls_are_required(self):
        for url in ['http://example.invalid', 'https://user:pass@example.invalid', 'https://example.invalid?foo=1', 'https://example.invalid#fragment']:
            self.client.base_url = url
            with self.assertRaises(BindingError): self.client.verify(7)
        self.client.opener.open.assert_not_called()
        self.assertIsNone(NoRedirect().redirect_request(None, None, 302, '', {}, 'https://other.invalid'))

    def test_invalid_codes_never_reach_server(self):
        for code in ['12345', '1234567', 'abcdef', '123456\n']:
            with self.assertRaises(BindingError): self.client.confirm_code(7, code)
        self.client.opener.open.assert_not_called()


if __name__ == '__main__':
    unittest.main()
