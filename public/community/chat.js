(function () {
  "use strict";
  const $ = id => document.getElementById(id);
  const state = { me: null, rows: new Map(), urls: new Map(), attachments: [], reply: null, busy: false, operation: null, polling: false, pending: null, unread: 0, readAt: 0, active: true };
  const nini = new URLSearchParams(location.search).get("client") === "nini";
  if (new URLSearchParams(location.search).has("embedded")) document.querySelector('.chat-header > a').hidden = true;
  const auth = () => (nini ? localStorage.getItem("nini_auth_data") || localStorage.getItem("auth_data") : localStorage.getItem("authorization")) || "";
  const startingAuth = auth();
  let composing = false, compositionEnterGuard = false, compositionGuardTimer = 0;
  const icons = () => lucide.createIcons();
  function error(message, scope) { state.errorScope = scope || "action"; $("error").textContent = message || ""; $("error").hidden = !message; }
  function controls() {
    const disabled = !state.me || state.me.muted || state.busy || !state.active;
    $("message").disabled = disabled; $("attach").disabled = disabled || state.attachments.length >= 2;
    $("send").disabled = disabled || (!$("message").value.trim() && !state.attachments.length);
    $("send").querySelector("span").textContent = state.busy ? state.operation === "upload" ? "上传中" : "发送中" : "发送";
    $("send").dataset.state = state.busy ? state.operation === "upload" ? "uploading" : "sending" : "idle";
    $("send").setAttribute("aria-busy", String(state.busy));
    $("attachments").querySelectorAll("button").forEach(node => { node.disabled = disabled; });
    if (state.me && state.me.muted) $("status").textContent = "当前已被禁言";
  }
  async function api(action, data) {
    if (!auth() || auth() !== startingAuth || !state.active) { state.active = false; controls(); throw new Error("登录已变化，请返回面板重新进入"); }
    const controller = new AbortController(), timer = setTimeout(() => controller.abort(), 25000);
    try {
      const multipart = data instanceof FormData;
      const response = await fetch("/api/v1/user/community/" + action, {
        method: "POST", headers: Object.assign({ Authorization: auth(), Accept: "application/json" }, multipart ? {} : { "Content-Type": "application/json" }),
        body: multipart ? data : JSON.stringify(data || {}), signal: controller.signal, cache: "no-store"
      });
      let result;
      try { result = await response.json(); } catch (_) { throw new Error("群聊暂时不可用，请稍后重试"); }
      if (response.status === 401) { state.active = false; controls(); }
      if (!response.ok) throw new Error(result.message || "操作失败，请稍后重试");
      return result.data;
    } catch (e) { if (e.name === "AbortError") throw new Error("请求超时，可重试；重复提交不会重复发送"); throw e; }
    finally { clearTimeout(timer); }
  }
  function element(tag, cls, text) { const node = document.createElement(tag); if (cls) node.className = cls; if (text !== undefined) node.textContent = text; return node; }
  function button(icon, title, click) {
    const node = element("button", "icon-button"); node.title = title; node.setAttribute("aria-label", title);
    const symbol = element("i"); symbol.dataset.lucide = icon; node.append(symbol); node.addEventListener("click", click); return node;
  }
  function setupScrollChaining() {
    if (!nini || !new URLSearchParams(location.search).has("embedded")) return;
    const messages = $("messages");
    if (!messages) return;
    function pathFromMessages() {
      const path = [];
      let doc = messages.ownerDocument, first = messages, scale = 1;
      while (doc && first) {
        const view = doc.defaultView;
        for (let node = first; node; node = node.parentElement) {
          const css = view.getComputedStyle(node);
          if (node === doc.scrollingElement || /^(auto|scroll|overlay)$/.test(css.overflowY)) {
            path.push({ node, scale, max: Math.max(0, node.scrollHeight - node.clientHeight), top: node.scrollTop });
          }
          if (/^(contain|none)$/.test(css.overscrollBehaviorY)) return path;
        }
        try {
          const frame = view.frameElement;
          if (!frame) break;
          const frameScale = frame.getBoundingClientRect().height / (frame.offsetHeight || frame.clientHeight);
          if (Number.isFinite(frameScale) && frameScale > 0) scale *= frameScale;
          doc = frame.ownerDocument;
          first = frame.parentElement;
        } catch (_) { break; }
      }
      return path;
    }
    function moveInstantly(step, amount) {
      const node = step.node, before = node.scrollTop;
      let fallback = false;
      try { node.scrollBy({ top: amount, behavior: "instant" }); }
      catch (_) { fallback = true; }
      if (fallback || Math.abs(node.scrollTop - before) < 0.001) {
        const original = node.style.getPropertyValue("scroll-behavior");
        const priority = node.style.getPropertyPriority("scroll-behavior");
        try {
          node.style.setProperty("scroll-behavior", "auto", "important");
          node.scrollTop = before + amount;
        } finally {
          if (original) node.style.setProperty("scroll-behavior", original, priority);
          else node.style.removeProperty("scroll-behavior");
        }
      }
      return (node.scrollTop - before) / step.scale;
    }
    messages.addEventListener("wheel", function (event) {
      if (!event.cancelable || event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey || event.shiftKey || event.deltaX !== 0 || !event.deltaY) return;
      if (event.target && event.target.closest && event.target.closest("input,textarea,select,[contenteditable]")) return;
      let delta = event.deltaY;
      if (event.deltaMode === 1) {
        const css = messages.ownerDocument.defaultView.getComputedStyle(messages);
        delta *= parseFloat(css.lineHeight) || (parseFloat(css.fontSize) || 16) * 1.5;
      } else if (event.deltaMode === 2) delta *= messages.clientHeight;
      if (!Number.isFinite(delta)) return;
      const down = delta > 0;
      const maximum = Math.max(0, messages.scrollHeight - messages.clientHeight), top = messages.scrollTop;
      if (top < -1 || top > maximum + 1 || Math.abs(delta) <= Math.max(0, down ? maximum - top : top)) return;
      const path = pathFromMessages();
      if (!path.length || path[0].node !== messages || path.some(step => step.top < -1 || step.top > step.max + 1)) return;
      const capacity = step => Math.max(0, down ? step.max - Math.max(0, step.top) : Math.min(step.max, step.top));
      if (!path.slice(1).some(step => capacity(step) > 0)) return;
      // Ordinary scrolling stays native. Transfer only a boundary-crossing gesture.
      event.preventDefault();
      if (!event.defaultPrevented) return;
      let remaining = delta;
      for (const step of path) {
        const amount = (down ? 1 : -1) * Math.min(Math.abs(remaining) * step.scale, capacity(step));
        if (amount) remaining -= moveInstantly(step, amount);
        if (Math.abs(remaining) < 0.001 || (down ? remaining <= 0 : remaining >= 0)) break;
      }
    }, { passive: false });
  }
  function nearBottom() { return $("messages").scrollHeight - $("messages").scrollTop - $("messages").clientHeight < 80; }
  async function read() {
    if (!state.me || document.hidden || !nearBottom() || Date.now() - state.readAt < 5000) return;
    state.readAt = Date.now();
    try { await api("read"); state.unread = 0; $("unread").hidden = true; } catch (_) {}
  }
  function bottom() { $("messages").scrollTop = $("messages").scrollHeight; state.unread = 0; $("unread").hidden = true; read(); }
  async function imageURL(id) {
    if (!state.urls.has(id)) state.urls.set(id, api("file", { file_id: id }).then(file => {
      if (!["image/png", "image/jpeg", "image/webp"].includes(file.mime)) throw new Error("图片格式不支持");
      return URL.createObjectURL(new Blob([Uint8Array.from(atob(file.data), c => c.charCodeAt(0))], { type: file.mime }));
    }).catch(e => { state.urls.delete(id); throw e; }));
    return state.urls.get(id);
  }
  function displayName(member) { return member.moderator ? "客服小姐姐" : member.name; }
  function display(rows, reset) {
    const stick = nearBottom(), oldHeight = $("messages").scrollHeight, oldTop = $("messages").scrollTop;
    const previous = new Set(state.rows.keys());
    if (reset) {
      // Retain paged history, but remove deleted posts in the current server window.
      const oldest = rows.length ? rows[0].created_at : 0;
      for (const [id, row] of state.rows) if (row.created_at >= oldest) state.rows.delete(id);
    }
    rows.forEach(row => state.rows.set(row.id, row));
    const ordered = Array.from(state.rows.values()).sort((a, b) => a.created_at - b.created_at || a.id.localeCompare(b.id));
    const fragment = document.createDocumentFragment();
    ordered.forEach(row => {
      const node = element("article", "message" + (row.user_id === state.me.id ? " own" : "")); node.dataset.postId = row.id;
      node.append(element("div", "avatar", row.moderator ? "客服" : row.name.slice(-2)));
      const body = element("div", "message-body"), meta = element("div", "meta"); meta.append(element("span", "name", displayName(row)));
      const date = element("time", "", new Date(row.created_at).toLocaleString("zh-CN", { month: "2-digit", day: "2-digit", hour: "2-digit", minute: "2-digit" })); date.dateTime = new Date(row.created_at).toISOString(); meta.append(date);
      const actions = element("div", "message-actions");
      actions.append(button("reply", "回复", () => { state.reply = row; state.pending = null; $("reply-text").textContent = "回复 " + displayName(row) + "：" + row.message; $("reply").hidden = false; $("message").focus(); }));
      if (state.me.moderator || row.user_id === state.me.id) actions.append(button("trash-2", "删除消息", async () => {
        if (!confirm("删除这条消息？")) return;
        try { await api("delete", { post_id: row.id }); state.rows.delete(row.id); await poll(true); } catch (e) { error(e.message); }
      }));
      if (state.me.moderator && row.user_id !== state.me.id) actions.append(button(row.muted ? "volume-x" : "volume-2", row.muted ? "当前已禁言，点击解除禁言" : "当前可发言，点击禁言", async () => {
        if (!confirm((row.muted ? "解除禁言：" : "禁言：") + displayName(row) + "？")) return;
        try { await api("mute", { target_id: row.user_id, muted: !row.muted }); await poll(true); } catch (e) { error(e.message); }
      }));
      meta.append(actions); body.append(meta);
      if (row.root_id) { const root = state.rows.get(row.root_id); body.append(element("div", "quote", root ? "回复 " + displayName(root) + "：" + (root.message || "图片") : "回复较早的消息")); }
      if (row.message) body.append(element("div", "message-text", row.message));
      if (row.file_ids && row.file_ids.length) {
        const images = element("div", "images");
        row.file_ids.forEach(id => {
          const thumb = element("button", "image-thumb"); thumb.title = "查看图片"; const img = element("img"); img.alt = "群聊图片"; thumb.append(img); images.append(thumb);
          imageURL(id).then(url => { img.src = url; thumb.onclick = () => { $("full-image").src = url; $("viewer").showModal(); }; }).catch(() => { thumb.textContent = "图片加载失败"; thumb.onclick = () => poll(true); });
        }); body.append(images);
      }
      node.append(body); fragment.append(node);
    });
    $("list").replaceChildren(fragment); $("empty").hidden = ordered.length > 0; icons();
    const added = rows.filter(row => !previous.has(row.id) && row.user_id !== state.me.id).length;
    if (reset && (stick || previous.size === 0)) bottom();
    else if (!reset) $("messages").scrollTop = oldTop + $("messages").scrollHeight - oldHeight;
    else if (added) { state.unread += added; $("unread").textContent = state.unread + " 条新消息"; $("unread").hidden = false; }
  }
  async function poll(force) {
    if (state.polling || !state.me || !state.active || (!force && document.hidden)) return;
    state.polling = true;
    try {
      const data = await api("history"); state.me.muted = data.muted;
      const signature = JSON.stringify(data.messages);
      if (signature !== state.signature) { display(data.messages, true); state.signature = signature; }
      $("older").hidden = !data.has_more; $("status").textContent = state.me.muted ? "当前已被禁言" : "已连接"; if (state.errorScope === "connection") error(""); controls(); read();
    } catch (e) { $("status").textContent = "连接中断"; error(e.message, "connection"); }
    finally { state.polling = false; }
  }
  function showAttachments() {
    $("attachments").replaceChildren();
    state.attachments.forEach(file => { const wrap = element("div", "attachment"), img = element("img"); img.src = file.url; img.alt = "待发送图片"; wrap.append(img, button("x", "移除图片", () => { if (state.busy || !state.active || !state.me || state.me.muted) return; state.attachments = state.attachments.filter(x => x !== file); URL.revokeObjectURL(file.url); state.pending = null; showAttachments(); })); $("attachments").append(wrap); }); icons(); controls();
  }
  function composerReady() {
    if (!state.active || !startingAuth || auth() !== startingAuth) { state.active = false; controls(); error("登录已变化，请返回面板重新进入"); return false; }
    return !!state.me && !state.me.muted && !state.busy;
  }
  async function uploadImages(files) {
    if (!files.length || !composerReady()) return;
    if (state.attachments.length >= 2) { error("最多添加 2 张图片，请先移除一张再上传"); return; }
    state.busy = true; state.operation = "upload"; controls(); error("");
    const failures = [];
    try {
      for (const file of files) {
        if (!state.active || auth() !== startingAuth) { state.active = false; failures.push("登录已变化，请返回面板重新进入"); break; }
        if (!state.me || state.me.muted) { failures.push("当前已被禁言，无法上传图片"); break; }
        if (state.attachments.length >= 2) { failures.push("最多添加 2 张图片，超出的图片未添加"); break; }
        if (file.size > 3 * 1024 * 1024 || !["image/png", "image/jpeg", "image/webp"].includes(file.type)) { failures.push("请选择 3 MB 以内的 PNG、JPEG 或 WebP 图片"); continue; }
        try {
          const data = new FormData(); data.append("image", file);
          const uploaded = await api("upload", data);
          if (!state.active || auth() !== startingAuth) { state.active = false; failures.push("登录已变化，请返回面板重新进入"); break; }
          if (!state.me || state.me.muted) { failures.push("当前已被禁言，无法继续添加图片"); break; }
          state.attachments.push({ id: uploaded.id, url: URL.createObjectURL(file) }); state.pending = null; showAttachments();
        } catch (e) { failures.push(e.message); }
      }
    } finally {
      state.busy = false; state.operation = null; controls();
      if (failures.length) error(Array.from(new Set(failures)).join("；"));
      if (!$("message").disabled) $("message").focus();
    }
  }
  $("message").addEventListener("input", () => { $("count").textContent = $("message").value.length + " / 2000"; state.pending = null; controls(); });
  $("message").addEventListener("compositionstart", () => { clearTimeout(compositionGuardTimer); composing = true; compositionEnterGuard = true; });
  $("message").addEventListener("compositionend", () => {
    composing = false; compositionEnterGuard = true;
    clearTimeout(compositionGuardTimer);
    // Protect a confirmation Enter delivered in this event turn, not the next deliberate keypress.
    compositionGuardTimer = setTimeout(() => { compositionEnterGuard = false; }, 0);
  });
  $("message").addEventListener("keyup", () => { if (!composing) { compositionEnterGuard = false; clearTimeout(compositionGuardTimer); } });
  $("message").addEventListener("keydown", event => {
    const enter = event.key === "Enter" || event.code === "Enter" || event.code === "NumpadEnter";
    if (composing || event.isComposing || event.keyCode === 229 || event.which === 229) return;
    if (!enter) { compositionEnterGuard = false; return; }
    if (event.ctrlKey || event.altKey || event.metaKey || event.shiftKey) return;
    if (compositionEnterGuard) { event.preventDefault(); return; }
    event.preventDefault();
    if (!event.repeat) sendMessage();
  });
  $("message").addEventListener("paste", event => {
    const clipboard = event.clipboardData; if (!clipboard) return;
    let files = Array.from(clipboard.items || []).filter(item => item.kind === "file").map(item => item.getAsFile()).filter(Boolean);
    if (!files.length) files = Array.from(clipboard.files || []);
    const images = files.filter(file => file.type.startsWith("image/"));
    if (!images.length) return;
    event.preventDefault(); uploadImages(images);
  });
  $("cancel-reply").onclick = () => { state.reply = null; state.pending = null; $("reply").hidden = true; };
  $("attach").onclick = () => $("file").click();
  $("file").onchange = () => {
    const files = Array.from($("file").files || []); $("file").value = ""; return uploadImages(files);
  };
  async function sendMessage() {
    if (!composerReady() || (!$("message").value.trim() && !state.attachments.length)) return;
    state.busy = true; state.operation = "post"; controls(); error("");
    const data = { message: $("message").value.trim(), root_id: state.reply ? state.reply.id : "", file_ids: state.attachments.map(file => file.id), client_id: state.pending || crypto.randomUUID() }; state.pending = data.client_id;
    try {
      const sent = await api("post", data); state.rows.set(sent.id, sent); state.signature = null; $("message").value = ""; $("count").textContent = "0 / 2000";
      state.attachments.forEach(file => URL.revokeObjectURL(file.url)); state.attachments = []; state.pending = null; state.reply = null; $("reply").hidden = true; showAttachments(); await poll(true); bottom();
    } catch (e) { error(e.message); } finally { state.busy = false; state.operation = null; controls(); if (!$("message").disabled) $("message").focus(); }
  }
  $("send").onclick = sendMessage;
  $("older").onclick = async () => {
    const rows = Array.from(state.rows.values()).sort((a, b) => a.created_at - b.created_at); if (!rows.length) return;
    $("older").disabled = true;
    try { const data = await api("history", { before: rows[0].id }); display(data.messages, false); $("older").hidden = !data.has_more; } catch (e) { error(e.message); }
    finally { $("older").disabled = false; }
  };
  $("refresh").onclick = () => { error(""); state.signature = null; connect(); };
  $("unread").onclick = bottom; $("messages").addEventListener("scroll", read);
  $("close-viewer").onclick = () => $("viewer").close();
  window.addEventListener("storage", () => { if (auth() !== startingAuth) { state.active = false; error("登录已变化，请返回面板重新进入"); controls(); } });
  document.addEventListener("visibilitychange", () => poll());
  window.addEventListener("pagehide", () => { state.active = false; state.urls.forEach(p => p.then(URL.revokeObjectURL).catch(() => {})); });
  icons();
  async function connect() {
    if (state.connecting || !state.active) return;
    state.connecting = true;
    try { const data = await api("bootstrap"); state.me = data.me; $("identity").textContent = displayName(state.me); controls(); error(""); await poll(true); }
    catch (e) { $("status").textContent = "暂不可用"; error(e.message, "connection"); }
    finally { state.connecting = false; }
  }
  setupScrollChaining();
  connect();
  setInterval(() => state.me ? poll() : connect(), 3500);
})();
