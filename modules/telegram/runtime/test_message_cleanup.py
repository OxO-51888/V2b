import json
import os
from pathlib import Path
import tempfile
import threading
import time
import unittest
from unittest.mock import Mock, patch

import bot
from message_cleanup import MessageCleanup


class CleanupTests(unittest.TestCase):
    def setUp(self):
        folder = tempfile.TemporaryDirectory()
        self.addCleanup(folder.cleanup)
        self.path = Path(folder.name) / 'pending.json'
        self.now = 1000
        self.delete = Mock()
        self.cleanup = MessageCleanup(self.delete, self.path, lambda: self.now)

    def test_exact_ten_second_deadline(self):
        self.cleanup.schedule(-100, 21)
        self.now += 9.99
        self.cleanup.run_due()
        self.delete.assert_not_called()
        self.now += .01
        self.cleanup.run_due()
        self.delete.assert_called_once_with(-100, 21)
        self.assertEqual([], json.loads(self.path.read_text()))

    def test_restart_retains_deadline_and_deletes_only_recorded_message(self):
        self.cleanup.schedule(-100, 21)
        self.now += 20
        restored = MessageCleanup(self.delete, self.path, lambda: self.now)
        restored.run_due()
        self.delete.assert_called_once_with(-100, 21)

    def test_network_failure_retries_and_success_clears_job(self):
        self.cleanup.schedule(-100, 21)
        self.now += 10
        self.delete.side_effect = [TimeoutError(), None]
        self.cleanup.run_due()
        self.assertEqual(1015, self.cleanup.jobs[(-100, 21)]['due'])
        self.now += 4
        self.cleanup.run_due()
        self.assertEqual(1, self.delete.call_count)
        self.now += 1
        self.cleanup.run_due()
        self.assertEqual(2, self.delete.call_count)
        self.assertEqual({}, self.cleanup.jobs)

    def test_already_deleted_message_does_not_retry(self):
        self.cleanup.schedule(-100, 21)
        self.delete.side_effect = RuntimeError('Bad Request: message to delete not found')
        self.now += 10
        self.cleanup.run_due()
        self.assertEqual({}, self.cleanup.jobs)

    def test_duplicate_schedule_does_not_extend_deadline(self):
        self.cleanup.schedule(-100, 21)
        self.now += 5
        self.cleanup.schedule(-100, 21)
        self.assertEqual(1010, self.cleanup.jobs[(-100, 21)]['due'])

    def test_private_and_unidentified_messages_are_never_scheduled(self):
        for chat, message in [(100, 21), (-100, 0), (-100, None), (-100, True)]:
            with self.assertRaises(ValueError):
                self.cleanup.schedule(chat, message)
        self.assertFalse(self.path.exists())

    def test_cooldowns_are_per_user_and_group(self):
        self.assertTrue(self.cleanup.claim_prompt(-100, 7))
        self.assertFalse(self.cleanup.claim_prompt(-100, 7))
        self.assertTrue(self.cleanup.claim_prompt(-100, 8))
        self.assertTrue(self.cleanup.claim_prompt(-101, 7))
        self.now += 30
        self.assertTrue(self.cleanup.claim_prompt(-100, 7))

    def test_failed_prompt_can_be_retried(self):
        self.cleanup.claim_prompt(-100, 7)
        self.cleanup.release_prompt(-100, 7)
        self.assertTrue(self.cleanup.claim_prompt(-100, 7))

    def test_failed_persistence_does_not_keep_unsaved_job(self):
        with patch.object(self.cleanup, '_save', side_effect=OSError('disk full')):
            with self.assertRaises(OSError):
                self.cleanup.schedule(-100, 21)
        self.assertEqual({}, self.cleanup.jobs)

    def test_real_background_timer_does_not_block_and_uses_ten_seconds(self):
        deleted = threading.Event()
        elapsed = []
        started = time.monotonic()
        def record(chat, message):
            elapsed.append(time.monotonic() - started)
            deleted.set()
        worker = MessageCleanup(record, self.path)
        self.addCleanup(worker.stop)
        worker.start()
        worker.schedule(-100, 21)
        self.assertLess(time.monotonic() - started, 1)
        self.assertFalse(deleted.wait(.05))
        self.assertTrue(deleted.wait(13))
        self.assertGreaterEqual(elapsed[0], 9.9)
        self.assertLess(elapsed[0], 13)


