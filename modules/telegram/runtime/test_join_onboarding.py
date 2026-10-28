import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import Mock, patch

import bot


class JoinOnboardingTests(unittest.TestCase):
    def setUp(self):
        environment = patch.dict(os.environ, {'TELEGRAM_JOIN_ONBOARDING_CHAT_IDS': '-123',
                                              'TELEGRAM_ALLOWED_CHAT_IDS': ''})
        environment.start(); self.addCleanup(environment.stop)
        self.temp = tempfile.TemporaryDirectory(); self.addCleanup(self.temp.cleanup)
        self.db = bot.AppDatabase(Path(self.temp.name) / 'test.sqlite3')
        self.db.save_group({'chat_id': -123, 'title': 'Clash test', 'enabled': 1, 'verify_join': 1, 'verify_message': 1})
        self.api = Mock(spec=bot.TelegramBot)
        self.api.get_chat_member.return_value = {'status': 'left'}
        self.checker = Mock(spec=bot.PanelUserChecker)
        self.checker.email_client = Mock(spec=bot.PanelBindingClient)
        self.checker.verify.return_value = bot.VerificationResult(False, 'not registered')
        self.join = {'chat': {'id': -123, 'title': 'Clash test'}, 'from': {'id': 7, 'first_name': 'Test'},
                     'user_chat_id': 9007, 'date': bot.now_ts()}

    def request(self, join=None, allowed=None):
        bot.handle_join_request(self.api, self.db, self.checker, allowed or set(), join or self.join)

    def private(self, text, user=7):
        bot.handle_message(self.api, self.db, self.checker, set(), {
            'chat': {'id': user, 'type': 'private'}, 'message_id': 1, 'from': {'id': user}, 'text': text})

    def test_first_join_gets_private_guidance_without_decline_or_approval(self):
        self.request()
        self.api.send_message.assert_called_once()
        self.assertEqual(9007, self.api.send_message.call_args.args[0])
        self.assertIn('注册邮箱', self.api.send_message.call_args.args[1])
        self.assertIn('Clash test', self.api.send_message.call_args.args[1])
        self.assertIn('直接发送6位验证码', self.api.send_message.call_args.args[1])
        self.assertIn('垃圾邮件', self.api.send_message.call_args.args[1])
        self.assertNotIn('/验证码', self.api.send_message.call_args.args[1])
        self.assertEqual(bot.binding_resend_keyboard(), self.api.send_message.call_args.kwargs['reply_markup'])
        self.api.approve_chat_join_request.assert_not_called()
        self.api.decline_chat_join_request.assert_not_called()
        self.api.kick_chat_member.assert_not_called()
        self.assertEqual(1, len(self.db.pending_joins_for(7)))

    def test_duplicate_and_out_of_order_updates_do_not_repeat_prompt(self):
        self.request(); self.request()
        self.request(dict(self.join, date=self.join['date'] - 1))
        self.api.send_message.assert_called_once()
        self.assertEqual(self.join['date'], self.db.pending_joins_for(7)[0]['requested_at'])

    def test_pending_request_survives_database_reopen(self):
        self.request()
        self.db = bot.AppDatabase(self.db.path)
        self.api.reset_mock(); self.request()
        self.api.send_message.assert_not_called()
        self.assertEqual(1, len(self.db.pending_joins_for(7)))

    def test_invalid_private_metadata_does_not_send_to_user_id(self):
        for change in [{'user_chat_id': None}, {'date': None}, {'user_chat_id': -1},
                       {'user_chat_id': True}, {'date': bot.now_ts() + 1000}]:
            with self.subTest(change=change):
                self.request(dict(self.join, **change))
        self.api.send_message.assert_not_called()
        self.api.decline_chat_join_request.assert_not_called()
        self.assertEqual([], self.db.pending_joins_for(7))

    def test_expired_contact_window_holds_request_without_sending(self):
        self.request(dict(self.join, date=bot.now_ts() - 301))
        self.api.send_message.assert_not_called()
        self.assertEqual(1, len(self.db.pending_joins_for(7)))
        self.checker.verify.return_value = bot.VerificationResult(True, 'registered')
        self.private('/验证')
        self.api.approve_chat_join_request.assert_called_once_with(-123, 7)

    def test_private_message_failure_does_not_reject_or_lose_request(self):
        self.api.send_message.side_effect = RuntimeError('cannot contact user')
        self.request()
        self.assertEqual('guidance_failed', self.db.list_events()[0]['action'])
        self.assertEqual(1, len(self.db.pending_joins_for(7)))
        self.api.decline_chat_join_request.assert_not_called()
        self.api.approve_chat_join_request.assert_not_called()

    def test_unavailable_panel_holds_and_guides_without_approval(self):
        self.checker.verify.return_value = bot.VerificationResult(False, 'offline', unavailable=True)
        self.request()
        self.api.send_message.assert_called_once()
        self.api.approve_chat_join_request.assert_not_called()
        self.api.decline_chat_join_request.assert_not_called()
        self.assertEqual(1, len(self.db.pending_joins_for(7)))

    def test_registered_join_is_approved_after_neutral_guidance(self):
        self.checker.verify.return_value = bot.VerificationResult(True, 'registered')
        self.request()
        self.api.approve_chat_join_request.assert_called_once_with(-123, 7)
        self.api.send_message.assert_called_once()
        self.assertIn('无需重复绑定', self.api.send_message.call_args.args[1])
        self.assertEqual([], self.db.pending_joins_for(7))

    def test_manual_block_is_not_replaced_by_pending_onboarding(self):
        self.db.save_registered_user({'telegram_id': 7, 'status': 'blocked'})
        self.checker.verify.return_value = bot.VerificationResult(True, 'stale prior check')
        self.request()
        self.api.decline_chat_join_request.assert_called_once_with(-123, 7)
        self.api.send_message.assert_not_called()
        self.assertEqual([], self.db.pending_joins_for(7))

    def test_other_group_keeps_existing_policy(self):
        self.db.save_group({'chat_id': -456, 'title': 'Other', 'enabled': 1, 'verify_join': 1})
        self.request(dict(self.join, chat={'id': -456, 'title': 'Other'}))
        self.api.decline_chat_join_request.assert_called_once_with(-456, 7)
        self.api.send_message.assert_not_called()

    def test_disabled_group_and_allowlist_prevent_onboarding(self):
        self.request(allowed={-456})
        self.db.save_group({'chat_id': -123, 'enabled': 1, 'verify_join': 0})
        self.request()
        self.checker.verify.assert_not_called()
        self.api.send_message.assert_not_called()
        self.assertEqual([], self.db.pending_joins_for(7))

    def test_confirmation_approves_only_actual_pending_user_and_is_not_logged(self):
        self.request(); self.api.reset_mock()
        self.checker.verify.return_value = bot.VerificationResult(True, 'registered')
        self.private(' 001234\n')
        self.checker.email_client.confirm_code.assert_called_once_with(7, '001234')
        self.api.approve_chat_join_request.assert_called_once_with(-123, 7)
        self.assertEqual([], self.db.pending_joins_for(7))
        self.assertIn('请返回「', self.api.send_message.call_args.args[1])
        self.assertNotIn('001234', str([dict(row) for row in self.db.list_events()]))

    def test_other_user_confirmation_cannot_approve_pending_user(self):
        self.request(); self.api.reset_mock()
        self.checker.verify.return_value = bot.VerificationResult(True, 'registered')
        self.private('123456', user=8)
        self.api.approve_chat_join_request.assert_not_called()
        self.assertEqual(1, len(self.db.pending_joins_for(7)))

    def test_failed_code_does_not_approve(self):
        self.request(); self.api.reset_mock()
        self.checker.email_client.confirm_code.side_effect = bot.BindingError('wrong code')
        self.private('000000')
        self.api.approve_chat_join_request.assert_not_called()
        self.assertEqual(1, len(self.db.pending_joins_for(7)))

    def test_post_binding_network_failure_keeps_retryable_request(self):
        self.request(); self.api.reset_mock()
        self.checker.verify.return_value = bot.VerificationResult(False, 'offline', unavailable=True)
        self.private('123456')
        self.api.approve_chat_join_request.assert_not_called()
        self.assertIn('请点击“获取订阅”', self.api.send_message.call_args_list[0].args[1])
        self.assertIn('返回群组查看', self.api.send_message.call_args.args[1])
        self.assertNotIn('重试入群验证', self.api.send_message.call_args.args[1])
        self.assertEqual(1, len(self.db.pending_joins_for(7)))

    def test_failed_telegram_approval_can_be_retried(self):
        self.request(); self.api.reset_mock()
        self.checker.verify.return_value = bot.VerificationResult(True, 'registered')
        self.api.approve_chat_join_request.side_effect = RuntimeError('temporary outage')
        self.private('123456')
        self.assertEqual(1, len(self.db.pending_joins_for(7)))
        self.assertNotIn('请返回「', self.api.send_message.call_args.args[1])
        self.api.approve_chat_join_request.side_effect = None
        self.private('/验证')
        self.assertEqual([], self.db.pending_joins_for(7))

    def test_already_approved_request_is_reconciled_without_rejoining(self):
        self.request(); self.api.reset_mock()
        self.checker.verify.return_value = bot.VerificationResult(True, 'registered')
        self.api.approve_chat_join_request.side_effect = RuntimeError('already participant')
        self.api.get_chat_member.return_value = {'status': 'member'}
        self.private('/验证')
        self.assertEqual([], self.db.pending_joins_for(7))
        self.api.kick_chat_member.assert_not_called()

    def test_ban_disable_and_allowed_group_changes_are_respected_at_confirmation(self):
        for mode in ['blocked', 'disabled', 'off', 'allowlist', 'onboarding_off']:
            with self.subTest(mode=mode):
                self.db.save_group({'chat_id': -123, 'enabled': 1, 'verify_join': 1})
                self.db.delete_registered_user(7)
                self.db.remember_pending_join(-123, 7, 9007, bot.now_ts())
                self.checker.verify.return_value = bot.VerificationResult(True, 'registered')
                self.api.reset_mock()
                env = {'TELEGRAM_ALLOWED_CHAT_IDS': '', 'TELEGRAM_JOIN_ONBOARDING_CHAT_IDS': '-123'}
                if mode == 'blocked': self.db.save_registered_user({'telegram_id': 7, 'status': 'blocked'})
                if mode == 'disabled': self.db.save_group({'chat_id': -123, 'enabled': 0, 'verify_join': 1})
                if mode == 'off': self.db.save_group({'chat_id': -123, 'enabled': 1, 'verify_join': 0})
                if mode == 'allowlist': env['TELEGRAM_ALLOWED_CHAT_IDS'] = '-456'
                if mode == 'onboarding_off': env['TELEGRAM_JOIN_ONBOARDING_CHAT_IDS'] = ''
                with patch.dict(os.environ, env): self.private('/验证')
                self.api.approve_chat_join_request.assert_not_called()

    def test_stale_requests_cannot_be_approved_and_start_payload_cannot_forge_one(self):
        self.db.remember_pending_join(-123, 7, 9007, bot.now_ts() - 86401)
        self.checker.verify.return_value = bot.VerificationResult(True, 'registered')
        self.private('/start join_-123')
        self.api.approve_chat_join_request.assert_not_called()
        self.assertEqual([], self.db.pending_joins_for(7))

    def test_approval_requires_confirmed_telegram_success(self):
        api = bot.TelegramBot('fixture-only')
        with patch.object(api, 'request', return_value={'ok': True, 'result': False}):
            with self.assertRaises(RuntimeError): api.approve_chat_join_request(-123, 7)


if __name__ == '__main__':
    unittest.main()
