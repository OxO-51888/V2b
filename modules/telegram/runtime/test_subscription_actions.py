import copy
import time
import unittest
from unittest.mock import Mock

from panel_binding import BindingError, PanelBindingClient
import bot
from panel_sites import SitePrivateBot
from test_panel_sites import MultiSiteTests


class SubscriptionResetClientTests(unittest.TestCase):
    def setUp(self):
        self.client = PanelBindingClient()
        self.client.call = Mock(return_value={'status': 'confirmation_required',
            'confirmation_token': 'a' * 32, 'expires_in': 300, 'emailMasked': 'te***@example.invalid'})

    def test_publication_urls_display_all_six_chinese_domains(self):
        for prefix, display in [('xn--54qr1i', '光喵'), ('xn--i2r10aa', '囡囡喵'),
                                ('ovo', 'ovo'), ('clash', 'clash'),
                                ('xn--wtq35pfyd55o', '便宜机场'), ('xn--4gq62f52gdss', '一元机场')]:
            url = f'https://{prefix}.xn--oor32f63hs9js55d.com/'
            self.assertEqual(f'https://{display}.机场发布页.com', bot.publication_display_url(url))

    def test_publication_display_only_removes_root_slash(self):
        for url, expected in [
            ('https://example.invalid/', 'https://example.invalid'),
            ('https://example.invalid', 'https://example.invalid'),
            ('https://example.invalid:8443/', 'https://example.invalid:8443'),
            ('https://example.invalid/path/', 'https://example.invalid/path/'),
            ('https://example.invalid/?next=/#part/', 'https://example.invalid?next=/#part/'),
        ]:
            with self.subTest(url=url):
                self.assertEqual(expected, bot.publication_display_url(url))

    def test_link_entity_offsets_account_for_site_prefix_without_mutating_input(self):
        api = Mock(spec=bot.TelegramBot)
        entities = [{'type': 'text_link', 'offset': 2, 'length': 5, 'url': 'https://example.invalid/'}]
        wrapper = SitePrivateBot(api, '便宜机场')
        wrapper.send_message(7, '测试链接', entities=entities)
        sent = api.send_message.call_args.kwargs['entities'][0]
        self.assertEqual(8, sent['offset'])
        self.assertEqual(2, entities[0]['offset'])
        self.assertEqual('https://example.invalid/', sent['url'])

    def test_account_keyboard_stays_two_rows_without_duplicates_or_mutating_input(self):
        for bound_count in [0, 1, 2, 6]:
            for factory in [bot.binding_account_keyboard, bot.binding_resend_keyboard]:
                with self.subTest(bound_count=bound_count, factory=factory.__name__):
                    api, sites = Mock(), Mock()
                    sites.can_switch.return_value = bound_count > 1
                    wrapper = SitePrivateBot(api, 'fixture', sites, 7)
                    original = factory()
                    pristine = copy.deepcopy(original)
                    markup = original
                    for _ in range(2):
                        wrapper.send_message(7, 'fixture', reply_markup=markup)
                        markup = api.send_message.call_args.kwargs['reply_markup']
                        rows = markup['keyboard']
                        self.assertEqual([4, 4 if bound_count > 1 else 3], [len(row) for row in rows])
                        labels = [button['text'] for row in rows for button in row]
                        self.assertEqual(len(labels), len(set(labels)))
                        self.assertEqual('发布页', labels[0])
                        self.assertEqual(bound_count > 1, '切换机场' in labels)
                        if bound_count > 1:
                            self.assertEqual('切换机场', rows[1][-1]['text'])
                    self.assertEqual(pristine, original)

    def test_confirmation_requires_live_private_context_and_cancellation_removes_it(self):
        with self.assertRaises(BindingError):
            self.client.confirm_subscription_reset(7)
        self.client.call.assert_not_called()
        self.client.prepare_subscription_reset(7)
        self.client.cancel_subscription_reset(7)
        self.client.call.reset_mock()
        with self.assertRaises(BindingError):
            self.client.confirm_subscription_reset(7)
        self.client.call.assert_not_called()

    def test_failed_response_retry_uses_same_confirmation_and_success_clears_it(self):
        self.client.prepare_subscription_reset(7)
        self.client.call.side_effect = BindingError('temporary failure')
        with self.assertRaises(BindingError):
            self.client.confirm_subscription_reset(7)
        self.assertEqual('a' * 32, self.client.reset_confirmations[7]['token'])
        self.client.call.side_effect = None
        self.client.call.return_value = {'status': 'reset', 'subscribe_url': 'https://sub.example.invalid/new'}
        self.assertEqual('https://sub.example.invalid/new', self.client.confirm_subscription_reset(7))
        self.client.call.assert_called_with('account/subscription/reset/confirm',
            {'telegram_id': 7, 'confirmation_token': 'a' * 32})
        self.assertEqual({}, self.client.reset_confirmations)

    def test_expired_or_other_user_confirmation_never_calls_panel(self):
        self.client.prepare_subscription_reset(7)
        self.client.call.reset_mock()
        with self.assertRaises(BindingError):
            self.client.confirm_subscription_reset(8)
        self.client.reset_confirmations[7]['expires_at'] = time.monotonic() - 1
        with self.assertRaises(BindingError):
            self.client.confirm_subscription_reset(7)
        self.client.call.assert_not_called()

    def test_malformed_prepare_and_publication_are_rejected(self):
        for data in [{}, {'status': 'confirmation_required', 'confirmation_token': 'bad'},
                     {'status': 'confirmation_required', 'confirmation_token': 'a' * 32,
                      'expires_in': True, 'emailMasked': 'test'}]:
            self.client.call.return_value = data
            with self.assertRaises(BindingError):
                self.client.prepare_subscription_reset(7)
        for url in ['', 'javascript:alert(1)', 'https://user:pass@example.invalid', 'https://[bad', 'https://a.invalid/\n']:
            self.client.call.return_value = {'publication_url': url}
            with self.assertRaises(BindingError):
                self.client.get_publication(7)


