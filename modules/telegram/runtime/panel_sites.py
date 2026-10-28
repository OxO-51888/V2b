"""Explicit site routing; private conversation state never authorizes a group."""
import json
import copy
import hashlib
import hmac
from concurrent.futures import ThreadPoolExecutor
import os
from pathlib import Path
import re
import time
import urllib.parse

from panel_binding import BindingError, PanelBindingClient


class PanelSites:
    def __init__(self, items):
        if not isinstance(items, list) or not 1 <= len(items) <= 12:
            raise ValueError('Invalid panel site list')
        self.sites = {}
        self.groups = {}
        self.clients = {}
        self.selections = {}
        self.discoveries = {}
        urls, names, secrets = set(), set(), set()
        for item in items:
            if not isinstance(item, dict):
                raise ValueError('Invalid panel site')
            key, name = item.get('id'), item.get('name')
            url, secret = item.get('url'), item.get('secret')
            chats = item.get('chat_ids', [])
            if (not isinstance(key, str) or not re.fullmatch(r'[a-z][a-z0-9_-]{0,31}', key)
                    or not isinstance(name, str) or not 1 <= len(name.strip()) <= 40
                    or any(ord(c) < 32 for c in name)
                    or not isinstance(url, str) or not isinstance(secret, str) or len(secret) < 32):
                raise ValueError('Invalid panel site identity')
            name = name.strip()
            label = name if '机场' in name else name + '机场'
            parsed = urllib.parse.urlsplit(url)
            if (parsed.scheme != 'https' or not parsed.hostname or parsed.username or parsed.password
                    or parsed.query or parsed.fragment or parsed.path.rstrip('/') != '/api/v1/bot/telegram'
                    or any(ord(c) <= 32 for c in url)):
                raise ValueError('Invalid panel site URL')
            url = url.rstrip('/')
            if key in self.sites or url in urls or label in names or secret in secrets:
                raise ValueError('Duplicate panel site identity or secret')
            if not isinstance(chats, list) or any(type(c) is not int or c >= 0 for c in chats):
                raise ValueError('Invalid panel group mapping')
            for chat_id in chats:
                if chat_id in self.groups:
                    raise ValueError('Duplicate panel group mapping')
                self.groups[chat_id] = key
            self.sites[key] = {'id': key, 'name': name, 'label': label, 'url': url, 'chat_ids': chats}
            self.clients[key] = PanelBindingClient(url, secret, require_local_request=True)
            urls.add(url); names.add(label); secrets.add(secret)

    @classmethod
    def from_env(cls, root):
        value = os.getenv('PANEL_EMAIL_BINDING_SITES_FILE', '').strip()
        if not value:
            return None
        path = Path(value)
        if not path.is_absolute():
            path = root / path
        return cls(json.loads(path.read_text(encoding='utf-8')))

    def current(self, user_id):
        selected = self.selections.get(user_id)
        if selected and selected[1] > time.monotonic():
            return selected[0]
        if selected:
            self.selections.pop(user_id, None)
            self.clear_pending(user_id)
        return None

    def clear_pending(self, user_id):
        for client in self.clients.values():
            client.email_requests.pop(user_id, None)
            client.cancel_unbind(user_id)
            client.cancel_subscription_reset(user_id)

    def begin_switch(self, user_id):
        self.clear_pending(user_id)
        now = time.monotonic()
        self.selections = {uid: row for uid, row in self.selections.items() if row[1] > now}
        if user_id not in self.selections and len(self.selections) >= 20000:
            raise BindingError('当前请求较多，请稍后再试')
        # None reserves the conversation while the user explicitly chooses a site.
        self.selections[user_id] = (None, now + 86400)

    def awaiting_choice(self, user_id):
        self.current(user_id)
        selected = self.selections.get(user_id)
        return selected is not None and selected[0] is None

    def select(self, user_id, key):
        if key not in self.sites:
            raise BindingError('请选择有效的机场')
        if self.current(user_id) != key:
            self.clear_pending(user_id)
        now = time.monotonic()
        self.selections = {uid: row for uid, row in self.selections.items() if row[1] > now}
        if user_id not in self.selections and len(self.selections) >= 20000:
            raise BindingError('当前请求较多，请稍后再试')
        self.selections[user_id] = (key, now + 86400)
        return key

    def for_group(self, chat_id):
        key = self.groups.get(chat_id)
        if key is None:
            raise BindingError('该群尚未配置对应机场，验证暂缓，请联系管理员')
        return key

    def entry_payload(self, key):
        return 'b_' + hmac.new(self.clients[key].secret.encode(), b'telegram-site-entry-v1',
                               hashlib.sha256).hexdigest()[:32]

    def entry_url(self, key, username):
        if not re.fullmatch(r'[A-Za-z0-9_]{5,32}', username):
            raise BindingError('网站验证入口暂未配置，请联系管理员')
        return f'https://t.me/{username}?start={self.entry_payload(key)}'

    def bound_sites(self, user_id):
        cached = self.discoveries.get(user_id)
        if cached and cached[0] > time.monotonic():
            return cached[1], cached[2]
        def check(key):
            client = copy.copy(self.clients[key])
            client.timeout, client.attempts = 3, 1
            try:
                return key, client.verify(user_id, require_active=False).get('allowed') is True, False
            except BindingError:
                return key, False, True
        with ThreadPoolExecutor(max_workers=len(self.clients)) as pool:
            results = list(pool.map(check, self.clients))
        keys = [key for key, allowed, _ in results if allowed]
        incomplete = any(failed for _, _, failed in results)
        now = time.monotonic()
        self.discoveries = {uid: row for uid, row in self.discoveries.items() if row[0] > now}
        if len(self.discoveries) < 20000:
            self.discoveries[user_id] = (now + 15, keys, incomplete)
        return keys, incomplete

    def entry_choice(self, text):
        parts = text.split(maxsplit=1)
        payload = parts[1] if len(parts) == 2 and parts[0].split('@')[0].lower() == '/start' else None
        if payload and not re.fullmatch(r'b_[a-f0-9]{32}', payload):
            payload = None
        if payload:
            for key in self.sites:
                if hmac.compare_digest(payload, self.entry_payload(key)):
                    return key
        return None

    def choice(self, user_id, text):
        entry = self.entry_choice(text)
        if entry:
            return entry
        current = self.current(user_id)
        for key, site in self.sites.items():
            if text != site['label']:
                continue
            if key == current:
                return key
            # Telegram keeps reply buttons after the short discovery cache expires.
            own, incomplete = self.bound_sites(user_id)
            if key in own:
                return key
            if incomplete:
                raise BindingError('未切换机场。请稍后发送 /sites 重新选择，或从网站的验证入口进入。')
            raise BindingError('未切换机场。请从网站的验证入口进入，或发送 /sites 重新选择已绑定的机场。')
        return None

    def can_switch(self, user_id):
        keys, _ = self.bound_sites(user_id)
        return len(keys) > 1

    def keyboard(self, keys):
        buttons = [{'text': self.sites[key]['label']} for key in keys]
        if not buttons:
            return {'remove_keyboard': True}
        return {'keyboard': [buttons[i:i + 3] for i in range(0, len(buttons), 3)],
                'resize_keyboard': True, 'is_persistent': False, 'one_time_keyboard': False}


