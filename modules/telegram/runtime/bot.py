#!/usr/bin/env python3
from __future__ import annotations

import argparse
import base64
import copy
import hashlib
import hmac
import html
import json
import os
import re
import socket
import sqlite3
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from contextlib import contextmanager
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone
from http import HTTPStatus
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import Any
from panel_binding import BindingError, PanelBindingClient, normalize_email
from panel_sites import PanelSites, SitePrivateBot
from telegram_transport import TelegramHTTPSHandler
from message_cleanup import MessageCleanup
from member_departures import DepartureUnbinder
from anti_spam import AdvertisingGuard


BASE_DIR = Path(__file__).resolve().parent
STATE_FILE = BASE_DIR / "state.json"
APP_DB_FILE = BASE_DIR / "bot_admin.sqlite3"
JOIN_VERIFICATION_SECONDS = 300
USER_MESSAGE_FIELDS = {
    "text", "animation", "audio", "document", "photo", "sticker", "story",
    "video", "video_note", "voice", "contact", "dice", "game", "poll",
    "venue", "location", "paid_media", "live_photo", "rich_message",
}


def now_ts() -> int:
    return int(time.time())


def load_env(path: Path) -> None:
    if not path.exists():
        return
    for raw_line in path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        key = key.strip()
        value = value.strip().strip('"').strip("'")
        os.environ.setdefault(key, value)


def env_bool(name: str, default: bool = False) -> bool:
    value = os.getenv(name)
    if value is None:
        return default
    return value.strip().lower() in {"1", "true", "yes", "on"}


def env_int(name: str, default: int) -> int:
    value = os.getenv(name)
    if not value:
        return default
    try:
        return int(value)
    except ValueError:
        return default


def log_timing(event: str, **values: Any) -> None:
    if env_bool('TELEGRAM_TIMING_LOGS', False):
        print('timing ' + json.dumps({'event': event, **values}, separators=(',', ':')), flush=True)


def parse_chat_ids(value: str | None) -> set[int]:
    ids: set[int] = set()
    if not value:
        return ids
    for item in value.split(","):
        item = item.strip()
        if not item:
            continue
        try:
            ids.add(int(item))
        except ValueError:
            print(f"忽略无效 chat_id: {item}", file=sys.stderr)
    return ids


def parse_bool_field(form: dict[str, list[str]], name: str) -> int:
    return 1 if form.get(name, ["0"])[0] in {"1", "on", "true"} else 0


def short_user_name(user: dict[str, Any]) -> str:
    parts = [str(user.get("first_name") or "").strip(), str(user.get("last_name") or "").strip()]
    name = " ".join(part for part in parts if part).strip()
    username = str(user.get("username") or "").strip()
    if username:
        return f"{name} (@{username})" if name else f"@{username}"
    return name or str(user.get("id") or "")


@dataclass
class VerificationResult:
    allowed: bool
    reason: str
    source: str = ""
    email: str = ""
    unavailable: bool = False


class TelegramBot:
    def __init__(self, token: str) -> None:
        self.token = token
        self.api_base = f"https://api.telegram.org/bot{token}"
        self.opener = urllib.request.build_opener(TelegramHTTPSHandler())
        self.message_cleanup = None
        self.ad_guard = None

    def request(self, method: str, payload: dict[str, Any] | None = None) -> dict[str, Any]:
        started = time.monotonic()
        succeeded = False
        try:
            result = self._request(method, payload)
            succeeded = True
            return result
        finally:
            if method != 'getUpdates':
                log_timing('telegram_api', method=method, ok=succeeded,
                           elapsed_ms=round((time.monotonic() - started) * 1000))

    def _request(self, method: str, payload: dict[str, Any] | None = None) -> dict[str, Any]:
        data = None
        headers = {"Content-Type": "application/json"}
        if payload is not None:
            data = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        req = urllib.request.Request(f"{self.api_base}/{method}", data=data, headers=headers)
        timeout = 10
        if method == 'getUpdates':
            timeout = max(timeout, min(int((payload or {}).get('timeout', 0)), 30) + 5)
        try:
            with self.opener.open(req, timeout=timeout) as response:
                body = response.read().decode("utf-8")
        except urllib.error.HTTPError as exc:
            body = exc.read().decode("utf-8", errors="replace")
            raise RuntimeError(f"Telegram API HTTP {exc.code}: {body}") from exc
        except urllib.error.URLError as exc:
            raise RuntimeError(f"Telegram API 连接失败: {exc.reason}") from exc

        result = json.loads(body)
        if not result.get("ok"):
            raise RuntimeError(f"Telegram API 返回错误: {body}")
        return result

    def get_me(self) -> dict[str, Any]:
        return self.request("getMe")["result"]

    def get_updates(self, offset: int | None, timeout: int) -> list[dict[str, Any]]:
        payload: dict[str, Any] = {
            "timeout": timeout,
            "allowed_updates": ["message", "chat_join_request", "chat_member", "callback_query"],
        }
        if offset is not None:
            payload["offset"] = offset
        return self.request("getUpdates", payload)["result"]

    def answer_callback_query(self, query_id, text='', *, show_alert=False):
        return self.request('answerCallbackQuery', {'callback_query_id': query_id,
                            'text': text, 'show_alert': show_alert, 'cache_time': 0})

    def send_message(self, chat_id: int, text: str, reply_to_message_id: int | None = None,
                     *, reply_markup: dict[str, Any] | None = None,
                     entities: list[dict[str, Any]] | None = None) -> dict[str, Any]:
        payload: dict[str, Any] = {
            "chat_id": chat_id,
            "text": text,
            "disable_web_page_preview": True,
        }
        if reply_to_message_id is not None:
            payload["reply_to_message_id"] = reply_to_message_id
            payload['allow_sending_without_reply'] = True
        if reply_markup is not None:
            payload['reply_markup'] = reply_markup
        if entities is not None:
            payload['entities'] = entities
        return self.request("sendMessage", payload)

    def delete_message(self, chat_id: int, message_id: int) -> None:
        self.request("deleteMessage", {"chat_id": chat_id, "message_id": message_id})

    def claim_group_prompt(self, chat_id: int, user_id: int) -> bool:
        if self.message_cleanup is None:
            raise RuntimeError('Group prompt cleanup is not running')
        return self.message_cleanup.claim_prompt(chat_id, user_id)

    def release_group_prompt(self, chat_id: int, user_id: int) -> None:
        self.message_cleanup.release_prompt(chat_id, user_id)

    def delete_message_later(self, chat_id: int, message_id: int) -> None:
        try:
            self.message_cleanup.schedule(chat_id, message_id, delay=10)
        except Exception:
            self.delete_message(chat_id, message_id)
            raise

    def get_chat_member(self, chat_id: int, user_id: int) -> dict[str, Any]:
        return self.request("getChatMember", {"chat_id": chat_id, "user_id": user_id})["result"]

    def kick_chat_member(self, chat_id: int, user_id: int) -> None:
        self.request("banChatMember", {"chat_id": chat_id, "user_id": user_id, "revoke_messages": False})
        self.request("unbanChatMember", {"chat_id": chat_id, "user_id": user_id, "only_if_banned": True})

    def ban_advertiser(self, chat_id: int, actor_id: int, *, channel: bool = False) -> None:
        if channel:
            result = self.request('banChatSenderChat', {'chat_id': chat_id, 'sender_chat_id': actor_id})
        else:
            result = self.request('banChatMember', {'chat_id': chat_id, 'user_id': actor_id,
                                                   'revoke_messages': True})
        if result.get('result') is not True:
            raise RuntimeError('Telegram did not confirm the advertising ban')

    def approve_chat_join_request(self, chat_id: int, user_id: int) -> None:
        result = self.request("approveChatJoinRequest", {"chat_id": chat_id, "user_id": user_id})
        if result.get('result') is not True:
            raise RuntimeError('Telegram 未确认入群审批成功')

    def decline_chat_join_request(self, chat_id: int, user_id: int) -> None:
        result = self.request("declineChatJoinRequest", {"chat_id": chat_id, "user_id": user_id})
        if result.get('result') is not True:
            raise RuntimeError('Telegram 未确认拒绝申请成功')

    def delete_webhook(self) -> None:
        self.request("deleteWebhook", {"drop_pending_updates": False})


