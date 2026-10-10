import io
import json
import os
from pathlib import Path
import tempfile
import unittest
import urllib.error
from unittest.mock import Mock, patch

import bot
from panel_binding import BindingError, PanelBindingClient


class ResendClientTests(unittest.TestCase):
    def setUp(self):
        env = patch.dict(os.environ, {'PANEL_EMAIL_BINDING_URL': 'https://example.invalid/api/v1/bot/telegram',
                                    'PANEL_EMAIL_BINDING_SECRET': 's' * 48})
        env.start(); self.addCleanup(env.stop)
        clock = patch('panel_binding.time.monotonic', return_value=1000)
        self.clock = clock.start(); self.addCleanup(clock.stop)
        self.client = PanelBindingClient()
        self.call = patch.object(self.client, 'call', return_value={'status': 'queued'}).start()
        self.addCleanup(patch.stopall)

    def test_resend_reuses_only_that_users_email_and_new_request_id(self):
        self.client.request_code(7, 'first@example.invalid')
        first = self.call.call_args.args[1]
        self.client.request_code(8, 'other@example.invalid')
        self.clock.return_value = 1601
        self.client.resend_code(7)
        latest = self.call.call_args.args[1]
        self.assertEqual('email/request', self.call.call_args.args[0])
        self.assertEqual('first@example.invalid', latest['email'])
        self.assertEqual(7, latest['telegram_id'])
        self.assertNotEqual(first['request_id'], latest['request_id'])

    def test_repeated_resend_and_changed_email_cannot_bypass_cooldown(self):
        self.client.request_code(7, 'first@example.invalid')
        self.call.reset_mock()
        for moment in [1000, 1001, 1059.9]:
            self.clock.return_value = moment
            for attempt in [lambda: self.client.resend_code(7),
                            lambda: self.client.request_code(7, 'other@example.invalid')]:
                with self.assertRaisesRegex(BindingError, '等待.*秒'):
                    attempt()
        self.call.assert_not_called()
        self.clock.return_value = 1060
        self.client.resend_code(7)
        self.call.assert_called_once()

    def test_hourly_limit_from_panel_is_preserved(self):
        self.client.request_code(7, 'member@example.invalid')
        self.clock.return_value = 1061
        self.call.side_effect = BindingError('获取次数过多，请一小时后重试')
        with self.assertRaisesRegex(BindingError, '一小时后重试'):
            self.client.resend_code(7)
        self.assertEqual(2, self.call.call_count)

    def test_expired_or_missing_resend_context_requires_email_without_sending(self):
        self.client.request_code(7, 'member@example.invalid')
        self.clock.return_value = 4600
        self.call.reset_mock()
        for user in [7, 8]:
            with self.assertRaisesRegex(BindingError, '重新发送网站注册邮箱'):
                self.client.resend_code(user)
        self.assertEqual({}, self.client.email_requests)
        self.call.assert_not_called()

    def test_failed_request_does_not_remember_email_or_claim_sent(self):
        self.call.side_effect = BindingError('发送繁忙，请稍后重试')
        with self.assertRaises(BindingError):
            self.client.request_code(7, 'member@example.invalid')
        self.assertEqual({}, self.client.email_requests)

    def test_successful_confirmation_clears_resend_context(self):
        self.client.request_code(7, 'member@example.invalid')
        self.call.return_value = {'allowed': True, 'status': 'bound'}
        self.client.confirm_code(7, '001234')
        self.assertEqual({}, self.client.email_requests)

    def test_expired_confirmation_gives_resend_hint_without_automatic_email(self):
        self.client.request_code(7, 'member@example.invalid')
        self.call.reset_mock()
        self.call.side_effect = BindingError('验证码已失效，请重新获取')
        with self.assertRaisesRegex(BindingError, '已过期或失效.*重新发送验证码'):
            self.client.confirm_code(7, '001234')
        self.assertEqual('email/confirm', self.call.call_args.args[0])
        self.call.assert_called_once()
        self.assertIsNotNone(self.client.recent_email_request(7))

    def test_wrong_code_and_transport_failure_are_not_misreported_as_expired(self):
        for reason in ['验证码不正确', '面板连接暂时不可用，请稍后重试']:
            self.call.side_effect = BindingError(reason)
            with self.assertRaises(BindingError) as error:
                self.client.confirm_code(7, '001234')
            self.assertEqual(reason, str(error.exception))


