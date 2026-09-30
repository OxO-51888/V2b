(function () {
  'use strict';
  const settings = window.settings || {}, theme = settings.theme_config || {};
  const N = window.Nini = {pages: {}, actions: {}, forms: {}, settings, theme};
  const root = document.getElementById('nini-root');
  if (root) root.dataset.decorationMotion = ['on','off'].includes(theme.decoration_motion) ? theme.decoration_motion : 'auto';
  const readAuth = () => localStorage.getItem('authorization') || localStorage.getItem('nini_auth_data') || localStorage.getItem('auth_data') || '';
  N.state = {auth: readAuth(), user: {}, sub: {}, stat: [], notice: [], config: {}, guest: {}, route: null};
  const S = N.state;
  let renderID = 0, sessionID = 0, coreLoaded = false, routeData, modalCallback, captchaID, sendAt = 0, chromeHeader, chromeKey;
  let coreSync = null, coreSyncedAt = 0;
  const coreMaxAge = 30000, accountResets = new Set();
  N.accountVersion = () => sessionID;
  N.onAccountReset = callback => {accountResets.add(callback);return () => accountResets.delete(callback);};
  N.e = value => String(value == null ? '' : value).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  N.on = value => value === true || value === 1 || value === '1';
  N.data = r => r && r.data;
  N.list = r => Array.isArray(N.data(r)) ? r.data : [];
  N.money = cents => `${S.config.currency_symbol || S.config.currency || '¥'}${(Number(cents || 0) / 100).toLocaleString('zh-CN', {minimumFractionDigits:2, maximumFractionDigits:2})}`;
  N.bytes = value => {let n = Number(value || 0), i = 0; const units = ['B','KB','GB','TB']; units[2] = 'MB'; units.push('PB'); const u = ['B','KB','MB','GB','TB','PB']; while (Math.abs(n) >= 1024 && i < 5) {n /= 1024; i++;} return `${n.toLocaleString('zh-CN', {maximumFractionDigits:2})} ${u[i]}`;};
  N.date = (ts, time = false) => ts == null || ts === '' ? '—' : (!Number(ts) ? '—' : new Date(Number(ts)*1000).toLocaleString('zh-CN', {year:'numeric',month:'2-digit',day:'2-digit',...(time ? {hour:'2-digit',minute:'2-digit'} : {})}).replaceAll('/', '-'));
  N.safeURL = value => {if(value==null||!String(value).trim())return '';try {const u = new URL(String(value).trim(), location.href); return ['https:','http:'].includes(u.protocol) ? u.href : '';} catch (_) {return '';}};
  N.art = name => `${settings.assets_path || '/theme/NINI/assets'}/art/${['mascot','gift','headset'].includes(name) ? name : 'mascot'}.png`;
  const paths = {
    home:'M3 10 12 3l9 7M5 9v12h5v-7h4v7h5V9', chat:'M21 11a9 9 0 0 1-13 8l-5 2 1-5A9 9 0 1 1 21 11ZM8 10h.01M12 10h.01M16 10h.01', book:'M5 3h14v18H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Zm0 14h14M8 7h7M8 11h5', cart:'M2 3h3l3 12h11l3-9H6M9 20h.01M18 20h.01', pin:'M19 10c0 5-7 11-7 11S5 15 5 10a7 7 0 1 1 14 0ZM12 7a3 3 0 1 0 0 6 3 3 0 0 0 0-6', order:'M6 3h12v18l-3-2-3 2-3-2-3 2V3Zm3 5h6M9 12h6', users:'M8 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM1 21v-3a7 7 0 0 1 14 0v3M17 4a4 4 0 0 1 0 8M18 16c3 0 5 2 5 5', user:'M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10ZM3 22v-2a9 9 0 0 1 18 0v2Z', support:'M3 15v-4a9 9 0 0 1 18 0v4M3 11h3v8H3Zm15 0h3v8h-3ZM21 19c0 3-6 3-8 3', chart:'M4 20v-7M10 20V8M16 20V3M22 20V6', bell:'M4 17h16l-2-4V9a6 6 0 0 0-12 0v4ZM10 21h4', settings:'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8ZM9 2h6l1 3 3 1 3 4-2 3 1 4-4 3-4-1-3 2-4-3 1-4-2-3 3-4 3-1Z', wallet:'M3 6h18v15H3ZM3 6V3h15v3M16 11h5v5h-5Z', crown:'m3 6 5 5 4-8 4 8 5-5-3 15H6Z', refresh:'M20 7a9 9 0 0 0-15-2L2 8M2 2v6h6M4 17a9 9 0 0 0 15 2l3-3M22 22v-6h-6', copy:'M8 8h13v13H8ZM16 8V3H3v13h5', arrow:'M4 12h16m-6-6 6 6-6 6', clock:'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Zm0 4v5l4 3', calendar:'M3 5h18v16H3ZM7 2v6M17 2v6M3 10h18', device:'M3 3h12v18H3ZM18 10h5v11h-5M8 17h2', gift:'M2 8h20v5H2ZM4 13v9h16v-9M12 8v14M12 8C3 9 4 0 8 2c3 1 4 6 4 6Zm0 0c9 1 8-8 4-6-3 1-4 6-4 6', star:'m12 2 3 6 7 1-5 5 1 7-6-3-6 3 1-7-5-5 7-1Z', heart:'M12 21 3 12C-3 4 7-2 12 6c5-8 15-2 9 6Z', menu:'M3 6h18M3 12h18M3 18h18', close:'m5 5 14 14M5 19 19 5', logout:'M9 3H3v18h6M8 12h14m-5-5 5 5-5 5', shield:'m12 2 9 4v6c0 6-9 10-9 10S3 18 3 12V6Z', search:'M10 2a8 8 0 1 0 0 16 8 8 0 0 0 0-16Zm6 14 6 6', mail:'M2 4h20v16H2ZM2 4l10 9L22 4', diamond:'m3 3 18 0 2 6-11 13L1 9ZM1 9h22M8 3l-2 6 6 13 6-13-2-6'
  };
  N.icon = name => `<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="${paths[name] || paths.star}"/></svg>`;
  const clientImportEndpoints = {
    hiddify:'hiddify://import/', 'sing-box':'sing-box://import-remote-profile?',
    shadowrocket:'shadowrocket://add/sub://', 'quantumult-x':'quantumult-x:///update-configuration?',
    surge:'surge:///install-config?', surfboard:'surfboard:///install-config?', stash:'stash://install-config?', loon:'loon://import?',
    v2rayng:'v2rayng://install-sub?', mihomo:'mihomo://install-config?', clash:'clash://install-config?'
  };
  const richURIs = new RegExp('^(?:(?:https?|mailto|tel):|' + Object.values(clientImportEndpoints).map(value=>value.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')).join('|') + '|[^a-z]|[a-z][a-z0-9+.-]*(?:[^a-z0-9+.:-]|$))','i');
  let noticeMarkdown;
  N.rich = (text,notice=false) => {
    const src=String(text || '');
    if (!window.DOMPurify) return N.e(src).replace(/\n/g,'<br>');
    let parser=window.marked;
    if (parser && notice) {
      // Menu separators such as Settings~Method~ICMP are not deletions.
      noticeMarkdown ||= new parser.Marked({tokenizer:{del(source){
        if (source.startsWith('~~')) return false;
      }}});
      parser=noticeMarkdown;
    }
    const html=parser ? parser.parse(src,{breaks:true}) : src;
    return DOMPurify.sanitize(html,{USE_PROFILES:{html:true},FORBID_TAGS:['style','form','input','button','iframe'],FORBID_ATTR:['style','srcset'],ALLOWED_URI_REGEXP:richURIs});
  };
  N.qr = (text, element) => {element.replaceChildren(); if (!window.QRCode) throw new Error('二维码组件未加载，请刷新重试'); return new QRCode(element, {text:String(text),width:220,height:220,correctLevel:QRCode.CorrectLevel.M});};
  N.button = (label, action, attrs = '', kind = 'primary') => `<button type="button" class="button ${kind}" data-action="${N.e(action)}" ${attrs}>${label}</button>`;
  N.input = (name,type='text',placeholder='',value='') => `<input class="input" name="${N.e(name)}" type="${N.e(type)}" placeholder="${N.e(placeholder)}" value="${N.e(value)}">`;
  N.select = (name,html) => `<select class="input" name="${N.e(name)}">${html}</select>`;
  N.field = (label,html) => `<label class="field"><span>${N.e(label)}</span>${html}</label>`;
  N.badge = (label,tone='') => `<span class="badge ${N.e(tone)}">${N.e(label)}</span>`;
  N.empty = text => `<div class="empty">${N.icon('heart')}<p>${N.e(text)}</p></div>`;
  N.card = (title,body,extra='') => `<section class="card ${extra}">${title ? `<header class="card-head"><h2>${title}</h2><span class="tiny-star">✧</span></header>` : ''}<div class="card-body">${body}</div></section>`;
  N.subscriptionExplanations = (items,showEmpty=false) => {
    const text = value => typeof value === 'string' ? value.trim() : '';
    const states = {blocked:['已保护订阅','danger'],reset:['已重置订阅','danger'],allowed:['已放行','success'],reviewed:['已审查','warning'],recorded:['已记录','info']};
    const rows = (Array.isArray(items) ? items : []).map(item => {
      const value = typeof item === 'string' ? {message:item} : item && typeof item === 'object' && !Array.isArray(item) ? item : {};
      const title = text(value.title), summary = text(value.summary) || text(value.message) || text(value.explanation) || text(value.reason), advice = text(value.advice);
      if (!title && !summary && !advice) return '';
      const state = Object.prototype.hasOwnProperty.call(states,value.status) ? states[value.status] : null;
      const severity = ['danger','success','warning','info'].includes(value.severity) ? value.severity : state?.[1] || 'info';
      const status = text(value.status_text) || state?.[0] || '';
      const timestamp = (typeof value.created_at === 'number' || typeof value.created_at === 'string') && Number(value.created_at) > 0 ? Number(value.created_at) : 0;
      const date = timestamp && Number.isFinite(timestamp) && Number.isFinite(new Date(timestamp * 1000).getTime()) ? new Date(timestamp * 1000) : null;
      const client = text(value.client);
      const metadata = (date ? `<time datetime="${N.e(date.toISOString())}">${N.e(N.date(timestamp,true))}</time>` : '') + (client ? `<span>客户端：${N.e(client)}</span>` : '');
      return `<article class="subscription-explanation severity-${severity}"><div class="subscription-explanation-heading"><h3>${N.e(title || '订阅提醒')}</h3>${status ? `<span class="subscription-explanation-status">${N.e(status)}</span>` : ''}${N.on(value.admin_preview) && status !== '管理员预览' ? '<span class="subscription-explanation-preview">管理员预览</span>' : ''}</div>${metadata ? `<div class="subscription-explanation-meta">${metadata}</div>` : ''}${summary ? `<p class="subscription-explanation-summary">${N.e(summary)}</p>` : ''}${advice ? `<div class="subscription-explanation-advice"><strong>建议</strong><p>${N.e(advice)}</p></div>` : ''}</article>`;
    }).filter(Boolean).slice(0,3);
    const body = rows.length ? rows.join('') : showEmpty ? '<p class="subscription-safety-empty">暂无订阅安全提醒</p>' : '';
    return body ? N.card(N.icon('shield')+' 订阅安全小提醒',body,'subscription-status-card fidelity-subscription-status').replace('<div class="card-body">','<div class="card-body" role="region" aria-label="订阅安全提醒列表" tabindex="0">') : '';
  };
  N.hero = (title,subtitle,art='mascot') => `<header class="hero compact"><div class="hero-copy"><span class="ribbon-label">${N.icon('star')} ${N.e(settings.title || '用户中心')}</span><h1>${N.e(title)} <span>♡</span></h1><p>${N.e(subtitle)}</p></div><img class="hero-art" src="${N.e(N.art(art))}" alt=""/><span class="hero-seal" aria-hidden="true">♡</span></header>`;
  N.formValues = form => Object.fromEntries(new FormData(form));
  N.error = error => N.toast(error.message || '请求失败，请稍后重试');
  N.toast = text => {const el=document.createElement('div'); el.className='nini-toast'; el.setAttribute('role','status'); el.textContent=text; document.body.append(el); setTimeout(()=>el.remove(),4500);};
  N.copy = async (text,message='已复制') => {if (!text) throw new Error('暂无可复制的内容'); if (navigator.clipboard && window.isSecureContext) await navigator.clipboard.writeText(String(text)); else {const a=document.createElement('textarea'); a.value=String(text); a.style.position='fixed'; a.style.opacity='0'; document.body.append(a); a.select(); const copied=document.execCommand('copy'); a.remove(); if (!copied) throw new Error('复制失败，请手动选择复制');} N.toast(message);};
  let closeActiveModal = null;
  N.modal = (title,body,contentTitle=false) => {
    setMobileMenu(false);N.closeModal();
    let previousFocus=document.activeElement;
    // Disabling a submitting button can move browser focus to body before an async confirmation opens.
    if(previousFocus===document.body||previousFocus===document.documentElement){
      const triggers=document.querySelectorAll('[data-action][data-busy="1"],form[data-busy="1"] [type="submit"]');
      if(triggers.length===1)previousFocus=triggers[0];
    }
    const el=document.createElement('dialog');
    const native=typeof el.showModal==='function'&&typeof el.close==='function';
    let removed=false,backdrop,restoreScroll;
    el.className='nini-app modal';
    el.setAttribute('role','dialog');el.setAttribute('aria-modal','true');el.setAttribute('aria-labelledby','nini-modal-title');
    el.innerHTML=`<header class="card-head"><h2 id="nini-modal-title" class="${contentTitle?'':'nini-ui-text'}">${N.e(title)}</h2>${N.button(N.icon('close'),'close-modal','aria-label="关闭"','icon-button')}</header><div class="modal-body">${body}</div>`;
    const controls=()=>Array.from(el.querySelectorAll('a[href],button,input:not([type="hidden"]),select,textarea,[tabindex],[contenteditable="true"]')).filter(item=>!item.disabled&&item.tabIndex>=0&&item.getClientRects().length);
    const focusFirst=()=>{(controls()[0]||el).focus({preventScroll:true});};
    const keepFocus=event=>{if(!el.contains(event.target))focusFirst();};
    const keydown=event=>{
      if(event.key==='Escape'){event.preventDefault();event.stopPropagation();close();return;}
      if(event.key!=='Tab')return;
      const items=controls(),first=items[0],last=items[items.length-1],active=document.activeElement;
      if(!first){event.preventDefault();el.focus({preventScroll:true});}
      else if(event.shiftKey&&(active===first||active===el||!el.contains(active))){event.preventDefault();last.focus();}
      else if(!event.shiftKey&&(active===last||active===el||!el.contains(active))){event.preventDefault();first.focus();}
    };
    const cleanup=()=>{
      if(removed)return;removed=true;
      document.removeEventListener('keydown',keydown,true);document.removeEventListener('focusin',keepFocus,true);
      if(backdrop)backdrop.remove();el.remove();
      if(restoreScroll)restoreScroll();
      if(closeActiveModal===close){closeActiveModal=null;modalCallback=null;}
      if(previousFocus&&previousFocus.isConnected&&typeof previousFocus.focus==='function')previousFocus.focus({preventScroll:true});
    };
    const close=()=>{try{if(native&&el.open)el.close();}finally{cleanup();}};
    closeActiveModal=close;
    el.addEventListener('click',event=>{if(event.target===el)close();});
    el.addEventListener('close',cleanup);
    el.addEventListener('cancel',event=>{event.preventDefault();close();});
    if(native){
      document.body.append(el);
      try{el.showModal();}catch(error){cleanup();throw error;}
    }else{
      // Older WebKit has neither dialog method; keep the same content and styles.
      const x=window.scrollX,y=window.scrollY,bodyStyle=document.body.style,htmlStyle=document.documentElement.style;
      const saved=['position','top','left','width','overflow'].map(key=>[key,bodyStyle.getPropertyValue(key),bodyStyle.getPropertyPriority(key)]);
      const htmlOverflow=[htmlStyle.getPropertyValue('overflow'),htmlStyle.getPropertyPriority('overflow')];
      restoreScroll=()=>{
        saved.forEach(([key,value,priority])=>{if(value)bodyStyle.setProperty(key,value,priority);else bodyStyle.removeProperty(key);});
        if(htmlOverflow[0])htmlStyle.setProperty('overflow',htmlOverflow[0],htmlOverflow[1]);else htmlStyle.removeProperty('overflow');
        window.scrollTo(x,y);
      };
      bodyStyle.setProperty('position','fixed');bodyStyle.setProperty('top',`${-y}px`);bodyStyle.setProperty('left',`${-x}px`);
      bodyStyle.setProperty('width','100%');bodyStyle.setProperty('overflow','hidden');htmlStyle.setProperty('overflow','hidden');
      backdrop=document.createElement('div');backdrop.className='nini-modal-backdrop';backdrop.setAttribute('aria-hidden','true');
      backdrop.addEventListener('click',close);
      el.classList.add('nini-modal-fallback');el.setAttribute('open','');el.setAttribute('tabindex','-1');
      document.body.append(backdrop,el);
      document.addEventListener('keydown',keydown,true);document.addEventListener('focusin',keepFocus,true);
      focusFirst();
    }
    return el;
  };
  N.closeModal = () => {if(closeActiveModal)closeActiveModal();modalCallback=null;};
  N.confirm = (title,text,callback) => {N.modal(title,`<p>${N.e(text)}</p><div class="row end">${N.button('取消','close-modal','','secondary')}${N.button('确认','confirm')}</div>`); modalCallback=callback;};
  N.actions['close-modal']=N.closeModal;
  N.actions.confirm=async()=>{const cb=modalCallback; if(cb){await cb();N.closeModal();}};
  // Reuse only bounded, account-scoped reads. Payment checks and actions always reach the server.
  const cachedReads=new Map(),pendingReads=new Map(),readLifetime=15000;
  let readGeneration=0;
  const reusableReads=new Set(['/user/notice/fetch','/user/plan/fetch','/user/server/fetch','/user/stat/getTrafficLog','/user/knowledge/fetch','/user/ticket/fetch','/user/order/fetch','/user/order/detail','/user/order/getPaymentMethod','/user/invite/fetch','/user/invite/details']);
  function invalidateReads(){readGeneration++;cachedReads.clear();pendingReads.clear();}
  function copyResponse(value){return typeof structuredClone==='function'?structuredClone(value):JSON.parse(JSON.stringify(value));}
  async function readRequest(path,query){
    if(!reusableReads.has(path)){
      if(['/user/resetSecurity','/user/unbindTelegram'].includes(path)){invalidateReads();try{return await request('GET',path,query);}finally{invalidateReads();}}
      return request('GET',path,query);
    }
    const version=sessionID,authorization=S.auth,generation=readGeneration,params=new URLSearchParams();
    Object.entries(query||{}).forEach(([key,value])=>{if(value!=null)params.set(key,value);});params.sort();
    const key=path+'?'+params,entry=cachedReads.get(key),retain=path!=='/user/order/detail'&&!(path==='/user/ticket/fetch'&&params.has('id'));
    let value;
    if(entry&&entry.until>Date.now())value=entry.value;
    else{
      cachedReads.delete(key);let pending=pendingReads.get(key);
      if(!pending){
        pending=request('GET',path,query).then(result=>{
          if(retain&&generation===readGeneration&&version===sessionID&&authorization===S.auth){
            if(cachedReads.size>=64)cachedReads.delete(cachedReads.keys().next().value);
            cachedReads.set(key,{until:Date.now()+readLifetime,value:result});
          }
          return result;
        }).finally(()=>{if(pendingReads.get(key)===pending)pendingReads.delete(key);});
        pendingReads.set(key,pending);
      }
      value=await pending;
    }
    if(version!==sessionID||authorization!==S.auth)throw new Error('登录已变化，请重试');
    return copyResponse(value);
  }
  N.api = {get:readRequest,post:async(path,body)=>{invalidateReads();try{return await request('POST',path,body);}finally{invalidateReads();}}};
  async function request(method,path,payload) {
    if (window.NINI_PREVIEW === true && typeof window.NINI_TRANSPORT === 'function') return window.NINI_TRANSPORT(method,path,payload || {});
    const controller=new AbortController(), timeout=setTimeout(()=>controller.abort(),25000), session=sessionID, authorization=S.auth;
    try {
      const q=new URLSearchParams(); if(method==='GET')Object.entries(payload||{}).forEach(([k,v])=>{if(v!=null)q.set(k,v);});
      const res=await fetch(`/api/v1${path}${q.size ? '?'+q : ''}`,{method,signal:controller.signal,headers:{Accept:'application/json',...(authorization?{Authorization:authorization}:{}),...(method==='POST'?{'Content-Type':'application/json'}:{})},...(method==='POST'?{body:JSON.stringify(payload||{})}:{})});
      let data; try {data=await res.json();} catch(_){throw new Error('服务返回异常，请稍后重试');}
      if(path.startsWith('/user/')&&session!==sessionID)throw new Error('登录已变化，请重试');
      if((res.status===401||res.status===403)&&path.startsWith('/user/')){N.authExpired(true);throw new Error('登录已过期，请重新登录');}
      if(!res.ok || data.errors){const detail=data.errors&&Object.values(data.errors).flat()[0],error=new Error(detail || data.message || `请求失败 (${res.status})`);error.status=res.status;error.serverMessage=data.message;throw error;}
      return data;
    } catch(error){if(error.name==='AbortError')throw new Error('连接超时，请重试');throw error;} finally {clearTimeout(timeout);}
  }
  function clearAccount(){sessionID++;renderID++;invalidateReads();root.classList.remove('navigation-pending');coreLoaded=false;coreSync=null;coreSyncedAt=0;routeData=undefined;Object.assign(S,{user:{},sub:{},stat:[],notice:[],config:{}});accountResets.forEach(reset=>{try{reset();}catch(error){console.error('Account state cleanup failed',error);}});if(document.getElementById('page-content'))root.innerHTML='<div class="boot-screen account-loading" role="status" aria-live="polite"><span class="account-loading-heart" aria-hidden="true">♡</span><span>正在准备你的专属空间…</span></div>';}
  N.authExpired = (returnToPage=false) => {const destination=typeof returnToPage==='string'?safeRedirect(returnToPage):returnToPage?safeRedirect(location.hash):'';S.auth='';clearAccount();if(window.NINI_PREVIEW!==true)for(const k of ['nini_auth_data','auth_data','authorization','token'])localStorage.removeItem(k);N.closeModal();N.go('login'+(destination?'?redirect='+encodeURIComponent(destination):''));};
  function bridgeAuth(){if(window.NINI_PREVIEW!==true)for(const k of ['nini_auth_data','auth_data','authorization'])localStorage.setItem(k,S.auth);}
  N.saveAuth = data => {if(!data||!data.auth_data)throw new Error('登录数据不完整');clearAccount();S.auth=data.auth_data;bridgeAuth();if(data.token&&window.NINI_PREVIEW!==true)localStorage.setItem('token',data.token);};
  N.refreshCore = () => {
    if(coreSync)return coreSync;
    invalidateReads();
    const session=sessionID,authorization=S.auth;
    const pending=(async()=>{
      const [user,sub,stat,config,guest]=await Promise.all([N.api.get('/user/info'),N.api.get('/user/getSubscribe'),N.api.get('/user/getStat'),N.api.get('/user/comm/config'),N.api.get('/guest/comm/config')]);
      if(session!==sessionID||authorization!==S.auth)throw new Error('登录已变化，请重试');
      Object.assign(S,{user:N.data(user)||{},sub:N.data(sub)||{},stat:N.data(stat)||[],config:N.data(config)||{},guest:N.data(guest)||{}});
      bridgeAuth();coreLoaded=true;coreSyncedAt=Date.now();
    })();
    coreSync=pending;
    return pending.finally(()=>{if(coreSync===pending)coreSync=null;});
  };
  const nav=[['dashboard','仪表盘','home'],['community','售后群聊','chat'],['knowledge','使用文档','book'],['plan','购买订阅','cart'],['node','节点状态','pin'],['order','我的订单','order'],['invite','我的邀请','users'],['profile','个人中心','user'],['ticket','我的工单','support'],['traffic','流量明细','chart']];
  N.communityEnabled=()=>N.on(settings.community_enabled ?? S.config.community_enabled ?? S.guest.community_enabled);
  N.route = () => {const raw=location.hash.replace(/^#\/?/,'')||(N.pages.landing&&theme.landing_enabled!=='off'?'landing':'dashboard'), [path,q='']=raw.split('?'), pieces=path.split('/'); const aliases={plans:'plan',orders:'order',nodes:'node',tickets:'ticket',subscribe:'dashboard',forget:'forgetpassword'};return {key:aliases[pieces[0]]||pieces[0],id:(()=>{try{return decodeURIComponent(pieces[1]||'');}catch(_){return '';}})(),query:new URLSearchParams(q)};};
  function safeRedirect(value){const v=String(value||'').replace(/^#?\//,'');return /^[a-z]+(?:\/[A-Za-z0-9_-]+)?(?:\?[^#]*)?$/.test(v)&&nav.some(x=>x[0]===v.split(/[/?]/)[0])?v:'dashboard';}
  N.go = route => {const path=String(route).replace(/^#?\/?/,'');if(location.hash==='#/'+path)N.refresh();else location.hash='/'+path;};
  const mobileDetailTargets={plan:'.commerce-purchase',order:'.commerce-order-detail',knowledge:'.knowledge-detail',ticket:'.ticket-detail'};
  N.revealMobileDetail = key => {
    if(!window.matchMedia('(max-width:1024px)').matches||N.route().key!==key||!mobileDetailTargets[key])return;
    const target=document.querySelector(mobileDetailTargets[key]),hash=location.hash,session=sessionID,page=renderID;
    if(!target)return;
    // Wait for route patching and focus restoration before revealing the stacked detail.
    window.requestAnimationFrame(()=>{
      if(!window.matchMedia('(max-width:1024px)').matches||!target.isConnected||location.hash!==hash||session!==sessionID||page!==renderID)return;
      target.setAttribute('tabindex','-1');target.focus({preventScroll:true});
      window.scrollTo({top:Math.max(0,window.scrollY+target.getBoundingClientRect().top-16),behavior:window.matchMedia('(prefers-reduced-motion: reduce)').matches?'instant':'smooth'});
    });
  };
  N.refresh = () => {invalidateReads();return render(true);};
  N.renderCurrent = () => {const route=S.route; const page=N.pages[route.key];let patched=false;if(page){const host=page.public?root:document.getElementById('page-content');if(!host)return;patched=!!(page.patch&&page.patch(route,routeData)===true);if(!patched){host.innerHTML=page.render(route,routeData);if(page.mounted)Promise.resolve(page.mounted(route,routeData)).catch(N.error);}}updateChrome();if(N.fitArtworkTypography)N.fitArtworkTypography();if(!patched&&N.refreshParallax)N.refreshParallax();};
  function brand(){if(N.brand)return N.brand();return `<a class="brand" href="#/dashboard">${settings.logo ? `<img src="${N.e(N.safeURL(settings.logo))}" alt="${N.e(settings.title)}">` : `<span class="brand-star">✦</span><strong>${N.e(settings.title || '用户中心')}</strong>`}<span class="brand-bow" aria-hidden="true">୨୧</span></a>`;}
  function expiry(){if(!S.sub.plan_id)return '暂无订阅';if(S.sub.expired_at===null)return '长期有效';const days=Math.ceil((Number(S.sub.expired_at)*1000-Date.now())/86400000);return days>0?`${days} 天后到期`:'已到期';}
  function account(){return `<div class="account"><div class="avatar">${N.avatar?N.avatar():'<img src="'+N.e(N.art('mascot'))+'" alt="">'}</div><div><strong>${N.e(S.user.email)}</strong><div class="row small">${N.badge(S.sub.plan?.name || '暂无订阅')}<span class="muted">${expiry()}</span></div></div></div><div class="top-actions"><a class="wallet-pill" href="#/profile">${N.coinIcon?N.coinIcon():N.icon('wallet')}<span>余额 ${N.e(N.money(S.user.balance))}</span></a>${N.button(N.headerIcon?N.headerIcon('bell'):N.icon('bell'),'notices','aria-label="公告"','icon-button')}${N.button(N.headerIcon?N.headerIcon('settings'):N.icon('settings'),'account-menu','aria-label="账户设置"','icon-button')}</div>`;}
  function shell(){root.className='nini-app';root.dataset.page=S.route.key;root.innerHTML=`<aside class="sidebar" id="nini-sidebar">${brand()}${N.button(N.icon('close'),'close-menu','aria-label="关闭导航"','icon-button mobile-nav-close')}<nav aria-label="主导航">${nav.filter(([k])=>k!=='community'||N.communityEnabled()).map(([k,label,icon])=>`<a href="#/${k}" class="nav-link ${S.route.key===k?'active':''}">${N.navIcon?N.navIcon(k,S.route.key===k):N.icon(icon)}<span>${label}</span><b aria-hidden="true">${N.icon('star')}</b></a>`).join('')}</nav><div class="sidebar-decoration">${N.fragment?N.fragment('dashboard',1,670,288,350,'sidebar-original-art',{parallax:true}):''}</div></aside><button type="button" class="mobile-nav-backdrop" data-action="close-menu" aria-label="关闭导航" tabindex="-1"></button><div class="main-shell"><header class="topbar">${N.button(N.icon('menu'),'menu','aria-label="展开导航" aria-expanded="false" aria-controls="nini-sidebar"','icon-button mobile-menu')}<div id="account-header">${account()}</div></header><main id="page-content" tabindex="-1"></main><footer>${N.e(settings.title || '')}<span>♡</span>${N.e(settings.description || '')}</footer></div>`;}
  function updateChrome(activeKey=S.route?.key){
    const header=document.getElementById('account-header'),key=JSON.stringify([S.user.email,N.money(S.user.balance),S.sub.plan?.name,expiry()]);
    if(header&&(header!==chromeHeader||key!==chromeKey)){header.innerHTML=account();chromeHeader=header;chromeKey=key;}
    const menu=document.querySelector('.sidebar nav'),community=menu?.querySelector('[href="#/community"]');
    if(community&&!N.communityEnabled())community.remove();
    else if(menu&&!community&&N.communityEnabled())menu.firstElementChild.insertAdjacentHTML('afterend',`<a href="#/community" class="nav-link">${N.navIcon?N.navIcon('community',false):N.icon('chat')}<span>售后群聊</span><b aria-hidden="true">${N.icon('star')}</b></a>`);
    document.querySelectorAll('.nav-link').forEach(a=>{const active=a.hash===`#/${activeKey}`;if(a.classList.contains('active')!==active&&N.navIcon&&a.firstElementChild)a.firstElementChild.outerHTML=N.navIcon(a.hash.slice(2),active);a.classList.toggle('active',active);});
  }
  function setMobileMenu(open, restoreFocus=false){
    root.classList.toggle('menu-open', open);
    const main=root.querySelector('.main-shell');if(main)main.inert=open;
    const toggle=root.querySelector('.mobile-menu');
    if(toggle){toggle.setAttribute('aria-expanded',String(open));toggle.setAttribute('aria-label',open?'收起导航':'展开导航');}
    if(open)root.querySelector('.mobile-nav-close')?.focus({preventScroll:true});
    else if(restoreFocus)toggle?.focus({preventScroll:true});
  }
  document.addEventListener('keydown',event=>{
    if(!root.classList.contains('menu-open')||!window.matchMedia('(max-width:1024px)').matches)return;
    if(event.key==='Escape'){event.preventDefault();setMobileMenu(false,true);return;}
    if(event.key!=='Tab')return;
    const controls=Array.from(root.querySelectorAll('.sidebar a[href],.sidebar button')).filter(el=>el.getClientRects().length&&!el.disabled);
    const first=controls[0],last=controls[controls.length-1];
    if(event.shiftKey&&document.activeElement===first){event.preventDefault();last?.focus();}
    else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first?.focus();}
  });
  window.matchMedia('(max-width:1024px)').addEventListener('change',()=>setMobileMenu(false));
  async function render(forceCore=false){const token=++renderID,route=N.route();S.route=route;N.closeModal();setMobileMenu(false);
    try {
      const publicPage=N.pages[route.key];
      if(publicPage&&publicPage.public){
        if(route.key==='landing'&&theme.landing_enabled==='off'){N.go(S.auth?'dashboard':'login');return;}
        root.className='nini-app landing-app';root.dataset.page=route.key;root.innerHTML='<div class="boot-screen">正在准备新的相遇…</div>';
        const data=publicPage.load?await publicPage.load(route):undefined;if(token!==renderID)return;routeData=data;N.renderCurrent();document.title=(settings.title||'用户中心')+' · 欢迎';return;
      }
      if(['login','register','forgetpassword'].includes(route.key) || !S.auth){await renderAuth(route,token);return;}
      let host=document.getElementById('page-content');const initialCore=!coreLoaded,focused=host?.contains(document.activeElement)?document.activeElement:null;
      if(host){host.inert=true;host.setAttribute('aria-busy','true');root.classList.add('navigation-pending');updateChrome();}
      if(!coreLoaded){root.innerHTML='<div class="boot-screen account-loading" role="status" aria-live="polite"><span class="account-loading-heart" aria-hidden="true">♡</span><span>正在准备你的专属空间…</span></div>';await N.refreshCore();}
      if(token!==renderID)return;
      host=document.getElementById('page-content');if(!host){shell();host=document.getElementById('page-content');host.innerHTML='<div class="loading">正在加载…</div>';}
      const page=N.pages[route.key],needsCore=!initialCore&&(forceCore===true||Date.now()-coreSyncedAt>=coreMaxAge);
      // Plan selection depends on the current subscription. Other route reads can overlap core refresh.
      if(needsCore&&(!page||route.key==='plan'||route.key==='community'))await N.refreshCore();
      if(token!==renderID)return;
      if(route.key==='community'&&!N.communityEnabled()){host.inert=false;root.dataset.page=route.key;host.innerHTML=N.empty('站点暂未开启群聊');updateChrome();return;}
      if(!page){host.inert=false;root.dataset.page=route.key;host.innerHTML=N.empty('页面不存在')+'<a href="#/dashboard">返回仪表盘</a>';updateChrome();return;}
      host.inert=true;host.setAttribute('aria-busy','true');root.classList.add('navigation-pending');
      const sync=needsCore&&route.key!=='plan'&&route.key!=='community'?N.refreshCore():null;
      const [data]=await Promise.all([page.load?page.load(route):undefined,sync]);
      if(token!==renderID)return;host.inert=false;root.dataset.page=route.key;routeData=data;N.renderCurrent();if(focused?.isConnected)focused.focus({preventScroll:true});if(!forceCore&&route.id)N.revealMobileDetail(route.key);document.title=`${nav.find(x=>x[0]===route.key)?.[1] || '用户中心'} · ${settings.title||''}`;
    }catch(error){if(token!==renderID)return;const host=document.getElementById('page-content')||root;host.inert=false;root.dataset.page=route.key;host.innerHTML=N.card('暂时无法加载',`<p role="alert">${N.e(error.message)}</p>${N.button('重新加载','refresh')}`);updateChrome();}
    finally{if(token===renderID){root.classList.remove('navigation-pending');const host=document.getElementById('page-content');if(host){host.inert=false;host.removeAttribute('aria-busy');}}}
  }
  const prefetchReads={dashboard:[['/user/notice/fetch',{current:1,pageSize:3}]],plan:[['/user/plan/fetch']],node:[['/user/server/fetch']],traffic:[['/user/stat/getTrafficLog']],invite:[['/user/invite/fetch'],['/user/invite/details',{current:1,page_size:10}]]};
  N.prefetchPage=async key=>{
    if(!S.auth||!coreLoaded||S.route?.key===key||!N.pages[key]||!nav.some(item=>item[0]===key))return;
    const page=N.pages[key];
    try{if(page.prefetch)await page.prefetch({key,id:'',query:new URLSearchParams()});else await Promise.all((prefetchReads[key]||[]).map(([path,query])=>N.api.get(path,query)));}catch(_){}
  };
  let prefetchTimer,prefetchLink;
  function hoverNavigation(event){
    const link=event.target.closest?.('.sidebar nav .nav-link');if(!link||link===prefetchLink)return;
    clearTimeout(prefetchTimer);prefetchLink=link;
    prefetchTimer=setTimeout(()=>{if(link.isConnected)N.prefetchPage(link.hash.slice(2));},80);
  }
  document.addEventListener('pointerover',hoverNavigation);
  document.addEventListener('focusin',hoverNavigation);
  document.addEventListener('pointerout',event=>{if(prefetchLink?.contains(event.target)&&!prefetchLink.contains(event.relatedTarget)){clearTimeout(prefetchTimer);prefetchLink=null;}});
  document.addEventListener('click',event=>{
    if(event.defaultPrevented||event.button!==0||event.ctrlKey||event.metaKey||event.shiftKey||event.altKey)return;
    const detailLink=event.target.closest?.('a.knowledge-link,a.ticket-list-row');
    if(detailLink&&root.contains(detailLink)&&detailLink.origin===location.origin&&detailLink.pathname===location.pathname&&detailLink.search===location.search&&(!detailLink.target||detailLink.target==='_self')&&!detailLink.hasAttribute('download')&&detailLink.hash===location.hash&&N.route().id&&window.matchMedia('(max-width:1024px)').matches){event.preventDefault();N.revealMobileDetail(N.route().key);return;}
    const link=event.target.closest?.('.sidebar nav .nav-link'),host=document.getElementById('page-content');
    if(link&&root.classList.contains('menu-open'))setMobileMenu(false,true);
    if(!link||!host||!S.auth||link.hash===location.hash)return;
    // Give pointer and keyboard activation feedback in this event, before hashchange runs.
    updateChrome(link.hash.slice(2));host.inert=true;host.setAttribute('aria-busy','true');root.classList.add('navigation-pending');
  });
  N.onAccountReset(()=>{clearTimeout(prefetchTimer);prefetchLink=null;});
  N.actions.refresh=N.refresh;N.actions.menu=()=>setMobileMenu(!root.classList.contains('menu-open'));N.actions['close-menu']=()=>setMobileMenu(false,true);N.actions.logout=()=>N.confirm('退出登录','确定退出当前账户？',N.authExpired);N.actions['account-menu']=()=>N.modal('账户设置',`<div class="stack"><a class="button secondary" href="#/profile" data-action="account-profile">${N.icon('user')}个人中心</a>${N.button(N.icon('logout')+'退出登录','logout','','secondary')}</div>`);N.actions['account-profile']=()=>{N.closeModal();N.go('profile');};
  N.actions.notices=async()=>{const res=await N.api.get('/user/notice/fetch',{current:1,pageSize:20});N.modal('站点公告',N.list(res).map(item=>`<article class="article"><h3>${N.e(item.title)}</h3><small>${N.date(item.created_at)}</small>${N.rich(item.content,true)}</article>`).join('')||N.empty('暂无公告')).classList.add('notice-modal');};
  N.actions['copy-sub']=()=>N.copy(S.sub.subscribe_url);
  // Six HY2-capable clients per platform, shared by dashboard and modal.
  N.clients = [
    {id:'hiddify',label:'Hiddify',icon:'Hiddify',flag:'hiddify',scheme:'hiddify'},
    {id:'sing-box',label:'sing-box',icon:'Sing-box',flag:'sing-box',scheme:'sing-box'},
    {id:'v2rayn',label:'v2rayN',icon:'v2rayN',flag:'v2rayn',scheme:'copy'},
    {id:'shadowrocket',label:'Shadowrocket',icon:'Shadowrocket',flag:'shadowrocket',scheme:'shadowrocket'},
    {id:'surge',label:'Surge',icon:'Surge',flag:'surge',scheme:'surge'},
    {id:'stash',label:'Stash',icon:'Stash',flag:'stash',scheme:'stash'},
    {id:'loon',label:'Loon',icon:'Loon',flag:'loon',scheme:'loon'},
    {id:'v2rayng',label:'v2rayNG',icon:'v2rayNG',flag:'v2rayng',scheme:'v2rayng'},
    {id:'nekobox',label:'NekoBox',icon:'NekoBox',flag:'meta',scheme:'clash'},
    {id:'surfboard',label:'Surfboard',icon:'Surfboard',flag:'surfboard',scheme:'surfboard'},
    {id:'clash-android',label:'Clash Meta',icon:'ClashMetaForAndroid',flag:'meta',scheme:'clash'},
    {id:'clash-verge',label:'Clash Verge Rev',icon:'ClashVerge',flag:'verge',scheme:'clash'},
    {id:'clash-party',label:'Clash Party',icon:'ClashParty',flag:'meta',scheme:'mihomo'},
    {id:'clash-nyanpasu',label:'Clash Nyanpasu',icon:'ClashNyanpasu',flag:'nyanpasu',scheme:'clash'}
  ];
  N.clientPlatforms = [
    {id:'windows',label:'Windows',clients:['clash-verge','v2rayn','hiddify','clash-party','clash-nyanpasu','sing-box']},
    {id:'android',label:'Android',clients:['clash-android','v2rayng','hiddify','sing-box','nekobox','surfboard']},
    {id:'ios',label:'iOS / iPadOS',clients:['shadowrocket','stash','surge','loon','hiddify','sing-box']},
    {id:'macos',label:'macOS',clients:['clash-verge','surge','stash','hiddify','clash-party','sing-box']},
    {id:'linux',label:'Linux',clients:['clash-verge','v2rayn','hiddify','clash-party','clash-nyanpasu','sing-box']}
  ];
  const clientUA=navigator.userAgent || '';
  N.clientPlatform=/iPhone|iPad|iPod/i.test(clientUA)||(/Mac/i.test(clientUA)&&navigator.maxTouchPoints>2)?'ios':/Android/i.test(clientUA)?'android':/Macintosh|Mac OS X/i.test(clientUA)?'macos':/Windows/i.test(clientUA)?'windows':/Linux|X11/i.test(clientUA)?'linux':'windows';
  const clientSubscriptionURL=()=>{const value=String(S.sub.subscribe_url||'').trim();return /^https?:\/\//i.test(value)?N.safeURL(value):'';};
  const clientFlagURL=(source,flag,depth=0)=>{
    const url=new URL(source);url.hash='';
    // The proxy forwards only its nested URL, not sibling query parameters.
    if (/\/proxy\/?$/.test(url.pathname)&&url.searchParams.has('url')) {
      const target=String(url.searchParams.get('url')||'').trim();
      if(depth>=4||!/^https?:\/\//i.test(target))return '';
      const safe=N.safeURL(target);if(!safe)return '';
      const nested=clientFlagURL(safe,flag,depth+1);if(!nested)return '';
      url.searchParams.set('url',nested);
    }
    url.searchParams.set('flag',flag);
    return url.href;
  };
  N.clientURL = value => {
    const client=N.clients.find(item=>item.id===(typeof value==='string'?value:value?.id)), source=clientSubscriptionURL();
    if(!client||!source)return '';
    const url=clientFlagURL(source,client.flag);if(!url)return '';
    const title=String(settings.title||'订阅'), name=encodeURIComponent(title), encoded=encodeURIComponent(url), endpoint=clientImportEndpoints[client.scheme];
    switch(client.scheme){
      case 'copy':return url;
      case 'hiddify':return `${endpoint}${url}#${name}`;
      case 'sing-box':return `${endpoint}url=${encoded}#${name}`;
      // v2rayNG decodes both the query parameter and its value before importing.
      case 'v2rayng':return `${endpoint}url=${encodeURIComponent(encoded)}#${name}`;
      case 'shadowrocket':return `${endpoint}${btoa(unescape(encodeURIComponent(url))).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'')}?remark=${name}`;
      case 'loon':return `${endpoint}nodelist=${encoded}`;
      case 'surge':case 'surfboard':case 'stash':case 'mihomo':case 'clash':return `${endpoint}url=${encoded}&name=${name}`;
      default:return '';
    }
  };
  N.actions['copy-client-sub']=el=>{
    const client=N.clients.find(item=>item.id===el.dataset.client&&item.scheme==='copy');
    if(!client)return;
    return N.copy(N.clientURL(client),`已复制，请在 ${client.label} 中添加订阅`);
  };
  N.clientArtwork=client=>`<img class="client-app-icon" src="${N.e((settings.assets_path||'/theme/NINI/assets')+'/clients/'+encodeURIComponent(client.icon)+'.png')}" alt="" loading="lazy" decoding="async">`;
  N.clientPicker = (includeCopy=true) => {
    if(!clientSubscriptionURL())return N.empty('暂无可用订阅，请先购买订阅');
    const platform=N.clientPlatforms.find(item=>item.id===N.clientPlatform)||N.clientPlatforms[0];
    return `<section class="subscription-clients" data-client-picker data-copy="${includeCopy?'1':'0'}"><div class="client-platforms" role="group" aria-label="客户端系统">${N.clientPlatforms.map(item=>N.button(N.e(item.label),'client-platform',`data-platform="${item.id}" aria-pressed="${item.id===platform.id}"`,'secondary')).join('')}</div><div class="subscription-client-grid">${platform.clients.map(id=>{const client=N.clients.find(item=>item.id===id),copy=client.scheme==='copy',tag=copy?'button':'a';return `<${tag} class="subscription-client" data-client="${client.id}" ${copy?'type="button" data-action="copy-client-sub"':`href="${N.e(N.clientURL(client))}"`} title="${copy?'复制订阅到':'导入到'} ${N.e(client.label)}"><span class="client-art-box">${N.clientArtwork(client)}</span><span class="client-name">${N.e(client.label)}</span></${tag}>`;}).join('')}</div><div class="client-utilities">${includeCopy?N.button(N.icon('copy')+' 复制订阅','copy-sub','','secondary'):''}${N.button(N.icon('device')+' 订阅二维码','subscription-qr','','secondary')}<a class="button secondary" href="#/knowledge" data-action="client-guide">${N.icon('book')} 使用文档</a></div></section>`;
  };
  N.actions['client-platform']=el=>{
    if(!N.clientPlatforms.some(item=>item.id===el.dataset.platform))return;
    N.clientPlatform=el.dataset.platform;
    const source=el.closest('[data-client-picker]'),pickers=Array.from(document.querySelectorAll('[data-client-picker]')),index=pickers.indexOf(source);
    pickers.forEach(picker=>{picker.outerHTML=N.clientPicker(picker.dataset.copy!=='0');});
    document.querySelectorAll('[data-client-picker]')[index]?.querySelector(`[data-platform="${N.clientPlatform}"]`)?.focus({preventScroll:true});
  };
  N.actions['client-guide']=()=>{N.closeModal();N.go('knowledge');};
  N.actions['subscription-qr']=()=>{const url=S.sub.subscribe_url;if(!clientSubscriptionURL())return N.toast('暂无可用订阅，请先购买订阅');const modal=N.modal('订阅二维码','<p class="muted">使用客户端扫描二维码添加订阅。</p><div class="subscription-qr"></div>');N.qr(url,modal.querySelector('.subscription-qr'));};
  N.actions['import-sub']=()=>{if(!clientSubscriptionURL())return N.toast('暂无可用订阅，请先购买订阅');N.modal('一键订阅',`<p class="muted">选择设备系统，点击已安装的客户端导入订阅。</p>${N.clientPicker()}`);};
  N.actions['new-period']=()=>N.confirm('提前开启周期','此操作将扣除订阅剩余时长并重置流量，服务器将检查是否符合条件。',async()=>{await N.api.post('/user/newPeriod');await N.refreshCore();N.renderCurrent();N.toast('已开启新周期');});
  N.pages.dashboard={load:async()=>N.list(await N.api.get('/user/notice/fetch',{current:1,pageSize:3})),render:(_route,notices)=>{
    const s=S.sub, total=Number(s.transfer_enable||0),used=Number(s.u||0)+Number(s.d||0),left=Math.max(0,total-used),pct=total?Math.max(0,Math.min(100,Math.round(left/total*100))):0,active=s.plan_id&&(s.expired_at===null||Number(s.expired_at)*1000>Date.now());
    const plan=s.plan||{},hasSub=!!s.plan_id;
    return `<header class="hero dashboard-hero"><div class="hero-copy"><span class="ribbon-label">DASHBOARD ${N.icon('star')}</span><h1>欢迎回来 <span>♡</span></h1><p>订阅、流量与服务状态，一眼掌握</p><div class="row wrap">${hasSub?N.button(N.icon('crown')+' 一键订阅','import-sub'):''}<a href="#/plan" class="button ${hasSub?'secondary':'primary'}">${N.icon('cart')} 购买订阅</a></div></div><img class="hero-art" src="${N.e(N.art('mascot'))}" alt=""><span class="hero-seal" aria-hidden="true">♡<small>HAVE A<br>NICE DAY</small></span></header><div class="grid four metrics">${N.card(N.icon('chart')+' 剩余流量',`<div class="traffic-ring" style="--progress:${pct}%"><strong>${pct}%</strong><small>剩余</small></div><p class="small"><b>${N.bytes(left)}</b> / ${N.bytes(total)}</p>`)}${N.card(N.icon('calendar')+' 到期时间',`<div class="metric-value">${expiry()}</div><div class="pretty-rule"></div><p>${s.expired_at===null&&hasSub?'长期有效':N.date(s.expired_at)}</p>${N.badge(active?'有效':'未生效',active?'success':'')}`)}${N.card(N.icon('device')+' 在线设备',`<div class="metric-value">${s.alive_ip==null?'—':N.e(s.alive_ip)} <small>台</small></div><div class="pretty-rule"></div><p>设备上限 ${s.device_limit?N.e(s.device_limit):'不限'}</p>`)}${N.card(N.icon('crown')+' 当前套餐',`<div class="metric-value plan-name">${N.e(plan.name||'暂无订阅')}</div><div class="pretty-rule"></div><p>${s.reset_day!=null?`${N.e(s.reset_day)} 天后流量重置`:'暂无周期重置'}</p>`)}</div><div class="action-strip">${active&&!N.on(S.user.banned)&&Number(S.user.transfer_enable)>0&&plan.reset_price!=null?`<a href="#/plan/${N.e(s.plan_id)}?period=reset_price">${N.icon('refresh')}<span><b>流量重置</b><small>${N.e(N.money(plan.reset_price))}</small></span>${N.icon('arrow')}</a>`:''}${N.on(s.allow_new_period)&&hasSub?N.button(N.icon('clock')+' 提前开启周期','new-period','','secondary'):''}<a href="#/order">${N.icon('order')}<span><b>待支付订单 ${N.badge(S.stat[0]||0)}</b><small>查看订单与支付状态</small></span>${N.icon('arrow')}</a><a href="#/ticket">${N.icon('support')}<span><b>处理中工单 ${N.badge(S.stat[1]||0)}</b><small>查看客服回复</small></span>${N.icon('arrow')}</a></div><div class="grid dashboard-bottom"><div>${N.card(N.icon('gift')+' 订阅快捷操作',hasSub?`<p class="muted">复制链接或导入客户端</p><div class="row"><input class="input" readonly aria-label="订阅链接" value="${N.e(s.subscribe_url||'')}">${N.button(N.icon('copy'),'copy-sub','aria-label="复制订阅链接"')}</div>${N.clientPicker(false)}`:N.empty('购买订阅后，即可在这里导入客户端'), 'stitched')}</div><div class="stack">${N.communityEnabled()?`<a href="#/community" class="community-banner"><img src="${N.e(N.art('headset'))}" alt=""><div><h2>售后群聊</h2><p>交流使用问题，获取售后帮助</p></div><span class="button primary">进入群聊 ${N.icon('arrow')}</span></a>`:''}${N.card(N.icon('bell')+' 最新公告',(notices||[]).map((n)=>`<button class="notice" data-action="notice-detail" data-id="${N.e(n.id)}"><span><strong>${N.e(n.title)}</strong><small>${N.date(n.created_at)}</small></span>${N.icon('arrow')}</button>`).join('')||N.empty('暂无公告'))}</div></div>${N.subscriptionExplanations(s.subscription_rule_explanations)}`;
  }};
  N.actions['notice-detail']=el=>{const n=(routeData||[]).find(x=>String(x.id)===el.dataset.id);if(n)N.modal(n.title,`<article class="article">${N.rich(n.content,true)}</article>`,true).classList.add('notice-modal');};
  let captchaPromise;
  const inviteStorageKey='nini_pending_invite',inviteLifetime=30*60*1000;
  let pendingInvite;
  function clearInvite(){pendingInvite={code:'',expires:0};try{sessionStorage.removeItem(inviteStorageKey);}catch(_){} }
  if(S.auth)clearInvite();
  window.addEventListener('storage',event=>{if(['authorization','nini_auth_data','auth_data'].includes(event.key)&&event.newValue!==S.auth)clearInvite();});
  function storeInvite(value){const code=String(value||'').trim();if(!code){clearInvite();return;}pendingInvite={code,expires:Date.now()+inviteLifetime};try{sessionStorage.setItem(inviteStorageKey,JSON.stringify(pendingInvite));}catch(_){} }
  N.inviteCode=route=>{
    if(S.auth){clearInvite();return '';}
    if(pendingInvite===undefined){try{pendingInvite=JSON.parse(sessionStorage.getItem(inviteStorageKey)||'null');}catch(_){} }
    if(!pendingInvite||typeof pendingInvite.code!=='string'||!(pendingInvite.expires>Date.now()))clearInvite();
    if(route?.query?.has('code'))storeInvite(route.query.get('code'));
    else if(route?.query?.has('invite_code'))storeInvite(route.query.get('invite_code'));
    return pendingInvite.code;
  };
  N.authHref=(path,redirect)=>{const query=new URLSearchParams(),code=N.inviteCode();if(code&&['register','landing'].includes(path))query.set('code',code);if(redirect&&safeRedirect(redirect)!=='dashboard')query.set('redirect',safeRedirect(redirect));return '#/'+path+(query.size?'?'+query:'');};
  function replaceAuthURL(path,redirect){const href=N.authHref(path,redirect);history.replaceState(history.state,'',location.pathname+location.search+href);}
  document.addEventListener('input',event=>{const field=event.target;if(field.name!=='invite_code'||!field.closest('form[data-form="auth"][data-mode="register"]'))return;storeInvite(field.value);replaceAuthURL('register',field.closest('form').dataset.redirect);document.querySelectorAll('a[data-auth-path]').forEach(link=>{link.href=N.authHref(link.dataset.authPath,link.dataset.authRedirect);});});
  function captchaMessage(error){const text=document.querySelector('[data-captcha-error]'),retry=document.querySelector('[data-action="retry-captcha"]');if(text)text.textContent=error?.message||'';if(retry)retry.hidden=!error;}
  function captchaScript(){
    if(window.grecaptcha?.render)return Promise.resolve();
    if(captchaPromise)return captchaPromise;
    captchaPromise=new Promise((resolve,reject)=>{
      const script=document.createElement('script');let settled=false;
      const finish=error=>{if(settled)return;settled=true;clearTimeout(timeout);script.onload=script.onerror=null;if(error){script.remove();captchaPromise=undefined;reject(error);}else resolve();};
      const timeout=setTimeout(()=>finish(new Error('验证码加载超时，请重新加载')),20000);
      script.src='https://www.recaptcha.net/recaptcha/api.js?render=explicit';
      script.onload=()=>{if(!window.grecaptcha?.ready){finish(new Error('验证码加载失败，请重新加载'));return;}window.grecaptcha.ready(()=>finish(window.grecaptcha?.render?null:new Error('验证码加载失败，请重新加载')));};
      script.onerror=()=>finish(new Error('验证码加载失败，请检查网络后重新加载'));
      document.head.append(script);
    });
    return captchaPromise;
  }
  async function loadCaptcha(){
    if(!N.on(S.guest.is_recaptcha))return;
    const el=document.getElementById('captcha');if(!el)return;captchaMessage();
    try{if(!S.guest.recaptcha_site_key)throw new Error('站点验证码未配置，请联系管理员');await captchaScript();if(el.isConnected){if(el.dataset.widgetId===undefined)el.dataset.widgetId=String(window.grecaptcha.render(el,{sitekey:S.guest.recaptcha_site_key}));captchaID=Number(el.dataset.widgetId);}}
    catch(error){if(el.isConnected)captchaMessage(error);throw error;}
  }
  N.actions['retry-captcha']=loadCaptcha;
  function captcha(){if(!N.on(S.guest.is_recaptcha))return undefined;const v=window.grecaptcha&&captchaID!=null?grecaptcha.getResponse(captchaID):'';if(!v)throw new Error('请先完成人机验证');return v;}
  function resetCaptcha(){if(window.grecaptcha&&captchaID!=null)grecaptcha.reset(captchaID);}
  async function renderAuth(route,token){const guest=N.data(await N.api.get('/guest/comm/config'))||{};if(token!==renderID)return;S.guest=guest;let mode=['register','forgetpassword'].includes(route.key)?route.key:'login',authMessage='',retryToken=false;
    const destination=safeRedirect(route.query.get('redirect')||(!['login','register','forgetpassword'].includes(route.key)?location.hash:''));
    N.inviteCode(route);
    if(route.query.get('verify')){
      try{const login=N.data(await N.api.get('/passport/auth/token2Login',{verify:route.query.get('verify')}));if(token!==renderID)return;N.saveAuth(login);clearInvite();N.go(destination);return;}
      catch(error){if(token!==renderID)return;mode='login';const expired=['Token error','令牌有误'].includes(error.serverMessage||error.message);if(expired){route.query.delete('verify');replaceAuthURL('login',destination);authMessage='快捷登录已失效，请使用账号密码登录。';}else{retryToken=true;authMessage='快捷登录暂时无法完成，请重试或使用账号密码登录。';}}
    }
    const closed=N.on(settings.stop_register??S.guest.stop_register);if(mode==='register'&&closed)mode='login';const title={login:'欢迎回来',register:'创建账户',forgetpassword:'找回密码'}[mode],verify=mode==='forgetpassword'||mode==='register'&&N.on(S.guest.is_email_verify),tos=N.safeURL(S.guest.tos_url);const authHref=path=>N.authHref(path,destination);captchaID=undefined;
    root.className='nini-app auth-app';root.innerHTML=`${N.authArtwork?N.authArtwork():`<div class="auth-art">${brand()}<img src="${N.e(N.art('mascot'))}" alt=""><div><h1>${N.e(settings.title||'用户中心')}</h1><p>${N.e(settings.description||S.guest.app_description||'')}</p></div></div>`}<section class="auth-card"><span class="ribbon-label">${N.icon('heart')} ${N.e(settings.title||'用户中心')}</span><h1>${title}</h1>${authMessage?`<p role="alert">${N.e(authMessage)}</p>${retryToken?N.button('重试快捷登录','refresh','','secondary'):''}`:''}<p class="muted">${mode==='login'?'登录后管理您的订阅与服务':mode==='register'?'填写信息，开启新的旅程':'验证邮箱并设置新密码'}</p><form data-form="auth" data-mode="${mode}" data-redirect="${N.e(destination)}" class="stack">${N.field('邮箱',`<input class="input" name="email" type="email" required autocomplete="email" placeholder="请输入邮箱">`)}${mode!=='login'&&Array.isArray(S.guest.email_whitelist_suffix)?`<small class="muted">支持的邮箱：${S.guest.email_whitelist_suffix.map(N.e).join('、')}</small>`:''}${verify?N.field('邮箱验证码',`<div class="row"><input class="input" name="email_code" inputmode="numeric" pattern="[0-9]{6}" required placeholder="6 位验证码">${N.button('发送验证码','send-code','','secondary')}</div>`):''}${N.field(mode==='forgetpassword'?'新密码':'密码',`<input class="input" name="password" type="password" minlength="8" required autocomplete="${mode==='login'?'current-password':'new-password'}" placeholder="至少 8 位">`)}${mode==='register'?N.field(N.on(S.guest.is_invite_force)?'邀请码':'邀请码（可选）',`<input class="input" name="invite_code" value="${N.e(N.inviteCode())}" ${N.on(S.guest.is_invite_force)?'required':''}>`):''}${mode!=='login'&&N.on(S.guest.is_recaptcha)?'<div id="captcha"></div><p data-captcha-error role="alert"></p><button type="button" class="button secondary" data-action="retry-captcha" hidden>重新加载人机验证</button>':''}${mode==='register'&&tos?`<label class="row small"><input type="checkbox" required> 我已阅读并同意 <a href="${N.e(tos)}" target="_blank" rel="noopener noreferrer">服务条款</a></label>`:''}<button class="button primary" type="submit">${mode==='login'?'登录':mode==='register'?'注册':'重置密码'} ${N.icon('arrow')}</button><p data-form-error role="alert"></p></form>${N.pages.landing&&theme.landing_enabled!=='off'?`<a class="auth-back-home" data-auth-path="landing" href="${N.e(N.authHref('landing'))}">← 返回首页</a>`:''}<div class="auth-links">${mode!=='login'?`<a href="${N.e(authHref('login'))}">返回登录</a>`:`${closed?'':`<a data-auth-path="register" data-auth-redirect="${N.e(destination)}" href="${N.e(authHref('register'))}">注册账户</a>`}<a href="${N.e(authHref('forgetpassword'))}">忘记密码？</a>`}</div></section>`;if(N.refreshParallax)N.refreshParallax();if(mode!=='login')try{await loadCaptcha();}catch(e){N.error(e);}}
  function authRequestScope(form){
    const account=N.accountVersion?.()??sessionID,authorization=S.auth,page=renderID,hash=location.hash,widget=captchaID,widgetElement=document.getElementById('captcha');
    const current=()=>account===(N.accountVersion?.()??sessionID)&&authorization===S.auth&&page===renderID&&hash===location.hash&&form.isConnected&&root.contains(form);
    return {current,reset:()=>{if(current()&&captchaID===widget&&document.getElementById('captcha')===widgetElement)resetCaptcha();}};
  }
  N.actions['send-code']=async el=>{
    const form=el.closest('form'),scope=authRequestScope(form);if(!scope.current())return;
    if(Date.now()<sendAt)throw new Error(`请 ${Math.ceil((sendAt-Date.now())/1000)} 秒后重试`);
    const email=form.elements.email;if(!email.reportValidity())return;
    try{await N.api.post('/passport/comm/sendEmailVerify',{email:email.value,isforget:form.dataset.mode==='forgetpassword'?1:0,recaptcha_data:captcha()});if(!scope.current())return;sendAt=Date.now()+60000;N.toast('验证码已发送');}
    catch(error){if(scope.current())throw error;}finally{scope.reset();}
  };
  N.forms.auth=async(form,values)=>{
    const mode=form.dataset.mode,scope=authRequestScope(form);if(!scope.current())return;
    try{if(mode==='register')values.recaptcha_data=captcha();const r=await N.api.post(`/passport/auth/${mode==='forgetpassword'?'forget':mode}`,values);if(!scope.current())return;if(mode==='forgetpassword'){N.authExpired(form.dataset.redirect);N.toast('密码已重置，请重新登录');}else{N.saveAuth(N.data(r));clearInvite();N.go(safeRedirect(form.dataset.redirect));}}
    catch(error){if(scope.current())throw error;}finally{scope.reset();}
  };
  document.addEventListener('click',async event=>{const el=event.target.closest('[data-action]');if(!el||(!el.closest('.nini-app')&&!el.closest('#nini-root')))return;const fn=N.actions[el.dataset.action];if(!fn)return;event.preventDefault();if(el.dataset.busy)return;el.dataset.busy='1';const wasDisabled=el.disabled;el.disabled=true;try{await fn(el,event);}catch(e){N.error(e);}finally{delete el.dataset.busy;el.disabled=wasDisabled;}});
  document.addEventListener('submit',async event=>{const form=event.target.closest('form[data-form]');if(!form||!N.forms[form.dataset.form])return;event.preventDefault();if(form.dataset.busy)return;form.dataset.busy='1';const btn=form.querySelector('[type=submit]'),wasDisabled=btn?.disabled;if(btn)btn.disabled=true;const error=form.querySelector('[data-form-error]');if(error)error.textContent='';try{await N.forms[form.dataset.form](form,N.formValues(form),event);}catch(e){if(error)error.textContent=e.message;N.error(e);}finally{delete form.dataset.busy;if(btn)btn.disabled=wasDisabled;}});
  window.addEventListener('storage',event=>{if(window.NINI_PREVIEW===true||!['authorization','nini_auth_data','auth_data'].includes(event.key)||event.newValue===S.auth)return;if(!event.newValue){N.authExpired();return;}clearAccount();S.auth=event.newValue;bridgeAuth();N.closeModal();N.refresh();});
  // Only the catalogue needs an API call on the landing page. Its completion
  // never gates the hero, navigation, artwork, or login entry.
  const readLandingCatalog = () => theme.landing_plans_enabled === 'off'
    ? Promise.resolve(null) : N.api.get('/guest/plan/fetch').catch(() => ({error:true}));
  let initialLandingRead = null;
  const initialHash = location.hash;
  const initialPath = initialHash.replace(/^#\/?/, '').split('?')[0];
  N.loadLandingCatalog = () => {
    const pending = initialLandingRead;
    initialLandingRead = null;
    return pending && pending.session === sessionID && pending.authorization === S.auth && initialHash === location.hash
      ? pending.result : readLandingCatalog();
  };
  window.addEventListener('hashchange', () => {initialLandingRead = null;}, {once:true});
  N.onAccountReset(() => {initialLandingRead = null;});
  if (theme.landing_enabled !== 'off' && (!initialPath || initialPath === 'landing')) {
    initialLandingRead = {session:sessionID, authorization:S.auth, result:readLandingCatalog()};
  }
  N.boot=()=>{const colors={sakura:'#ff408a',coral:'#f66d88',berry:'#ca4788'};document.documentElement.style.setProperty('--pink',colors[theme.accent_color]||colors.sakura);const bg=N.safeURL(theme.background_url);if(bg)document.body.style.backgroundImage=`url("${bg.replace(/"/g,'%22')}")`;window.addEventListener('hashchange',()=>{if(N.route().key!==S.route?.key)window.scrollTo(0,0);render();});return render();};
})();