class AppDatabase:
    DEFAULT_SETTINGS = {
        "verify_join_default": "0",
        "verify_message_default": "0",
        "unregistered_join_action": "decline",
        "unregistered_message_action": "warn",
        "panel_require_active_plan": "0",
        "welcome_registered": "1",
        "warn_unregistered_text": "本群需要验证网站注册身份。请私聊机器人发送 /绑定 加注册邮箱，再输入邮箱验证码。",
    }

    def __init__(self, path: Path) -> None:
        self.path = path
        self.lock = threading.RLock()
        self.join_locks = {}
        self.init_schema()

    def connect(self) -> sqlite3.Connection:
        conn = sqlite3.connect(self.path)
        conn.row_factory = sqlite3.Row
        return conn

    @contextmanager
    def connection(self) -> Any:
        conn = self.connect()
        try:
            yield conn
            conn.commit()
        finally:
            conn.close()

    def init_schema(self) -> None:
        with self.lock, self.connection() as conn:
            conn.executescript(
                """
                CREATE TABLE IF NOT EXISTS settings (
                    key TEXT PRIMARY KEY,
                    value TEXT NOT NULL
                );
                CREATE TABLE IF NOT EXISTS groups (
                    chat_id INTEGER PRIMARY KEY,
                    title TEXT NOT NULL DEFAULT '',
                    enabled INTEGER NOT NULL DEFAULT 1,
                    verify_join INTEGER NOT NULL DEFAULT 0,
                    verify_message INTEGER NOT NULL DEFAULT 0,
                    created_at INTEGER NOT NULL,
                    updated_at INTEGER NOT NULL
                );
                CREATE TABLE IF NOT EXISTS registered_users (
                    telegram_id INTEGER PRIMARY KEY,
                    username TEXT NOT NULL DEFAULT '',
                    display_name TEXT NOT NULL DEFAULT '',
                    email TEXT NOT NULL DEFAULT '',
                    status TEXT NOT NULL DEFAULT 'active',
                    source TEXT NOT NULL DEFAULT 'manual',
                    note TEXT NOT NULL DEFAULT '',
                    created_at INTEGER NOT NULL,
                    updated_at INTEGER NOT NULL
                );
                CREATE TABLE IF NOT EXISTS events (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    event_type TEXT NOT NULL,
                    chat_id INTEGER,
                    chat_title TEXT NOT NULL DEFAULT '',
                    telegram_id INTEGER,
                    username TEXT NOT NULL DEFAULT '',
                    display_name TEXT NOT NULL DEFAULT '',
                    action TEXT NOT NULL DEFAULT '',
                    reason TEXT NOT NULL DEFAULT '',
                    text TEXT NOT NULL DEFAULT '',
                    created_at INTEGER NOT NULL
                );
                CREATE TABLE IF NOT EXISTS pending_joins (
                    chat_id INTEGER NOT NULL,
                    telegram_id INTEGER NOT NULL,
                    user_chat_id INTEGER NOT NULL,
                    requested_at INTEGER NOT NULL,
                    expires_at INTEGER NOT NULL,
                    PRIMARY KEY (chat_id, telegram_id)
                );
                CREATE INDEX IF NOT EXISTS pending_joins_user ON pending_joins(telegram_id, expires_at);
                """
            )
            # Remove the retired settings atomically while preserving group policies.
            conn.execute("BEGIN IMMEDIATE")
            columns = {row["name"] for row in conn.execute("PRAGMA table_info(groups)")}
            if "keyword_reply" in columns:
                conn.execute("ALTER TABLE groups DROP COLUMN keyword_reply")
            conn.execute("DELETE FROM settings WHERE key = ?", ("keyword_reply_default",))
            for key, value in self.DEFAULT_SETTINGS.items():
                conn.execute("INSERT OR IGNORE INTO settings(key, value) VALUES(?, ?)", (key, value))

    def get_setting(self, key: str, default: str = "") -> str:
        with self.lock, self.connection() as conn:
            row = conn.execute("SELECT value FROM settings WHERE key = ?", (key,)).fetchone()
            return str(row["value"]) if row else default

    def settings(self) -> dict[str, str]:
        with self.lock, self.connection() as conn:
            rows = conn.execute("SELECT key, value FROM settings ORDER BY key").fetchall()
            data = dict(self.DEFAULT_SETTINGS)
            data.update({str(row["key"]): str(row["value"]) for row in rows})
            return data

    def save_settings(self, values: dict[str, str]) -> None:
        if set(values) - self.DEFAULT_SETTINGS.keys():
            raise ValueError("不支持的设置项")
        with self.lock, self.connection() as conn:
            for key, value in values.items():
                conn.execute(
                    "INSERT INTO settings(key, value) VALUES(?, ?) "
                    "ON CONFLICT(key) DO UPDATE SET value = excluded.value",
                    (key, value),
                )

    def ensure_group(self, chat_id: int, title: str) -> sqlite3.Row:
        current = now_ts()
        with self.lock, self.connection() as conn:
            row = conn.execute("SELECT * FROM groups WHERE chat_id = ?", (chat_id,)).fetchone()
            if row:
                if title and title != row["title"]:
                    conn.execute("UPDATE groups SET title = ?, updated_at = ? WHERE chat_id = ?", (title, current, chat_id))
                    row = conn.execute("SELECT * FROM groups WHERE chat_id = ?", (chat_id,)).fetchone()
                return row
            conn.execute(
                """
                INSERT INTO groups(chat_id, title, enabled, verify_join, verify_message, created_at, updated_at)
                VALUES(?, ?, 1, ?, ?, ?, ?)
                """,
                (
                    chat_id,
                    title,
                    int(self.get_setting("verify_join_default", "0")),
                    int(self.get_setting("verify_message_default", "0")),
                    current,
                    current,
                ),
            )
            return conn.execute("SELECT * FROM groups WHERE chat_id = ?", (chat_id,)).fetchone()

    def list_groups(self) -> list[sqlite3.Row]:
        with self.lock, self.connection() as conn:
            return conn.execute("SELECT * FROM groups ORDER BY updated_at DESC, chat_id DESC").fetchall()

    def save_group(self, values: dict[str, Any]) -> None:
        current = now_ts()
        with self.lock, self.connection() as conn:
            conn.execute(
                """
                INSERT INTO groups(chat_id, title, enabled, verify_join, verify_message, created_at, updated_at)
                VALUES(?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT(chat_id) DO UPDATE SET
                    title = excluded.title,
                    enabled = excluded.enabled,
                    verify_join = excluded.verify_join,
                    verify_message = excluded.verify_message,
                    updated_at = excluded.updated_at
                """,
                (
                    int(values["chat_id"]),
                    str(values.get("title", "")),
                    int(values.get("enabled", 0)),
                    int(values.get("verify_join", 0)),
                    int(values.get("verify_message", 0)),
                    current,
                    current,
                ),
            )

    def save_registered_user(self, values: dict[str, Any]) -> None:
        current = now_ts()
        with self.lock, self.connection() as conn:
            conn.execute(
                """
                INSERT INTO registered_users(telegram_id, username, display_name, email, status, source, note, created_at, updated_at)
                VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT(telegram_id) DO UPDATE SET
                    username = excluded.username,
                    display_name = excluded.display_name,
                    email = excluded.email,
                    status = excluded.status,
                    source = excluded.source,
                    note = excluded.note,
                    updated_at = excluded.updated_at
                """,
                (
                    int(values["telegram_id"]),
                    str(values.get("username", "")).lstrip("@"),
                    str(values.get("display_name", "")),
                    str(values.get("email", "")),
                    str(values.get("status", "active")),
                    str(values.get("source", "manual")),
                    str(values.get("note", "")),
                    current,
                    current,
                ),
            )

    def delete_registered_user(self, telegram_id: int) -> None:
        with self.lock, self.connection() as conn:
            conn.execute("DELETE FROM registered_users WHERE telegram_id = ?", (telegram_id,))

    def get_registered_user(self, telegram_id: int) -> sqlite3.Row | None:
        with self.lock, self.connection() as conn:
            return conn.execute("SELECT * FROM registered_users WHERE telegram_id = ?", (telegram_id,)).fetchone()

    def list_registered_users(self) -> list[sqlite3.Row]:
        with self.lock, self.connection() as conn:
            return conn.execute("SELECT * FROM registered_users ORDER BY updated_at DESC LIMIT 300").fetchall()

    def record_event(self, **values: Any) -> None:
        current = now_ts()
        with self.lock, self.connection() as conn:
            conn.execute(
                """
                INSERT INTO events(event_type, chat_id, chat_title, telegram_id, username, display_name, action, reason, text, created_at)
                VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                """,
                (
                    str(values.get("event_type", "")),
                    values.get("chat_id"),
                    str(values.get("chat_title", ""))[:255],
                    values.get("telegram_id"),
                    str(values.get("username", ""))[:255],
                    str(values.get("display_name", ""))[:255],
                    str(values.get("action", ""))[:255],
                    str(values.get("reason", ""))[:255],
                    str(values.get("text", ""))[:1000],
                    current,
                ),
            )

    def list_events(self, limit: int = 120) -> list[sqlite3.Row]:
        with self.lock, self.connection() as conn:
            return conn.execute("SELECT * FROM events ORDER BY id DESC LIMIT ?", (limit,)).fetchall()

    def clear_events(self) -> None:
        with self.lock, self.connection() as conn:
            conn.execute("DELETE FROM events")

    @contextmanager
    def join_guard(self, chat_id: int, telegram_id: int):
        key = (chat_id, telegram_id)
        with self.lock:
            entry = self.join_locks.setdefault(key, [threading.RLock(), 0])
            entry[1] += 1
        try:
            with entry[0]:
                yield
        finally:
            with self.lock:
                entry[1] -= 1
                if not entry[1]:
                    del self.join_locks[key]

    def remember_pending_join(self, chat_id: int, telegram_id: int, user_chat_id: int, requested_at: int) -> bool:
        with self.join_guard(chat_id, telegram_id), self.lock, self.connection() as conn:
            cursor = conn.execute(
                "INSERT INTO pending_joins(chat_id,telegram_id,user_chat_id,requested_at,expires_at) VALUES(?,?,?,?,?) "
                "ON CONFLICT(chat_id,telegram_id) DO UPDATE SET user_chat_id=excluded.user_chat_id, "
                "requested_at=excluded.requested_at,expires_at=excluded.expires_at "
                "WHERE excluded.requested_at > pending_joins.requested_at",
                (chat_id, telegram_id, user_chat_id, requested_at, requested_at + JOIN_VERIFICATION_SECONDS),
            )
            return cursor.rowcount == 1

    def pending_joins_for(self, telegram_id: int) -> list[sqlite3.Row]:
        with self.lock, self.connection() as conn:
            return conn.execute(
                "SELECT p.*,g.title,g.enabled,g.verify_join FROM pending_joins p "
                "JOIN groups g ON g.chat_id=p.chat_id WHERE p.telegram_id=? AND p.requested_at>? ORDER BY p.requested_at",
                (telegram_id, now_ts() - 86400),
            ).fetchall()

    def pending_join(self, chat_id: int, telegram_id: int):
        with self.lock, self.connection() as conn:
            return conn.execute(
                "SELECT p.*,g.title,g.enabled,g.verify_join FROM pending_joins p "
                "JOIN groups g ON g.chat_id=p.chat_id WHERE p.chat_id=? AND p.telegram_id=?",
                (chat_id, telegram_id),
            ).fetchone()

    def expired_pending_joins(self):
        with self.lock, self.connection() as conn:
            return conn.execute(
                "SELECT p.*,g.title,g.enabled,g.verify_join FROM pending_joins p "
                "JOIN groups g ON g.chat_id=p.chat_id WHERE p.expires_at<=? "
                "AND g.enabled=1 AND g.verify_join=1 ORDER BY p.expires_at",
                (now_ts(),),
            ).fetchall()

    def forget_pending_join(self, chat_id: int, telegram_id: int, requested_at: int) -> None:
        with self.join_guard(chat_id, telegram_id), self.lock, self.connection() as conn:
            conn.execute("DELETE FROM pending_joins WHERE chat_id=? AND telegram_id=? AND requested_at=?",
                         (chat_id, telegram_id, requested_at))


