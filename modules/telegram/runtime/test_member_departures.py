import copy
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import Mock, patch

import bot
from member_departures import DepartureUnbinder
from panel_binding import BindingError
from panel_sites import PanelSites


class DepartureTests(unittest.TestCase):
    def setUp(self):
        self.now = 1000000
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.db = bot.AppDatabase(self.root/'test.sqlite3')
        self.sites = PanelSites([{'id': f's{i}', 'name': f'Site {i}',
            'url': f'https://s{i}.example.invalid/api/v1/bot/telegram',
            'secret': str(i)*64, 'chat_ids': [-100-i]} for i in range(6)])
        for cid in self.sites.groups:
            self.db.save_group({'chat_id': cid, 'title': 'Test', 'enabled': 1})
        self.api = Mock(spec=bot.TelegramBot)
        self.api.get_chat_member.return_value = {'status': 'left'}
        self.worker = self.make_worker()
        self.addCleanup(self.worker.stop)
        self.bound = {(site, 7) for site in self.sites.sites}
        self.attach_clients()

    def make_worker(self):
        return DepartureUnbinder(self.api, self.db, self.sites, self.root/'departures.json',
                                 set(self.sites.groups), clock=lambda: self.now)

    def attach_clients(self):
        for site, client in self.worker.clients.items():
            client.call = Mock(side_effect=lambda a, v, s=site: self.response(s, a, v))

    def response(self, site, action, values):
        key = (site, values['telegram_id'])
        if action == 'email/unbind/prepare':
            if key not in self.bound:
                return {'status': 'not_bound'}
            return {'status': 'confirmation_required', 'emailMasked': 'te***@example.invalid',
                    'confirmation_token': 'a'*32, 'expires_in': 300}
        self.assertEqual('email/unbind/confirm', action)
        self.bound.discard(key)
        return {'status': 'unbound', 'allowed': False}

    def event(self, *, status='left', old='member', actor=7, cid=-100, uid=7, update=1):
        return {'update_id': update, 'chat_member': {'chat': {'id': cid, 'type': 'supergroup'},
            'from': {'id': actor}, 'date': int(self.now),
            'old_chat_member': {'status': old, 'user': {'id': uid}},
            'new_chat_member': {'status': status, 'user': {'id': uid}}}}

    def dispatch(self, **kwargs):
        self.worker.observe(self.event(**kwargs))
        self.worker.run_due()

    def calls(self, site='s0'):
        return [c.args[0] for c in self.worker.clients[site].call.call_args_list]

    def actions(self):
        return [row['action'] for row in self.db.list_events()]

    def tearDown(self):
        self.api.kick_chat_member.assert_not_called()
        self.api.ban_advertiser.assert_not_called()
        self.api.approve_chat_join_request.assert_not_called()
        self.api.decline_chat_join_request.assert_not_called()
        self.api.request.assert_not_called()
        for client in self.worker.clients.values():
            self.assertTrue(all(c.args[0] in {'email/unbind/prepare', 'email/unbind/confirm'}
                                for c in client.call.call_args_list))

    def test_voluntary_leave_unbinds_only_group_site_and_notifies_privately(self):
        self.sites.select(7, 's5')
        self.sites.discoveries[7] = (999999999, list(self.sites.sites), False)
        self.dispatch()
        self.assertEqual({'s1', 's2', 's3', 's4', 's5'}, {s for s, _ in self.bound})
        self.assertEqual(['email/unbind/prepare', 'email/unbind/confirm'], self.calls())
        self.assertNotIn(7, self.sites.discoveries)
        args = self.api.send_message.call_args.args
        self.assertEqual(7, args[0])
        self.assertIn('Site 0', args[1])
        self.assertIn('重新绑定邮箱', args[1])
        self.assertNotIn('Site 5', args[1])
        self.assertIn('leave_unbind_success', self.actions())

    def test_all_six_sites_routed_independently(self):
        for i in range(6):
            self.dispatch(cid=-100-i)
            self.assertNotIn((f's{i}', 7), self.bound)
            self.assertEqual(2, len(self.calls(f's{i}')))
        self.assertEqual(6, self.api.send_message.call_count)

    def test_banned_never_unbinds_or_notifies(self):
        self.dispatch(status='kicked', actor=99)
        self.assertEqual([], self.calls())
        self.api.send_message.assert_not_called()

    def test_admin_removal_never_unbinds(self):
        self.dispatch(actor=99)
        self.assertEqual([], self.calls())

    def test_unbanning_never_unbinds(self):
        self.dispatch(old='kicked', actor=99)
        self.assertEqual([], self.calls())

    def test_rejected_join_request_never_unbinds(self):
        self.worker.observe({'update_id': 1, 'chat_join_request': {'from': {'id': 7}}})
        self.dispatch(old='left')
        self.assertEqual([], self.calls())

    def test_old_and_unknown_groups_ignored(self):
        self.dispatch(cid=-999)
        self.assertEqual([], self.calls())

    def test_allowlist_honored(self):
        self.worker.allowed = {-101}
        self.dispatch()
        self.assertEqual([], self.calls())

    def test_disabled_group_preserves_binding(self):
        self.db.save_group({'chat_id': -100, 'enabled': 0})
        self.dispatch()
        self.assertEqual([], self.calls())

    def test_blocked_local_user_preserved(self):
        self.db.save_registered_user({'telegram_id': 7, 'status': 'blocked'})
        self.dispatch()
        self.assertEqual([], self.calls())
        self.assertEqual('blocked', self.db.get_registered_user(7)['status'])

    def test_bot_and_mismatched_user_ignored(self):
        event = self.event()
        event['chat_member']['new_chat_member']['user']['is_bot'] = True
        self.worker.observe(event)
        event = self.event()
        event['chat_member']['old_chat_member']['user']['id'] = 8
        self.worker.observe(event)
        self.worker.run_due()
        self.assertEqual([], self.calls())

    def test_restricted_member_can_leave(self):
        event = self.event(old='restricted')
        event['chat_member']['old_chat_member']['is_member'] = True
        self.worker.observe(event)
        self.worker.run_due()
        self.assertEqual(2, len(self.calls()))

    def test_restricted_nonmember_cannot_trigger_unbind(self):
        self.dispatch(old='restricted')
        self.assertEqual([], self.calls())

    def test_duplicate_event_never_unbinds_new_binding(self):
        self.dispatch()
        self.bound.add(('s0', 7))
        self.dispatch()
        self.assertIn(('s0', 7), self.bound)
        self.api.send_message.assert_called_once()

    def test_older_event_after_join_ignored(self):
        self.worker.observe(self.event(status='member', old='left', update=2))
        self.dispatch()
        self.assertEqual([], self.calls())

    def test_new_join_cancels_pending_leave(self):
        self.worker.observe(self.event())
        self.dispatch(status='member', old='left', update=2)
        self.assertEqual([], self.calls())

    def test_new_ban_cancels_pending_leave(self):
        self.worker.observe(self.event())
        self.dispatch(status='kicked', old='left', actor=99, update=2)
        self.assertEqual([], self.calls())

    def test_live_ban_or_rejoin_preserves_binding(self):
        for status in ['kicked', 'member', 'restricted', 'administrator', 'creator']:
            self.api.get_chat_member.return_value = {'status': status}
            self.dispatch(update=len(status))
        self.assertEqual([], self.calls())

    def test_ban_between_prepare_and_confirm_preserves_binding(self):
        self.api.get_chat_member.side_effect = [{'status': 'left'}, {'status': 'kicked'}]
        self.dispatch()
        self.assertEqual(['email/unbind/prepare'], self.calls())
        self.assertIn(('s0', 7), self.bound)
        self.api.send_message.assert_not_called()

    def test_status_network_failure_keeps_binding_and_retries(self):
        self.api.get_chat_member.side_effect = RuntimeError('offline')
        self.dispatch()
        self.assertEqual([], self.calls())
        self.api.get_chat_member.side_effect = None
        self.now += 30
        self.worker.run_due()
        self.assertNotIn(('s0', 7), self.bound)

    def test_prepare_failure_keeps_binding_and_retries(self):
        client = self.worker.clients['s0']
        client.call.side_effect = BindingError('offline')
        self.dispatch()
        self.assertIn(('s0', 7), self.bound)
        self.api.send_message.assert_not_called()
        client.call.side_effect = lambda a, v: self.response('s0', a, v)
        self.now += 30
        self.worker.run_due()
        self.assertNotIn(('s0', 7), self.bound)

    def test_uncertain_confirmation_retries_same_token_and_request_id(self):
        def response(action, values):
            result = self.response('s0', action, values)
            if action.endswith('/confirm'):
                raise BindingError('response lost')
            return result
        client = self.worker.clients['s0']
        client.call.side_effect = response
        self.dispatch()
        first = copy.deepcopy(client.call.call_args.args)
        self.worker = self.make_worker()
        self.attach_clients()
        self.now += 30
        self.worker.run_due()
        self.assertEqual(['email/unbind/confirm'], self.calls())
        self.assertEqual(first, self.worker.clients['s0'].call.call_args.args)

    def test_confirmation_failure_expires_without_preparing_again(self):
        client = self.worker.clients['s0']
        def response(action, values):
            if action.endswith('/confirm'):
                raise BindingError('changed binding')
            return self.response('s0', action, values)
        client.call.side_effect = response
        self.dispatch()
        self.now += 300
        self.worker.run_due()
        self.assertEqual(2, len(self.calls()))
        self.assertIn(('s0', 7), self.bound)
        self.assertIn('leave_unbind_expired', self.actions())

    def test_notification_failure_retries_message_not_unbind(self):
        self.api.send_message.side_effect = RuntimeError('offline')
        self.dispatch()
        self.assertNotIn(('s0', 7), self.bound)
        self.assertEqual('notify', self.worker.state['rows']['-100:7']['phase'])
        self.now += 30
        self.api.send_message.side_effect = None
        self.worker.run_due()
        self.assertEqual(2, len(self.calls()))
        self.assertIn('leave_notice_sent', self.actions())

    def test_blocked_private_chat_records_failure_without_rebinding(self):
        self.api.send_message.side_effect = RuntimeError('Telegram API HTTP 403: blocked')
        self.dispatch()
        self.now += 30
        self.worker.run_due()
        self.api.send_message.assert_called_once()
        self.assertNotIn(('s0', 7), self.bound)
        self.assertIn('leave_notice_blocked', self.actions())

    def test_notification_expiry_never_repeats_unbind(self):
        self.api.send_message.side_effect = RuntimeError('offline')
        self.dispatch()
        self.now += 3600
        self.worker.run_due()
        self.assertEqual(2, len(self.calls()))
        self.api.send_message.assert_called_once()

    def test_already_unbound_no_message(self):
        self.bound.discard(('s0', 7))
        self.dispatch()
        self.assertEqual(['email/unbind/prepare'], self.calls())
        self.api.send_message.assert_not_called()

    def test_pending_survives_restart(self):
        self.worker.observe(self.event())
        self.worker = self.make_worker()
        self.attach_clients()
        self.worker.run_due()
        self.assertNotIn(('s0', 7), self.bound)

    def test_completed_survives_restart(self):
        self.dispatch()
        self.worker = self.make_worker()
        self.attach_clients()
        self.dispatch()
        self.assertEqual([], self.calls())

    def test_pending_notice_survives_restart_without_repeating_unbind(self):
        self.api.send_message.side_effect = RuntimeError('offline')
        self.dispatch()
        self.worker = self.make_worker()
        self.attach_clients()
        self.now += 30
        self.api.send_message.side_effect = None
        self.worker.run_due()
        self.assertEqual([], self.calls())
        self.assertIn('leave_notice_sent', self.actions())

    def test_events_before_activation_and_future_events_ignored(self):
        for date in [self.now - 1, self.now + 31]:
            event = self.event()
            event['chat_member']['date'] = date
            self.worker.observe(event)
        self.worker.run_due()
        self.assertEqual([], self.calls())

    def test_persist_failure_does_not_acknowledge_event(self):
        with patch.object(self.worker, '_save', side_effect=OSError('disk full')):
            with self.assertRaises(OSError):
                self.worker.observe(self.event())
        self.assertEqual({}, self.worker.state['rows'])

    def test_confirmation_not_sent_when_token_cannot_be_persisted(self):
        self.worker.observe(self.event())
        original = self.worker._save
        def save():
            if 'confirmation' in self.worker.state['rows']['-100:7']:
                raise OSError('disk full')
            original()
        with patch.object(self.worker, '_save', side_effect=save):
            self.worker.run_due()
        self.assertEqual(['email/unbind/prepare'], self.calls())
        self.assertIn(('s0', 7), self.bound)

    def test_completed_file_contains_no_token_or_email(self):
        self.dispatch()
        raw = (self.root/'departures.json').read_text()
        self.assertNotIn('a'*32, raw)
        self.assertNotIn('example.invalid', raw)
        self.assertNotIn('confirmation', raw)

    def test_worker_starts_and_stops(self):
        self.worker.start()
        self.assertTrue(self.worker.thread.is_alive())
        self.worker.stop()
        self.assertFalse(self.worker.thread.is_alive())

    def test_polling_explicitly_subscribes_to_member_updates(self):
        api = bot.TelegramBot('fixture')
        api.request = Mock(return_value={'result': []})
        api.get_updates(123, 5)
        self.assertEqual(['message', 'chat_join_request', 'chat_member', 'callback_query'],
                         api.request.call_args.args[1]['allowed_updates'])

    def run_poll_loop(self, fail=False):
        checker = Mock(spec=bot.PanelUserChecker)
        checker.sites = self.sites
        worker = Mock(spec=DepartureUnbinder)
        if fail:
            worker.observe.side_effect = OSError('fixture disk full')
        self.api.get_me.return_value = {'username': 'fixture'}
        self.api.get_updates.side_effect = [[self.event(update=5)], KeyboardInterrupt]
        order = Mock()
        order.attach_mock(worker.observe, 'observe')
        with patch.dict(os.environ, {'TELEGRAM_BOT_TOKEN': 'fixture', 'WEB_ADMIN_ENABLE': 'false',
                                    'TELEGRAM_DROP_PENDING_UPDATES': 'false'}), \
                patch.object(bot, 'AppDatabase', return_value=self.db), \
                patch.object(bot, 'PanelUserChecker', return_value=checker), \
                patch.object(bot, 'TelegramBot', return_value=self.api), \
                patch.object(bot, 'DepartureUnbinder', return_value=worker), \
                patch.object(bot, 'MessageCleanup'), patch.object(bot, 'JoinRequestCleanup'), \
                patch.object(bot, 'load_state', return_value={'offset': 4}), \
                patch.object(bot, 'save_state') as save, patch.object(bot.time, 'sleep'):
            order.attach_mock(save, 'save')
            bot.run_bot()
        worker.stop.assert_called_once()
        return order, save

    def test_poll_loop_persists_departure_before_offset(self):
        order, save = self.run_poll_loop()
        self.assertEqual(['observe', 'save'], [c[0] for c in order.mock_calls])
        save.assert_called_once_with({'offset': 6})

    def test_poll_loop_does_not_advance_offset_on_persistence_failure(self):
        _, save = self.run_poll_loop(fail=True)
        save.assert_not_called()
        self.assertEqual(4, self.api.get_updates.call_args.kwargs['offset'])


if __name__ == '__main__':
    unittest.main()
