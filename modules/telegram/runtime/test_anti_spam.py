import copy
from pathlib import Path
import tempfile
import unittest
from unittest.mock import Mock

import bot
from anti_spam import AdvertisingGuard


def message(mid=1, text='hi @target_bot', user=7, chat=-100):
    return {'chat': {'id': chat, 'type': 'supergroup', 'title': 'Fixture'},
            'message_id': mid, 'from': {'id': user, 'first_name': 'Fixture'}, 'text': text}


class AdvertisingTests(unittest.TestCase):
    def setUp(self):
        self.now = 1000
        self.guard = AdvertisingGuard({-100, -101, -102, -103, -104}, lambda: self.now)

    def test_ninth_is_allowed_tenth_is_candidate(self):
        for mid in range(1, 10):
            self.assertIsNone(self.guard.match(message(mid)))
        self.assertEqual('repeated_mention_10_in_600s', self.guard.match(message(10))['reason'])

    def test_ordinary_mentions_alone_never_ban(self):
        for mid in range(1, 31):
            self.assertIsNone(self.guard.match(message(mid, f'different {mid} @member_name')))

    def test_repeated_content_without_mentions_never_bans(self):
        for mid in range(1, 31):
            self.assertIsNone(self.guard.match(message(mid, 'hello world')))

    def test_email_and_bot_command_are_not_mentions(self):
        for text in ['me@example.com', '/help@fixture_bot']:
            for mid in range(1, 20):
                self.assertIsNone(self.guard.match(message(mid, text)))

    def test_all_five_groups_are_isolated(self):
        for chat in self.guard.chat_ids:
            for mid in range(1, 10):
                self.assertIsNone(self.guard.match(message(mid, chat=chat)))
        for chat in self.guard.chat_ids:
            self.assertIsNotNone(self.guard.match(message(10, chat=chat)))

    def test_user_counters_are_isolated(self):
        for mid in range(1, 10):
            self.guard.match(message(mid))
        self.assertIsNone(self.guard.match(message(10, user=8)))

    def test_duplicate_update_never_counts_twice(self):
        for _ in range(20):
            self.assertIsNone(self.guard.match(message()))

    def test_window_expiry_does_not_count_old_messages(self):
        for mid in range(1, 10):
            self.guard.match(message(mid))
        self.now += 600
        self.assertIsNone(self.guard.match(message(10)))
        self.assertIsNone(self.guard.match({**message(11), 'date': 1000}))

    def test_button_is_immediate_and_not_dependent_on_mentions(self):
        msg = message(text='offer')
        msg['reply_markup'] = {'inline_keyboard': [[{'text': 'Go', 'url': 'https://example.invalid'}]]}
        self.assertEqual('message_buttons', self.guard.match(msg)['reason'])

    def test_replying_to_a_button_message_is_not_sending_buttons(self):
        msg = message(text='is this safe?')
        msg['reply_to_message'] = {'reply_markup': {'inline_keyboard': [[{'text': 'Go'}]]}}
        self.assertIsNone(self.guard.match(msg))

    def test_empty_buttons_do_not_trigger(self):
        for rows in [[], [[]]]:
            self.assertIsNone(self.guard.match({**message(), 'reply_markup': {'inline_keyboard': rows}}))

    def test_caption_mentions_and_hidden_mentions_count(self):
        for mid in range(1, 11):
            msg = message(mid)
            del msg['text']
            msg.update(photo=[{}], caption='Contact Friend', caption_entities=[
                {'type': 'text_mention', 'offset': 8, 'length': 6, 'user': {'id': 88}}])
            result = self.guard.match(msg)
        self.assertIsNotNone(result)

    def test_hidden_mentions_of_different_users_are_not_identical(self):
        for mid in range(1, 15):
            msg = message(mid, 'Contact Friend')
            msg['entities'] = [{'type': 'text_mention', 'offset': 8, 'length': 6, 'user': {'id': mid}}]
            self.assertIsNone(self.guard.match(msg))

    def test_own_anonymous_admin_and_automatic_channel_forward_exempt(self):
        base = {**message(), 'reply_markup': {'inline_keyboard': [[{'text': 'Go'}]]}}
        self.assertIsNone(self.guard.match({**base, 'sender_chat': {'id': -100}}))
        self.assertIsNone(self.guard.match({**base, 'is_automatic_forward': True}))

    def test_external_channel_has_its_own_identity(self):
        msg = {**message(), 'sender_chat': {'id': -900}, 'reply_markup': {'inline_keyboard': [[{'text': 'Go'}]]}}
        self.assertEqual({'actor': -900, 'kind': 'channel', 'reason': 'message_buttons'}, self.guard.match(msg))

    def test_other_groups_private_and_unidentified_senders_are_ignored(self):
        base = {**message(), 'reply_markup': {'inline_keyboard': [[{'text': 'Go'}]]}}
        for change in [{'chat': {'id': -999, 'type': 'supergroup'}},
                       {'chat': {'id': -100, 'type': 'private'}}, {'from': {}}]:
            self.assertIsNone(self.guard.match({**base, **change}))


