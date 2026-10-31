/* Loaded only on the payment page. Sequential polling with visible failure/recovery. */
(() => {
  const status = document.querySelector('[data-order]');
  if (!status) return;
  status.setAttribute('role','status');status.setAttribute('aria-live','polite');
  let timer, controller, stopped=false;
  const show = (text,state) => {status.textContent=text;status.className='payment-status order-state-'+state;};
  const poll = async () => {
    if (stopped) return;
    if (document.hidden) {timer=setTimeout(poll,3000);return;}
    controller=new AbortController();const timeout=setTimeout(()=>controller.abort(),10000);
    try {
      const response=await fetch('/payment_status.php?order='+encodeURIComponent(status.dataset.order),{credentials:'same-origin',signal:controller.signal,cache:'no-store'});
      if (!response.ok) throw Error('status');
      const data=await response.json();
      if(data.status==='paid') {stopped=true;show('支付成功，正在返回用户中心…','paid');timer=setTimeout(()=>location.href='/dashboard.php',1200);}
      else if(data.status==='closed'||data.status==='failed') {stopped=true;show(data.status==='closed'?'订单已关闭，请重新选择套餐。':'订单支付失败，请重新选择套餐。',data.status);}
      else if(data.status==='pending') show('等待支付，订单状态将自动更新…','pending');
      else throw Error('status');
    } catch (_) {if(!stopped)show('暂时无法获取支付状态，正在自动重试；请勿重复付款。','pending');}
    finally {clearTimeout(timeout);if(!stopped)timer=setTimeout(poll,3000);}
  };
  show('等待支付，订单状态将自动更新…','pending');timer=setTimeout(poll,3000);
  addEventListener('pagehide',()=>{stopped=true;clearTimeout(timer);controller?.abort();});
  addEventListener('pageshow',event=>{if(event.persisted){stopped=false;poll();}});
})();
