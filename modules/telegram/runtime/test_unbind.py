import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import Mock, patch

import bot
from panel_binding import BindingError, PanelBindingClient


class UnbindClientTests(unittest.TestCase):
    def setUp(self):
        self.client = PanelBindingClient()
        self.call = Mock(return_value={'status': 'confirmation_required', 'confirmation_token': 'a' * 32,
                                       'expires_in': 300, 'emailMasked': 'me***@example.invalid'})
        self.client.call = self.call
        clock = patch('panel_binding.time.monotonic', return_value=1000)
        self.clock = clock.start(); self.addCleanup(clock.stop)

    def test_prepare_masks_email_and_does_not_unbind(self):
        result = self.client.prepare_unbind(7)
        self.assertEqual({'status': 'confirmation_required', 'emailMasked': 'me***@example.invalid'}, result)
        self.call.assert_called_once_with('email/unbind/prepare', {'telegram_id': 7})

    def test_confirmation_is_user_specific_and_expires(self):
        self.client.prepare_unbind(7); self.call.reset_mock()
        with self.assertRaises(BindingError):
            self.client.confirm_unbind(8)
        self.clock.return_value = 1300
        with self.assertRaises(BindingError):
            self.client.confirm_unbind(7)
        self.call.assert_not_called()
        self.assertEqual({}, self.client.unbind_confirmations)

    def test_cancel_never_calls_panel_and_confirmation_is_no_longer_valid(self):
        self.client.prepare_unbind(7); self.call.reset_mock()
        self.client.cancel_unbind(7)
        with self.assertRaises(BindingError):
            self.client.confirm_unbind(7)
        self.call.assert_not_called()

    def test_success_clears_only_own_resend_and_confirmation_context(self):
        self.client.prepare_unbind(7)
        self.client.prepare_unbind(8)
        self.client.email_requests = {7: {'email': 'mine@example.invalid'}, 8: {'email': 'other@example.invalid'}}
        self.call.return_value = {'status': 'unbound', 'allowed': False}
        self.assertEqual('unbound', self.client.confirm_unbind(7)['status'])
        action, payload = self.call.call_args.args
        self.assertEqual('email/unbind/confirm', action)
        self.assertEqual(7, payload['telegram_id'])
        self.assertEqual('a' * 32, payload['confirmation_token'])
        self.assertEqual({8}, set(self.client.email_requests))
        self.assertEqual({8}, set(self.client.unbind_confirmations))

    def test_retry_reuses_request_id_when_response_was_lost(self):
        self.client.prepare_unbind(7)
        self.call.side_effect = [BindingError('offline'), {'status': 'unbound', 'allowed': False}]
        with self.assertRaises(BindingError):
            self.client.confirm_unbind(7)
        first = self.call.call_args.args[1]
        self.client.confirm_unbind(7)
        self.assertEqual(first, self.call.call_args.args[1])

    def test_invalid_success_response_does_not_clear_state(self):
        self.client.prepare_unbind(7)
        for result in [{'status': 'unbound', 'allowed': 0}, {'status': 'bound', 'allowed': False}, {}]:
            self.call.return_value = result
            with self.assertRaises(BindingError):
                self.client.confirm_unbind(7)
            self.assertIn(7, self.client.unbind_confirmations)

    def test_malformed_prepare_does_not_enable_confirmation(self):
        for change in [{'confirmation_token': 'bad'}, {'expires_in': True}, {'expires_in': 301},
                       {'emailMasked': None}, {'status': 'bound'}]:
            self.call.return_value = dict(status='confirmation_required', confirmation_token='a' * 32,
                                          expires_in=300, emailMasked='me***@example.invalid')
            self.call.return_value.update(change)
            with self.assertRaises(BindingError):
                self.client.prepare_unbind(7)
            self.assertNotIn(7, self.client.unbind_confirmations)

    def test_not_bound_clears_old_confirmation_without_changing_resend(self):
        self.client.prepare_unbind(7)
        self.call.return_value = {'status': 'not_bound'}
        self.assertEqual({'status': 'not_bound'}, self.client.prepare_unbind(7))
        self.assertNotIn(7, self.client.unbind_confirmations)