class BindingFeedbackTests(unittest.TestCase):
    def setUp(self):
        env = patch.dict(os.environ, {'PANEL_EMAIL_BINDING_URL': 'https://example.invalid/api/v1/bot/telegram',
                                    'PANEL_EMAIL_BINDING_SECRET': 's' * 48,
                                    'TELEGRAM_JOIN_ONBOARDING_CHAT_IDS': '-123', 'TELEGRAM_ALLOWED_CHAT_IDS': ''})
        env.start(); self.addCleanup(env.stop)
        temp = tempfile.TemporaryDirectory(); self.addCleanup(temp.cleanup)
        self.db = bot.AppDatabase(Path(temp.name) / 'test.sqlite3')
        self.api = Mock(spec=bot.TelegramBot)
        self.checker = Mock(spec=bot.PanelUserChecker)
        self.checker.email_client = PanelBindingClient()
        self.checker.email_client.opener = Mock()
        self.checker.verify.return_value = bot.VerificationResult(True, 'registered')

    def dispatch(self, text, chat_type='private', user=7):
        bot.handle_message(self.api, self.db, self.checker, set(), {
            'chat': {'id': user if chat_type == 'private' else -123, 'type': chat_type},
            'message_id': 4, 'from': {'id': user}, 'text': text})

    def test_actual_http_expiry_reaches_private_feedback_without_approving(self):
        body = json.dumps({'message': '验证码已失效，请重新获取'}).encode()
        self.checker.email_client.opener.open.side_effect = urllib.error.HTTPError(
            'https://example.invalid', 422, '', {}, io.BytesIO(body))
        self.db.remember_pending_join(-123, 7, 9007, bot.now_ts())
        self.dispatch('001234')
        self.api.send_message.assert_called_once()
        self.assertIn('已过期或失效', self.api.send_message.call_args.args[1])
        self.assertIn('重新发送验证码', self.api.send_message.call_args.args[1])
        self.assertEqual(bot.binding_resend_keyboard(), self.api.send_message.call_args.kwargs['reply_markup'])
        self.api.approve_chat_join_request.assert_not_called()
        self.checker.email_client.opener.open.assert_called_once()
        req = self.checker.email_client.opener.open.call_args.args[0]
        self.assertTrue(req.full_url.endswith('/email/confirm'))
        self.assertEqual('bind_failed', self.db.list_events()[0]['action'])
        self.assertNotIn('001234', str([dict(row) for row in self.db.list_events()]))

    def test_resend_keyboard_dispatches_only_for_private_user(self):
        self.checker.email_client = Mock(spec=PanelBindingClient)
        self.checker.email_client.verify.return_value = {'allowed': False}
        self.dispatch('重新发送验证码')
        self.checker.email_client.resend_code.assert_called_once_with(7)
        self.checker.email_client.request_code.assert_not_called()
        self.assertIn('至少间隔60秒', self.api.send_message.call_args.args[1])
        self.assertIn('每小时最多5次', self.api.send_message.call_args.args[1])

    def test_resend_in_group_does_not_send_email(self):
        self.checker.email_client = Mock(spec=PanelBindingClient)
        self.dispatch('重新发送验证码', 'supergroup')
        self.checker.email_client.resend_code.assert_not_called()
        self.checker.email_client.request_code.assert_not_called()
        self.api.send_message.assert_not_called()

    def test_blocked_user_cannot_use_resend(self):
        self.checker.email_client = Mock(spec=PanelBindingClient)
        self.db.save_registered_user({'telegram_id': 7, 'status': 'blocked'})
        self.dispatch('重新发送验证码')
        self.checker.email_client.resend_code.assert_not_called()
        self.assertIn('不可绑定', self.api.send_message.call_args.args[1])

    def test_resend_limit_displays_error_not_queued_confirmation(self):
        self.checker.email_client = Mock(spec=PanelBindingClient)
        self.checker.email_client.verify.return_value = {'allowed': False}
        self.checker.email_client.resend_code.side_effect = BindingError('请等待40秒后再重新发送验证码')
        self.dispatch('重新发送验证码')
        self.assertIn('等待40秒', self.api.send_message.call_args.args[1])
        self.assertNotIn('已提交', self.api.send_message.call_args.args[1])

    def test_malformed_code_gets_help_without_panel_request(self):
        for text in ['12345', '1234567', '123 456', '１２３４５６', '已过期']:
            self.api.reset_mock()
            self.dispatch(text)
            self.api.send_message.assert_called_once()
            self.assertIn('6位数字验证码', self.api.send_message.call_args.args[1])
        self.checker.email_client.opener.open.assert_not_called()

    def test_binding_success_precedes_panel_lookup_and_telegram_approval(self):
        self.db.save_group({'chat_id': -123, 'title': 'Test', 'enabled': 1, 'verify_join': 1})
        self.db.remember_pending_join(-123, 7, 9007, bot.now_ts())
        self.checker.email_client = Mock(spec=PanelBindingClient)
        sequence = []
        self.checker.email_client.confirm_code.side_effect = lambda *args: sequence.append('confirm')
        self.api.send_message.side_effect = lambda _, text, *args, **kwargs: sequence.append('success' if '请点击“获取订阅”' in text else 'join_result')
        self.checker.verify.side_effect = lambda _: sequence.append('verify') or bot.VerificationResult(True, 'registered')
        self.api.approve_chat_join_request.side_effect = lambda *args: sequence.append('approve')
        self.dispatch('001234')
        self.assertEqual(['confirm', 'success', 'verify', 'approve', 'join_result'], sequence)
        self.assertEqual(bot.binding_account_keyboard(), self.api.send_message.call_args_list[0].kwargs['reply_markup'])


class TelegramTransportTests(unittest.TestCase):
    def test_network_timeout_does_not_clip_long_poll_but_bounds_other_calls(self):
        api = bot.TelegramBot('fixture')
        response = Mock()
        response.read.return_value = b'{"ok":true,"result":[]}'
        response.__enter__ = Mock(return_value=response)
        response.__exit__ = Mock(return_value=False)
        cases = [('sendMessage', {'chat_id': 7, 'text': 'fixture'}, 10),
                 ('approveChatJoinRequest', {}, 10), ('getUpdates', {'timeout': 5}, 10),
                 ('getUpdates', {'timeout': 30}, 35)]
        with patch.object(api.opener, 'open', return_value=response) as request:
            for method, payload, expected in cases:
                api._request(method, payload)
                self.assertEqual(expected, request.call_args.kwargs['timeout'])

    def test_private_reply_survives_deleted_original_and_can_show_resend(self):
        api = bot.TelegramBot('fixture')
        with patch.object(api, 'request', return_value={'ok': True}) as request:
            api.send_message(7, 'fixture', 4, reply_markup=bot.binding_resend_keyboard())
            payload = request.call_args.args[1]
            self.assertTrue(payload['allow_sending_without_reply'])
            self.assertEqual(bot.binding_resend_keyboard(), payload['reply_markup'])


if __name__ == '__main__':
    unittest.main()
