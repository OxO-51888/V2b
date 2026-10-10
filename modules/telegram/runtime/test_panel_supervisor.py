import json
import os
from pathlib import Path
import queue
import sqlite3
import tempfile
import unittest
from unittest.mock import MagicMock, patch

from panel_supervisor import Supervisor, load_groups, fingerprint
from message_cleanup import MessageCleanup
from panel_sites import PanelSites


TOKEN = '111111111:fixture_original_token_abcdefghijklmnop'
OTHER = '222222222:fixture_independent_token_abcdefghijklmnop'


class FakeProcess:
    def __init__(self, events, **kwargs):
        self.events, self.options, self.alive = events, kwargs, False
        self.stubborn = False

    def start(self):
        self.alive = True
        self.events.append(('start', self.options['name']))

    def is_alive(self):
        return self.alive

    def join(self, timeout):
        if not self.stubborn:
            self.alive = False
        self.events.append(('join', self.options['name']))

    def kill(self):
        self.events.append(('kill', self.options['name']))


class FakeContext:
    def __init__(self):
        self.events = []

    def Queue(self): return queue.Queue()
    def Event(self): return MagicMock()
    def Process(self, **kwargs): return FakeProcess(self.events, **kwargs)


class PanelSupervisorTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.root = Path(self.directory.name)
        self.sites = [{'id': key, 'name': key, 'url': f'https://{key}.example.invalid/api/v1/bot/telegram',
                       'secret': key * 40, 'chat_ids': [cid]}
                      for key, cid in [('pianyi', -123), ('ovo', -456), ('clash', -789)]]
        (self.root / 'panel-sites.json').write_text(json.dumps(self.sites))
        with sqlite3.connect(self.root / 'bot_admin.sqlite3') as db:
            db.execute('CREATE TABLE settings(key TEXT PRIMARY KEY,value TEXT NOT NULL)')

    def tearDown(self): self.directory.cleanup()

    def save(self, site, token, chat_id):
        with sqlite3.connect(self.root / 'bot_admin.sqlite3') as db:
            db.execute('INSERT OR REPLACE INTO settings VALUES(?,?)',
                       ('panel.connection.' + site, json.dumps({'token': token, 'chat_id': str(chat_id)})))

    def test_same_token_creates_one_receiver_and_preserves_site_identity(self):
        groups = load_groups(self.root, TOKEN)
        self.assertEqual(['111111111'], list(groups))
        self.assertEqual(['clash', 'ovo', 'pianyi'], [s['id'] for s in groups['111111111']['sites']])
        router = PanelSites(groups['111111111']['sites'])
        self.assertEqual('pianyi', router.for_group(-123))
        self.assertEqual('ovo', router.for_group(-456))
        self.assertNotEqual(router.entry_payload('pianyi'), router.entry_payload('ovo'))

    def test_other_site_configuration_reuses_running_receiver_without_restart(self):
        context = FakeContext(); supervisor = Supervisor(self.root, TOKEN, context)
        supervisor.reconcile(load_groups(self.root, TOKEN))
        process = supervisor.running['111111111']['process']
        self.save('pianyi', TOKEN, -123)
        supervisor.reconcile(load_groups(self.root, TOKEN))
        self.save('ovo', TOKEN, -456)
        supervisor.reconcile(load_groups(self.root, TOKEN))
        self.assertIs(process, supervisor.running['111111111']['process'])
        self.assertEqual([('start', 'telegram-111111111')], context.events)

    def test_independent_bot_does_not_gain_other_sites_and_old_stops_before_replacement(self):
        context = FakeContext(); supervisor = Supervisor(self.root, TOKEN, context)
        supervisor.reconcile(load_groups(self.root, TOKEN))
        self.save('pianyi', OTHER, -999)
        groups = load_groups(self.root, TOKEN)
        self.assertEqual(['pianyi'], [s['id'] for s in groups['222222222']['sites']])
        self.assertEqual(['clash', 'ovo'], [s['id'] for s in groups['111111111']['sites']])
        supervisor.reconcile(groups)
        self.assertEqual(('join', 'telegram-111111111'), context.events[1])
        self.assertEqual(2, len(supervisor.running))
        router = PanelSites(groups['222222222']['sites'])
        self.assertEqual({'pianyi'}, set(router.clients))
        with self.assertRaises(Exception): router.for_group(-456)

    def test_merging_tokens_stops_old_consumers_before_starting_single_shared_receiver(self):
        context = FakeContext(); supervisor = Supervisor(self.root, TOKEN, context)
        self.save('pianyi', OTHER, -123)
        supervisor.reconcile(load_groups(self.root, TOKEN))
        context.events.clear()
        self.save('pianyi', TOKEN, -123)
        supervisor.reconcile(load_groups(self.root, TOKEN))
        self.assertEqual(['join', 'join', 'start'], [event[0] for event in context.events])
        self.assertEqual(['111111111'], list(supervisor.running))

    def test_stubborn_old_process_prevents_duplicate_consumer(self):
        context = FakeContext(); supervisor = Supervisor(self.root, TOKEN, context)
        supervisor.reconcile(load_groups(self.root, TOKEN))
        supervisor.running['111111111']['process'].stubborn = True
        self.save('pianyi', OTHER, -123)
        with self.assertRaises(RuntimeError): supervisor.reconcile(load_groups(self.root, TOKEN))
        self.assertEqual(['111111111'], list(supervisor.running))
        self.assertEqual(1, sum(event[0] == 'start' for event in context.events))

    def test_conflicting_group_or_bot_credential_is_rejected(self):
        for token, chat_id in [(TOKEN, -456), ('111111111:other_rotated_token_abcdefghijklmnop', -123)]:
            self.save('pianyi', token, chat_id)
            with self.assertRaises(ValueError): load_groups(self.root, TOKEN)

    def test_ready_status_requires_current_receiver_ack_and_does_not_expose_credentials(self):
        context = FakeContext(); supervisor = Supervisor(self.root, TOKEN, context)
        groups = load_groups(self.root, TOKEN)
        supervisor.reconcile(groups)
        path = self.root / 'receiver-status.json'
        self.assertFalse(json.loads(path.read_text())['sites']['pianyi']['ready'])
        supervisor.ready.put(('111111111', 'stale'))
        supervisor.reconcile(groups)
        self.assertFalse(json.loads(path.read_text())['sites']['pianyi']['ready'])
        supervisor.ready.put(('111111111', groups['111111111']['revision']))
        supervisor.reconcile(groups)
        self.assertEqual({'ready': True, 'fingerprint': fingerprint(TOKEN, -123)}, json.loads(path.read_text())['sites']['pianyi'])
        self.assertNotIn(TOKEN, path.read_text())

    def test_prompt_cleanup_never_uses_wrong_bot_after_site_moves(self):
        delete = MagicMock(); path = self.root / 'cleanup.json'
        path.write_text(json.dumps([{'chat_id': cid, 'message_id': 1, 'due': 0, 'expires': 200, 'attempts': 0}
                                   for cid in [-123, -456]]))
        cleanup = MessageCleanup(delete, path, clock=lambda: 100, allowed_chat_ids={-456})
        cleanup.run_due()
        delete.assert_called_once_with(-456, 1)
        self.assertEqual(-123, json.loads(path.read_text())[0]['chat_id'])

    def test_worker_reuses_existing_bot_runtime_and_private_keyboard(self):
        import panel_supervisor
        import bot
        config = load_groups(self.root, TOKEN)['111111111']
        stop, ready = MagicMock(), MagicMock()
        with patch.dict(os.environ, {}, clear=False), patch.object(bot, 'run_bot') as run, \
                patch.object(bot, 'BASE_DIR'), patch.object(bot, 'STATE_FILE'), patch.object(bot, 'APP_DB_FILE'), \
                patch.object(panel_supervisor.signal, 'signal'):
            panel_supervisor.run_receiver(self.root, '111111111', '111111111', config, stop, ready)
            run.assert_called_once()
            self.assertEqual(self.root / 'state.json', bot.STATE_FILE)
            self.assertEqual(TOKEN, os.environ['TELEGRAM_BOT_TOKEN'])
            self.assertEqual({'-123', '-456', '-789'}, set(os.environ['TELEGRAM_ALLOWED_CHAT_IDS'].split(',')))
            callback = run.call_args.kwargs['on_ready']; callback(); callback()
            ready.put.assert_called_once_with(('111111111', config['revision']))


if __name__ == '__main__': unittest.main()
