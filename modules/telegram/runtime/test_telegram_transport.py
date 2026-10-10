import http.client
import io
import socket
import ssl
import unittest
import urllib.error
import urllib.request
from unittest.mock import Mock, patch

import bot
import telegram_transport as transport
from panel_binding import PanelBindingClient


V4 = (socket.AF_INET, socket.SOCK_STREAM, socket.IPPROTO_TCP, '', ('192.0.2.10', 443))
V4_SECOND = (socket.AF_INET, socket.SOCK_STREAM, socket.IPPROTO_TCP, '', ('192.0.2.11', 443))
V6 = (socket.AF_INET6, socket.SOCK_STREAM, socket.IPPROTO_TCP, '', ('2001:db8::10', 443, 0, 0))


class ConnectionTests(unittest.TestCase):
    def test_ipv4_is_tried_first_even_when_dns_returns_ipv6_first(self):
        connection = Mock()
        with patch.object(socket, 'getaddrinfo', return_value=[V6, V4]) as resolve, \
                patch.object(socket, 'socket', return_value=connection) as factory:
            self.assertIs(connection, transport.create_ipv4_first_connection(('api.telegram.org', 443), 10))
        resolve.assert_called_once_with('api.telegram.org', 443, 0, socket.SOCK_STREAM)
        factory.assert_called_once_with(socket.AF_INET, socket.SOCK_STREAM, socket.IPPROTO_TCP)
        connection.connect.assert_called_once_with(V4[4])
        connection.settimeout.assert_called_once_with(10)
        connection.close.assert_not_called()

    def test_all_ipv4_addresses_are_tried_before_ipv6(self):
        first, second = Mock(), Mock()
        first.connect.side_effect = TimeoutError('fixture')
        with patch.object(socket, 'getaddrinfo', return_value=[V6, V4, V4_SECOND]), \
                patch.object(socket, 'socket', side_effect=[first, second]) as factory:
            result = transport.create_ipv4_first_connection(('api.telegram.org', 443), 10)
        self.assertIs(second, result)
        self.assertEqual([socket.AF_INET, socket.AF_INET], [c.args[0] for c in factory.call_args_list])
        first.close.assert_called_once()
        second.connect.assert_called_once_with(V4_SECOND[4])

    def test_ipv6_remains_fallback_when_ipv4_connection_fails(self):
        first, second = Mock(), Mock()
        first.connect.side_effect = ConnectionRefusedError('fixture')
        with patch.object(socket, 'getaddrinfo', return_value=[V6, V4]), \
                patch.object(socket, 'socket', side_effect=[first, second]) as factory:
            result = transport.create_ipv4_first_connection(('api.telegram.org', 443), 10)
        self.assertIs(second, result)
        self.assertEqual([socket.AF_INET, socket.AF_INET6], [c.args[0] for c in factory.call_args_list])
        first.close.assert_called_once()
        second.connect.assert_called_once_with(V6[4])

    def test_ipv6_only_dns_remains_supported(self):
        connection = Mock()
        with patch.object(socket, 'getaddrinfo', return_value=[V6]), \
                patch.object(socket, 'socket', return_value=connection):
            self.assertIs(connection, transport.create_ipv4_first_connection(('api.telegram.org', 443), 10))
        connection.connect.assert_called_once_with(V6[4])

    def test_all_failed_sockets_are_closed_and_error_is_reported(self):
        first, second = Mock(), Mock()
        first.connect.side_effect = TimeoutError('first')
        second.connect.side_effect = OSError('second')
        with patch.object(socket, 'getaddrinfo', return_value=[V6, V4]), \
                patch.object(socket, 'socket', side_effect=[first, second]), \
                self.assertRaisesRegex(OSError, 'second'):
            transport.create_ipv4_first_connection(('api.telegram.org', 443), 10)
        first.close.assert_called_once()
        second.close.assert_called_once()

    def test_dns_failure_does_not_construct_socket(self):
        with patch.object(socket, 'getaddrinfo', side_effect=socket.gaierror('fixture')), \
                patch.object(socket, 'socket') as factory, self.assertRaises(socket.gaierror):
            transport.create_ipv4_first_connection(('api.telegram.org', 443), 10)
        factory.assert_not_called()

    def test_empty_dns_result_is_an_error(self):
        with patch.object(socket, 'getaddrinfo', return_value=[]), self.assertRaises(OSError):
            transport.create_ipv4_first_connection(('api.telegram.org', 443), 10)

    def test_source_binding_and_default_timeout_are_preserved(self):
        connection = Mock()
        with patch.object(socket, 'getaddrinfo', return_value=[V4]), \
                patch.object(socket, 'socket', return_value=connection):
            transport.create_ipv4_first_connection(('api.telegram.org', 443), source_address=('192.0.2.1', 0))
        connection.bind.assert_called_once_with(('192.0.2.1', 0))
        connection.settimeout.assert_not_called()


