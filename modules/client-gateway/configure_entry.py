#!/usr/bin/python3
"""Provision only the native gateway locations in an existing HTTPS vhost."""
import fcntl
import hashlib
import json
import os
import pathlib
import re
import subprocess
import sys
import tempfile

BASE = pathlib.Path('/www/server/panel/vhost/nginx')
POLICY = pathlib.Path('/etc/panel-gateway-entry.json')
NGINX = '/www/server/nginx/sbin/nginx'


def valid_domain(value):
    return (len(value) <= 253 and re.fullmatch(
        r'(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?', value)
        and not value.endswith(('.localhost', '.local', '.internal', '.invalid', '.test')))


def fastcgi(site, extra=''):
    root = site['root']
    panel = site['panel_id']
    if not re.fullmatch(r'/www/wwwroot/[a-zA-Z0-9._-]+', root) or not re.fullmatch(r'panel_[0-9]{2}', panel):
        raise ValueError('Invalid trusted site policy')
    return f'''    include /www/server/nginx/conf/fastcgi_params;
    fastcgi_pass unix:/tmp/php-cgi-81.sock;
    fastcgi_param SCRIPT_FILENAME {root}/public/index.php;
    fastcgi_param SCRIPT_NAME /index.php;
    fastcgi_param DOCUMENT_ROOT {root}/public;
    fastcgi_param REQUEST_URI /api/v1/client-entry/$panel_gateway_endpoint$is_args$args;
    fastcgi_read_timeout 45s;
    client_max_body_size 4m;
    add_header Cache-Control "no-store" always;
{extra}'''


def render(site):
    panel = site['panel_id']
    common = fastcgi(site)
    return (f'# managed-panel-gateway-entry:{panel}\n'
            f'location = /.well-known/panel-gateway/{panel} {{\n'
            '    set $panel_gateway_endpoint probe;\n' + common + '}\n'
            'location ~ ^/(api/client/(?:verify|heartbeat|deactivate)|api/gateway/panel/[a-zA-Z0-9/_-]+)$ {\n'
            '    set $panel_gateway_endpoint $1;\n' + common + '}\n').encode()


def render_shared(policy):
    sites = policy['sites']
    dispatcher = sites[policy['dispatcher']]
    if len({s['panel_id'] for s in sites.values()}) != len(sites):
        raise ValueError('Duplicate panel identifiers')
    result = '# managed-panel-gateway-entry:shared-v1\n'
    for site in sites.values():
        panel = site['panel_id']
        common = fastcgi(site, f'    fastcgi_param PANEL_GATEWAY_TARGET {panel};\n')
        result += (f'location = /.well-known/panel-gateway/{panel} {{\n'
                   '    set $panel_gateway_endpoint probe;\n' + common + '}\n')
        result += (f'location ~ ^/__panel_gateway_dispatch/{panel}/(api/client/(?:verify|heartbeat|deactivate)|api/gateway/panel/[a-zA-Z0-9/_-]+)$ {{\n'
                   '    internal;\n    set $panel_gateway_endpoint $1;\n' + common
                   + '    fastcgi_param REQUEST_METHOD $panel_gateway_original_method;\n}\n')
    result += ('location ~ ^/(health|api/public-key|api/client/(?:verify|heartbeat|deactivate)|api/gateway/panel/[a-zA-Z0-9/_-]+)$ {\n'
               '    set $panel_gateway_endpoint $1;\n    set $panel_gateway_original_method $request_method;\n'
               + fastcgi(dispatcher, '    fastcgi_param PANEL_GATEWAY_DISPATCH 1;\n') + '}\n')
    return result.encode()


def atomic(path, raw):
    fd, name = tempfile.mkstemp(prefix='.panel-gateway-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as file:
            file.write(raw)
            file.flush()
            os.fsync(file.fileno())
        os.chmod(name, 0o644)
        os.replace(name, path)
    finally:
        if os.path.exists(name):
            os.unlink(name)


def configure(site, domain, policy=None):
    if not valid_domain(domain):
        return {'ok': False, 'code': 'HOST_NOT_READY'}
    # The web process cannot choose upstream addresses, document roots or file paths.
    vhost = BASE / (domain + '.conf')
    extension = BASE / 'extension' / domain
    if vhost.is_symlink() or extension.is_symlink() or not vhost.is_file() or not extension.is_dir():
        return {'ok': False, 'code': 'HOST_NOT_READY'}
    content = vhost.read_text()
    if (not re.search(r'\bserver_name\s+' + re.escape(domain) + r'\s*;', content)
            or not re.search(r'\blisten\s+443\s+ssl\s*;', content)
            or f'include {extension}/*.conf;' not in content
            or 'ssl_certificate ' not in content):
        return {'ok': False, 'code': 'HOST_NOT_READY'}
    target = extension / 'panel-native-gateway.conf'
    desired = render_shared(policy) if policy else render(site)
    previous = target.read_bytes() if target.is_file() else None
    owners = [s['panel_id'] for s in policy['sites'].values()] + ['shared-v1'] if policy else [site['panel_id']]
    markers = tuple(('# managed-panel-gateway-entry:' + owner + '\n').encode() for owner in owners)
    if target.is_symlink() or (previous is not None and not previous.startswith(markers)):
        return {'ok': False, 'code': 'HOST_CONFLICT'}
    if previous == desired:
        return {'ok': True, 'changed': False}
    atomic(target, desired)
    try:
        subprocess.run([NGINX, '-t'], check=True, capture_output=True, timeout=8)
        subprocess.run([NGINX, '-s', 'reload'], check=True, capture_output=True, timeout=8)
    except (subprocess.SubprocessError, OSError):
        if previous is None:
            target.unlink()
        else:
            atomic(target, previous)
        subprocess.run([NGINX, '-t'], check=True, capture_output=True, timeout=8)
        subprocess.run([NGINX, '-s', 'reload'], check=True, capture_output=True, timeout=8)
        return {'ok': False, 'code': 'NGINX_CHECK_FAILED'}
    return {'ok': True, 'changed': True, 'sha256': hashlib.sha256(desired).hexdigest()}


def main():
    if os.geteuid() != 0 or len(sys.argv) != 3:
        raise ValueError('Invalid invocation')
    st = POLICY.stat()
    if st.st_uid != 0 or st.st_mode & 0o022:
        raise ValueError('Unsafe policy permissions')
    policy = json.loads(POLICY.read_text())
    site = policy['sites'].get(sys.argv[1])
    if not site:
        raise ValueError('Site is not enabled')
    with open('/run/panel-gateway-entry.lock', 'a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        result = configure(site, sys.argv[2], policy if 'dispatcher' in policy else None)
    print(json.dumps(result))
    return 0 if result['ok'] else 1


if __name__ == '__main__':
    try:
        sys.exit(main())
    except Exception:
        print(json.dumps({'ok': False, 'code': 'CONFIGURATION_FAILED'}))
        sys.exit(1)
