import copy
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import Mock, patch

import bot
from panel_binding import BindingError
from panel_sites import PanelSites


def site_items():
    return [{'id': key, 'name': name, 'url': f'https://{key}.example.invalid/api/v1/bot/telegram',
             'secret': str(i) * 64, 'chat_ids': [-100 - i] if i < 5 else []}
            for i, (key, name) in enumerate([
                ('clash', 'Clash'), ('gm', '光喵'), ('nnm', '囡囡喵'),
                ('ovo', 'OvO'), ('yiyuan', '一元机场'), ('pianyi', '便宜机场')])]


class MultiSiteTests(unittest.TestCase):
    def setUp(self):
        env = patch.dict(os.environ, {'PANEL_EMAIL_BINDING_SITES_FILE': '',
                                     'TELEGRAM_BOT_USERNAME': 'fixture_bot',
                                     'TELEGRAM_ALLOWED_CHAT_IDS': '',
                                     'TELEGRAM_JOIN_ONBOARDING_CHAT_IDS': '-100,-101,-102,-103,-104'})
        env.start(); self.addCleanup(env.stop)
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.root = Path(temp.name)
        self.db = bot.AppDatabase(self.root / 'bot.sqlite3')
        self.checker = bot.PanelUserChecker(self.db)
        self.sites = self.checker.sites = PanelSites(site_items())
        self.api = Mock(spec=bot.TelegramBot)
        self.api.send_message.return_value = {'ok': True, 'result': {'message_id': 99}}
        self.api.get_chat_member.return_value = {'status': 'left'}
        self.bound = set()
        for key, client in self.sites.clients.items():
            client.call = Mock(side_effect=lambda action, values, k=key: self.response(k, action, values))
        for chat, key in self.sites.groups.items():
            self.db.save_group({'chat_id': chat, 'title': key, 'enabled': 1,
                                'verify_join': 1, 'verify_message': 1})

    def response(self, key, action, values):
        if action == 'verify':
            return {'allowed': key in self.bound, 'emailMasked': 'te***@example.invalid'}
        if action == 'email/request':
            return {'status': 'queued'}
        if action == 'email/confirm':
            self.bound.add(key)
            return {'status': 'bound', 'allowed': True}
        if action == 'email/unbind/prepare':
            return {'status': 'confirmation_required', 'confirmation_token': 'a' * 32,
                    'expires_in': 300, 'emailMasked': 'te***@example.invalid'}
        if action == 'email/unbind/confirm':
            self.bound.discard(key)
            return {'status': 'unbound', 'allowed': False}
        if action == 'account/subscription':
            return {'subscribe_url': f'https://{key}.example.invalid/sub?token=private-token'}
        if action == 'account/plan':
            return {'plan_name': key, 'status': 'active', 'expires_at': None,
                    'total_bytes': 1000, 'used_bytes': 200, 'remaining_bytes': 800}
        self.fail('Unexpected action: ' + action)

    def dispatch(self, text, user=7, chat=None):
        bot.handle_message(self.api, self.db, self.checker, set(), {
            'chat': {'id': user if chat is None else chat, 'type': 'private' if chat is None else 'supergroup'},
            'message_id': 1, 'from': {'id': user}, 'text': text})

    def select(self, key, user=7):
        self.dispatch('/start ' + self.sites.entry_payload(key), user)

    def reset_calls(self):
        self.api.reset_mock()
        for client in self.sites.clients.values():
            client.call.reset_mock()

    def test_unbound_group_prompt_links_only_its_site_and_expires(self):
        self.db.save_settings({'unregistered_message_action': 'delete_warn'})
        for chat, key in self.sites.groups.items():
            with self.subTest(site=key):
                self.reset_calls()
                self.dispatch('hello', chat=chat)
                self.api.delete_message.assert_called_once_with(chat, 1)
                self.api.delete_message_later.assert_called_once_with(chat, 99)
                message = self.api.send_message.call_args
                self.assertIn(self.sites.sites[key]['label'], message.args[1])
                buttons = message.kwargs['reply_markup']['inline_keyboard']
                self.assertEqual([[{'text': '绑定邮箱', 'url': self.sites.entry_url(key, 'fixture_bot')}]], buttons)
                for other in self.sites.sites:
                    if other != key:
                        self.assertNotIn(self.sites.sites[other]['label'], message.args[1])
                self.assert_only_site_called(key)

    def assert_only_site_called(self, key, *, allow_discovery=False):
        self.assertTrue(self.sites.clients[key].call.called)
        for other, client in self.sites.clients.items():
            if other != key:
                if allow_discovery:
                    self.assertTrue(all(c.args[0] == 'verify' for c in client.call.call_args_list))
                    self.assertTrue(all(c.args[1] == {'telegram_id': 7, 'require_active_plan': False}
                                        for c in client.call.call_args_list))
                else:
                    client.call.assert_not_called()

    def switch_visible(self):
        markup = self.api.send_message.call_args.kwargs['reply_markup']
        return any(b['text'] == '切换机场' for row in markup.get('keyboard', []) for b in row)

    def test_unselected_private_operations_never_show_site_directory_or_write(self):
        for text in ['你好', '/start', '绑定邮箱', 'test@example.invalid', '123456', '获取订阅', '确认解绑']:
            self.dispatch(text)
            markup = self.api.send_message.call_args.kwargs['reply_markup']
            self.assertEqual({'remove_keyboard': True}, markup)
            for site in self.sites.sites.values():
                self.assertNotIn(site['label'], self.api.send_message.call_args.args[1])
        for client in self.sites.clients.values():
            self.assertEqual(['verify'], [c.args[0] for c in client.call.call_args_list])

    def test_unbound_selection_shows_only_original_actions(self):
        for key in self.sites.sites:
            self.select(key)
            call = self.api.send_message.call_args
            self.assertTrue(call.args[1].startswith(self.sites.sites[key]['label'] + '\n'))
            rows = call.kwargs['reply_markup']['keyboard']
            self.assertEqual([3, 3], [len(row) for row in rows])
            self.assertFalse(self.switch_visible())
        self.assertTrue(all(call.args[0] == 'verify' for client in self.sites.clients.values()
                            for call in client.call.call_args_list))

    def test_single_bound_site_same_entry_and_normal_reopen_hide_switch(self):
        self.bound.add('clash')
        for text in ['/start', '/help', '/start ' + self.sites.entry_payload('clash'), '绑定邮箱']:
            self.dispatch(text)
            self.assertFalse(self.switch_visible())
            self.assertEqual([3, 3], [len(r) for r in self.api.send_message.call_args.kwargs['reply_markup']['keyboard']])

    def test_single_bound_site_other_entry_hides_switch_before_binding_second(self):
        self.bound.add('clash')
        self.select('ovo')
        self.assertFalse(self.switch_visible())
        self.assertEqual({'clash'}, self.bound)
        self.dispatch('切换机场')
        self.assertEqual([[{'text': 'Clash机场'}]], self.api.send_message.call_args.kwargs['reply_markup']['keyboard'])
        self.dispatch('Clash机场')
        self.assertFalse(self.switch_visible())
        self.assertEqual('clash', self.sites.current(7))

    def test_all_six_distinct_entries_hide_switch_for_only_one_existing_binding(self):
        for key in self.sites.sites:
            with self.subTest(site=key):
                self.bound.clear()
                self.bound.add('ovo' if key == 'clash' else 'clash')
                self.sites.discoveries.clear()
                self.select(key)
                self.assertFalse(self.switch_visible())

    def test_multiple_existing_bindings_automatically_show_switch_on_normal_open(self):
        self.bound.update(['clash', 'ovo'])
        self.dispatch('/start')
        self.dispatch('Clash机场')
        self.assertTrue(self.switch_visible())
        self.dispatch('/help')
        self.assertTrue(self.switch_visible())

    def test_successful_second_binding_refreshes_cached_count_immediately(self):
        self.select('clash'); self.dispatch('same@example.invalid'); self.dispatch('123456')
        self.assertFalse(self.switch_visible())
        self.select('ovo'); self.assertFalse(self.switch_visible())
        self.dispatch('same@example.invalid'); self.assertFalse(self.switch_visible())
        self.dispatch('123456')
        self.assertTrue(self.switch_visible())
        self.assertEqual({'clash', 'ovo'}, self.bound)
        self.dispatch('切换机场')
        self.assertEqual(['Clash机场', 'OvO机场'],
                         [b['text'] for r in self.api.send_message.call_args.kwargs['reply_markup']['keyboard'] for b in r])

    def test_same_site_automatic_restore_does_not_count_as_another_entry(self):
        self.bound.add('clash')
        self.sites.select(7, 'ovo')
        self.dispatch('/help')
        self.assertFalse(self.switch_visible())

    def test_invalid_entry_and_site_name_do_not_enable_switch(self):
        self.bound.add('clash'); self.select('clash')
        for text in ['OvO机场', '/start b_' + '0' * 32, '/start site_ovo']:
            self.dispatch(text)
            self.assertFalse(self.switch_visible())
            self.assertEqual('clash', self.sites.current(7))

    def test_failed_discovery_does_not_invent_binding_or_show_switch(self):
        for client in self.sites.clients.values():
            client.call.side_effect = BindingError('offline')
        self.select('ovo')
        self.assertFalse(self.switch_visible())
        self.assertNotIn('Clash', str(self.api.send_message.call_args))

    def test_keyboard_checks_use_cached_read_only_discovery(self):
        self.bound.add('clash'); self.select('ovo'); self.reset_calls()
        for text in ['/help', '/start', 'hello']:
            self.dispatch(text)
            self.assertFalse(self.switch_visible())
        self.assertTrue(all(not c.call.called for c in self.sites.clients.values()))

    def test_switch_visibility_isolated_by_user(self):
        self.bound.update(['clash', 'ovo'])
        self.select('ovo', 7); self.assertTrue(self.switch_visible())
        self.bound.discard('ovo')
        self.select('clash', 8); self.assertFalse(self.switch_visible())
        self.dispatch('/help', 7); self.assertTrue(self.switch_visible())

    def test_unbinding_all_sites_hides_switch_immediately(self):
        self.bound.add('clash'); self.select('ovo'); self.assertFalse(self.switch_visible())
        self.dispatch('切换机场'); self.dispatch('Clash机场')
        self.dispatch('解绑邮箱'); self.dispatch('确认解绑')
        self.assertFalse(self.switch_visible())
        self.assertEqual(set(), self.bound)

    def test_expired_entry_context_cannot_enable_single_binding_switch(self):
        self.bound.add('clash'); self.select('ovo'); self.assertFalse(self.switch_visible())
        self.sites.selections[7] = ('ovo', 0)
        self.dispatch('/start')
        self.assertFalse(self.switch_visible())

    def test_unbind_from_two_sites_to_one_hides_switch_even_at_other_entry(self):
        self.bound.update(['clash', 'ovo']); self.select('ovo')
        self.assertTrue(self.switch_visible())
        self.dispatch('解绑邮箱'); self.dispatch('确认解绑')
        self.assertEqual({'clash'}, self.bound)
        self.assertFalse(self.switch_visible())

    def test_unbind_from_three_sites_to_two_keeps_switch(self):
        self.bound.update(['clash', 'ovo', 'gm']); self.select('ovo')
        self.dispatch('解绑邮箱'); self.dispatch('确认解绑')
        self.assertEqual({'clash', 'gm'}, self.bound)
        self.assertTrue(self.switch_visible())

    def test_failed_second_confirmation_does_not_show_switch(self):
        self.bound.add('clash'); self.select('ovo'); self.dispatch('same@example.invalid')
        self.sites.clients['ovo'].call.side_effect = BindingError('请输入正确的6位验证码')
        self.dispatch('123456')
        self.assertFalse(self.switch_visible())
        self.assertEqual({'clash'}, self.bound)

    def test_entry_help_gives_the_next_action_for_current_site(self):
        self.bound.add('clash')
        for key in self.sites.sites:
            self.select(key)
            text = self.api.send_message.call_args.args[1]
            if key == 'clash':
                self.assertIn('请点击“获取订阅”', text)
                self.assertIn('重试入群验证', text)
            else:
                self.assertIn('请直接在本私聊发送', text)
                self.assertIn('6位验证码', text)
                self.assertIn('垃圾邮件', text)
            for state_only in ['已选择', '已识别', '当前 Telegram 已绑定']:
                self.assertNotIn(state_only, text)
            self.assertFalse(self.switch_visible())

    def test_email_and_confirmation_replies_explain_the_next_step(self):
        self.select('ovo'); self.dispatch('same@example.invalid')
        self.assertIn('请打开注册邮箱', self.api.send_message.call_args.args[1])
        self.dispatch('123456')
        self.assertIn('请点击“获取订阅”', self.api.send_message.call_args.args[1])
        self.assertIn('查看套餐', self.api.send_message.call_args.args[1])
        self.assertFalse(self.switch_visible())

    def test_all_six_sites_route_bind_request_and_code_to_only_selected_site(self):
        for key in self.sites.sites:
            self.select(key); self.reset_calls()
            self.dispatch('same@example.invalid'); self.dispatch('123456')
            client = self.sites.clients[key]
            self.assertEqual(['email/request', 'email/confirm'],
                             [call.args[0] for call in client.call.call_args_list if call.args[0] != 'verify'])
            self.assert_only_site_called(key, allow_discovery=True)
            self.assertNotIn('123456', str([dict(r) for r in self.db.list_events()]))

    def test_all_six_sites_subscription_and_plan_are_read_only_and_private(self):
        for key in self.sites.sites:
            self.select(key); self.reset_calls()
            before = len(self.db.list_events())
            self.dispatch('获取订阅'); self.dispatch('查看套餐')
            calls = self.sites.clients[key].call.call_args_list
            self.assertEqual(['account/subscription', 'account/plan'], [c.args[0] for c in calls])
            self.assertTrue(all(c.args[1] == {'telegram_id': 7} for c in calls))
            self.assert_only_site_called(key)
            self.assertEqual(before, len(self.db.list_events()))

    def test_switch_rejects_old_code_and_resend_without_contacting_other_site(self):
        self.select('clash'); self.dispatch('same@example.invalid')
        self.select('ovo'); self.reset_calls()
        self.dispatch('123456')
        self.assertIn('当前机场没有待验证', self.api.send_message.call_args.args[1])
        self.assertTrue(all(not c.call.called for c in self.sites.clients.values()))
        self.dispatch('重新发送验证码')
        self.assertEqual(['verify'], [c.args[0] for c in self.sites.clients['ovo'].call.call_args_list])
        self.assertIn('重新发送网站注册邮箱', self.api.send_message.call_args.args[1])

    def test_same_site_selection_keeps_current_code_and_cooldown(self):
        self.select('clash'); self.dispatch('same@example.invalid'); self.select('clash')
        self.dispatch('重新发送验证码')
        self.assertIn('等待', self.api.send_message.call_args.args[1])
        self.dispatch('123456')
        self.assertIn('请点击“获取订阅”', self.api.send_message.call_args.args[1])

    def test_switch_invalidates_pending_unbind_and_never_unbinds_new_site(self):
        self.select('clash'); self.dispatch('解绑邮箱')
        self.select('ovo'); self.reset_calls(); self.dispatch('确认解绑')
        self.assertTrue(all(not c.call.called for c in self.sites.clients.values()))
        self.assertIn('解绑确认已过期或未开始', self.api.send_message.call_args.args[1])

    def test_unbind_stays_on_selected_site_for_all_six_sites(self):
        for key in self.sites.sites:
            self.select(key); self.reset_calls(); self.dispatch('解绑邮箱'); self.dispatch('确认解绑')
            self.assert_only_site_called(key, allow_discovery=True)
            self.assertEqual(['email/unbind/prepare', 'email/unbind/confirm'],
                             [c.args[0] for c in self.sites.clients[key].call.call_args_list if c.args[0] != 'verify'])
            self.api.kick_chat_member.assert_not_called()

    def test_existing_binding_is_checked_on_selected_site_only(self):
        self.bound.add('ovo'); self.select('ovo'); self.reset_calls(); self.dispatch('new@example.invalid')
        self.assertEqual(['verify'], [c.args[0] for c in self.sites.clients['ovo'].call.call_args_list])
        self.assert_only_site_called('ovo')
        self.assertIn('请点击“获取订阅”', self.api.send_message.call_args.args[1])

    def test_user_selection_and_pending_operations_are_independent(self):
        self.select('clash', 7); self.select('ovo', 8)
        self.dispatch('same@example.invalid', 7); self.dispatch('same@example.invalid', 8)
        self.select('nnm', 7)
        self.assertIsNotNone(self.sites.clients['ovo'].recent_email_request(8))
        self.dispatch('123456', 8)
        self.assertIn('OvO机场', self.api.send_message.call_args.args[1])

    def test_same_user_can_bind_two_sites_and_unbind_only_one(self):
        for key in ['clash', 'ovo']:
            self.select(key); self.dispatch('same@example.invalid'); self.dispatch('123456')
        self.assertEqual({'clash', 'ovo'}, self.bound)
        self.select('clash'); self.dispatch('绑定邮箱')
        self.assertIn('请点击“获取订阅”', self.api.send_message.call_args.args[1])
        self.select('ovo'); self.dispatch('绑定邮箱')
        self.assertIn('请点击“获取订阅”', self.api.send_message.call_args.args[1])
        self.reset_calls(); self.dispatch('解绑邮箱'); self.dispatch('确认解绑')
        self.assertEqual({'clash'}, self.bound)
        self.assert_only_site_called('ovo', allow_discovery=True)

    def test_expired_selection_and_restart_require_selection_again(self):
        self.select('clash'); self.dispatch('same@example.invalid')
        self.sites.selections[7] = ('clash', 0)
        self.dispatch('123456')
        self.assertIn('验证入口', self.api.send_message.call_args.args[1])
        self.assertIsNone(self.sites.clients['clash'].recent_email_request(7))
        self.assertIsNone(PanelSites(site_items()).current(7))

    def test_private_selection_does_not_authorize_other_groups(self):
        self.bound.add('clash'); self.select('clash'); self.reset_calls()
        self.dispatch('hello', chat=-103)
        self.assert_only_site_called('ovo')
        self.assertIn('OvO机场的网站账号', self.api.send_message.call_args.args[1])

    def test_pending_groups_are_each_verified_against_their_own_site(self):
        self.bound.update(['clash', 'nnm'])
        for chat in [-100, -101, -102]:
            self.db.remember_pending_join(chat, 7, 9007, bot.now_ts())
        approved, pending = bot.approve_pending_joins(self.api, self.db, self.checker, 7)
        self.assertEqual(['clash', 'nnm'], sorted(approved))
        self.assertTrue(pending)
        self.assertEqual({-100, -102}, {c.args[0] for c in self.api.approve_chat_join_request.call_args_list})
        self.assertEqual([-101], [r['chat_id'] for r in self.db.pending_joins_for(7)])

    def test_fault_on_one_site_does_not_fall_back_or_block_other_site(self):
        self.bound.add('nnm')
        self.sites.clients['clash'].call.side_effect = BindingError('offline')
        for chat in [-100, -102]:
            self.db.remember_pending_join(chat, 7, 9007, bot.now_ts())
        approved, pending = bot.approve_pending_joins(self.api, self.db, self.checker, 7)
        self.assertEqual(['nnm'], approved); self.assertTrue(pending)
        self.sites.clients['ovo'].call.assert_not_called()

    def test_unmapped_group_and_unscoped_checker_fail_closed(self):
        self.bound.update(self.sites.sites)
        self.assertTrue(bot.verify_group_member(self.checker, 7, -999).unavailable)
        self.assertTrue(self.checker.verify(7).unavailable)
        self.assertTrue(all(not c.call.called for c in self.sites.clients.values()))

    def test_second_group_join_does_not_switch_private_context(self):
        self.select('clash'); self.dispatch('same@example.invalid'); self.reset_calls()
        bot.handle_join_request(self.api, self.db, self.checker, set(), {
            'chat': {'id': -103, 'title': 'OvO'}, 'from': {'id': 7},
            'user_chat_id': 9007, 'date': bot.now_ts()})
        self.assertEqual('clash', self.sites.current(7))
        self.assertIsNotNone(self.sites.clients['clash'].recent_email_request(7))
        self.assert_only_site_called('ovo')
        self.api.decline_chat_join_request.assert_not_called()

    def test_first_join_sets_correct_site_and_preserves_private_prompt_first(self):
        client = self.sites.clients['nnm']
        def response(action, values):
            self.api.send_message.assert_called_once()
            return {'allowed': False}
        client.call.side_effect = response
        bot.handle_join_request(self.api, self.db, self.checker, set(), {
            'chat': {'id': -102, 'title': '囡囡喵'}, 'from': {'id': 7},
            'user_chat_id': 9007, 'date': bot.now_ts()})
        self.assertEqual('nnm', self.sites.current(7))
        self.assertIn('囡囡喵机场', self.api.send_message.call_args.args[1])

    def test_disabled_other_group_stays_unchecked(self):
        self.db.save_group({'chat_id': -103, 'enabled': 1, 'verify_join': 0, 'verify_message': 0})
        self.dispatch('获取订阅', chat=-103)
        bot.handle_join_request(self.api, self.db, self.checker, set(), {
            'chat': {'id': -103, 'title': 'OvO'}, 'from': {'id': 7},
            'user_chat_id': 9007, 'date': bot.now_ts()})
        self.assertTrue(all(not c.call.called for c in self.sites.clients.values()))
        self.api.send_message.assert_not_called()

    def test_manual_block_prevents_reads_and_group_approval(self):
        self.db.save_registered_user({'telegram_id': 7, 'status': 'blocked'})
        self.select('clash'); self.dispatch('获取订阅')
        self.assertFalse(bot.verify_group_member(self.checker, 7, -100).allowed)
        self.assertTrue(all(not c.call.called for c in self.sites.clients.values()))

    def test_switch_lists_only_users_own_bound_sites(self):
        self.bound.update(['clash', 'ovo'])
        self.select('clash'); self.dispatch('切换机场')
        markup = self.api.send_message.call_args.kwargs['reply_markup']
        self.assertEqual(['Clash机场', 'OvO机场'],
                         [b['text'] for row in markup['keyboard'] for b in row])
        self.dispatch('OvO机场')
        self.assertEqual('ovo', self.sites.current(7))
        self.dispatch('囡囡喵机场')
        self.assertEqual('ovo', self.sites.current(7))

    def test_sole_existing_binding_is_restored_without_listing_other_sites(self):
        self.bound.add('clash'); self.dispatch('/start')
        self.assertEqual('clash', self.sites.current(7))
        self.assertIn('Clash机场', self.api.send_message.call_args.args[1])
        self.assertNotIn('OvO', str(self.api.send_message.call_args))

    def test_forged_site_name_and_old_predictable_start_payload_do_not_select(self):
        for text in ['OvO机场', '/start site_ovo', '/start 用户', '/start b_' + '0' * 32]:
            self.dispatch(text)
            self.assertIsNone(self.sites.current(7))
            self.assertNotIn('OvO', str(self.api.send_message.call_args))

    def test_discovery_failure_does_not_reveal_site_identity_or_cache_as_bound(self):
        self.bound.add('clash')
        self.sites.clients['ovo'].call.side_effect = BindingError('OvO secret diagnostic')
        self.dispatch('切换机场')
        self.assertNotIn('OvO', str(self.api.send_message.call_args))
        self.assertIn('暂未能完整获取', self.api.send_message.call_args.args[1])
        self.assertEqual(['clash'], self.sites.discoveries[7][1])

    def test_group_bind_entry_only_contains_its_own_site_link(self):
        self.dispatch('/绑定', chat=-103)
        markup = self.api.send_message.call_args.kwargs['reply_markup']
        self.assertEqual(self.sites.entry_url('ovo', 'fixture_bot'),
                         markup['inline_keyboard'][0][0]['url'])
        self.assertNotIn(self.sites.entry_payload('clash'), str(markup))
        self.assertTrue(all(not c.call.called for c in self.sites.clients.values()))

    def test_entry_tokens_are_unique_opaque_and_not_authentication(self):
        tokens = [self.sites.entry_payload(k) for k in self.sites.sites]
        self.assertEqual(6, len(set(tokens)))
        for key, token in zip(self.sites.sites, tokens):
            self.assertRegex(token, r'^b_[a-f0-9]{32}$')
            self.assertNotIn(self.sites.clients[key].secret, token)
        self.select('ovo'); self.reset_calls(); self.dispatch('绑定邮箱')
        self.assertEqual(['verify'], [c.args[0] for c in self.sites.clients['ovo'].call.call_args_list])
        self.assertIn('注册邮箱', self.api.send_message.call_args.args[1])

    def test_config_rejects_duplicates_and_unsafe_urls(self):
        cases = [('id', 'clash'), ('name', 'Clash机场'), ('secret', '0' * 64),
                 ('url', 'https://clash.example.invalid/api/v1/bot/telegram'), ('chat_ids', [-100]),
                 ('url', 'http://ovo.example.invalid/api/v1/bot/telegram'),
                 ('url', 'https://u:p@ovo.example.invalid/api/v1/bot/telegram'),
                 ('url', 'https://ovo.example.invalid/api/v1/bot/telegram?x=1'),
                 ('chat_ids', [True]), ('secret', 'short')]
        for field, value in cases:
            with self.subTest(field=field, value=value):
                items = copy.deepcopy(site_items()); items[1][field] = value
                with self.assertRaises(ValueError): PanelSites(items)

    def test_missing_config_does_not_fall_back_to_legacy(self):
        with patch.dict(os.environ, {'PANEL_EMAIL_BINDING_SITES_FILE': str(self.root / 'missing.json')}):
            with self.assertRaises(FileNotFoundError): bot.PanelUserChecker(self.db)

    def test_admin_lists_six_sites_without_secrets(self):
        path = self.root / 'sites.json'; path.write_text(json.dumps(site_items()), encoding='utf-8')
        with patch.dict(os.environ, {'PANEL_EMAIL_BINDING_SITES_FILE': str(path)}):
            handler = object.__new__(bot.AdminHandler)
            handler.db = self.db
            page = handler.render_admin()
        self.assertIn('已配置 6 个站', page)
        for item in site_items():
            self.assertIn(item['name'], page)
            self.assertNotIn(item['secret'], page)


if __name__ == '__main__':
    unittest.main()
