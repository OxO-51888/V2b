import os
from pathlib import Path
import tempfile
import threading
import unittest
from unittest.mock import Mock, patch

import bot


class JoinTimeoutTests(unittest.TestCase):
    def setUp(self):
        self.time = 1000000
        clock = patch.object(bot, 'now_ts', side_effect=lambda: self.time)
        clock.start(); self.addCleanup(clock.stop)
        env = patch.dict(os.environ, {'TELEGRAM_JOIN_ONBOARDING_CHAT_IDS': '-123,-456',
                                      'TELEGRAM_ALLOWED_CHAT_IDS': '-123,-456'})
        env.start(); self.addCleanup(env.stop)
        temp = tempfile.TemporaryDirectory(); self.addCleanup(temp.cleanup)
        self.db = bot.AppDatabase(Path(temp.name)/'test.sqlite3')
        self.db.save_group({'chat_id': -123, 'title': 'Test', 'enabled': 1, 'verify_join': 1})
        self.db.save_group({'chat_id': -456, 'title': 'Other', 'enabled': 1, 'verify_join': 1})
        self.api = Mock(spec=bot.TelegramBot)
        self.api.get_chat_member.return_value = {'status': 'left'}
        self.checker = Mock(spec=bot.PanelUserChecker)
        self.checker.verify.return_value = bot.VerificationResult(False, 'not bound')
        self.cleanup = bot.JoinRequestCleanup(self.api, self.db, self.checker)
        self.addCleanup(self.cleanup.stop)
        self.db.remember_pending_join(-123, 7, 9007, self.time)

    def tearDown(self):
        self.api.kick_chat_member.assert_not_called()
        self.api.ban_advertiser.assert_not_called()
        self.api.send_message.assert_not_called()
        self.api.request.assert_not_called()
        self.assertIsNone(self.db.get_registered_user(7))

    def expire(self):
        self.time += 300
        self.cleanup.run_due()

    def test_five_minute_deadline_is_persisted(self):
        self.assertEqual(self.time + 300, self.db.pending_join(-123, 7)['expires_at'])

    def test_before_five_minutes_does_not_decline(self):
        self.time += 299
        self.cleanup.run_due()
        self.api.decline_chat_join_request.assert_not_called()
        self.checker.verify.assert_not_called()

    def test_at_five_minutes_only_declines_and_removes_tracking(self):
        self.expire()
        self.api.decline_chat_join_request.assert_called_once_with(-123, 7)
        self.api.approve_chat_join_request.assert_not_called()
        self.assertIsNone(self.db.pending_join(-123, 7))
        self.assertEqual('decline_timeout', self.db.list_events()[0]['action'])

    def test_bound_user_is_approved_instead_of_declined(self):
        self.checker.verify.return_value = bot.VerificationResult(True, 'bound')
        self.expire()
        self.api.approve_chat_join_request.assert_called_once_with(-123, 7)
        self.api.decline_chat_join_request.assert_not_called()
        self.assertIsNone(self.db.pending_join(-123, 7))

    def test_panel_outage_does_not_reject_or_lose_request(self):
        self.checker.verify.return_value = bot.VerificationResult(False, 'offline', unavailable=True)
        self.expire()
        self.api.decline_chat_join_request.assert_not_called()
        self.assertIsNotNone(self.db.pending_join(-123, 7))

    def test_panel_exception_is_retryable(self):
        self.checker.verify.side_effect = RuntimeError('offline')
        self.expire()
        self.api.decline_chat_join_request.assert_not_called()
        self.assertTrue(self.cleanup.retry_after)

    def test_telegram_decline_failure_preserves_tracking_and_retries(self):
        self.api.decline_chat_join_request.side_effect = RuntimeError('network outage')
        self.expire()
        self.assertIsNotNone(self.db.pending_join(-123, 7))
        self.cleanup.run_due()
        self.assertEqual(1, self.api.decline_chat_join_request.call_count)
        self.cleanup.retry_after.clear()
        self.api.decline_chat_join_request.side_effect = None
        self.cleanup.run_due()
        self.assertEqual(2, self.api.decline_chat_join_request.call_count)
        self.assertIsNone(self.db.pending_join(-123, 7))

    def test_telegram_lookup_failure_never_declines(self):
        self.api.get_chat_member.side_effect = RuntimeError('network outage')
        self.expire()
        self.api.decline_chat_join_request.assert_not_called()
        self.assertIsNotNone(self.db.pending_join(-123, 7))

    def test_admin_handled_request_is_removed_without_any_ban(self):
        self.api.decline_chat_join_request.side_effect = RuntimeError('Bad Request: HIDE_REQUESTER_MISSING')
        self.expire()
        self.assertIsNone(self.db.pending_join(-123, 7))
        self.assertEqual('request_already_handled', self.db.list_events()[0]['action'])

    def test_existing_members_are_not_declined(self):
        for status in ['member', 'administrator', 'creator']:
            with self.subTest(status=status):
                self.db.remember_pending_join(-123, 7, 9007, self.time)
                self.api.get_chat_member.return_value = {'status': status}
                self.expire()
                self.assertIsNone(self.db.pending_join(-123, 7))
        self.api.decline_chat_join_request.assert_not_called()
        self.checker.verify.assert_not_called()

    def test_restricted_existing_member_is_not_declined(self):
        self.api.get_chat_member.return_value = {'status': 'restricted', 'is_member': True}
        self.expire()
        self.api.decline_chat_join_request.assert_not_called()

    def test_reapply_gets_new_five_minutes_and_can_bind(self):
        self.expire()
        self.time += 1
        self.db.remember_pending_join(-123, 7, 9007, self.time)
        self.assertEqual(self.time+300, self.db.pending_join(-123, 7)['expires_at'])
        self.cleanup.run_due()
        self.assertEqual(1, self.api.decline_chat_join_request.call_count)
        self.checker.verify.return_value = bot.VerificationResult(True, 'bound')
        self.assertEqual((['Test'], False), bot.approve_pending_joins(self.api, self.db, self.checker, 7))
        self.assertIsNone(self.db.pending_join(-123, 7))

    def test_old_cleanup_snapshot_does_not_reject_new_application(self):
        self.time += 300
        stale = self.db.expired_pending_joins()[0]
        self.db.remember_pending_join(-123, 7, 9007, self.time)
        self.cleanup.resolve(stale)
        self.api.decline_chat_join_request.assert_not_called()
        self.assertEqual(self.time, self.db.pending_join(-123, 7)['requested_at'])

    def test_duplicate_and_older_updates_do_not_extend_deadline(self):
        self.time += 150
        self.assertFalse(self.db.remember_pending_join(-123, 7, 9007, self.time-150))
        self.assertFalse(self.db.remember_pending_join(-123, 7, 9007, self.time-151))
        self.assertEqual(self.time+150, self.db.pending_join(-123, 7)['expires_at'])

    def test_new_application_does_not_delete_other_expired_records(self):
        self.time += 300
        self.db.remember_pending_join(-456, 8, 9008, self.time)
        self.assertIsNotNone(self.db.pending_join(-123, 7))

    def test_expired_application_survives_restart(self):
        self.time += 300
        reopened = bot.AppDatabase(self.db.path)
        worker = bot.JoinRequestCleanup(self.api, reopened, self.checker)
        worker.run_due()
        self.api.decline_chat_join_request.assert_called_once_with(-123, 7)

    def test_preserves_existing_long_deadline_on_upgrade(self):
        with self.db.connection() as conn:
            conn.execute('UPDATE pending_joins SET expires_at=requested_at+86400')
        self.expire()
        self.api.decline_chat_join_request.assert_not_called()
        self.assertIsNotNone(self.db.pending_join(-123, 7))

    def test_disabled_group_is_not_processed(self):
        self.db.save_group({'chat_id': -123, 'enabled': 0, 'verify_join': 1})
        self.expire()
        self.api.get_chat_member.assert_not_called()

    def test_verification_off_is_not_processed(self):
        self.db.save_group({'chat_id': -123, 'enabled': 1, 'verify_join': 0})
        self.expire()
        self.api.get_chat_member.assert_not_called()

    def test_unmanaged_group_is_not_processed(self):
        with patch.dict(os.environ, {'TELEGRAM_JOIN_ONBOARDING_CHAT_IDS': '-456'}):
            self.expire()
        self.api.get_chat_member.assert_not_called()

    def test_disallowed_group_is_not_processed(self):
        with patch.dict(os.environ, {'TELEGRAM_ALLOWED_CHAT_IDS': '-456'}):
            self.expire()
        self.api.get_chat_member.assert_not_called()

    def test_flag_change_during_network_check_prevents_decline(self):
        def verify(_):
            self.db.save_group({'chat_id': -123, 'enabled': 1, 'verify_join': 0})
            return bot.VerificationResult(False, 'not bound')
        self.checker.verify.side_effect = verify
        self.expire()
        self.api.decline_chat_join_request.assert_not_called()

    def test_binding_approval_wins_over_inflight_cleanup(self):
        def verify(_):
            checker = Mock(spec=bot.PanelUserChecker)
            checker.verify.return_value = bot.VerificationResult(True, 'bound')
            bot.approve_pending_joins(self.api, self.db, checker, 7)
            return bot.VerificationResult(False, 'stale result')
        self.checker.verify.side_effect = verify
        self.expire()
        self.api.approve_chat_join_request.assert_called_once_with(-123, 7)
        self.api.decline_chat_join_request.assert_not_called()

    def test_cleanup_does_not_block_other_users_during_network_wait(self):
        entered, release, finished = threading.Event(), threading.Event(), threading.Event()
        def verify(_):
            entered.set()
            release.wait(3)
            return bot.VerificationResult(False, 'not bound')
        self.checker.verify.side_effect = verify
        self.time += 300
        worker = threading.Thread(target=self.cleanup.run_due)
        worker.start()
        try:
            self.assertTrue(entered.wait(1))
            def save():
                self.db.remember_pending_join(-456, 8, 9008, self.time)
                finished.set()
            writer = threading.Thread(target=save)
            writer.start()
            self.assertTrue(finished.wait(1))
            writer.join(1)
        finally:
            release.set(); worker.join(3)

    def test_guard_registry_does_not_leak(self):
        for user_id in range(100):
            with self.db.join_guard(-123, user_id):
                with self.db.join_guard(-123, user_id):
                    pass
        self.assertEqual({}, self.db.join_locks)

    def test_scheduler_starts_and_stops(self):
        with patch.object(self.cleanup, 'run_due') as run:
            called = threading.Event()
            run.side_effect = called.set
            self.cleanup.start()
            self.assertTrue(called.wait(1))
            self.cleanup.stop()
            self.assertFalse(self.cleanup.thread.is_alive())

    def test_decline_requires_confirmed_telegram_success(self):
        api = bot.TelegramBot('fixture')
        with patch.object(api, 'request', return_value={'ok': True, 'result': False}):
            with self.assertRaises(RuntimeError):
                api.decline_chat_join_request(-123, 7)

    def test_decline_transport_uses_only_decline_method(self):
        api = bot.TelegramBot('fixture')
        with patch.object(api, 'request', return_value={'ok': True, 'result': True}) as request:
            api.decline_chat_join_request(-123, 7)
        request.assert_called_once_with('declineChatJoinRequest', {'chat_id': -123, 'user_id': 7})


if __name__ == '__main__':
    unittest.main()
