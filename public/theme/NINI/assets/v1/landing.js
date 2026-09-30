(function(N){
  'use strict';
  const E=N.e;
  function satinBow(color='#ffc1db'){
    return `<svg viewBox="0 0 200 156" aria-hidden="true" focusable="false"><g stroke-linejoin="round" stroke-linecap="round"><path d="M81 68C64 59 50 84 38 124L64 117L81 138L100 91L129 135L140 112L169 116C147 89 133 70 115 64C135 43 162 18 177 25C185 40 184 73 173 91C155 99 127 85 111 77C94 94 56 103 28 89C18 72 15 37 25 25C45 18 68 45 81 68Z" fill="white" stroke="white" stroke-width="11"/><g stroke="#ac829c" stroke-width="2.5"><path d="M89 68C67 58 54 83 39 124L65 117L81 137L101 84L130 135L141 111L168 115C147 86 135 68 113 64Z" fill="${color}"/><path d="M91 66C75 49 49 19 27 24C15 42 20 77 29 89C46 99 77 83 95 76C113 90 150 103 174 91C186 70 185 38 178 25C158 19 130 44 108 65Z" fill="${color}"/><path d="M37 35C55 39 69 51 83 65C66 62 47 66 33 73Z M119 67C137 50 155 40 169 37L173 73C156 65 139 63 119 67Z" fill="#fff0f6" stroke="none"/><path d="m49 82 32-9m43 2 34 11M80 96l-9 21m48-20 17 18" fill="none" stroke="#d494b5"/><path d="M87 61Q98 54 112 62L115 81Q102 91 86 81Z" fill="#ffe4ef"/><path d="M93 65q8-3 15 0" fill="none" stroke="white"/></g></g></svg>`;
  }
  const corners=()=>`<div class="lp-frame-corners" aria-hidden="true"><span class="lp-corner-sticker lp-corner-star">${sticker('star','#ffe5a6')}</span><span class="lp-corner-sticker lp-corner-heart">${sticker('heart','#ffc6df')}</span></div>`;
  const jewel=()=>`<svg viewBox="0 0 160 130" aria-hidden="true"><path d="M15 45Q80 110 145 45M15 45Q80 128 145 45" fill="none" stroke="#e5ae69" stroke-width="2"/><path d="M15 45Q80 110 145 45" fill="none" stroke="#fff" stroke-width="7" stroke-linecap="round" stroke-dasharray=".1 10"/><g fill="#fff3bd" stroke="#dda55f" stroke-width="1.8"><path d="m80 77 9 10 14 1-8 12 1 14-16-5-14 5 1-14-8-12 14-1Z"/><path d="m34 53 4 7 8 1-6 6 1 8-7-4-7 4 1-8-6-6 8-1ZM126 53l4 7 8 1-6 6 1 8-7-4-7 4 1-8-6-6 8-1Z"/></g><path d="m80 83 4 9 10 1-8 8 1 6-7-3-7 3 1-8-7-6 9-1Z" fill="#ffafd1"/></svg>`;
  function sticker(type,color='#ff9cc4'){
    if(type==='bow')return satinBow(color);
    const shapes={
      heart:'M35 58C26 52 10 41 10 26C10 12 27 10 35 24C44 8 62 14 61 28C60 41 45 53 35 58Z',
      star:'M35 8Q38 8 41 22L56 23Q65 24 57 32L47 41L51 55Q54 63 46 59L34 51L21 59Q13 63 16 54L20 40L9 30Q3 24 12 23L27 22L31 10Q32 7 35 8Z',
      sparkle:'M36 7Q39 29 61 34Q40 38 36 61Q32 40 10 35Q31 31 36 7Z',
      bow:'M29 29C8 8 3 15 8 43C10 52 24 43 31 38L24 62L37 53L48 62L42 37C58 51 66 50 64 22C63 10 48 20 41 28Z',
      paw:'M22 41C28 27 41 27 46 42C62 50 49 62 36 56C21 63 7 52 22 41ZM15 22C9 8 22 5 25 18C28 31 18 34 15 22ZM31 16C29 2 43 2 43 16C43 29 31 29 31 16ZM49 21C52 7 65 12 61 25C57 37 46 34 49 21Z'
    },d=shapes[type]||shapes.star;
    return `<svg viewBox="0 0 72 72" aria-hidden="true" focusable="false"><path d="${d}" fill="white" stroke="white" stroke-width="9" stroke-linejoin="round"/><path d="${d}" fill="${color}" stroke="${type==='star'?'#dfad71':'#e36c9e'}" stroke-width="2" stroke-linejoin="round"/>${type==='bow'?'<path d="m13 24 15 9m20-2 11-6M33 41l-3 11m9-10 5 11" stroke="#fff2fa" stroke-width="3" fill="none"/><path d="M36 42C21 32 31 22 36 29C44 19 53 32 36 42Z" fill="#ffeab0" stroke="#e6a8aa" stroke-width="1.5"/>':type==='heart'?'<path d="M16 27q-1-9 8-7" stroke="white" stroke-width="4" fill="none" stroke-linecap="round"/>':type==='star'?'<path d="m33 16-4 13-13 1" stroke="#fff9df" stroke-width="3" fill="none" stroke-linecap="round"/>':''}</svg>`;
  }
  // Pre-rendered, lossless copies of the original vectors. Only these small
  // image textures move; no SVG image rasterization is needed during motion.
  function ornament(type,x,y,size,depth,color){
    const file=type+'-'+(color||'#ff9cc4').slice(1)+'.png';
    const src=(N.settings.assets_path||'/theme/NINI/assets')+'/v1/drift-stickers/'+file+'?v=20260920a';
    return `<span class="lp-float lp-float-${type}" data-parallax-depth="${depth}" style="left:${x}px;top:${y}px;width:${size}px;height:${size}px"><img src="${E(src)}" alt="" width="${size}" height="${size}" decoding="async" draggable="false"></span>`;
  }
  N.authArtwork=()=>{
    const title=N.settings.title||'用户中心',tagline=N.theme.brand_tagline||'';
    const logo=N.safeURL(N.settings.logo||N.state.guest.logo);
    const description=N.settings.description||N.state.guest.app_description||'';
    return `<aside class="auth-art auth-poster" aria-label="${E(title)}">
      <div class="auth-poster-top" aria-hidden="true"><span class="auth-poster-greeting">HELLO, SWEET WORLD! <b>♡</b></span><span class="auth-poster-mini">A LITTLE JOY, EVERY DAY</span></div>
      <div class="auth-poster-stage" aria-hidden="true">
        <div class="auth-poster-paper"><span class="auth-poster-photo-label">SWEET MOMENTS</span><span class="auth-poster-photo-note">Made with love ♡</span></div>
        <div class="auth-poster-backing"></div>
        <span class="auth-poster-bow">${satinBow()}</span>
        <span class="auth-poster-stamp"><small>HAVE A</small>${sticker('heart','#ffc0dc')}<b>SWEET DAY</b></span>
        <span class="auth-poster-speech">好心情，<br>正在连接中 <b>♡</b></span>
        <span class="auth-poster-note">Small steps,<br><b>big happiness!</b></span>
        <img class="auth-poster-mascot" src="${E((N.settings.assets_path||'/theme/NINI/assets')+'/retouched/mascot-soft-v3-optimized.png')}" alt="" width="1024" height="1536" fetchpriority="high">
        <span class="auth-poster-treat auth-poster-cupcake"></span><span class="auth-poster-treat auth-poster-rainbow"></span>
        <span class="auth-poster-star-pair">${sticker('sparkle','#ffeb9b')}${sticker('sparkle','#ffe9a6')}</span>
        <div class="auth-poster-floating" data-parallax-scene>
          ${ornament('heart',87,267,59,.8,'#ffc2dc')}${ornament('star',565,256,58,1,'#ffe7a5')}${ornament('sparkle',533,58,41,.7,'#e0c5fa')}${ornament('heart',535,416,44,.9,'#ffc0dc')}${ornament('star',149,462,43,.8,'#ffe6a3')}
        </div>
      </div>
      <a class="auth-poster-brand" data-auth-path="${N.theme.landing_enabled==='off'?'login':'landing'}" href="${E(N.authHref(N.theme.landing_enabled==='off'?'login':'landing'))}">${logo?`<img class="auth-poster-logo" src="${E(logo)}" alt="">`:`<span class="auth-poster-brand-heart" aria-hidden="true">${sticker('heart','#ffc0dd')}</span>`}<span class="auth-poster-brand-copy">${tagline?`<small>${E(tagline)}</small>`:''}<strong>${E(title)}</strong></span><span class="auth-poster-brand-star" aria-hidden="true">${sticker('star','#ffe6a3')}</span><span class="auth-brand-bow" aria-hidden="true">${satinBow('#ffd0e2')}</span><span class="auth-brand-blossom" aria-hidden="true"></span><span class="auth-brand-sparkles" aria-hidden="true">✧ <i></i></span></a>
      ${description?`<p class="auth-poster-description">${E(description)}</p>`:''}
      <div class="auth-poster-finish" aria-hidden="true"><i></i><span>♡</span><i></i></div>
    </aside>`;
  };
  const arrow='<svg viewBox="0 0 28 24" aria-hidden="true"><path d="M3 12h20m-7-7 7 7-7 7" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  const gate=route=>N.state.auth?'#/'+route:'#/login?redirect='+encodeURIComponent(route);
  const cta=(label,href,kind='pink')=>`<a class="lp-button lp-button-${kind}" href="${E(href)}"><span>${E(label)}</span>${arrow}</a>`;
  // Decorative artwork is separate from all configurable package information.
  function presentBox(index){
    const colors=[['#ff95c6','#e45897','#ffd9e9'],['#cdb0f4','#9872c4','#eee2ff'],['#ffd485','#da9b47','#fff0c9']][index%3];
    return `<svg viewBox="0 0 170 150" aria-hidden="true"><ellipse cx="86" cy="133" rx="58" ry="8" fill="#c5679630"/><g transform="rotate(-9 85 80)" stroke="${colors[1]}" stroke-width="2" stroke-linejoin="round"><path d="M29 67h111v56q0 7-8 7H37q-8 0-8-7Z" fill="${colors[0]}"/><path d="M112 67h28v56q0 7-8 7h-20Z" fill="${colors[1]}" opacity=".27"/><path d="M24 50h122v24H24Z" fill="${colors[2]}"/><path d="M27 55h116M35 121h66" stroke="white" stroke-width="3"/><path d="M72 51h24v79H72Z" fill="#fff3fb"/><path d="M79 55v72" stroke="${colors[0]}" stroke-width="3"/><path d="M82 49C51 6 30 19 42 40q8 15 40 9ZM89 49c24-43 54-34 44-13q-8 18-44 13Z" fill="${colors[0]}"/><path d="M76 43Q47 16 47 32q0 9 27 13M96 43q28-27 31-15q2 9-27 17" fill="${colors[2]}"/><path d="m76 46-17 37 20-7 5-23 12 28 16-8-20-29Z" fill="${colors[2]}"/><rect x="74" y="38" width="23" height="20" rx="7" fill="${colors[0]}"/><path d="M80 43h10" stroke="white" stroke-linecap="round"/><path d="m51 91 3 6 7 1-5 5 1 7-6-3-6 3 1-7-5-5 7-1Z" fill="#fff2bd" stroke="#e6b66d"/><path d="m121 92-1 8m-4-4h8" stroke="white"/><circle cx="44" cy="81" r="2" fill="white" stroke="none"/><circle cx="129" cy="116" r="2" fill="white" stroke="none"/></g><path d="m147 15 3 8 9 3-9 3-3 8-3-8-8-3 8-3Z" fill="#fff0b6" stroke="#e3b974" stroke-width="1.5"/><path d="M20 35q-9-7-11-1t9 12q12-8 8-12t-6 1Z" fill="#ffaed0" stroke="#e686b1" stroke-width="1.5"/></svg>`;
  }
  const pricePeriods={month_price:'月付',quarter_price:'季付',half_year_price:'半年付',year_price:'年付',two_year_price:'两年付',three_year_price:'三年付',onetime_price:'一次性'};
  const selectedPeriods=new Map();
  let currentCatalog;
  // Keep whole period buttons in one row, including the user's current choice.
  let periodObserver = null, periodFrame = 0;
  function fitPeriodRows(root = document) {
    root.querySelectorAll('.lp-plan-periods').forEach(row => {
      if (!row.isConnected) return;
      const buttons = Array.from(row.querySelectorAll('button'));
      buttons.forEach(button => {button.hidden = false;});
      const bounds = row.getBoundingClientRect();
      if (!bounds.width || !buttons.length) return;
      // Both measurements include the page's CSS zoom, avoiding mixed units.
      const rects = buttons.map(button => button.getBoundingClientRect());
      const gap = rects.length > 1 ? Math.max(0, rects[1].left - rects[0].right) : 0;
      const selected = Math.max(0, buttons.findIndex(button => button.getAttribute('aria-pressed') === 'true'));
      const visible = new Set([selected]);
      let used = rects[selected].width;
      for (let index = 0; index < buttons.length; index++) {
        if (index === selected) continue;
        const next = used + gap + rects[index].width;
        if (next > bounds.width + 0.1) break;
        visible.add(index);
        used = next;
      }
      buttons.forEach((button, index) => {button.hidden = !visible.has(index);});
      if (buttons.includes(document.activeElement) && document.activeElement.hidden) {
        buttons[selected].focus({preventScroll:true});
      }
    });
  }
  function schedulePeriodFit() {
    if (!periodFrame) periodFrame = requestAnimationFrame(() => {
      periodFrame = 0;
      fitPeriodRows();
    });
  }
  function stopPeriodFit() {
    periodObserver?.disconnect();
    periodObserver = null;
    cancelAnimationFrame(periodFrame);
    periodFrame = 0;
  }
  function watchPeriodRows(paper) {
    stopPeriodFit();
    const grid = paper.querySelector('.lp-price-grid');
    if (grid && typeof ResizeObserver !== 'undefined') {
      periodObserver = new ResizeObserver(schedulePeriodFit);
      periodObserver.observe(grid);
    }
    fitPeriodRows(paper);
  }
  window.addEventListener('resize', schedulePeriodFit, {passive:true});
  window.addEventListener('hashchange', stopPeriodFit);
  document.fonts?.ready.then(schedulePeriodFit);
  document.fonts?.addEventListener('loadingdone', schedulePeriodFit);
  const validPeriods=plan=>Object.keys(pricePeriods).filter(key=>plan[key]!==null&&plan[key]!==undefined&&plan[key]!==''&&Number.isFinite(Number(plan[key]))&&Number(plan[key])>=0);
  const priceText=(amount,catalog)=>`${catalog.currency_symbol||catalog.currency||'¥'}${(Number(amount)/100).toLocaleString('zh-CN',{minimumFractionDigits:2,maximumFractionDigits:2})}`;
  function planDescription(content){
    if(!content)return '';
    let rows=content;try{if(typeof content==='string')rows=JSON.parse(content);}catch(_){}
    if(Array.isArray(rows)&&rows.every(row=>row&&typeof row.feature==='string'))return `<ul class="lp-plan-features">${rows.map(row=>`<li class="${N.on(row.support)?'':'not-included'}"><span aria-hidden="true">${N.on(row.support)?'♡':'×'}</span><span>${E(row.feature)}</span></li>`).join('')}</ul>`;
    const description=N.rich(content).replace(/(<br\s*\/?>)(?:\s*<br\s*\/?>)+/gi,'$1');
    return `<div class="lp-plan-description article">${description}</div>`;
  }
  function priceCard(plan,catalog,index){
    const choices=validPeriods(plan),chosen=selectedPeriods.get(String(plan.id)),period=choices.includes(chosen)?chosen:choices[0];
    const available=plan.available!==false&&!['0',0].includes(plan.available),id=String(plan.id);
    return `<article class="lp-plan-card lp-feature lp-feature-0${index%3+1}" data-plan-card="${E(id)}">
      ${corners()}<div class="lp-card-lace" aria-hidden="true"></div>
      <span class="lp-feature-sticker" aria-hidden="true">${satinBow(index%3===1?'#c6a3ef':index%3===2?'#ffd79c':'#ff9cc4')}</span>
      <div class="lp-plan-cap">
        <span class="lp-plan-present" aria-hidden="true">${presentBox(index)}</span>
        <div class="lp-plan-topline"><span>SUBSCRIPTION ♡</span><span class="lp-plan-stock ${available?'':'sold-out'}">${available?'可订购':'暂时售罄'}</span></div>
        <h3>${E(plan.name||'订阅套餐')}</h3>
        <div class="lp-plan-price"><strong>${E(priceText(plan[period],catalog))}</strong><span> / ${E(pricePeriods[period])}</span></div>
        <span class="lp-cap-stitch" aria-hidden="true"></span>
      </div>
      <div class="lp-plan-periods" role="group" aria-label="${E(plan.name)}付款周期">${choices.map(key=>`<button type="button" data-action="landing-price-period" data-plan="${E(id)}" data-period="${key}" aria-pressed="${key===period}" class="${key===period?'selected':''}">${pricePeriods[key]}</button>`).join('')}</div>
      <ul class="lp-plan-specs">${plan.transfer_enable!=null?`<li>${N.icon('gift')}<span><b>${E(plan.transfer_enable)}</b> GB 流量</span></li>`:''}${plan.speed_limit!=null?`<li>${N.icon('star')}<span>${Number(plan.speed_limit)===0?'不限速率':`<b>${E(plan.speed_limit)}</b> Mbps 速率`}</span></li>`:''}${plan.device_limit!=null?`<li>${N.icon('device')}<span>${Number(plan.device_limit)===0?'不限设备数量':`<b>${E(plan.device_limit)}</b> 台设备`}</span></li>`:''}</ul>
      ${planDescription(plan.content)}<div class="lp-plan-buy">${available?cta('选择这个套餐',gate(`plan/${encodeURIComponent(id)}?period=${period}`),'pink'):'<span class="lp-plan-unavailable">暂时售罄，请看看其他套餐</span>'}</div>
    </article>`;

  }
  function priceSection(catalog){
    currentCatalog=catalog;
    const plans=Array.isArray(catalog?.data)?catalog.data.filter(plan=>plan&&plan.show!==0&&plan.show!=='0'&&validPeriods(plan).length):[];
    return `<section class="lp-pricing lp-section" id="lp-pricing" aria-labelledby="lp-pricing-title"><div class="lp-section-heading"><div class="lp-heading-scroll" aria-hidden="true"></div><span class="lp-section-bow" aria-hidden="true">${satinBow()}</span><p>FIND YOUR SWEET CONNECTION</p><h2 id="lp-pricing-title">挑一份喜欢，让美好开始 <span>♡</span></h2><div class="lp-heading-dots" aria-hidden="true"><i></i> ✧ <i></i></div></div><div class="lp-price-grid" data-plan-count="${plans.length}">${catalog?.loading?'<div class="lp-price-empty" role="status">正在加载套餐…</div>':catalog?.error?'<div class="lp-price-empty"><p>套餐暂时无法加载，请稍后再试。</p><button class="lp-button lp-button-white" data-action="landing-reload-prices">重新加载</button></div>':plans.length?plans.map((plan,index)=>priceCard(plan,catalog,index)).join(''):'<div class="lp-price-empty">暂无在售套餐，欢迎稍后再来看看 ♡</div>'}</div><p class="lp-pricing-note">价格对应所选付款周期，最终金额以订单确认为准。</p></section>`;
  }
  N.actions['landing-price-period']=el=>{const plans=Array.isArray(currentCatalog?.data)?currentCatalog.data:[],index=plans.findIndex(plan=>String(plan.id)===el.dataset.plan),plan=plans[index],period=el.dataset.period,id=el.dataset.plan;if(!plan||!validPeriods(plan).includes(period))return;selectedPeriods.set(id,period);el.closest('[data-plan-card]').outerHTML=priceCard(plan,currentCatalog,index);fitPeriodRows();[...document.querySelectorAll('[data-plan-card]')].find(card=>card.dataset.planCard===id)?.querySelector(`[data-period="${period}"]`)?.focus({preventScroll:true});};
  let catalogSession = null;
  const currentCatalogSession = (scope, request) => scope === catalogSession && scope.request === request
    && scope.route === N.state.route && scope.paper.isConnected
    && scope.paper === document.querySelector('#nini-root .lp-paper')
    && scope.account === N.accountVersion() && scope.authorization === N.state.auth;
  function patchCatalog(scope, request, catalog) {
    if (!currentCatalogSession(scope, request)) return;
    const grid = scope.paper.querySelector('.lp-price-grid');
    if (!grid) return;
    // Keep the hero and all decoration nodes mounted while only prices update.
    const template = document.createElement('template');
    template.innerHTML = priceSection(catalog);
    const next = template.content.querySelector('.lp-price-grid');
    scope.data.landing_catalog = catalog;
    grid.dataset.planCount = next.dataset.planCount;
    grid.replaceChildren(...Array.from(next.childNodes));
    fitPeriodRows(grid);
    if (catalog?.loading) grid.setAttribute('aria-busy', 'true');
    else grid.removeAttribute('aria-busy');
  }
  async function loadCatalog(scope, retry = false) {
    const request = ++scope.request;
    if (!currentCatalogSession(scope, request)) return;
    if (retry) patchCatalog(scope, request, {loading:true});
    let catalog;
    try {catalog = await (N.loadLandingCatalog ? N.loadLandingCatalog() : N.api.get('/guest/plan/fetch'));}
    catch (_) {catalog = {error:true};}
    patchCatalog(scope, request, catalog);
  }
  N.onAccountReset(() => {catalogSession = null;stopPeriodFit();});
  N.actions['landing-reload-prices'] = () => catalogSession ? loadCatalog(catalogSession, true) : undefined;
  N.actions['landing-scroll']=el=>{const id=el.dataset.section;if(['lp-features','lp-guide','lp-pricing'].includes(id))document.getElementById(id)?.scrollIntoView({behavior:window.matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth',block:'start'});};
  N.pages.landing={public:true,
    load:route=>{
      stopPeriodFit();
      N.inviteCode(route);
      catalogSession = null;
      // These are public settings already supplied by the HTML response.
      // Leave state.guest intact: auth pages load their own validation settings.
      return {logo:N.settings.logo,app_description:N.settings.description,tos_url:N.settings.tos_url,
        stop_register:N.settings.stop_register,community_enabled:N.settings.community_enabled,
        landing_catalog:N.theme.landing_plans_enabled==='off'?null:{loading:true}};
    },
    mounted:(route,data)=>{
      if (N.theme.landing_plans_enabled === 'off') return;
      const paper = document.querySelector('#nini-root .lp-paper');
      if (!paper) return;
      const scope = {route,data,paper,account:N.accountVersion(),authorization:N.state.auth,request:0};
      catalogSession = scope;
      watchPeriodRows(paper);
      return loadCatalog(scope);
    },
    render:(_route,guest={})=>{
      const T=N.theme,title=N.settings.title||'用户中心',tagline=T.brand_tagline||'',logo=N.safeURL(N.settings.logo||guest.logo);
      const closed=N.on(N.settings.stop_register??guest.stop_register),logged=!!N.state.auth;
      const heading=T.landing_heading||'把每一次连接，\n变成心动时刻。';
      const description=T.landing_description||N.settings.description||guest.app_description||'订阅、使用指南与售后服务，都在这个小小空间。';
      const start=logged?'#/dashboard':closed?'#/login':N.authHref('register'),startText=logged?'进入我的账户':closed?'登录我的账户':'开启我的旅程';
      const community=N.communityEnabled(),tos=N.safeURL(guest.tos_url),footer=T.landing_footer||'愿每一次相遇，都有一点甜。';
      const brand=()=>`<a class="lp-brand" href="#/landing" aria-label="${E(title)}首页">${logo?`<img src="${E(logo)}" alt="">`:`<span class="lp-brand-sticker">${sticker('bow')}</span>`}<span><strong>${E(title)}</strong>${tagline?`<small>${E(tagline)}</small>`:''}</span></a>`;
      const features=[
        {n:'01',type:'star',color:'#ffe7a6',label:'CHOOSE YOUR FAVORITE',title:'喜欢的套餐，自己选',description:'查看可选套餐与付款周期，按自己的需要，找到合适的订阅。',link:gate('plan'),action:'去看看套餐',icon:'cart'},
        {n:'02',type:'bow',color:'#dabfff',label:'A LITTLE LESS TROUBLE',title:'轻松导入，即刻出发',description:'复制订阅链接，或导入已安装的客户端。常用操作，都放在一起。',link:gate('dashboard'),action:'前往个人中心',icon:'gift'},
        {n:'03',type:'heart',color:'#ffb2ce',label:'ALWAYS HERE FOR YOU',title:'每个疑问，都有入口',description:community?'查阅使用指南，或到售后群聊交流使用问题，让下一步更清楚。':'从使用指南开始，按步骤了解订阅与客户端，慢慢来也没关系。',link:gate(community?'community':'knowledge'),action:community?'前往售后群聊':'阅读使用指南',icon:community?'chat':'book'}
      ];
      return `<div class="lp-paper"><div class="lp-page-trim" aria-hidden="true"></div><header class="lp-nav">${brand()}<nav aria-label="落地页导航">${T.landing_plans_enabled!=='off'?'<button type="button" data-action="landing-scroll" data-section="lp-pricing">套餐价格</button>':''}<button type="button" data-action="landing-scroll" data-section="lp-features">发现美好 <span>✦</span></button><button type="button" data-action="landing-scroll" data-section="lp-guide">开始使用</button><a href="${gate('knowledge')}">使用指南</a></nav><div class="lp-nav-actions">${logged?cta('我的账户','#/dashboard','white'):`<a class="lp-login" href="#/login">已有账号？登录</a>${closed?'':cta('注册账号',N.authHref('register'),'small')}`}</div></header>
        <main>
          <section class="lp-hero" aria-labelledby="lp-title">
            <div class="lp-hero-binding" aria-hidden="true"><i class="lp-binding-tape lp-binding-tape-left"></i><i class="lp-binding-tape lp-binding-tape-right"></i><span class="lp-binding-bow">${satinBow('#dfc5f3')}</span><span class="lp-binding-charms">${sticker('heart','#ffc5df')}${sticker('star','#ffe6ad')}${sticker('heart','#e2caf8')}</span></div>
            ${corners()}<div class="lp-hero-grid" aria-hidden="true"></div><div class="lp-hero-pennants" aria-hidden="true"><i>♡</i><i>✦</i><i>♡</i><i>✦</i><i>♡</i></div><div class="lp-hero-lace" aria-hidden="true"></div>
            <div class="lp-copy"><div class="lp-eyebrow"><span>✦</span> ${E(T.landing_eyebrow||'HELLO, SWEET WORLD!')} <span>♡</span></div><div class="lp-kicker">${E(T.landing_kicker||'欢迎来到你的小小世界')}<span class="lp-kicker-line"></span></div>
              <h1 id="lp-title">${String(heading).split('\n').map((line,i)=>`<span class="${i%2?'lp-title-pink':''}">${E(line)}</span>`).join('')}</h1><p class="lp-description">${E(description)}</p>
              <div class="lp-hero-actions">${cta(startText,start)}${T.landing_plans_enabled==='off'?cta('去看看套餐',gate('plan'),'white'):`<button type="button" class="lp-button lp-button-white" data-action="landing-scroll" data-section="lp-pricing"><span>去看看套餐</span>${arrow}</button>`}</div>
              <div class="lp-small-notes" aria-label="快捷入口"><a href="${gate('dashboard')}"><span class="lp-shortcut-icon">${N.icon('gift')}</span><span><b>我的订阅</b><small>管理与导入</small></span>${arrow}</a><a href="${gate('knowledge')}"><span class="lp-shortcut-icon">${N.icon('book')}</span><span><b>使用指南</b><small>陪你轻松开始</small></span>${arrow}</a><a href="${gate(community?'community':'ticket')}"><span class="lp-shortcut-icon">${N.icon('chat')}</span><span><b>${community?'售后群聊':'我的工单'}</b><small>需要帮助的时候</small></span>${arrow}</a></div>

            </div>
            <div class="lp-art" aria-hidden="true"><div class="lp-photo"><div class="lp-photo-stitches"></div><div class="lp-photo-pearls"></div><span class="lp-photo-top">SWEET MOMENTS</span><span class="lp-photo-bottom">WITH ${E(title)} ♡</span></div><div class="lp-orbit lp-orbit-one"></div><div class="lp-orbit lp-orbit-two"></div><span class="lp-jewel lp-jewel-left">${jewel()}</span><span class="lp-jewel lp-jewel-right">${jewel()}</span><div class="lp-rosette"><span class="lp-rosette-tail"></span><span class="lp-rosette-inner"><small>MADE WITH</small>${sticker("paw","#ffa5cd")}<b>LOVE ♡</b></span></div>
              <div class="lp-girl-window"><img class="lp-girl" src="${E((N.settings.assets_path||'/theme/NINI/assets')+'/retouched/gift-soft-v2-lossless.png')}" alt="" fetchpriority="high" width="1145" height="1374"></div>
              <div class="lp-speech">今天也要<br>好心情呀！<span>♡</span></div><div class="lp-handwritten">Small steps,<br>big happiness <span>✧</span></div>
              <div class="lp-welcome-ticket"><span>YOUR LITTLE WORLD</span><strong>可爱，正在连接中<span>♡</span></strong><i></i><small>WELCOME · ${E(title)}</small></div>
              <div class="lp-art-floating" data-parallax-scene>${ornament('bow',66,9,94,.7)}${ornament('heart',3,162,73,.9)}${ornament('star',528,51,67,.9,'#ffe4a0')}${ornament('star',40,408,59,.8,'#d9c3ff')}${ornament('heart',510,371,57,.8)}${ornament('paw',560,462,54,.6)}${ornament('sparkle',464,17,37,.7,'#fff0ad')}${ornament('sparkle',74,295,33,.9,'#ffcede')}${ornament('star',519,581,48,.8,'#c9ebff')}${ornament('sparkle',24,588,41,.6,'#fff0ad')}</div>
            </div>
            <div class="lp-edge-note" aria-hidden="true">A SWEETER WAY TO STAY CONNECTED</div>
          </section>
          <div class="lp-ribbon-divider" aria-hidden="true"><i class="lp-ribbon-bow">${satinBow()}</i><span>✦</span> SWEET CONNECTION <span>♡</span> LITTLE JOYS EVERY DAY <span>✦</span> SWEET CONNECTION <span>♡</span> LITTLE JOYS EVERY DAY <span>✦</span></div>
          ${T.landing_plans_enabled!=='off'?priceSection(guest.landing_catalog):''}<section class="lp-features lp-section" id="lp-features" aria-labelledby="lp-features-title"><div class="lp-utility-heading"><span class="lp-utility-bow" aria-hidden="true">${satinBow()}</span><div><p>YOUR LITTLE TOOLBOX</p><h2 id="lp-features-title">你的小小便利站 <span>♡</span></h2></div><span class="lp-utility-line" aria-hidden="true"></span><small>常用入口，都在身边。</small></div><div class="lp-feature-grid">${features.map(f=>`<article class="lp-feature lp-feature-${f.n}">${corners()}<div class="lp-card-lace" aria-hidden="true"></div><span class="lp-ticket-number">${f.n}</span><span class="lp-feature-sticker">${sticker(f.type,f.color)}</span><div class="lp-feature-icon">${N.icon(f.icon)}<span>✦</span></div><small>${f.label}</small><h3>${f.title}</h3><p>${f.description}</p><a href="${f.link}">${f.action}${arrow}</a><span class="lp-ticket-perforation" aria-hidden="true"></span></article>`).join('')}</div></section>
          <section class="lp-guide lp-section" id="lp-guide" aria-labelledby="lp-guide-title">${corners()}<div class="lp-guide-topbow" aria-hidden="true">${satinBow("#d5b4ff")}</div><div class="lp-guide-intro"><span class="lp-mini-ribbon">LET’S GET STARTED</span><h2 id="lp-guide-title">带着好心情，<br>一起出发吧 <span>✧</span></h2><p>从第一步开始，每一步都有入口。</p>${cta(logged?'返回我的账户':closed?'前往登录':'创建我的账号',start,'white')}<div class="lp-guide-decoration" aria-hidden="true">${satinBow("#dfc9ff")}${jewel()}</div></div><ol class="lp-steps"><li><span class="lp-step-number">01</span><div><h3>${closed?'登录你的账户':'注册或登录账户'}</h3><p>${closed?'使用已有账号，进入自己的个人中心。':'从自己的账户开始，收藏属于你的连接。'}</p></div><span class="lp-step-mark">♡</span></li><li><span class="lp-step-number">02</span><div><h3>选择适合自己的订阅</h3><p>查看套餐、周期与价格，确认后再继续。</p></div><span class="lp-step-mark">✦</span></li><li><span class="lp-step-number">03</span><div><h3>导入客户端，开始使用</h3><p>在个人中心复制或导入订阅，也可以先看看使用指南。</p></div><span class="lp-step-mark">✧</span></li></ol></section>
          <section class="lp-last-note">${corners()}<span class="lp-last-bow" aria-hidden="true">${satinBow()}</span><span class="lp-last-star" aria-hidden="true">${sticker('star','#ffe5a8')}</span><div><span>SEE YOU ON THE OTHER SIDE ♡</span><h2>属于你的下一站，从这里开始。</h2></div>${cta(startText,start)}<span class="lp-last-heart" aria-hidden="true">${sticker('heart')}</span></section>
        </main>
        <footer class="lp-footer"><div class="lp-footer-lace" aria-hidden="true"></div><div>${brand()}<p>${E(footer)}</p></div><div class="lp-footer-links"><a href="${gate('knowledge')}">使用指南</a>${community?`<a href="${gate('community')}">售后群聊</a>`:''}${tos?`<a href="${E(tos)}" target="_blank" rel="noopener noreferrer">服务条款</a>`:''}<span>${E(title)}${tagline?` <b>♡</b> ${E(tagline)}`:''}</span></div></footer>
      </div>`;
    }
  };
})(window.Nini);
