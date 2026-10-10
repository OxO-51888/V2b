import contextlib
import http.client
import io
import json
import os
from pathlib import Path
import sqlite3
import tempfile
import threading
import unittest
import urllib.error
from unittest.mock import Mock, patch
from urllib.parse import urlencode

import bot


class BotTests(unittest.TestCase):
    def setUp(self):
        environment = patch.dict(os.environ, {'PANEL_EMAIL_BINDING_URL': '', 'PANEL_EMAIL_BINDING_SECRET': '',
                                              'TELEGRAM_JOIN_ONBOARDING_CHAT_IDS': '', 'TELEGRAM_ALLOWED_CHAT_IDS': ''})
        environment.start(); self.addCleanup(environment.stop)
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.db = bot.AppDatabase(Path(self.temp.name) / 'test.sqlite3')
        self.api = Mock(spec=bot.TelegramBot)
        self.api.send_message.return_value = {'ok': True, 'result': {'message_id': 99}}
        self.api.get_chat_member.return_value = {'status': 'member'}
        self.checker = Mock(spec=bot.PanelUserChecker)
        self.checker.email_client = Mock(spec=bot.PanelBindingClient)
        self.checker.email_client.verify.return_value = {'allowed': False}
        self.checker.verify.return_value = bot.VerificationResult(True, 'registered')

    def message(self, text, chat_type='supergroup'):
        return {'chat': {'id': -123, 'type': chat_type, 'title': 'Test'}, 'message_id': 42,
                'from': {'id': 7, 'first_name': 'Test'}, 'text': text}

    def dispatch(self, message, allowed=None):
        bot.handle_message(self.api, self.db, self.checker, allowed or set(), message)

    def retained_data(self):
        with self.db.connection() as conn:
            return {
                'groups': [tuple(r) for r in conn.execute('SELECT chat_id,title,enabled,verify_join,verify_message,created_at,updated_at FROM groups ORDER BY chat_id')],
                'settings': [tuple(r) for r in conn.execute("SELECT * FROM settings WHERE key != 'keyword_reply_default' ORDER BY key")],
                'users': [tuple(r) for r in conn.execute('SELECT * FROM registered_users ORDER BY telegram_id')],
                'events': [tuple(r) for r in conn.execute('SELECT * FROM events ORDER BY id')],
            }

    def test_migration_preserves_other_data_and_is_idempotent(self):
        self.db.save_group({'chat_id': -123, 'title': 'Keep', 'enabled': 1, 'verify_join': 1, 'verify_message': 0})
        self.db.save_registered_user({'telegram_id': 7, 'status': 'active', 'note': 'Keep'})
        self.db.record_event(event_type='bind', action='bind_success')
        with self.db.connection() as conn:
            conn.execute('ALTER TABLE groups ADD COLUMN keyword_reply INTEGER NOT NULL DEFAULT 1')
            conn.execute("INSERT INTO settings VALUES ('keyword_reply_default', '1')")
        before = self.retained_data()
        for _ in range(2):
            self.db = bot.AppDatabase(self.db.path)
            self.assertEqual(before, self.retained_data())
            self.assertNotIn('keyword_reply_default', self.db.settings())
            with self.db.connection() as conn:
                self.assertNotIn('keyword_reply', [r['name'] for r in conn.execute('PRAGMA table_info(groups)')])

    def test_removed_texts_never_reply(self):
        for text in ['订阅不显示', '节点超时', '添加配置失败', '官网打不开', 'x509', '订单付款', '/reload', '/reload@testbot']:
            with self.subTest(text=text):
                self.dispatch(self.message(text))
        self.api.send_message.assert_not_called()
        self.assertEqual([], self.db.list_events())

    def test_old_global_setting_cannot_be_recreated(self):
        with self.assertRaises(ValueError):
            self.db.save_settings({'keyword_reply_default': '1'})
        self.assertNotIn('keyword_reply_default', self.db.settings())

    def test_help_and_ping_remain(self):
        for command in ['/help', '/start', '/ping', '/验证']:
            with self.subTest(command=command):
                self.api.reset_mock()
                self.dispatch(self.message(command))
                self.api.send_message.assert_called_once()
                text = self.api.send_message.call_args.args[1]
                self.assertNotIn('关键词', text)
                self.assertNotIn('/reload', text)

    def test_private_binding_remains(self):
        self.dispatch(self.message('/bind member@example.invalid', 'private'))
        self.checker.email_client.request_code.assert_called_once_with(7, 'member@example.invalid')
        self.api.send_message.assert_called_once()
        self.assertEqual('email_code_requested', self.db.list_events()[0]['action'])

    def test_group_binding_still_protects_token(self):
        self.dispatch(self.message('/bind https://example.invalid/sub?token=abcdefgh12345678'))
        self.api.delete_message.assert_called_once_with(-123, 42)
        self.checker.email_client.request_code.assert_not_called()
        self.assertEqual('', self.db.list_events()[0]['text'])

    def test_verification_off_does_not_query_or_delete(self):
        self.dispatch(self.message('normal message'))
        self.checker.verify.assert_not_called()
        self.api.send_message.assert_not_called()
        self.api.delete_message.assert_not_called()

    def test_message_verification_and_admin_exemption_remain(self):
        self.db.save_group({'chat_id': -123, 'enabled': 1, 'verify_message': 1})
        self.db.save_settings({'unregistered_message_action': 'delete_warn'})
        self.checker.verify.return_value = bot.VerificationResult(False, 'not registered')
        self.dispatch(self.message('订阅不显示'))
        self.api.delete_message.assert_called_once_with(-123, 42)
        self.api.send_message.assert_called_once()
        self.assertEqual('message_verify', self.db.list_events()[0]['event_type'])
        self.api.reset_mock()
        self.checker.reset_mock()
        self.api.get_chat_member.return_value = {'status': 'administrator'}
        self.dispatch(self.message('订阅不显示'))
        self.checker.verify.assert_not_called()
        self.api.delete_message.assert_not_called()
        self.api.send_message.assert_not_called()

    def test_registered_messages_remain_silent(self):
        self.db.save_group({'chat_id': -123, 'enabled': 1, 'verify_message': 1})
        self.dispatch(self.message('节点超时'))
        self.checker.verify.assert_called_once_with(7)
        self.api.send_message.assert_not_called()

    def test_group_disabled_and_allowlist_preserved(self):
        self.db.save_group({'chat_id': -123, 'enabled': 0, 'verify_message': 1})
        self.dispatch(self.message('/ping'))
        self.db.save_group({'chat_id': -123, 'enabled': 1, 'verify_message': 1})
        self.dispatch(self.message('/ping'), {-456})
        self.api.send_message.assert_not_called()
        self.checker.verify.assert_not_called()

    def test_join_request_verification_remains(self):
        self.db.save_group({'chat_id': -123, 'enabled': 1, 'verify_join': 1})
        request = {'chat': {'id': -123, 'title': 'Test'}, 'from': {'id': 7}}
        bot.handle_join_request(self.api, self.db, self.checker, set(), request)
        self.api.approve_chat_join_request.assert_called_once_with(-123, 7)
        self.checker.verify.return_value = bot.VerificationResult(False, 'not registered')
        bot.handle_join_request(self.api, self.db, self.checker, set(), request)
        self.api.decline_chat_join_request.assert_called_once_with(-123, 7)

    def test_new_member_verification_remains(self):
        self.db.save_group({'chat_id': -123, 'enabled': 1, 'verify_join': 1})
        message = self.message('')
        message['new_chat_members'] = [{'id': 7, 'first_name': 'Test'}]
        self.checker.verify.return_value = bot.VerificationResult(False, 'not registered')
        self.dispatch(message)
        self.api.kick_chat_member.assert_called_once_with(-123, 7)

    def enable_verification(self):
        self.db.save_group({'chat_id': -123, 'enabled': 1, 'verify_join': 1, 'verify_message': 1})
        self.db.save_settings({'unregistered_message_action': 'delete_warn'})

    def test_all_media_and_commands_require_registration(self):
        self.enable_verification()
        self.checker.verify.return_value = bot.VerificationResult(False, 'not registered')
        messages = [self.message(cmd) for cmd in ['/start', '/help', '/ping', '/验证', '/unknown@testbot text']]
        for field in bot.USER_MESSAGE_FIELDS - {'text'}:
            message = self.message('')
            del message['text']
            message[field] = {'test': True}
            messages.append(message)
        caption = self.message('')
        del caption['text']
        caption.update(photo=[{}], caption='caption')
        messages.append(caption)
        for message in messages:
            with self.subTest(fields=list(message), text=message.get('text')):
                self.api.reset_mock(); self.checker.reset_mock()
                self.dispatch(message)
                self.checker.verify.assert_called_once_with(7)
                self.api.delete_message.assert_called_once_with(-123, 42)
                self.api.send_message.assert_called_once()

    def test_registered_media_is_not_deleted(self):
        self.enable_verification()
        for field in bot.USER_MESSAGE_FIELDS:
            self.api.reset_mock(); self.checker.reset_mock()
            message = self.message('ok')
            if field != 'text':
                del message['text']; message[field] = {'test': True}
            self.dispatch(message)
            self.checker.verify.assert_called_once_with(7)
            self.api.delete_message.assert_not_called()
            self.api.send_message.assert_not_called()

    def test_service_messages_are_not_speech(self):
        self.enable_verification()
        message = self.message('')
        del message['text']
        message['left_chat_member'] = {'id': 8}
        self.dispatch(message)
        self.checker.verify.assert_not_called()
        self.api.delete_message.assert_not_called()

    def test_channel_identity_cannot_bypass_registration(self):
        self.enable_verification()
        message = self.message('channel speech')
        message['from']['is_bot'] = True
        message['sender_chat'] = {'id': -456}
        self.dispatch(message)
        self.api.delete_message.assert_called_once_with(-123, 42)
        self.checker.verify.assert_not_called()
        for change in [{'sender_chat': {'id': -123}}, {'sender_chat': {'id': -456}, 'is_automatic_forward': True}]:
            self.api.reset_mock()
            self.dispatch(dict(message, **change))
            self.api.delete_message.assert_not_called()

    def test_unavailable_gateway_does_not_deny_join_or_kick(self):
        self.enable_verification()
        self.checker.verify.return_value = bot.VerificationResult(False, 'timeout', unavailable=True)
        bot.handle_join_request(self.api, self.db, self.checker, set(), {'chat': {'id': -123}, 'from': {'id': 7}})
        self.dispatch(dict(self.message(''), new_chat_members=[{'id': 7}]))
        self.api.approve_chat_join_request.assert_not_called()
        self.api.decline_chat_join_request.assert_not_called()
        self.api.kick_chat_member.assert_not_called()
        self.api.send_message.assert_not_called()
        self.assertTrue(all(r['action'] == 'verification_deferred' for r in self.db.list_events()))

    def test_gateway_outage_blocks_speech_without_false_unregistered_claim(self):
        self.enable_verification()
        self.checker.verify.return_value = bot.VerificationResult(False, 'timeout', unavailable=True)
        self.dispatch(self.message('hello'))
        self.api.delete_message.assert_called_once_with(-123, 42)
        self.assertIn('暂时不可用', self.api.send_message.call_args.args[1])
        self.assertIn('verification_unavailable', self.db.list_events()[0]['action'])
        self.api.kick_chat_member.assert_not_called()

    def gateway_response(self, checker, data):
        response = Mock()
        response.read.return_value = json.dumps(data).encode()
        response.__enter__ = Mock(return_value=response)
        response.__exit__ = Mock(return_value=False)
        with patch('urllib.request.urlopen', return_value=response):
            return checker.query_verify_gateway_by_telegram_id(7)

    @patch.dict(os.environ, {'PANEL_VERIFY_GATEWAY_URL': 'https://example.invalid/api/bot/verify-user', 'PANEL_VERIFY_GATEWAY_SECRET': 'test-only'})
    def test_gateway_strictly_distinguishes_allowed_denied_and_bad_response(self):
        checker = bot.PanelUserChecker(self.db)
        for allowed in [True, False]:
            result = self.gateway_response(checker, {'data': {'allowed': allowed}})
            self.assertEqual(allowed, result.allowed)
            self.assertFalse(result.unavailable)
        for data in [None, [], {}, {'data': {}}, {'data': {'allowed': 'false'}}, {'data': {'allowed': 1}}]:
            result = self.gateway_response(checker, data)
            self.assertFalse(result.allowed)
            self.assertTrue(result.unavailable)

    @patch.dict(os.environ, {'PANEL_VERIFY_GATEWAY_URL': 'https://example.invalid/api/bot/verify-user', 'PANEL_VERIFY_GATEWAY_SECRET': 'test-only'})
    def test_gateway_transport_errors_do_not_mean_unregistered(self):
        checker = bot.PanelUserChecker(self.db)
        errors = [TimeoutError(), ConnectionResetError(), urllib.error.URLError('offline'),
                  urllib.error.HTTPError('https://example.invalid', 403, 'forbidden', {}, io.BytesIO(b'{}'))]
        for error in errors:
            with patch('urllib.request.urlopen', side_effect=error):
                result = checker.query_verify_gateway_by_telegram_id(7)
                self.assertTrue(result.unavailable)
                self.assertFalse(result.allowed)

    def test_registration_does_not_require_paid_plan(self):
        checker = bot.PanelUserChecker(self.db)
        for setting, expected in [('0', False), ('1', True)]:
            self.db.save_settings({'panel_require_active_plan': setting})
            with patch.object(checker, 'query_verify_gateway', return_value=bot.VerificationResult(True, 'registered')) as query:
                self.assertTrue(checker.verify(7).allowed)
                self.assertEqual(expected, query.call_args.args[0]['require_active_plan'])
                self.assertEqual('telegram_id', query.call_args.args[0]['mode'])

    def test_email_code_confirmation_is_private_and_not_logged(self):
        self.dispatch(self.message('/code 123456', 'private'))
        self.checker.email_client.confirm_code.assert_called_once_with(7, '123456')
        event = self.db.list_events()[0]
        self.assertEqual('bind_success', event['action'])
        self.assertNotIn('123456', str(dict(event)))
        self.assertIsNone(self.db.get_registered_user(7))

    def test_bare_email_and_code_in_private_are_supported(self):
        self.dispatch(self.message('member@example.invalid', 'private'))
        self.dispatch(self.message('123456', 'private'))
        self.checker.email_client.request_code.assert_called_once_with(7, 'member@example.invalid')
        self.checker.email_client.confirm_code.assert_called_once_with(7, '123456')

    def test_code_request_guides_plain_digits_and_spam_folder(self):
        self.dispatch(self.message('member@example.invalid', 'private'))
        text = self.api.send_message.call_args.args[1]
        self.assertIn('请打开注册邮箱', text)
        self.assertIn('直接在本私聊发送收到的6位验证码', text)
        self.assertIn('无需输入命令', text)
        self.assertIn('垃圾邮件', text)
        self.assertIn('10分钟', text)
        self.assertNotIn('/验证码', text)
        self.checker.email_client.confirm_code.assert_not_called()

    def test_binding_help_uses_plain_digits_and_spam_reminder(self):
        for command in ['/绑定', '/code', '/start', '/help']:
            with self.subTest(command=command):
                self.api.reset_mock()
                self.dispatch(self.message(command, 'private'))
                text = self.api.send_message.call_args.args[1]
                self.assertIn('直接发送收到的6位验证码', text)
                self.assertIn('垃圾邮件', text)
                self.assertNotIn('/验证码', text)
        self.checker.email_client.request_code.assert_not_called()
        self.checker.email_client.confirm_code.assert_not_called()

    def test_plain_code_preserves_leading_zero_and_trims_whitespace(self):
        self.dispatch(self.message('  001234\n', 'private'))
        self.checker.email_client.confirm_code.assert_called_once_with(7, '001234')
        self.checker.email_client.request_code.assert_not_called()
        self.assertIn('请点击“获取订阅”', self.api.send_message.call_args.args[1])
        self.assertNotIn('001234', str([dict(row) for row in self.db.list_events()]))

    def test_plain_group_code_never_confirms_binding(self):
        self.dispatch(self.message('001234'))
        self.checker.email_client.confirm_code.assert_not_called()
        self.checker.email_client.request_code.assert_not_called()

    def test_group_code_never_calls_panel(self):
        self.dispatch(self.message('/code 123456'))
        self.api.delete_message.assert_called_once_with(-123, 42)
        self.checker.email_client.confirm_code.assert_not_called()
        self.assertNotIn('123456', str(dict(self.db.list_events()[0])))

    def test_local_block_cannot_be_overridden_by_email_binding(self):
        self.db.save_registered_user({'telegram_id': 7, 'status': 'blocked'})
        self.dispatch(self.message('/bind member@example.invalid', 'private'))
        self.checker.email_client.request_code.assert_not_called()
        self.assertEqual('bind_failed', self.db.list_events()[0]['action'])

    def test_binding_failure_is_not_reported_as_success(self):
        self.checker.email_client.request_code.side_effect = bot.BindingError('发送暂未开放')
        self.dispatch(self.message('/bind member@example.invalid', 'private'))
        self.assertIn('暂未开放', self.api.send_message.call_args.args[1])
        self.assertEqual('bind_failed', self.db.list_events()[0]['action'])

    def test_new_panel_verification_bypasses_pc_gateway(self):
        checker = bot.PanelUserChecker(self.db)
        checker.email_client = Mock(configured=True)
        with patch.object(checker, 'query_verify_gateway_by_telegram_id') as gateway:
            for allowed in [True, False]:
                checker.email_client.verify.return_value = {'allowed': allowed, 'reason': 'result'}
                self.assertEqual(allowed, checker.verify(7).allowed)
            checker.email_client.verify.side_effect = bot.BindingError('offline')
            self.assertTrue(checker.verify(7).unavailable)
            gateway.assert_not_called()

    def test_retired_subscription_binding_has_no_callable_path(self):
        self.assertFalse(hasattr(bot.PanelUserChecker, 'bind_by_token'))
        self.assertFalse(hasattr(bot, 'extract_bind_token'))
        self.assertNotIn('完整订阅', bot.bind_help_text())

    def test_existing_manual_block_and_allow_are_preserved(self):
        checker = bot.PanelUserChecker(self.db)
        for status, expected in [('blocked', False), ('active', True)]:
            self.db.save_registered_user({'telegram_id': 7, 'status': status})
            with patch.object(checker, 'query_verify_gateway') as query:
                self.assertEqual(expected, checker.verify(7).allowed)
                query.assert_not_called()

    def test_join_allowlist_and_switches_are_respected(self):
        self.enable_verification()
        join = {'chat': {'id': -123}, 'from': {'id': 7}}
        bot.handle_join_request(self.api, self.db, self.checker, {-456}, join)
        self.db.save_group({'chat_id': -123, 'enabled': 1, 'verify_join': 0})
        bot.handle_join_request(self.api, self.db, self.checker, set(), join)
        self.checker.verify.assert_not_called()

    def test_removed_cli_is_rejected(self):
        with patch('sys.argv', ['bot.py', 'dry-run', 'hello']), patch.object(bot, 'load_env'), contextlib.redirect_stderr(io.StringIO()):
            with self.assertRaises(SystemExit) as result:
                bot.main()
        self.assertEqual(2, result.exception.code)

    def test_polling_runs_without_keyword_configuration(self):
        self.api.get_me.return_value = {'username': 'test'}
        self.api.get_updates.side_effect = [[{'update_id': 5, 'message': self.message('节点超时')}], KeyboardInterrupt]
        with patch.dict(os.environ, {'TELEGRAM_BOT_TOKEN': 'test-only', 'BOT_ADMIN_DB': str(self.db.path), 'WEB_ADMIN_ENABLE': 'false', 'TELEGRAM_DROP_PENDING_UPDATES': 'false', 'TELEGRAM_ALLOWED_CHAT_IDS': ''}), patch.object(bot, 'TelegramBot', return_value=self.api), patch.object(bot, 'load_state', return_value={'offset': 4}), patch.object(bot, 'save_state') as state, patch.object(bot.time, 'sleep'):
            bot.run_bot()
        state.assert_called_once_with({'offset': 6})
        self.api.send_message.assert_not_called()

    def test_admin_kept_controls_can_be_saved(self):
        handler = object.__new__(bot.AdminHandler)
        handler.db = self.db
        handler.save_settings({'verify_join_default': ['1'], 'unregistered_message_action': ['delete'], 'welcome_registered': ['1']})
        handler.save_group({'chat_id': ['-123'], 'title': ['Keep'], 'enabled': ['1'], 'verify_join': ['1'], 'verify_message': ['1']})
        self.assertEqual('1', self.db.get_setting('verify_join_default'))
        self.assertEqual('delete', self.db.get_setting('unregistered_message_action'))
        group = self.db.list_groups()[0]
        self.assertEqual((1, 1, 1), (group['enabled'], group['verify_join'], group['verify_message']))

    def test_admin_http_rejects_retired_controls_without_changes(self):
        self.db.save_group({'chat_id': -123, 'title': 'Keep', 'enabled': 1, 'verify_join': 1})
        handler = type('TestAdmin', (bot.AdminHandler,), {'db': self.db, 'log_message': lambda *args: None})
        server = bot.ThreadingHTTPServer(('127.0.0.1', 0), handler)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        try:
            with patch.dict(os.environ, {'WEB_ADMIN_PASSWORD': 'test-only', 'WEB_ADMIN_SECRET': 'test-secret'}):
                cookie = 'tg_admin_session=' + bot.make_session('test-only')
                before = self.retained_data()
                for endpoint, values in [('/admin/settings', {'keyword_reply_default': '1'}), ('/admin/group', {'chat_id': '-123', 'keyword_reply': '1'})]:
                    conn = http.client.HTTPConnection(*server.server_address)
                    conn.request('POST', endpoint, urlencode(values), {'Cookie': cookie, 'Content-Type': 'application/x-www-form-urlencoded'})
                    response = conn.getresponse()
                    self.assertEqual(400, response.status)
                    response.read(); conn.close()
                    self.assertEqual(before, self.retained_data())
                conn = http.client.HTTPConnection(*server.server_address)
                conn.request('GET', '/admin', headers={'Cookie': cookie})
                response = conn.getresponse()
                self.assertEqual(200, response.status)
                page = response.read().decode(); conn.close()
                self.assertNotIn('keyword_reply', page)
                self.assertNotIn('关键词', page)
                self.assertIn('name="verify_join"', page)
                self.assertIn('name="verify_message"', page)
        finally:
            server.shutdown(); server.server_close(); thread.join()


if __name__ == '__main__':
    unittest.main()
