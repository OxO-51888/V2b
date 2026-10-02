(function () {
  'use strict';
  const params = new URLSearchParams(location.search);
  const nini = params.get('client') === 'nini';
  document.body.dataset.theme = nini ? 'nini' : 'default';
  if (params.has('embedded')) document.body.dataset.embedded = '1';
  const auth = () => (nini ? localStorage.getItem('nini_auth_data') || localStorage.getItem('auth_data') : localStorage.getItem('authorization')) || '';
  const initialAuth = auth(), requests = new Set();
  const $ = id => document.getElementById(id);
  let active = true, page = 1, sequence = 0, loading = false, total = 0;
  const pageSize = 3;
  function node(tag, className, text) {
    const el = document.createElement(tag);
    if (className) el.className = className;
    if (text !== undefined) el.textContent = text;
    return el;
  }
  function icon(name) { const el = node('i'); el.dataset.lucide = name; return el; }
  function icons() { if (window.lucide) window.lucide.createIcons(); }
  function message(target, text) { target.replaceChildren(node('p', 'resource-empty', text)); }
  function safeURL(value) {
    try {
      if (typeof value !== 'string' || !value.trim()) return '';
      const url = new URL(value.trim(), location.origin);
      return ['https:', 'http:'].includes(url.protocol) && !url.username && !url.password ? url.href : '';
    } catch (_) { return ''; }
  }
  async function get(path) {
    if (!active || !initialAuth || auth() !== initialAuth) throw new Error('请登录后查看');
    const controller = new AbortController(), timer = setTimeout(() => controller.abort(), 12000);
    requests.add(controller);
    try {
      const response = await fetch('/api/v1' + path, {headers: {Authorization: initialAuth, Accept: 'application/json'}, cache: 'no-store', signal: controller.signal});
      if (!response.ok) throw new Error([401, 403].includes(response.status) ? '请重新登录后查看' : '暂时无法加载，请稍后重试');
      const data = await response.json();
      if (!active || auth() !== initialAuth) throw new Error('登录已变化，请重新进入');
      return data;
    } finally { clearTimeout(timer); requests.delete(controller); }
  }
  // Link readable domains only after announcement HTML has been cleaned.
  function linkNoticeDomains(root) {
    const label = '[\\p{L}\\p{N}](?:[\\p{L}\\p{N}-]{0,61}[\\p{L}\\p{N}])?';
    const domain = '(?:' + label + '\\.)+[\\p{L}](?:[\\p{L}\\p{N}-]{0,61}[\\p{L}\\p{N}])';
    const tail = '[^\\s<>"\\u0000-\\u0020，。；：！？、…“”‘’（）【】《》]*';
    const pattern = new RegExp('https?:\\/\\/' + tail + '|' + domain + '(?::\\d{1,5})?(?:[/?#]' + tail + ')?', 'giu');
    const skipped = new Set(['A', 'CODE', 'PRE']);
    function visit(parent) {
      for (const child of Array.from(parent.childNodes)) {
        if (child.nodeType === 1) { if (!skipped.has(child.tagName)) visit(child); continue; }
        if (child.nodeType !== 3) continue;
        const text = child.textContent, fragment = document.createDocumentFragment();
        let cursor = 0, linked = false;
        pattern.lastIndex = 0;
        let match;
        while ((match = pattern.exec(text))) {
          const start = match.index, end = start + match[0].length;
          if ((start && /[\p{L}\p{N}_@＠/:\\-]/u.test(text[start - 1])) || /[@＠_\p{L}\p{N}-]/u.test(text[end] || '')) continue;
          let value = match[0].replace(/[.,;:!?]+$/g, '');
          for (const [open, close] of [['(', ')'], ['[', ']'], ['{', '}']]) {
            while (value.endsWith(close) && value.split(close).length > value.split(open).length) value = value.slice(0, -1);
          }
          const href = safeURL(/^https?:\/\//i.test(value) ? value : 'https://' + value);
          if (!href) continue;
          const link = document.createElement('a');
          link.href = href; link.target = '_blank'; link.rel = 'noopener noreferrer'; link.textContent = value;
          fragment.append(document.createTextNode(text.slice(cursor, start)), link);
          cursor = start + value.length; linked = true;
        }
        if (linked) { fragment.append(document.createTextNode(text.slice(cursor))); child.replaceWith(fragment); }
      }
    }
    visit(root);
  }
  // Retain readable announcement formatting; never insert server HTML into the live page.
  function noticeContent(value) {
    const template = document.createElement('template');
    const source = String(value || '').slice(0, 30000);
    template.innerHTML = window.marked && typeof window.marked.parse === 'function' ? window.marked.parse(source, {breaks: true, gfm: true}) : source;
    const allowed = new Set(['P', 'DIV', 'SPAN', 'BR', 'STRONG', 'B', 'EM', 'I', 'U', 'UL', 'OL', 'LI', 'BLOCKQUOTE', 'PRE', 'CODE', 'H2', 'H3', 'H4', 'A']);
    const blocked = new Set(['SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'EMBED', 'SVG', 'MATH', 'TEMPLATE', 'FORM', 'INPUT', 'BUTTON']);
    function copy(source, target) {
      for (const child of source.childNodes) {
        if (child.nodeType === 3) { target.append(document.createTextNode(child.textContent)); continue; }
        if (child.nodeType !== 1 || child.namespaceURI !== 'http://www.w3.org/1999/xhtml') continue;
        const tag = child.tagName.toUpperCase();
        if (blocked.has(tag)) continue;
        if (tag === 'IMG') { if (child.getAttribute('alt')) target.append(document.createTextNode(child.getAttribute('alt'))); continue; }
        if (!allowed.has(tag)) { copy(child, target); continue; }
        const clean = document.createElement(tag.toLowerCase());
        if (tag === 'A') {
          const href = safeURL(child.getAttribute('href'));
          if (href) { clean.href = href; clean.target = '_blank'; clean.rel = 'noopener noreferrer'; }
        }
        copy(child, clean); target.append(clean);
      }
    }
    const fragment = document.createDocumentFragment(); copy(template.content, fragment); linkNoticeDomains(fragment); return fragment;
  }
  function pagination() {
    $('notice-prev').disabled = loading || page <= 1;
    $('notice-next').disabled = loading || page * pageSize >= total;
    $('notice-page').textContent = total ? page + ' / ' + Math.ceil(total / pageSize) : '最新公告';
    $('notice-page').parentElement.hidden = !loading && total <= pageSize;
    $('notice-reload').disabled = loading;
  }
  async function loadNotices(nextPage = 1) {
    const id = ++sequence; loading = true; pagination();
    $('notice-list').setAttribute('aria-busy', 'true');
    try {
      const result = await get('/user/notice/fetch?community=1&current=' + nextPage + '&pageSize=' + pageSize);
      if (id !== sequence || !active) return;
      const rows = Array.isArray(result.data) ? result.data : [];
      page = nextPage; total = Math.max(rows.length, Number(result.total) || 0);
      if (!rows.length && page > 1) return loadNotices(Math.max(1, Math.ceil(total / pageSize)));
      const fragment = document.createDocumentFragment();
      rows.forEach(item => {
        const article = node('article', 'notice-item'), heading = node('header', 'notice-title-row');
        heading.append(node('h3', 'notice-title', String(item.title || '站点公告')));
        const date = new Date(Number(item.created_at) * 1000);
        if (!Number.isNaN(date.getTime())) { const time = node('time', '', date.toLocaleDateString('zh-CN')); time.dateTime = date.toISOString(); heading.append(time); }
        const content = node('div', 'notice-content'); content.append(noticeContent(item.content));
        article.append(heading, content); fragment.append(article);
      });
      $('notice-list').replaceChildren(fragment);
      $('notice-list').scrollTop = 0;
      if (!rows.length) message($('notice-list'), '暂时没有新公告，有更新会在这里告诉您。');
    } catch (error) {
      if (active && id === sequence) message($('notice-list'), initialAuth ? '公告暂时无法加载，点击右上角重试。' : '登录后可查看本站公告。');
    } finally {
      if (id === sequence) { loading = false; $('notice-list').setAttribute('aria-busy', 'false'); pagination(); }
    }
  }
  async function loadClients() {
    try {
      const downloads = $('client-list').dataset;
      const config = {windows_download_url: downloads.windowsDownloadUrl, windows_version: downloads.windowsVersion};
      if (!active) return;
      const fragment = document.createDocumentFragment();
      [['windows', 'Windows', 'monitor'], ['macos', 'macOS', 'laptop'], ['android', 'Android', 'smartphone']].forEach(([key, name, symbol]) => {
        const href = safeURL(config[key + '_download_url']);
        const card = node('section', 'client-platform client-platform-' + key + (href ? ' is-available' : ' is-unavailable'));
        card.setAttribute('aria-label', name + ' 客户端');
        const head = node('div', 'client-platform-head');
        const image = node('span', 'client-platform-icon'); image.append(icon(symbol));
        const text = node('div', 'client-platform-copy'); text.append(node('strong', 'client-platform-name', name));
        if (href) text.append(node('small', 'client-platform-version', config[key + '_version'] ? '版本 ' + String(config[key + '_version']) : '官方客户端'));
        head.append(image, text); card.append(head);
        if (href) {
          const action = node('a', 'client-platform-action');
          action.href = href; action.target = '_blank'; action.rel = 'noopener noreferrer';
          action.setAttribute('aria-label', '下载本站 ' + name + ' 客户端');
          action.append(icon('download'), node('span', '', key === 'windows' ? '下载 Windows 版' : '下载'));
          card.append(action);
        } else {
          card.append(node('p', 'client-platform-state', key === 'windows' ? '暂未提供' : '敬请期待'));
        }
        fragment.append(card);
      });
      $('client-list').replaceChildren(fragment);
      if (!$('client-list').children.length) message($('client-list'), '本站客户端正在准备中，可先查看安装教程。');
      icons();
    } catch (_) { if (active) message($('client-list'), initialAuth ? '客户端下载暂时无法加载，请刷新页面重试。' : '登录后可查看本站客户端。'); }
  }
  $('notice-reload').addEventListener('click', () => loadNotices(page));
  $('notice-prev').addEventListener('click', () => { if (!loading && page > 1) loadNotices(page - 1); });
  $('notice-next').addEventListener('click', () => { if (!loading && page * pageSize < total) loadNotices(page + 1); });
  function stop() { active = false; sequence++; requests.forEach(request => request.abort()); requests.clear(); }
  window.addEventListener('pagehide', stop);
  window.addEventListener('storage', () => {
    if (auth() !== initialAuth) { stop(); message($('notice-list'), '登录已变化，请重新进入。'); message($('client-list'), '登录已变化，请重新进入。'); }
  });
  icons(); loadNotices(); loadClients();
})();