class NewSiteActionsTests(MultiSiteTests):
    def response(self, key, action, values):
        if action == 'account/subscription/reset/prepare':
            if key not in self.bound:
                raise BindingError('请先绑定')
            return {'status': 'confirmation_required', 'confirmation_token': 'a' * 32,
                    'expires_in': 300, 'emailMasked': 'te***@example.invalid'}
        if action == 'account/subscription/reset/confirm':
            return {'status': 'reset', 'subscribe_url': f'https://{key}.example.invalid/sub?token=new-secret'}
        if action == 'account/publication':
            return {'publication_url': f'https://{key}.example.invalid/publication'}
        return super().response(key, action, values)

    def test_all_six_publication_buttons_return_only_current_site_without_binding(self):
        for key in self.sites.sites:
            self.select(key)
            self.reset_calls()
            self.dispatch('发布页')
            self.assert_only_site_called(key)
            self.assertIn(f'https://{key}.example.invalid/publication', self.api.send_message.call_args.args[1])
            call = self.api.send_message.call_args
            entity = call.kwargs['entities'][0]
            text_bytes = call.args[1].encode('utf-16-le')
            displayed = text_bytes[entity['offset']*2:(entity['offset']+entity['length'])*2].decode('utf-16-le')
            self.assertEqual(f'https://{key}.example.invalid/publication', displayed)
            self.assertEqual(displayed, entity['url'])
            self.assertEqual([], self.db.list_events())

    def test_reset_is_confirmed_only_in_selected_site_and_never_logged(self):
        self.bound = set(self.sites.sites)
        self.select('pianyi')
        self.reset_calls()
        self.dispatch('重置订阅')
        self.assert_only_site_called('pianyi')
        self.assertNotIn('new-secret', self.api.send_message.call_args.args[1])
        self.assertIn('旧订阅链接和节点连接凭据将失效', self.api.send_message.call_args.args[1])
        self.assertTrue(self.api.send_message.call_args.args[1].startswith('便宜机场'))
        self.click_confirmation('确认重置订阅')
        self.assert_only_site_called('pianyi')
        self.assertIn('https://pianyi.example.invalid/sub?token=new-secret', self.api.send_message.call_args.args[1])
        self.assertEqual([], self.db.list_events())
        rows = self.api.send_message.call_args.kwargs['reply_markup']['keyboard']
        self.assertEqual([4, 4], [len(row) for row in rows])
        self.assertEqual(['发布页', '获取订阅', '查看套餐', '重置订阅'], [b['text'] for b in rows[0]])
        self.assertEqual(['绑定邮箱', '重新发送验证码', '解绑邮箱', '切换机场'], [b['text'] for b in rows[1]])

    def test_all_six_root_publication_replies_omit_slash_and_preserve_link_target(self):
        prefixes = {'gm': 'xn--54qr1i', 'nnm': 'xn--i2r10aa', 'ovo': 'ovo',
                    'clash': 'clash', 'pianyi': 'xn--wtq35pfyd55o', 'yiyuan': 'xn--4gq62f52gdss'}
        original = self.response

        def response(key, action, values):
            if action == 'account/publication':
                return {'publication_url': f'https://{prefixes[key]}.xn--oor32f63hs9js55d.com/'}
            return original(key, action, values)

        self.response = response
        for key in self.sites.sites:
            with self.subTest(site=key):
                self.select(key)
                self.reset_calls()
                self.dispatch('发布页')
                self.assert_only_site_called(key)
                sent = self.api.send_message.call_args
                entity = sent.kwargs['entities'][0]
                text_bytes = sent.args[1].encode('utf-16-le')
                visible = text_bytes[entity['offset']*2:(entity['offset']+entity['length'])*2].decode('utf-16-le')
                original_url = f'https://{prefixes[key]}.xn--oor32f63hs9js55d.com/'
                self.assertEqual(bot.publication_display_url(original_url), visible)
                self.assertTrue(visible.endswith('.com'))
                self.assertNotIn('xn--', visible)
                self.assertEqual(original_url, entity['url'])
                self.assertEqual([], self.db.list_events())

    def test_switch_or_cancel_invalidates_reset_and_prevents_cross_site_operation(self):
        self.bound = {'pianyi', 'ovo'}
        for action in ['取消重置', '切换机场', '/start ' + self.sites.entry_payload('ovo')]:
            self.select('pianyi')
            self.dispatch('重置订阅')
            self.dispatch(action)
            self.reset_calls()
            self.dispatch('确认重置订阅')
            for client in self.sites.clients.values():
                self.assertFalse(any(c.args[0].endswith('/reset/confirm') for c in client.call.call_args_list))

    def test_new_operations_never_run_in_group_or_for_blocked_user(self):
        for text in ['发布页', '重置订阅', '确认重置订阅', '取消重置']:
            self.dispatch(text, chat=-999)
        for client in self.sites.clients.values():
            client.call.assert_not_called()
        self.db.save_registered_user({'telegram_id': 7, 'status': 'blocked'})
        for text in ['发布页', '重置订阅', '确认重置订阅']:
            self.dispatch(text)
        for client in self.sites.clients.values():
            client.call.assert_not_called()


if __name__ == '__main__':
    unittest.main()
