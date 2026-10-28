import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import Mock, call, patch

import bot
from panel_binding import BindingError


BUTTONS = {'绑定邮箱', '获取订阅', '查看套餐', '重新发送验证码', '解绑邮箱', '重置订阅', '发布页'}


class PrivateButtonTests(unittest.TestCase):
    def setUp(self):
        env = patch.dict(os.environ, {'TELEGRAM_JOIN_ONBOARDING_CHAT_IDS': '-123',
                                     'TELEGRAM_ALLOWED_CHAT_IDS': ''})
        env.start()
        self.addCleanup(env.stop)
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.db = bot.AppDatabase(Path(temp.name) / 'test.sqlite3')
        self.db.save_group({'chat_id': -123, 'title': 'Test', 'enabled': 1,
                            'verify_join': 1, 'verify_message': 0})
        self.api = Mock(spec=bot.TelegramBot)
        self.checker = Mock(spec=bot.PanelUserChecker)
        self.checker.email_client = Mock(spec=bot.PanelBindingClient)
        self.checker.email_client.verify.return_value = {'allowed': False}
        self.checker.verify.return_value = bot.VerificationResult(True, 'registered')

    def dispatch(self, text, private=True, user=7):
        bot.handle_message(self.api, self.db, self.checker, set(), {
            'chat': {'id': user if private else -123, 'type': 'private' if private else 'supergroup'},
            'message_id': 1, 'from': {'id': user}, 'text': text})

    def assert_menu(self, call=None):
        markup = (call or self.api.send_message.call_args).kwargs['reply_markup']
        self.assertEqual(BUTTONS, {item['text'] for row in markup['keyboard'] for item in row})
        self.assertEqual([4, 3], [len(row) for row in markup['keyboard']])
        self.assertEqual([['发布页', '获取订阅', '查看套餐', '重置订阅'], ['绑定邮箱', '重新发送验证码', '解绑邮箱']],
                         [[item['text'] for item in row] for row in markup['keyboard']])
        self.assertFalse(markup['is_persistent'])
        self.assertFalse(markup['one_time_keyboard'])
        self.assertTrue(markup['resize_keyboard'])

    def pending(self, user=7):
        self.db.remember_pending_join(-123, user, 9000 + user, bot.now_ts())

    def test_first_join_shows_buttons_before_panel_lookup_without_email(self):
        def verify(_):
            self.api.send_message.assert_called_once()
            self.assert_menu()
            return bot.VerificationResult(False, 'not bound')

        self.checker.verify.side_effect = verify
        bot.handle_join_request(self.api, self.db, self.checker, set(), {
            'chat': {'id': -123, 'title': 'Test'}, 'from': {'id': 7},
            'user_chat_id': 9007, 'date': bot.now_ts()})
        self.assertEqual(9007, self.api.send_message.call_args.args[0])
        self.assertNotIn('/验证', self.api.send_message.call_args.args[1])
        self.checker.email_client.assert_not_called()
        self.assertEqual([], self.checker.email_client.mock_calls)
        self.api.approve_chat_join_request.assert_not_called()

    def test_ordinary_first_message_shows_buttons_without_start(self):
        self.dispatch('你好')
        self.api.send_message.assert_called_once()
        self.assert_menu()
        self.assertEqual([], self.checker.email_client.mock_calls)
        self.checker.verify.assert_not_called()

    def test_bind_button_only_displays_steps_without_mail_or_state_change(self):
        self.pending()
        before = [dict(row) for row in self.db.pending_joins_for(7)]
        self.dispatch('绑定邮箱')
        self.assert_menu()
        self.assertIn('网站注册邮箱', self.api.send_message.call_args.args[1])
        self.assertIn('垃圾邮件', self.api.send_message.call_args.args[1])
        self.assertEqual([call.verify(7, require_active=False)], self.checker.email_client.mock_calls)
        self.checker.verify.assert_not_called()
        self.assertEqual(before, [dict(row) for row in self.db.pending_joins_for(7)])

    def test_retry_button_approves_only_verified_callers_pending_request(self):
        self.pending()
        self.pending(8)
        self.dispatch('重试入群验证')
        self.checker.verify.assert_called_once_with(7)
        self.api.approve_chat_join_request.assert_called_once_with(-123, 7)
        self.assertEqual([], self.db.pending_joins_for(7))
        self.assertEqual(1, len(self.db.pending_joins_for(8)))
        self.assert_menu()
        self.assertEqual([], self.checker.email_client.mock_calls)

    def test_retry_without_pending_cannot_forge_join(self):
        self.dispatch('重试入群验证')
        self.api.approve_chat_join_request.assert_not_called()
        self.checker.verify.assert_not_called()
        self.assertIn('请先申请入群', self.api.send_message.call_args.args[1])
        self.assert_menu()

    def test_retry_failure_retains_menu_and_does_not_approve(self):
        self.pending()
        for result in [bot.VerificationResult(False, 'not registered'),
                       bot.VerificationResult(False, 'unavailable', unavailable=True)]:
            with self.subTest(result=result):
                self.checker.verify.return_value = result
                self.dispatch('重试入群验证')
                self.assertIn('返回群组查看', self.api.send_message.call_args.args[1])
                self.assertNotIn('重试入群验证', self.api.send_message.call_args.args[1])
                self.assert_menu()
                self.api.approve_chat_join_request.assert_not_called()
                self.assertEqual(1, len(self.db.pending_joins_for(7)))

    def test_retry_cannot_bypass_manual_block(self):
        self.pending()
        self.db.save_registered_user({'telegram_id': 7, 'status': 'blocked'})
        self.dispatch('重试入群验证')
        self.checker.verify.assert_not_called()
        self.api.approve_chat_join_request.assert_not_called()
        self.assert_menu()

    def test_group_button_text_never_runs_private_operations_or_shows_keyboard(self):
        self.pending()
        for text in BUTTONS:
            self.dispatch(text, private=False)
        self.assertEqual([], self.checker.email_client.mock_calls)
        self.api.approve_chat_join_request.assert_not_called()
        self.api.send_message.assert_not_called()

    def test_request_error_and_success_all_keep_menu(self):
        self.dispatch('fixture@example.invalid')
        self.checker.email_client.request_code.assert_called_once_with(7, 'fixture@example.invalid')
        self.assert_menu()
        self.checker.email_client.confirm_code.side_effect = BindingError('验证码已过期')
        self.dispatch('001234')
        self.assert_menu()
        self.checker.email_client.confirm_code.side_effect = None
        self.pending()
        self.dispatch('001234')
        for call in self.api.send_message.call_args_list:
            self.assert_menu(call)

    def test_unbind_still_needs_confirmation_and_cancel_restores_menu(self):
        self.checker.email_client.prepare_unbind.return_value = {
            'status': 'confirmation_required', 'emailMasked': 'fi***@example.invalid'}
        self.dispatch('解绑邮箱')
        self.checker.email_client.confirm_unbind.assert_not_called()
        markup = self.api.send_message.call_args.kwargs['reply_markup']
        self.assertEqual({'确认解绑', '取消解绑'}, {b['text'] for row in markup['inline_keyboard'] for b in row})
        self.dispatch('取消解绑')
        self.checker.email_client.cancel_unbind.assert_called_once_with(7)
        self.checker.email_client.confirm_unbind.assert_not_called()
        self.assert_menu()
        self.api.kick_chat_member.assert_not_called()

    def test_legacy_start_help_and_retry_keep_menu(self):
        for text in ['/start', '/help', '/验证']:
            with self.subTest(text=text):
                self.dispatch(text)
                self.assert_menu()

    def test_help_and_pending_feedback_never_advertise_removed_button(self):
        for text in [bot.bind_help_text(), bot.bound_help_text(), bot.join_approval_text([], True)]:
            self.assertNotIn('重试入群验证', text)
        for command in ['/start', '/help', '绑定邮箱']:
            self.dispatch(command)
            for sent in self.api.send_message.call_args_list:
                self.assertNotIn('重试入群验证', sent.args[1])
            self.assert_menu()


if __name__ == '__main__':
    unittest.main()