class GroupPromptTests(unittest.TestCase):
    def setUp(self):
        folder = tempfile.TemporaryDirectory()
        self.addCleanup(folder.cleanup)
        self.path = Path(folder.name)
        self.now = 1000
        self.api = bot.TelegramBot('fixture-token')
        self.api.request = Mock(return_value={'ok': True, 'result': {'message_id': 99}})
        self.api.message_cleanup = MessageCleanup(self.api.delete_message, self.path / 'pending.json', lambda: self.now)
        self.db = bot.AppDatabase(self.path / 'test.sqlite3')
        self.db.save_settings({'unregistered_message_action': 'delete_warn'})

    def dispatch(self, unavailable=False):
        bot.handle_unregistered_message(self.api, self.db, -100, 'Fixture', 42,
                                        {'id': 7, 'first_name': 'User'}, 'hello',
                                        'network failure' if unavailable else 'not bound', unavailable)

    def test_delete_then_prompt_then_only_prompt_deleted_at_ten_seconds(self):
        self.dispatch()
        methods = [call.args[0] for call in self.api.request.call_args_list]
        self.assertEqual(['deleteMessage', 'sendMessage'], methods)
        self.assertEqual(1010, self.api.message_cleanup.jobs[(-100, 99)]['due'])
        self.now += 10
        self.api.message_cleanup.run_due()
        self.api.request.assert_called_with('deleteMessage', {'chat_id': -100, 'message_id': 99})
        self.assertEqual('delete_warn', self.db.list_events()[0]['action'])

    def test_repeated_speech_deleted_but_prompt_throttled(self):
        self.dispatch()
        self.dispatch()
        methods = [call.args[0] for call in self.api.request.call_args_list]
        self.assertEqual(['deleteMessage', 'sendMessage', 'deleteMessage'], methods)
        self.assertEqual(1, len(self.api.message_cleanup.jobs))

    def test_outage_message_is_not_unbound_accusation_or_kick(self):
        self.dispatch(unavailable=True)
        sent = self.api.request.call_args_list[1].args[1]
        self.assertIn('暂时不可用', sent['text'])
        self.assertNotIn('请先绑定', sent['text'])
        self.assertNotIn('inline_keyboard', sent)
        self.assertEqual(1, len(self.api.message_cleanup.jobs))

    def test_send_failure_releases_prompt_cooldown(self):
        self.api.request.side_effect = [None, RuntimeError('send failed')]
        self.dispatch()
        self.assertEqual({}, self.api.message_cleanup.cooldowns)
        self.assertEqual('delete_warn_failed', self.db.list_events()[0]['action'])

    def test_delete_failure_does_not_suppress_binding_guidance(self):
        self.api.request.side_effect = [RuntimeError('no permission'), {'ok': True, 'result': {'message_id': 99}}]
        self.dispatch()
        self.assertEqual('sendMessage', self.api.request.call_args.args[0])
        self.assertEqual(1, len(self.api.message_cleanup.jobs))
        self.assertEqual('delete_warn_failed', self.db.list_events()[0]['action'])

    def test_schedule_failure_immediately_removes_prompt(self):
        with patch.object(self.api.message_cleanup, '_save', side_effect=OSError('disk full')):
            self.dispatch()
        self.api.request.assert_called_with('deleteMessage', {'chat_id': -100, 'message_id': 99})
        self.assertEqual({}, self.api.message_cleanup.jobs)
        self.assertEqual({}, self.api.message_cleanup.cooldowns)


if __name__ == '__main__':
    unittest.main()