class PanelUserChecker:
    def __init__(self, db: AppDatabase) -> None:
        self.db = db
        self.email_client = PanelBindingClient()
        self.sites = PanelSites.from_env(BASE_DIR)
        self.site_id = None

    def for_site(self, site_id):
        if not self.sites or site_id not in self.sites.sites:
            raise BindingError('请选择有效的机场')
        scoped = copy.copy(self)
        scoped.site_id = site_id
        scoped.email_client = self.sites.clients[site_id]
        return scoped

    def verify_gateway_url(self) -> str:
        return os.getenv("PANEL_VERIFY_GATEWAY_URL", "").strip().rstrip("/")

    def verify_gateway_secret(self) -> str:
        return os.getenv("PANEL_VERIFY_GATEWAY_SECRET", "").strip()

    def query_verify_gateway(self, payload: dict[str, Any]) -> VerificationResult | None:
        url = self.verify_gateway_url()
        secret = self.verify_gateway_secret()
        if bool(url) != bool(secret):
            return VerificationResult(False, "注册验证网关配置不完整", "gateway", unavailable=True)
        if not url:
            return None
        body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        request = urllib.request.Request(
            url,
            data=body,
            headers={
                "Content-Type": "application/json",
                "Accept": "application/json",
                "User-Agent": "NiniSupportBot/1.0",
                "X-Bot-Secret": secret,
            },
            method="POST",
        )
        try:
            with urllib.request.urlopen(request, timeout=env_int("PANEL_VERIFY_GATEWAY_TIMEOUT", 6)) as response:
                raw = response.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as exc:
            raw = exc.read().decode("utf-8", "replace")
            try:
                data = json.loads(raw)
                message = str(data.get("message") or data.get("error") or "")
            except Exception:
                message = ""
            return VerificationResult(False, message or "注册验证网关拒绝了请求", "gateway", unavailable=True)
        except urllib.error.URLError:
            return VerificationResult(False, "注册验证网关暂时连不上", "gateway", unavailable=True)
        except TimeoutError:
            return VerificationResult(False, "注册验证网关查询超时", "gateway", unavailable=True)
        except OSError:
            return VerificationResult(False, "注册验证网关连接异常", "gateway", unavailable=True)

        try:
            data = json.loads(raw)
        except Exception:
            return VerificationResult(False, "注册验证网关返回格式异常", "gateway", unavailable=True)
        item = data.get("data") if isinstance(data, dict) else None
        if not isinstance(item, dict) or not isinstance(item.get("allowed"), bool):
            return VerificationResult(False, "注册验证网关返回内容异常", "gateway", unavailable=True)
        source = str(item.get("source") or item.get("panelId") or "gateway")
        reason = str(item.get("reason") or ("验证通过" if item.get("allowed") else "未找到注册账号"))
        email = str(item.get("emailMasked") or "")
        return VerificationResult(bool(item.get("allowed")), reason, f"gateway:{source}", email)

    def query_verify_gateway_by_telegram_id(self, telegram_id: int) -> VerificationResult | None:
        return self.query_verify_gateway({
            "mode": "telegram_id", "telegram_id": int(telegram_id),
            "require_active_plan": self.db.get_setting("panel_require_active_plan", "0") == "1",
        })


    def load_panel_configs(self) -> list[dict[str, Any]] | VerificationResult | None:
        config_path = os.getenv("PANEL_DB_CONFIGS_FILE", "").strip()
        if not config_path:
            return None
        path = Path(config_path)
        if not path.is_absolute():
            path = BASE_DIR / path
        if not path.exists():
            return None
        try:
            configs = json.loads(path.read_text(encoding="utf-8"))
        except Exception as exc:
            return VerificationResult(False, f"数据库配置读取失败: {exc}", "panel")
        if not isinstance(configs, list):
            return VerificationResult(False, "数据库配置格式错误", "panel")
        return [cfg for cfg in configs if isinstance(cfg, dict) and str(cfg.get("driver", "mysql")).lower() == "mysql"]

    def verify(self, telegram_id: int) -> VerificationResult:
        if self.sites and self.site_id is None:
            return VerificationResult(False, '请先选择对应机场', 'panel-email', unavailable=True)
        local = self.db.get_registered_user(telegram_id)
        if local:
            if local["status"] == "blocked":
                return VerificationResult(False, "后台已拉黑", "local", local["email"])
            if local["status"] == "active":
                return VerificationResult(True, "后台手动放行", "local", local["email"])

        if self.email_client.configured:
            try:
                data = self.email_client.verify(telegram_id, self.db.get_setting('panel_require_active_plan', '0') == '1')
                return VerificationResult(data['allowed'], str(data.get('reason') or ''), 'panel-email', str(data.get('emailMasked') or ''))
            except BindingError as error:
                return VerificationResult(False, str(error), 'panel-email', unavailable=True)

        gateway_result = self.query_verify_gateway_by_telegram_id(telegram_id)
        if gateway_result is not None:
            return gateway_result

        panel_result = self.query_panel_databases(telegram_id)
        if panel_result is not None:
            return panel_result

        return VerificationResult(False, "未找到已绑定的注册账号")

    def query_panel_databases(self, telegram_id: int) -> VerificationResult | None:
        configs = self.load_panel_configs()
        if configs is None:
            return None
        if isinstance(configs, VerificationResult):
            return configs

        require_active = self.db.get_setting("panel_require_active_plan", "0") == "1"
        for cfg in configs:
            result = self.query_mysql_config(cfg, telegram_id, require_active)
            if result is not None:
                return result
        return None

    def query_mysql_config(self, cfg: dict[str, Any], telegram_id: int, require_active: bool) -> VerificationResult | None:
        query = (
            "SELECT id,email,banned,IFNULL(plan_id,0),IFNULL(expired_at,0),IFNULL(transfer_enable,0) "
            f"FROM v2_user WHERE telegram_id = {int(telegram_id)} LIMIT 1"
        )
        cmd = [
            str(cfg.get("mysql_bin", "mysql")),
            "-h",
            str(cfg.get("host", "127.0.0.1")),
            "-P",
            str(cfg.get("port", 3306)),
            "-u",
            str(cfg.get("username", "")),
            "--batch",
            "--raw",
            "--skip-column-names",
            str(cfg.get("database", "")),
            "-e",
            query,
        ]
        env = os.environ.copy()
        env["MYSQL_PWD"] = str(cfg.get("password", ""))
        try:
            proc = subprocess.run(
                cmd,
                env=env,
                capture_output=True,
                text=True,
                timeout=env_int("PANEL_QUERY_TIMEOUT", 6),
                check=False,
            )
        except FileNotFoundError:
            return VerificationResult(False, "服务器缺少 mysql 客户端，暂时无法查面板数据库", "panel")
        except subprocess.TimeoutExpired:
            return VerificationResult(False, "面板数据库查询超时", "panel")
        if proc.returncode != 0:
            return VerificationResult(False, "面板数据库查询失败", "panel")
        line = proc.stdout.strip().splitlines()[0] if proc.stdout.strip() else ""
        if not line:
            return None
        parts = line.split("\t")
        if len(parts) < 6:
            return VerificationResult(False, "面板数据库返回格式异常", "panel")
        _, email, banned, plan_id, expired_at, transfer_enable = parts[:6]
        if str(banned) == "1":
            return VerificationResult(False, "账号已封禁", "panel", email)
        if require_active:
            expire_value = int(expired_at or "0")
            has_plan = int(plan_id or "0") > 0
            has_traffic = int(transfer_enable or "0") > 0
            not_expired = expire_value == 0 or expire_value > now_ts()
            if not (has_plan and has_traffic and not_expired):
                return VerificationResult(False, "账号没有可用套餐", "panel", email)
        return VerificationResult(True, "面板注册用户", "panel", email)






def load_state() -> dict[str, Any]:
    if not STATE_FILE.exists():
        return {}
    try:
        return json.loads(STATE_FILE.read_text(encoding="utf-8"))
    except json.JSONDecodeError:
        return {}


def save_state(state: dict[str, Any]) -> None:
    STATE_FILE.write_text(json.dumps(state, ensure_ascii=False, indent=2), encoding="utf-8")


def should_reply(chat_id: int, allowed_chat_ids: set[int]) -> bool:
    return not allowed_chat_ids or chat_id in allowed_chat_ids


def chat_title(chat: dict[str, Any]) -> str:
    return str(chat.get("title") or chat.get("username") or chat.get("id") or "")


def user_is_admin(bot: TelegramBot, chat_id: int, user_id: int) -> bool:
    try:
        member = bot.get_chat_member(chat_id, user_id)
    except Exception:
        return False
    return str(member.get("status", "")) in {"creator", "administrator"}


def registration_prompt(db: AppDatabase | None = None) -> str:
    if db is not None:
        configured = db.get_setting("warn_unregistered_text", "").strip()
        if configured:
            return configured
    return (
        "亲亲，这个群需要先完成账号验证才能发言。\n"
        "请私聊本机器人发送 /绑定 加网站注册邮箱，再输入邮箱验证码。不要在群里发送邮箱或验证码。"
    )


def group_binding_hint(checker, chat_id, db):
    sites = site_registry(checker) if checker is not None else None
    if sites:
        try:
            key = sites.for_group(chat_id)
            url = sites.entry_url(key, os.getenv('TELEGRAM_BOT_USERNAME', ''))
            return (f"请先绑定{sites.sites[key]['label']}的网站账号后再发言。\n"
                    "点击下方按钮，在私聊中完成邮箱验证。不要在群里发送邮箱或验证码。",
                    {'inline_keyboard': [[{'text': '绑定邮箱', 'url': url}]]})
        except BindingError:
            return '本群验证入口暂不可用，请联系管理员。不要在群里发送邮箱或验证码。', None
    return registration_prompt(db), None


def send_group_prompt(bot, chat_id, user_id, text, *, reply_markup=None):
    if not bot.claim_group_prompt(chat_id, user_id):
        return
    try:
        sent = bot.send_message(chat_id, text, reply_markup=reply_markup)
        bot.delete_message_later(chat_id, sent['result']['message_id'])
    except Exception:
        bot.release_group_prompt(chat_id, user_id)
        raise




def bind_help_text() -> str:
    return (
        "亲亲，请直接在本私聊发送你的网站注册邮箱。\n\n"
        "收到邮件后，直接发送收到的6位验证码即可，无需输入命令。\n"
        "收件箱没找到验证码邮件，请检查垃圾邮件文件夹。\n"
        "验证码过期后可点击“重新发送验证码”，每次发送至少间隔60秒，每小时最多5次。\n"
        "已绑定的用户可点击“解绑邮箱”，确认后解除绑定。\n"
        "无需提交订阅链接，不要在群里发送邮箱或验证码。"
    )


def bound_help_text() -> str:
    return (
        '请点击“获取订阅”获取订阅链接，或点击“查看套餐”查看流量和有效期。\n'
        '需要更换订阅链接，请点击“重置订阅”并确认；查找网站入口，请点击“发布页”。\n'
        '需要入群，请从网站的加群入口提交申请；完成邮箱绑定后，请返回群组查看。\n'
        '更换绑定邮箱，请点击“解绑邮箱”并确认，再发送新的注册邮箱。'
    )


def site_help_text(sites, user_id, key) -> str:
    keys, _ = sites.bound_sites(user_id)
    return bound_help_text() if key in keys else bind_help_text()


def binding_resend_keyboard() -> dict[str, Any]:
    return {**binding_account_keyboard(), 'input_field_placeholder': '输入注册邮箱或6位验证码'}


def binding_account_keyboard() -> dict[str, Any]:
    return {
        'keyboard': [[{'text': '发布页'}, {'text': '获取订阅'}, {'text': '查看套餐'}, {'text': '重置订阅'}],
                     [{'text': '绑定邮箱'}, {'text': '重新发送验证码'}, {'text': '解绑邮箱'}]],
        'resize_keyboard': True,
        'is_persistent': False,
        'one_time_keyboard': False,
    }


def account_plan_text(data: dict[str, Any]) -> str:
    names = {'active': '可用', 'no_plan': '未设置套餐', 'plan_missing': '套餐信息暂不可用',
             'expired': '已到期', 'exhausted': '流量已用完'}
    expiry = data['expires_at']
    if data['status'] == 'no_plan':
        expiry_text = '未设置'
    elif expiry is None:
        expiry_text = '不限时'
    elif expiry == 0:
        expiry_text = '已到期'
    else:
        expiry_text = datetime.fromtimestamp(expiry, timezone.utc).astimezone(
            timezone(timedelta(hours=8))).strftime('%Y-%m-%d %H:%M') + '（北京时间）'
    lines = ['当前套餐：' + (data['plan_name'] or names[data['status']]),
             '状态：' + names[data['status']], '到期时间：' + expiry_text]
    for label, key in [('总流量', 'total_bytes'), ('已用流量', 'used_bytes'), ('剩余流量', 'remaining_bytes')]:
        lines.append(f'{label}：{data[key] / (1024 ** 3):.2f} GB')
    if data['status'] == 'plan_missing':
        lines.append('请登录网站核对套餐信息。')
    return '\n'.join(lines)


def publication_display_url(url: str) -> str:
    try:
        parsed = urllib.parse.urlsplit(url)
        host = parsed.hostname.encode('ascii').decode('idna')
        netloc = f'[{host}]' if ':' in host else host
        if parsed.port:
            netloc += ':' + str(parsed.port)
        path = '' if parsed.path == '/' else parsed.path
        return urllib.parse.urlunsplit(parsed._replace(netloc=netloc, path=path))
    except (ValueError, UnicodeError, AttributeError):
        raise BindingError('发布页地址异常，请联系管理员') from None