class ModerationTests(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.db = bot.AppDatabase(Path(temp.name) / 'bot.sqlite3')
        self.api = bot.TelegramBot('fixture-token')
        self.api.ad_guard = AdvertisingGuard({-100, -101, -102, -103, -104})
        self.api.request = Mock(side_effect=lambda method, data=None: {'ok': True, 'result':
                                {'status': 'member'} if method == 'getChatMember' else True})
        self.checker = Mock(spec=bot.PanelUserChecker)
        self.checker.verify.return_value = bot.VerificationResult(True, 'bound')
        self.msg = message(text='offer')
        self.msg['reply_markup'] = {'inline_keyboard': [[{'text': 'Go', 'url': 'https://example.invalid'}]]}

    def dispatch(self, msg=None, allowed=None):
        bot.handle_message(self.api, self.db, self.checker, allowed or set(), msg or self.msg)

    def methods(self):
        return [call.args[0] for call in self.api.request.call_args_list]

    def test_permanent_ban_never_calls_unban_or_website(self):
        self.dispatch()
        self.assertEqual(['getChatMember', 'banChatMember', 'deleteMessage'], self.methods())
        ban = self.api.request.call_args_list[1].args[1]
        self.assertEqual({'chat_id': -100, 'user_id': 7, 'revoke_messages': True}, ban)
        self.checker.verify.assert_not_called()
        self.assertEqual('user_banned', self.db.list_events()[0]['action'])

    def test_all_groups_work_with_email_verification_off(self):
        for chat in self.api.ad_guard.chat_ids:
            self.api.request.reset_mock()
            msg = copy.deepcopy(self.msg)
            msg['chat']['id'] = chat
            self.dispatch(msg)
            self.assertIn('banChatMember', self.methods())
            group = next(group for group in self.db.list_groups() if group['chat_id'] == chat)
            self.assertEqual(0, group['verify_message'])

    def test_bound_user_still_banned_on_tenth_identical_mention(self):
        self.db.save_group({'chat_id': -100, 'enabled': 1, 'verify_message': 1})
        for mid in range(1, 10):
            self.dispatch(message(mid))
        self.assertNotIn('banChatMember', self.methods())
        self.dispatch(message(10))
        self.assertIn('banChatMember', self.methods())
        self.assertNotIn('unbanChatMember', self.methods())
        self.assertEqual(9, self.checker.verify.call_count)

    def test_admins_and_owners_exempt(self):
        for status in ['administrator', 'creator']:
            self.api.request.reset_mock()
            self.api.request.side_effect = lambda method, data=None: {'ok': True, 'result': {'status': status}}
            self.dispatch()
            self.assertEqual(['getChatMember'], self.methods())

    def test_admin_lookup_outage_never_bans(self):
        self.api.request.side_effect = TimeoutError()
        self.dispatch()
        self.assertEqual(['getChatMember'], self.methods())
        self.assertEqual('ban_deferred', self.db.list_events()[0]['action'])

    def test_channel_ban_never_bans_anonymous_placeholder_user(self):
        self.dispatch({**self.msg, 'sender_chat': {'id': -900, 'title': 'Ads'}})
        self.assertEqual(['banChatSenderChat', 'deleteMessage'], self.methods())
        self.assertEqual({'chat_id': -100, 'sender_chat_id': -900}, self.api.request.call_args_list[0].args[1])

    def test_verification_binding_command_cannot_bypass_advertising(self):
        self.dispatch({**self.msg, 'text': '/bind'})
        self.assertIn('banChatMember', self.methods())
        self.assertNotIn('sendMessage', self.methods())

    def test_disabled_or_disallowed_group_never_bans(self):
        self.db.save_group({'chat_id': -100, 'enabled': 0})
        self.dispatch()
        self.api.request.assert_not_called()
        self.db.save_group({'chat_id': -100, 'enabled': 1})
        self.dispatch(allowed={-101})
        self.api.request.assert_not_called()

    def test_ban_failure_never_reports_success_or_unbans(self):
        def request(method, data=None):
            if method == 'getChatMember':
                return {'result': {'status': 'member'}}
            if method == 'banChatMember':
                raise RuntimeError('Insufficient rights')
            return {'result': True}
        self.api.request.side_effect = request
        self.dispatch()
        self.assertNotIn('unbanChatMember', self.methods())
        self.assertEqual('ban_failed', self.db.list_events()[0]['action'])


if __name__ == '__main__':
    unittest.main()
