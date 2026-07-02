#!/usr/bin/env python3
import glob
import json
import os
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer


DATA_DIR = os.environ.get("V2B_KNOWLEDGE_DIR", "/opt/v2b-ai-knowledge/data")
API_KEY = os.environ.get("V2B_KNOWLEDGE_API_KEY", "")
HOST = os.environ.get("V2B_KNOWLEDGE_HOST", "127.0.0.1")
PORT = int(os.environ.get("V2B_KNOWLEDGE_PORT", "11435"))
MAX_BODY = 64 * 1024

_CACHE = {
    "loaded_at": 0,
    "signature": "",
    "items": [],
}


def json_response(handler, status, payload):
    body = json.dumps(payload, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
    handler.send_response(status)
    handler.send_header("Content-Type", "application/json; charset=utf-8")
    handler.send_header("Content-Length", str(len(body)))
    handler.end_headers()
    handler.wfile.write(body)


def data_signature():
    parts = []
    for path in sorted(glob.glob(os.path.join(DATA_DIR, "*.json"))):
        try:
            stat = os.stat(path)
        except FileNotFoundError:
            continue
        parts.append(f"{path}:{stat.st_mtime_ns}:{stat.st_size}")
    return "|".join(parts)


def load_items():
    signature = data_signature()
    if _CACHE["signature"] == signature:
        return _CACHE["items"]

    items = []
    for path in sorted(glob.glob(os.path.join(DATA_DIR, "*.json"))):
        try:
            with open(path, "r", encoding="utf-8") as handle:
                payload = json.load(handle)
        except Exception:
            continue
        if isinstance(payload, list):
            items.extend(payload)

    clean = []
    for item in items:
        if not isinstance(item, dict):
            continue
        keywords = item.get("keywords") or []
        answer_points = item.get("answer_points") or []
        if not keywords or not answer_points:
            continue
        clean.append({
            "id": str(item.get("id", "")),
            "title": str(item.get("title", "")),
            "keywords": [str(keyword) for keyword in keywords if str(keyword).strip()],
            "answer_points": [str(point) for point in answer_points if str(point).strip()],
        })

    _CACHE.update({
        "loaded_at": int(time.time()),
        "signature": signature,
        "items": clean,
    })
    return clean


def search_items(query, limit):
    haystack = str(query or "").lower()
    scored = []
    for item in load_items():
        score = 0
        for keyword in item["keywords"]:
            needle = keyword.strip().lower()
            if needle and needle in haystack:
                score += max(2, len(needle))
        if score > 0:
            scored.append((score, item))

    scored.sort(key=lambda row: row[0], reverse=True)
    results = []
    for score, item in scored[:limit]:
        results.append({
            "id": item["id"],
            "title": item["title"],
            "answer_points": item["answer_points"][:3],
            "score": score,
        })
    return results


class Handler(BaseHTTPRequestHandler):
    server_version = "V2BAIKnowledge/1.0"

    def log_message(self, fmt, *args):
        return

    def authorized(self):
        if not API_KEY:
            return True
        return self.headers.get("X-Knowledge-Key", "") == API_KEY

    def do_GET(self):
        if self.path != "/health":
            json_response(self, 404, {"ok": False, "message": "not found"})
            return
        json_response(self, 200, {
            "ok": True,
            "items": len(load_items()),
            "loaded_at": _CACHE["loaded_at"],
        })

    def do_POST(self):
        if self.path != "/api/search":
            json_response(self, 404, {"ok": False, "message": "not found"})
            return
        if not self.authorized():
            json_response(self, 403, {"ok": False, "message": "forbidden"})
            return

        try:
            length = min(int(self.headers.get("Content-Length", "0")), MAX_BODY)
        except ValueError:
            length = 0
        try:
            payload = json.loads(self.rfile.read(length).decode("utf-8")) if length else {}
        except Exception:
            json_response(self, 400, {"ok": False, "message": "invalid json"})
            return

        limit = max(1, min(10, int(payload.get("limit", 6))))
        query = str(payload.get("query", ""))[:5000]
        json_response(self, 200, {
            "ok": True,
            "items": search_items(query, limit),
        })


if __name__ == "__main__":
    httpd = ThreadingHTTPServer((HOST, PORT), Handler)
    print(f"v2b-ai-knowledge listening on {HOST}:{PORT}, data={DATA_DIR}", flush=True)
    httpd.serve_forever()
