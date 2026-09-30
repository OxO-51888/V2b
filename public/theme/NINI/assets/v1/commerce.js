(function () {
  'use strict';
  var N = window.Nini;
  var periodNames = {
    month_price: '月付', quarter_price: '季付', half_year_price: '半年付', year_price: '年付',
    two_year_price: '两年付', three_year_price: '三年付', onetime_price: '一次性', reset_price: '重置流量', deposit: '余额充值'
  };
  var periods = Object.keys(periodNames).filter(function (key) { return key !== 'deposit'; });
  function orderPeriodName(period) {
    // Older orders may use billing labels instead of the current plan price keys.
    var aliases = {
      monthly: 'month_price', quarterly: 'quarter_price', half_yearly: 'half_year_price', yearly: 'year_price',
      two_yearly: 'two_year_price', three_yearly: 'three_year_price', onetime: 'onetime_price', reset: 'reset_price'
    };
    return periodNames[aliases[period] || period] || period;
  }
  var orderStatuses = { 0: ['待支付', 'pending'], 1: ['开通中', 'pending'], 2: ['已取消', 'muted'], 3: ['已完成', 'success'], 4: ['已折抵', 'muted'] };
  function initialState() { return { filter: 'all', coupon: null, couponCode: '', couponSequence: 0, period: '', plan: null, orders: null, orderFilter: 'all', orderPage: 1, invite: null, invitePage: 1, pending: {}, poll: null, stripe: null }; }
  var M = initialState();
  function sameAccount(version) { return N.accountVersion() === version; }
  N.onAccountReset(function () {
    stopPolling(); destroyStripe(); stopPlanFeatures();
    expandedPlans.clear();
    M = initialState();
  });
  function text(value) { return N.e(value == null ? '' : String(value)); }
  function num(value) { var n = Number(value); return Number.isFinite(n) ? n : 0; }
  function query(route, key) { return route.query && typeof route.query.get === 'function' ? route.query.get(key) : route.query && route.query[key]; }
  function action(label, name, data, kind) {
    return '<button type="button" class="button ' + (kind || 'secondary') + '" data-action="' + name + '" ' + (data || '') + '>' + label + '</button>';
  }
  function submit(label, disabled) { return '<button class="button primary" type="submit"' + (disabled ? ' disabled' : '') + '>' + label + '</button>'; }
  function input(name, type, placeholder, value, attrs) {
    return '<input class="input" name="' + name + '" type="' + type + '" placeholder="' + text(placeholder) + '" value="' + text(value) + '" ' + (attrs || '') + '>';
  }
  function hidden(name, value) { return input(name, 'hidden', '', value); }
  function stat(label, value, icon) { return '<div class="card commerce-stat"><span class="commerce-stat-icon">' + polishedIcon(icon, 'commerce-stat-original') + '</span><div><span class="muted">' + label + '</span><strong class="metric-value">' + value + '</strong></div>' + (icon === 'users' ? fragment('invite', 563,474,78,43,'commerce-stat-ornament') : fragment('invite',971,416,82,77,'commerce-stat-ornament')) + '</div>'; }
  function row(label, value, extra) { return '<div class="row between commerce-detail-line ' + (extra || '') + '"><span class="muted">' + label + '</span><strong>' + value + '</strong></div>'; }
  function money(value) { return text(N.money(value)); }
  function compactPrice(value) { return '<span class="commerce-currency">' + text(N.state.config.currency_symbol || N.state.config.currency || '¥') + '</span><span>' + text((num(value) / 100).toLocaleString('zh-CN', { maximumFractionDigits: 2 })) + '</span>'; }
  function fragment(page, x, y, width, height, className, options) { return N.fragment ? N.fragment(page, x, y, width, height, className || '', options).replace('<svg ', '<svg style="--art-width:' + width + 'px;--art-height:' + height + 'px" ') : ''; }
  function letteringFragment(page, x, y, width, height, className) {
    if (!N.artLettering) return '';
    return '<svg class="reference-fragment ' + text(className || '') + '" width="' + width + '" height="' + height + '" viewBox="' + [x, y, width, height].join(' ') + '" aria-hidden="true" focusable="false">' + N.artLettering(page, [x, y, width, height], 'commerce-' + page + '-' + x + '-' + y) + '</svg>';
  }
  function identityPaws() {
    var shape = '<path d="M6 17c0-3 3-7 6-7s6 4 6 7c0 4-3 4-6 3-3 1-6 1-6-3Z"/><ellipse cx="4" cy="10" rx="2.7" ry="3.4" transform="rotate(-22 4 10)"/><ellipse cx="9" cy="5.8" rx="2.5" ry="3.3" transform="rotate(-8 9 5.8)"/><ellipse cx="15" cy="5.8" rx="2.5" ry="3.3" transform="rotate(8 15 5.8)"/><ellipse cx="20" cy="10" rx="2.7" ry="3.4" transform="rotate(22 20 10)"/>';
    return '<span class="commerce-identity-paws" aria-hidden="true"><svg class="commerce-identity-paw paw-left" width="25" height="25" viewBox="0 0 24 24">' + shape + '</svg><svg class="commerce-identity-paw paw-right" width="27" height="27" viewBox="0 0 24 24">' + shape + '</svg></span>';
  }
  function polishedIcon(name, className, bare) {
    var id = 'commerce-art-' + (M.iconSequence = (M.iconSequence || 0) + 1), body = '', circle = true;
    var p = 'url(#' + id + '-pink)', gold = 'url(#' + id + '-gold)';
    if (name === 'star') { circle = false; body = '<path d="M29 8Q32 2 35 9L40 21 53 22Q61 23 54 30L44 39 47 52Q49 60 41 56L31 49 19 55Q11 59 14 51L17 38 7 29Q1 23 10 22L23 21Z" fill="none" stroke="#fff" stroke-width="10"/><path d="M29 8Q32 2 35 9L40 21 53 22Q61 23 54 30L44 39 47 52Q49 60 41 56L31 49 19 55Q11 59 14 51L17 38 7 29Q1 23 10 22L23 21Z" fill="' + gold + '" stroke="#efaeaf" stroke-width="2"/><path d="m31 13 6 13 11 2" fill="none" stroke="#fff7d0" stroke-width="3" stroke-linecap="round"/>'; }
    else if (name === 'coin') { circle = false; body = '<circle cx="32" cy="32" r="28" fill="#ffb84d" stroke="#fff3b2" stroke-width="3"/><circle cx="32" cy="31" r="22" fill="' + gold + '" stroke="#e99b2e" stroke-width="2"/><circle cx="32" cy="30" r="18" fill="none" stroke="#fff5b8" stroke-width="2"/><path d="m32 16 5 10 11 2-8 8 2 11-10-6-10 6 2-11-8-8 11-2Z" fill="#ffbf55" stroke="#fff5be" stroke-width="2"/><path d="M12 23c3-8 10-14 18-15" fill="none" stroke="#fff" stroke-opacity=".8" stroke-width="2" stroke-linecap="round"/>'; }
    else if (name === 'wallet-dark') { circle = false; body = '<path d="M12 19 44 8q5-1 5 4v9Z" fill="#ffdbeb" stroke="#9b6483" stroke-width="2"/><rect x="9" y="20" width="45" height="34" rx="7" fill="' + p + '" stroke="#683d59" stroke-width="2"/><path d="M14 25h34M14 27v19" stroke="#ffe4ef" stroke-width="2" fill="none" stroke-linecap="round"/><path d="M45 32h13v15H44q-8 0-8-7t9-8Z" fill="#ffdef0" stroke="#71425f" stroke-width="2"/><circle cx="44" cy="40" r="2.5" fill="#8b5776"/>'; }
    else if (name === 'wallet') body = '<path d="m17 24 26-8q4-1 4 3v6" fill="#ffb9d7"/><rect x="14" y="23" width="36" height="27" rx="5" fill="' + p + '"/><path d="M19 28h24" stroke="#fff" stroke-width="2"/><path d="M43 33h9v11h-9q-6 0-6-5.5t6-5.5Z" fill="#ffe4f1"/><circle cx="43" cy="39" r="1.5" fill="#fb2e80" stroke="none"/>';
    else if (name === 'shield') body = '<path d="m32 13 17 7v13c0 9-7 16-17 22-10-6-17-13-17-22V20Z" fill="' + p + '"/><path d="m22 23 10-4 10 4v10c0 6-4 11-10 15" fill="none" stroke="#fff" stroke-width="1.5" stroke-opacity=".7"/>';
    else if (name === 'bolt') body = '<path d="M35 9 15 35h14l-3 21 23-30H35Z" fill="' + p + '"/><path d="m33 17-9 12" fill="none" stroke="#fff" stroke-width="2"/>';
    else if (name === 'device') body = '<rect x="13" y="10" width="24" height="40" rx="3" fill="#ffe2ef"/><path d="M19 16h12" stroke="#fff" stroke-width="3"/><path d="M23 44h4"/><rect x="34" y="28" width="16" height="27" rx="3" fill="#fff6fa"/><path d="M41 50h2"/>';
    else if (name === 'lock') body = '<rect x="17" y="29" width="30" height="23" rx="4" fill="#ffdbea"/><path d="M23 29v-9a9 9 0 0 1 18 0v9" fill="none" stroke-width="3.5"/><circle cx="32" cy="38" r="3" fill="#ff3889" stroke="none"/><path d="M32 40v5" stroke-width="3"/>';
    else if (name === 'mail') body = '<rect x="12" y="19" width="40" height="29" rx="4" fill="#ffe3f0"/><path d="m14 22 18 15 18-15M15 45l11-12m23 12L38 33" fill="none"/><path d="M17 23h30" stroke="#fff" stroke-width="2"/>';
    else if (name === 'send') body = '<path d="m10 29 43-15-11 39-12-15-10 10 2-16Z" fill="' + p + '"/><path d="m22 32 22-13-14 19" fill="none" stroke="#fff" stroke-width="2"/>';
    else if (name === 'clock') body = '<circle cx="32" cy="32" r="19" fill="#fff7fb" stroke-width="3.5"/><path d="M32 19v14l10 6" fill="none" stroke-width="3.5"/><path d="M20 21a16 16 0 0 1 12-5" stroke="#ffb9d5" stroke-width="2" fill="none"/>';
    else if (name === 'chart') body = '<rect x="17" y="33" width="6" height="17" rx="3" fill="#ff4c99" stroke="none"/><rect x="29" y="23" width="6" height="27" rx="3" fill="#ff4c99" stroke="none"/><rect x="41" y="13" width="6" height="37" rx="3" fill="#ff4c99" stroke="none"/>';
    else if (name === 'refresh') body = '<path d="M47 23a18 18 0 0 0-31-3l-3 5M17 42a18 18 0 0 0 31 2l3-5" fill="none" stroke-width="6"/><path d="m12 13 1 13 13-1M52 51l-1-13-13 1" fill="' + p + '" stroke-width="1.5"/>';
    else if (name === 'gift') body = '<rect x="16" y="27" width="32" height="25" rx="2" fill="#ffe8f3"/><rect x="12" y="21" width="40" height="10" rx="2" fill="#ffb3d4"/><path d="M32 22v30M32 22c-25 0-16-20-6-12 4 4 6 12 6 12Zm0 0c25 0 16-20 6-12-4 4-6 12-6 12Z" fill="none" stroke-width="3"/>';
    else if (name === 'ticket') body = '<path d="M16 13h32v9a6 6 0 0 0 0 12v16H16V34a6 6 0 0 0 0-12Z" fill="#ffe3ef"/><path d="M23 20v23" stroke="#fff" stroke-width="2"/><path d="m34 23 3 6 6 1-5 4 1 7-5-3-5 3 1-7-5-4 6-1Z" fill="' + p + '" stroke-width="1"/>';
    else if (name === 'users') body = '<circle cx="26" cy="22" r="8" fill="#ffe0ee"/><circle cx="44" cy="26" r="6" fill="#ffb8d5"/><path d="M12 49v-7c0-14 28-14 28 0v7ZM43 35c10 0 12 4 12 12h-9" fill="#ffe0ee"/>';
    else if (name === 'crown') body = '<path d="m11 23 12 9 9-19 9 19 12-9-5 27H16Z" fill="' + p + '"/><path d="M20 44h24" stroke="#fff" stroke-width="2"/>';
    else if (name === 'invoice') body = '<path d="M17 12h26v40H17Z" fill="#ffe1ef"/><path d="M24 21h11m-11 8h11m-11 8h8M43 25h9v25h-9" fill="none" stroke-width="3"/>';
    else if (name === 'coin-stack') body = '<ellipse cx="32" cy="19" rx="17" ry="7" fill="' + p + '"/><path d="M15 19v9c0 9 34 9 34 0v-9M15 30v9c0 9 34 9 34 0v-9M15 41v7c0 9 34 9 34 0v-7" fill="' + p + '"/><path d="M20 27q12 6 24 0M20 38q12 6 24 0M20 49q12 6 24 0" stroke="#ffe6f1" stroke-width="2" fill="none"/>';
    return '<svg class="commerce-polished-icon ' + (className || '') + '" viewBox="' + (bare ? '8 8 48 48' : '0 0 64 64') + '" aria-hidden="true" focusable="false"><defs><linearGradient id="' + id + '-pink" x1="0" y1="0" x2=".8" y2="1"><stop stop-color="#ffe0ed"/><stop offset="1" stop-color="#ff6cad"/></linearGradient><linearGradient id="' + id + '-gold" x1="0" y1="0" x2=".6" y2="1"><stop stop-color="#fff3b5"/><stop offset="1" stop-color="#ffc55e"/></linearGradient></defs>' + (circle && !bare ? '<circle cx="32" cy="32" r="30" fill="#fff0f7" stroke="#ffe0ee" stroke-width="1"/><path d="M10 25a23 23 0 0 1 24-17" fill="none" stroke="#fff" stroke-width="2"/>' : '') + '<g stroke="#ff3489" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round">' + body + '</g></svg>';
  }
  function ticketFrame(page) { return '<span class="commerce-ticket-decoration" aria-hidden="true">' + polishedIcon('star','commerce-ticket-star') + (page === 'profile' ? polishedIcon('star','commerce-ticket-star small-star') : '') + '</span>'; }
  function profileIcon(name) { return polishedIcon({ shield: 'lock', arrow: 'send', reset: 'refresh', gift: 'ticket' }[name] || name, 'commerce-profile-row-icon'); }
  function fidelityHero(page) {
    var titles = { plan: '购买订阅', order: '我的订单', invite: '邀请好友，一起分享', profile: '个人中心' };
    var subtitles = { plan: '选择适合您的套餐，享受更稳定、快速、安全的网络体验～', order: '管理您的订阅订单，查看订单状态与支付详情 ～', invite: '和小魔法师一起，让更多朋友遇见更好的上网体验 ～', profile: '管理您的账户信息与安全设置 ～' };
    // These uninterrupted title panels contain decorative copy only; every business
    // value and control, including the plan filters below, remains a live DOM node.
    if (page !== 'profile') {
      var staticArt = page === 'plan' ? fragment(page,309,85,1206,201,'commerce-static-hero-art',{background:'surface',parallax:true}) : fragment(page,309,page === 'order' ? 91 : 86,1206,page === 'order' ? 232 : 300,'commerce-static-hero-art',{parallax:true});
      return '<header class="commerce-fidelity-hero commerce-hero-' + page + ' commerce-static-hero"><h1 class="commerce-semantic-heading">' + titles[page] + '</h1><p class="commerce-semantic-heading">' + subtitles[page] + '</p>' + staticArt + '</header>';
    }
    if (page === 'profile') return '<header class="commerce-fidelity-hero commerce-hero-profile"><h1 class="commerce-semantic-heading">个人中心</h1><p class="commerce-semantic-heading">管理您的账户信息与安全设置</p></header>';
  }
  function fidelityCard(page, title, subtitle, iconBox, body, className) {
    return '<section class="card commerce-fidelity-card ' + (className || '') + '"><header class="card-head">' +
      polishedIcon({ 'commerce-wallet-card':'wallet','commerce-gift-card':'gift','commerce-security':'shield','commerce-invite-card':'star','commerce-invite-details':'invoice' }[className] || 'star','commerce-heading-icon') +
      '<div><h2>' + title + '</h2>' + (subtitle ? '<p class="muted">' + subtitle + '</p>' : '') + '</div>' + (className === 'commerce-gift-card' ? fragment('profile',761,698,131,60,'commerce-gift-heading-bow') : polishedIcon('star','commerce-heading-star')) + '</header><div class="card-body">' + body + '</div></section>';
  }
  function emptyRow(columns, message) { return '<tr><td colspan="' + columns + '">' + N.empty(message) + '</td></tr>'; }
  function formNote(message) { return '<p class="muted small">' + message + '</p>'; }
  function status(order) { var s = orderStatuses[num(order.status)] || ['未知状态', 'muted']; return N.badge(s[0], s[1]); }
  function isCommissionTransfer(order) {
    return num(order.plan_id) === 0 && order.period === 'deposit' && num(order.status) === 3 && num(order.total_amount) === 0 && num(order.surplus_amount) > 0 && order.callback_no === '佣金划转 Commission transfer';
  }
  function planName(order) { return String(isCommissionTransfer(order) ? '佣金划转' : order.period === 'deposit' || num(order.plan_id) === 0 ? '余额充值' : order.plan && order.plan.name || '套餐已下架'); }
  function orderPeriod(order) { return isCommissionTransfer(order) ? '佣金划转' : orderPeriodName(order.period); }
  // Replace only named business regions. The page header and its running
  // decorations stay mounted while filters, forms, and records change.
  function patchView(key, selectors, beforeReplace) {
    var page = document.querySelector('.commerce-page-' + key);
    if (!page) return false;
    if (!selectors.length) return true;
    var next = document.createElement('div');
    next.innerHTML = N.pages[key].render();
    var pairs = selectors.map(function (selector) { return [page.querySelector(selector), next.querySelector(selector)]; });
    if (pairs.some(function (pair) { return !pair[0] || !pair[1]; })) return false;
    if (beforeReplace) beforeReplace();
    pairs.forEach(function (pair) { pair[0].replaceWith(pair[1]); });
    return true;
  }
  function planGridSignature() { return JSON.stringify([M.filter, M.plan && M.plan.list, N.state.user.plan_id, N.state.config.currency, N.state.config.currency_symbol]); }
  function patchPurchase() {
    var purchase = document.querySelector('.commerce-page-plan .commerce-purchase');
    if (!purchase) return false;
    purchase.outerHTML = planPurchase(activePlan());
    return true;
  }
  function orderDetailSignature() { return JSON.stringify([M.orders && M.orders.selected, M.orders && M.orders.methods, M.orders && M.orders.method, M.orders && M.orders.paymentError, N.state.config.currency, N.state.config.currency_symbol]); }
  function orderListSignature() { return JSON.stringify([M.orders && M.orders.list, M.orderFilter, M.orderPage, selectedOrder() && selectedOrder().trade_no, N.state.config.currency, N.state.config.currency_symbol]); }
  function lock(key, fn) {
    var pending = M.pending, version = N.accountVersion();
    if (pending[key]) return pending[key];
    pending[key] = Promise.resolve().then(function () { if (sameAccount(version)) return fn(); }).finally(function () { delete pending[key]; });
    return pending[key];
  }
  function registerAction(name, fn) { N.actions[name] = function (element, event) { var key = name + (element && element.dataset && element.dataset.field ? ':' + element.dataset.field : ''); return lock(key, function () { return fn(element, event); }); }; }
  function registerForm(name, fn) { N.forms[name] = function (form, values, event) { return lock(name, function () { return fn(form, values, event); }); }; }
  function confirm(title, description, key, callback) { N.confirm(title, description, function () { return lock(key, callback); }); }
  function cents(value) {
    var match = /^(\d+)(?:\.(\d{1,2}))?$/.exec(String(value || '').trim());
    if (!match) throw new Error('请输入有效金额，最多保留两位小数');
    var amount = Number(match[1]) * 100 + Number(((match[2] || '') + '00').slice(0, 2));
    if (!Number.isSafeInteger(amount) || amount <= 0) throw new Error('金额必须大于 0');
    return amount;
  }
  function canBuy(plan) { return plan && (plan.capacity_limit == null || num(plan.capacity_limit) > 0 || String(plan.id) === String(N.state.user.plan_id)); }
  function options(plan, reset) {
    return periods.filter(function (key) {
      var blockedRenewal = plan.renew != null && !N.on(plan.renew) && String(plan.id) === String(N.state.user.plan_id) && key !== 'reset_price';
      return !blockedRenewal && plan[key] != null && Number.isFinite(Number(plan[key])) && num(plan[key]) >= 0 && (key !== 'reset_price' || reset);
    });
  }
  function activePlan() { return M.plan && M.plan.selected; }
  function subscriptionAvailable() {
    var user = N.state.user;
    return !N.on(user.banned) && num(user.transfer_enable) > 0 && (user.expired_at == null || num(user.expired_at) > Date.now() / 1000);
  }
  function canReset(plan) { return plan && String(plan.id) === String(N.state.user.plan_id) && subscriptionAvailable(); }
  function renewablePlan() {
    var sub = N.state.sub || {}, plan = sub.plan, user = N.state.user;
    if (!plan || !N.on(plan.renew) || String(plan.id) !== String(user.plan_id) || !options(plan, false).length) return null;
    return N.on(plan.show) || subscriptionAvailable() ? plan : null;
  }
  function planContent(content) {
    if (!content) return '';
    var features = content;
    if (typeof content === 'string') { try { features = JSON.parse(content); } catch (error) { features = null; } }
    if (Array.isArray(features) && features.every(function (item) { return item && typeof item === 'object' && typeof item.feature === 'string'; })) {
      return features.map(function (item) {
        var supported = N.on(item.support);
        return '<li class="commerce-configured-feature' + (supported ? '' : ' unsupported') + '"><span class="commerce-feature-support" aria-hidden="true">' + (supported ? '✓' : '×') + '</span><span><span class="sr-only">' + (supported ? '支持：' : '不支持：') + '</span>' + text(item.feature) + '</span></li>';
      }).join('');
    }
    return '<li class="commerce-plan-content">' + polishedIcon('shield','commerce-feature-icon',true) + '<div class="article">' + N.rich(content) + '</div></li>';
  }
  var expandedPlans = new Set(), featureObserver = null, featureFrame = 0, featureGrid = null;
  function stopPlanFeatures() {
    if (featureObserver) featureObserver.disconnect();
    if (featureFrame) window.cancelAnimationFrame(featureFrame);
    featureObserver = null; featureFrame = 0; featureGrid = null;
  }
  function syncPlanFeatures() {
    if (!featureGrid || !featureGrid.isConnected) { stopPlanFeatures(); return; }
    featureGrid.querySelectorAll('.commerce-plan').forEach(function (card) {
      var list = card.querySelector('.commerce-feature-list'), button = card.querySelector('.commerce-feature-toggle');
      var limit = parseFloat(window.getComputedStyle(list).getPropertyValue('--feature-preview-height')) || 134;
      var more = list.scrollHeight > limit + 1, id = card.dataset.planId;
      if (!more) expandedPlans.delete(id);
      var expanded = more && expandedPlans.has(id);
      card.classList.toggle('has-more-features', more);
      card.classList.toggle('features-expanded', expanded);
      button.hidden = !more;
      button.setAttribute('aria-expanded', String(expanded));
      button.querySelector('.commerce-feature-toggle-label').textContent = expanded ? '收起说明' : '展开全部说明';
    });
  }
  function schedulePlanFeatures() {
    if (!featureGrid || featureFrame) return;
    featureFrame = window.requestAnimationFrame(function () { featureFrame = 0; syncPlanFeatures(); });
  }
  function mountPlanFeatures() {
    stopPlanFeatures();
    featureGrid = document.querySelector('.commerce-page-plan .plan-grid');
    if (!featureGrid) return;
    syncPlanFeatures();
    if (window.ResizeObserver) {
      featureObserver = new ResizeObserver(schedulePlanFeatures);
      featureGrid.querySelectorAll('.commerce-feature-list,.commerce-feature-list>li').forEach(function (element) { featureObserver.observe(element); });
    }
  }
  window.addEventListener('resize', schedulePlanFeatures);
  function giftDrawing(index) {
    if (N.fragment) { var crop = [[320, 312, 137, 140], [725, 315, 117, 141], [1136, 314, 151, 141]][index % 3]; return '<span class="commerce-gift-slot motif-' + (index % 3) + '">' + fragment('plan', crop[0], crop[1], crop[2], crop[3], 'commerce-gift') + '</span>'; }
    var motifs = [
      '<path d="M20 43h60v43H20zM15 34h70v14H15zM46 34h9v52"/><path d="M49 33c-28-1-26-26-10-19 9 3 11 19 11 19Zm3 0c28-1 25-25 9-19-8 3-9 19-9 19Z"/>',
      '<path d="m24 48 8 37h38l8-37Z"/><path d="M20 49c-7-13 7-21 13-20 0-13 23-24 32-8 14-2 20 16 11 23 13 8 3 16-5 14-9 8-14-2-21 1-8 7-19-6-25-2Z"/><circle cx="51" cy="16" r="8"/><path d="m48 8 5-7m-15 64 4 15m11-16v16m11-16-3 15"/>',
      '<rect x="12" y="26" width="77" height="53" rx="15"/><rect x="36" y="35" width="30" height="32" rx="4"/><path d="M21 46v14m-7-7h14"/><circle cx="79" cy="46" r="4"/><circle cx="78" cy="60" r="4"/><path d="m52 40 3 7 8 1-6 5 1 7-6-4-7 4 2-7-6-5 8-1Z"/>'
    ];
    return '<svg class="commerce-gift" viewBox="0 0 100 100" aria-hidden="true"><circle cx="50" cy="50" r="46" fill="#fff5fa" stroke="none"/><g fill="#ffd3e7" stroke="#eb6398" stroke-width="2.8" stroke-linejoin="round">' + motifs[index % motifs.length] + '</g><path d="m87 9 2 5 6 1-5 4 1 6-4-3-5 3 1-6-4-4 6-1Z" fill="#ffe7a7" stroke="#e6b374"/></svg>';
  }
  function priceEstimate(plan) {
    var base = num(plan[M.period]), discount = 0;
    if (M.coupon) discount = num(M.coupon.type) === 1 ? num(M.coupon.value) : base * num(M.coupon.value) / 100;
    discount = Math.min(base, discount) + base * num(N.state.user.discount) / 100;
    return Math.max(0, Math.round(base - discount));
  }
  function planCard(plan, index) {
    var available = options(plan, false);
    var priceKey = available[0];
    var selected = activePlan() && String(activePlan().id) === String(plan.id);
    var current = String(plan.id) === String(N.state.user.plan_id);
    var sold = !canBuy(plan);
    var expanded = expandedPlans.has(String(plan.id)), featureId = 'commerce-features-' + encodeURIComponent(plan.id);
    return '<article class="plan-card commerce-plan ' + (selected ? 'selected ' : '') + (expanded ? 'features-expanded' : '') + '" data-plan-id="' + text(plan.id) + '">' +
      (current ? '<span class="commerce-plan-current">' + N.icon('crown') + ' 当前套餐</span>' : '') +
      '<div class="commerce-plan-head">' + giftDrawing(index) + '<div><h2>' + text(plan.name) + '</h2>' +
      (priceKey ? '<div class="price">' + compactPrice(plan[priceKey]) + '<small> / ' + ({ month_price: '月', quarter_price: '季', half_year_price: '半年', year_price: '年', two_year_price: '两年', three_year_price: '三年' }[priceKey] || periodNames[priceKey]) + '</small></div>' : '<p class="muted">' + (current && plan.renew != null && !N.on(plan.renew) ? '当前套餐不支持续费' : '暂无可购买周期') + '</p>') + '</div></div>' +
      '<div class="commerce-plan-features"><div class="commerce-feature-preview"><ul class="commerce-feature-list" id="' + text(featureId) + '">' +
      (plan.transfer_enable != null ? '<li>' + polishedIcon('coin-stack','commerce-feature-icon',true) + '<span>' + text(plan.transfer_enable) + ' GB 流量</span></li>' : '') +
      (plan.speed_limit != null ? '<li>' + polishedIcon('bolt','commerce-feature-icon',true) + '<span>' + (num(plan.speed_limit) ? text(plan.speed_limit) + ' Mbps 速率' : '不限速率') + '</span></li>' : '') +
      (plan.device_limit != null ? '<li>' + polishedIcon('device','commerce-feature-icon',true) + '<span>' + (num(plan.device_limit) ? text(plan.device_limit) + ' 台设备' : '不限设备数量') + '</span></li>' : '') +
      planContent(plan.content) + '</ul></div><button type="button" class="commerce-feature-toggle" data-action="commerce-features-toggle" aria-controls="' + text(featureId) + '" aria-expanded="' + expanded + '" hidden><span class="commerce-feature-toggle-label">' + (expanded ? '收起说明' : '展开全部说明') + '</span><svg viewBox="0 0 16 16" aria-hidden="true"><path d="m4 6 4 4 4-4"/></svg></button></div>' +
      '<button type="button" class="button ' + (selected ? 'primary' : 'secondary') + '" data-action="commerce-plan-select" data-id="' + text(plan.id) + '" aria-pressed="' + Boolean(selected) + '"' + (sold || !available.length ? ' disabled' : '') + '>' + (sold ? '暂时售罄' : '选择套餐') + '<span aria-hidden="true">›</span></button></article>';
  }
  function planPurchase(plan) {
    if (!plan) return N.card('配置订阅', N.empty('选择一个套餐，查看可用的付款周期'));
    var reset = canReset(plan);
    var choices = options(plan, reset);
    var showResetLink = choices.indexOf('reset_price') >= 0 && choices.length > 1 && M.period !== 'reset_price';
    var visibleChoices = choices.filter(function (key) { return !showResetLink || key !== 'reset_price'; });
    var couponLabel = M.coupon ? '<span class="badge success">已验证：' + text(M.couponCode) + '</span>' + action('移除', 'commerce-coupon-remove', '', 'link') : '';
    var disabled = !canBuy(plan) || choices.indexOf(M.period) < 0;
    return '<section class="card commerce-purchase"><div class="card-head row between wrap"><div class="commerce-purchase-title">' + polishedIcon('star','commerce-heading-icon') + '<h2>配置订阅</h2><span class="muted small">自定义订阅选项，确认后即可购买</span></div><div class="commerce-chosen-plan">已选： ' + N.badge(plan.name) + '</div>' + polishedIcon('star','commerce-purchase-star') + '</div><div class="card-body commerce-purchase-grid">' +
      '<section><h3>' + polishedIcon('clock','commerce-config-icon') + '选择付款周期</h3><div class="commerce-periods">' + visibleChoices.map(function (key) {
        var months = { quarter_price: 3, half_year_price: 6, year_price: 12, two_year_price: 24, three_year_price: 36 }[key];
        return '<button type="button" class="commerce-period ' + (M.period === key ? 'selected' : '') + '" data-action="commerce-period" data-period="' + key + '" aria-pressed="' + (M.period === key) + '"><strong>' + periodNames[key] + '</strong><span>' + money(plan[key]) + '</span>' + (months ? '<small>（' + money(num(plan[key]) / months) + ' / 月）</small>' : '') + '</button>';
      }).join('') + (choices.length ? '' : N.empty('当前套餐没有可用付款周期')) + '</div>' + (showResetLink ? action('重置当前套餐流量 ' + money(plan.reset_price), 'commerce-period', 'data-period="reset_price"', 'link commerce-reset-option') : '') + '</section>' +
      '<section><h3>' + polishedIcon('ticket','commerce-config-icon') + '优惠券</h3><form class="inline-form" data-form="commerce-coupon">' + input('code', 'text', '请输入优惠券代码', M.couponCode, 'required autocomplete="off" aria-label="优惠券代码"') + submit('验证') + '</form><div class="row wrap commerce-coupon-state">' + couponLabel + '</div>' + formNote('* 如有优惠券，请在此输入后点击验证') + '</section>' +
      '<section class="commerce-buy-total"><h3>' + polishedIcon('coin-stack','commerce-config-icon') + '合计金额</h3><strong class="price">' + (M.period ? money(priceEstimate(plan)) : '—') + '</strong><form data-form="commerce-purchase">' + submit(N.icon('cart') + '确认购买　›', disabled) + '</form>' + formNote('最终实付金额将在订单中确认。') + '</section></div></section>';
  }
  N.pages.plan = {
    load: async function (route) {
      var sequence = M.planSequence = (M.planSequence || 0) + 1, version = N.accountVersion();
      var current = N.state.sub && N.state.sub.plan;
      // An owned, active plan may still permit a reset after sales and renewals close.
      var resetPlan = query(route, 'period') === 'reset_price' && current && String(current.id) === String(route.id) && current.reset_price != null && canReset(current) ? current : null;
      var results = await Promise.all([N.api.get('/user/plan/fetch'), resetPlan ? Promise.resolve({ data: resetPlan }) : route.id ? N.api.get('/user/plan/fetch', { id: route.id }) : Promise.resolve(null)]);
      if (!sameAccount(version) || sequence !== M.planSequence) return null;
      var list = N.list(results[0]), selected = results[1] ? N.data(results[1]) : null;
      if (selected) {
        var listedPlan = list.find(function (plan) { return String(plan.id) === String(selected.id); });
        if (listedPlan) selected.capacity_limit = listedPlan.capacity_limit;
      }
      if (!selected && M.plan && M.plan.selected) selected = list.find(function (p) { return String(p.id) === String(M.plan.selected.id); });
      if (!selected) selected = list.find(function (plan) { return String(plan.id) === String(N.state.user.plan_id) && canBuy(plan) && options(plan, false).length; });
      if (!selected) selected = list.find(function (plan) { return canBuy(plan) && options(plan, false).length; }) || list[0] || null;
      if (selected && !list.some(function (p) { return String(p.id) === String(selected.id); })) list.unshift(selected);
      if (!M.plan || !M.plan.selected || !selected || String(M.plan.selected.id) !== String(selected.id)) { M.coupon = null; M.couponCode = ''; M.period = ''; }
      M.plan = { list: list, selected: selected };
      if (selected) {
        var reset = canReset(selected);
        var available = options(selected, reset), requested = query(route, 'period');
        if (available.indexOf(requested) >= 0) M.period = requested;
        else if (available.indexOf(M.period) < 0) M.period = available[0] || '';
      }
      return M.plan;
    },
    render: function () {
      var list = M.plan ? M.plan.list : [];
      var renewal = renewablePlan();
      if (M.filter === 'period') list = list.filter(function (p) { return options(p, false).some(function (key) { return key !== 'onetime_price'; }); });
      if (M.filter === 'traffic') list = list.filter(function (p) { return p.onetime_price != null; });
      return '<div class="commerce-page commerce-page-plan"><div class="commerce-plan-hero">' + fidelityHero('plan') +
        '<div class="tabs commerce-tabs" aria-label="套餐分类">' + [['all', '全部'], ['period', '按周期'], ['traffic', '按流量']].map(function (x) { return action(x[1], 'commerce-plan-filter', 'data-filter="' + x[0] + '" aria-pressed="' + (M.filter === x[0]) + '"', M.filter === x[0] ? 'active' : ''); }).join('') + '</div>' +
        '</div>' + (renewal ? '<div class="commerce-renewal"><span>当前订阅：<strong>' + text(renewal.name) + '</strong></span><a class="button secondary" href="#/plan/' + text(encodeURIComponent(renewal.id)) + '">续费当前订阅</a></div>' : '') + '<div class="plan-grid">' + (list.length ? list.map(planCard).join('') : N.empty('当前分类暂无可购买的套餐')) + '</div>' + planPurchase(activePlan()) + '</div>';
    },
    mounted: function () { M.planGridSignature = planGridSignature(); mountPlanFeatures(); },
    patch: function () {
      var page = document.querySelector('.commerce-page-plan');
      if (!page) return false;
      var signature = planGridSignature();
      if (signature !== M.planGridSignature) {
        if (!patchView('plan', ['.commerce-plan-hero .commerce-tabs', '.plan-grid', '.commerce-purchase'], stopPlanFeatures)) return false;
        M.planGridSignature = signature;
        mountPlanFeatures();
      } else if (!patchPurchase()) return false;
      page.querySelectorAll('[data-action="commerce-plan-select"]').forEach(function (button) {
        var selected = activePlan() && String(activePlan().id) === button.dataset.id;
        button.closest('.commerce-plan').classList.toggle('selected', Boolean(selected));
        button.classList.toggle('primary', Boolean(selected));
        button.classList.toggle('secondary', !selected);
        button.setAttribute('aria-pressed', String(Boolean(selected)));
      });
      var renewal = page.querySelector('.commerce-renewal'), plan = renewablePlan();
      if (!plan && renewal) renewal.remove();
      else if (plan) {
        if (renewal) {
          renewal.querySelector('strong').textContent = plan.name;
          renewal.querySelector('a').setAttribute('href', '#/plan/' + encodeURIComponent(plan.id));
        } else {
          renewal = document.createElement('div'); renewal.className = 'commerce-renewal';
          renewal.innerHTML = '<span>当前订阅：<strong>' + text(plan.name) + '</strong></span><a class="button secondary" href="#/plan/' + text(encodeURIComponent(plan.id)) + '">续费当前订阅</a>';
          page.querySelector('.plan-grid').before(renewal);
        }
      }
      return true;
    }
  };
  registerAction('commerce-plan-filter', function (el) { if (M.filter === el.dataset.filter) return; M.filter = el.dataset.filter; N.renderCurrent(); });
  registerAction('commerce-features-toggle', function (el) {
    var card = el.closest('.commerce-plan');
    if (!card || el.hidden) return;
    var id = card.dataset.planId;
    if (expandedPlans.has(id)) expandedPlans.delete(id); else expandedPlans.add(id);
    card.querySelector('.commerce-feature-list').scrollTop = 0;
    syncPlanFeatures();
  });
  registerAction('commerce-plan-select', function (el) {
    var page = document.querySelector('.commerce-page-plan');
    var purchase = page && page.querySelector('.commerce-purchase');
    var plan = M.plan && M.plan.list.find(function (item) { return String(item.id) === el.dataset.id; });
    if (!purchase || !plan || !canBuy(plan) || !options(plan, false).length) return;
    if (activePlan() && String(activePlan().id) === String(plan.id)) { if (N.revealMobileDetail) N.revealMobileDetail('plan'); return; }
    // A new context invalidates pending coupon/order checks for the previous plan.
    M.plan = { list: M.plan.list, selected: plan };
    M.coupon = null; M.couponCode = '';
    M.period = options(plan, canReset(plan))[0] || '';
    var route = '#/plan/' + encodeURIComponent(plan.id) + (M.period ? '?period=' + encodeURIComponent(M.period) : '');
    window.history.pushState(null, '', route);
    N.state.route = N.route();
    page.querySelectorAll('[data-action="commerce-plan-select"]').forEach(function (button) {
      var selected = button.dataset.id === String(plan.id);
      button.closest('.commerce-plan').classList.toggle('selected', selected);
      button.classList.toggle('primary', selected);
      button.classList.toggle('secondary', !selected);
      button.setAttribute('aria-pressed', String(selected));
    });
    purchase.outerHTML = planPurchase(plan);
    if (N.revealMobileDetail) N.revealMobileDetail('plan');
  });
  registerAction('commerce-period', function (el) {
    if (M.period === el.dataset.period) return;
    M.couponSequence++;
    M.period = el.dataset.period;
    if (M.coupon && Array.isArray(M.coupon.limit_period) && M.coupon.limit_period.length && M.coupon.limit_period.indexOf(M.period) < 0) { M.coupon = null; N.toast('此优惠券不适用于新选择的周期，请重新验证'); }
    N.renderCurrent();
  });
  registerForm('commerce-coupon', async function (form, values) {
    var plan = activePlan();
    if (!plan || !M.period) throw new Error('请先选择套餐和付款周期');
    var code = String(values.code || '').trim(), context = M.plan, chosenPeriod = M.period, version = N.accountVersion(), sequence = ++M.couponSequence, planSequence = M.planSequence;
    var current = function () { return sameAccount(version) && M.plan === context && M.planSequence === planSequence && M.period === chosenPeriod && M.couponSequence === sequence && N.route().key === 'plan'; };
    M.coupon = null; M.couponCode = code;
    try {
      var coupon = N.data(await N.api.post('/user/coupon/check', { code: code, plan_id: plan.id }));
      if (!current()) return;
      if (coupon && Array.isArray(coupon.limit_period) && coupon.limit_period.length && coupon.limit_period.indexOf(M.period) < 0) throw new Error('该优惠券不适用于所选付款周期');
      M.coupon = coupon; M.couponCode = code; N.renderCurrent(); N.toast('优惠券已验证');
    } catch (error) {
      if (!current()) return;
      N.renderCurrent();
      throw error;
    }
  });
  registerAction('commerce-coupon-remove', function () { M.couponSequence++; M.coupon = null; M.couponCode = ''; N.renderCurrent(); });
  registerAction('commerce-resume-order', function (el) { N.closeModal(); N.go('order/' + encodeURIComponent(el.dataset.trade)); });
  registerForm('commerce-purchase', async function () {
    var plan = activePlan();
    if (!plan || !canBuy(plan) || options(plan, canReset(plan)).indexOf(M.period) < 0) throw new Error('请重新选择可用套餐和付款周期');
    var body = { plan_id: plan.id, period: M.period };
    if (M.coupon && M.couponCode) body.coupon_code = M.couponCode;
    var context = M.plan, existing = N.list(await N.api.get('/user/order/fetch')).find(function (order) { return num(order.status) === 0 || num(order.status) === 1; });
    if (M.plan !== context || M.period !== body.period) return;
    if (existing) {
      N.modal('有未完成的订单', '<p>' + (num(existing.status) === 1 ? '您已有订单正在开通，请先查看该订单的处理状态。' : '您已有待支付订单，请先查看并处理该订单。') + '</p><div class="row end">' + action('返回', 'close-modal') + action('查看原订单', 'commerce-resume-order', 'data-trade="' + text(existing.trade_no) + '"', 'primary') + '</div>');
      return;
    }
    var changing = N.state.user.plan_id && String(plan.id) !== String(N.state.user.plan_id) && (N.state.user.expired_at == null || num(N.state.user.expired_at) > Date.now() / 1000);
    confirm('确认生成订单', plan.name + ' · ' + periodNames[body.period] + '。' + (changing ? '变更订阅会用新订阅覆盖当前订阅。' : '') + '生成订单时将按站点规则使用账户余额和计算套餐折抵。', 'purchase-order', async function () {
      var trade = N.data(await N.api.post('/user/order/save', body));
      if (!trade) throw new Error('订单号为空，请刷新订单列表确认结果');
      N.closeModal(); N.go('order/' + encodeURIComponent(trade)); await N.refreshCore();
    });
  });

  function selectedOrder() { return M.orders && M.orders.selected; }
  function selectedPayment() { return M.orders && M.orders.methods.find(function (p) { return String(p.id) === String(M.orders.method); }); }
  function handling(payment, order) { return payment ? Math.round(num(order.total_amount) * num(payment.handling_fee_percent) / 100 + num(payment.handling_fee_fixed)) : 0; }
  function cancelOrder(trade) {
    confirm('取消订单', '取消后会按站点规则退回此订单使用的账户余额，是否继续？', 'cancel-' + trade, async function () {
      await N.api.post('/user/order/cancel', { trade_no: trade });
      N.closeModal(); await N.refresh(); N.toast('订单已取消');
    });
  }
  function stopPolling() { if (M.poll) window.clearTimeout(M.poll); M.poll = null; }
  function schedulePoll() {
    stopPolling();
    var order = selectedOrder();
    if (!order || [0, 1].indexOf(num(order.status)) < 0 || N.route().key !== 'order') return;
    var trade = order.trade_no, session = M.orders, version = N.accountVersion(), sequence = M.orderSequence;
    var current = function () { return sameAccount(version) && M.orders === session && M.orderSequence === sequence && N.route().key === 'order' && selectedOrder() && selectedOrder().trade_no === trade; };
    M.poll = window.setTimeout(async function () {
      if (!current()) return;
      try {
        var result = N.data(await N.api.get('/user/order/check', { trade_no: trade }));
        if (!current()) return;
        if (num(result) !== num(order.status)) {
          var detail = N.data(await N.api.get('/user/order/detail', { trade_no: trade }));
          if (!current()) return;
          M.orders.selected = detail;
          M.orders.list = M.orders.list.map(function (item) { return item.trade_no === trade ? M.orders.selected : item; });
          if (num(result) !== 0) N.closeModal();
          await N.refreshCore();
          if (!current()) return;
          N.renderCurrent();
          N.toast(num(result) === 3 ? '订单已完成' : '订单状态已更新');
        }
        schedulePoll();
      } catch (err) {
        if (!current()) return;
        var element = document.querySelector('[data-commerce-payment-status]');
        if (element) element.textContent = '支付状态暂时无法刷新，请点击“刷新状态”重试。';
      }
    }, 5000);
  }
  window.addEventListener('hashchange', function () { stopPolling(); if (N.route().key !== 'order') destroyStripe(); if (N.route().key !== 'plan') stopPlanFeatures(); });
  function orderDetails(order) {
    if (!order) return N.card('订单详情', N.empty('选择一个订单查看详情'), 'commerce-order-detail');
    var pending = num(order.status) === 0, payment = selectedPayment(), fee = handling(payment, order), transfer = isCommissionTransfer(order);
    var methods = M.orders.methods;
    var cost = transfer ? row('交易类型', '佣金划转') + row('划转金额', money(order.surplus_amount)) + row('已到账金额', money(order.surplus_amount), 'commerce-grand-total') : row('套餐', text(planName(order))) + row('周期', text(orderPeriodName(order.period))) +
      (order.discount_amount ? row('优惠金额', '− ' + money(order.discount_amount)) : '') +
      (order.balance_amount ? row('余额抵扣', '− ' + money(order.balance_amount)) : '') +
      (order.surplus_amount ? row('套餐折抵', '− ' + money(order.surplus_amount)) : '') +
      (order.refund_amount ? row('退回余额', money(order.refund_amount)) : '') +
      (order.bounus != null ? row('充值赠送', money(order.bounus)) : '') +
      (order.get_amount != null ? row(num(order.status) === 3 ? '已到账金额' : '预计到账', money(order.get_amount)) : '') +
      (pending && fee ? row('支付手续费', money(fee)) : !pending && order.handling_amount ? row('支付手续费', money(order.handling_amount)) : '') +
      row(pending ? '合计' : [1, 3, 4].indexOf(num(order.status)) >= 0 ? '实付金额' : '订单金额', money(num(order.total_amount) + (pending ? fee : num(order.handling_amount))), 'commerce-grand-total');
    var payments = '';
    if (pending) {
      payments = '<form data-form="commerce-checkout"><h3>' + (num(order.total_amount) > 0 ? '选择支付方式' : '确认开通') + '</h3>';
      if (num(order.total_amount) > 0) {
        payments += '<div class="commerce-payments">' + methods.map(function (p) {
          var url = N.safeURL(p.icon);
          return '<button type="button" class="commerce-payment ' + (String(p.id) === String(M.orders.method) ? 'selected' : '') + '" data-action="commerce-payment" data-method="' + text(p.id) + '" title="' + text(p.name) + '" aria-pressed="' + (String(p.id) === String(M.orders.method)) + '"><i class="commerce-payment-radio" aria-hidden="true"></i>' + (url ? '<img src="' + text(url) + '" alt="" loading="lazy">' : N.icon('wallet')) + '<span>' + text(p.name) + '</span></button>';
        }).join('') + '</div>';
        if (M.orders.paymentError) payments += '<p class="notice danger">' + text(M.orders.paymentError) + '</p>';
        if (!methods.length) payments += N.empty('暂时没有可用支付方式，请联系站点客服');
        if (payment && payment.payment === 'StripeCredit') payments += '<div class="commerce-stripe"><label>信用卡信息</label><div id="nini-stripe-card" class="input"></div><div data-commerce-stripe-error class="small" role="alert"></div></div>';
      }
      payments += submit(num(order.total_amount) > 0 ? '♥　去支付　 ›' : '确认开通', num(order.total_amount) > 0 && !payment) + '</form>' + action('取消订单', 'commerce-order-cancel', 'data-trade="' + text(order.trade_no) + '"', 'link');
    }
    return '<aside class="card commerce-order-detail"><div class="card-head"><h2 class="commerce-detail-title"><span class="commerce-semantic-heading">订单详情 ♡</span>' + fragment('order',1202,335,224,57,'commerce-detail-title-original') + '</h2>' + action(N.icon('refresh'), 'commerce-order-refresh', 'aria-label="刷新状态" title="刷新状态"', 'commerce-order-refresh-icon') + '</div><div class="card-body"><div class="commerce-order-stamp"><span class="muted small">订单号</span><span class="ticket-stamp" title="' + text(N.date(order.created_at, true)) + '">' + '<span class="commerce-ticket-text">' + text(order.trade_no) + '</span>' + ticketFrame('order',1225,421,183,48) + '</span></div>' + cost + payments + '<div class="commerce-check-status"><p class="muted small" data-commerce-payment-status role="status">' + (pending ? '付款完成后，订单状态会自动更新。' : num(order.status) === 1 ? '已收到付款，正在开通。' : '') + '</p></div>' + fragment('order', 1145, 793, 359, 197, 'commerce-detail-art') + '</div></aside>';
  }
  function defaultOrder(list) { return list.find(function (item) { return num(item.status) === 0; }) || list[0] || null; }
  function needsPaymentMethods(order) { return order && num(order.status) === 0 && num(order.total_amount) > 0; }
  async function paymentMethods() {
    try { return { methods: N.list(await N.api.get('/user/order/getPaymentMethod')), error: '' }; }
    catch (error) { return { methods: [], error: error.message || '支付方式加载失败' }; }
  }
  N.pages.order = {
    prefetch: async function (route) {
      var version = N.accountVersion(), list = N.list(await N.api.get('/user/order/fetch'));
      if (!sameAccount(version)) return;
      var candidate = route.id ? list.find(function (item) { return item.trade_no === route.id; }) : defaultOrder(list);
      // Prefetch only cacheable GET data; never alter order state or payment polling.
      if (needsPaymentMethods(candidate)) await N.api.get('/user/order/getPaymentMethod');
    },
    load: async function (route) {
      stopPolling();
      var sequence = M.orderSequence = (M.orderSequence || 0) + 1, version = N.accountVersion();
      var initial = await Promise.all([N.api.get('/user/order/fetch'), route.id ? N.api.get('/user/order/detail', { trade_no: route.id }) : null]);
      if (!sameAccount(version) || sequence !== M.orderSequence) return null;
      var list = N.list(initial[0]);
      var candidate = route.id ? list.find(function (item) { return item.trade_no === route.id; }) : defaultOrder(list);
      var trade = route.id || candidate && candidate.trade_no;
      var results = await Promise.all([
        route.id ? N.data(initial[1]) : trade ? N.api.get('/user/order/detail', { trade_no: trade }).then(N.data) : null,
        needsPaymentMethods(route.id ? N.data(initial[1]) : candidate) ? paymentMethods() : null
      ]);
      if (!sameAccount(version) || sequence !== M.orderSequence) return null;
      var order = results[0], payments = results[1];
      var methods = [], paymentError = '';
      if (needsPaymentMethods(order)) {
        if (!payments) payments = await paymentMethods();
        methods = payments.methods; paymentError = payments.error;
      }
      if (!sameAccount(version) || sequence !== M.orderSequence) return null;
      var oldMethod = M.orders && M.orders.method;
      M.orders = { list: list, selected: order, methods: methods, method: methods.some(function (p) { return String(p.id) === String(oldMethod); }) ? oldMethod : methods[0] && methods[0].id, paymentError: paymentError };
      return M.orders;
    },
    render: function () {
      var all = M.orders ? M.orders.list : [], orders = all.filter(function (o) { return M.orderFilter === 'all' || String(o.status) === M.orderFilter; });
      var pages = Math.max(1, Math.ceil(orders.length / 4)); M.orderPage = Math.min(M.orderPage, pages);
      var rows = orders.slice((M.orderPage - 1) * 4, M.orderPage * 4);
      var selected = selectedOrder();
      var filters = [['all', '全部'], ['0', '待支付'], ['3', '已完成'], ['2', '已取消']];
      if (all.some(function (order) { return num(order.status) === 1; })) filters.push(['1', '开通中']);
      if (all.some(function (order) { return num(order.status) === 4; })) filters.push(['4', '已折抵']);
      return '<div class="commerce-page commerce-page-order">' + fidelityHero('order') + '<div class="commerce-order-layout"><section class="stack"><div class="tabs commerce-tabs">' + filters.map(function (item) { return action(item[1], 'commerce-order-filter', 'data-filter="' + item[0] + '"', M.orderFilter === item[0] ? 'active' : ''); }).join('') + '</div>' +
        '<div class="card commerce-order-list"><div class="table-wrap"><table class="commerce-order-table"><thead><tr><th>订单号</th><th>周期</th><th>金额</th><th>状态</th><th>创建时间</th><th>操作</th></tr></thead><tbody>' + (rows.length ? rows.map(function (order) {
          return '<tr class="' + (selected && selected.trade_no === order.trade_no ? 'selected' : '') + '"><td><span class="ticket-stamp" title="' + text(planName(order)) + '">' + '<span class="commerce-ticket-text">' + text(order.trade_no) + '</span>' + ticketFrame('order',1225,421,183,48) + '</span></td><td>' + text(orderPeriod(order)) + '</td><td class="commerce-money">' + money(isCommissionTransfer(order) ? order.surplus_amount : order.total_amount) + '</td><td>' + status(order) + '</td><td>' + text(N.date(order.created_at)) + '</td><td>' + action('查看', 'commerce-order-select', 'data-trade="' + text(order.trade_no) + '"', 'primary') + '</td></tr>';
        }).join('') : emptyRow(6, '暂无符合条件的订单')) + '</tbody></table></div><div class="pagination">' + action('‹', 'commerce-order-page', 'aria-label="上一页" data-page="' + (M.orderPage - 1) + '"' + (M.orderPage === 1 ? ' disabled' : '')) + '<span aria-label="第 ' + M.orderPage + ' 页，共 ' + pages + ' 页">' + M.orderPage + '</span>' + action('›', 'commerce-order-page', 'aria-label="下一页" data-page="' + (M.orderPage + 1) + '"' + (M.orderPage === pages ? ' disabled' : '')) + '</div></div>' +
        '<div class="commerce-thankyou">' + fragment('order', 309, 843, 802, 137, 'commerce-original-thanks') + '</div></section>' + orderDetails(selected) + '</div></div>';
    },
    mounted: function () { M.orderDetailSignature = orderDetailSignature(); M.orderListSignature = orderListSignature(); schedulePoll(); mountStripe().catch(N.error); },
    patch: function () {
      var signature = orderDetailSignature(), detailChanged = signature !== M.orderDetailSignature;
      var selectors = orderListSignature() !== M.orderListSignature ? ['.commerce-order-layout > section > .commerce-tabs', '.commerce-order-table tbody', '.commerce-order-list .pagination'] : [];
      if (detailChanged) selectors.push('.commerce-order-detail');
      if (!patchView('order', selectors, detailChanged ? destroyStripe : null)) return false;
      M.orderDetailSignature = signature;
      M.orderListSignature = orderListSignature();
      schedulePoll();
      if (detailChanged) mountStripe().catch(N.error);
      return true;
    }
  };
  registerAction('commerce-order-filter', function (el) { if (M.orderFilter === el.dataset.filter) return; M.orderFilter = el.dataset.filter; M.orderPage = 1; N.renderCurrent(); });
  registerAction('commerce-order-page', function (el) { var page = Math.max(1, num(el.dataset.page)); if (page === M.orderPage) return; M.orderPage = page; N.renderCurrent(); });
  registerAction('commerce-order-select', function (el) { if (selectedOrder() && selectedOrder().trade_no === el.dataset.trade) { if (N.revealMobileDetail) N.revealMobileDetail('order'); return; } N.go('order/' + encodeURIComponent(el.dataset.trade)); });
  registerAction('commerce-order-cancel', function (el) { cancelOrder(el.dataset.trade); });
  registerAction('commerce-order-refresh', async function () { await N.refresh(); N.toast('订单状态已刷新'); });
  registerAction('commerce-payment', function (el) { if (!M.orders || String(M.orders.method) === el.dataset.method) return; M.orders.method = el.dataset.method; N.renderCurrent(); });
  var stripeScript;
  function loadStripeScript() {
    if (window.Stripe) return Promise.resolve();
    if (stripeScript) return stripeScript;
    stripeScript = new Promise(function (resolve, reject) {
      var script = document.createElement('script'); script.src = 'https://js.stripe.com/v3/'; script.async = true;
      script.onload = function () { if (window.Stripe) resolve(); else reject(new Error('信用卡支付组件加载失败')); };
      script.onerror = function () { script.remove(); stripeScript = null; reject(new Error('信用卡支付组件无法加载，请稍后重试')); };
      document.head.appendChild(script);
    });
    return stripeScript;
  }
  function destroyStripe() { if (M.stripe && M.stripe.card) { try { M.stripe.card.destroy(); } catch (ignore) {} } M.stripe = null; }
  async function mountStripe() {
    var order = selectedOrder(), payment = selectedPayment(), target = document.getElementById('nini-stripe-card'), version = N.accountVersion();
    if (!order || !payment || payment.payment !== 'StripeCredit' || !target) return;
    if (M.stripe && M.stripe.target === target) return;
    destroyStripe();
    var stripeState = { target: target, method: payment.id }; M.stripe = stripeState;
    target.textContent = '正在加载安全支付组件…';
    try {
      var results = await Promise.all([N.api.post('/user/comm/getStripePublicKey', { id: payment.id }), loadStripeScript()]);
      if (!sameAccount(version) || !document.contains(target) || M.stripe !== stripeState) return;
      var pk = N.data(results[0]);
      if (!pk) throw new Error('此支付方式未配置公钥');
      var stripe = window.Stripe(pk), elements = stripe.elements();
      var card = elements.create('card', { style: { base: { color: '#38253c', fontSize: '16px', '::placeholder': { color: '#a68c9b' } } } });
      target.textContent = ''; card.mount(target);
      stripeState.stripe = stripe; stripeState.card = card;
      card.on('change', function (event) { var err = document.querySelector('[data-commerce-stripe-error]'); if (err) err.textContent = event.error ? event.error.message : ''; });
    } catch (err) { if (!sameAccount(version) || M.stripe !== stripeState || !document.contains(target)) return; target.textContent = err.message; throw err; }
  }
  registerForm('commerce-checkout', async function () {
    var order = selectedOrder(), method = selectedPayment(), context = M.orders, version = N.accountVersion(), sequence = M.orderSequence;
    if (!order || num(order.status) !== 0) throw new Error('该订单当前不可支付');
    if (num(order.total_amount) > 0 && !method) throw new Error('请选择可用支付方式');
    var body = { trade_no: order.trade_no, method: method ? method.id : 0 };
    var sameView = function () { var route = N.route(); return sameAccount(version) && route.key === 'order' && (!route.id || route.id === order.trade_no) && selectedOrder() && selectedOrder().trade_no === order.trade_no; };
    var current = function () { return sameView() && M.orders === context && M.orderSequence === sequence && selectedOrder() === order && num(order.status) === 0 && String(context.method || 0) === String(body.method); };
    var response;
    try {
      if (!current()) return;
      if (num(order.total_amount) > 0 && method.payment === 'StripeCredit') {
        if (!M.stripe || !M.stripe.card) throw new Error('请等待信用卡支付组件加载完成');
        var token = await M.stripe.stripe.createToken(M.stripe.card);
        if (!current()) return;
        if (token.error) throw new Error(token.error.message);
        if (!token.token || !token.token.id) throw new Error('信用卡验证失败');
        body.token = token.token.id;
      }
      response = await N.api.post('/user/order/checkout', body);
      if (!current()) return;
    } catch (error) {
      if (!current()) return;
      throw error;
    }
    var kind = num(response.type);
    if (kind === -1) { await N.refresh(); if (sameView()) N.toast('订单已确认，请查看开通状态'); return; }
    if (kind === 0) {
      if (typeof response.data !== 'string' || !response.data) throw new Error('支付二维码为空，请重试');
      M.paymentPayload = response.data;
      N.modal('扫码支付', '<div class="commerce-qr" id="nini-payment-qr"></div><p class="muted">请使用所选支付方式扫码。支付成功后会自动更新订单。</p><div class="row wrap">' + action('复制支付内容', 'commerce-copy-payment') + action('我已付款，刷新状态', 'commerce-order-refresh', '', 'primary') + '</div>');
      var qrTarget = document.getElementById('nini-payment-qr');
      if (N.qr) N.qr(response.data, qrTarget); else throw new Error('二维码组件尚未加载，请刷新后重试');
    } else if (kind === 1) {
      if (typeof response.data !== 'string') throw new Error('支付平台返回了无效链接');
      var url = N.safeURL(response.data);
      if (!url) throw new Error('支付平台返回了无效链接');
      N.modal('继续完成支付', '<p>请打开支付页面完成付款，付款后返回此页查看订单状态。</p><div class="row wrap"><a class="button primary" target="_blank" rel="noopener noreferrer" href="' + text(url) + '">打开支付页面</a>' + action('我已付款，刷新状态', 'commerce-order-refresh') + '</div>');
    } else if (kind === 2) {
      N.modal('付款已提交', '<p>正在等待支付平台确认。订单状态会自动更新，请勿重复付款。</p>' + action('刷新状态', 'commerce-order-refresh', '', 'primary'));
    } else throw new Error('无法识别支付平台的响应，请刷新订单确认状态');
    schedulePoll();
  });
  registerAction('commerce-copy-payment', function () { return N.copy(M.paymentPayload || ''); });

  function inviteURL(code) {
    var base = N.safeURL(N.state.guest.app_url) || window.location.origin;
    var url = new URL(base); url.hash = '/register?code=' + encodeURIComponent(code); url.search = '';
    return url.href;
  }
  function withdrawMethods() { return Array.isArray(N.state.config.withdraw_methods) ? N.state.config.withdraw_methods.filter(function (method) { return typeof method === 'string' && method; }) : []; }
  N.pages.invite = {
    load: async function () {
      var sequence = M.inviteSequence = (M.inviteSequence || 0) + 1, version = N.accountVersion();
      var responses = await Promise.all([N.api.get('/user/invite/fetch'), N.api.get('/user/invite/details', { current: M.invitePage, page_size: 10 })]);
      if (!sameAccount(version) || sequence !== M.inviteSequence) return null;
      var data = N.data(responses[0]) || {};
      M.invite = { codes: Array.isArray(data.codes) ? data.codes : [], stat: Array.isArray(data.stat) ? data.stat : [], details: N.list(responses[1]), total: num(responses[1].total) };
      return M.invite;
    },
    render: function () {
      var invite = M.invite || { codes: [], stat: [], details: [], total: 0 }, values = invite.stat;
      var withdraw = !N.on(N.state.config.withdraw_close) && withdrawMethods().length > 0;
      var commission = num(values[4]);
      var stats = '<div class="commerce-invite-stats">' + stat('已注册用户', text(num(values[0])) + '<small> 人</small>', 'users') + stat('佣金比例', text(num(values[3])) + '<small>%</small>', 'crown') +
        '<div class="card commerce-commission-wallet"><span class="commerce-stat-icon">' + polishedIcon('coin-stack','commerce-stat-original') + '</span><div class="commerce-commission-main"><span class="muted">剩余佣金</span><div class="row wrap between"><strong class="metric-value">' + money(commission) + '</strong><div class="row wrap">' + action('划转', 'commerce-transfer', commission > 0 ? '' : 'disabled') + (withdraw ? action('提现', 'commerce-withdraw', commission > 0 ? '' : 'disabled', 'primary') : '') + '</div></div></div></div></div>';
      var codes = '<div class="commerce-invite-codes">' + (invite.codes.length ? invite.codes.map(function (code) {
        return '<article class="commerce-invite-code"><div class="commerce-code-sticker">' + fragment('invite', 331, 641, 47, 77, 'commerce-code-heart') + '<div class="ticket-stamp" title="' + text(code.code) + '">' + '<span class="commerce-ticket-text">' + text(code.code) + '</span>' + ticketFrame('invite',384,637,300,81) + '</div>' + fragment('invite', 688, 639, 47, 76, 'commerce-code-star') + '</div><div class="commerce-copy-field">' + N.icon('copy') + '<input class="input" readonly aria-label="邀请链接" value="' + text(inviteURL(code.code)) + '"></div><div class="commerce-code-actions">' + action(N.icon('copy') + '复制链接', 'commerce-invite-copy', 'data-code="' + text(code.code) + '"', 'primary') + action(N.icon('refresh') + '生成邀请码', 'commerce-invite-create', '', 'secondary') + '</div></article>';
      }).join('') : N.empty('还没有邀请码，点击下方生成') + action('生成邀请码', 'commerce-invite-create')) + '</div>' + '<p class="muted small commerce-invite-hint">ⓘ　提示：通过您的邀请链接注册的用户，在完成符合返佣规则的订单后，即可为您带来佣金奖励。</p>' + fragment('invite', 676, 905, 73, 48, 'commerce-invite-bow');
      var distribution = '';
      if (N.on(N.state.config.commission_distribution_enable)) distribution = '<div class="notice small">分级返佣比例：' + ['l1', 'l2', 'l3'].filter(function (key) { return N.state.config['commission_distribution_' + key] != null; }).map(function (key) { return ({ l1: '一级', l2: '二级', l3: '三级' })[key] + ' ' + text((num(values[3]) * num(N.state.config['commission_distribution_' + key]) / 100).toLocaleString('zh-CN', { maximumFractionDigits: 4 })) + '%'; }).join(' · ') + '</div>';
      M.inviteSummary = '<div class="row between wrap commerce-commission-summary"><span>累计佣金 <strong>' + money(num(values[1])) + '</strong></span><span>确认中 <strong>' + money(num(values[2])) + '</strong></span></div>' + distribution;
      var details = '<div class="table-wrap commerce-invite-records"><table><thead><tr><th>时间</th><th>订单金额</th><th>佣金</th><th>状态</th></tr></thead><tbody>' + (invite.details.length ? invite.details.map(function (item) {
        return '<tr title="订单号：' + text(item.trade_no) + '"><td>' + text(N.date(item.created_at, true)) + '</td><td>' + money(item.order_amount) + '</td><td>' + money(item.get_amount) + '</td><td>' + N.badge('已确认', 'success') + '</td></tr>';
      }).join('') : emptyRow(4, '暂无佣金发放记录')) + '</tbody></table></div>' + fragment('invite', 792, 838, 712, 106, 'commerce-invite-thanks') + '<div class="commerce-invite-pagination">' + action('‹', 'commerce-invite-page', 'aria-label="上一页" data-page="' + (M.invitePage - 1) + '"' + (M.invitePage <= 1 ? ' disabled' : '')) + '<span>' + M.invitePage + ' / ' + Math.max(1, Math.ceil(invite.total / 10)) + '</span>' + action('›', 'commerce-invite-page', 'aria-label="下一页" data-page="' + (M.invitePage + 1) + '"' + (M.invitePage * 10 >= invite.total ? ' disabled' : '')) + action('佣金统计', 'commerce-invite-summary') + '</div>';
      return '<div class="commerce-page commerce-page-invite">' + fidelityHero('invite') + stats + '<div class="commerce-invite-layout">' + fidelityCard('invite', '邀请码管理', '分享您的专属邀请码，邀请好友注册使用', [326, 562, 51, 58], codes, 'commerce-invite-card') + fidelityCard('invite', '佣金发放记录', '以下是通过您的邀请产生的佣金记录', [792, 559, 58, 62], details, 'commerce-invite-details') + '</div></div>';
    },
    patch: function () { return patchView('invite', ['.commerce-invite-stats', '.commerce-invite-codes', '.commerce-invite-records tbody', '.commerce-invite-pagination']); }
  };
  registerAction('commerce-invite-create', async function () { await N.api.get('/user/invite/save'); await N.refresh(); N.toast('邀请码已生成'); });
  registerAction('commerce-invite-summary', function () { N.modal('佣金统计', M.inviteSummary || N.empty('暂无佣金信息')); });
  registerAction('commerce-invite-copy', function (el) { return N.copy(inviteURL(el.dataset.code)); });
  registerAction('commerce-invite-page', async function (el) {
    var page = Math.max(1, num(el.dataset.page)), context = M.invite, version = N.accountVersion();
    if (!context || page === M.invitePage || page > Math.max(1, Math.ceil(context.total / 10))) return;
    var response = await N.api.get('/user/invite/details', { current: page, page_size: 10 });
    if (!sameAccount(version) || M.invite !== context || N.route().key !== 'invite') return;
    M.invitePage = page; context.details = N.list(response); context.total = num(response.total);
    if (!patchView('invite', ['.commerce-invite-records tbody', '.commerce-invite-pagination'])) N.renderCurrent();
  });
  registerAction('commerce-transfer', function () {
    var available = M.invite ? num(M.invite.stat[4]) : num(N.state.user.commission_balance);
    N.modal('佣金划转到余额', '<p>可划转佣金 <strong>' + money(available) + '</strong>。划转后可用于购买订阅。</p><form data-form="commerce-transfer">' + N.field('划转金额', input('amount', 'text', '最多两位小数', (available / 100).toFixed(2), 'required inputmode="decimal" autocomplete="off"')) + formNote('划转操作完成后，金额将从佣金转入账户余额。') + submit('确认划转') + '</form>');
  });
  registerForm('commerce-transfer', async function (form, values) {
    var amount = cents(values.amount), available = M.invite ? num(M.invite.stat[4]) : num(N.state.user.commission_balance);
    if (amount > available) throw new Error('划转金额超过可用佣金');
    await N.api.post('/user/transfer', { transfer_amount: amount }); N.closeModal(); await N.refresh(); N.toast('佣金已划转到余额');
  });
  registerAction('commerce-withdraw', function () {
    var methods = withdrawMethods();
    if (N.on(N.state.config.withdraw_close) || !methods.length) throw new Error('站点当前未开放佣金提现');
    N.modal('申请佣金提现', '<form data-form="commerce-withdraw">' + N.field('提现方式', '<select class="input" name="withdraw_method" required>' + methods.map(function (method) { return '<option value="' + text(method) + '">' + text(method) + '</option>'; }).join('') + '</select>') + N.field('收款账号', input('withdraw_account', 'text', '请确认收款账号准确', '', 'required autocomplete="off"')) + formNote('提交后会创建提现工单，最低提现金额和处理结果由站点审核。') + submit('确认提交提现申请') + '</form>');
  });
  registerForm('commerce-withdraw', async function (form, values) {
    if (N.on(N.state.config.withdraw_close) || withdrawMethods().indexOf(values.withdraw_method) < 0) throw new Error('该提现方式当前不可用');
    var account = String(values.withdraw_account || '').trim(); if (!account) throw new Error('请输入收款账号');
    await N.api.post('/user/ticket/withdraw', { withdraw_method: values.withdraw_method, withdraw_account: account });
    N.closeModal(); await N.refreshCore(); N.go('ticket'); N.toast('提现申请已提交，可在工单中查看进度');
  });

  function settingSwitch(field, title, description) {
    var enabled = N.on(N.state.user[field]);
    var icon = { auto_renewal: 'refresh', remind_traffic: 'chart', remind_expire: 'clock' }[field];
    return '<div class="commerce-setting-row"><span class="commerce-setting-icon">' + profileIcon(icon) + '</span><div><strong>' + title + '</strong>' + (description ? '<p class="muted small">' + description + '</p>' : '') + '</div><button type="button" class="switch ' + (enabled ? 'on' : '') + '" data-action="commerce-setting" data-field="' + field + '" role="switch" aria-checked="' + enabled + '" aria-label="' + title + '"><span></span></button></div>';
  }
  function profileAvatar(value) {
    var source = N.safeURL(value);
    if (source) {
      var url = new URL(source);
      // The backend supplies an identicon even when no personal avatar exists.
      if (url.hostname === 'cravatar.cn' && /^\/avatar\/[a-f0-9]{32}$/i.test(url.pathname) && url.searchParams.get('d') === 'identicon') {
        url.searchParams.set('d', '404');
        url.searchParams.set('s', '320');
        source = url.href;
      }
    }
    return '<span class="commerce-avatar commerce-avatar-frame" role="img" aria-label="账户头像">' + fragment('profile', 339, 238, 160, 156, 'commerce-avatar-default', {lettering:false}) + (source ? '<img class="commerce-avatar-photo" src="' + text(source) + '" alt="">' : '') + '</span>';
  }
  // Capture also covers new avatar nodes inserted by profile.patch.
  document.addEventListener('load', function (event) {
    var photo = event.target;
    if (photo instanceof HTMLImageElement && photo.matches('.commerce-avatar-photo')) photo.classList.toggle('loaded', photo.naturalWidth > 0);
  }, true);
  document.addEventListener('error', function (event) {
    var photo = event.target;
    if (photo instanceof HTMLImageElement && photo.matches('.commerce-avatar-photo')) photo.classList.remove('loaded');
  }, true);
  N.pages.profile = {
    load: async function () { return {}; },
    render: function () {
      var user = N.state.user, sub = N.state.sub, plan = sub.plan;
      var identity = '<section class="card commerce-identity"><div class="commerce-identity-account">' + profileAvatar(user.avatar_url) + '<div><h2>' + text(user.email) + '</h2><span class="commerce-identity-plan">' + N.icon('crown') + text(plan && plan.name || '暂无订阅') + '</span><p class="muted">感谢一路有你，和 ' + text(N.settings && N.settings.title || '我们') + ' 一起遇见更好的网络体验！♡</p></div></div><div class="commerce-identity-meta">' + letteringFragment('profile', 904, 315, 172, 78, 'commerce-identity-slogan') + identityPaws() + '<div>' + row(N.icon('calendar') + '注册时间', text(N.date(user.created_at))) + row(N.icon('clock') + '套餐到期', !user.plan_id ? '暂无订阅' : user.expired_at == null ? '长期有效' : text(N.date(user.expired_at))) + row(N.icon('diamond') + '当前套餐', text(plan && plan.name || '暂无订阅')) + '</div></div></section>';
      var wallet = '<div class="row between commerce-wallet-balance"><div class="commerce-wallet-ticket ticket-stamp">' + ticketFrame('profile',331,506,309,95) + polishedIcon('wallet-dark','commerce-wallet-graphic') + '<div><span>账户余额</span><strong>' + money(num(user.balance)) + '</strong></div></div>' + action(polishedIcon('coin','commerce-recharge-coin') + '充值', 'commerce-deposit', '', 'primary') + '</div>' + settingSwitch('auto_renewal', '自动续费', '开启后将在套餐到期时自动续费');
      var gift = '<form class="inline-form" data-form="commerce-gift"><span class="commerce-gift-input-icon">' + profileIcon('gift') + '</span>' + input('giftcard', 'text', '请输入礼品卡码', '', 'required autocomplete="off" aria-label="礼品卡码"') + submit('兑 换') + '</form>' + '<div class="commerce-gift-footer">' + fragment('profile', 314, 813, 592, 186, 'commerce-original-gift-footer') + '</div>';
      var security = '<div class="commerce-setting-row"><span class="commerce-setting-icon">' + profileIcon('shield') + '</span><div><strong>修改密码</strong><p class="muted small">为了账户安全，建议定期更换密码</p></div>' + action('修改　›', 'commerce-password') + '</div>';
      security += '<div class="commerce-setting-row"><span class="commerce-setting-icon">' + profileIcon('reset') + '</span><div><strong>重置订阅信息</strong><p class="muted small">重置后需重新导入客户端</p></div>' + action('重置', 'commerce-reset-security', '', 'secondary') + '</div>';
      var hasActivePlan = num(user.plan_id) > 0 && (user.expired_at === null || num(user.expired_at) * 1000 > Date.now());
      var telegramGroup = hasActivePlan ? N.safeURL(N.state.config.telegram_discuss_link) : '';
      if (telegramGroup) security += '<div class="commerce-setting-row commerce-telegram-group"><span class="commerce-setting-icon">' + profileIcon('arrow') + '</span><div><strong>TG 交流群</strong><p class="muted small">交流使用心得，获取最新消息</p></div><a class="button secondary" href="' + text(telegramGroup) + '" target="_blank" rel="noopener noreferrer">加入群聊</a></div>';
      security += '<section class="commerce-notifications"><header><span class="commerce-setting-icon">' + profileIcon('mail') + '</span><div><h3>邮件通知</h3><p class="muted small">及时接收账户提醒</p></div></header>' + settingSwitch('remind_traffic', '流量邮件提醒', '') + settingSwitch('remind_expire', '到期邮件提醒', '') + '</section>';
      return '<div class="commerce-page commerce-page-profile"><div class="commerce-profile-intro">' + fragment('profile',309,86,1206,334,'commerce-profile-surface',{background:'surface',parallax:true,hideLettering:[[904,315,172,78]]}) + fidelityHero('profile') + identity + '</div><div class="grid two commerce-profile-grid"><div class="stack">' + fidelityCard('profile', '我的钱包', '账户余额可用于购买订阅、续费套餐等', [326, 439, 57, 61], wallet, 'commerce-wallet-card') + fidelityCard('profile', '礼品卡兑换', '输入礼品卡码兑换余额或套餐', [326, 699, 56, 57], gift, 'commerce-gift-card') + '</div>' + fidelityCard('profile', '安全设置', '保护您的账户安全', [936, 440, 61, 64], security, 'commerce-security') + '</div></div>';
    },
    patch: function () { return patchView('profile', ['.commerce-identity', '.commerce-wallet-card .card-body', '.commerce-security .card-body']); }
  };
  registerAction('commerce-deposit', function () {
    N.modal('账户余额充值', '<form data-form="commerce-deposit">' + N.field('充值金额', input('amount', 'text', '请输入充值金额', '', 'required inputmode="decimal" autocomplete="off"')) + formNote('金额最多保留两位小数。生成订单后选择支付方式；到账金额与赠送金额以订单为准。') + submit('确认生成充值订单') + '</form>');
  });
  registerForm('commerce-deposit', async function (form, values) {
    var amount = cents(values.amount); if (amount >= 9999999) throw new Error('充值金额过大，请联系站点客服');
    var trade = N.data(await N.api.post('/user/order/save', { plan_id: 0, period: 'deposit', deposit_amount: amount }));
    if (!trade) throw new Error('订单号为空，请查看订单列表确认结果');
    N.closeModal(); N.go('order/' + encodeURIComponent(trade));
  });
  registerForm('commerce-gift', function (form, values) {
    var code = String(values.giftcard || '').trim(); if (!code) throw new Error('请输入礼品卡码');
    confirm('确认兑换礼品卡', '兑换后将立即将礼品卡对应权益应用到当前账户。', 'redeem-giftcard', async function () {
      await N.api.post('/user/redeemgiftcard', { giftcard: code }); form.reset(); N.closeModal(); await N.refresh(); N.toast('礼品卡兑换成功，账户权益已更新');
    });
  });
  registerAction('commerce-setting', async function (el) {
    var field = el.dataset.field; if (['auto_renewal', 'remind_expire', 'remind_traffic'].indexOf(field) < 0) return;
    var value = N.on(N.state.user[field]) ? 0 : 1;
    var save = async function () { var body = {}; body[field] = value; await N.api.post('/user/update', body); N.closeModal(); await N.refreshCore(); N.renderCurrent(); N.toast('设置已保存'); };
    if (field === 'auto_renewal' && value) confirm('开启自动续费', '开启后，站点将按续费规则从账户余额支付续费订单。', 'auto-renewal-setting', save); else await save();
  });
  registerAction('commerce-password', function () {
    N.modal('修改密码', '<form data-form="commerce-password">' + N.field('当前密码', input('old_password', 'password', '请输入当前密码', '', 'required autocomplete="current-password"')) + N.field('新密码', input('new_password', 'password', '至少 8 位', '', 'required minlength="8" autocomplete="new-password"')) + N.field('确认新密码', input('confirm_password', 'password', '请再次输入新密码', '', 'required minlength="8" autocomplete="new-password"')) + formNote('修改成功后所有登录会话会退出，请使用新密码重新登录。') + submit('确认修改密码') + '</form>');
  });
  registerForm('commerce-password', async function (form, values) {
    if (values.new_password !== values.confirm_password) throw new Error('两次输入的新密码不一致');
    if (String(values.new_password || '').length < 8) throw new Error('新密码至少 8 位');
    await N.api.post('/user/changePassword', { old_password: values.old_password, new_password: values.new_password });
    form.reset(); N.closeModal(); N.authExpired(); N.toast('密码已修改，请重新登录');
  });
  registerAction('commerce-reset-security', function () {
    confirm('重置订阅信息', '原订阅链接和连接凭据会立即失效。所有客户端都需要重新导入新订阅，是否继续？', 'reset-subscription', async function () {
      await N.api.get('/user/resetSecurity'); N.closeModal(); await N.refreshCore(); N.renderCurrent(); N.toast('订阅信息已重置，请到仪表盘复制新订阅');
    });
  });
}());
