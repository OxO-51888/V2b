"""Tenant-scoped management adapter; no Telegram calls or website writes."""
import argparse
from contextlib import closing
import hashlib
import hmac
import json
import logging
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
import sqlite3
import time
from urllib.parse import parse_qs, urlsplit


class ApiError(Exception):
    def __init__(self, status, message):
        self.status, self.message = status, message


class PanelAdmin:
    FLAGS = ('enabled', 'verify_join', 'verify_message')

    def __init__(self, config):
        self.db = Path(config['database']).resolve(strict=True)
        self.site = config['site']
        self.chat_id = config['chat_id']
        self.token = config['token']
        self.username = config['bot_username']
        self.sites_file = Path(config['sites_file']).resolve(strict=True)
        if (self.site != 'pianyi' or type(self.chat_id) is not int or self.chat_id >= 0
                or not isinstance(self.token, str) or len(self.token) < 64):
            raise ValueError('Invalid tenant configuration')
        self.assert_mapping()

    def assert_mapping(self):
        sites = json.loads(self.sites_file.read_text(encoding='utf-8'))
        matches = [s for s in sites if self.chat_id in s.get('chat_ids', [])]
        if len(matches) != 1 or matches[0].get('id') != self.site:
            raise ApiError(503, '群组归属已变化，请检查本站接入配置')

    def connection(self, writable=False):
        mode = 'rw' if writable else 'ro'
        db = sqlite3.connect(self.db.as_uri() + '?mode=' + mode, uri=True, timeout=3)
        db.row_factory = sqlite3.Row
        if not writable:
            db.execute('PRAGMA query_only=ON')
        return db

    def group(self, db):
        row = db.execute('SELECT chat_id,title,enabled,verify_join,verify_message,updated_at '
                         'FROM groups WHERE chat_id=?', (self.chat_id,)).fetchone()
        if row is None:
            raise ApiError(503, '本站群组尚未接入')
        result = dict(row)
        result['revision'] = hashlib.sha256(json.dumps(result, sort_keys=True).encode()).hexdigest()
        return result

    def snapshot(self):
        self.assert_mapping()
        with closing(self.connection()) as db:
            group = self.group(db)
            pending = db.execute('SELECT COUNT(*) FROM pending_joins WHERE chat_id=? AND expires_at>?',
                                 (self.chat_id, int(time.time()))).fetchone()[0]
        return {'site': self.site, 'bot_username': self.username, 'group': group,
                'pending_count': pending, 'join_timeout_seconds': 300,
                'prompt_delete_seconds': 10, 'spam_repeat_count': 10}

    def events(self, before=0, limit=30):
        self.assert_mapping()
        with closing(self.connection()) as db:
            rows = db.execute('SELECT id,event_type,telegram_id,username,display_name,action,reason,created_at '
                              'FROM events WHERE chat_id=? AND (?=0 OR id<?) ORDER BY id DESC LIMIT ?',
                              (self.chat_id, before, before, limit + 1)).fetchall()
        items = [dict(row) for row in rows[:limit]]
        return {'items': items, 'next_cursor': items[-1]['id'] if len(rows) > limit else None}

    def update(self, payload):
        if not isinstance(payload, dict) or set(payload) != set(self.FLAGS) | {'revision'}:
            raise ApiError(422, '仅允许修改本站验证开关')
        if (any(type(payload[k]) is not bool for k in self.FLAGS)
                or not isinstance(payload['revision'], str) or len(payload['revision']) != 64):
            raise ApiError(422, '设置格式不正确')
        self.assert_mapping()
        db = self.connection(writable=True)
        try:
            db.execute('BEGIN IMMEDIATE')
            row = self.group(db)
            if not hmac.compare_digest(row['revision'], payload['revision']):
                raise ApiError(409, '设置已被其他管理员修改，请刷新后重试')
            changed = any(bool(row[k]) != payload[k] for k in self.FLAGS)
            if changed:
                db.execute('UPDATE groups SET enabled=?,verify_join=?,verify_message=?,updated_at=? WHERE chat_id=?',
                           (*(int(payload[k]) for k in self.FLAGS), int(time.time()), self.chat_id))
                db.execute('INSERT INTO events(event_type,chat_id,chat_title,action,reason,created_at) '
                           'VALUES(?,?,?,?,?,?)', ('panel.group.settings', self.chat_id, row['title'],
                                                  'updated', '本站面板管理员更新验证开关', int(time.time())))
            result = self.group(db)
            db.commit()
            return {'group': result, 'changed': changed}
        except Exception:
            db.rollback()
            raise
        finally:
            db.close()


def handler_for(adapter):
    class Handler(BaseHTTPRequestHandler):
        server_version = 'PanelManagement'

        def log_message(self, fmt, *args):
            pass

        def reply(self, status, data):
            raw = json.dumps(data, ensure_ascii=False).encode()
            self.send_response(status)
            self.send_header('Content-Type', 'application/json; charset=utf-8')
            self.send_header('Content-Length', str(len(raw)))
            self.send_header('Cache-Control', 'no-store')
            self.send_header('X-Content-Type-Options', 'nosniff')
            self.end_headers()
            self.wfile.write(raw)

        def handle_request(self):
            try:
                expected = 'Bearer ' + adapter.token
                if not hmac.compare_digest(self.headers.get('Authorization', '').encode(), expected.encode()):
                    raise ApiError(401, '未授权')
                if self.headers.get('Transfer-Encoding'):
                    raise ApiError(400, '请求格式不正确')
                parsed = urlsplit(self.path)
                query = parse_qs(parsed.query, keep_blank_values=True)
                if self.command == 'GET' and parsed.path == '/v1/state' and not query:
                    data = adapter.snapshot()
                elif self.command == 'GET' and parsed.path == '/v1/events':
                    if set(query) - {'before', 'limit'} or any(len(v) != 1 for v in query.values()):
                        raise ApiError(422, '查询参数不正确')
                    try:
                        before, limit = int(query.get('before', ['0'])[0]), int(query.get('limit', ['30'])[0])
                    except ValueError:
                        raise ApiError(422, '查询参数不正确')
                    if not 0 <= before < 2**63 or not 1 <= limit <= 100:
                        raise ApiError(422, '查询范围不正确')
                    data = adapter.events(before, limit)
                elif self.command == 'POST' and parsed.path == '/v1/group' and not query:
                    try:
                        length = int(self.headers.get('Content-Length', '0'))
                    except ValueError:
                        raise ApiError(400, '请求格式不正确')
                    if not 1 <= length <= 2048 or self.headers.get_content_type() != 'application/json':
                        raise ApiError(413, '请求格式或大小不正确')
                    try:
                        payload = json.loads(self.rfile.read(length))
                    except (ValueError, UnicodeError):
                        raise ApiError(422, '设置格式不正确')
                    data = adapter.update(payload)
                else:
                    raise ApiError(404, '接口不存在')
                self.reply(200, {'data': data})
            except ApiError as error:
                self.reply(error.status, {'message': error.message})
            except Exception:
                logging.exception('Panel management request failed')
                self.reply(503, {'message': '管理接口暂时不可用，请稍后重试'})

        do_GET = handle_request
        do_POST = handle_request

    return Handler


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--config', required=True)
    parser.add_argument('--port', type=int, default=8788)
    args = parser.parse_args()
    service = PanelAdmin(json.loads(Path(args.config).read_text(encoding='utf-8')))
    ThreadingHTTPServer(('127.0.0.1', args.port), handler_for(service)).serve_forever()