class SitePrivateBot:
    """Add site identity to private replies without altering Telegram operations."""
    def __init__(self, bot, label, sites=None, user_id=None):
        self.bot = bot
        self.label = label
        self.sites = sites
        self.user_id = user_id

    def __getattr__(self, name):
        return getattr(self.bot, name)

    def send_message(self, chat_id, text, reply_to_message_id=None, *, reply_markup=None, entities=None):
        if reply_markup and 'keyboard' in reply_markup:
            rows = reply_markup['keyboard']
            labels = {b.get('text') for row in rows for b in row}
            if '绑定邮箱' in labels:
                rows = [[b for b in row if b.get('text') != '切换机场'] for row in rows]
                rows = [row for row in rows if row]
                if self.sites and self.sites.can_switch(self.user_id):
                    rows[-1] = rows[-1] + [{'text': '切换机场'}]
                reply_markup = {**reply_markup, 'keyboard': rows}
        prefix = self.label + '\n\n'
        if entities is not None:
            shift = len(prefix.encode('utf-16-le')) // 2
            shifted = [{**entity, 'offset': entity['offset'] + shift} for entity in entities]
            return self.bot.send_message(chat_id, prefix + text, reply_to_message_id,
                                         reply_markup=reply_markup, entities=shifted)
        return self.bot.send_message(chat_id, prefix + text, reply_to_message_id, reply_markup=reply_markup)
