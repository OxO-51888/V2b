(function (N) {
  'use strict';
  const ui = {
    knowledge: { keyword: '', category: '' },
    ticket: { filter: 'all', page: 1 },
    node: { keyword: '', status: 'all', page: 1 },
    traffic: { page: 1 }
  };
  const pageSize = 10;
  const renderedDetails = { knowledge: null, ticket: null };
  function detailSnapshot(key, data) {
    const item = key === 'ticket' ? data.ticket : data.article;
    return { element: document.querySelector(key === 'ticket' ? '.ticket-detail' : '.knowledge-detail'), id: item ? String(item.id) : '', value: JSON.stringify(item || null) };
  }
  function patchView(key, route, data) {
    const selector = '.support-' + key + '-page';
    const current = document.querySelector('#page-content ' + selector);
    if (!current) return null;
    const template = document.createElement('template');
    template.innerHTML = N.pages[key].render(route, data);
    return { current: current, next: template.content.querySelector(selector) };
  }
  // Each page names its own changing regions. Decorative headers stay mounted.
  function replaceRegion(view, selector) {
    const current = view.current.querySelector(selector), next = view.next.querySelector(selector);
    if (current && next) current.replaceWith(next);
  }
  const finite = value => value !== null && value !== undefined && value !== '' && Number.isFinite(Number(value));
  const byteText = value => finite(value) ? N.bytes(Number(value)) : '—';
  const trafficAmount = value => {
    const parts = String(value).match(/^(.+?)\s*([KMGTPE]?B)$/);
    return '<span class="traffic-metric-value">' + N.e(parts ? parts[1] : value) + '</span>' + (parts ? ' <span class="traffic-metric-unit">' + N.e(parts[2]) + '</span>' : '');
  };
  const rateText = value => finite(value) ? N.e(Number.isInteger(Number(value)) ? Number(value).toFixed(1) : Number(value)) + ' ×' : '—';
  const list = value => Array.isArray(value) ? value : [];
  const link = (route, label, cls) => '<a class="button ' + N.e(cls || 'secondary') + '" href="#/' + N.e(route) + '">' + N.e(label) + '</a>';
  const refreshButton = () => N.button('刷新', 'support-refresh', '', 'secondary');
  const decor = (key, x, y, width, height, cls) => '<span class="support-decoration ' + cls + '" aria-hidden="true">' + (N.fragment ? N.fragment(key, x, y, width, height, '', {parallax:['knowledge-approved-banner','ticket-approved-banner','traffic-header-art'].includes(cls)}) : '') + '</span>';
  let artID = 0;
  function continuousArt(key, x, y, width, height, holes, cls, options = {}) {
    if (!N.fragment) return '';
    // Dynamic copy only replaces the text layer; the paper remains one image.
    const svg = N.fragment(key, x, y, width, height, '', { ...options, parallax:options.parallax || ['community-continuous-header','node-continuous-header'].includes(cls), hideLettering: holes });
    return '<span class="support-decoration ' + cls + '" aria-hidden="true">' + svg + '</span>';
  }
  function nodeStampFrame() {
    let scallops = '';
    for (let i = 0; i <= 18; i++) { const x = 6 + i * 221 / 18; scallops += '<circle cx="' + x + '" cy="6" r="6"/><circle cx="' + x + '" cy="96" r="6"/>'; }
    for (let i = 1; i < 8; i++) { const y = 6 + i * 90 / 8; scallops += '<circle cx="6" cy="' + y + '" r="6"/><circle cx="227" cy="' + y + '" r="6"/>'; }
    return '<svg class="node-stat-vector-frame" viewBox="0 0 233 102" aria-hidden="true"><g fill="#292629"><rect x="5" y="5" width="223" height="92" rx="7"/>' + scallops + '</g><rect x="13" y="12" width="207" height="78" rx="11" fill="none" stroke="#f48eb7" stroke-width="1" stroke-dasharray="6 5"/></svg>';
  }
  // Small line art is rendered as paths at the final CSS size, not resampled
  // from a 1x screenshot. Portraits, lettering and larger illustrations stay intact.
  function vectorArt(kind, cls) {
    const id = 'support-vector-' + (++artID);
    let width = 50, height = 55, body = '', definitions = '';
    if (kind === 'star') {
      width = 36; height = 37;
      const outline = 'M18 3.5Q19 3 20 5L23.5 12L31.5 13Q34 13.5 32 16L26 22L27.5 30.5Q28 33 25.5 31.8L18 28L10.5 31.8Q8 33 8.5 30.5L10 22L4 16Q2 13.5 4.5 13L12.5 12L16 5Q17 3 18 3.5Z';
      definitions = '<linearGradient id="' + id + '" x2="0" y2="1"><stop stop-color="#fff0b9"/><stop offset="1" stop-color="#ffe49b"/></linearGradient>';
      body = '<path d="' + outline + '" fill="none" stroke="#fff" stroke-width="4" stroke-linejoin="round"/><path d="' + outline + '" fill="url(#' + id + ')" stroke="#efac94" stroke-width="1.3" stroke-linejoin="round"/>';
    } else if (kind === 'server') {
      definitions = '<linearGradient id="' + id + '" x2="0" y2="1"><stop stop-color="#fff6fb"/><stop offset="1" stop-color="#ffc7df"/></linearGradient>';
      body = '<g fill="url(#' + id + ')" stroke="#eea4c1" stroke-width="1"><rect x="8.5" y="9.5" width="34" height="15" rx="3"/><rect x="8.5" y="29.5" width="34" height="15" rx="3"/></g><g fill="#d96b99"><circle cx="15" cy="17" r="1.8"/><circle cx="20" cy="17" r="1.2"/><circle cx="15" cy="37" r="1.8"/><circle cx="20" cy="37" r="1.2"/></g><path d="M33 17h5M33 37h5" stroke="#f7a6c8" stroke-width="1.5" stroke-linecap="round"/>';
    } else if (kind === 'wrench') {
      width = 65; height = 64;
      body = '<path d="M42 12C33 9 25 17 27 26L13 41C8 46 16 54 21 49L36 34C45 36 53 28 50 19L43 26L36 24L34 18Z" fill="#fff0f7" stroke="#ffc4dc" stroke-width="1.3" stroke-linejoin="round"/><circle cx="18" cy="45" r="2" fill="#e28ab0"/>';
    } else {
      const down = kind === 'down', total = kind === 'total';
      width = down ? 91 : total ? 87 : 85; height = down || total ? 91 : 88;
      const cx = width / 2, cy = height / 2, edge = down ? '#c8d4ff' : '#ffc1db', ink = down ? '#5360ff' : '#ff4c9b';
      definitions = '<radialGradient id="' + id + '" cx="35%" cy="25%" r="78%"><stop stop-color="#fff"/><stop offset=".55" stop-color="' + (down ? '#f4f6ff' : '#fff2f8') + '"/><stop offset="1" stop-color="' + (down ? '#dee6ff' : '#ffc3df') + '"/></radialGradient>';
      body = '<ellipse cx="' + cx + '" cy="' + cy + '" rx="' + (width / 2 - 2) + '" ry="' + (height / 2 - 2) + '" fill="#fff" stroke="' + edge + '" stroke-width="1"/><ellipse cx="' + cx + '" cy="' + cy + '" rx="' + (width / 2 - 5) + '" ry="' + (height / 2 - 5) + '" fill="url(#' + id + ')" stroke="' + edge + '" stroke-width="1.5"/>';
      const icon = total
        ? '<path d="M25 30V58C25 68 62 68 62 58V30M25 40C25 50 62 50 62 40M25 49C25 59 62 59 62 49"/><ellipse cx="43.5" cy="30" rx="18.5" ry="7"/>'
        : '<path d="M27 60C10 58 14 37 27 36C24 16 55 15 57 35C72 32 78 57 62 60"/><path d="' + (down ? 'M45 35V62M35 52 45 62 55 52' : 'M43 66V40M33 50 43 40 53 50') + '"/>';
      body += '<g fill="none" stroke="#fff" stroke-width="7" stroke-linecap="round" stroke-linejoin="round">' + icon + '</g><g fill="none" stroke="' + ink + '" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">' + icon + '</g>';
    }
    return '<span class="support-decoration ' + cls + '" aria-hidden="true"><svg class="support-vector-art" width="' + width + '" height="' + height + '" viewBox="0 0 ' + width + ' ' + height + '" focusable="false"><defs>' + definitions + '</defs>' + body + '</svg></span>';
  }
  const star = () => vectorArt('star', 'support-sticker-star');
  const heart = () => '<svg class="support-heart-art" viewBox="0 0 32 32" aria-hidden="true"><path d="M16 27C12 24 3 18 3 10C3 2 13 1 16 8C20 1 29 3 29 10C29 18 20 24 16 27Z" fill="#ff9cc9" stroke="#fff" stroke-width="5" stroke-linejoin="round"/><path d="M16 27C12 24 3 18 3 10C3 2 13 1 16 8C20 1 29 3 29 10C29 18 20 24 16 27Z" fill="#ff9cc9" stroke="#ff64a3" stroke-width="1.3" stroke-linejoin="round"/><path d="M7 12C6 7 11 5 13 10" fill="none" stroke="#ffdfef" stroke-width="2.2" stroke-linecap="round"/></svg>';
  const ribbon = text => {
    const original = text === '节点列表' ? [324, 323, 215, 68] : text === '当前订阅' ? [1136, 323, 218, 67] : null;
    return original ? '<h2 class="support-ribbon original-ribbon' + (text === '当前订阅' ? ' node-subscription-title' : '') + '">' + decor('node', ...original, 'original-ribbon-art') + '<span class="sr-only">' + N.e(text) + '</span></h2>' : '<h2 class="support-ribbon"><span>' + N.e(text) + '</span>' + star() + '</h2>';
  };
  function knowledgeIcon(title, category) {
    const key = String(title || '');
    if (category && /客户端|教程/.test(key)) return '<svg class="knowledge-platform-icon knowledge-book-icon" viewBox="0 0 40 36" aria-hidden="true"><path d="M3 5Q11 2 20 7Q29 2 37 5V31Q28 27 20 33Q12 27 3 31Z" fill="#fff" stroke="#ff4e98" stroke-width="1.4"/><path d="M6 7Q12 5 18.5 9V29Q12 25 6 27ZM21.5 9Q28 5 34 7V27Q28 25 21.5 29Z" fill="#ff4f9b"/><path d="M9 9Q13 9 16 11M25 11Q29 9 32 9" fill="none" stroke="#ffb7d6" stroke-width="2"/></svg>';
    if (category && /快速|开始|入门/.test(key)) return '<svg class="knowledge-platform-icon knowledge-rocket-icon" viewBox="0 0 37 37" aria-hidden="true"><path d="m13 17-7 2-3 8 10-1m7-3-1 11 8-5 1-8" fill="#ff86b7" stroke="#fff" stroke-width="2.5" stroke-linejoin="round"/><path d="M10 22C14 11 22 4 34 3C34 15 28 24 18 28Z" fill="#ff3c91" stroke="#fff" stroke-width="2"/><circle cx="26" cy="11" r="4" fill="#ffd7e9" stroke="#ff9ac5"/><path d="m12 26-5 8 9-5" fill="#ff79ae"/><path d="M17 17 23 11" stroke="#ffaad0" stroke-width="2" stroke-linecap="round"/></svg>';
    let rectangle = category ? /快速|开始|入门/.test(key) ? [330, 338, 37, 37] : /客户端|教程/.test(key) ? [329, 471, 40, 36] : null
      : /windows/i.test(key) ? [337, 532, 31, 32] : /mac(?:os)?|苹果电脑/i.test(key) ? [337, 590, 32, 32] : /\bios\b|iphone|ipad/i.test(key) ? [339, 647, 28, 31] : /android|安卓/i.test(key) ? [333, 700, 36, 34] : null;
    if (/windows/i.test(key) && !category) return '<svg class="knowledge-platform-icon" viewBox="0 0 31 32" aria-hidden="true"><path fill="currentColor" d="M3 6.2 14 4.7v10H3Zm12.5-1.7L28 2.8v11.9H15.5ZM3 16.2h11v10.2L3 24.8Zm12.5 0H28v12.9l-12.5-2.5Z"/></svg>';
    return rectangle ? decor('knowledge', ...rectangle, 'knowledge-nav-art knowledge-art-' + rectangle[2] + '-' + rectangle[3]) : N.icon(category ? 'book' : 'device');
  }
  function supportHeader(key, extra) {
    const brand = N.settings && N.settings.title || '';
    if (key === 'community') return '<header class="support-header support-community-header">' + continuousArt('community', 309, 87, 1206, 141, [], 'community-continuous-header', { background: 'surface' }) + '<div class="support-heading"><span class="support-chat-emblem"></span><div><div class="row wrap"><h1 class="community-original-title"><span class="sr-only">售后群聊</span></h1><span id="support-chat-status" class="support-chat-status" role="status">' + (window.NINI_PREVIEW ? '本地演示' : '正在连接') + '</span></div><p>' + (window.NINI_PREVIEW ? '演示消息仅显示在本页，不会发送到服务器～' : '在这里与客服团队交流，记录您的问题与回复～') + '</p></div>' + N.button(N.icon('refresh') + ' 刷新', 'community-reload', '', 'secondary') + '</div>' + '</header>';
    if (key === 'knowledge') return '<header class="support-header support-knowledge-header approved-art-header">' + decor('knowledge', 309, 103, 1206, 130, 'knowledge-approved-banner') + decor('knowledge', 1001, 86, 275, 17, 'knowledge-approved-overlap') + '<h1 class="sr-only">使用文档</h1>' + (brand !== 'Keke' ? '<span class="knowledge-seal-custom"><small>' + N.e(brand) + '</small><b>Docs</b></span>' : '') + '</header>';
    if (key === 'ticket') return '<header class="support-header support-ticket-header approved-art-header">' + decor('ticket', 309, 102, 1206, 191, 'ticket-approved-banner') + decor('ticket', 1037, 85, 389, 17, 'ticket-approved-overlap') + '<h1 class="sr-only">我的工单</h1></header>';
    if (key === 'node') return '<header class="support-header support-node-header">' + continuousArt('node', 309, 99, 1206, 220, [], 'node-continuous-header', { background: 'surface' }) + '<h1 class="sr-only">节点状态</h1>' + extra + '</header>';
    return '<header class="support-header support-traffic-header"><div class="traffic-heading"><h1 class="traffic-title">' + decor('traffic', 333, 126, 294, 60, 'traffic-title-art') + '<span class="sr-only">流量明细</span></h1><span class="traffic-period">' + N.icon('calendar') + ' 本月记录</span><p>查看每日流量使用情况，合理规划您的使用～</p></div><span class="traffic-ledger-stamp original-ledger-stamp">' + decor('traffic', 975, 108, 221, 104, 'traffic-ledger-art') + '<span class="sr-only">流量账单</span></span>' + decor('traffic', 1104, 94, 408, 314, 'traffic-header-art') + extra + '</header>';
  }
  function pages(items, state, action) {
    const count = Math.max(1, Math.ceil(items.length / pageSize));
    state.page = Math.min(Math.max(1, state.page), count);
    return {
      rows: items.slice((state.page - 1) * pageSize, state.page * pageSize),
      footer: items.length > pageSize ? '<div class="pagination"><span class="muted small">共 ' + items.length + ' 条</span><button class="secondary" data-action="' + action + '" data-page="' + (state.page - 1) + '"' + (state.page === 1 ? ' disabled' : '') + '>上一页</button><span>' + state.page + ' / ' + count + '</span><button class="secondary" data-action="' + action + '" data-page="' + (state.page + 1) + '"' + (state.page === count ? ' disabled' : '') + '>下一页</button></div>' : '<div class="support-count muted small">共 ' + items.length + ' 条</div>'
    };
  }
  function tabs(items, selected, action) {
    return '<div class="tabs support-tabs" role="group">' + items.map(item => '<button type="button" class="' + (selected === item[0] ? 'active' : '') + '" aria-pressed="' + (selected === item[0]) + '" data-action="' + action + '" data-value="' + N.e(item[0]) + '">' + N.e(item[1]) + '</button>').join('') + '</div>';
  }
  function communityEnabled() {
    return N.communityEnabled ? N.communityEnabled() : N.on((window.settings || {}).community_enabled);
  }
  const communityURL = () => window.NINI_PREVIEW === true ? 'assets/v1/preview-community.html' : '/community/index.html?client=nini&embedded=1&v=6';
  const ticketCreationEnabled = () => Number((window.settings || {}).ticket_status) !== 2;
  N.pages.community = {
    load: async function () { return { enabled: communityEnabled() }; },
    render: function (route, data) {
      return '<div class="support-page support-community-page">' + supportHeader('community') + (data.enabled
        ? '<section class="card community-card"><iframe id="nini-community" class="community-frame" title="站内售后群聊" src="' + N.e(communityURL()) + '" referrerpolicy="same-origin"></iframe><div id="nini-community-error" class="notice" hidden>群聊页面暂时无法载入，请重试或通过工单联系支持。</div></section>'
        : N.card('售后群聊', N.empty('当前站点尚未开启群聊') + link('ticket', '我的工单'))) + '</div>';
    },
    mounted: function () {
      const frame = document.getElementById('nini-community');
      if (!frame) return;
      const styleFrame = function () {
        // The existing same-origin chat owns authentication, polling and uploads.
        // Only its presentation is adapted; no chat controls or status are duplicated.
        try {
          const doc = frame.contentDocument;
          if (!doc || doc.location.href === 'about:blank') return;
          const error = document.getElementById('nini-community-error');
          if (!doc.getElementById('messages')) {
            if (error) error.hidden = false;
            return;
          }
          if (error) error.hidden = true;
          doc.body.classList.add('nini-community-skin');
          doc.body.dataset.accent = N.theme && N.theme.accent_color || 'sakura';
          if (!doc.getElementById('nini-chat-theme')) {
            // The embedded client enforces style-src 'self', so use a real same-origin
            // stylesheet rather than an inline style that its CSP would reject.
            const style = doc.createElement('link');
            style.id = 'nini-chat-theme'; style.rel = 'stylesheet';
            const parentStyle = document.querySelector('link[href*="/assets/v1/support.css"]');
            style.href = parentStyle ? parentStyle.href : new URL((N.settings.assets_path || '/theme/NINI/assets') + '/v1/support.css', window.location.href).href;
            doc.head.appendChild(style);
          }
          if (!window.NINI_PREVIEW) {
            const source = doc.getElementById('status'), target = document.getElementById('support-chat-status');
            if (source && target) {
              const sync = () => { target.textContent = source.textContent; target.classList.toggle('connected', /已连接|连接正常/.test(source.textContent)); };
              sync(); const observer = new MutationObserver(sync); observer.observe(source, { childList: true, subtree: true, characterData: true });
              frame.contentWindow.addEventListener('unload', () => observer.disconnect(), { once: true });
            }
          }
        } catch (_) { /* The iframe remains functional when styling is unavailable. */ }
      };
      frame.addEventListener('load', styleFrame);
      frame.addEventListener('error', function () {
        const error = document.getElementById('nini-community-error');
        if (error) error.hidden = false;
      });
      styleFrame();
    }
  };
  N.actions['community-reload'] = function () {
    const frame = document.getElementById('nini-community');
    const error = document.getElementById('nini-community-error');
    if (error) error.hidden = true;
    if (frame) frame.src = communityURL();
  };

  N.pages.knowledge = {
    prefetch: async function (route) {
      const version = N.accountVersion(), keyword = ui.knowledge.keyword;
      const [response] = await Promise.all([
        N.api.get('/user/knowledge/fetch', { language: 'zh-CN', keyword: keyword }),
        route.id ? N.api.get('/user/knowledge/fetch', { id: route.id }) : null
      ]);
      if (N.accountVersion() !== version || ui.knowledge.keyword !== keyword || route.id) return;
      const raw = N.data(response) || {};
      const articles = Array.isArray(raw) ? raw : Object.keys(raw).filter(key => Array.isArray(raw[key])).flatMap(key => raw[key]);
      if (articles[0]) await N.api.get('/user/knowledge/fetch', { id: articles[0].id });
    },
    load: async function (route) {
      const [response, detailResponse] = await Promise.all([
        N.api.get('/user/knowledge/fetch', { language: 'zh-CN', keyword: ui.knowledge.keyword }),
        route.id ? N.api.get('/user/knowledge/fetch', { id: route.id }) : null
      ]);
      const raw = N.data(response) || {};
      const groups = Array.isArray(raw) ? raw.reduce(function (result, article) {
        const category = article.category || '使用文档';
        (result[category] || (result[category] = [])).push(article);
        return result;
      }, Object.create(null)) : raw;
      const categories = Object.keys(groups).filter(key => Array.isArray(groups[key]));
      if (ui.knowledge.category && !categories.includes(ui.knowledge.category)) ui.knowledge.category = '';
      const articles = categories.flatMap(key => groups[key]);
      const id = route.id || (articles[0] && articles[0].id);
      const article = route.id ? N.data(detailResponse) : id ? N.data(await N.api.get('/user/knowledge/fetch', { id: id })) : null;
      return { groups: groups, categories: categories, articles: articles, article: article };
    },
    render: function (route, data) {
      const article = data.article;
      const categories = ui.knowledge.category ? [ui.knowledge.category] : data.categories;
      const search = '<form data-form="knowledge-search" class="inline-form support-search"><label class="sr-only" for="knowledge-keyword">搜索文档</label><input id="knowledge-keyword" class="input" name="keyword" type="search" placeholder="搜索文档" value="' + N.e(ui.knowledge.keyword) + '"><button type="submit" class="secondary" aria-label="搜索文档">' + N.icon('search') + '</button></form>';
      const navigation = categories.map(name => '<section class="knowledge-group"><h3>' + knowledgeIcon(name, true) + N.e(name) + '</h3>' + list(data.groups[name]).map(item => '<a class="knowledge-link' + (article && String(item.id) === String(article.id) ? ' selected' : '') + '" href="#/knowledge/' + encodeURIComponent(item.id) + '"' + (article && String(item.id) === String(article.id) ? ' aria-current="page"' : '') + '><span class="knowledge-link-icon">' + knowledgeIcon(item.title, false) + '</span><span>' + N.e(item.title) + '</span><span aria-hidden="true">›</span></a>').join('') + '</section>').join('');
      const body = article ? '<div class="card-head knowledge-article-head"><div class="row wrap">' + star() + '<h2>' + N.e(article.title) + '</h2><span class="support-outline-heart">' + heart() + '</span></div><div class="knowledge-article-meta">' + (article.updated_at ? '<span>更新于 ' + N.date(article.updated_at) + '</span>' : '') + (article.category ? '<span class="knowledge-category-stamp">' + N.e(article.category) + '</span>' : '') + '</div><span class="support-handwriting knowledge-article-note">Easy<br>Secure<br>Wonderful ! ♡</span></div><div class="knowledge-article-surface" role="region" aria-label="教程正文" tabindex="0"><div class="article knowledge-article">' + N.rich(article.body || '') + '</div><div class="knowledge-article-tail"><div class="support-article-footer notice">' + N.icon('bell') + '<span>如遇问题，可前往 <a href="#/ticket">我的工单</a> 提交问题。</span></div>' + continuousArt('knowledge', 1183, 735, 323, 242, [[1183, 883, 24, 73]], 'knowledge-reader-art') + (N.settings.title !== 'Keke' ? '<span class="knowledge-book-label">' + N.e(N.settings.title || '') + '</span>' : '') + '</div></div>' : '<div class="card-body">' + N.empty(ui.knowledge.keyword ? '没有找到相关文档，请更换关键词' : '暂无可查看的文档') + '</div>';
      return '<div class="support-page support-knowledge-page">' + supportHeader('knowledge') + '<div class="knowledge-layout"><aside class="card knowledge-nav">' + search + (navigation || N.empty('暂无文档')) + '</aside><article class="card knowledge-detail">' + body + '</article></div></div>';
    },
    mounted: function (route, data) {
      renderedDetails.knowledge = detailSnapshot('knowledge', data);
      const select = document.querySelector('[data-support-category]');
      if (select) select.addEventListener('change', function () { ui.knowledge.category = select.value; N.renderCurrent(); });
    },
    patch: function (route, data) {
      const previous = renderedDetails.knowledge;
      if (!previous || previous.element !== document.querySelector('.knowledge-detail')) return false;
      const view = patchView('knowledge', route, data);
      if (!view) return false;
      const input = view.current.querySelector('#knowledge-keyword');
      const focused = input === document.activeElement;
      const selection = input ? [input.selectionStart, input.selectionEnd] : null;
      replaceRegion(view, '.knowledge-nav');
      if (previous.value !== JSON.stringify(data.article || null)) replaceRegion(view, '.knowledge-detail');
      N.pages.knowledge.mounted(route, data);
      if (focused) {
        const nextInput = view.current.querySelector('#knowledge-keyword');
        nextInput.focus({ preventScroll: true });
        if (selection) nextInput.setSelectionRange(...selection);
      }
      return true;
    }
  };
  N.forms['knowledge-search'] = async function (form, values) {
    ui.knowledge.keyword = String(values.keyword || '').trim();
    ui.knowledge.category = '';
    if (N.route().id) N.go('knowledge');
    else await N.refresh();
  };

  N.pages.node = {
    load: async function () { return { nodes: N.list(await N.api.get('/user/server/fetch')) }; },
    render: function (route, data) {
      const rows = data.nodes.filter(function (node) {
        const term = ui.node.keyword.toLocaleLowerCase();
        const matches = !term || [node.name].concat(list(node.tags)).join(' ').toLocaleLowerCase().includes(term);
        return matches && (ui.node.status === 'all' || (ui.node.status === 'online' ? N.on(node.is_online) : node.is_online != null && !N.on(node.is_online)));
      });
      const flag = node => {
        const name = [node.name].concat(list(node.tags)).join(' ');
        const countries = [['香港|Hong.?Kong','hk'],['日本|Japan','jp'],['新加坡|Singapore','sg'],['美国|United States|USA','us'],['德国|Germany','de'],['台湾|Taiwan','tw'],['韩国|Korea','kr'],['英国|United Kingdom','gb'],['法国|France','fr'],['加拿大|Canada','ca'],['澳大利亚|Australia','au']];
        const country = countries.find(item => new RegExp(item[0], 'i').test(name));
        return country ? '<img class="node-flag" alt="" src="' + N.e((N.settings.assets_path || '/theme/NINI/assets') + '/flags/' + country[1] + '.svg') + '">' : '<span class="node-emblem" aria-hidden="true">' + N.icon('pin') + '</span>';
      };
      const table = rows.length ? '<div class="table-wrap node-table"><table><thead><tr><th>节点名称</th><th>在线状态</th><th>扣费倍率</th></tr></thead><tbody>' + rows.map(node => '<tr><td><div class="row">' + flag(node) + '<span>' + N.e(node.name) + '</span></div></td><td>' + (node.is_online == null ? N.badge('未知') : '<span class="badge node-status ' + (N.on(node.is_online) ? 'success' : 'warning') + '"><i></i>' + (N.on(node.is_online) ? '在线' : '离线') + '</span>') + '</td><td><span class="node-rate">' + rateText(node.rate) + '</span></td></tr>').join('') + '</tbody></table></div>' : N.empty(data.nodes.length ? '没有符合条件的节点' : '暂无可用节点，请确认订阅状态');
      const sub = N.state.sub || {};
      const plan = sub.plan || {};
      const user = N.state.user || {};
      const expiry = sub.expired_at !== undefined ? sub.expired_at : user.expired_at;
      const quota = sub.transfer_enable !== undefined ? sub.transfer_enable : user.transfer_enable;
      const used = finite(sub.u) && finite(sub.d) ? Number(sub.u) + Number(sub.d) : finite(user.u) && finite(user.d) ? Number(user.u) + Number(user.d) : null;
      const hasPlan = plan.id || sub.plan_id || user.plan_id;
      const planSummary = (N.frame ? N.frame('node', 1120, 334, 394, 533, 15, 'node-subscription-frame') : '') + ribbon('当前订阅') + '<div class="node-plan-copy"><h3>' + decor('node', 1227, 390, 59, 54, 'node-plan-crown') + '<span class="node-plan-name" title="' + N.e(plan.name || '暂无订阅') + '">' + N.e(plan.name || '暂无订阅') + '</span>' + '</h3><p>' + (hasPlan ? expiry === null ? '长期有效' : N.date(expiry) + ' 到期' : '选一个喜欢的套餐吧') + '</p><a href="#/plan" class="button primary">' + N.icon('cart') + ' 购买订阅 ' + N.icon('arrow') + '</a></div><div class="node-travel-scene">' + decor('node', 1127, 549, 379, 318, 'node-travel-original') + '</div>';
      const online = data.nodes.filter(node => N.on(node.is_online)).length, offline = data.nodes.filter(node => node.is_online != null && !N.on(node.is_online)).length;
      const stats = '<div class="node-status-stamps"><button class="node-stat-stamp" data-action="node-status" data-value="' + (ui.node.status === 'online' ? 'all' : 'online') + '">' + nodeStampFrame() + vectorArt('server', 'node-stat-art') + '<span>可用节点<strong>' + online + '</strong></span>' + star() + '</button><button class="node-stat-stamp" data-action="node-status" data-value="' + (ui.node.status === 'offline' ? 'all' : 'offline') + '">' + nodeStampFrame() + vectorArt('wrench', 'node-stat-art') + '<span>离线节点<strong>' + offline + '</strong></span>' + star() + '</button></div>';
      const tools = '<div class="row node-table-tools">' + N.button(N.icon('search'), 'node-search-open', 'aria-label="搜索节点"', 'icon-button') + N.button(N.icon('refresh'), 'support-refresh', 'aria-label="刷新节点"', 'icon-button') + '</div>';
      return '<div class="support-page support-node-page">' + supportHeader('node', stats) + '<div class="support-node-layout"><section class="card node-list-card"><div class="node-list-heading">' + ribbon(ui.node.status === 'all' ? '节点列表' : ui.node.status === 'online' ? '在线节点' : '离线节点') + tools + '</div>' + table + '</section><aside class="card support-subscription" title="' + N.e('已用流量 ' + byteText(used) + ' / ' + byteText(quota)) + '">' + planSummary + '</aside></div><div class="node-help-banner">' + continuousArt('node', 313, 854, 1200, 138, [[402, 886, 440, 70]], 'node-help-continuous') + '<div><h3><span>' + N.e(N.settings.title || '') + '</span> <strong>小贴士</strong></h3><p>如遇节点异常，请稍后重试，或通过' + (communityEnabled() ? '<a href="#/community">售后群聊</a>' : '<a href="#/ticket">我的工单</a>') + '获取帮助～</p></div>' + '</div></div>';
    }
  };
  N.actions['node-search-open'] = function () { N.modal('搜索节点', '<form data-form="node-search" class="stack"><label class="field"><span>节点名称或标签</span><input class="input" name="keyword" type="search" value="' + N.e(ui.node.keyword) + '" placeholder="搜索节点或标签"></label><button class="primary" type="submit">搜索</button></form>'); };
  N.forms['node-search'] = function (form, values) { ui.node.keyword = String(values.keyword || '').trim(); ui.node.page = 1; N.closeModal(); N.renderCurrent(); };
  N.pages.node.patch = function (route, data) {
    const view = patchView('node', route, data);
    if (!view) return false;
    replaceRegion(view, '.node-status-stamps');
    replaceRegion(view, '.node-list-card');
    // A server refresh may also change the current subscription summary.
    const current = view.current.querySelector('.support-subscription');
    const next = view.next.querySelector('.support-subscription');
    current.title = next.title;
    const currentCopy = current.querySelector('.node-plan-copy'), nextCopy = next.querySelector('.node-plan-copy');
    if (currentCopy.textContent !== nextCopy.textContent) currentCopy.replaceWith(nextCopy);
    return true;
  };
  N.actions['node-status'] = function (element) { ui.node.status = element.dataset.value; ui.node.page = 1; N.renderCurrent(); };
  N.actions['node-page'] = function (element) { ui.node.page = Number(element.dataset.page) || 1; N.renderCurrent(); };

  function ticketStatus(ticket) {
    return Number(ticket.status) === 1 ? ['closed', '已关闭', ''] : N.on(ticket.reply_status) ? ['replied', '已回复', 'success'] : ['open', '处理中', 'warning'];
  }
  function ticketDetail(ticket) {
    if (!ticket) return N.empty('选择一条工单查看对话，或新建工单');
    const messages = list(ticket.message);
    const closed = Number(ticket.status) === 1;
    const waiting = messages.length && N.on(messages[messages.length - 1].is_me);
    const status = ticketStatus(ticket);
    const header = '<div class="card-head ticket-detail-heading"><div class="row between wrap"><div class="row wrap"><h2>' + N.e(ticket.subject) + '</h2>' + N.badge(status[1], status[2]) + '</div>' + (!closed ? N.button(N.icon('close') + ' 关闭工单', 'ticket-close', 'data-id="' + N.e(ticket.id) + '"', 'secondary') : '') + '</div><p class="ticket-meta">' + N.icon('calendar') + ' #' + N.e(ticket.id) + (ticket.created_at ? '<span>│</span>' + N.icon('clock') + ' 创建时间 ' + N.date(ticket.created_at, true) : '') + '</p></div>';
    const conversation = '<div class="chat-messages support-ticket-messages" aria-label="工单消息">' + (messages.length ? messages.map(function (message) {
      const mine = N.on(message.is_me);
      const avatar = mine ? decor('ticket', 887, 444, 75, 74, 'ticket-avatar-art') : decor('ticket', 887, 565, 77, 74, 'ticket-avatar-art');
      return '<div class="ticket-message' + (mine ? ' me' : '') + '"><span class="ticket-avatar" aria-hidden="true">' + avatar + '</span><div class="ticket-message-content"><div class="row wrap small"><strong>' + (mine ? '我' : '客服') + '</strong><time class="muted">' + N.date(message.created_at, true) + '</time></div><div class="bubble ticket-message-text">' + N.e(message.message) + '<span class="ticket-message-heart" aria-hidden="true">' + heart() + '</span></div></div></div>';
    }).join('') : N.empty('暂无消息')) + '</div>';
    const composer = '<form data-form="ticket-reply" class="ticket-composer"><input type="hidden" name="id" value="' + N.e(ticket.id) + '"><label class="sr-only" for="ticket-reply-message">回复内容</label><textarea id="ticket-reply-message" class="input" name="message" placeholder="' + (closed ? '此工单已关闭。' : waiting ? '消息已提交，请等待客服回复。' : '输入回复内容…') + '" rows="3" required' + (closed || waiting ? ' disabled' : '') + '></textarea><div class="row between">' + (waiting && !closed ? refreshButton() : '<span></span>') + '<button type="submit" class="primary"' + (closed || waiting ? ' disabled' : '') + '><svg class="icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M21 2 2 10l7 3 3 8 9-19ZM9 13 21 2 13 16l-1 5Z"/></svg> 提交回复</button></div></form>';
    return header + conversation + '<div class="card-body">' + composer + '</div>';
  }
  N.pages.ticket = {
    prefetch: function () { return N.api.get('/user/ticket/fetch'); },
    load: async function (route) {
      const version = N.accountVersion();
      const [response, detailResponse] = await Promise.all([
        N.api.get('/user/ticket/fetch'),
        route.id ? N.api.get('/user/ticket/fetch', { id: route.id }) : null
      ]);
      if (N.accountVersion() !== version) return null;
      const tickets = N.list(response);
      const id = route.id || (tickets[0] && tickets[0].id);
      const ticket = route.id ? N.data(detailResponse) : id ? N.data(await N.api.get('/user/ticket/fetch', { id: id })) : null;
      if (N.accountVersion() !== version) return null;
      return { tickets: tickets, ticket: ticket };
    },
    render: function (route, data) {
      const selected = data.ticket;
      const filtered = data.tickets.filter(ticket => ui.ticket.filter === 'all' || ticketStatus(ticket)[0] === ui.ticket.filter);
      const page = pages(filtered, ui.ticket, 'ticket-page');
      const rows = page.rows.map(function (ticket) {
        const status = ticketStatus(ticket);
        const levels = ['低', '中', '高'];
        return '<a class="ticket-list-row' + (selected && String(selected.id) === String(ticket.id) ? ' selected' : '') + '" href="#/ticket/' + encodeURIComponent(ticket.id) + '"' + (selected && String(selected.id) === String(ticket.id) ? ' aria-current="page"' : '') + '><strong>' + N.e(ticket.subject) + '</strong><span>' + N.badge(levels[Number(ticket.level)] || '—', Number(ticket.level) > 0 ? 'warning' : '') + '</span><span>' + N.badge(status[1], status[2]) + '</span><time class="small">' + N.date(ticket.updated_at || ticket.created_at) + '</time><span aria-hidden="true">›</span></a>';
      }).join('');
      const creationNotice = !ticketCreationEnabled() ? '<p class="muted small">站点当前未开放新建工单</p>' : Number((window.settings || {}).ticket_status) === 1 ? '<p class="muted small">仅已购买用户可发起工单</p>' : '';
      const count = Math.max(1, Math.ceil(filtered.length / pageSize));
      const pagination = '<div class="ticket-pagination"><button class="secondary" data-action="ticket-page" data-page="' + (ui.ticket.page - 1) + '" aria-label="上一页"' + (ui.ticket.page === 1 ? ' disabled' : '') + '>‹</button><span>' + ui.ticket.page + '</span><button class="secondary" data-action="ticket-page" data-page="' + (ui.ticket.page + 1) + '" aria-label="下一页"' + (ui.ticket.page === count ? ' disabled' : '') + '>›</button></div>';
      return '<div class="support-page support-ticket-page">' + supportHeader('ticket') + '<div class="support-ticket-layout"><section class="card ticket-list"><div class="card-head ticket-list-head"><div class="row between wrap"><h2><span class="ticket-list-icon">' + decor('ticket', 335, 318, 53, 54, 'ticket-list-original-icon') + '</span>工单记录 ' + star() + '</h2>' + (ticketCreationEnabled() ? N.button('＋ 新建工单', 'ticket-create') : '') + '</div>' + creationNotice + tabs([['all', '全部'], ['open', '处理中'], ['replied', '已回复'], ['closed', '已关闭']], ui.ticket.filter, 'ticket-filter') + '</div><div class="ticket-list-body"><div class="ticket-list-row ticket-list-heading"><span>标题</span><span>级别</span><span>状态</span><span>更新时间</span><span></span></div>' + (rows || N.empty('暂无此类工单')) + '</div>' + pagination + '</section><section class="card ticket-detail">' + ticketDetail(selected) + '</section></div></div>';
    }
  };
  N.pages.ticket.mounted = function (route, data) { renderedDetails.ticket = detailSnapshot('ticket', data); };
  N.pages.ticket.patch = function (route, data) {
    const previous = renderedDetails.ticket;
    if (!previous || previous.element !== document.querySelector('.ticket-detail')) return false;
    const view = patchView('ticket', route, data);
    if (!view) return false;
    // Rebuild this small header as well so configured creation limits stay current.
    replaceRegion(view, '.ticket-list-head');
    replaceRegion(view, '.ticket-list-body');
    replaceRegion(view, '.ticket-pagination');
    if (previous.value !== JSON.stringify(data.ticket || null)) {
      const editor = view.current.querySelector('[data-form="ticket-reply"] textarea');
      const sameTicket = previous.id === (data.ticket ? String(data.ticket.id) : '');
      const draft = editor && sameTicket ? { value: editor.value, start: editor.selectionStart, end: editor.selectionEnd, focused: editor === document.activeElement, scroll: editor.scrollTop } : null;
      replaceRegion(view, '.ticket-detail');
      const nextEditor = view.current.querySelector('[data-form="ticket-reply"] textarea');
      if (draft && nextEditor && !nextEditor.disabled) {
        nextEditor.value = draft.value;
        nextEditor.setSelectionRange(draft.start, draft.end);
        nextEditor.scrollTop = draft.scroll;
        if (draft.focused) nextEditor.focus({ preventScroll: true });
      }
    }
    renderedDetails.ticket = detailSnapshot('ticket', data);
    return true;
  };
  N.actions['ticket-filter'] = function (element) { ui.ticket.filter = element.dataset.value; ui.ticket.page = 1; N.renderCurrent(); };
  N.actions['ticket-page'] = function (element) { ui.ticket.page = Number(element.dataset.page) || 1; N.renderCurrent(); };
  N.actions['ticket-create'] = function () {
    if (!ticketCreationEnabled()) throw new Error('站点当前未开放新建工单');
    N.modal('新建工单', '<form data-form="ticket-create" class="stack">' + N.field('工单标题', '<input class="input" name="subject" placeholder="简要描述您遇到的问题" required>') + N.field('紧急程度', '<select class="input" name="level"><option value="0">低</option><option value="1">中</option><option value="2">高</option></select>') + N.field('问题详情', '<textarea class="input" name="message" rows="6" placeholder="请描述问题、发生时间与已尝试的步骤" required></textarea>') + '<button class="primary" type="submit">提交工单</button></form>');
  };
  N.forms['ticket-create'] = async function (form, values) {
    if (!ticketCreationEnabled()) throw new Error('站点当前未开放新建工单');
    const subject = String(values.subject || '').trim();
    const message = String(values.message || '').trim();
    if (!subject || !message) throw new Error('请填写工单标题和问题详情');
    await N.api.post('/user/ticket/save', { subject: subject, level: Number(values.level), message: message });
    N.closeModal();
    N.toast('工单已提交');
    ui.ticket.filter = 'all'; ui.ticket.page = 1;
    if (N.route().id) { await N.refreshCore(); N.go('ticket'); } else await N.refresh();
  };
  N.forms['ticket-reply'] = async function (form, values) {
    const message = String(values.message || '').trim();
    if (!message) throw new Error('请填写回复内容');
    await N.api.post('/user/ticket/reply', { id: values.id, message: message });
    // Successful replies are no longer an unsent draft during the data refresh.
    const editor = form.querySelector('textarea[name="message"]');
    if (editor) editor.value = '';
    N.toast('回复已提交');
    await N.refresh();
  };
  N.actions['ticket-close'] = function (element) {
    const id = element.dataset.id;
    N.confirm('关闭工单', '关闭后将不能继续回复这条工单。确认问题已处理完成？', async function () {
      await N.api.post('/user/ticket/close', { id: id });
      N.toast('工单已关闭');
      await N.refresh();
    });
  };

  N.pages.traffic = {
    load: async function () { return { records: N.list(await N.api.get('/user/stat/getTrafficLog')) }; },
    render: function (route, data) {
      const records = data.records;
      const sums = records.reduce(function (total, item) {
        if (finite(item.u)) total.u += Number(item.u);
        if (finite(item.d)) total.d += Number(item.d);
        if (finite(item.u) && finite(item.d) && finite(item.server_rate)) total.billed += (Number(item.u) + Number(item.d)) * Number(item.server_rate);
        else total.incomplete = true;
        return total;
      }, { u: 0, d: 0, billed: 0, incomplete: false });
      const metrics = '<div class="traffic-metrics">' + [['实际上行', N.bytes(sums.u), 'up'], ['实际下行', N.bytes(sums.d), 'down'], ['计费流量', sums.incomplete ? '—' : N.bytes(sums.billed), 'total']].map((item, index) => '<section class="traffic-metric traffic-metric-' + item[2] + '"><span class="traffic-metric-icon original-metric-icon" aria-hidden="true">' + vectorArt(item[2], 'traffic-medallion-art') + '</span><div><span class="traffic-metric-label">' + item[0] + '</span><strong>' + trafficAmount(item[1]) + '</strong><span class="traffic-metric-badge">' + (window.NINI_PREVIEW ? '演示数据' : '本月合计') + '</span></div></section>').join('') + '</div>';
      const table = records.length ? '<div class="table-wrap traffic-table"><table><thead><tr><th>记录时间</th><th>实际上行</th><th>实际下行</th><th>扣费倍率</th><th>计费流量</th></tr></thead><tbody>' + records.map(function (record) {
        const billed = finite(record.u) && finite(record.d) && finite(record.server_rate) ? (Number(record.u) + Number(record.d)) * Number(record.server_rate) : null;
        return '<tr><td>' + N.date(record.record_at) + '</td><td>' + byteText(record.u) + '</td><td>' + byteText(record.d) + '</td><td><span class="node-rate' + (Number(record.server_rate) > 1 ? ' multiplied' : '') + '">' + rateText(record.server_rate) + '</span></td><td>' + byteText(billed) + '</td></tr>';
      }).join('') + '</tbody></table></div>' : N.empty('本月暂无流量记录');
      return '<div class="support-page support-traffic-page">' + supportHeader('traffic', metrics) + '<section class="card traffic-table-card" aria-label="本月流量记录">' + table + '</section><div class="traffic-help"><strong>' + decor('traffic', 333, 871, 64, 52, 'traffic-tip-icon') + ' 小贴士</strong><p>流量按节点倍率计费。</p>' + decor('traffic', 1310, 862, 185, 70, 'traffic-tip-writing') + '' + N.button(N.icon('refresh'), 'support-refresh', 'aria-label="刷新流量记录"', 'icon-button') + '</div></div>';
    }
  };
  N.pages.traffic.patch = function (route, data) {
    const view = patchView('traffic', route, data);
    if (!view) return false;
    const amounts = view.next.querySelectorAll('.traffic-metric strong');
    view.current.querySelectorAll('.traffic-metric strong').forEach((amount, index) => { amount.replaceChildren(...Array.from(amounts[index].childNodes, child => child.cloneNode(true))); });
    replaceRegion(view, '.traffic-table-card');
    return true;
  };
  N.actions['traffic-page'] = function (element) { ui.traffic.page = Number(element.dataset.page) || 1; N.renderCurrent(); };
  N.actions['support-refresh'] = function () { return N.refresh(); };
})(window.Nini);
