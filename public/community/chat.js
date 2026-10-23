(function () {
  "use strict";
  const $ = id => document.getElementById(id);
  const state = { me: null, rows: new Map(), urls: new Map(), attachments: [], reply: null, busy: false, operation: null, polling: false, pending: null, failedSend: null, inFlight: null, retentionCutoff: 0, unread: 0, readAt: 0, active: true };
  const nini = new URLSearchParams(location.search).get("client") === "nini";
  if (new URLSearchParams(location.search).has("embedded")) document.querySelector('.chat-header > a').hidden = true;
  const auth = () => (nini ? localStorage.getItem("nini_auth_data") || localStorage.getItem("auth_data") : localStorage.getItem("authorization")) || "";
  const startingAuth = auth();
  let composing = false, compositionEnterGuard = false, compositionGuardTimer = 0;
  const renderedRows = new Map(), requests = new Set(), unseen = new Set();
  const messageDate = new Intl.DateTimeFormat("zh-CN", { month: "2-digit", day: "2-digit", hour: "2-digit", minute: "2-digit" });
  let epoch = 0, suspended = false, pollTimer = 0, readTimer = 0, failures = 0, mutationRevision = 0;
  Object.assign(state, { paged: false, hasMore: false, viewed: 0, acknowledged: 0, reading: false });
  function icons(root = document) {
    root.querySelectorAll('i[data-lucide]').forEach(node => {
      const name = node.dataset.lucide, key = name.replace(/(^|-)(\w)/g, (_, dash, letter) => letter.toUpperCase());
      const definition = lucide.icons[key]; if (!definition) return;
      const [tag, attrs, children] = definition;
      node.replaceWith(lucide.createElement([tag, { ...attrs, "data-lucide": name, class: "lucide lucide-" + name, "aria-hidden": "true" }, children]));
    });
  }
  function error(message, scope) { state.errorScope = scope || "action"; $("error").textContent = message || ""; $("error").hidden = !message; }
  function controls() {
    const disabled = !composerInputReady() || state.busy;
    $("message").disabled = !composerInputReady(); $("attach").disabled = disabled || state.attachments.length >= 2;
    $("send").disabled = disabled || !!state.failedSend || (!$("message").value.trim() && !state.attachments.length);
    $("send").querySelector("span").textContent = state.busy ? state.operation === "upload" ? "上传中" : "发送中" : "发送";
    $("send").dataset.state = state.busy ? state.operation === "upload" ? "uploading" : "sending" : "idle";
    $("send").setAttribute("aria-busy", String(state.busy));
    $("attachments").querySelectorAll("button").forEach(node => { node.disabled = disabled; });
    showFailedSend();
    if (state.me && state.me.muted) $("status").textContent = "当前已被禁言";
  }
  async function api(action, data) {
    if (!auth() || auth() !== startingAuth || !state.active) { deactivateComposer(); throw new Error("登录已变化，请返回面板重新进入"); }
    if (suspended) throw Object.assign(new Error("页面已暂停，返回后可重试"), { stale: true });
    const requestEpoch = epoch;
    const controller = new AbortController(), timer = setTimeout(() => controller.abort(), 25000);
    requests.add(controller);
    function current() {
      if (auth() !== startingAuth) deactivateComposer();
      if (requestEpoch !== epoch || !state.active || suspended) throw Object.assign(new Error("页面或登录已变化，请返回后重试"), { stale: true });
    }
    try {
      const multipart = data instanceof FormData;
      const response = await fetch("/api/v1/user/community/" + action, {
        method: "POST", headers: Object.assign({ Authorization: auth(), Accept: "application/json" }, multipart ? {} : { "Content-Type": "application/json" }),
        body: multipart ? data : JSON.stringify(data || {}), signal: controller.signal, cache: "no-store"
      });
      current();
      if (response.status === 401) {
        deactivateComposer();
        const message = "登录已失效，请返回面板重新登录后进入群聊";
        $("status").textContent = "登录已失效"; error(message, "session");
        throw Object.assign(new Error(message), { stale: true, status: 401 });
      }
      let result;
      try { result = await response.json(); } catch (_) { throw new Error("群聊暂时不可用，请稍后重试"); }
      current();
      if (!response.ok) throw new Error(result.message || "操作失败，请稍后重试");
      return result.data;
    } catch (e) {
      if (e.name === "AbortError") {
        if (requestEpoch !== epoch || suspended || !state.active) throw Object.assign(new Error("请求已暂停，返回后可重试"), { stale: true });
        throw new Error("请求超时，可重试；重复提交不会重复发送");
      }
      throw e;
    }
    finally { clearTimeout(timer); requests.delete(controller); }
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
  function unreadBadge() {
    state.unread = unseen.size;
    $("unread").hidden = !state.unread && !state.loadingLatest;
    $("unread").textContent = state.loadingLatest ? "正在加载最新消息…" : state.unread + " 条新消息 · 查看最新";
    $("unread").disabled = !!state.loadingLatest;
  }
  async function read(explicit = false) {
    if (!state.me || !state.active || suspended || document.hidden || (!explicit && !document.hasFocus()) || !nearBottom()) return;
    let viewed = state.viewed;
    for (const row of state.rows.values()) viewed = Math.max(viewed, row.created_at);
    state.viewed = viewed; unseen.clear(); unreadBadge();
    if (!viewed || viewed <= state.acknowledged || state.reading) return;
    const remaining = 5000 - (Date.now() - state.readAt);
    if (remaining > 0) { if (!readTimer) readTimer = setTimeout(() => { readTimer = 0; read(); }, remaining); return; }
    state.readAt = Date.now(); state.reading = true;
    const requestEpoch = epoch;
    try { await api("read"); if (requestEpoch === epoch) state.acknowledged = Math.max(state.acknowledged, viewed); }
    catch (_) { /* Local seen state is independent of the read receipt; retry below. */ }
    finally {
      state.reading = false;
      if (state.active && !suspended && state.viewed > state.acknowledged && !readTimer) readTimer = setTimeout(() => { readTimer = 0; read(); }, 5000);
    }
  }
  function bottom(explicit = false) { $("messages").scrollTop = $("messages").scrollHeight; read(explicit); }
  async function viewLatest() {
    if (state.loadingLatest || !state.me || !state.active || suspended) return;
    const requestEpoch = epoch;
    bottom(true); state.loadingLatest = true; unreadBadge();
    try { await poll(true); if (requestEpoch === epoch && state.active) bottom(true); }
    finally { if (requestEpoch === epoch) { state.loadingLatest = false; unreadBadge(); } }
  }
  function trimImages() {
    const referenced = new Set();
    for (const row of state.rows.values()) for (const id of row.file_ids || []) referenced.add(id);
    for (const [id, pending] of state.urls) if (!referenced.has(id)) {
      state.urls.delete(id);
      pending.then(url => {
        if ($("full-image").getAttribute("src") === url) { $("viewer").close(); $("full-image").removeAttribute("src"); }
        URL.revokeObjectURL(url);
      }).catch(() => {});
    }
  }
  async function imageURL(id) {
    if (!state.urls.has(id)) {
      const pending = api("file", { file_id: id }).then(file => {
        if (!["image/png", "image/jpeg", "image/webp"].includes(file.mime)) throw new Error("图片格式不支持");
        if (state.urls.get(id) !== pending) throw new Error("图片已移除");
        return URL.createObjectURL(new Blob([Uint8Array.from(atob(file.data), c => c.charCodeAt(0))], { type: file.mime }));
      }).catch(e => { if (state.urls.get(id) === pending) state.urls.delete(id); throw e; });
      state.urls.set(id, pending);
    }
    return state.urls.get(id);
  }
  const imageJobs = new Map();
  let imageObserver = null, activeImages = 0;
  function drainImages() {
    if (suspended || !state.active) return;
    for (const [thumb, job] of imageJobs) {
      if (activeImages >= 4) break;
      if (!job.visible || job.started || !thumb.isConnected) continue;
      job.started = true; activeImages++; const requestEpoch = epoch;
      imageURL(job.id).then(url => {
        if (!thumb.isConnected || imageJobs.get(thumb) !== job) return;
        if (requestEpoch !== epoch) { job.started = false; return; }
        job.img.src = url; thumb.replaceChildren(job.img);
        thumb.onclick = () => { $("full-image").src = url; $("viewer").showModal(); };
        imageObserver?.unobserve(thumb); imageJobs.delete(thumb);
      }).catch(e => {
        if (!thumb.isConnected || imageJobs.get(thumb) !== job) return;
        if (e.stale) { job.started = false; return; }
        thumb.textContent = "图片加载失败，点击重试";
        thumb.onclick = () => { job.started = false; job.visible = true; drainImages(); };
      }).finally(() => { activeImages--; drainImages(); });
    }
  }
  function lazyImage(thumb, img, id) {
    if (!imageObserver) imageObserver = new IntersectionObserver(entries => {
      entries.forEach(entry => { const job = imageJobs.get(entry.target); if (job) job.visible = entry.isIntersecting; });
      drainImages();
    }, { root: $("messages"), rootMargin: "240px" });
    imageJobs.set(thumb, { id, img, visible: false, started: false }); imageObserver.observe(thumb);
  }
  function releaseRendered(node) {
    node.querySelectorAll('.image-thumb').forEach(thumb => { imageObserver?.unobserve(thumb); imageJobs.delete(thumb); });
  }
  function displayName(member) { return member.moderator ? "客服小姐姐" : member.name; }
  function pruneExpiredRows(cutoff) {
    if (typeof cutoff === "number" && Number.isFinite(cutoff) && cutoff > 0) state.retentionCutoff = Math.max(state.retentionCutoff || 0, cutoff);
    let changed = false;
    if (state.retentionCutoff) for (const [id, row] of state.rows) if (row.created_at < state.retentionCutoff) { state.rows.delete(id); changed = true; }
    if (changed) trimImages();
    return changed;
  }
  function display(rows, reset, cutoff) {
    pruneExpiredRows(cutoff);
    rows = rows.filter(row => !state.retentionCutoff || !(row.created_at < state.retentionCutoff));
    const stick = nearBottom(), oldHeight = $("messages").scrollHeight, oldTop = $("messages").scrollTop;
    const previous = new Set(state.rows.keys());
    if (reset) {
      // Only explicit history pagination retains older windows during a long session.
      const oldest = rows.length ? rows[0].created_at : 0;
      if (!state.paged) state.rows.clear();
      else for (const [id, row] of state.rows) if (row.created_at >= oldest) state.rows.delete(id);
    }
    rows.forEach(row => state.rows.set(row.id, row));
    const ordered = Array.from(state.rows.values()).sort((a, b) => a.created_at - b.created_at || a.id.localeCompare(b.id));
    const liveIds = new Set(ordered.map(row => row.id));
    for (const id of unseen) if (!liveIds.has(id)) unseen.delete(id);
    for (const [id, rendered] of renderedRows) if (!liveIds.has(id)) {
      releaseRendered(rendered.node); rendered.node.remove(); renderedRows.delete(id);
    }
    let cursor = $("list").firstChild;
    ordered.forEach(row => {
      const root = row.root_id ? state.rows.get(row.root_id) : null;
      const signature = JSON.stringify([row, root && [displayName(root), root.message], state.me.id, state.me.moderator]);
      const existing = renderedRows.get(row.id);
      if (existing?.signature === signature) {
        if (existing.node !== cursor) $("list").insertBefore(existing.node, cursor);
        cursor = existing.node.nextSibling; return;
      }
      const node = element("article", "message" + (row.user_id === state.me.id ? " own" : "") + (row.moderator === true ? " moderator-message" : "")); node.dataset.postId = row.id;
      node.append(element("div", "avatar", row.moderator ? "客服" : row.name.slice(-2)));
      const body = element("div", "message-body"), meta = element("div", "meta"); meta.append(element("span", "name", displayName(row)));
      if (row.moderator !== true) {
        const date = element("time", "", messageDate.format(row.created_at)); date.dateTime = new Date(row.created_at).toISOString(); meta.append(date);
      }
      const actions = element("div", "message-actions");
      actions.append(button("reply", "回复", () => { state.reply = row; state.pending = null; $("reply-text").textContent = "回复 " + displayName(row) + "：" + row.message; $("reply").hidden = false; $("message").focus(); }));
      if (state.me.moderator || row.user_id === state.me.id) actions.append(button("trash-2", "删除消息", async () => {
        if (!confirm("删除这条消息？")) return;
        try { await api("delete", { post_id: row.id }); mutationRevision++; state.rows.delete(row.id); display([], false); await poll(true); } catch (e) { if (!e.stale) error(e.message); }
      }));
      if (state.me.moderator && row.user_id !== state.me.id) actions.append(button(row.muted ? "volume-x" : "volume-2", row.muted ? "当前已禁言，点击解除禁言" : "当前可发言，点击禁言", async () => {
        if (!confirm((row.muted ? "解除禁言：" : "禁言：") + displayName(row) + "？")) return;
        try { await api("mute", { target_id: row.user_id, muted: !row.muted }); mutationRevision++; await poll(true); } catch (e) { if (!e.stale) error(e.message); }
      }));
      meta.append(actions); body.append(meta);
      if (row.root_id) body.append(element("div", "quote", root ? "回复 " + displayName(root) + "：" + (root.message || "图片") : "回复较早的消息"));
      if (row.message) body.append(element("div", "message-text", row.message));
      if (row.file_ids && row.file_ids.length) {
        const images = element("div", "images");
        row.file_ids.forEach(id => {
          const thumb = element("button", "image-thumb"); thumb.title = "查看图片"; const img = element("img"); img.alt = "群聊图片"; img.decoding = "async"; thumb.append(img); images.append(thumb);
          lazyImage(thumb, img, id);
        }); body.append(images);
      }
      node.append(body); icons(node);
      if (existing) {
        releaseRendered(existing.node);
        if (existing.node === cursor) cursor = existing.node.nextSibling;
        existing.node.remove();
      }
      $("list").insertBefore(node, cursor); cursor = node.nextSibling;
      renderedRows.set(row.id, { node, signature });
    });
    $("empty").hidden = ordered.length > 0; trimImages();
    if (reset && previous.size) for (const row of rows) if (!previous.has(row.id) && row.user_id !== state.me.id && row.created_at > state.viewed) unseen.add(row.id);
    unreadBadge();
    if (reset && (stick || previous.size === 0)) bottom();
    else if (!reset) $("messages").scrollTop = oldTop + $("messages").scrollHeight - oldHeight;
    else $("messages").scrollTop = oldTop;
  }
  function schedulePoll(delay = 3500) {
    clearTimeout(pollTimer); pollTimer = 0;
    if (!state.active || suspended || document.hidden) return;
    pollTimer = setTimeout(() => { pollTimer = 0; state.me ? poll() : connect(); }, delay);
  }
  async function poll(force) {
    if (!state.me || !state.active || suspended || (!force && document.hidden)) return;
    if (state.polling) { if (force) state.refreshQueued = true; return state.pollPromise; }
    state.polling = true; clearTimeout(pollTimer); pollTimer = 0;
    state.pollPromise = (async () => {
      try {
        do {
          state.refreshQueued = false;
          const revision = mutationRevision, data = await api("history");
          if (revision !== mutationRevision) { state.refreshQueued = true; continue; }
          state.me.muted = data.muted;
          const expired = pruneExpiredRows(data.retention_cutoff), signature = JSON.stringify([data.messages, state.me.id, state.me.moderator]);
          if (expired || signature !== state.signature) { display(data.messages, true); state.signature = signature; }
          if (!state.paged) state.hasMore = data.has_more;
          $("older").hidden = !state.hasMore; $("status").textContent = state.me.muted ? "当前已被禁言" : "已连接";
          failures = 0; if (state.errorScope === "connection") error(""); controls(); read();
        } while (state.refreshQueued && state.active && !suspended);
      } catch (e) {
        if (!e.stale && state.active) { failures++; $("status").textContent = "连接中断，正在重试"; error(e.message, "connection"); }
      } finally {
        state.polling = false; state.pollPromise = null;
        schedulePoll(state.refreshQueued ? 0 : failures ? Math.min(30000, 3500 * 2 ** Math.min(failures, 3)) : 3500);
      }
    })();
    return state.pollPromise;
  }
  function showAttachments() {
    $("attachments").replaceChildren();
    state.attachments.forEach(file => { const wrap = element("div", "attachment"), img = element("img"); img.src = file.url; img.alt = "待发送图片"; wrap.append(img, button("x", "移除图片", () => { if (state.busy || !state.active || !state.me || state.me.muted) return; state.attachments = state.attachments.filter(x => x !== file); URL.revokeObjectURL(file.url); state.pending = null; showAttachments(); })); $("attachments").append(wrap); }); icons(); controls();
  }
  function composerInputReady() { return !!state.me && !state.me.muted && state.active && !suspended && !!startingAuth && auth() === startingAuth; }
  function composerReady() {
    if (!state.active || !startingAuth || auth() !== startingAuth) { deactivateComposer(); error("登录已变化，请返回面板重新进入"); return false; }
    return composerInputReady() && !state.busy;
  }
  async function uploadImages(files) {
    if (!files.length || !composerReady()) return;
    if (state.attachments.length >= 2) { error("最多添加 2 张图片，请先移除一张再上传"); return; }
    state.busy = true; state.operation = "upload"; controls(); error("");
    const failures = [];
    try {
      for (const file of files) {
        if (!state.active || auth() !== startingAuth) { deactivateComposer(); failures.push("登录已变化，请返回面板重新进入"); break; }
        if (!state.me || state.me.muted) { failures.push("当前已被禁言，无法上传图片"); break; }
        if (state.attachments.length >= 2) { failures.push("最多添加 2 张图片，超出的图片未添加"); break; }
        if (file.size > 3 * 1024 * 1024 || !["image/png", "image/jpeg", "image/webp"].includes(file.type)) { failures.push("请选择 3 MB 以内的 PNG、JPEG 或 WebP 图片"); continue; }
        try {
          const data = new FormData(); data.append("image", file);
          const uploaded = await api("upload", data);
          if (!state.active || auth() !== startingAuth) { deactivateComposer(); failures.push("登录已变化，请返回面板重新进入"); break; }
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
  function updateDraft() { $("count").textContent = $("message").value.length + " / 2000"; state.pending = null; controls(); }
  $("message").addEventListener("input", updateDraft);
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
    event.preventDefault();
    if (!composerInputReady()) return;
    const text = clipboard.getData ? clipboard.getData("text/plain").replace(/\r\n?/g, "\n") : "";
    if (text) {
      const input = $("message"), start = input.selectionStart, end = input.selectionEnd;
      const limit = input.maxLength >= 0 ? input.maxLength : 2000;
      const room = Math.max(0, limit - input.value.length + end - start);
      let insertion = text.slice(0, room);
      if (/[\uD800-\uDBFF]$/.test(insertion)) insertion = insertion.slice(0, -1);
      if (insertion) { input.setRangeText(insertion, start, end, "end"); updateDraft(); }
    }
    if (state.busy) { error(state.operation === "post" ? "消息正在发送，请稍后再添加图片" : "图片正在上传，请稍后再添加"); return; }
    uploadImages(images);
  });
  $("cancel-reply").onclick = () => { state.reply = null; state.pending = null; $("reply").hidden = true; };
  $("attach").onclick = () => $("file").click();
  $("file").onchange = () => {
    const files = Array.from($("file").files || []); $("file").value = ""; return uploadImages(files);
  };
  function releaseSubmission(submission) {
    if (!submission) return;
    submission.attachments.forEach(file => URL.revokeObjectURL(file.url)); submission.attachments = [];
  }
  function deactivateComposer() {
    if (!state.active) return;
    state.active = false;
    stopTransport();
    releaseSubmission(state.inFlight); releaseSubmission(state.failedSend); state.failedSend = null;
    state.attachments.forEach(file => URL.revokeObjectURL(file.url)); state.attachments = [];
    state.rows.clear(); trimImages(); renderedRows.clear(); unseen.clear(); imageObserver?.disconnect(); imageJobs.clear();
    $("list").replaceChildren(); $("empty").hidden = false;
    $("message").value = ""; $("count").textContent = "0 / 2000"; unreadBadge();
    $("viewer").close(); $("full-image").removeAttribute("src");
    state.reply = null; state.pending = null; $("reply").hidden = true; showAttachments();
  }
  function stopTransport() {
    epoch++; clearTimeout(pollTimer); pollTimer = 0; clearTimeout(readTimer); readTimer = 0;
    state.loadingLatest = false;
    requests.forEach(controller => controller.abort());
  }
  function showFailedSend() {
    let panel = $("failed-send");
    if (!panel && !state.failedSend) return;
    if (!panel) {
      panel = element("section", "failed-send"); panel.id = "failed-send"; panel.setAttribute("aria-label", "待重试消息");
      const copy = element("div", "failed-send-copy"), status = element("strong"), preview = element("p");
      status.id = "failed-send-status"; status.setAttribute("role", "status"); preview.id = "failed-send-preview"; copy.append(status, preview);
      const actions = element("div", "failed-send-actions"), retry = element("button", "", "重试发送"), discard = element("button", "", "不再重试");
      retry.id = "retry-send"; retry.type = "button"; discard.id = "discard-send"; discard.type = "button";
      retry.onclick = () => { if (state.failedSend) postSubmission(state.failedSend); };
      discard.onclick = () => { if (state.busy) return; releaseSubmission(state.failedSend); state.failedSend = null; controls(); };
      actions.append(retry, discard); panel.append(copy, actions); $("message").before(panel);
    }
    const submission = state.failedSend; panel.hidden = !submission;
    if (!submission) return;
    $("failed-send-status").textContent = "上一条未确认发送：" + (submission.error || "请重试");
    $("failed-send-preview").textContent = (submission.data.message || "图片消息") + (submission.data.file_ids.length ? "（" + submission.data.file_ids.length + " 张图片）" : "") + " · 下方草稿已保留";
    $("retry-send").disabled = !composerInputReady() || state.busy; $("discard-send").disabled = state.busy;
  }
  async function postSubmission(submission) {
    if (!composerReady() || submission.user !== state.me.id) return;
    const current = () => state.active && auth() === startingAuth && state.me && submission.user === state.me.id;
    state.inFlight = submission; state.busy = true; state.operation = "post"; controls(); error("");
    $("message").focus();
    try {
      const sent = await api("post", submission.data); if (!current()) return;
      mutationRevision++;
      if (state.failedSend === submission) state.failedSend = null;
      releaseSubmission(submission); state.rows.set(sent.id, sent); state.signature = null; display([], false); bottom();
      // History refresh runs independently so its latency cannot lock the next draft.
      poll(true);
    } catch (e) { if (current()) { submission.error = e.message; state.failedSend = submission; } }
    finally {
      if (state.inFlight === submission) { state.inFlight = null; state.busy = false; state.operation = null; controls(); }
      if (!current()) { deactivateComposer(); releaseSubmission(submission); }
    }
  }
  async function sendMessage() {
    if (!composerReady() || state.failedSend || (!$("message").value.trim() && !state.attachments.length)) return;
    const submission = { user: state.me.id, attachments: state.attachments.slice(),
      data: { message: $("message").value.trim(), root_id: state.reply ? state.reply.id : "", file_ids: state.attachments.map(file => file.id), client_id: state.pending || crypto.randomUUID() } };
    $("message").value = ""; $("count").textContent = "0 / 2000";
    state.attachments = []; state.pending = null; state.reply = null; $("reply").hidden = true; showAttachments();
    return postSubmission(submission);
  }
  $("send").onclick = sendMessage;
  $("older").onclick = async () => {
    const rows = Array.from(state.rows.values()).sort((a, b) => a.created_at - b.created_at); if (!rows.length) return;
    const revision = mutationRevision;
    $("older").disabled = true;
    try {
      const data = await api("history", { before: rows[0].id });
      if (revision !== mutationRevision) { error("消息已更新，请再次加载更早消息"); return; }
      state.paged = true; state.hasMore = data.has_more;
      display(data.messages, false, data.retention_cutoff); $("older").hidden = !state.hasMore;
    } catch (e) { if (!e.stale) error(e.message); }
    finally { $("older").disabled = false; }
  };
  $("refresh").onclick = () => { error(""); state.signature = null; connect(); };
  $("unread").onclick = viewLatest; $("messages").addEventListener("scroll", () => read(), { passive: true });
  $("close-viewer").onclick = () => $("viewer").close();
  window.addEventListener("storage", () => { if (auth() !== startingAuth) { deactivateComposer(); error("登录已变化，请返回面板重新进入"); } });
  function resume() {
    if (!state.active) return;
    if (auth() !== startingAuth) { deactivateComposer(); error("登录已变化，请返回面板重新进入"); return; }
    suspended = false; state.readAt = 0; state.refreshQueued = true; controls(); unreadBadge(); drainImages();
    state.me ? poll(true) : connect();
  }
  document.addEventListener("visibilitychange", () => { if (document.hidden) { clearTimeout(pollTimer); pollTimer = 0; } else resume(); });
  window.addEventListener("online", resume);
  window.addEventListener("focus", () => { read(); });
  window.addEventListener("pagehide", event => {
    if (event.persisted) { suspended = true; stopTransport(); }
    else deactivateComposer();
  });
  window.addEventListener("pageshow", event => { if (event.persisted) resume(); });
  icons();
  async function connect() {
    if (state.connecting || !state.active || suspended) return;
    state.connecting = true; state.refreshQueued = false; clearTimeout(pollTimer); pollTimer = 0;
    try { const data = await api("bootstrap"); mutationRevision++; state.me = data.me; $("identity").textContent = displayName(state.me); controls(); error(""); await poll(true); }
    catch (e) { if (!e.stale && state.active) { failures++; $("status").textContent = "暂不可用，正在重试"; error(e.message, "connection"); } }
    finally { state.connecting = false; schedulePoll(state.refreshQueued ? 0 : failures ? Math.min(30000, 3500 * 2 ** Math.min(failures, 3)) : 3500); }
  }
  setupScrollChaining();
  connect();
})();