class UnbindBotTests(unittest.TestCase):
    def setUp(self):
        env = patch.dict(os.environ, {'TELEGRAM_JOIN_ONBOARDING_CHAT_IDS': '-123',
                                     'TELEGRAM_ALLOWED_CHAT_IDS': ''})
        env.start(); self.addCleanup(env.stop)
        temp = tempfile.TemporaryDirectory(); self.addCleanup(temp.cleanup)
        self.db = bot.AppDatabase(Path(temp.name) / 'test.sqlite3')
        self.api = Mock(spec=bot.TelegramBot)
        self.checker = Mock(spec=bot.PanelUserChecker)
        self.checker.email_client = Mock(spec=PanelBindingClient)
        self.checker.email_client.prepare_unbind.return_value = {
            'status': 'confirmation_required', 'emailMasked': 'me***@example.invalid'}

    def dispatch(self, text, private=True, user=7):
        bot.handle_message(self.api, self.db, self.checker, set(), {
            'chat': {'id': user if private else -123, 'type': 'private' if private else 'supergroup'},
            'message_id': 1, 'from': {'id': user}, 'text': text})

    def test_start_exposes_button_only_in_private(self):
        self.dispatch('/start')
        self.assertEqual(bot.binding_account_keyboard(), self.api.send_message.call_args.kwargs['reply_markup'])
        self.api.reset_mock()
        self.dispatch('/start', private=False)
        self.assertIsNone(self.api.send_message.call_args.kwargs['reply_markup'])

    def test_preview_requires_confirmation_and_logs_no_email(self):
        self.dispatch('解绑邮箱')
        self.checker.email_client.prepare_unbind.assert_called_once_with(7)
        self.checker.email_client.confirm_unbind.assert_not_called()
        message = self.api.send_message.call_args.args[1]
        self.assertIn('me***@example.invalid', message)
        self.assertIn('5分钟', message)
        self.assertIn('确认解绑', str(self.api.send_message.call_args.kwargs['reply_markup']))
        self.assertNotIn('example.invalid', str([dict(r) for r in self.db.list_events()]))

    def test_confirmation_preserves_manual_rules_pending_joins_and_group_flags(self):
        self.db.save_group({'chat_id': -123, 'enabled': 1, 'verify_join': 1, 'verify_message': 1})
        self.db.save_registered_user({'telegram_id': 7, 'status': 'blocked', 'note': 'keep'})
        self.db.remember_pending_join(-123, 7, 9007, bot.now_ts())
        before_user = dict(self.db.get_registered_user(7))
        before_pending = [dict(r) for r in self.db.pending_joins_for(7)]
        self.dispatch('确认解绑')
        self.checker.email_client.confirm_unbind.assert_called_once_with(7)
        self.assertIn('需要重新绑定，请在这里发送网站注册邮箱', self.api.send_message.call_args.args[1])
        self.assertEqual(before_user, dict(self.db.get_registered_user(7)))
        self.assertEqual(before_pending, [dict(r) for r in self.db.pending_joins_for(7)])
        self.assertEqual(1, self.db.ensure_group(-123, '')['verify_join'])
        self.api.kick_chat_member.assert_not_called()
        self.api.approve_chat_join_request.assert_not_called()
        self.api.decline_chat_join_request.assert_not_called()
        self.checker.email_client.request_code.assert_not_called()

    def test_cancel_does_not_unbind_or_send_email(self):
        self.dispatch('取消解绑')
        self.checker.email_client.cancel_unbind.assert_called_once_with(7)
        self.checker.email_client.confirm_unbind.assert_not_called()
        self.checker.email_client.request_code.assert_not_called()
        self.assertIn('请点击“获取订阅”', self.api.send_message.call_args.args[1])

    def test_group_buttons_never_call_unbind_api(self):
        for text in ['解绑邮箱', '确认解绑', '取消解绑']:
            self.dispatch(text, private=False)
        self.checker.email_client.prepare_unbind.assert_not_called()
        self.checker.email_client.confirm_unbind.assert_not_called()
        self.checker.email_client.cancel_unbind.assert_not_called()

    def test_failed_confirmation_does_not_claim_unbound(self):
        self.checker.email_client.confirm_unbind.side_effect = BindingError('确认已过期')
        self.dispatch('确认解绑')
        self.assertIn('确认已过期', self.api.send_message.call_args.args[1])
        self.assertNotIn('需要重新绑定，请在这里发送网站注册邮箱', self.api.send_message.call_args.args[1])
        self.assertEqual('unbind_failed', self.db.list_events()[0]['action'])

    def test_not_bound_does_not_offer_confirmation(self):
        self.checker.email_client.prepare_unbind.return_value = {'status': 'not_bound'}
        self.dispatch('解绑邮箱')
        self.assertIn('请直接发送网站注册邮箱', self.api.send_message.call_args.args[1])
        self.assertNotIn('确认解绑', str(self.api.send_message.call_args.kwargs['reply_markup']))


if __name__ == '__main__':
    unittest.main()
