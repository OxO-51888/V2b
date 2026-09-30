(function(N){
  'use strict';
  const E=N.e,S=N.state;
  const pageKeys=['dashboard','community','knowledge','plan','node','order','invite','profile','ticket','traffic'];
  // Only decorative portions of the approved artwork are displayed by these
  // SVG viewports. All business data, navigation and controls remain real DOM.
  N.fragment=function(page,x,y,w,h,cls='',options={}){
    if(!pageKeys.includes(page))page='dashboard';
    const id='art-edge-'+(++stickerID);
    // Keep every approved ornament. A restrained print tone removes the gray
    // veil during rendering; source PNGs and crop geometry remain untouched.
    const edge=`<defs><filter id="${id}" filterUnits="userSpaceOnUse" x="${Number(x)}" y="${Number(y)}" width="${Number(w)}" height="${Number(h)}" color-interpolation-filters="sRGB"><feConvolveMatrix order="3" kernelMatrix="0 -.16 0 -.16 1.64 -.16 0 -.16 0" divisor="1" bias="0" preserveAlpha="true" edgeMode="duplicate"/><feColorMatrix type="saturate" values="1.065"/><feComponentTransfer><feFuncR type="linear" slope="1.05" intercept="-.025"/><feFuncG type="linear" slope="1.05" intercept="-.025"/><feFuncB type="linear" slope="1.05" intercept="-.025"/></feComponentTransfer></filter></defs>`;
    const bounds=[Number(x),Number(y),Number(w),Number(h)];
    const background=(N.artBackground&&N.artBackground(page,bounds,id,options))||`<g class="reference-background"><image filter="url(#${id})" href="${E(new URL((N.settings.assets_path||'/theme/NINI/assets')+'/reference/'+page+'.webp',window.location.href).href)}" x="0" y="0" width="1536" height="1024"/></g>`;
    const lettering=options.lettering!==false&&N.artLettering&&!/(^|\s)frame-(top|bottom|left|right)(\s|$)/.test(cls)?N.artLettering(page,bounds,id,options):'';
    const sprites=N.artSprites?N.artSprites(page,bounds,id,options):'';
    const geometry=`width="${Number(w)}" height="${Number(h)}" viewBox="${Number(x)} ${Number(y)} ${Number(w)} ${Number(h)}" preserveAspectRatio="xMidYMid meet" aria-hidden="true" focusable="false"`;
    const size=`--native-width:${Number(w)}px;--native-height:${Number(h)}px`;
    // The sidebar crop overlaps the old navigation border. Preserve the ear
    // and hair tips while excluding that border from the static artwork only.
    const sidebar=/(^|\s)sidebar-original-art(\s|$)/.test(cls);
    const crop=sidebar?`<defs><clipPath id="${id}-sidebar"><path d="M1 682H79L80 679L81 675L82 672L83 670H94L102 674L109 678L116 682H131L133 678L135 675L139 672L143 670H153L158 673L161 676L163 681L164 682H289V1020H1Z"/></clipPath></defs>`:'';
    const artwork=`${edge}${crop}<g class="reference-surface"${sidebar?` clip-path="url(#${id}-sidebar)"`:''}>${background}${lettering}</g>`;
    if(sprites)return `<span class="reference-fragment reference-layered-fragment ${E(cls)}" style="${size}" aria-hidden="true"><svg class="reference-artwork" ${geometry}>${artwork}</svg><span class="mascot-drift-layer" data-parallax-scene>${sprites}</span></span>`;
    return `<svg class="reference-fragment ${E(cls)}" ${geometry} style="${size}">${artwork}</svg>`;
  };
  let stickerID=0;
  // Small ornaments must remain vector paths: a 30px crop of a page mockup
  // cannot provide clean outlines on a high density display.
  const detailPaths={
    chart:'<path d="M3 7v18c0 7 26 7 26 0V7Z"/><path d="M3 13c0 7 26 7 26 0M3 20c0 7 26 7 26 0" fill="none"/><path d="M5 15c2 5 20 5 22 0M5 22c2 5 20 5 22 0" fill="none" stroke="#ffdef0" stroke-width="1.2"/><ellipse cx="16" cy="7" rx="13" ry="4" fill="#ffa8cc"/><path d="M6 6c5-3 15-3 20 0M6 11v13" fill="none" stroke="#fff5fa" stroke-width="1.2"/>',
    calendar:'<rect x="3" y="6" width="26" height="24" rx="3"/><path d="M3 13h26M10 2v8M22 2v8"/><path d="M9 18h2m5 0h2m5 0h1M9 23h2m5 0h2m5 0h1M9 27h2m5 0h2m5 0h1" stroke-width="2.3"/>',
    device:'<rect x="2" y="3" width="22" height="20" rx="3"/><path d="M10 23v5m-5 1h11"/><rect x="20" y="11" width="10" height="20" rx="2.5" fill="#fff8fc"/><path d="M23 15h4m-3 12h2" stroke-width="1.5"/>',
    crown:'<path d="m2 8 8 6 6-11 6 11 8-6-5 20H7Z"/><path d="M8 24h16" stroke="#fff" stroke-width="1.7"/>',
    star:'<path d="m16 2 4.5 9 10 1.5-7.2 7 1.7 10-9-4.7-9 4.7 1.7-10-7.2-7L11.5 11Z"/><path d="m15 8-3 6-5 .8" fill="none" stroke="#fff" stroke-width="1.6"/>',
    diamond:'<path d="m7 4-6 9 15 18 15-18-6-9ZM1 13h30M7 4l9 27 9-27M7 4l9 9 9-9" fill="none"/>',
    gear:'<path d="m13 2 6 0 1 4 3 2 4-1 3 5-3 3v3l3 3-3 5-4-1-3 2-1 4h-6l-1-4-3-2-4 1-3-5 3-3v-3l-3-3 3-5 4 1 3-2Z"/><circle cx="16" cy="16" r="5" fill="#fff9fc" stroke="none"/>'
  };
  function detailSVG(w,h,cls,content,defs=''){
    return `<svg class="detail-vector ${E(cls)}" width="${w}" height="${h}" style="--native-width:${w}px;--native-height:${h}px" viewBox="0 0 ${w} ${h}" aria-hidden="true" focusable="false"><defs>${defs}</defs>${content}</svg>`;
  }
  N.detailMedallion=function(name,w=56,h=55,cls=''){
    const id='detail-'+(++stickerID),cx=Math.floor(w/2),cy=Math.floor(h/2),r=Math.floor(Math.min(w,h)/2)-3;
    const glyphSize=w<42?22:32;
    const defs=`<linearGradient id="${id}" x2=".2" y2="1"><stop stop-color="#fffafd"/><stop offset="1" stop-color="#ffe1ee"/></linearGradient><linearGradient id="${id}-ink" x2=".3" y2="1"><stop stop-color="#ffacd0"/><stop offset="1" stop-color="#ff6da5"/></linearGradient>`;
    return detailSVG(w,h,cls,`<circle cx="${cx}" cy="${cy}" r="${r}" fill="url(#${id})" stroke="#ffeaf3"/><path d="M${cx-r+4} ${cy}a${r-4} ${r-4} 0 0 1 ${r-4} -${r-4}" fill="none" stroke="#fff" stroke-width="2"/><g transform="translate(${cx-glyphSize/2} ${cy-glyphSize/2}) scale(${glyphSize/32})" fill="${name==='chart'?'url(#'+id+'-ink)':'#ffe7f1'}" stroke="#f73587" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${detailPaths[name]||detailPaths.star}</g>`,defs);
  };
  N.detailCorner=function(name,w,h,cls=''){
    const ink=name==='device'?'#ac90ba':'#ee86b2';
    return detailSVG(w,h,cls,`<g transform="translate(${Math.floor((w-32)/2)} ${Math.floor((h-32)/2)})" fill="${name==='star'?'#ffdfed':'#fff4fa'}" stroke="${ink}" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">${detailPaths[name]||detailPaths.star}</g>`);
  };
  N.coinIcon=function(){
    const id='coin-'+(++stickerID);
    return detailSVG(35,36,'wallet-original-icon',`<circle cx="17.5" cy="18" r="16" fill="#fff8df" stroke="#efc57f"/><circle cx="17.5" cy="18" r="13" fill="url(#${id})" stroke="#eba449"/><circle cx="17.5" cy="18" r="10.5" fill="none" stroke="#ffeaae"/><path d="m17.5 9 2.7 5.5 6.1.9-4.4 4.3 1 6-5.4-2.8-5.4 2.8 1-6-4.4-4.3 6.1-.9Z" fill="#ffe17b" stroke="#dd9c37" stroke-linejoin="round"/><path d="M6 14a12 12 0 0 1 10-8" fill="none" stroke="#fffdf0" stroke-width="1.5"/>`,`<linearGradient id="${id}" x2=".3" y2="1"><stop stop-color="#fff7c2"/><stop offset="1" stop-color="#ffca67"/></linearGradient>`);
  };
  function ornament(name,cls=''){
    if(name==='crown')return detailSVG(32,29,cls,'<path d="m2 5 8 6L16 1l6 10 8-6-4 22H6Z" fill="#ffe48b" stroke="#fff4bf" stroke-width="1.2" stroke-linejoin="round"/><path d="M16 22c-7-4-6-9-2-8l2 2 2-2c4-1 5 4-2 8Z" fill="#ffaf7b"/><path d="M8 24h16" stroke="#fff4c8"/>');
    if(name==='megaphone')return detailSVG(44,28,cls,'<g stroke="#ed4e96" stroke-width="1.1" stroke-linejoin="round"><path d="m13 18 4 9 5-2-3-9" fill="#ffa5cc"/><path d="M8 8h10L30 2v24l-12-6H8Z" fill="#ffb4d5"/><rect x="4" y="9" width="7" height="10" rx="2" fill="#ff75b1"/><ellipse cx="30" cy="14" rx="4" ry="12" fill="#ffd3e6"/><ellipse cx="30" cy="14" rx="1.5" ry="8" fill="#ff9fc7"/><path d="M17 10v8M38 7l3-2m-2 9h4m-5 7 3 2" fill="none"/><path d="m11 10 6 0 9-4" stroke="#fff4fa" fill="none"/></g>');
    return N.detailCorner('star',41,46,cls);
  }
  function stickerIcon(name,cls='',active=false){
    const id='nini-sticker-'+(++stickerID),ink=active?'#fff':'url(#'+id+')',line=active?'#fff':'#ff347e',pale=active?'#ffffff33':'#ffdeed';
    const shapes={
      dashboard:`<path d="M3 14 18 2 33 14l-3 4-3-2v16h-7V22h-5v10H8V16l-3 2Z" fill="${ink}"/><path d="m9 11 9-7 9 7" fill="none" stroke="#fff" stroke-opacity=".55"/>`,
      community:`<path d="M25 13c5 2 8 5 7 10 0 3-2 6-5 7l2 3-7-2h-5" fill="${pale}" stroke="${line}" stroke-width="2.3"/><path d="M29 14c0 7-6 11-13 11l-9 5 2-7C-2 15 5 3 16 3c7 0 13 5 13 11Z" fill="none" stroke="${line}" stroke-width="2.5"/><g fill="${ink}"><circle cx="10" cy="14" r="1.7"/><circle cx="16" cy="14" r="1.7"/><circle cx="22" cy="14" r="1.7"/></g>`,
      knowledge:`<rect x="7" y="2" width="22" height="31" rx="3" fill="${ink}"/><path d="M11 4v27M15 9h9M15 14h9M15 19h7M15 26h9" fill="none" stroke="${active?'#ef6098':'#fff'}" stroke-width="2" stroke-linecap="round"/>`,
      plan:`<path d="M2 5h5l5 19h17" fill="none" stroke="${line}" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M9 10h23l-3 10H12Z" fill="${pale}" stroke="${line}" stroke-width="2.4" stroke-linejoin="round"/><path d="M15 14h12" stroke="${line}" stroke-width="1.5"/><circle cx="14" cy="30" r="2.6" fill="${ink}"/><circle cx="28" cy="30" r="2.6" fill="${ink}"/>`,
      node:`<path d="M18 34C10 24 5 20 5 13a13 13 0 1 1 26 0c0 7-5 12-13 21Z" fill="${ink}"/><circle cx="18" cy="13" r="5" fill="${active?'#f4619d':'white'}"/><circle cx="18" cy="13" r="2" fill="${ink}"/><path d="M9 12c0-4 3-8 7-8" stroke="#fff" stroke-opacity=".6" stroke-width="1.4" fill="none"/>`,
      order:`<rect x="6" y="2" width="25" height="32" rx="4" fill="${ink}"/><path d="M13 10h11M13 17h11M13 24h8" stroke="${active?'#f15d99':'white'}" stroke-width="2.5" stroke-linecap="round"/><path d="M9 6v23" stroke="#fff" stroke-opacity=".4"/>`,
      invite:`<g stroke="${line}" stroke-width="2.6" fill="none"><circle cx="13" cy="9" r="5"/><circle cx="26" cy="12" r="4"/><path d="M3 32v-5c0-5 4-8 10-8s10 3 10 8v5ZM25 23c6 0 9 3 9 7v2h-7"/></g><path d="M7 27c1-3 3-4 6-4" fill="none" stroke="${line}" stroke-width="1.2"/>`,
      profile:`<circle cx="18" cy="10" r="7" fill="${pale}" stroke="${line}" stroke-width="2.6"/><path d="M6 33v-4c0-6 5-10 12-10s12 4 12 10v4Z" fill="none" stroke="${line}" stroke-width="2.6"/><path d="M13 7c1-2 3-3 5-3" fill="none" stroke="#fff" stroke-width="1.4"/>`,
      ticket:`<path d="M5 18v-4a13 13 0 0 1 26 0v10c0 6-3 9-10 9" fill="none" stroke="${line}" stroke-width="2.7"/><rect x="1" y="14" width="8" height="15" rx="4" fill="${ink}"/><rect x="27" y="14" width="8" height="15" rx="4" fill="${ink}"/><rect x="16" y="29" width="9" height="5" rx="2.5" fill="${ink}"/><path d="M4 17v8M30 17v8" stroke="#fff" stroke-opacity=".5"/>`,
      traffic:`<rect x="4" y="19" width="6" height="14" rx="2.5" fill="${ink}"/><rect x="15" y="10" width="6" height="23" rx="2.5" fill="${ink}"/><rect x="26" y="2" width="6" height="31" rx="2.5" fill="${ink}"/><path d="M6 22v7M17 13v16M28 5v24" stroke="#fff" stroke-opacity=".42" stroke-linecap="round"/>`
    };
    return `<svg class="sticker-glyph ${E(cls)}" viewBox="0 0 36 36" aria-hidden="true" focusable="false"><defs><linearGradient id="${id}" x1="0" y1="0" x2=".3" y2="1"><stop stop-color="${active?'#fff':'#ff6aa6'}"/><stop offset="1" stop-color="${active?'#fff':'#f42175'}"/></linearGradient></defs>${shapes[name]||shapes.profile}</svg>`;
  }
  N.navIcon=function(key,active){return stickerIcon(key,'nav-art-icon',active);};
  N.headerIcon=function(name){return `<svg class="icon header-detail-icon" viewBox="0 0 36 36" aria-hidden="true" fill="#fffafd" stroke="#38233e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">${name==='bell'?'<path d="M7 26c4-4 3-7 3-12a8 8 0 0 1 16 0c0 5-1 8 3 12Z"/><path d="M14 31c2 3 6 3 8 0M16 4V2h4v2"/><path d="M13 12c0-3 2-5 4-5" stroke="#cabacb" stroke-width="1"/>':'<path d="m15 3 6 0 1 4 3 1 3-2 4 4-2 3 1 3 4 1v6l-4 1-1 3 2 3-4 4-3-2-3 1-1 4h-6l-1-4-3-1-3 2-4-4 2-3-1-3-4-1v-6l4-1 1-3-2-3 4-4 3 2 3-1Z" transform="translate(1 -2) scale(.94)"/><circle cx="18" cy="18" r="6"/><circle cx="18" cy="18" r="2.2" fill="#fce8f2" stroke="none"/>'}</svg>`;};
  function dashboardArtwork(customWelcome){
    const id='dashboard-art-'+(++stickerID);
    function box(x,y,w,h,r=0){return r?`M${x+r} ${y}H${x+w-r}Q${x+w} ${y} ${x+w} ${y+r}V${y+h-r}Q${x+w} ${y+h} ${x+w-r} ${y+h}H${x+r}Q${x} ${y+h} ${x} ${y+h-r}V${y+r}Q${x} ${y} ${x+r} ${y}Z`:`M${x} ${y}h${w}v${h}h-${w}Z`;}
    const holes=box(349,293,198,60,30)+box(557,293,198,60,30)+(customWelcome?box(344,156,516,94):'');
    return N.fragment('dashboard',309,92,1206,296,'dashboard-continuous-art',{parallax:true}).replace('<g class="reference-surface"',`<defs><clipPath id="${id}"><path clip-rule="evenodd" d="${box(309,92,1206,296)}${holes}"/></clipPath></defs><g class="reference-surface" clip-path="url(#${id})"`);
  }
  N.avatar=function(cls=''){return N.fragment('dashboard',316,10,70,70,'original-avatar '+cls);};
  const metricArt={chart:[320,408,58,56],calendar:[623,408,56,55],device:[927,408,57,55],crown:[1225,408,56,55]};
  N.frame=function(page,x,y,w,h,edge=12,cls=''){
    return `<span class="reference-frame ${E(cls)}">${N.fragment(page,x,y,w,edge,'frame-top')}${N.fragment(page,x,y+h-edge,w,edge,'frame-bottom')}${N.fragment(page,x,y+edge,edge,h-2*edge,'frame-left')}${N.fragment(page,x+w-edge,y+edge,edge,h-2*edge,'frame-right')}</span>`;
  };
  N.metricArt=name=>metricArt[name]?N.detailMedallion(name,metricArt[name][2],metricArt[name][3],'metric-original-icon metric-icon-'+name):N.icon(name);
  N.brand=function(){const s=N.settings,tagline=N.theme.brand_tagline||'';return `<a class="brand brand-dynamic" href="#/dashboard" aria-label="${E(s.title||'用户中心')}">${s.logo?`<img class="brand-config-logo" src="${E(N.safeURL(s.logo))}" alt="${E(s.title)}">`:`<strong style="font-size:${Math.min(48,176/Math.max(3,[...(s.title||'用户中心')].reduce((n,c)=>n+(/[\u0000-\u00ff]/.test(c)?.55:1),0)))}px">${E(s.title||'用户中心')}</strong>${tagline?`<small title="${E(tagline)}">${E(tagline)}</small>`:''}`}${N.fragment('dashboard',15,19,44,45,'brand-original-star')}${N.fragment('dashboard',16,75,45,43,'brand-original-heart')}${N.fragment('dashboard',198,10,63,66,'brand-original-bow')}${N.fragment('dashboard',245,69,29,35,'brand-original-sparkle')}</a>`;};
  const dynamicBrand=N.brand;
  N.brand=function(){return !N.settings.logo&&N.settings.title==='Keke'&&!N.theme.brand_tagline?`<a class="brand brand-approved" href="#/dashboard" aria-label="${E(N.settings.title)}">${N.fragment('dashboard',0,0,289,149,'brand-approved-art')}</a>`:dynamicBrand();};
  function status(label,tone='success'){return N.badge(label,tone);}
  const glyph=(name)=>N.icon(name);
  N.pages.dashboard.render=function(_route,notices){
    const s=S.sub,p=s.plan||{},has=!!s.plan_id,total=Number(s.transfer_enable||0),used=Number(s.u||0)+Number(s.d||0),left=Math.max(0,total-used),pct=total?Math.max(0,Math.min(100,Math.round(left/total*100))):0,days=s.expired_at==null?null:Math.max(0,Math.ceil((s.expired_at*1000-Date.now())/86400000)),active=has&&(days===null||days>0);
    const welcome=N.theme.welcome_title||'早安，小魔法师';
    const canReset=has&&active&&!N.on(S.user.banned)&&Number(S.user.transfer_enable)>0&&p.reset_price!=null,canStartPeriod=has&&N.on(s.allow_new_period);
    const subscriptionBadge=label=>has?status(!active?'已到期':left<=0?'流量已用尽':label,active&&left>0?'success':'warning'):'';
    const ring=`<div class="fidelity-ring" style="--progress:${pct}%"><svg viewBox="0 0 115 115" aria-hidden="true"><circle cx="57.5" cy="57.5" r="50" fill="none" stroke="#ffdeea" stroke-width="14"/><circle cx="57.5" cy="57.5" r="50" fill="none" stroke="#ff579a" stroke-width="14" stroke-linecap="round" visibility="${pct>0?'visible':'hidden'}" stroke-dasharray="${pct/100*314.159} 314.159" transform="rotate(-90 57.5 57.5)"/></svg><b>${pct}%</b><span>剩余</span></div>`;
    function metric(title,icon,value,foot,badge,cls=''){const corner={chart:[546,542,37,37],calendar:[846,538,33,39],device:[1139,538,31,37],crown:[1456,538,38,38]}[icon];return `<section class="card fidelity-metric ${cls}"><div class="metric-heading"><span class="metric-medallion">${N.metricArt(icon)}</span><h2>${title}</h2>${badge||''}</div><div class="metric-interior">${value}<p>${foot}</p></div>${N.detailCorner(({chart:'star',calendar:'calendar',device:'device',crown:'diamond'})[icon],corner[2],corner[3],'metric-corner-art corner-'+icon)}</section>`;}
    return `<header class="fidelity-dashboard-hero">${dashboardArtwork(!!N.theme.welcome_title)}${N.fragment('dashboard',880,92,440,296,'mobile-dashboard-art',{lettering:false})}<div class="dashboard-hero-copy"><h1 class="${N.theme.welcome_title?'custom-greeting':'sr-only'}">${E(welcome)}</h1><p class="sr-only">订阅、流量与服务状态，一眼掌握</p><div class="row">${has?N.button(ornament('crown','subscribe-crown-art')+' 一键订阅','import-sub'):''}<a class="button secondary" href="#/plan">${stickerIcon('plan','purchase-cart-icon')} 购买订阅</a></div></div></header><div class="fidelity-metrics">${metric('剩余流量','chart',ring,`<b>${N.bytes(left)}</b> / ${N.bytes(total)}`,subscriptionBadge('使用中'),'traffic-card')}${metric('到期时间','calendar',`<strong class="metric-number">${has?(days===null?'长期有效':`${days}<small> 天</small>`):'暂无订阅'}</strong><div class="metric-progress" aria-hidden="true"></div>`,has?(days===null?'长期有效':N.date(s.expired_at)):'购买后开始使用',has?status(active?'有效':'已到期',active?'success':'warning'):'')}${metric('在线设备','device',`<strong class="metric-number">${s.alive_ip==null?'—':E(s.alive_ip)}<small> 台</small></strong><div class="metric-progress" aria-hidden="true"></div>`,`设备上限 ${s.device_limit?E(s.device_limit):'不限'}`,s.alive_ip?status('在线'):'')}${metric('当前套餐','crown',`<strong class="metric-number" title="${E(p.name||'暂无订阅')}">${E(p.name||'暂无订阅')}</strong><div class="metric-progress" aria-hidden="true"></div>`,s.reset_day!=null?`重置日 ${E(s.reset_day)} 天后`:'暂无周期重置',subscriptionBadge('订阅中'),'current-plan-card')}</div><div class="fidelity-action-strip ${canReset&&canStartPeriod?'has-four-actions':''}">${N.fragment('dashboard',301,603,65,63,'strip-star left')}${N.fragment('dashboard',1483,601,46,62,'strip-star right')}${canReset?`<a href="#/plan/${E(s.plan_id)}?period=reset_price"><span class="action-round">${glyph('refresh')}</span><span><b>流量重置</b><small>${E(N.money(p.reset_price))}</small></span>${glyph('arrow')}</a>`:''}${canStartPeriod?`<button data-action="new-period"><span class="action-round">${glyph('clock')}</span><span><b>提前开启周期</b><small>立即重置并开始新周期</small></span>${glyph('arrow')}</button>`:''}<a href="#/order"><span class="action-round">${stickerIcon('order','action-order-icon')}</span><span><b>待支付订单 ${N.badge(S.stat[0]||0)}</b><small>有 ${E(S.stat[0]||0)} 个订单待支付</small></span>${glyph('arrow')}</a><a href="#/ticket"><span class="action-round">${stickerIcon('ticket','action-ticket-icon')}</span><span><b>处理中工单 ${N.badge(S.stat[1]||0)}</b><small>查看客服回复</small></span>${glyph('arrow')}</a></div><div class="fidelity-dashboard-bottom"><section class="card fidelity-subscribe"><header class="card-head">${N.fragment('dashboard',322,692,51,48,'card-bow')}<div><h2>订阅快捷操作</h2><p>复制链接或导入客户端。</p></div>${N.fragment('dashboard',727,689,41,46,'heading-star')}</header><div class="card-body">${has?`<div class="row sub-copy"><input class="input" readonly aria-label="订阅链接" value="${E(s.subscribe_url||'')}">${N.button('复制订阅 '+glyph('copy'),'copy-sub')}</div>${N.clientPicker(false)}`:N.empty('购买订阅后，即可在这里导入客户端')}</div></section><div class="fidelity-dashboard-right">${N.communityEnabled()?`<a class="fidelity-community-banner" href="#/community">${N.fragment('dashboard',795,679,719,154,'community-complete-art')}<span class="sr-only">售后群聊，交流使用问题，获取售后帮助，进入群聊</span></a>`:''}<section class="card fidelity-notices"><header class="card-head">${ornament('megaphone','notice-megaphone-art')}<h2>最新公告</h2>${N.button('查看全部公告 →','notices','','secondary')}</header><div class="card-body">${(notices||[]).slice(0,2).map((n,i)=>`<button class="notice" data-action="notice-detail" data-id="${E(n.id)}"><span class="notice-round">${N.detailMedallion(i?'gear':'chart',38,37,'notice-original-icon')}</span><span><strong>${E(n.title)}</strong><small>${E(String(n.content||'').replace(/<[^>]*>/g,'').replace(/[#*]/g,'').slice(0,65))}</small></span><time>${N.date(n.created_at)}</time></button>`).join('')||N.empty('暂无公告')}</div></section>${N.subscriptionExplanations(s.subscription_rule_explanations,true)}</div></div>`;
  };
  // Configured greetings keep their full text inside the approved title area.
  const fitArtworkTypography=N.fitArtworkTypography;
  const fittedGreetings=new WeakMap(),greetingBatches=new WeakMap();
  N.fitArtworkTypography=function(root=document){
    if(fitArtworkTypography)fitArtworkTypography(root);
    const doc=root.nodeType===9?root:root.ownerDocument;
    let batch=greetingBatches.get(doc);
    if(!batch){
      batch={roots:new Set(),pending:false,fontVersion:0};greetingBatches.set(doc,batch);
      if(doc.fonts?.addEventListener)doc.fonts.addEventListener('loadingdone',()=>{batch.fontVersion++;N.fitArtworkTypography(doc);});
    }
    batch.roots.add(root);if(batch.pending)return;batch.pending=true;
    const ready=doc.fonts?.ready;
    Promise.resolve(ready).then(()=>{
      if(doc.fonts&&doc.fonts.ready!==ready){batch.pending=false;N.fitArtworkTypography(doc);return;}
      const roots=batch.roots.has(doc)?[doc]:Array.from(batch.roots);batch.roots.clear();batch.pending=false;
      const titles=new Set();roots.forEach(scope=>scope.querySelectorAll('.dashboard-hero-copy>.custom-greeting').forEach(title=>titles.add(title)));
      for(const title of titles){
        if(!title.isConnected)continue;
        const width=title.clientWidth,height=title.clientHeight;if(!width||!height)continue;
        const signature=JSON.stringify([title.textContent,width,height,title.style.fontFamily,title.style.fontWeight,title.style.letterSpacing]),previous=fittedGreetings.get(title);
        if(previous&&previous.signature===signature&&previous.ready===ready&&previous.fontVersion===batch.fontVersion&&previous.size===title.style.fontSize)continue;
        let size=62;
        title.style.fontSize=size+'px';
        for(let step=0;step<48;step++){
          const scrollWidth=title.scrollWidth,scrollHeight=title.scrollHeight;
          if(scrollWidth<=width+1&&scrollHeight<=height+1)break;
          const ratio=Math.min(width/Math.max(1,scrollWidth),Math.sqrt(height/Math.max(1,scrollHeight)),.95);
          size*=ratio;
          title.style.fontSize=size.toFixed(3)+'px';
        }
        fittedGreetings.set(title,{signature,ready,fontVersion:batch.fontVersion,size:title.style.fontSize});
      }
    });
  };
})(window.Nini);
