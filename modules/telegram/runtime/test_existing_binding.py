from pathlib import Path
import tempfile
import unittest
from unittest.mock import Mock

import bot
from panel_binding import BindingError, PanelBindingClient


class ExistingBindingTests(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.db = bot.AppDatabase(Path(temp.name) / 'test.sqlite3')
        self.db.save_group({'chat_id': -123, 'title': 'Test', 'enabled': 1,
                            'verify_join': 1, 'verify_message': 0})
        self.api = Mock(spec=bot.TelegramBot)
        self.api.send_message.return_value = {'ok': True, 'result': {'message_id': 99}}
        self.client = PanelBindingClient()
        self.client.call = Mock(return_value={'allowed': True, 'emailMasked': 'me***@example.invalid'})
        self.checker = Mock(spec=bot.PanelUserChecker)
        self.checker.email_client = self.client

    def dispatch(self, text, private=True, user=7):
        bot.handle_message(self.api, self.db, self.checker, set(), {
            'chat': {'id': user if private else -123, 'type': 'private' if private else 'supergroup'},
            'message_id': 1, 'from': {'id': user}, 'text': text})

    def assert_read_only_check(self, user=7):
        self.client.call.assert_called_once_with('verify', {'telegram_id': user, 'require_active_plan': False})
        self.checker.verify.assert_not_called()
        self.api.approve_chat_join_request.assert_not_called()
        self.api.decline_chat_join_request.assert_not_called()
        self.api.kick_chat_member.assert_not_called()

    def assert_already_bound(self):
        self.api.send_message.assert_called_once()
        text = self.api.send_message.call_args.args[1]
        self.assertIn('请点击“获取订阅”', text)
        self.assertIn('点击“查看套餐”', text)
        self.assertIn('请点击“解绑邮箱”并确认', text)
        self.assertEqual(bot.binding_account_keyboard(), self.api.send_message.call_args.kwargs['reply_markup'])

    def test_bound_button_displays_mask_and_preserves_all_local_state(self):
        self.db.save_registered_user({'telegram_id': 7, 'status': 'allowed'})
        self.db.remember_pending_join(-123, 7, 9007, bot.now_ts())
        before_user = dict(self.db.get_registered_user(7))
        before_pending = [dict(row) for row in self.db.pending_joins_for(7)]
        self.client.email_requests[7] = {'email': 'member@example.invalid', 'requested_at': 1}
        self.client.unbind_confirmations[7] = {'token': 'existing'}
        self.dispatch('绑定邮箱')
        self.assert_already_bound()
        self.assert_read_only_check()
        self.assertIn('me***@example.invalid', self.api.send_message.call_args.args[1])
        self.assertEqual(before_user, dict(self.db.get_registered_user(7)))
        self.assertEqual(before_pending, [dict(row) for row in self.db.pending_joins_for(7)])
        self.assertEqual('existing', self.client.unbind_confirmations[7]['token'])
        self.assertEqual('member@example.invalid', self.client.email_requests[7]['email'])
        self.assertEqual([], self.db.list_events())

    def test_same_or_different_email_cannot_start_another_email_request(self):
        for email in ['member@example.invalid', 'another@example.invalid']:
            with self.subTest(email=email):
                self.api.reset_mock()
                self.client.call.reset_mock()
                self.dispatch(email)
                self.assert_read_only_check()
                self.assert_already_bound()
                self.assertNotIn(email, self.api.send_message.call_args.args[1])
        self.assertEqual({}, self.client.email_requests)

    def test_legacy_bind_commands_cannot_bypass_check(self):
        for text in ['/绑定', '/bind', '/绑定 another@example.invalid', '/bind@fixture another@example.invalid']:
            with self.subTest(text=text):
                self.api.reset_mock()
                self.client.call.reset_mock()
                self.dispatch(text)
                self.assert_read_only_check()
                self.assert_already_bound()

    def test_resend_button_does_not_send_for_bound_account(self):
        self.dispatch('重新发送验证码')
        self.assert_read_only_check()
        self.assert_already_bound()

    def test_missing_mask_still_stops_duplicate_binding(self):
        for mask in ['', None, 123]:
            with self.subTest(mask=mask):
                self.api.reset_mock()
                self.client.call.reset_mock()
                self.client.call.return_value = {'allowed': True, 'emailMasked': mask}
                self.dispatch('绑定邮箱')
                self.assert_read_only_check()
                self.assert_already_bound()

    def test_lookup_failure_never_falls_through_to_email(self):
        self.client.call.side_effect = BindingError('面板连接暂时不可用，请稍后重试')
        for text in ['绑定邮箱', 'another@example.invalid', '重新发送验证码']:
            with self.subTest(text=text):
                self.api.reset_mock()
                self.client.call.reset_mock()
                self.dispatch(text)
                self.assert_read_only_check()
                self.assertIn('暂时不可用', self.api.send_message.call_args.args[1])
                self.assertNotIn('请打开注册邮箱', self.api.send_message.call_args.args[1])
        self.assertEqual({}, self.client.email_requests)

    def test_malformed_lookup_never_falls_through_to_email(self):
        for data in [{}, {'allowed': 'false'}, {'allowed': None}, {'allowed': 0}]:
            with self.subTest(data=data):
                self.api.reset_mock()
                self.client.call.reset_mock()
                self.client.call.return_value = data
                self.dispatch('another@example.invalid')
                self.assert_read_only_check()
                self.assertIn('验证结果异常', self.api.send_message.call_args.args[1])
                self.assertEqual({}, self.client.email_requests)

    def test_unbound_button_remains_read_only_and_shows_instructions(self):
        self.client.call.return_value = {'allowed': False, 'emailMasked': ''}
        self.dispatch('绑定邮箱')
        self.assert_read_only_check()
        self.assertIn('请直接在本私聊发送', self.api.send_message.call_args.args[1])
        self.assertEqual({}, self.client.email_requests)

    def test_manual_allow_is_not_mistaken_for_email_binding(self):
        self.db.save_registered_user({'telegram_id': 7, 'status': 'allowed'})
        self.checker.verify.return_value = bot.VerificationResult(True, 'manual allow')
        self.client.call.side_effect = [{'allowed': False}, {'status': 'queued'}]
        self.dispatch('member@example.invalid')
        self.assertEqual(['verify', 'email/request'], [call.args[0] for call in self.client.call.call_args_list])
        request = self.client.call.call_args.args[1]
        self.assertEqual(7, request['telegram_id'])
        self.assertEqual('member@example.invalid', request['email'])
        self.assertIn('请打开注册邮箱', self.api.send_message.call_args.args[1])
        self.checker.verify.assert_not_called()

    def test_binding_state_is_refreshed_after_unbind(self):
        self.dispatch('绑定邮箱')
        self.assert_already_bound()
        self.client.call.side_effect = [{'allowed': False}, {'status': 'queued'}]
        self.dispatch('new@example.invalid')
        self.assertEqual(['verify', 'verify', 'email/request'], [call.args[0] for call in self.client.call.call_args_list])
        self.assertEqual('new@example.invalid', self.client.email_requests[7]['email'])

    def test_manual_block_remains_effective_without_contacting_panel(self):
        self.db.save_registered_user({'telegram_id': 7, 'status': 'blocked'})
        for text in ['绑定邮箱', 'member@example.invalid', '重新发送验证码']:
            self.dispatch(text)
            self.assertIn('不可绑定', self.api.send_message.call_args.args[1])
        self.client.call.assert_not_called()

    def test_group_cannot_look_up_binding_or_expose_mask(self):
        for text in ['绑定邮箱', '/bind member@example.invalid', 'member@example.invalid', '重新发送验证码']:
            self.dispatch(text, private=False)
        self.client.call.assert_not_called()
        self.assertNotIn('me***@example.invalid', str(self.api.mock_calls))

    def test_code_confirmation_still_has_no_extra_precheck(self):
        self.client.call.return_value = {'allowed': True, 'status': 'bound'}
        self.dispatch('001234')
        self.client.call.assert_called_once()
        self.assertEqual('email/confirm', self.client.call.call_args.args[0])
        self.assertIn('请点击“获取订阅”', self.api.send_message.call_args.args[1])

    def test_check_uses_message_sender_not_other_account(self):
        self.dispatch('绑定邮箱', user=8)
        self.assert_read_only_check(user=8)
        self.assertEqual(8, self.api.send_message.call_args.args[0])


if __name__ == '__main__':
    unittest.main()
