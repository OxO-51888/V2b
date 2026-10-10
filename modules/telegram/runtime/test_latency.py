import contextlib
import io
import json
import os
from pathlib import Path
import tempfile
import threading
import unittest
from unittest.mock import Mock, call, patch

import bot


class LatencyTests(unittest.TestCase):
    def setUp(self):
        self.environment = patch.dict(os.environ, {
            'TELEGRAM_JOIN_ONBOARDING_CHAT_IDS': '-123', 'TELEGRAM_ALLOWED_CHAT_IDS': '',
            'TELEGRAM_TIMING_LOGS': 'true', 'TELEGRAM_POLL_TIMEOUT': '5', 'TELEGRAM_POLL_INTERVAL': '1',
            'PANEL_EMAIL_BINDING_URL': '', 'PANEL_EMAIL_BINDING_SECRET': '',
        })
        self.environment.start()
        self.addCleanup(self.environment.stop)
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.db = bot.AppDatabase(Path(temporary.name) / 'test.sqlite3')
        self.db.save_group({'chat_id': -123, 'title': 'Test', 'enabled': 1, 'verify_join': 1, 'verify_message': 1})
        self.api = Mock(spec=bot.TelegramBot)
        self.api.get_chat_member.return_value = {'status': 'left'}
        self.checker = Mock(spec=bot.PanelUserChecker)
        self.checker.verify.return_value = bot.VerificationResult(False, 'not registered')
        self.request = {'chat': {'id': -123, 'title': 'Test'}, 'from': {'id': 7},
                        'user_chat_id': 9007, 'date': bot.now_ts()}

    def dispatch(self):
        bot.handle_join_request(self.api, self.db, self.checker, set(), self.request)

    def test_private_guidance_arrives_while_panel_check_is_still_blocked(self):
        sent = threading.Event()
        checking = threading.Event()
        release = threading.Event()
        errors = []
        self.api.send_message.side_effect = lambda *args, **kwargs: sent.set()

        def verify(_):
            checking.set()
            if not release.wait(5):
                raise AssertionError('Test failed to release panel fixture')
            return bot.VerificationResult(False, 'not registered')

        def handle():
            try:
                self.dispatch()
            except Exception as exc:
                errors.append(exc)

        self.checker.verify.side_effect = verify
        worker = threading.Thread(target=handle)
        worker.start()
        try:
            self.assertTrue(sent.wait(1), 'Private guidance was blocked behind verification')
            self.assertTrue(checking.wait(1))
            self.assertTrue(worker.is_alive())
            self.api.approve_chat_join_request.assert_not_called()
            self.api.decline_chat_join_request.assert_not_called()
        finally:
            release.set()
            worker.join(5)
        self.assertFalse(worker.is_alive())
        self.assertEqual([], errors)

    def test_approval_is_after_both_guidance_and_successful_verification(self):
        sequence = []
        self.api.send_message.side_effect = lambda *args, **kwargs: sequence.append('guidance')
        self.checker.verify.side_effect = lambda _: sequence.append('verify') or bot.VerificationResult(True, 'registered')
        self.api.approve_chat_join_request.side_effect = lambda *args: sequence.append('approve')
        self.dispatch()
        self.assertEqual(['guidance', 'verify', 'approve'], sequence)
        self.assertEqual([], self.db.pending_joins_for(7))

    def test_failed_approval_remains_retryable_even_for_already_bound_user(self):
        self.checker.verify.return_value = bot.VerificationResult(True, 'registered')
        self.api.approve_chat_join_request.side_effect = RuntimeError('fixture outage')
        self.dispatch()
        self.assertEqual(1, len(self.db.pending_joins_for(7)))
        self.api.approve_chat_join_request.side_effect = None
        self.assertEqual((['Test'], False), bot.approve_pending_joins(self.api, self.db, self.checker, 7))
        self.assertEqual([], self.db.pending_joins_for(7))

    def test_block_during_verification_cannot_be_overridden_by_old_allowed_result(self):
        def verify(_):
            self.db.save_registered_user({'telegram_id': 7, 'status': 'blocked'})
            return bot.VerificationResult(True, 'stale allowed result')

        self.checker.verify.side_effect = verify
        self.dispatch()
        self.api.approve_chat_join_request.assert_not_called()
        self.api.decline_chat_join_request.assert_called_once_with(-123, 7)

    def test_duplicate_requests_do_not_repeat_early_guidance(self):
        self.dispatch()
        self.dispatch()
        self.api.send_message.assert_called_once()
        self.api.approve_chat_join_request.assert_not_called()

    def test_telegram_timing_contains_no_payload_or_token(self):
        api = bot.TelegramBot('secret-token-fixture')
        out = io.StringIO()
        with patch.object(api, '_request', return_value={'ok': True, 'result': True}), contextlib.redirect_stdout(out):
            api.request('sendMessage', {'text': 'private@example.invalid 123456'})
        log = json.loads(out.getvalue().removeprefix('timing '))
        self.assertEqual('sendMessage', log['method'])
        self.assertTrue(log['ok'])
        for secret in ['secret-token-fixture', 'private@example.invalid', '123456']:
            self.assertNotIn(secret, out.getvalue())

    def test_failed_telegram_calls_are_timed_without_exception_content(self):
        api = bot.TelegramBot('fixture')
        out = io.StringIO()
        with patch.object(api, '_request', side_effect=RuntimeError('private error fixture')), contextlib.redirect_stdout(out):
            with self.assertRaises(RuntimeError):
                api.request('sendMessage', {'text': 'private'})
        self.assertFalse(json.loads(out.getvalue().removeprefix('timing '))['ok'])
        self.assertNotIn('private error fixture', out.getvalue())

    def run_loop(self, updates):
        self.api.get_me.return_value = {'username': 'fixture'}
        self.api.get_updates.side_effect = updates + [KeyboardInterrupt]
        with patch.dict(os.environ, {'TELEGRAM_BOT_TOKEN': 'fixture', 'BOT_ADMIN_DB': str(self.db.path),
                                    'WEB_ADMIN_ENABLE': 'false', 'TELEGRAM_DROP_PENDING_UPDATES': 'false'}), \
                patch.object(bot, 'TelegramBot', return_value=self.api), \
                patch.object(bot, 'load_state', return_value={'offset': 4}), \
                patch.object(bot, 'save_state') as state, \
                patch.object(bot, 'handle_message') as handler, \
                patch.object(bot.time, 'sleep') as sleep:
            out = io.StringIO()
            with contextlib.redirect_stdout(out):
                bot.run_bot()
        return state, handler, sleep, out.getvalue()

    def test_nonempty_batches_have_no_extra_sleep_and_keep_offsets(self):
        message = {'chat': {'id': 7, 'type': 'private'}, 'message_id': 1, 'from': {'id': 7},
                   'date': bot.now_ts(), 'text': 'private@example.invalid 123456'}
        state, handler, sleep, output = self.run_loop([
            [{'update_id': 5, 'message': message}], [{'update_id': 6, 'message': message}],
        ])
        self.assertEqual([call({'offset': 6}), call({'offset': 7})], state.call_args_list)
        self.assertEqual(2, handler.call_count)
        sleep.assert_not_called()
        self.assertEqual([call(offset=4, timeout=5), call(offset=6, timeout=5), call(offset=7, timeout=5)], self.api.get_updates.call_args_list)
        self.assertEqual(2, output.count('update_received'))
        self.assertNotIn('private@example.invalid', output)
        self.assertNotIn('123456', output)

    def test_idle_and_network_errors_keep_backoff_without_double_sleep(self):
        for updates in [[[]], [TimeoutError('fixture')], [RuntimeError('fixture')]]:
            with self.subTest(updates=updates):
                _, _, sleep, _ = self.run_loop(updates)
                sleep.assert_called_once()

    def test_poll_timeout_is_bounded(self):
        for value, expected in [('0', 1), ('500', 30), ('bad', 5)]:
            with self.subTest(value=value), patch.dict(os.environ, {'TELEGRAM_POLL_TIMEOUT': value}):
                self.api.reset_mock()
                self.run_loop([])
                self.api.get_updates.assert_called_once_with(offset=4, timeout=expected)


if __name__ == '__main__':
    unittest.main()
