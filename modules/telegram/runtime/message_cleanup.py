"""Durable cleanup for short-lived group prompts, independent of update polling."""
import json
import os
from pathlib import Path
import tempfile
import threading
import time


class MessageCleanup:
    def __init__(self, delete, path, clock=time.time, allowed_chat_ids=None):
        self.delete = delete
        self.path = Path(path)
        self.clock = clock
        self.allowed = set(allowed_chat_ids or [])
        self.condition = threading.Condition()
        self.stopped = False
        self.thread = None
        self.cooldowns = {}
        self.jobs = {}
        if self.path.exists():
            for row in json.loads(self.path.read_text(encoding='utf-8')):
                if (type(row.get('chat_id')) is not int or row['chat_id'] >= 0
                        or type(row.get('message_id')) is not int or row['message_id'] <= 0
                        or not all(type(row.get(k)) in (int, float) for k in ('due', 'expires', 'attempts'))):
                    raise ValueError('Invalid pending message cleanup')
                self.jobs[(row['chat_id'], row['message_id'])] = row

    def claim_prompt(self, chat_id, user_id):
        with self.condition:
            now = self.clock()
            self.cooldowns = {key: expiry for key, expiry in self.cooldowns.items() if expiry > now}
            key = (chat_id, user_id)
            if key in self.cooldowns or len(self.cooldowns) >= 10000:
                return False
            self.cooldowns[key] = now + 30
            return True

    def release_prompt(self, chat_id, user_id):
        with self.condition:
            self.cooldowns.pop((chat_id, user_id), None)

    def _save(self):
        fd, name = tempfile.mkstemp(prefix='.message-cleanup-', dir=self.path.parent)
        try:
            with os.fdopen(fd, 'w', encoding='utf-8') as stream:
                json.dump(list(self.jobs.values()), stream, separators=(',', ':'))
                stream.flush()
                os.fsync(stream.fileno())
            os.replace(name, self.path)
        finally:
            if os.path.exists(name):
                os.unlink(name)

    def schedule(self, chat_id, message_id, delay=10):
        if type(chat_id) is not int or chat_id >= 0 or type(message_id) is not int or message_id <= 0:
            raise ValueError('Only identified group messages can be scheduled')
        with self.condition:
            key = (chat_id, message_id)
            if key in self.jobs:
                return
            now = self.clock()
            self.jobs[key] = {'chat_id': chat_id, 'message_id': message_id,
                              'due': now + delay, 'expires': now + 47 * 3600, 'attempts': 0}
            try:
                self._save()
            except Exception:
                self.jobs.pop(key, None)
                raise
            self.condition.notify_all()

    def run_due(self):
        with self.condition:
            due = [dict(row) for row in self.jobs.values() if row['due'] <= self.clock()
                   and (not self.allowed or row['chat_id'] in self.allowed)][:20]
        for row in due:
            key = (row['chat_id'], row['message_id'])
            complete = row['expires'] <= self.clock()
            if not complete:
                try:
                    self.delete(*key)
                    complete = True
                except Exception as error:
                    complete = 'message to delete not found' in str(error).lower()
                    if not complete:
                        print('Group prompt cleanup will retry: ' + type(error).__name__, flush=True)
            with self.condition:
                if complete:
                    self.jobs.pop(key, None)
                else:
                    row['attempts'] += 1
                    row['due'] = self.clock() + min(60, 5 * 2 ** min(row['attempts'] - 1, 4))
                    self.jobs[key] = row
                self._save()

    def _run(self):
        while True:
            with self.condition:
                if self.stopped:
                    return
                wait = max(0, min((r['due'] - self.clock() for r in self.jobs.values()
                                  if not self.allowed or r['chat_id'] in self.allowed), default=60))
                if wait:
                    self.condition.wait(min(wait, 60))
                    continue
            try:
                self.run_due()
            except Exception as error:
                print('Group prompt cleanup error: ' + type(error).__name__, flush=True)
                with self.condition:
                    self.condition.wait(5)

    def start(self):
        if self.thread is not None:
            return
        self.thread = threading.Thread(target=self._run, name='group-prompt-cleanup', daemon=True)
        self.thread.start()

    def stop(self):
        with self.condition:
            self.stopped = True
            self.condition.notify_all()
        if self.thread:
            self.thread.join(timeout=15)