class ScopeAndTLS(unittest.TestCase):
    def test_only_exact_telegram_host_uses_custom_connection(self):
        handler = transport.TelegramHTTPSHandler()
        for host in ['api.telegram.org', 'api.telegram.org.attacker.invalid', 'example.invalid']:
            with self.subTest(host=host), patch.object(handler, 'do_open') as open_request:
                request = urllib.request.Request('https://' + host + '/fixture')
                handler.https_open(request)
                expected = transport.TelegramHTTPSConnection if host == 'api.telegram.org' else http.client.HTTPSConnection
                self.assertIs(expected, open_request.call_args.args[0])

    def test_telegram_connection_keeps_tls_verification_and_original_sni(self):
        context = ssl.create_default_context()
        connection = transport.TelegramHTTPSConnection('api.telegram.org', context=context, timeout=10)
        raw_socket, tls_socket = Mock(), Mock()
        self.assertTrue(context.check_hostname)
        self.assertEqual(ssl.CERT_REQUIRED, context.verify_mode)
        with patch.object(connection, '_create_connection', return_value=raw_socket), \
                patch.object(context, 'wrap_socket', return_value=tls_socket) as wrap:
            connection.connect()
        wrap.assert_called_once_with(raw_socket, server_hostname='api.telegram.org')
        self.assertIs(tls_socket, connection.sock)

    def test_certificate_failure_is_not_retried_or_ignored(self):
        context = ssl.create_default_context()
        connection = transport.TelegramHTTPSConnection('api.telegram.org', context=context, timeout=10)
        with patch.object(connection, '_create_connection', return_value=Mock()) as connect, \
                patch.object(context, 'wrap_socket', side_effect=ssl.SSLCertVerificationError('fixture')), \
                self.assertRaises(ssl.SSLCertVerificationError):
            connection.connect()
        connect.assert_called_once()

    def test_no_global_network_patches_or_panel_handler_changes(self):
        resolve, connect, open_url = socket.getaddrinfo, socket.create_connection, urllib.request.urlopen
        api = bot.TelegramBot('fixture')
        panel = PanelBindingClient()
        self.assertIs(resolve, socket.getaddrinfo)
        self.assertIs(connect, socket.create_connection)
        self.assertIs(open_url, urllib.request.urlopen)
        self.assertTrue(any(isinstance(h, transport.TelegramHTTPSHandler) for h in api.opener.handlers))
        self.assertFalse(any(isinstance(h, transport.TelegramHTTPSHandler) for h in panel.opener.handlers))

    def test_transport_errors_do_not_resend_side_effecting_requests(self):
        api = bot.TelegramBot('fixture')
        errors = [TimeoutError('fixture'), urllib.error.URLError('fixture'),
                  urllib.error.HTTPError('https://example.invalid', 503, '', {}, io.BytesIO(b'fixture'))]
        for error in errors:
            with self.subTest(error=type(error)), patch.object(api.opener, 'open', side_effect=error) as open_request:
                with self.assertRaises((RuntimeError, TimeoutError)):
                    api.send_message(7, 'fixture')
                open_request.assert_called_once()


if __name__ == '__main__':
    unittest.main()
