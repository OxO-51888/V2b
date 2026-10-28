"""Signed, private panel API client. No SMTP or database credentials are needed."""
import hashlib
import hmac
import json
import math
import os
import re
import secrets
import time
import urllib.error
import urllib.parse
import urllib.request


class BindingError(Exception):
    pass


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def normalize_email(value):
    value = value.strip().lower()
    if len(value) > 254 or not value.isascii() or not re.fullmatch(
        r"[a-z0-9!#$%&'*+/=?^_`{|}~-]+(?:\.[a-z0-9!#$%&'*+/=?^_`{|}~-]+)*"
        r"@[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+", value
    ) or len(value.split('@', 1)[0]) > 64:
        return ''
    return value


class PanelBindingClient:
    def __init__(self, base_url=None, secret=None, *, require_local_request=False):
        self.base_url = (os.getenv('PANEL_EMAIL_BINDING_URL', '') if base_url is None else base_url).strip().rstrip('/')
        self.secret = (os.getenv('PANEL_EMAIL_BINDING_SECRET', '') if secret is None else secret).strip()
        self.require_local_request = require_local_request
        self.timeout = 6
        self.attempts = 2
        self.opener = urllib.request.build_opener(NoRedirect())
        self.email_requests = {}
        self.unbind_confirmations = {}
        self.reset_confirmations = {}

    def recent_email_request(self, telegram_id):
        current = time.monotonic()
        self.email_requests = {key: value for key, value in self.email_requests.items()
                               if current - value['requested_at'] < 3600}
        return self.email_requests.get(telegram_id)

    @property
    def configured(self):
        return bool(self.base_url)

    def call(self, action, values):
        parsed = urllib.parse.urlsplit(self.base_url)
        if (parsed.scheme != 'https' or not parsed.hostname or parsed.username or parsed.password
                or parsed.query or parsed.fragment or len(self.secret) < 32):
            raise BindingError('邮箱绑定尚未配置完成')
        url = self.base_url + '/' + action
        body = json.dumps(values, separators=(',', ':'), ensure_ascii=False).encode()
        for attempt in range(self.attempts):
            timestamp = str(int(time.time()))
            nonce = secrets.token_hex(16)
            canonical = '\n'.join([timestamp, nonce, 'POST', urllib.parse.urlsplit(url).path,
                                   hashlib.sha256(body).hexdigest()])
            signature = hmac.new(self.secret.encode(), canonical.encode(), hashlib.sha256).hexdigest()
            req = urllib.request.Request(url, data=body, method='POST', headers={
                'Content-Type': 'application/json', 'Accept': 'application/json',
                'User-Agent': 'NiniTelegramBinding/1.0', 'X-Tg-Timestamp': timestamp,
                'X-Tg-Nonce': nonce, 'X-Tg-Signature': signature,
            })
            try:
                with self.opener.open(req, timeout=self.timeout) as response:
                    raw = response.read(65537)
                    if len(raw) > 65536:
                        raise BindingError('面板返回内容异常，请稍后重试')
                    payload = json.loads(raw)
                data = payload.get('data') if isinstance(payload, dict) else None
                if not isinstance(data, dict):
                    raise BindingError('面板返回内容异常，请稍后重试')
                return data
            except urllib.error.HTTPError as error:
                if error.code in (502, 504) and attempt + 1 < self.attempts:
                    error.close()
                    time.sleep(0.3)
                    continue
                message = ''
                try:
                    payload = json.loads(error.read(65536))
                    if isinstance(payload, dict) and error.code in (409, 422, 429, 503):
                        message = str(payload.get('message') or '')[:200]
                except (ValueError, OSError):
                    pass
                finally:
                    error.close()
                raise BindingError(message or '面板验证暂时不可用，请稍后重试') from None
            except (urllib.error.URLError, TimeoutError, OSError):
                if attempt + 1 < self.attempts:
                    time.sleep(0.3)
                    continue
                raise BindingError('面板连接暂时不可用，请稍后重试') from None
            except (ValueError, UnicodeError):
                raise BindingError('面板返回内容异常，请稍后重试') from None

    def request_code(self, telegram_id, email):
        email = normalize_email(email)
        if not email:
            raise BindingError('请填写网站注册邮箱，不要提交订阅链接')
        previous = self.recent_email_request(telegram_id)
        if previous:
            remaining = 60 - (time.monotonic() - previous['requested_at'])
            if remaining > 0:
                raise BindingError(f'请等待{math.ceil(remaining)}秒后再重新发送验证码')
        data = self.call('email/request', {'telegram_id': telegram_id, 'email': email,
                                        'request_id': secrets.token_hex(16)})
        if data.get('status') != 'queued':
            raise BindingError('验证码发送状态异常，请稍后重试')
        self.email_requests[telegram_id] = {'email': email, 'requested_at': time.monotonic()}
        return data

    def resend_code(self, telegram_id):
        previous = self.recent_email_request(telegram_id)
        if not previous:
            raise BindingError('请在本私聊重新发送网站注册邮箱，获取新的验证码。')
        return self.request_code(telegram_id, previous['email'])

    def confirm_code(self, telegram_id, code):
        if not re.fullmatch(r'[0-9]{6}', code):
            raise BindingError('请输入6位邮箱验证码')
        if self.require_local_request and not self.recent_email_request(telegram_id):
            raise BindingError('当前机场没有待验证的申请。切换机场或机器人重启后，请重新发送注册邮箱获取验证码。')
        try:
            data = self.call('email/confirm', {'telegram_id': telegram_id, 'code': code,
                                            'request_id': secrets.token_hex(16)})
        except BindingError as error:
            if str(error) == '验证码已失效，请重新获取':
                raise BindingError('验证码已过期或失效，请点击“重新发送验证码”获取新验证码。每次发送至少间隔60秒，每小时最多5次。') from None
            raise
        if data.get('allowed') is not True or data.get('status') != 'bound':
            raise BindingError('邮箱绑定未完成，请重新核对验证码')
        self.email_requests.pop(telegram_id, None)
        self.unbind_confirmations.pop(telegram_id, None)
        self.cancel_subscription_reset(telegram_id)
        return data

    def prepare_subscription_reset(self, telegram_id):
        current = time.monotonic()
        self.reset_confirmations = {key: value for key, value in self.reset_confirmations.items()
                                    if value['expires_at'] > current}
        self.cancel_subscription_reset(telegram_id)
        data = self.call('account/subscription/reset/prepare', {'telegram_id': telegram_id})
        token, ttl = data.get('confirmation_token'), data.get('expires_in')
        if (data.get('status') != 'confirmation_required' or not isinstance(token, str)
                or not re.fullmatch(r'[a-f0-9]{32}', token) or type(ttl) is not int or not 0 < ttl <= 300
                or not isinstance(data.get('emailMasked'), str) or not data['emailMasked']):
            raise BindingError('重置确认暂不可用，请稍后重试')
        self.reset_confirmations[telegram_id] = {'token': token, 'expires_at': current + ttl,
                                                'ui_token': secrets.token_hex(16)}
        return {'emailMasked': data['emailMasked']}

    def cancel_subscription_reset(self, telegram_id):
        self.reset_confirmations.pop(telegram_id, None)

    def confirm_subscription_reset(self, telegram_id):
        pending = self.reset_confirmations.get(telegram_id)
        if not pending or pending['expires_at'] <= time.monotonic():
            self.cancel_subscription_reset(telegram_id)
            raise BindingError('重置确认已过期或未开始，请重新点击“重置订阅”。')
        data = self.call('account/subscription/reset/confirm', {'telegram_id': telegram_id,
                         'confirmation_token': pending['token']})
        if data.get('status') != 'reset':
            raise BindingError('重置未确认成功，请稍后重试')
        url = self.web_url(data.get('subscribe_url'))
        self.cancel_subscription_reset(telegram_id)
        return url

    def get_publication(self, telegram_id):
        data = self.call('account/publication', {'telegram_id': telegram_id})
        return self.web_url(data.get('publication_url'))

    def prepare_unbind(self, telegram_id):
        current = time.monotonic()
        self.unbind_confirmations = {key: value for key, value in self.unbind_confirmations.items()
                                     if value['expires_at'] > current}
        self.unbind_confirmations.pop(telegram_id, None)
        data = self.call('email/unbind/prepare', {'telegram_id': telegram_id})
        if data.get('status') == 'not_bound':
            return data
        token = data.get('confirmation_token')
        ttl = data.get('expires_in')
        if (data.get('status') != 'confirmation_required' or not isinstance(token, str)
                or not re.fullmatch(r'[a-f0-9]{32}', token) or type(ttl) is not int or not 0 < ttl <= 300
                or not isinstance(data.get('emailMasked'), str) or not data['emailMasked']):
            raise BindingError('解绑状态异常，请稍后重试')
        self.unbind_confirmations[telegram_id] = {'token': token, 'expires_at': current + ttl,
                                                 'request_id': secrets.token_hex(16), 'ui_token': secrets.token_hex(16)}
        return {'status': data['status'], 'emailMasked': data['emailMasked']}

    def confirmation_button_token(self, telegram_id, operation):
        pending = (self.reset_confirmations if operation == 'reset' else self.unbind_confirmations).get(telegram_id)
        if not pending or pending['expires_at'] <= time.monotonic():
            raise BindingError('确认已过期，请重新点击对应操作按钮。')
        return pending['ui_token']

    def matches_confirmation_button(self, telegram_id, operation, token):
        try:
            expected = self.confirmation_button_token(telegram_id, operation)
        except BindingError:
            return False
        return isinstance(token, str) and hmac.compare_digest(expected, token)

    def cancel_unbind(self, telegram_id):
        self.unbind_confirmations.pop(telegram_id, None)

    def confirm_unbind(self, telegram_id):
        pending = self.unbind_confirmations.get(telegram_id)
        if not pending or pending['expires_at'] <= time.monotonic():
            self.cancel_unbind(telegram_id)
            raise BindingError('解绑确认已过期或未开始，请重新点击“解绑邮箱”。')
        data = self.call('email/unbind/confirm', {'telegram_id': telegram_id,
                         'confirmation_token': pending['token'], 'request_id': pending['request_id']})
        if data.get('status') != 'unbound' or data.get('allowed') is not False:
            raise BindingError('解绑未确认成功，请稍后重试')
        self.cancel_unbind(telegram_id)
        self.email_requests.pop(telegram_id, None)
        self.cancel_subscription_reset(telegram_id)
        return data

    def verify(self, telegram_id, require_active=False):
        data = self.call('verify', {'telegram_id': telegram_id, 'require_active_plan': bool(require_active)})
        if not isinstance(data.get('allowed'), bool):
            raise BindingError('面板验证结果异常，请稍后重试')
        return data

    def get_subscription(self, telegram_id):
        data = self.call('account/subscription', {'telegram_id': telegram_id})
        return self.web_url(data.get('subscribe_url'))

    @staticmethod
    def web_url(url):
        if not isinstance(url, str) or len(url) > 2048 or any(ord(c) <= 32 or ord(c) == 127 for c in url):
            raise BindingError('订阅信息异常，请稍后重试或登录网站查看')
        try:
            parsed = urllib.parse.urlsplit(url)
            valid = parsed.scheme in {'https', 'http'} and parsed.hostname and not parsed.username and not parsed.password
        except ValueError:
            valid = False
        if not valid:
            raise BindingError('订阅信息异常，请稍后重试或登录网站查看')
        return url

    def get_plan(self, telegram_id):
        data = self.call('account/plan', {'telegram_id': telegram_id})
        valid = (isinstance(data.get('plan_name'), str) and len(data['plan_name']) <= 160
                 and data.get('status') in {'active', 'no_plan', 'plan_missing', 'expired', 'exhausted'})
        for key in ['total_bytes', 'used_bytes', 'remaining_bytes']:
            valid = valid and type(data.get(key)) is int and 0 <= data[key] <= 2**63 - 1
        expires = data.get('expires_at')
        valid = valid and 'expires_at' in data and (expires is None or type(expires) is int and 0 <= expires <= 253402271999)
        if not valid or data['remaining_bytes'] != max(0, data['total_bytes'] - data['used_bytes']):
            raise BindingError('套餐信息异常，请稍后重试或登录网站查看')
        return data