def handle_account_read(bot: TelegramBot, db: AppDatabase, checker: PanelUserChecker,
                        chat_id: int, message_id: int, user_id: int, text: str) -> None:
    if not user_id:
        return
    entities = None
    try:
        local = db.get_registered_user(user_id)
        if local and local['status'] == 'blocked':
            raise BindingError('当前Telegram账号不可查询，请联系管理员')
        if text == '获取订阅':
            url = checker.email_client.get_subscription(user_id)
            message = '你的订阅链接（仅供本人使用，请勿转发）：\n' + url
        elif text == '发布页':
            url = checker.email_client.get_publication(user_id)
            display = publication_display_url(url)
            prefix = '请打开本站发布页，选择可用的网站入口：\n'
            message = prefix + display
            entities = [{'type': 'text_link', 'offset': len(prefix.encode('utf-16-le')) // 2,
                         'length': len(display.encode('utf-16-le')) // 2, 'url': url}]
        else:
            message = account_plan_text(checker.email_client.get_plan(user_id))
    except BindingError as error:
        message = str(error)
    # Subscription credentials and account details must not be recorded in bot events.
    if entities:
        bot.send_message(chat_id, message, message_id, reply_markup=binding_account_keyboard(), entities=entities)
    else:
        bot.send_message(chat_id, message, message_id, reply_markup=binding_account_keyboard())


def account_confirmation_keyboard(client, user_id, operation):
    token = client.confirmation_button_token(user_id, operation)
    confirm, cancel = ('确认重置订阅', '取消重置') if operation == 'reset' else ('确认解绑', '取消解绑')
    return {'inline_keyboard': [[{'text': confirm, 'callback_data': f'ac:{operation}:yes:{token}'},
                                 {'text': cancel, 'callback_data': f'ac:{operation}:no:{token}'}]]}


def handle_account_callback(bot, db, checker, query):
    query_id, payload = query.get('id'), query.get('data')
    message, sender = query.get('message') or {}, query.get('from') or {}
    chat = message.get('chat') or {}
    user_id, chat_id, message_id = sender.get('id'), chat.get('id'), message.get('message_id')
    match = re.fullmatch(r'ac:(reset|unbind):(yes|no):([a-f0-9]{32})', payload) if isinstance(payload, str) else None
    if (not isinstance(query_id, str) or not query_id or not match or type(user_id) is not int or user_id <= 0
            or chat.get('type') != 'private' or chat_id != user_id or type(message_id) is not int or message_id <= 0):
        if isinstance(query_id, str) and query_id:
            bot.answer_callback_query(query_id, '请在机器人私聊中重新点击操作按钮。', show_alert=True)
        return
    local = db.get_registered_user(user_id)
    if local and local['status'] == 'blocked':
        bot.answer_callback_query(query_id, '当前Telegram账号不可操作，请联系管理员。', show_alert=True)
        return
    sites = site_registry(checker)
    current = sites.current(user_id) if sites else None
    if sites and current is None:
        bot.answer_callback_query(query_id, '请先选择机场，再重新点击操作按钮。', show_alert=True)
        return
    if sites:
        checker = checker.for_site(current)
    operation, decision, token = match.groups()
    if not checker.email_client.matches_confirmation_button(user_id, operation, token):
        bot.answer_callback_query(query_id, '此确认已失效，请在当前机场重新点击操作按钮。', show_alert=True)
        return
    # Acknowledge before the panel request; failure must not perform a write.
    bot.answer_callback_query(query_id)
    if sites:
        bot = SitePrivateBot(bot, sites.sites[current]['label'], sites, user_id)
    if operation == 'reset':
        handle_subscription_reset(bot, db, checker, chat_id, message_id, user_id,
                                  '确认重置订阅' if decision == 'yes' else '取消重置', confirmed=True)
    else:
        handle_unbind_message(bot, db, checker, chat_id, message_id, user_id,
                              '确认解绑' if decision == 'yes' else '取消解绑', confirmed=True)


def handle_subscription_reset(bot, db, checker, chat_id, message_id, user_id, text, *, confirmed=False):
    if not user_id:
        return
    keyboard = binding_account_keyboard()
    try:
        local = db.get_registered_user(user_id)
        if local and local['status'] == 'blocked':
            raise BindingError('当前Telegram账号不可操作，请联系管理员')
        if text == '取消重置':
            checker.email_client.cancel_subscription_reset(user_id)
            message = '已退出重置确认。需要查看当前订阅，请点击“获取订阅”。'
        elif text == '重置订阅':
            data = checker.email_client.prepare_subscription_reset(user_id)
            message = (f"绑定邮箱：{data['emailMasked']}\n"
                       '确认重置本站订阅吗？旧订阅链接和节点连接凭据将失效，所有设备需要重新导入新订阅。\n'
                       '账号密码、套餐、有效期和流量不变。\n'
                       '请在5分钟内点击“确认重置订阅”；不需要更换请点击“取消重置”。')
            keyboard = account_confirmation_keyboard(checker.email_client, user_id, 'reset')
        else:
            if not confirmed:
                raise BindingError('重置确认已过期或未开始，请点击本次确认消息下方的按钮；旧按钮请重新点击“重置订阅”。')
            url = checker.email_client.confirm_subscription_reset(user_id)
            message = '请在所有设备重新导入以下新订阅，旧订阅已失效。链接仅供本人使用，请勿转发：\n' + url
    except BindingError as error:
        message = str(error)
    # Neither confirmation secrets nor subscription credentials belong in event logs.
    bot.send_message(chat_id, message, message_id, reply_markup=keyboard)


def handle_unbind_message(bot: TelegramBot, db: AppDatabase, checker: PanelUserChecker,
                          chat_id: int, message_id: int, user_id: int, text: str, *, confirmed=False) -> None:
    if not user_id:
        return
    action = 'unbind_cancelled'
    try:
        if text == '取消解绑':
            checker.email_client.cancel_unbind(user_id)
            message = bound_help_text()
            keyboard = binding_account_keyboard()
        elif text == '解绑邮箱':
            result = checker.email_client.prepare_unbind(user_id)
            if result['status'] == 'not_bound':
                message = '请直接发送网站注册邮箱，再发送邮件中的6位验证码完成绑定。'
                keyboard = binding_resend_keyboard()
                action = 'unbind_not_bound'
            else:
                message = (f"当前绑定邮箱：{result['emailMasked']}\n"
                           '确认解除当前 Telegram 与此网站邮箱的绑定吗？\n'
                           '网站账号、套餐和流量不会删除或改变，也不会自动将你踢出群。'
                           '解绑后，群聊仍按现有规则校验身份，可能需要重新绑定才能发言或入群。\n'
                           '请在5分钟内确认，误触请点“取消解绑”。')
                keyboard = account_confirmation_keyboard(checker.email_client, user_id, 'unbind')
                action = 'unbind_confirmation_requested'
        else:
            if not confirmed:
                raise BindingError('解绑确认已过期或未开始，请点击本次确认消息下方的按钮；旧按钮请重新点击“解绑邮箱”。')
            checker.email_client.confirm_unbind(user_id)
            sites = site_registry(checker)
            if sites:
                sites.discoveries.pop(user_id, None)
            message = ('需要重新绑定，请在这里发送网站注册邮箱，再发送邮件中的6位验证码。\n'
                       '网站账号、套餐和流量不会因解绑而改变。')
            keyboard = binding_resend_keyboard()
            action = 'unbind_success'
    except BindingError as error:
        message = str(error)
        keyboard = binding_account_keyboard()
        action = 'unbind_failed'
    bot.send_message(chat_id, message, message_id, reply_markup=keyboard)
    db.record_event(event_type='unbind', chat_id=chat_id, telegram_id=user_id, action=action, text='')


def join_onboarding_enabled(chat_id: int) -> bool:
    return chat_id in parse_chat_ids(os.getenv("TELEGRAM_JOIN_ONBOARDING_CHAT_IDS"))


def site_registry(checker):
    sites = getattr(checker, 'sites', None)
    return sites if isinstance(sites, PanelSites) else None


def verify_group_member(checker, user_id, chat_id):
    sites = site_registry(checker)
    if sites is None:
        return checker.verify(user_id)
    try:
        return checker.for_site(sites.for_group(chat_id)).verify(user_id)
    except BindingError as error:
        return VerificationResult(False, str(error), 'panel-email', unavailable=True)


def hold_join_for_binding(bot: TelegramBot, db: AppDatabase, join_request: dict[str, Any], checker=None) -> str:
    chat_id = int(join_request['chat']['id'])
    user_id = int(join_request['from']['id'])
    private_id = join_request.get('user_chat_id')
    requested_at = join_request.get('date')
    if (type(private_id) is not int or not 0 < private_id < 2**52 or type(requested_at) is not int
            or not 0 < requested_at <= now_ts() + 60 or requested_at + 86400 <= now_ts()):
        return 'guidance_metadata_missing'
    if not db.remember_pending_join(chat_id, user_id, private_id, requested_at):
        return 'waiting_for_binding'
    # Telegram grants a short private-message window only while the request remains pending.
    if now_ts() >= requested_at + 300:
        return 'guidance_window_expired'
    title = chat_title(join_request['chat'])[:100]
    sites = site_registry(checker)
    if sites:
        key = sites.for_group(chat_id)
        # A second group request must not silently switch an ongoing private operation.
        if sites.current(user_id) is None and not sites.awaiting_choice(user_id):
            sites.select(user_id, key)
        label = sites.sites[key]['label']
        bot.send_message(private_id,
            f'加入「{title}」，请点击下方“验证网站账号”，在私聊发送{label}的注册邮箱。\n'
            '收到邮件后直接发送6位验证码；收件箱没有请检查垃圾邮件。\n'
            '完成过该站绑定的用户，无需重复绑定，请返回群组查看；申请已过期时，请从网站重新申请入群。\n'
            '请在5分钟内完成验证；超时仅拒绝本次申请，不会封禁，可重新申请。\n'
            '只验证网站注册身份，无需购买套餐。请勿发送邮箱密码或订阅链接。',
            reply_markup={'inline_keyboard': [[{'text': '验证网站账号',
                'url': sites.entry_url(key, os.getenv('TELEGRAM_BOT_USERNAME', ''))}]]})
        return 'guidance_sent'
    bot.send_message(private_id,
        f"加入「{title}」，请直接在本私聊发送网站注册邮箱，收到邮件后直接发送6位验证码，无需输入命令。\n"
        "收件箱没找到验证码邮件，请检查垃圾邮件文件夹。"
        "验证成功后，我会自动批准本次入群申请，无需购买套餐。\n\n"
        "下方可直接点击“绑定邮箱”查看步骤；完成过绑定的用户无需重复绑定，请返回群组查看；申请已过期时，请从网站重新申请入群。\n"
        "不要发送邮箱密码或订阅链接。请在5分钟内完成验证；超时仅拒绝本次申请，不会封禁，可重新申请。",
        reply_markup=binding_resend_keyboard())
    return 'guidance_sent'


def approve_pending_joins(bot: TelegramBot, db: AppDatabase, checker: PanelUserChecker, user_id: int) -> tuple[list[str], bool]:
    allowed_ids = parse_chat_ids(os.getenv('TELEGRAM_ALLOWED_CHAT_IDS'))
    pending = [row for row in db.pending_joins_for(user_id) if row['enabled'] and row['verify_join']
               and join_onboarding_enabled(row['chat_id']) and should_reply(row['chat_id'], allowed_ids)]
    sites = site_registry(checker)
    selected = getattr(checker, 'site_id', None)
    if sites and selected:
        pending = [row for row in pending if sites.groups.get(row['chat_id']) == selected]
    if not pending:
        return [], False
    local = db.get_registered_user(user_id)
    if local and local['status'] == 'blocked':
        return [], True
    approved = []
    for row in pending:
        result = verify_group_member(checker, user_id, row['chat_id'])
        if result.unavailable or not result.allowed:
            continue
        action = 'approve_after_binding'
        reason = result.reason
        try:
            with db.join_guard(row['chat_id'], user_id):
                current = db.pending_join(row['chat_id'], user_id)
                if not current or current['requested_at'] != row['requested_at']:
                    continue
                local = db.get_registered_user(user_id)
                if not current['enabled'] or not current['verify_join'] or (local and local['status'] == 'blocked'):
                    continue
                try:
                    bot.approve_chat_join_request(row['chat_id'], user_id)
                except Exception:
                    member = bot.get_chat_member(row['chat_id'], user_id)
                    if member.get('status') not in {'member', 'administrator', 'creator'}:
                        raise
                db.forget_pending_join(row['chat_id'], user_id, row['requested_at'])
                approved.append(row['title'])
        except Exception as exc:
            action = 'approve_after_binding_failed'
            reason = str(exc)
        db.record_event(event_type='join_request', chat_id=row['chat_id'], chat_title=row['title'],
                        telegram_id=user_id, action=action, reason=reason, text='')
    return approved, len(approved) != len(pending)


class JoinRequestCleanup:
    """Resolve expired applications without blocking private-message polling."""

    def __init__(self, bot, db, checker):
        self.bot, self.db, self.checker = bot, db, checker
        self.stopped = threading.Event()
        self.thread = None
        self.retry_after = {}

    def eligible(self, row):
        return (row is not None and row['enabled'] and row['verify_join']
                and row['expires_at'] <= now_ts() and join_onboarding_enabled(row['chat_id'])
                and should_reply(row['chat_id'], parse_chat_ids(os.getenv('TELEGRAM_ALLOWED_CHAT_IDS'))))

    def resolve(self, row):
        chat_id, user_id = row['chat_id'], row['telegram_id']
        member = self.bot.get_chat_member(chat_id, user_id)
        joined = member.get('status') in {'member', 'administrator', 'creator'} or (
            member.get('status') == 'restricted' and member.get('is_member') is True)
        result = None if joined else verify_group_member(self.checker, user_id, chat_id)
        if result and result.unavailable:
            raise RuntimeError('Verification unavailable; application retained')
        with self.db.join_guard(chat_id, user_id):
            current = self.db.pending_join(chat_id, user_id)
            if not self.eligible(current) or current['requested_at'] != row['requested_at']:
                return
            local = self.db.get_registered_user(user_id)
            blocked = bool(local and local['status'] == 'blocked')
            action = 'already_joined'
            if not joined:
                action = 'approve_after_binding' if result.allowed and not blocked else 'decline_timeout'
                try:
                    if action == 'approve_after_binding':
                        self.bot.approve_chat_join_request(chat_id, user_id)
                    else:
                        self.bot.decline_chat_join_request(chat_id, user_id)
                except Exception as error:
                    if 'HIDE_REQUESTER_MISSING' not in str(error):
                        raise
                    action = 'request_already_handled'
            self.db.forget_pending_join(chat_id, user_id, row['requested_at'])
            self.db.record_event(event_type='join_request', chat_id=chat_id, chat_title=row['title'],
                                 telegram_id=user_id, action=action,
                                 reason='入群申请超时复核；不封禁、不修改邮箱绑定', text='')
            log_timing('join_cleanup', chat_id=chat_id, action=action)

    def run_due(self):
        rows = self.db.expired_pending_joins()
        keys = {(r['chat_id'], r['telegram_id'], r['requested_at']) for r in rows}
        self.retry_after = {key: value for key, value in self.retry_after.items() if key in keys}
        processed = 0
        for row in rows:
            if self.stopped.is_set():
                break
            key = (row['chat_id'], row['telegram_id'], row['requested_at'])
            if not self.eligible(row) or self.retry_after.get(key, 0) > time.monotonic():
                continue
            try:
                self.resolve(row)
                self.retry_after.pop(key, None)
            except Exception as error:
                self.retry_after[key] = time.monotonic() + 30
                print('Join request cleanup will retry: ' + type(error).__name__, flush=True)
            processed += 1
            if processed >= 20:
                break

    def _run(self):
        while not self.stopped.is_set():
            try:
                self.run_due()
            except Exception as error:
                print('Join request cleanup error: ' + type(error).__name__, flush=True)
            self.stopped.wait(5)

    def start(self):
        if self.thread is None:
            self.thread = threading.Thread(target=self._run, name='join-request-cleanup', daemon=True)
            self.thread.start()

    def stop(self):
        self.stopped.set()
        if self.thread:
            self.thread.join(timeout=25)


def join_approval_text(approved: list[str], pending: bool) -> str:
    message = ''
    if approved:
        message += '\n请返回「' + '、'.join(approved) + '」开始群聊。'
    if pending:
        message += '\n请稍后返回群组查看；如申请已取消或过期，请从网站重新申请入群。'
    return message


def handle_bind_command(
    bot: TelegramBot,
    db: AppDatabase,
    checker: PanelUserChecker,
    chat_id: int,
    chat_type: str,
    chat_name: str,
    message_id: int,
    user: dict[str, Any],
    text: str,
) -> None:
    user_id = int(user.get("id") or 0)
    username = str(user.get("username") or "")
    display_name = short_user_name(user)
    if not user_id:
        return

    if chat_type != "private":
        try:
            bot.delete_message(chat_id, message_id)
        except Exception:
            pass
        prompt, markup = group_binding_hint(checker, chat_id, db)
        send_group_prompt(bot, chat_id, user_id, prompt, reply_markup=markup)
        db.record_event(
            event_type="bind",
            chat_id=chat_id,
            chat_title=chat_name,
            telegram_id=user_id,
            username=username,
            display_name=display_name,
            action="group_bind_blocked",
            reason="群内绑定已拦截",
            text="",
        )
        return

    parts = text.split(maxsplit=1)
    command = parts[0].split('@')[0].lower() if parts else ''
    value = parts[1].strip() if len(parts) > 1 else ''
    confirming = command in {'/验证码', '/code'}
    resending = command == '/resend'
    if confirming and not value:
        bot.send_message(chat_id, bind_help_text(), message_id, reply_markup=binding_resend_keyboard())
        return
    local = db.get_registered_user(user_id)
    reason = ''
    try:
        if local and local['status'] == 'blocked':
            raise BindingError('当前Telegram账号不可绑定，请联系管理员')
        if not confirming:
            # Query the panel directly: a manual allow or cached approval is not an email binding.
            binding = checker.email_client.verify(user_id, require_active=False)
            if binding.get('allowed') is True:
                masked_email = binding.get('emailMasked')
                message = bound_help_text()
                if isinstance(masked_email, str) and masked_email:
                    message += '\n如需更换 ' + masked_email + '，请先完成解绑，无需重复获取验证码。'
                bot.send_message(chat_id, message, message_id, reply_markup=binding_account_keyboard())
                return
            if not value and not resending:
                bot.send_message(chat_id, bind_help_text(), message_id, reply_markup=binding_resend_keyboard())
                return
        if confirming:
            checker.email_client.confirm_code(user_id, value)
            sites = site_registry(checker)
            if sites:
                sites.discoveries.pop(user_id, None)
            bot.send_message(chat_id, bound_help_text(), message_id,
                             reply_markup=binding_account_keyboard())
            approved, pending = approve_pending_joins(bot, db, checker, user_id)
            if approved or pending:
                bot.send_message(chat_id, join_approval_text(approved, pending).strip(), message_id,
                                 reply_markup=binding_account_keyboard())
            action = 'bind_success'
        else:
            if resending:
                checker.email_client.resend_code(user_id)
            else:
                checker.email_client.request_code(user_id, value)
            bot.send_message(chat_id,
                "请打开注册邮箱查看验证码邮件。\n"
                "直接在本私聊发送收到的6位验证码即可，无需输入命令。\n"
                "收件箱没找到验证码邮件，请检查垃圾邮件文件夹。\n"
                "验证码自申请起10分钟有效。重新发送后请使用最新邮件中的验证码。\n"
                "每次发送至少间隔60秒，每小时最多5次。", message_id,
                reply_markup=binding_resend_keyboard())
            action = 'email_code_requested'
    except BindingError as error:
        reason = str(error)
        bot.send_message(chat_id, reason, message_id, reply_markup=binding_resend_keyboard())
        action = 'bind_failed'

    db.record_event(
        event_type="bind",
        chat_id=chat_id,
        chat_title=chat_name,
        telegram_id=user_id,
        username=username,
        display_name=display_name,
        action=action,
        reason=reason,
        text="",
    )


def handle_unregistered_message(
    bot: TelegramBot,
    db: AppDatabase,
    chat_id: int,
    chat_name: str,
    message_id: int,
    user: dict[str, Any],
    text: str,
    reason: str,
    unavailable: bool = False,
    checker=None,
) -> None:
    action = db.get_setting("unregistered_message_action", "warn")
    user_id = int(user.get("id") or 0)
    username = str(user.get("username") or "")
    display_name = short_user_name(user)
    final_action = f"{action}_verification_unavailable" if unavailable else action

    errors = []
    if action in {"delete", "delete_warn"}:
        try:
            bot.delete_message(chat_id, message_id)
        except Exception as exc:
            errors.append(str(exc))
    if action in {"warn", "delete_warn"}:
        try:
            if unavailable:
                prompt, markup = '网站验证服务暂时不可用，请稍后重试；这不代表你的账号未注册。', None
            else:
                prompt, markup = group_binding_hint(checker, chat_id, db)
            send_group_prompt(bot, chat_id, user_id, display_name[:80] + '，' + prompt, reply_markup=markup)
        except Exception as exc:
            errors.append(str(exc))
    if errors:
        final_action = f"{action}_failed"
        reason = f"{reason}; 处理失败: {'; '.join(errors)}"

    db.record_event(
        event_type="message_verify",
        chat_id=chat_id,
        chat_title=chat_name,
        telegram_id=user_id,
        username=username,
        display_name=display_name,
        action=final_action,
        reason=reason,
        text=text,
    )


def handle_join_request(
    bot: TelegramBot,
    db: AppDatabase,
    checker: PanelUserChecker,
    allowed_chat_ids: set[int],
    join_request: dict[str, Any],
) -> None:
    chat, user = join_request.get('chat') or {}, join_request.get('from') or {}
    if chat.get('id') is None or user.get('id') is None:
        return
    with db.join_guard(int(chat['id']), int(user['id'])):
        _handle_join_request(bot, db, checker, allowed_chat_ids, join_request)


def _handle_join_request(bot, db, checker, allowed_chat_ids, join_request):
    started = time.monotonic()
    chat = join_request.get("chat") or {}
    user = join_request.get("from") or {}
    if chat.get("id") is None or user.get("id") is None:
        return
    chat_id = int(chat["id"])
    user_id = int(user["id"])
    chat_name = chat_title(chat)
    group = db.ensure_group(chat_id, chat_name)
    if not should_reply(chat_id, allowed_chat_ids) or not int(group["enabled"]) or not int(group["verify_join"]):
        return

    username = str(user.get("username") or "")
    display_name = short_user_name(user)
    local = db.get_registered_user(user_id)
    blocked = bool(local and local['status'] == 'blocked')
    onboarding = join_onboarding_enabled(chat_id)
    guidance_action = None
    guidance_error = ''
    if onboarding and not blocked:
        try:
            guidance_action = hold_join_for_binding(bot, db, join_request, checker)
        except Exception as exc:
            guidance_action = 'guidance_failed'
            guidance_error = str(exc)
    guidance_finished = time.monotonic()
    result = verify_group_member(checker, user_id, chat_id)
    verified = time.monotonic()
    local = db.get_registered_user(user_id)
    blocked = bool(local and local['status'] == 'blocked')
    if blocked:
        result = VerificationResult(False, '后台已拉黑', 'local')
    if onboarding and not result.allowed and not blocked:
        action = guidance_action or 'waiting_for_binding'
        reason = result.reason + (f'; 私聊引导失败: {guidance_error}' if guidance_error else '')
    elif result.unavailable:
        action = "verification_deferred"
        reason = result.reason
    elif result.allowed:
        action = "approve"
        reason = result.reason
        try:
            bot.approve_chat_join_request(chat_id, user_id)
            if onboarding and type(join_request.get('date')) is int:
                db.forget_pending_join(chat_id, user_id, join_request['date'])
        except Exception as exc:
            action = "approve_failed"
            reason = f"{result.reason}; 处理失败: {exc}"
    else:
        action = db.get_setting("unregistered_join_action", "decline")
        reason = result.reason
        if action == "decline":
            try:
                bot.decline_chat_join_request(chat_id, user_id)
            except Exception as exc:
                action = "decline_failed"
                reason = f"{result.reason}; 处理失败: {exc}"

    db.record_event(
        event_type="join_request",
        chat_id=chat_id,
        chat_title=chat_name,
        telegram_id=user_id,
        username=username,
        display_name=display_name,
        action=action,
        reason=reason,
        text="",
    )
    print(f"chat={chat_id} join_request user={user_id} action={action} reason={reason}", flush=True)
    log_timing('join_handler', chat_id=chat_id, action=action,
               guidance_ms=round((guidance_finished - started) * 1000),
               verify_ms=round((verified - guidance_finished) * 1000),
               finish_ms=round((time.monotonic() - verified) * 1000))


def handle_new_members(
    bot: TelegramBot,
    db: AppDatabase,
    checker: PanelUserChecker,
    group: sqlite3.Row,
    chat_id: int,
    chat_name: str,
    message_id: int,
    members: list[dict[str, Any]],
) -> None:
    if not int(group["verify_join"]):
        return
    for member in members:
        if member.get("is_bot"):
            continue
        user_id = int(member.get("id") or 0)
        if not user_id:
            continue
        result = verify_group_member(checker, user_id, chat_id)
        username = str(member.get("username") or "")
        display_name = short_user_name(member)
        action = "allow_join"
        reason = result.reason
        if result.unavailable:
            action = "verification_deferred"
        elif not result.allowed:
            action = "kick"
            try:
                bot.kick_chat_member(chat_id, user_id)
                bot.send_message(chat_id, registration_prompt(db), None)
            except Exception as exc:
                action = "kick_failed"
                reason = f"{result.reason}; 处理失败: {exc}"
        elif db.get_setting("welcome_registered", "1") == "1":
            try:
                bot.send_message(chat_id, f"欢迎 {display_name}，验证通过啦。", message_id)
            except Exception:
                pass
        db.record_event(
            event_type="new_member",
            chat_id=chat_id,
            chat_title=chat_name,
            telegram_id=user_id,
            username=username,
            display_name=display_name,
            action=action,
            reason=reason,
            text="",
        )
        print(f"chat={chat_id} new_member user={user_id} action={action} reason={reason}", flush=True)


def handle_command(
    bot: TelegramBot,
    text: str,
    chat_id: int,
    message_id: int,
    private: bool = False,
) -> None:
    command = text.split()[0].split("@")[0].lower()
    print(f"chat={chat_id} command={command}", flush=True)
    if command in {"/start", "/help"}:
        bot.send_message(
            chat_id,
            "亲亲，我是群组验证小助手。\n\n"
            "绑定网站账号请直接在私聊发送注册邮箱，收到邮件后直接发送收到的6位验证码，无需输入命令。\n"
            "收件箱没找到验证码邮件，请检查垃圾邮件文件夹。\n"
            "私聊下方可直接点击“绑定邮箱”查看步骤；入群请从网站的加群入口提交申请，完成邮箱绑定后返回群组查看。",
            message_id,
            reply_markup=binding_account_keyboard() if private else None,
        )
        return None
    if command in {"/ping", "/验证"}:
        bot.send_message(chat_id, f"机器人在线，当前 chat_id：{chat_id}", message_id)
        return None
    return None


def handle_advertising(bot, db, message):
    guard = getattr(bot, 'ad_guard', None)
    if guard is None:
        return False
    match = guard.match(message)
    if match is None:
        return False
    chat = message['chat']
    chat_id, actor = int(chat['id']), match['actor']
    channel = match['kind'] == 'channel'
    if not channel:
        try:
            status = bot.get_chat_member(chat_id, actor).get('status')
            if status in {'administrator', 'creator'}:
                guard.clear_actor(chat_id, match['kind'], actor)
                return False
            if status not in {'member', 'restricted', 'left', 'kicked'}:
                raise RuntimeError('Unrecognized member status')
        except Exception:
            db.record_event(event_type='advertising', chat_id=chat_id, chat_title=chat_title(chat),
                            telegram_id=actor, action='ban_deferred', reason='Cannot confirm administrator status', text='')
            return False
    reason = match['reason']
    try:
        bot.ban_advertiser(chat_id, actor, channel=channel)
        action = 'channel_banned' if channel else 'user_banned'
        guard.clear_actor(chat_id, match['kind'], actor)
    except Exception as error:
        action = 'ban_failed'
        reason += '; ' + str(error)
    try:
        bot.delete_message(chat_id, int(message['message_id']))
    except Exception:
        pass
    sender = message.get('sender_chat' if channel else 'from') or {}
    db.record_event(event_type='advertising', chat_id=chat_id, chat_title=chat_title(chat),
                    telegram_id=0 if channel else actor, username=str(sender.get('username') or ''),
                    display_name=chat_title(sender) if channel else short_user_name(sender),
                    action=action, reason=f"{reason}; {match['kind']}={actor}", text='')
    return True


def handle_message(
    bot: TelegramBot,
    db: AppDatabase,
    checker: PanelUserChecker,
    allowed_chat_ids: set[int],
    message: dict[str, Any],
) -> None:
    chat = message.get("chat") or {}
    if chat.get("id") is None or message.get("message_id") is None:
        return
    chat_id = int(chat["id"])
    message_id = int(message["message_id"])
    chat_name = chat_title(chat)
    chat_type = str(chat.get("type") or "")
    group = db.ensure_group(chat_id, chat_name)

    members = message.get("new_chat_members") or []
    if isinstance(members, list) and members:
        if not should_reply(chat_id, allowed_chat_ids) or not int(group["enabled"]):
            return
        handle_new_members(bot, db, checker, group, chat_id, chat_name, message_id, members)
        return

    # Service updates are not speech; all supported user message types are checked.
    if not USER_MESSAGE_FIELDS.intersection(message):
        return
    text = (message.get("text") or message.get("caption") or "").strip()

    sender = message.get("from") or {}
    sender_id = int(sender.get("id") or 0)
    command_name = text.split()[0].split("@")[0].lower() if text.startswith("/") else ""

    if (chat_type in {'group', 'supergroup'} and should_reply(chat_id, allowed_chat_ids)
            and int(group['enabled']) and handle_advertising(bot, db, message)):
        return

    sites = site_registry(checker) if chat_type == 'private' else None
    if sites:
        if sender_id <= 0:
            return
        local = db.get_registered_user(sender_id)
        if local and local['status'] == 'blocked':
            bot.send_message(chat_id, '当前Telegram账号不可操作，请联系管理员。',
                             message_id, reply_markup={'remove_keyboard': True})
            return
        try:
            if text in {'切换机场', '/sites'}:
                sites.begin_switch(sender_id)
            choice = sites.choice(sender_id, text)
            if choice:
                sites.select(sender_id, choice)
        except BindingError as error:
            try:
                sites.begin_switch(sender_id)
            except BindingError:
                pass
            bot.send_message(chat_id, str(error), message_id, reply_markup={'remove_keyboard': True})
            return
        if choice:
            SitePrivateBot(bot, sites.sites[choice]['label'], sites, sender_id).send_message(
                chat_id, site_help_text(sites, sender_id, choice),
                message_id, reply_markup=binding_account_keyboard())
            return
        current = sites.current(sender_id)
        if text in {'切换机场', '/sites'} or current is None:
            sites.clear_pending(sender_id)
            keys, incomplete = sites.bound_sites(sender_id)
            if (current is None and len(keys) == 1 and not sites.awaiting_choice(sender_id)
                    and text not in {'切换机场', '/sites'}):
                sites.select(sender_id, keys[0])
                SitePrivateBot(bot, sites.sites[keys[0]]['label'], sites, sender_id).send_message(
                    chat_id, bound_help_text(),
                    message_id, reply_markup=binding_account_keyboard())
                return
            message = ('请点击下方要使用的机场，获取订阅或查看套餐。' if keys else
                       '请从你使用的网站或群内的账号验证入口进入。')
            if incomplete:
                message += '\n绑定信息暂未能完整获取，请稍后重试。'
            bot.send_message(chat_id, message, message_id, reply_markup=sites.keyboard(keys))
            return
        checker = checker.for_site(current)
        bot = SitePrivateBot(bot, sites.sites[current]['label'], sites, sender_id)

    if command_name in {"/绑定", "/bind", "/验证码", "/code"}:
        if chat_type == "private" or should_reply(chat_id, allowed_chat_ids):
            handle_bind_command(bot, db, checker, chat_id, chat_type, chat_name, message_id, sender, text)
        return

    if chat_type == "private":
        if text in {'获取订阅', '查看套餐', '发布页'}:
            handle_account_read(bot, db, checker, chat_id, message_id, sender_id, text)
            return
        if text in {'重置订阅', '确认重置订阅', '取消重置'}:
            handle_subscription_reset(bot, db, checker, chat_id, message_id, sender_id, text)
            return
        if text == '绑定邮箱':
            handle_bind_command(bot, db, checker, chat_id, chat_type, chat_name, message_id, sender, '/绑定')
            return
        if text in {'解绑邮箱', '确认解绑', '取消解绑'}:
            handle_unbind_message(bot, db, checker, chat_id, message_id, sender_id, text)
            return
        if text == '重新发送验证码':
            handle_bind_command(bot, db, checker, chat_id, chat_type, chat_name, message_id, sender, '/resend')
            return
        if normalize_email(text) or re.fullmatch(r'[0-9]{6}', text):
            binding_text = ('/验证码 ' if text.isdigit() else '/绑定 ') + text
            handle_bind_command(bot, db, checker, chat_id, chat_type, chat_name, message_id, sender, binding_text)
            return
        if command_name == '/验证' or text == '重试入群验证':
            approved, pending = approve_pending_joins(bot, db, checker, sender_id)
            text = join_approval_text(approved, pending).strip()
            bot.send_message(chat_id, text or '请先申请入群，再在本私聊发送网站注册邮箱和邮件验证码完成验证。',
                             message_id, reply_markup=binding_account_keyboard())
            return
        if text.startswith("/") and command_name in {"/start", "/help"}:
            if sites:
                bot.send_message(chat_id, site_help_text(sites, sender_id, current), message_id,
                                 reply_markup=binding_account_keyboard())
            else:
                handle_command(bot, text, chat_id, message_id, private=True)
            approved, pending = approve_pending_joins(bot, db, checker, sender_id)
            if approved or pending:
                bot.send_message(chat_id, join_approval_text(approved, pending).strip(), message_id,
                                 reply_markup=binding_account_keyboard())
            return
        bot.send_message(chat_id, '请发送网站注册邮箱，或直接发送收到的6位数字验证码。\n'
                         '验证码过期可点击“重新发送验证码”；每次发送至少间隔60秒，每小时最多5次。',
                         message_id, reply_markup=binding_resend_keyboard())
        return

    if not should_reply(chat_id, allowed_chat_ids) or not int(group["enabled"]):
        return

    if int(group["verify_message"]):
        sender_chat = message.get("sender_chat") or {}
        if sender_chat:
            if sender_chat.get("id") == chat_id or message.get("is_automatic_forward"):
                return
            result = VerificationResult(False, "请使用个人账号完成网站用户验证后发言")
        elif sender.get("is_bot") or (sender_id and user_is_admin(bot, chat_id, sender_id)):
            result = VerificationResult(True, "管理员或机器人")
        elif sender_id:
            result = verify_group_member(checker, sender_id, chat_id)
        else:
            result = VerificationResult(False, "无法识别发言账号")
        if result.unavailable or not result.allowed:
            handle_unregistered_message(
                bot, db, chat_id, chat_name, message_id, sender, text,
                result.reason, unavailable=result.unavailable,
                checker=checker,
            )
            return

    if text.startswith("/"):
        handle_command(bot, text, chat_id, message_id)


def run_bot(stop_requested=None, on_ready=None) -> None:
    token = os.getenv("TELEGRAM_BOT_TOKEN", "").strip()
    if not token or token.startswith("123456789:"):
        raise SystemExit("请先在 .env 中填写 TELEGRAM_BOT_TOKEN")

    allowed_chat_ids = parse_chat_ids(os.getenv("TELEGRAM_ALLOWED_CHAT_IDS"))
    poll_timeout = max(1, min(env_int("TELEGRAM_POLL_TIMEOUT", 5), 30))
    poll_interval = env_int("TELEGRAM_POLL_INTERVAL", 1)
    db = AppDatabase(Path(os.getenv("BOT_ADMIN_DB", str(APP_DB_FILE))))
    checker = PanelUserChecker(db)

    if env_bool("WEB_ADMIN_ENABLE", False):
        start_web_admin(db)

    bot = TelegramBot(token)
    mapped_groups = set(checker.sites.groups) if checker.sites else allowed_chat_ids
    bot.ad_guard = AdvertisingGuard({cid for cid in mapped_groups if should_reply(cid, allowed_chat_ids)})
    me = bot.get_me()
    os.environ['TELEGRAM_BOT_USERNAME'] = me['username']
    cleanup_bot = TelegramBot(token)
    bot.message_cleanup = MessageCleanup(cleanup_bot.delete_message, BASE_DIR / 'pending_message_deletions.json', allowed_chat_ids=allowed_chat_ids)
    bot.message_cleanup.start()
    join_cleanup = JoinRequestCleanup(TelegramBot(token), db, PanelUserChecker(db))
    join_cleanup.start()
    departures = DepartureUnbinder(TelegramBot(token), db, checker.sites,
                                  BASE_DIR / 'member_departures.json', allowed_chat_ids)
    departures.start()
    print(f"机器人已启动: @{me.get('username', me.get('first_name', 'unknown'))}", flush=True)
    log_timing('poll_config', timeout_s=poll_timeout, idle_interval_s=poll_interval)
    log_timing('join_cleanup_config', timeout_s=JOIN_VERIFICATION_SECONDS, scan_interval_s=5)
    log_timing('departure_config', voluntary_leave_unbind=True, keep_banned_binding=True)

    state = load_state()
    offset = state.get("offset")
    if env_bool("TELEGRAM_DROP_PENDING_UPDATES", False):
        updates = bot.get_updates(offset=-1, timeout=0)
        if updates:
            offset = int(updates[-1]["update_id"]) + 1
            save_state({"offset": offset})
        print("已跳过历史消息，从新消息开始处理。", flush=True)

    while True:
        try:
            if stop_requested and stop_requested():
                raise KeyboardInterrupt
            poll_started = time.monotonic()
            updates = bot.get_updates(offset=offset, timeout=poll_timeout)
            if on_ready:
                on_ready()
            received = time.monotonic()
            received_at = time.time()
            for update in updates:
                kind = next((k for k in ('chat_join_request', 'chat_member', 'callback_query') if update.get(k)), 'message')
                timestamp = (update.get(kind) or {}).get('date')
                log_timing('update_received', update_id=update['update_id'], kind=kind,
                           age_ms=round((received_at - timestamp) * 1000) if type(timestamp) is int else None,
                           poll_ms=round((received - poll_started) * 1000),
                           queued_ms=round((time.monotonic() - received) * 1000))
                # Persist member events before acknowledging their Telegram update offset.
                if update.get('chat_member'):
                    departures.observe(update)
                offset = int(update["update_id"]) + 1
                save_state({"offset": offset})

                if update.get("chat_join_request"):
                    handle_join_request(bot, db, checker, allowed_chat_ids, update["chat_join_request"])
                    continue

                if update.get('callback_query'):
                    handle_account_callback(bot, db, checker, update['callback_query'])
                    continue

                message = update.get("message") or {}
                if message:
                    handle_message(bot, db, checker, allowed_chat_ids, message)
            if not updates:
                time.sleep(poll_interval)
        except KeyboardInterrupt:
            departures.stop()
            join_cleanup.stop()
            bot.message_cleanup.stop()
            print("已停止。", flush=True)
            return
        except (TimeoutError, socket.timeout):
            time.sleep(poll_interval)
        except Exception as exc:
            if "timed out" in str(exc).lower():
                time.sleep(poll_interval)
                continue
            print(f"运行出错，稍后重试: {exc}", file=sys.stderr, flush=True)
            time.sleep(max(poll_interval, 3))


def send_test() -> None:
    token = os.getenv("TELEGRAM_BOT_TOKEN", "").strip()
    chat_id_text = os.getenv("TELEGRAM_VERIFY_CHAT_ID", "").strip()
    if not token or token.startswith("123456789:"):
        raise SystemExit("请先在 .env 中填写 TELEGRAM_BOT_TOKEN")
    if not chat_id_text:
        raise SystemExit("请先在 .env 中填写 TELEGRAM_VERIFY_CHAT_ID")
    try:
        chat_id = int(chat_id_text)
    except ValueError as exc:
        raise SystemExit("TELEGRAM_VERIFY_CHAT_ID 必须是数字") from exc
    message = os.getenv("TELEGRAM_VERIFY_MESSAGE", "TG bot test message.")
    TelegramBot(token).send_message(chat_id, message)
    print(f"测试消息已发送到 {chat_id}")


def check_bot() -> None:
    token = os.getenv("TELEGRAM_BOT_TOKEN", "").strip()
    if not token or token.startswith("123456789:"):
        raise SystemExit("请先在 .env 中填写 TELEGRAM_BOT_TOKEN")
    me = TelegramBot(token).get_me()
    username = me.get("username", "")
    name = me.get("first_name", "")
    print(f"机器人验证成功: {name} @{username}")


def format_time(ts: int | str | None) -> str:
    try:
        value = int(ts or 0)
    except ValueError:
        value = 0
    if not value:
        return "-"
    return time.strftime("%Y-%m-%d %H:%M:%S", time.localtime(value))


def h(value: Any) -> str:
    return html.escape(str(value if value is not None else ""), quote=True)


def make_session(password: str) -> str:
    ts = str(now_ts())
    secret = os.getenv("WEB_ADMIN_SECRET", password)
    sig = hmac.new(secret.encode("utf-8"), ts.encode("utf-8"), hashlib.sha256).hexdigest()
    return base64.urlsafe_b64encode(f"{ts}:{sig}".encode("utf-8")).decode("ascii")


def valid_session(cookie: str, password: str) -> bool:
    if not cookie:
        return False
    cookies = {}
    for part in cookie.split(";"):
        if "=" in part:
            key, value = part.strip().split("=", 1)
            cookies[key] = value
    raw = cookies.get("tg_admin_session", "")
    if not raw:
        return False
    try:
        decoded = base64.urlsafe_b64decode(raw.encode("ascii")).decode("utf-8")
        ts, sig = decoded.split(":", 1)
        age = now_ts() - int(ts)
    except Exception:
        return False
    if age < 0 or age > env_int("WEB_ADMIN_SESSION_TTL", 86400):
        return False
    secret = os.getenv("WEB_ADMIN_SECRET", password)
    expected = hmac.new(secret.encode("utf-8"), ts.encode("utf-8"), hashlib.sha256).hexdigest()
    return hmac.compare_digest(sig, expected)


def option(value: str, label: str, current: str) -> str:
    selected = " selected" if value == current else ""
    return f'<option value="{h(value)}"{selected}>{h(label)}</option>'


class AdminHandler(BaseHTTPRequestHandler):
    db: AppDatabase

    def log_message(self, fmt: str, *args: Any) -> None:
        print(f"web_admin {self.address_string()} {fmt % args}", flush=True)

    def password(self) -> str:
        return os.getenv("WEB_ADMIN_PASSWORD", "").strip()

    def authenticated(self) -> bool:
        password = self.password()
        return bool(password) and valid_session(self.headers.get("Cookie", ""), password)

    def send_html(self, body: str, status: HTTPStatus = HTTPStatus.OK, headers: dict[str, str] | None = None) -> None:
        data = body.encode("utf-8")
        self.send_response(status.value)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        self.send_header("Content-Length", str(len(data)))
        if headers:
            for key, value in headers.items():
                self.send_header(key, value)
        self.end_headers()
        self.wfile.write(data)

    def redirect(self, location: str) -> None:
        self.send_response(302)
        self.send_header("Location", location)
        self.end_headers()

    def read_form(self) -> dict[str, list[str]]:
        length = int(self.headers.get("Content-Length", "0") or "0")
        data = self.rfile.read(length).decode("utf-8") if length else ""
        return urllib.parse.parse_qs(data, keep_blank_values=True)

    def do_GET(self) -> None:
        path = urllib.parse.urlparse(self.path).path
        if path in {"/", "/admin"}:
            if not self.authenticated():
                self.redirect("/login")
                return
            self.send_html(self.render_admin())
            return
        if path == "/login":
            self.send_html(self.render_login())
            return
        self.send_html("Not Found", HTTPStatus.NOT_FOUND)

    def do_POST(self) -> None:
        path = urllib.parse.urlparse(self.path).path
        if path == "/login":
            form = self.read_form()
            password = self.password()
            supplied = form.get("password", [""])[0]
            if password and hmac.compare_digest(password, supplied):
                cookie = make_session(password)
                self.send_html(
                    "<meta http-equiv='refresh' content='0;url=/admin'>登录成功",
                    headers={"Set-Cookie": f"tg_admin_session={cookie}; HttpOnly; SameSite=Lax; Path=/"},
                )
                return
            self.send_html(self.render_login("密码不对"), HTTPStatus.UNAUTHORIZED)
            return
        if not self.authenticated():
            self.redirect("/login")
            return
        form = self.read_form()
        try:
            if path == "/admin/settings":
                self.save_settings(form)
            elif path == "/admin/group":
                self.save_group(form)
            elif path == "/admin/user":
                self.save_user(form)
            elif path == "/admin/user/delete":
                self.db.delete_registered_user(int(form.get("telegram_id", ["0"])[0]))
            elif path == "/admin/events/clear":
                self.db.clear_events()
        except Exception as exc:
            self.send_html(self.render_admin(error=str(exc)), HTTPStatus.BAD_REQUEST)
            return
        self.redirect("/admin")

    def save_settings(self, form: dict[str, list[str]]) -> None:
        if set(form) - AppDatabase.DEFAULT_SETTINGS.keys():
            raise ValueError("不支持的设置项，请刷新页面")
        values = {
            "verify_join_default": str(parse_bool_field(form, "verify_join_default")),
            "verify_message_default": str(parse_bool_field(form, "verify_message_default")),
            "panel_require_active_plan": str(parse_bool_field(form, "panel_require_active_plan")),
            "welcome_registered": str(parse_bool_field(form, "welcome_registered")),
            "unregistered_join_action": form.get("unregistered_join_action", ["decline"])[0],
            "unregistered_message_action": form.get("unregistered_message_action", ["warn"])[0],
            "warn_unregistered_text": form.get("warn_unregistered_text", [""])[0].strip(),
        }
        self.db.save_settings(values)

    def save_group(self, form: dict[str, list[str]]) -> None:
        if set(form) - {"chat_id", "title", "enabled", "verify_join", "verify_message"}:
            raise ValueError("不支持的群设置项，请刷新页面")
        chat_id = int(form.get("chat_id", ["0"])[0])
        self.db.save_group(
            {
                "chat_id": chat_id,
                "title": form.get("title", [""])[0].strip(),
                "enabled": parse_bool_field(form, "enabled"),
                "verify_join": parse_bool_field(form, "verify_join"),
                "verify_message": parse_bool_field(form, "verify_message"),
            }
        )

    def save_user(self, form: dict[str, list[str]]) -> None:
        self.db.save_registered_user(
            {
                "telegram_id": int(form.get("telegram_id", ["0"])[0]),
                "username": form.get("username", [""])[0].strip(),
                "display_name": form.get("display_name", [""])[0].strip(),
                "email": form.get("email", [""])[0].strip(),
                "status": form.get("status", ["active"])[0],
                "source": "manual",
                "note": form.get("note", [""])[0].strip(),
            }
        )

    def render_login(self, error: str = "") -> str:
        return f"""<!doctype html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>TG 机器人后台</title>{self.style()}</head>
<body><main class="login-card"><h1>TG 机器人后台</h1><p>请输入后台密码。</p>
{f'<div class="error">{h(error)}</div>' if error else ''}
<form method="post" action="/login"><input type="password" name="password" placeholder="后台密码" autofocus>
<button type="submit">登录</button></form></main></body></html>"""

    def render_admin(self, error: str = "") -> str:
        settings = self.db.settings()
        groups = self.db.list_groups()
        users = self.db.list_registered_users()
        events = self.db.list_events()
        panel_url = os.getenv("PANEL_EMAIL_BINDING_URL", "").strip() or "未配置"
        gateway_status = "已配置" if os.getenv("PANEL_EMAIL_BINDING_URL", "").strip() and os.getenv("PANEL_EMAIL_BINDING_SECRET", "").strip() else "未配置"
        entry_links = ''
        sites = PanelSites.from_env(BASE_DIR)
        if sites:
            panel_url = '、'.join(site['label'] for site in sites.sites.values())
            gateway_status = f'已配置 {len(sites.sites)} 个站'
            username = os.getenv('TELEGRAM_BOT_USERNAME', '')
            if username:
                entry_links = '<p class="muted note">验证入口：' + ' | '.join(
                    f'<a href="{h(sites.entry_url(key, username))}" target="_blank" rel="noopener noreferrer">{h(site["label"])}</a>'
                    for key, site in sites.sites.items()) + '</p>'
        return f"""<!doctype html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>TG 机器人后台</title>{self.style()}</head>
<body><main class="page">
<header class="topbar"><div><h1>TG 加群与发言验证</h1><p>群组与注册用户管理</p></div>
<div class="status-pills"><span class="pill">邮箱绑定：{h(gateway_status)}</span><span class="pill">数据库：{h(APP_DB_FILE.name)}</span></div></header>
{f'<div class="error">{h(error)}</div>' if error else ''}
<section class="grid two">
<div class="card"><h2>验证策略</h2>
<form method="post" action="/admin/settings" class="stack">
<div class="switch-grid">
{self.checkbox("verify_join_default", "新群默认开启加群验证", settings)}
{self.checkbox("verify_message_default", "新群默认开启发言验证", settings)}
{self.checkbox("panel_require_active_plan", "面板用户必须有可用套餐", settings)}
{self.checkbox("welcome_registered", "已验证用户进群时发送欢迎", settings)}
</div>
<div class="row">
<label>未注册加群处理<select name="unregistered_join_action">
{option("decline", "拒绝加群申请", settings.get("unregistered_join_action", "decline"))}
{option("notify", "只记录，不处理", settings.get("unregistered_join_action", "decline"))}
</select></label>
<label>未注册发言处理<select name="unregistered_message_action">
{option("warn", "提醒，不删消息", settings.get("unregistered_message_action", "warn"))}
{option("delete_warn", "删除并提醒", settings.get("unregistered_message_action", "warn"))}
{option("delete", "只删除", settings.get("unregistered_message_action", "warn"))}
{option("ignore", "只记录", settings.get("unregistered_message_action", "warn"))}
</select></label>
</div>
<label>未注册提醒文案<textarea name="warn_unregistered_text">{h(settings.get("warn_unregistered_text", ""))}</textarea></label>
<button type="submit">保存策略</button></form>
<p class="muted note">邮箱绑定面板：{h(panel_url)}</p>{entry_links}</div>

<div class="card"><h2>手动登记用户</h2>
<form method="post" action="/admin/user" class="stack">
<div class="row"><label>Telegram ID<input name="telegram_id" required placeholder="例如 123456789"></label>
<label>状态<select name="status"><option value="active">放行</option><option value="blocked">拉黑</option></select></label></div>
<div class="row"><label>用户名<input name="username" placeholder="不带 @ 也可以"></label>
<label>邮箱备注<input name="email" placeholder="可留空"></label></div>
<label>显示名<input name="display_name" placeholder="可留空"></label>
<label>备注<input name="note" placeholder="登记原因"></label>
<button type="submit">保存用户</button></form></div>
</section>

<section class="card"><h2>群管理</h2>{self.render_groups(groups)}</section>
<section class="grid two">
<div class="card"><h2>已登记用户</h2>{self.render_users(users)}</div>
<div class="card"><h2>最近记录</h2>{self.render_events(events)}</div>
</section>
</main></body></html>"""

    def checkbox(self, name: str, label: str, settings: dict[str, str]) -> str:
        checked = " checked" if settings.get(name, "0") == "1" else ""
        return f'<label class="check"><input type="checkbox" name="{h(name)}" value="1"{checked}><span>{h(label)}</span></label>'

    def render_groups(self, groups: list[sqlite3.Row]) -> str:
        if not groups:
            return "<p class='muted'>还没有群记录。先在群里发送 /ping，机器人会自动记录。</p>"
        rows = []
        for row in groups:
            rows.append(
                f"""<form class="table-row" method="post" action="/admin/group">
<input type="hidden" name="chat_id" value="{h(row['chat_id'])}">
<div><b>{h(row['title'])}</b><small>{h(row['chat_id'])}</small></div>
<input name="title" value="{h(row['title'])}">
<label><input type="checkbox" name="enabled" value="1"{' checked' if row['enabled'] else ''}>启用</label>
<label><input type="checkbox" name="verify_join" value="1"{' checked' if row['verify_join'] else ''}>加群验证</label>
<label><input type="checkbox" name="verify_message" value="1"{' checked' if row['verify_message'] else ''}>发言验证</label>
<button type="submit">保存</button></form>"""
            )
        return "<div class='table'>" + "".join(rows) + "</div>"

    def render_users(self, users: list[sqlite3.Row]) -> str:
        if not users:
            return "<p class='muted'>暂无手动登记用户。</p>"
        items = []
        for row in users:
            badge = "ok" if row["status"] == "active" else "bad"
            items.append(
                f"""<div class="list-item"><div><b>{h(row['display_name'] or row['username'] or row['telegram_id'])}</b>
<small>{h(row['telegram_id'])} {h('@' + row['username'] if row['username'] else '')} {h(row['email'])}</small>
<small>{h(row['note'])}</small></div><div><span class="badge {badge}">{h(row['status'])}</span>
<form method="post" action="/admin/user/delete"><input type="hidden" name="telegram_id" value="{h(row['telegram_id'])}"><button>删除</button></form></div></div>"""
            )
        return "".join(items)

    def render_events(self, events: list[sqlite3.Row]) -> str:
        clear = "<form method='post' action='/admin/events/clear'><button type='submit'>清空记录</button></form>"
        if not events:
            return "<p class='muted'>暂无记录。</p>" + clear
        items = [clear]
        for row in events:
            items.append(
                f"""<div class="event"><b>{h(row['action'] or row['event_type'])}</b><span>{h(format_time(row['created_at']))}</span>
<small>{h(row['chat_title'])} / {h(row['display_name'] or row['telegram_id'])}</small>
<p>{h(row['reason'])}</p><p class="muted">{h(row['text'])}</p></div>"""
            )
        return "".join(items)

    def style(self) -> str:
        return """<style>
*{box-sizing:border-box}
body{margin:0;background:#f3f6fb;color:#172033;font:14px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Arial,"Microsoft YaHei",sans-serif}
.page{width:min(1500px,calc(100vw - 32px));margin:24px auto 40px}
.login-card{width:min(420px,92vw);margin:12vh auto;padding:30px;background:#fff;border:1px solid #dfe7f1;border-radius:16px;box-shadow:0 16px 36px rgba(35,52,83,.1)}
.login-card form{display:grid;gap:12px}
.topbar{display:flex;align-items:center;justify-content:space-between;gap:18px;margin-bottom:16px;padding:18px 20px;background:#fff;border:1px solid #dfe7f1;border-radius:16px;box-shadow:0 10px 28px rgba(38,56,87,.06)}
h1{margin:0;font-size:24px;line-height:1.25;color:#111827}
h2{margin:0 0 16px;font-size:17px;line-height:1.25;color:#172033}
p{margin:0 0 10px}
.muted{color:#748398}
.note{margin-top:14px;padding:12px 14px;background:#f7f9fc;border:1px solid #e7edf5;border-radius:12px}
.error{background:#fff1f1;color:#b91c1c;border:1px solid #fecaca;border-radius:12px;padding:12px 14px;margin-bottom:14px}
.status-pills{display:flex;align-items:center;justify-content:flex-end;gap:10px;flex-wrap:wrap;min-width:260px}
.pill,.badge{display:inline-flex;align-items:center;justify-content:center;min-height:32px;border-radius:999px;padding:6px 12px;background:#eef5ff;color:#34517a;font-weight:700;white-space:nowrap}
.badge.ok{background:#dcfce7;color:#166534}
.badge.bad{background:#fee2e2;color:#991b1b}
.grid{display:grid;gap:16px;margin-bottom:16px}
.grid.two{grid-template-columns:minmax(0,1fr) minmax(0,1fr);align-items:stretch}
.card{height:100%;background:#fff;border:1px solid #dfe7f1;border-radius:16px;padding:18px;box-shadow:0 10px 28px rgba(38,56,87,.055);min-width:0}
.stack{display:grid;gap:12px}
.switch-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.row{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:12px}
label{display:grid;gap:7px;color:#344258;font-weight:650}
.check{display:flex;align-items:center;gap:10px;min-height:42px;padding:10px 12px;border:1px solid #e8eef6;border-radius:12px;background:#f8fafd;font-weight:600}
.check input{width:auto;margin:0}
input,select,textarea{width:100%;min-height:42px;border:1px solid #ccd7e5;border-radius:10px;background:#fff;color:#172033;padding:10px 12px;font:inherit;outline:none}
input:focus,select:focus,textarea:focus{border-color:#7aa7e8;box-shadow:0 0 0 3px rgba(122,167,232,.15)}
textarea{min-height:94px;resize:vertical}
button{min-height:38px;border:0;border-radius:10px;background:#1f2937;color:#fff;padding:9px 16px;font-weight:750;cursor:pointer;white-space:nowrap}
button:hover{background:#111827}
button:active{transform:translateY(1px)}
.table{display:grid;gap:10px;overflow-x:auto;padding-bottom:2px}
.table-row{display:grid;grid-template-columns:minmax(220px,1.4fr) minmax(180px,1fr) repeat(3,118px) 82px;gap:10px;align-items:center;min-width:850px;padding:12px;border:1px solid #edf1f6;border-radius:14px;background:#fafcff}
.table-row label{display:flex;align-items:center;justify-content:center;gap:6px;min-height:38px;padding:8px 10px;border:1px solid #edf1f6;border-radius:10px;background:#fff;font-weight:600}
.table-row input[type="checkbox"]{width:auto;min-height:auto}
.table-row small,.list-item small{display:block;color:#75849a;line-height:1.45}
.list-item{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;border-bottom:1px solid #edf1f6;padding:12px 0}
.list-item:first-child{padding-top:0}
.list-item:last-child{border-bottom:0;padding-bottom:0}
.list-item form{display:inline}
.list-item button{min-height:32px;padding:6px 12px;background:#eef2f7;color:#243044}
.event{position:relative;border-bottom:1px solid #edf1f6;padding:12px 0}
.event:first-child{padding-top:0}
.event:last-child{border-bottom:0;padding-bottom:0}
.event span{position:absolute;right:0;top:12px;color:#75849a}
.event b{display:block;max-width:calc(100% - 145px)}
.event p{margin:4px 0}
@media(max-width:900px){.page{width:min(100vw - 20px,760px);margin-top:12px}.topbar{display:block}.status-pills{justify-content:flex-start;margin-top:12px}.grid.two{grid-template-columns:1fr}.row,.switch-grid{grid-template-columns:1fr}.card{padding:16px}.event span{position:static;display:block;margin-top:4px}.event b{max-width:none}}
</style>"""


def start_web_admin(db: AppDatabase) -> None:
    password = os.getenv("WEB_ADMIN_PASSWORD", "").strip()
    if not password:
        print("WEB_ADMIN_ENABLE=true 但未设置 WEB_ADMIN_PASSWORD，网页后台未启动。", file=sys.stderr, flush=True)
        return
    host = os.getenv("WEB_ADMIN_HOST", "127.0.0.1").strip() or "127.0.0.1"
    port = env_int("WEB_ADMIN_PORT", 8787)
    AdminHandler.db = db
    server = ThreadingHTTPServer((host, port), AdminHandler)
    thread = threading.Thread(target=server.serve_forever, name="web-admin", daemon=True)
    thread.start()
    print(f"网页后台已启动: http://{host}:{port}/admin", flush=True)


def init_admin_db() -> None:
    db = AppDatabase(Path(os.getenv("BOT_ADMIN_DB", str(APP_DB_FILE))))
    print(f"后台数据库已初始化: {db.path}")


def main() -> None:
    load_env(BASE_DIR / ".env")
    parser = argparse.ArgumentParser(description="Telegram group verification bot")
    subparsers = parser.add_subparsers(dest="command", required=True)
    subparsers.add_parser("run", help="启动长轮询机器人")
    subparsers.add_parser("check", help="验证 Bot Token 是否可用")
    subparsers.add_parser("send-test", help="发送一条测试消息")
    subparsers.add_parser("init-admin-db", help="初始化网页后台数据库")
    subparsers.add_parser("delete-webhook", help="删除 Telegram webhook，改用长轮询")
    args = parser.parse_args()

    if args.command == "run":
        run_bot()
    elif args.command == "check":
        check_bot()
    elif args.command == "send-test":
        send_test()
    elif args.command == "init-admin-db":
        init_admin_db()
    elif args.command == "delete-webhook":
        token = os.getenv("TELEGRAM_BOT_TOKEN", "").strip()
        if not token or token.startswith("123456789:"):
            raise SystemExit("请先在 .env 中填写 TELEGRAM_BOT_TOKEN")
        TelegramBot(token).delete_webhook()
        print("webhook 已删除。")


if __name__ == "__main__":
    main()
