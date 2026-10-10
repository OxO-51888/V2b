"""Group-local advertising detection; this module never changes website accounts."""
import hashlib
import json
import re
import time


class AdvertisingGuard:
    def __init__(self, chat_ids, clock=time.time):
        self.chat_ids = set(chat_ids)
        self.clock = clock
        self.repeats = {}

    def match(self, message):
        chat = message.get('chat') or {}
        chat_id = chat.get('id')
        if chat_id not in self.chat_ids or chat.get('type') not in {'group', 'supergroup'}:
            return None
        if message.get('is_automatic_forward'):
            return None
        sender_chat = message.get('sender_chat') or {}
        if sender_chat:
            actor = sender_chat.get('id')
            if type(actor) is not int or actor >= 0 or actor == chat_id:
                return None
            kind = 'channel'
        else:
            actor = (message.get('from') or {}).get('id')
            if type(actor) is not int or actor <= 0:
                return None
            kind = 'user'
        message_id = message.get('message_id')
        if type(message_id) is not int or message_id <= 0:
            return None
        now = self.clock()
        timestamp = message.get('date', now)
        if type(timestamp) not in (int, float) or not now - 600 < timestamp <= now + 30:
            return None
        buttons = (message.get('reply_markup') or {}).get('inline_keyboard') or []
        if any(isinstance(row, list) and any(isinstance(button, dict) and button for button in row) for row in buttons):
            return {'actor': actor, 'kind': kind, 'reason': 'message_buttons'}
        text = str(message.get('text') or message.get('caption') or '').strip()
        entities = message.get('entities') or message.get('caption_entities') or []
        mentions = [e for e in entities if e.get('type') in {'mention', 'text_mention'}]
        if not mentions and not re.search(r'(?<![\w@/])@[A-Za-z0-9_]{5,32}\b', text):
            return None
        if not text:
            return None
        # Hidden text mentions of different people are different content.
        targets = [(e.get('type'), e.get('offset'), e.get('length'), (e.get('user') or {}).get('id')) for e in mentions]
        fingerprint = hashlib.sha256(json.dumps([text, targets], ensure_ascii=False).encode()).hexdigest()
        cutoff = now - 600
        self.repeats = {key: {mid: stamp for mid, stamp in rows.items() if stamp > cutoff}
                        for key, rows in self.repeats.items() if any(stamp > cutoff for stamp in rows.values())}
        key = (chat_id, kind, actor, fingerprint)
        if key not in self.repeats and len(self.repeats) >= 20000:
            return None
        rows = self.repeats.setdefault(key, {})
        if message_id in rows:
            return None
        rows[message_id] = timestamp
        if len(rows) > 10:
            rows.pop(min(rows, key=rows.get))
        if len(rows) >= 10:
            return {'actor': actor, 'kind': kind, 'reason': 'repeated_mention_10_in_600s'}
        return None

    def clear_actor(self, chat_id, kind, actor):
        self.repeats = {key: rows for key, rows in self.repeats.items() if key[:3] != (chat_id, kind, actor)}
