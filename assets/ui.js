(() => {
  'use strict';
  const theme = window.SedTheme;
  if (!theme) return;
  const el = (tag, className, text) => { const node = document.createElement(tag); if (className) node.className = className; if (text) node.textContent = text; return node; };
  const button = (text, label) => { const b = el('button','',text); b.type = 'button'; if (label) b.setAttribute('aria-label',label); return b; };
  const focusables = root => [...root.querySelectorAll('a[href],button:not(:disabled),input:not(:disabled),select:not(:disabled),textarea:not(:disabled),summary,[tabindex="0"]')].filter(n => {
    if (!n.getClientRects().length || n.closest('[inert]') || getComputedStyle(n).visibility==='hidden') return false;
    for(let parent=n.parentElement;parent && parent!==root;parent=parent.parentElement) if(parent.tagName==='DETAILS' && !parent.open && !parent.querySelector('summary')?.contains(n)) return false;
    return true;
  });
  const trap = (event, root) => { if (event.key !== 'Tab') return; const nodes = focusables(root); const first = nodes[0], last = nodes.at(-1); if (!first) {event.preventDefault(); return;} if (event.shiftKey && (document.activeElement === first || !root.contains(document.activeElement))) {event.preventDefault();last.focus();} else if (!event.shiftKey && (document.activeElement === last || !root.contains(document.activeElement))) {event.preventDefault();first.focus();} };

  const dialog = el('dialog','ui-dialog'); dialog.id = 'ui-theme-dialog'; dialog.setAttribute('aria-labelledby','ui-theme-title');
  const head = el('div','ui-dialog-head'), title = el('h2','','主题设置'); title.id = 'ui-theme-title';
  const close = button('×','关闭主题设置'); head.append(title,close); dialog.append(head,el('p','muted','即时预览，偏好仅保存在当前浏览器。'));
  const colors = {default:'#f4f5fa',dark:'#202b3a','translucent-green':'linear-gradient(115deg,#00cef9,#00e6af)','translucent-blue':'linear-gradient(115deg,#9f78ff,#32cafe)','translucent-yellow':'linear-gradient(115deg,#ff8008,#ffc837)','translucent-red':'linear-gradient(115deg,#ff5858,#e888b7)','translucent-pink':'linear-gradient(115deg,#a376fc,#f96f9b)','translucent-cyan':'linear-gradient(115deg,#514a9d,#24c6dc)',color_2:'#15c377',color_3:'#48b0f7',color_4:'#faa64b',color_5:'#f44236',color_6:'#926dde',color_7:'#33cabb',color_8:'#212529'};
  for (const [field,label] of [['theme','整体主题'],['logo','Logo / 品牌区域'],['header','顶部导航'],['sidebar','侧边栏']]) {
    const group = el('fieldset','ui-palette-group'), legend = el('legend','',label), grid = el('div','ui-swatches');
    for (const [value,name] of field === 'theme' ? theme.themes : theme.regions) {
      const wrap = el('label','ui-swatch'), input = el('input'), chip = el('span','ui-swatch-chip');
      input.type = 'radio'; input.name = 'ui-' + field; input.value = value; input.dataset.preference = field;
      input.setAttribute('aria-label',label + '：' + name); chip.style.setProperty('--swatch',colors[value]); chip.setAttribute('aria-hidden','true');
      wrap.append(input,chip,el('span','',name)); grid.append(wrap);
      input.addEventListener('change', () => theme.set({[field]:value}));
    }
    group.append(legend,grid); dialog.append(group);
  }
  const footer = el('div','ui-theme-footer'), reset = button('恢复默认'), status = el('div','ui-theme-status');
  status.setAttribute('role','status'); reset.addEventListener('click',()=>theme.reset()); footer.append(reset,status); dialog.append(footer); document.body.append(dialog);
  const sync = (saved = true) => { const state = theme.get(); dialog.querySelectorAll('input').forEach(input=>input.checked = state[input.dataset.preference] === input.value); status.textContent = saved ? '偏好已应用；跨页面保持一致。' : '偏好已应用；浏览器不允许保存，刷新后恢复默认。'; };
  sync(); document.addEventListener('sed:theme', event=>sync(event.detail.saved));
  let returnFocus;
  const openTheme = event => { returnFocus = event.currentTarget; dialog.showModal(); close.focus(); };
  close.addEventListener('click',()=>dialog.close()); dialog.addEventListener('close',()=>returnFocus?.focus());
  dialog.addEventListener('click',event=>{if (event.target === dialog && event.clientX < dialog.getBoundingClientRect().left) dialog.close();});
  dialog.addEventListener('keydown',event=>trap(event,dialog));
  if (!document.querySelector('[data-theme-open]')) {
    const tools = el('div','ui-auth-tools'), trigger = button('主题设置'); trigger.dataset.themeOpen = ''; tools.append(trigger); document.body.append(tools);
  }
  document.querySelectorAll('[data-theme-open]').forEach(trigger=>{trigger.setAttribute('aria-haspopup','dialog');trigger.setAttribute('aria-controls',dialog.id);trigger.addEventListener('click',openTheme);});

  const sidebar = document.querySelector('.ui-sidebar'), toggle = document.querySelector('[data-ui-toggle]'), backdrop = document.querySelector('.ui-backdrop');
  const mobile = matchMedia('(max-width:991px)'); let drawerOpen = false, drawerTrigger, inertBefore = [];
  const release = () => {inertBefore.forEach(([node,value])=>node.inert=value);inertBefore=[];};
  const setDrawer = open => {
    if (!sidebar) return;
    drawerOpen = open; document.body.classList.toggle('ui-drawer-open',open); if (backdrop) backdrop.hidden = !open;
    toggle?.setAttribute('aria-expanded',String(open)); sidebar.inert = mobile.matches && !open;
    release();
    if (open) {
      drawerTrigger = document.activeElement;
      document.querySelectorAll('.ui-topbar,.ui-content,.ui-auth-tools').forEach(node=>{inertBefore.push([node,node.inert]);node.inert=true;});
      sidebar.setAttribute('role','dialog');sidebar.setAttribute('aria-modal','true');
      requestAnimationFrame(()=>{if(drawerOpen) sidebar.querySelector('.ui-nav-link[aria-current="page"],a[href]')?.focus();});
    } else {
      sidebar.removeAttribute('role');sidebar.removeAttribute('aria-modal');if (drawerTrigger?.isConnected) drawerTrigger.focus();drawerTrigger = null;
    }
  };
  const syncLayout = () => {if (!sidebar) return;setDrawer(false);sidebar.inert=mobile.matches;toggle?.setAttribute('aria-expanded',String(!mobile.matches && !theme.get().collapsed));toggle?.setAttribute('aria-label',mobile.matches?'打开导航':theme.get().collapsed?'展开导航':'折叠导航');};
  toggle?.addEventListener('click',()=>{if(mobile.matches)setDrawer(!drawerOpen);else {theme.set({collapsed:!theme.get().collapsed});syncLayout();}});
  document.querySelectorAll('[data-ui-close]').forEach(node=>node.addEventListener('click',()=>setDrawer(false)));
  sidebar?.addEventListener('click',event=>{
    if (event.target.closest('a[href]') && mobile.matches) setDrawer(false);
  });
  mobile.addEventListener('change',syncLayout);syncLayout();
  document.addEventListener('sed:theme',()=>{if (!drawerOpen) syncLayout();});
  document.addEventListener('keydown',event=>{if (drawerOpen) {if(event.key==='Escape'){event.preventDefault();setDrawer(false);}else trap(event,sidebar);} if(event.key==='Escape')document.querySelectorAll('.ui-user-menu[open]').forEach(menu=>{menu.open=false;menu.querySelector('summary').focus();});});
  document.addEventListener('click',event=>document.querySelectorAll('.ui-user-menu[open]').forEach(menu=>{if(!menu.contains(event.target))menu.open=false;}));

  // A scroll region per table keeps headings/filters on the page and wide data inside the card.
  document.querySelectorAll('table').forEach(table=>{
    if (!table.parentElement.classList.contains('ui-table-scroll')) {const wrap = el('div','ui-table-scroll');wrap.tabIndex=0;wrap.setAttribute('role','region');wrap.setAttribute('aria-label','数据表格，可横向滚动');table.before(wrap);wrap.append(table);}
    if (table.tBodies[0] && !table.tBodies[0].rows.length) {const row = table.tBodies[0].insertRow(),cell = row.insertCell();cell.colSpan=table.tHead?.rows[0]?.cells.length||1;cell.className='empty-state';cell.textContent='暂无数据';}
    const rows=[...(table.tBodies[0]?.rows||[])];
    if(rows.length>20) {
      let current=1,size=20;
      const pager=el('nav','ui-pagination');pager.setAttribute('aria-label','已加载记录分页');
      const label=el('label','','每页'),select=el('select'),previous=button('上一页'),next=button('下一页'),info=el('span','muted');info.setAttribute('role','status');
      for(const [value,text] of [[20,'20 条'],[50,'50 条'],[rows.length,'全部']]) {const option=el('option','',text);option.value=value;select.append(option);}
      label.append(select);pager.append(info,label,previous,next);table.parentElement.after(pager);
      const update=()=>{const pages=Math.ceil(rows.length/size);current=Math.min(current,pages);rows.forEach((row,i)=>row.hidden=i<(current-1)*size||i>=current*size);previous.disabled=current===1;next.disabled=current===pages;info.textContent=`已加载 ${rows.length} 条 · 第 ${current} / ${pages} 页`;};
      previous.addEventListener('click',()=>{current--;update();});next.addEventListener('click',()=>{current++;update();});select.addEventListener('change',()=>{size=Number(select.value);current=1;update();});update();
    }
  });
  document.querySelectorAll('.alert').forEach(node=>node.setAttribute('role',node.classList.contains('bad')?'alert':'status'));
  // Keep native submitter name/value in the request: aria-busy instead of disabling controls.
  document.addEventListener('click',event=>{if(event.target.closest('button[aria-disabled="true"],a[aria-disabled="true"],input[type="submit"][aria-disabled="true"]')){event.preventDefault();event.stopImmediatePropagation();}},true);
  document.addEventListener('submit',event=>{
    const form=event.target;if(form.method.toLowerCase()!=='post'||event.defaultPrevented)return;
    if(form.dataset.uiSubmitting){event.preventDefault();return;}
    form.dataset.uiSubmitting='true';form.setAttribute('aria-busy','true');
    [...form.elements].filter(node=>node.type==='submit').forEach(node=>{node.setAttribute('aria-busy','true');node.setAttribute('aria-disabled','true');});
  });
  addEventListener('pageshow',()=>document.querySelectorAll('form[data-ui-submitting]').forEach(form=>{delete form.dataset.uiSubmitting;form.removeAttribute('aria-busy');[...form.elements].filter(node=>node.hasAttribute('aria-busy')).forEach(node=>{node.removeAttribute('aria-busy');node.removeAttribute('aria-disabled');});}));

  // Keep the existing site-wide default setting, separate from this browser's preference.
  document.querySelectorAll('[data-theme-form]').forEach(form=>{
    const saved = form.querySelector('input[name="site_theme"]:checked')?.value;
    form.addEventListener('change',event=>{if(event.target.name==='site_theme'){theme.set({theme:event.target.value},false);form.querySelector('.theme-preview-status').textContent='预览中，保存后作为新浏览器的默认主题。';}});
    form.addEventListener('reset',()=>{theme.set({theme:saved},false);form.querySelector('.theme-preview-status').textContent='已恢复站点默认主题预览。';});
  });

  // Existing announcement dialogs: focus containment, Escape and focus restoration.
  document.querySelectorAll('.announcement-backdrop,.modal-backdrop').forEach(overlay=>{
    const modal=overlay.querySelector('.announcement-modal,.bind-modal');if(!modal)return;
    const prior=document.activeElement;modal.setAttribute('role','dialog');modal.setAttribute('aria-modal','true');modal.tabIndex=-1;
    if(!modal.hasAttribute('aria-labelledby')) {const heading=modal.querySelector('h2');if(heading){heading.id=heading.id||'ui-modal-heading';modal.setAttribute('aria-labelledby',heading.id);}}
    const inertSiblings=[];
    for(let node=overlay;node && node!==document.body;node=node.parentElement) {
      [...node.parentElement.children].filter(sibling=>sibling!==node && !['SCRIPT','STYLE'].includes(sibling.tagName)).forEach(sibling=>{inertSiblings.push([sibling,sibling.inert]);sibling.inert=true;});
    }
    const activate=()=>{if(overlay.isConnected && !dialog.open)(focusables(modal)[0]||modal).focus();};
    activate();
    const keydown=event=>{if(!overlay.isConnected)return;if(dialog.open)return;trap(event,modal);if(event.key==='Escape'){const dismiss=overlay.querySelector('[data-announcement-close],button[onclick]');dismiss?.click();}};
    document.addEventListener('keydown',keydown);
    const observer=new MutationObserver(()=>{if(!overlay.isConnected){inertSiblings.forEach(([node,value])=>node.inert=value);document.removeEventListener('keydown',keydown);observer.disconnect();if(prior?.isConnected)prior.focus();}});observer.observe(overlay.parentNode,{childList:true});
  });
})();
