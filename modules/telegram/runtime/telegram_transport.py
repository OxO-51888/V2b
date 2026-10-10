"""IPv4-first connections scoped to the Telegram API opener."""
import http.client
import socket
import urllib.parse
import urllib.request


def create_ipv4_first_connection(address, timeout=socket._GLOBAL_DEFAULT_TIMEOUT, source_address=None):
    host, port = address
    addresses = socket.getaddrinfo(host, port, 0, socket.SOCK_STREAM)
    addresses.sort(key=lambda entry: entry[0] != socket.AF_INET)
    error = None
    for family, socktype, protocol, _, sockaddr in addresses:
        connection = None
        try:
            connection = socket.socket(family, socktype, protocol)
            if timeout is not socket._GLOBAL_DEFAULT_TIMEOUT:
                connection.settimeout(timeout)
            if source_address:
                connection.bind(source_address)
            connection.connect(sockaddr)
            return connection
        except OSError as exc:
            error = exc
            if connection is not None:
                connection.close()
    if error is not None:
        raise error
    raise OSError('No addresses returned for Telegram connection')


class TelegramHTTPSConnection(http.client.HTTPSConnection):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        # Keep the standard HTTPS handshake, SNI and certificate validation.
        self._create_connection = create_ipv4_first_connection


class TelegramHTTPSHandler(urllib.request.HTTPSHandler):
    def https_open(self, request):
        connection = http.client.HTTPSConnection
        if urllib.parse.urlsplit(request.full_url).hostname == 'api.telegram.org':
            connection = TelegramHTTPSConnection
        return self.do_open(connection, request, context=self._context)
