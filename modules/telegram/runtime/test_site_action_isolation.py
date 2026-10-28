import copy
import itertools
import os
import time
import unittest
from unittest.mock import Mock, patch

import bot
from panel_binding import BindingError
import test_panel_sites as site_fixtures


class SiteActionIsolationTests(unittest.TestCase):
    dispatch = site_fixtures.MultiSiteTests.dispatch
    select = site_fixtures.MultiSiteTests.select
    reset_calls = site_fixtures.MultiSiteTests.reset_calls
    assert_only_site_called = site_fixtures.MultiSiteTests.assert_only_site_called

    def setUp(self):
        site_fixtures.MultiSiteTests.setUp(self)
        self.accounts = {(site, user): {'bound': True, 'revision': 0, 'plan': site,
                                       'password': 'unchanged', 'traffic': 100, 'balance': 20}
                         for site in self.sites.sites for user in (7, 8)}
        self.writes = []
        self.tokens = {}

    def response(self, site, action, values):
        user = values['telegram_id']
        account = self.accounts.get((site, user))
        if action == 'verify':
            return {'allowed': bool(account and account['bound'])}
        if not account or not account['bound']:
            raise BindingError('Please bind first')
        if action == 'account/subscription':
            return {'subscribe_url': f'https://{site}.example.invalid/sub/{user}/{account["revision"]}'}
        if action == 'account/plan':
            return {'plan_name': site, 'status': 'active', 'expires_at': None,
                    'total_bytes': 1000, 'used_bytes': 200, 'remaining_bytes': 800}
        if action == 'account/publication':
            return {'publication_url': f'https://{site}.example.invalid/publication'}
        if action.endswith('/prepare'):
            operation = 'reset' if '/reset/' in action else 'unbind'
            token = ('a' if operation == 'reset' else 'b') * 32
            self.tokens[(site, user, operation)] = token
            return {'status': 'confirmation_required', 'confirmation_token': token,
                    'emailMasked': 'fi***@example.invalid', 'expires_in': 300}
        if action.endswith('/confirm'):
            operation = 'reset' if '/reset/' in action else 'unbind'
            self.assertEqual(self.tokens[(site, user, operation)], values['confirmation_token'])
            self.writes.append((site, user, operation))
            if operation == 'reset':
                account['revision'] += 1
                return {'status': 'reset', 'subscribe_url': f'https://{site}.example.invalid/sub/{user}/{account["revision"]}'}
            account['bound'] = False
            return {'status': 'unbound', 'allowed': False}
        self.fail('Unexpected panel action: ' + action)

    def button(self, index=0):
        value = self.api.send_message.call_args.kwargs['reply_markup']['inline_keyboard'][0][index]['callback_data']
        self.assertLessEqual(len(value.encode()), 64)
        return value

    def callback(self, data, user=7, chat=None, private=True):
        bot.handle_account_callback(self.api, self.db, self.checker, {
            'id': 'isolation-fixture', 'from': {'id': user}, 'data': data,
            'message': {'message_id': 99, 'chat': {'id': user if chat is None else chat,
                                                 'type': 'private' if private else 'supergroup'}}})

    def test_all_30_site_pairs_and_both_destructive_actions(self):
        for source, target in itertools.permutations(self.sites.sites, 2):
            for operation, label in [('reset', '重置订阅'), ('unbind', '解绑邮箱')]:
                with self.subTest(source=source, target=target, operation=operation):
                    for account in self.accounts.values():
                        account['bound'] = True
                    self.sites.discoveries.clear()
                    self.select(source); self.dispatch(label)
                    old_button = self.button()
                    self.dispatch('切换机场')
                    self.assertIsNone(self.sites.current(7))
                    self.reset_calls(); self.dispatch('获取订阅')
                    self.assertTrue(all(c.args[0] == 'verify' for client in self.sites.clients.values()
                                        for c in client.call.call_args_list))
                    self.sites.discoveries[7] = (0, list(self.sites.sites), False)
                    self.dispatch(self.sites.sites[target]['label'])
                    self.assertEqual(target, self.sites.current(7))
                    for label_read in ['获取订阅', '查看套餐', '发布页']:
                        self.reset_calls(); self.dispatch(label_read)
                        self.assert_only_site_called(target)
                        self.assertTrue(self.api.send_message.call_args.args[1].startswith(self.sites.sites[target]['label']))
                    self.dispatch(label); new_button = self.button()
                    self.assertNotEqual(old_button, new_button)
                    before = copy.deepcopy(self.accounts)
                    self.reset_calls(); self.callback(old_button)
                    self.assertEqual(before, self.accounts)
                    self.assertTrue(all(not client.call.called for client in self.sites.clients.values()))
                    self.callback(new_button)
                    changed = {key for key in self.accounts if self.accounts[key] != before[key]}
                    self.assertEqual({(target, 7)}, changed)
                    self.assertEqual((target, 7, operation), self.writes[-1])
                    for key in ['password', 'traffic', 'balance', 'plan']:
                        self.assertEqual(before[(target, 7)][key], self.accounts[(target, 7)][key])
                    count = len(self.writes)
                    self.callback(new_button)
                    self.assertEqual(count, len(self.writes))
        self.assertEqual(60, len(self.writes))

    def test_other_user_group_forged_and_old_operation_callbacks_are_rejected(self):
        for operation, label in [('reset', '重置订阅'), ('unbind', '解绑邮箱')]:
            self.select('pianyi'); self.dispatch(label); data = self.button()
            self.select('pianyi', 8); self.dispatch(label, 8)
            before = copy.deepcopy(self.accounts)
            self.callback(data, user=8)
            self.callback(data, chat=-123, private=False)
            self.callback(data, chat=8)
            self.callback(data[:-32] + 'f' * 32)
            self.callback(data.replace(operation, 'unbind' if operation == 'reset' else 'reset'))
            self.callback('unrecognized')
            self.assertEqual(before, self.accounts)
            self.assertEqual([], self.writes)

    def test_typed_confirmation_cannot_bypass_bound_buttons(self):
        self.select('gm')
        for prepare, confirm in [('重置订阅', '确认重置订阅'), ('解绑邮箱', '确认解绑')]:
            self.dispatch(prepare); self.reset_calls(); self.dispatch(confirm)
            self.assertEqual([], self.writes)
            self.assertTrue(all(not client.call.called for client in self.sites.clients.values()))

    def test_menu_switch_failure_restart_expiry_and_roundtrip_invalidate_old_callbacks(self):
        for operation, label in [('reset', '重置订阅'), ('unbind', '解绑邮箱')]:
            for case in ['menu', 'failed', 'restart', 'expiry', 'roundtrip', 'replace', 'cancel']:
                with self.subTest(operation=operation, case=case):
                    self.select('pianyi'); self.dispatch(label); data = self.button()
                    if case == 'menu':
                        self.dispatch('切换机场')
                    elif case == 'failed':
                        self.accounts[('gm', 7)]['bound'] = False
                        self.sites.discoveries.clear(); self.dispatch('光喵机场')
                        self.accounts[('gm', 7)]['bound'] = True
                    elif case == 'restart':
                        self.sites.begin_switch(7)
                    elif case == 'expiry':
                        pending = self.sites.clients['pianyi'].reset_confirmations if operation == 'reset' else self.sites.clients['pianyi'].unbind_confirmations
                        pending[7]['expires_at'] = 0
                    elif case == 'roundtrip':
                        self.select('gm'); self.select('pianyi'); self.dispatch(label)
                    elif case == 'replace':
                        self.dispatch(label)
                    else:
                        self.callback(self.button(1))
                    self.reset_calls(); self.callback(data)
                    self.assertEqual([], self.writes)
                    self.assertTrue(all(not client.call.called for client in self.sites.clients.values()))

    def test_blocked_user_cannot_use_previously_valid_callback(self):
        self.select('gm'); self.dispatch('重置订阅'); data = self.button()
        self.db.save_registered_user({'telegram_id': 7, 'status': 'blocked'})
        self.reset_calls(); self.callback(data)
        self.assertEqual([], self.writes)
        self.assertTrue(all(not client.call.called for client in self.sites.clients.values()))

    def test_acknowledgement_failure_does_not_write_and_can_retry(self):
        self.select('gm'); self.dispatch('重置订阅'); data = self.button()
        self.api.answer_callback_query.side_effect = RuntimeError('offline')
        with self.assertRaises(RuntimeError):
            self.callback(data)
        self.assertEqual([], self.writes)
        self.api.answer_callback_query.side_effect = None
        self.callback(data)
        self.assertEqual([('gm', 7, 'reset')], self.writes)

    def test_interleaved_users_keep_separate_selected_sites_and_confirmation(self):
        self.select('gm', 7); self.dispatch('重置订阅', 7); first = self.button()
        self.select('ovo', 8); self.dispatch('重置订阅', 8); second = self.button()
        self.dispatch('获取订阅', 7)
        self.assertIn('https://gm.example.invalid/sub/7/', self.api.send_message.call_args.args[1])
        self.dispatch('获取订阅', 8)
        self.assertIn('https://ovo.example.invalid/sub/8/', self.api.send_message.call_args.args[1])
        self.callback(first, 7); self.callback(second, 8)
        self.assertEqual([('gm', 7, 'reset'), ('ovo', 8, 'reset')], self.writes)

    def test_private_join_retry_only_processes_selected_sites_group(self):
        for chat in self.sites.groups:
            self.db.remember_pending_join(chat, 7, 9007, bot.now_ts())
        self.select('gm'); self.reset_calls(); self.dispatch('重试入群验证')
        group = self.sites.sites['gm']['chat_ids'][0]
        self.api.approve_chat_join_request.assert_called_once_with(group, 7)
        self.assert_only_site_called('gm')
        remaining = {row['chat_id'] for row in self.db.pending_joins_for(7)}
        self.assertEqual(set(self.sites.groups) - {group}, remaining)

    def test_group_join_during_switch_does_not_choose_a_private_site(self):
        self.select('pianyi'); self.dispatch('重置订阅'); old_button = self.button()
        self.dispatch('切换机场')
        group = self.sites.sites['gm']['chat_ids'][0]
        with patch.dict(os.environ, {'TELEGRAM_BOT_USERNAME': 'fixture_bot'}):
            result = bot.hold_join_for_binding(self.api, self.db, {
                'chat': {'id': group, 'title': 'fixture'}, 'from': {'id': 7},
                'user_chat_id': 9007, 'date': bot.now_ts()}, self.checker)
        self.assertTrue(self.sites.awaiting_choice(7))
        self.assertIsNone(self.sites.current(7))
        for label in ['获取订阅', '重置订阅', '解绑邮箱', '重试入群验证']:
            self.reset_calls(); self.dispatch(label)
            self.assertTrue(all(c.args[0] == 'verify' for client in self.sites.clients.values()
                                for c in client.call.call_args_list))
        self.callback(old_button)
        self.assertEqual([], self.writes)
        self.assertFalse(self.api.approve_chat_join_request.called)
        self.select('ovo')
        self.assertFalse(self.sites.awaiting_choice(7))
        self.reset_calls(); self.dispatch('获取订阅'); self.assert_only_site_called('ovo')

    def test_single_remaining_binding_does_not_auto_select_during_switch(self):
        self.select('gm'); self.dispatch('切换机场')
        for (site, user), account in self.accounts.items():
            if user == 7:
                account['bound'] = site == 'ovo'
        self.sites.discoveries.clear()
        for label in ['获取订阅', '重置订阅', '解绑邮箱']:
            self.dispatch(label)
            self.assertIsNone(self.sites.current(7))
            self.assertTrue(self.sites.awaiting_choice(7))
        self.assertEqual([], self.writes)
        self.dispatch(self.sites.sites['ovo']['label'])
        self.assertEqual('ovo', self.sites.current(7))

    def test_switch_state_expires_and_respects_capacity(self):
        self.sites.begin_switch(7)
        self.assertTrue(self.sites.awaiting_choice(7))
        self.sites.selections[7] = (None, 0)
        self.assertFalse(self.sites.awaiting_choice(7))
        self.sites.selections = {uid: ('gm', time.monotonic() + 60) for uid in range(20000)}
        self.dispatch('切换机场', 20001)
        self.assertEqual(20000, len(self.sites.selections))
        self.assertIn('稍后再试', self.api.send_message.call_args.args[1])
        self.dispatch('切换机场', 7)
        self.assertTrue(self.sites.awaiting_choice(7))
        self.assertEqual(20000, len(self.sites.selections))

    def test_poll_loop_delivers_inline_cancel_without_sending_panel_writes(self):
        self.select('gm'); self.dispatch('重置订阅')
        query = {'id': 'poll-fixture', 'from': {'id': 7}, 'data': self.button(1),
                 'message': {'message_id': 99, 'chat': {'id': 7, 'type': 'private'}}}
        self.api.get_me.return_value = {'username': 'fixture'}
        self.api.get_updates.side_effect = [[{'update_id': 123, 'callback_query': query}], KeyboardInterrupt]
        with patch.dict(os.environ, {'TELEGRAM_BOT_TOKEN': 'fixture', 'WEB_ADMIN_ENABLE': 'false',
                                     'TELEGRAM_DROP_PENDING_UPDATES': 'false'}), \
                patch.object(bot, 'AppDatabase', return_value=self.db), \
                patch.object(bot, 'PanelUserChecker', return_value=self.checker), \
                patch.object(bot, 'TelegramBot', return_value=self.api), \
                patch.object(bot, 'DepartureUnbinder'), patch.object(bot, 'MessageCleanup'), \
                patch.object(bot, 'JoinRequestCleanup'), patch.object(bot, 'load_state', return_value={'offset': 123}), \
                patch.object(bot, 'save_state') as save:
            bot.run_bot()
        save.assert_called_once_with({'offset': 124})
        self.api.answer_callback_query.assert_called_once_with('poll-fixture')
        self.assertEqual({}, self.sites.clients['gm'].reset_confirmations)
        self.assertEqual([], self.writes)


if __name__ == '__main__':
    unittest.main()
