from pathlib import Path
import tempfile
import unittest
from unittest.mock import Mock

import bot
from panel_binding import BindingError, PanelBindingClient


def plan_data(**values):
    return {'plan_name': 'Fixture plan', 'status': 'active', 'expires_at': None,
            'total_bytes': 5 * 1024**3, 'used_bytes': 2 * 1024**3,
            'remaining_bytes': 3 * 1024**3, **values}


class AccountReadClientTests(unittest.TestCase):
    def setUp(self):
        self.client = PanelBindingClient()
        self.client.call = Mock()

    def test_subscription_returns_only_valid_web_url_for_current_identity(self):
        for scheme in ['https', 'http']:
            url = scheme + '://sub.example.invalid/sub?token=fixture-secret'
            self.client.call.return_value = {'subscribe_url': url}
            self.assertEqual(url, self.client.get_subscription(7))
            self.client.call.assert_called_with('account/subscription', {'telegram_id': 7})
        self.assertEqual({}, self.client.email_requests)
        self.assertEqual({}, self.client.unbind_confirmations)

    def test_invalid_subscription_response_is_not_forwarded(self):
        for url in [None, '', 'javascript:alert(1)', 'https://user:pass@host.invalid/sub',
                    'https://host.invalid/\nsecret', '/relative', 'https://[broken', 'x' * 2049]:
            with self.subTest(url=url):
                self.client.call.return_value = {'subscribe_url': url}
                with self.assertRaises(BindingError):
                    self.client.get_subscription(7)

    def test_plan_is_a_separate_read_only_request(self):
        self.client.call.return_value = plan_data()
        self.assertEqual(plan_data(), self.client.get_plan(8))
        self.client.call.assert_called_once_with('account/plan', {'telegram_id': 8})

    def test_invalid_plan_values_are_rejected(self):
        for values in [{'total_bytes': True}, {'used_bytes': -1}, {'remaining_bytes': 100},
                       {'status': 'unknown'}, {'plan_name': 'x' * 161}, {'expires_at': 'tomorrow'},
                       {'expires_at': 253402300799}, {'expires_at': -1}]:
            with self.subTest(values=values):
                self.client.call.return_value = plan_data(**values)
                with self.assertRaises(BindingError):
                    self.client.get_plan(7)

    def test_api_error_does_not_call_a_fallback_or_write_endpoint(self):
        self.client.call.side_effect = BindingError('请先绑定可用的网站账号，再查询订阅或套餐')
        for method, action in [(self.client.get_plan, 'account/plan'),
                               (self.client.get_subscription, 'account/subscription')]:
            self.client.call.reset_mock()
            with self.assertRaises(BindingError):
                method(7)
            self.client.call.assert_called_once_with(action, {'telegram_id': 7})


