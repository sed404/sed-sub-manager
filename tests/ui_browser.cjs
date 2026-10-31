/* UI acceptance against CLI-rendered presentation fixtures. No production DB or API. */
const fs=require('fs'),path=require('path'),http=require('http'),assert=require('assert/strict');
const {chromium}=require('playwright');
const root=path.resolve(__dirname,'..'),fixtures=process.argv[2],out=process.argv[3]||fixtures;
if(!fixtures)throw Error('Usage: node tests/ui_browser.cjs FIXTURES OUTPUT');
const types={'.css':'text/css','.js':'text/javascript','.html':'text/html','.svg':'image/svg+xml','.png':'image/png','.jpg':'image/jpeg','.webp':'image/webp'};
const server=http.createServer((req,res)=>{
 const url=new URL(req.url,'http://127.0.0.1'),name=decodeURIComponent(url.pathname);
 const base=name.startsWith('/assets/')||name.startsWith('/tutorials/')?root:fixtures;
 const relative=name==='/dashboard.php'?'user-'+(['home','renew','docs','tickets','profile'].includes(url.searchParams.get('tab'))?url.searchParams.get('tab'):'home')+'.html':name==='/'?'admin-cards.html':name.replace(/^\//,'');
 const target=path.resolve(base,relative.endsWith('/')?relative+'index.html':relative);
 if(!target.startsWith(path.resolve(base)+path.sep)){res.writeHead(403);return res.end();}
 if(req.method==='POST'){res.writeHead(200,{'Content-Type':'text/html'});return res.end('fixture submission');}
 try {const data=fs.readFileSync(target);res.writeHead(200,{'Content-Type':types[path.extname(target)]||'text/plain'});res.end(data);}catch(_){res.writeHead(404);res.end('Not found');}
});
(async()=>{
 await new Promise(r=>server.listen(8765,'127.0.0.1',r));
 const browser=await chromium.launch({headless:true,executablePath:process.env.UI_CHROMIUM || undefined});
 const page=await browser.newPage({viewport:{width:1440,height:1000}}),errors=[];
 await page.route('https://**/*',route=>route.fulfill({status:200,contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="240" height="240"><rect width="240" height="240" fill="white"/></svg>'}));
 page.on('pageerror',e=>errors.push(e.message));page.on('console',msg=>{if(msg.type()==='error')errors.push(msg.text());});
 const report={pages:[],checks:[],errors};
 const go=async name=>{await page.goto('http://127.0.0.1:8765/'+name);await page.waitForFunction(()=>!!window.SedTheme && !!document.querySelector('.ui-dialog'));};
 try {
  const filter=process.env.UI_PAGE_FILTER?.split(',');
  const pages=fs.readdirSync(fixtures).filter(n=>n.endsWith('.html')&&(!filter||filter.includes(n)));
  for(const width of [1440,1024,768,390,320]) {
   await page.setViewportSize({width,height:1000});
   for(const name of pages) {
    await go(name);
    const dimensions=await page.evaluate(()=>({viewport:innerWidth,scroll:document.documentElement.scrollWidth,body:document.body.scrollWidth}));
    assert(dimensions.scroll<=width+1,`Overflow ${name} ${width}: ${JSON.stringify(dimensions)}`);
    report.pages.push({name,width,overflow:false});
   }
  }
  report.checks.push('All rendered business pages: no document overflow at five widths');
  if(process.env.UI_LAYOUT_ONLY) {
    await page.setViewportSize({width:1440,height:1000});await go('pay.html');await page.screenshot({path:path.join(out,'payment-desktop.png'),fullPage:true});
    const card=await page.locator('.plan-card').first().boundingBox();assert(card.width<450,'A single plan must not become a full-width giant card');
    report.checks=['Selected payment and renewal layouts at five widths; single-plan compact card'];
    fs.writeFileSync(path.join(out,'layout-report.json'),JSON.stringify(report,null,2));console.log(JSON.stringify({pageChecks:report.pages.length,checks:report.checks}));return;
  }
  await page.setViewportSize({width:1440,height:1000});await go('admin-cards.html');
  await page.screenshot({path:path.join(out,'admin-desktop.png'),fullPage:true});
  await page.locator('[data-ui-toggle]').click();assert(await page.locator('html').evaluate(n=>n.classList.contains('ui-collapsed')));
  await page.reload();assert(await page.locator('html').evaluate(n=>n.classList.contains('ui-collapsed')));
  assert.equal(await page.locator('.ui-navigation details').count(),0);
  assert.equal(await page.locator('.ui-navigation>a').count(),11);
  await page.locator('[data-ui-toggle]').click();assert(!await page.locator('html').evaluate(n=>n.classList.contains('ui-collapsed')));
  await go('user-home.html');assert.equal(await page.locator('.ui-navigation>a').count(),5);assert.equal(await page.locator('.ui-navigation details').count(),0);
  for(const link of await page.locator('.ui-navigation>a').all())assert(await link.isVisible());
  await page.screenshot({path:path.join(out,'user-desktop.png'),fullPage:true});
  await page.getByRole('link',{name:'使用教程',exact:true}).click();await page.waitForURL('**/dashboard.php?tab=docs');assert.equal(await page.locator('.ui-navigation>a').count(),5);assert.equal(await page.locator('.ui-nav-link[aria-current="page"]').textContent(),'使用教程');assert.equal(await page.locator('iframe').count(),0);assert(await page.locator('#clash-verge').count());await page.screenshot({path:path.join(out,'tutorial-desktop.png')});await page.locator('.tutorial-toc a').first().click();assert(await page.locator('#clash-verge').evaluate(n=>n.getBoundingClientRect().top>=68));await go('tutorials/');assert(page.url().includes('/dashboard.php?tab=docs'));report.checks.push('Tutorial navigation and legacy redirect preserve the shared five-item shell; anchors clear fixed header');
  await go('login.html');await page.screenshot({path:path.join(out,'login-desktop.png'),fullPage:true});
  await page.getByLabel('显示密码', {exact:true}).click();assert.equal(await page.locator('input[name="password"]').getAttribute('type'),'text');
  await page.getByLabel('隐藏密码', {exact:true}).click();assert.equal(await page.locator('input[name="password"]').getAttribute('type'),'password');
  assert.equal(await page.locator('form').getAttribute('method'),'post');assert.equal(await page.locator('input[name="csrf"]').inputValue(),'fixture-csrf-not-a-session');
  await go('landing.html');await page.screenshot({path:path.join(out,'landing-desktop.png'),fullPage:true});
  assert.equal(await page.getByRole('link',{name:'开始体验'}).getAttribute('href'),'/login.php');
  await page.setViewportSize({width:1920,height:1080});
  assert.equal(Math.round((await page.locator('.landing-hero').boundingBox()).width),1920);
  await page.screenshot({path:path.join(out,'landing-wide.png')});
  await page.setViewportSize({width:1440,height:1000});
  await page.getByRole('link',{name:'探索产品'}).click();await page.waitForURL('**#capabilities');
  await page.setViewportSize({width:390,height:844});await go('landing.html');await page.screenshot({path:path.join(out,'landing-mobile.png'),fullPage:true});
  await go('login.html');await page.screenshot({path:path.join(out,'login-mobile.png'),fullPage:true});
  await page.setViewportSize({width:1440,height:1000});
  report.checks.push('Landing product navigation, responsive public pages, password visibility and unchanged POST/CSRF fields');
  await go('user-paid.html');await page.getByRole('button',{name:'切换订阅',exact:true}).click();
  assert(await page.locator('#default-line-form').isVisible());
  assert.equal(await page.locator('#default-line-form input[name="action"]').inputValue(),'set_default_line');
  await page.setViewportSize({width:390,height:844});await page.screenshot({path:path.join(out,'subscription-mobile.png'),fullPage:true});
  assert((await page.locator('.dashboard-subscription-hero').boundingBox()).height<240);
  await page.setViewportSize({width:1440,height:1000});
  await go('pay.html');await page.screenshot({path:path.join(out,'payment-desktop.png'),fullPage:true});
  await go('admin-cards.html');
  report.checks.push('Flat navigation: all 5 user and 11 admin links preserved; desktop collapse and persistence');
  await page.locator('[data-theme-open]').click();
  assert(await page.locator('.ui-dialog').evaluate(n=>n.open));
  await page.locator('input[name="ui-theme"][value="dark"]').check();
  await page.getByRole('tab',{name:'品牌区域',exact:true}).click();
  await page.locator('input[name="ui-logo"][value="color_3"]').check();
  await page.keyboard.press('Escape');assert(await page.locator('[data-theme-open]').evaluate(n=>n===document.activeElement));
  await go('user-home.html');assert.equal(await page.locator('html').getAttribute('data-ui-theme'),'dark');assert.equal(await page.locator('html').getAttribute('data-ui-logo'),'color_3');
  await page.screenshot({path:path.join(out,'user-dark.png'),fullPage:true});
  report.checks.push('Theme interaction, Escape focus return and cross-page persistence');
  await page.setViewportSize({width:390,height:844});await go('admin-cards.html');
  assert(await page.locator('.ui-sidebar').evaluate(n=>n.inert));await page.locator('[data-ui-toggle]').click();
  await page.waitForFunction(()=>document.querySelector('.ui-sidebar').contains(document.activeElement));
  assert(await page.locator('.ui-content').evaluate(n=>n.inert));
  assert.equal(await page.getByRole('button',{name:'关闭导航'}).count(),0);
  assert.equal(await page.locator('.ui-sidebar').evaluate(n=>getComputedStyle(n).backgroundColor.startsWith('rgba')),false);
  for(let i=0;i<25;i++){await page.keyboard.press('Tab');assert(await page.locator('.ui-sidebar').evaluate(n=>n.contains(document.activeElement)));}
  await page.screenshot({path:path.join(out,'admin-mobile-drawer.png')});
  await page.keyboard.press('Escape');assert(await page.locator('[data-ui-toggle]').evaluate(n=>n===document.activeElement));
  await page.locator('[data-ui-toggle]').click();await page.locator('.ui-backdrop').click({position:{x:370,y:700}});assert(!await page.locator('body').evaluate(n=>n.classList.contains('ui-drawer-open')));
  report.checks.push('Mobile drawer: inert background, Tab trap, Escape, backdrop and focus return');
  await go('user-home.html');await page.screenshot({path:path.join(out,'user-mobile-dark.png'),fullPage:true});await page.evaluate(()=>SedTheme.reset());await page.screenshot({path:path.join(out,'user-mobile.png'),fullPage:true});
  await page.setViewportSize({width:1440,height:1000});await go('admin-cards.html');
  await page.evaluate(()=>SedTheme.reset());
  const b=page.locator('button.primary').first(),before=await b.boundingBox();await b.hover();assert.deepEqual(await b.boundingBox(),before);
  assert.equal(await b.evaluate(n=>getComputedStyle(n).backgroundImage),'none');
  await b.focus();assert.notEqual(await b.evaluate(n=>getComputedStyle(n).outlineStyle),'none');
  await b.evaluate(n=>n.disabled=true);assert.equal(await b.evaluate(n=>getComputedStyle(n).backgroundImage),'none');
  await page.emulateMedia({reducedMotion:'reduce'});assert.equal(await b.evaluate(n=>getComputedStyle(n).transitionDuration),'0s');
  const input=page.locator('input:not([type="hidden"])').first();await input.focus();assert.equal(await input.evaluate(n=>getComputedStyle(n).backgroundImage),'none');assert.notEqual(await input.evaluate(n=>getComputedStyle(n).outlineStyle),'none');report.checks.push('Plain buttons and inputs, stable geometry, focus ring, disabled state, reduced motion');
  await go('admin-payment.html');
  const form=await page.evaluate(()=>Object.fromEntries(new FormData(document.querySelector('#plan-1'))));
  for(const key of ['csrf','action','id','name','price','duration_days','status'])assert(key in form,`missing ${key}`);
  assert.equal(await page.locator('textarea[name="alipay_private_key"]').inputValue(),'');
  report.checks.push('Editable plan form owns every field; stored private key is not rendered');
  await go('admin-paged.html');
  assert.equal(await page.locator('tbody tr:visible').count(),20);await page.getByRole('button',{name:'下一页',exact:true}).click();assert.equal(await page.locator('tbody tr:visible').count(),5);
  await page.getByLabel('每页').selectOption('25');assert.equal(await page.locator('tbody tr:visible').count(),25);
  const submission=await page.evaluate(()=>{const f=document.querySelector('form[method="post"]');const first=f.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})),second=f.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));return{first,second,busy:f.getAttribute('aria-busy'),count:new FormData(f).get('count')};});
  assert.deepEqual(submission,{first:true,second:false,busy:'true',count:'10'});
  report.checks.push('Actual loaded-row pagination and duplicate-submit prevention preserve fields');
  await go('user-announcement.html');assert(await page.locator('.announcement-modal').evaluate(n=>n.contains(document.activeElement)));assert(await page.locator('.ui-topbar').evaluate(n=>n.inert));
  for(let i=0;i<6;i++){await page.keyboard.press('Tab');assert(await page.locator('.announcement-modal').evaluate(n=>n.contains(document.activeElement)));}
  await page.keyboard.press('Escape');assert.equal(await page.locator('.announcement-modal').count(),0);assert(!await page.locator('.ui-topbar').evaluate(n=>n.inert));
  report.checks.push('Announcement modal keyboard containment, dismissal and background restoration');
  let paymentCalls=0;await page.route('**/payment_status.php?*',route=>{paymentCalls++;return route.fulfill({status:paymentCalls===1?503:200,contentType:'application/json',body:JSON.stringify({status:'closed'})});});
  await go('pay-pending.html');await page.waitForFunction(()=>document.querySelector('[data-order]').textContent.includes('正在自动重试'));await page.waitForFunction(()=>document.querySelector('[data-order]').textContent.includes('订单已关闭'));
  assert.equal(paymentCalls,2);await page.unroute('**/payment_status.php?*');
  report.checks.push('Payment polling failure feedback and recovery to terminal closed state (mock responses)');
  await go('admin-cards.html');
  const combinations=await page.evaluate(()=>{
   const themes=SedTheme.themes.map(t=>t[0]),regions=SedTheme.regions.map(t=>t[0]),html=document.documentElement;
   const rgb=s=>s.match(/[\d.]+/g).slice(0,3).map(Number),lum=c=>c.map(v=>{v/=255;return v<=.04045?v/12.92:((v+.055)/1.055)**2.4}).reduce((s,v,i)=>s+v*[.2126,.7152,.0722][i],0);
   const ratio=(a,b)=>{const x=lum(rgb(a)),y=lum(rgb(b));return(Math.max(x,y)+.05)/(Math.min(x,y)+.05);};
   let count=0,min=100;
   for(const theme of themes){html.dataset.uiTheme=theme;for(const logo of regions){html.dataset.uiLogo=logo;for(const header of regions){html.dataset.uiHeader=header;for(const sidebar of regions){html.dataset.uiSidebar=sidebar;count++;
     for(const selector of ['.ui-brand','.ui-topbar','.ui-sidebar']){const c=getComputedStyle(document.querySelector(selector)),r=ratio(c.color,c.backgroundColor);if(r<4.5)throw Error(`Contrast ${theme}/${logo}/${header}/${sidebar} ${selector}: ${r}`);min=Math.min(min,r);}
   }}}}
   SedTheme.reset();return{count,minRegionalContrast:min};
  });report.combinations=combinations;
  for(const [value] of await page.evaluate(()=>SedTheme.themes)) {
   await page.evaluate(value=>SedTheme.set({theme:value}),value);await page.screenshot({path:path.join(out,'theme-'+value+'.png')});
  }
  await page.locator('[data-theme-open]').click();await page.screenshot({path:path.join(out,'theme-panel.png')});await page.keyboard.press('Escape');
  await page.locator('[data-theme-open]').click();await page.getByRole('button',{name:'恢复默认',exact:true}).click();assert.equal(await page.locator('html').getAttribute('data-ui-theme'),'default');await page.keyboard.press('Escape');
  await page.evaluate(()=>localStorage.setItem('sed.subscription.ui.v1','{invalid'));await page.reload();assert.equal(await page.locator('html').getAttribute('data-ui-theme'),'default');
  await page.evaluate(()=>localStorage.setItem('sed.subscription.ui.v1',JSON.stringify({theme:'invalid',logo:'bad'})));await page.reload();assert.equal(await page.locator('html').getAttribute('data-ui-logo'),'default');
  const blocked=await browser.newPage();await blocked.addInitScript(()=>{Object.defineProperty(window,'localStorage',{get(){throw Error('blocked')}});});await blocked.goto('http://127.0.0.1:8765/login.html');await blocked.waitForSelector('[data-theme-open]');assert.equal(await blocked.locator('html').getAttribute('data-ui-theme'),'default');await blocked.locator('[data-theme-open]').click();await blocked.locator('input[name="ui-theme"][value="dark"]').check();assert((await blocked.locator('.ui-theme-status').textContent()).includes('不允许保存'));await blocked.close();
  report.checks.push('4096 regional combinations AA contrast; 8 theme screenshots; reset, invalid and denied storage');
  // Chromium logs the deliberately simulated 503 used by the recovery check.
  const expected503='Failed to load resource: the server responded with a status of 503 (Service Unavailable)';
  report.expectedConsoleErrors=errors.filter(message=>message===expected503);
  assert.equal(report.expectedConsoleErrors.length,1,'The payment failure simulation must emit one HTTP 503');
  report.errors=errors.filter(message=>message!==expected503);
  assert.equal(report.errors.length,0,report.errors.join('\n'));
  fs.writeFileSync(path.join(out,'ui-report.json'),JSON.stringify(report,null,2));console.log(JSON.stringify({pageChecks:report.pages.length,checks:report.checks,combinations,errors:report.errors,expectedConsoleErrors:report.expectedConsoleErrors},null,2));
 } finally {await browser.close();server.close();}
})().catch(e=>{console.error(e);server.close();process.exitCode=1;});
