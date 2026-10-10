"""One receiver per bot identity, with site-local connection overrides."""
import copy
import hashlib
import json
import multiprocessing
import os
from pathlib import Path
import queue
import re
import signal
import sqlite3
import tempfile
import time


def fingerprint(token, chat_id):
    return hashlib.sha256((token + '\n' + str(chat_id)).encode()).hexdigest()


def load_groups(root, default_token):
    root = Path(root)
    sites = json.loads((root / 'panel-sites.json').read_text(encoding='utf-8'))
    with sqlite3.connect('file:' + str(root / 'bot_admin.sqlite3') + '?mode=ro', uri=True) as db:
        settings = dict(db.execute("SELECT key,value FROM settings WHERE key LIKE 'panel.connection.%'"))
    groups, claimed = {}, set()
    for original in sites:
        site = copy.deepcopy(original)
        saved = json.loads(settings.get('panel.connection.' + site['id'], '{}'))
        token = saved.get('token', default_token)
        if not re.fullmatch(r'[0-9]{5,20}:[A-Za-z0-9_-]{20,100}', token):
            raise ValueError('Invalid bot credential')
        chats = [int(saved['chat_id'])] if 'chat_id' in saved else site['chat_ids']
        if len(chats) != 1 or type(chats[0]) is not int or chats[0] >= 0 or chats[0] in claimed:
            raise ValueError('Invalid or duplicate site group')
        claimed.update(chats)
        site['chat_ids'] = chats
        bot_id = token.split(':', 1)[0]
        group = groups.setdefault(bot_id, {'token': token, 'sites': [], 'fingerprints': {}})
        if group['token'] != token:
            raise ValueError('Conflicting credentials for one bot identity')
        group['sites'].append(site)
        group['fingerprints'][site['id']] = fingerprint(token, chats[0])
    for group in groups.values():
        group['sites'].sort(key=lambda site: site['id'])
        group['revision'] = hashlib.sha256(json.dumps(group, sort_keys=True).encode()).hexdigest()
    return groups


def atomic_json(path, value):
    path = Path(path)
    fd, temporary = tempfile.mkstemp(prefix='.receiver-', dir=path.parent)
    try:
        with os.fdopen(fd, 'w', encoding='utf-8') as stream:
            json.dump(value, stream, ensure_ascii=False, separators=(',', ':'))
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, path)
    finally:
        Path(temporary).unlink(missing_ok=True)


def run_receiver(root, bot_id, default_bot_id, config, stop, ready):
    import fcntl
    import bot
    root = Path(root)
    data = root if bot_id == default_bot_id else root / 'receivers' / bot_id
    data.mkdir(parents=True, exist_ok=True, mode=0o700)
    with (data / 'token-receiver.lock').open('a') as lock:
        fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        signal.signal(signal.SIGTERM, lambda *_: stop.set())
        signal.signal(signal.SIGINT, lambda *_: stop.set())
        site_file = data / 'receiver-sites.json'
        atomic_json(site_file, config['sites'])
        chats = ','.join(str(cid) for site in config['sites'] for cid in site['chat_ids'])
        os.environ.update(TELEGRAM_BOT_TOKEN=config['token'], WEB_ADMIN_ENABLE='false',
                          BOT_ADMIN_DB=str(root / 'bot_admin.sqlite3'),
                          PANEL_EMAIL_BINDING_SITES_FILE=str(site_file), TELEGRAM_ALLOWED_CHAT_IDS=chats,
                          TELEGRAM_JOIN_ONBOARDING_CHAT_IDS=chats, TELEGRAM_DROP_PENDING_UPDATES='false')
        bot.BASE_DIR, bot.STATE_FILE, bot.APP_DB_FILE = data, data / 'state.json', root / 'bot_admin.sqlite3'
        published = False

        def on_ready():
            nonlocal published
            if not published:
                ready.put((bot_id, config['revision']))
                published = True

        try:
            bot.run_bot(stop_requested=stop.is_set, on_ready=on_ready)
        except Exception as error:
            print('Panel receiver failed: ' + type(error).__name__, flush=True)
            raise SystemExit(1) from None


class Supervisor:
    def __init__(self, root, default_token, context=None):
        self.root, self.default_token = Path(root), default_token
        self.context = context or multiprocessing.get_context('spawn')
        self.ready = self.context.Queue()
        self.running, self.retry_after = {}, {}
        self.stopped = False

    def stop_worker(self, bot_id):
        entry = self.running[bot_id]
        entry['stop'].set()
        entry['process'].join(70)
        if entry['process'].is_alive():
            entry['process'].kill()
            entry['process'].join(10)
        if entry['process'].is_alive():
            raise RuntimeError('Previous receiver did not stop')
        self.running.pop(bot_id)

    def reconcile(self, wanted):
        # Stop changed consumers before starting replacements, including token merges.
        for bot_id, entry in list(self.running.items()):
            if bot_id not in wanted or wanted[bot_id]['revision'] != entry['config']['revision']:
                self.stop_worker(bot_id)
                self.retry_after.pop(bot_id, None)
            elif not entry['process'].is_alive():
                self.stop_worker(bot_id)
                self.retry_after[bot_id] = time.monotonic() + 10
        for bot_id, config in wanted.items():
            if bot_id in self.running or self.retry_after.get(bot_id, 0) > time.monotonic():
                continue
            stop = self.context.Event()
            process = self.context.Process(target=run_receiver,
                args=(str(self.root), bot_id, self.default_token.split(':', 1)[0], config, stop, self.ready),
                name='telegram-' + bot_id)
            process.start()
            self.running[bot_id] = {'process': process, 'stop': stop, 'config': config, 'ready': False}
        while True:
            try:
                bot_id, revision = self.ready.get_nowait()
            except queue.Empty:
                break
            entry = self.running.get(bot_id)
            if entry and entry['config']['revision'] == revision:
                entry['ready'] = True
        states = {}
        for entry in self.running.values():
            for site, value in entry['config']['fingerprints'].items():
                states[site] = {'fingerprint': value, 'ready': entry['ready'] and entry['process'].is_alive()}
        atomic_json(self.root / 'receiver-status.json', {'updated_at': int(time.time()), 'sites': states})

    def run(self):
        signal.signal(signal.SIGTERM, lambda *_: setattr(self, 'stopped', True))
        signal.signal(signal.SIGINT, lambda *_: setattr(self, 'stopped', True))
        try:
            while not self.stopped:
                try:
                    self.reconcile(load_groups(self.root, self.default_token))
                except Exception as error:
                    print('Panel receiver configuration check failed: ' + type(error).__name__, flush=True)
                time.sleep(2)
        finally:
            for bot_id in list(self.running):
                self.stop_worker(bot_id)
            atomic_json(self.root / 'receiver-status.json', {'updated_at': int(time.time()), 'sites': {}})


def run(root):
    import bot
    bot.load_env(Path(root) / '.env')
    Supervisor(root, os.environ.get('TELEGRAM_BOT_TOKEN', '').strip()).run()