class AccountReadBotTests(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.db = bot.AppDatabase(Path(temp.name) / 'test.sqlite3')
        self.api = Mock(spec=bot.TelegramBot)
        self.checker = Mock(spec=bot.PanelUserChecker)
        self.checker.email_client = PanelBindingClient()
        self.checker.email_client.call = Mock()

    def dispatch(self, text, private=True, user=7):
        bot.handle_message(self.api, self.db, self.checker, set(), {
            'chat': {'id': user if private else -123, 'type': 'private' if private else 'supergroup'},
            'message_id': 1, 'from': {'id': user}, 'text': text})

    def test_subscription_is_private_and_is_not_logged(self):
        url = 'https://sub.example.invalid/sub?token=fixture-secret'
        self.checker.email_client.call.return_value = {'subscribe_url': url}
        self.dispatch('获取订阅', user=8)
        self.checker.email_client.call.assert_called_once_with('account/subscription', {'telegram_id': 8})
        self.api.send_message.assert_called_once()
        self.assertEqual(8, self.api.send_message.call_args.args[0])
        self.assertIn(url, self.api.send_message.call_args.args[1])
        self.assertIn('请勿转发', self.api.send_message.call_args.args[1])
        self.assertEqual([], self.db.list_events())

    def test_plan_shows_actual_quota_and_no_expiry(self):
        self.checker.email_client.call.return_value = plan_data()
        self.dispatch('查看套餐')
        text = self.api.send_message.call_args.args[1]
        for expected in ['Fixture plan', '不限时', '总流量：5.00 GB', '已用流量：2.00 GB', '剩余流量：3.00 GB']:
            self.assertIn(expected, text)
        self.assertEqual([], self.db.list_events())

    def test_plan_formats_expiry_in_beijing_time(self):
        self.checker.email_client.call.return_value = plan_data(expires_at=1700000000)
        self.dispatch('查看套餐')
        self.assertIn('2023-11-15 06:13（北京时间）', self.api.send_message.call_args.args[1])

    def test_zero_expiry_and_no_plan_do_not_claim_unlimited(self):
        for data, expected in [(plan_data(expires_at=0, status='expired'), '到期时间：已到期'),
                               (plan_data(status='no_plan', plan_name=''), '到期时间：未设置')]:
            self.checker.email_client.call.return_value = data
            self.dispatch('查看套餐')
            self.assertIn(expected, self.api.send_message.call_args.args[1])
            self.assertNotIn('不限时', self.api.send_message.call_args.args[1])

    def test_overused_plan_keeps_used_total_and_zero_remaining(self):
        self.checker.email_client.call.return_value = plan_data(status='exhausted', used_bytes=6 * 1024**3, remaining_bytes=0)
        self.dispatch('查看套餐')
        text = self.api.send_message.call_args.args[1]
        self.assertIn('流量已用完', text)
        self.assertIn('已用流量：6.00 GB', text)
        self.assertIn('剩余流量：0.00 GB', text)

    def test_group_buttons_do_not_query_or_disclose_account(self):
        for text in ['获取订阅', '查看套餐']:
            self.dispatch(text, private=False)
        self.checker.email_client.call.assert_not_called()
        self.api.send_message.assert_not_called()

    def test_manual_allow_does_not_skip_real_binding_and_block_denies_queries(self):
        self.db.save_registered_user({'telegram_id': 7, 'status': 'allowed'})
        self.checker.email_client.call.side_effect = BindingError('请先绑定可用的网站账号，再查询订阅或套餐')
        self.dispatch('获取订阅')
        self.assertIn('请先绑定', self.api.send_message.call_args.args[1])
        self.checker.email_client.call.assert_called_once()
        self.checker.email_client.call.reset_mock()
        self.db.save_registered_user({'telegram_id': 7, 'status': 'blocked'})
        for text in ['获取订阅', '查看套餐']:
            self.dispatch(text)
        self.checker.email_client.call.assert_not_called()
        self.checker.verify.assert_not_called()

    def test_api_failure_and_rate_limit_preserve_keyboard_and_make_no_other_requests(self):
        for reason in ['面板连接暂时不可用，请稍后重试', '查询过于频繁，请等待3秒后重试']:
            self.checker.email_client.call.side_effect = BindingError(reason)
            self.checker.email_client.call.reset_mock()
            self.dispatch('获取订阅')
            self.checker.email_client.call.assert_called_once_with('account/subscription', {'telegram_id': 7})
            self.assertEqual(reason, self.api.send_message.call_args.args[1])
            self.assertEqual(bot.binding_account_keyboard(), self.api.send_message.call_args.kwargs['reply_markup'])

    def test_reads_preserve_binding_context_pending_joins_and_manual_records(self):
        self.db.save_group({'chat_id': -123, 'title': 'Test', 'enabled': 1, 'verify_join': 1})
        self.db.remember_pending_join(-123, 7, 9007, bot.now_ts())
        self.db.save_registered_user({'telegram_id': 7, 'status': 'allowed'})
        before_user = dict(self.db.get_registered_user(7))
        before_pending = [dict(row) for row in self.db.pending_joins_for(7)]
        client = self.checker.email_client
        client.unbind_confirmations[7] = {'token': 'fixture'}
        client.call.return_value = plan_data()
        self.dispatch('查看套餐')
        self.assertEqual(before_user, dict(self.db.get_registered_user(7)))
        self.assertEqual(before_pending, [dict(row) for row in self.db.pending_joins_for(7)])
        self.assertEqual({'token': 'fixture'}, client.unbind_confirmations[7])
        self.assertEqual(['account/plan'], [call.args[0] for call in client.call.call_args_list])
        self.api.kick_chat_member.assert_not_called()


if __name__ == '__main__':
    unittest.main()
