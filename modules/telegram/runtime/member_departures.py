"""Site-scoped unbinding for verified voluntary departures, never for bans."""
import copy
import json
import os
from pathlib import Path
import tempfile
import threading
import time

from panel_binding import PanelBindingClient


def is_present(member):
    return (member.get('status') in {'member', 'administrator', 'creator'}
            or member.get('status') == 'restricted' and member.get('is_member') is True)


class DepartureUnbinder:
    def __init__(self, bot, db, sites, path, allowed_chat_ids, clock=time.time):
        self.bot, self.db, self.sites = bot, db, sites
        self.path, self.allowed, self.clock = Path(path), allowed_chat_ids, clock
        self.lock = threading.RLock()
        self.stop_event = threading.Event()
        self.thread = None
        self.clients = {key: PanelBindingClient(client.base_url, client.secret)
                        for key, client in sites.clients.items()} if sites else {}
        self.state = {'since': int(clock()), 'rows': {}}
        if not sites:
            return
        if self.path.exists():
            self.state = json.loads(self.path.read_text(encoding='utf-8'))
            if type(self.state.get('since')) is not int or not isinstance(self.state.get('rows'), dict):
                raise ValueError('Invalid departure state')
        else:
            self._save()

    def _save(self):
        fd, name = tempfile.mkstemp(prefix='.departures-', dir=self.path.parent)
        try:
            with os.fdopen(fd, 'w', encoding='utf-8') as stream:
                json.dump(self.state, stream, separators=(',', ':'))
                stream.flush()
                os.fsync(stream.fileno())
            os.replace(name, self.path)
        finally:
            Path(name).unlink(missing_ok=True)

    def _put(self, key, row):
        with self.lock:
            previous = self.state['rows'].get(key)
            self.state['rows'][key] = copy.deepcopy(row)
            try:
                self._save()
            except Exception:
                if previous is None:
                    self.state['rows'].pop(key, None)
                else:
                    self.state['rows'][key] = previous
                raise

    def observe(self, update):
        event = update.get('chat_member')
        if not isinstance(event, dict) or type(update.get('update_id')) is not int:
            return
        chat, old, new = (event.get(k) or {} for k in ('chat', 'old_chat_member', 'new_chat_member'))
        if not all(isinstance(value, dict) for value in (chat, old, new)):
            return
        user, actor = new.get('user') or {}, event.get('from') or {}
        if not isinstance(user, dict) or not isinstance(actor, dict):
            return
        cid, uid, date = chat.get('id'), user.get('id'), event.get('date')
        if (not self.sites or type(cid) is not int or cid not in self.sites.groups
                or self.allowed and cid not in self.allowed or chat.get('type') not in {'group', 'supergroup'}
                or type(uid) is not int or uid <= 0 or user.get('is_bot')
                or (old.get('user') or {}).get('id') != uid or type(date) is not int
                or date < self.state['since'] or date > self.clock() + 30):
            return
        key = f'{cid}:{uid}'
        with self.db.join_guard(cid, uid), self.lock:
            previous = self.state['rows'].get(key)
            if previous and previous['update_id'] >= update['update_id']:
                return
            voluntary = is_present(old) and new.get('status') == 'left' and actor.get('id') == uid
            row = {'chat_id': cid, 'user_id': uid, 'site': self.sites.groups[cid],
                   'update_id': update['update_id'], 'date': date, 'due': self.clock(),
                   'expires': date + 300, 'phase': 'unbind' if voluntary else 'done'}
            # Newer joins/bans cancel stale work before it can affect another binding.
            self._put(key, row)

    def _record(self, row, action):
        self.db.record_event(event_type='unbind', chat_id=row['chat_id'],
                             telegram_id=row['user_id'], action=action, text='', reason=row['site'])

    def _finish(self, key, row, action):
        row = {k: v for k, v in row.items() if k != 'confirmation'}
        row['phase'] = 'done'
        self._put(key, row)
        self._record(row, action)

    def _still_left(self, row):
        group = next((g for g in self.db.list_groups() if g['chat_id'] == row['chat_id']), None)
        local = self.db.get_registered_user(row['user_id'])
        return (self.sites.groups.get(row['chat_id']) == row['site']
                and (not self.allowed or row['chat_id'] in self.allowed)
                and group is not None and group['enabled'] == 1
                and not (local and local['status'] == 'blocked')
                and self.bot.get_chat_member(row['chat_id'], row['user_id']).get('status') == 'left')

    def _process(self, key, row):
        uid, site = row['user_id'], row['site']
        if row['phase'] == 'unbind':
            if row['expires'] <= self.clock():
                self._finish(key, row, 'leave_unbind_expired')
                return
            if not self._still_left(row):
                self._finish(key, row, 'leave_binding_preserved')
                return
            client = self.clients[site]
            if 'confirmation' not in row:
                result = client.prepare_unbind(uid)
                if result['status'] == 'not_bound':
                    self._finish(key, row, 'leave_not_bound')
                    return
                pending = client.unbind_confirmations[uid]
                row['confirmation'] = {'token': pending['token'], 'request_id': pending['request_id']}
                row['expires'] = min(row['expires'], self.clock() + pending['expires_at'] - time.monotonic())
                self._put(key, row)
            # Recheck after prepare; a ban or rejoin must never trigger the confirmation.
            if not self._still_left(row):
                self._finish(key, row, 'leave_binding_preserved')
                return
            client.unbind_confirmations[uid] = {**row['confirmation'],
                'expires_at': time.monotonic() + max(0, row['expires'] - self.clock())}
            client.confirm_unbind(uid)
            self.sites.clients[site].email_requests.pop(uid, None)
            self.sites.clients[site].cancel_unbind(uid)
            self.sites.discoveries.pop(uid, None)
            row.pop('confirmation', None)
            row.update(phase='notify', expires=self.clock() + 3600, due=self.clock())
            self._put(key, row)
            self._record(row, 'leave_unbind_success')
        if row['phase'] == 'notify':
            if row['expires'] <= self.clock():
                self._finish(key, row, 'leave_notice_expired')
                return
            label = self.sites.sites[site]['label']
            try:
                self.bot.send_message(uid, label + '\n\n你已退出售后群，本站邮箱绑定已自动解除。'
                                      '\n重新入群请从网站点击加群按钮，并按提示重新绑定邮箱。')
            except Exception as error:
                if 'HTTP 403' in str(error):
                    self._finish(key, row, 'leave_notice_blocked')
                    return
                raise
            self._finish(key, row, 'leave_notice_sent')

    def run_due(self):
        with self.lock:
            due = [copy.deepcopy(r) for r in self.state['rows'].values()
                   if r['phase'] != 'done' and r['due'] <= self.clock()
                   and self.sites and self.sites.groups.get(r['chat_id']) == r['site']
                   and (not self.allowed or r['chat_id'] in self.allowed)][:20]
        for snapshot in due:
            cid, uid = snapshot['chat_id'], snapshot['user_id']
            key = f'{cid}:{uid}'
            with self.db.join_guard(cid, uid):
                with self.lock:
                    row = copy.deepcopy(self.state['rows'].get(key))
                if not row or row['update_id'] != snapshot['update_id'] or row['phase'] == 'done':
                    continue
                try:
                    self._process(key, row)
                except Exception as error:
                    # Resume the persisted phase; never prepare a new token after an uncertain confirm.
                    with self.lock:
                        row = copy.deepcopy(self.state['rows'][key])
                    if row['phase'] != 'done':
                        row['due'] = self.clock() + 30
                        self._put(key, row)
                    self._record(row, 'leave_retry_' + type(error).__name__)
        with self.lock:
            obsolete = [k for k, r in self.state['rows'].items()
                        if r['phase'] == 'done' and r['date'] < self.clock() - 86400]
            if obsolete:
                for key in obsolete:
                    self.state['rows'].pop(key)
                self.state['since'] = max(self.state['since'], int(self.clock()) - 86400)
                self._save()

    def _run(self):
        while not self.stop_event.wait(2):
            try:
                self.run_due()
            except Exception as error:
                print('Departure cleanup error: ' + type(error).__name__, flush=True)

    def start(self):
        if self.thread is None:
            self.thread = threading.Thread(target=self._run, name='departure-unbinder', daemon=True)
            self.thread.start()

    def stop(self):
        self.stop_event.set()
        if self.thread:
            self.thread.join(timeout=25)
