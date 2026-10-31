/* Runs synchronously in <head>, before styles and first paint. Preferences only. */
(() => {
  'use strict';
  const key = 'sed.subscription.ui.v1';
  const themes = [ ['default','默认浅色'], ['dark','深色'], ['translucent-green','半透明绿色'], ['translucent-blue','半透明蓝色'], ['translucent-yellow','半透明黄色'], ['translucent-red','半透明红色'], ['translucent-pink','半透明粉色'], ['translucent-cyan','半透明青色'] ];
  // Reference V5 color_2 through color_8, checked against its published CSS.
  const regions = [['default','默认'],['color_2','绿色'],['color_3','蓝色'],['color_4','橙黄'],['color_5','红色'],['color_6','紫色'],['color_7','青绿'],['color_8','石墨']];
  const aliases = { ocean:'default', mono:'default', mint:'translucent-green', violet:'translucent-pink', amber:'translucent-yellow' };
  let server = document.querySelector('meta[name="sed-default-theme"]')?.content || 'default';
  server = aliases[server] || server;
  if (!themes.some(t => t[0] === server)) server = 'default';
  const defaults = {theme:'default',logo:'default',header:'default',sidebar:'default',collapsed:false};
  const clean = raw => {
    const result = {...defaults};
    if (!raw || typeof raw !== 'object' || Array.isArray(raw)) return result;
    for (const field of ['theme','logo','header','sidebar']) {
      if ((field === 'theme' ? themes : regions).some(t => t[0] === raw[field])) result[field] = raw[field];
    }
    result.collapsed = raw.collapsed === true;
    return result;
  };
  const read = () => {
    try { const saved = localStorage.getItem(key); return saved === null ? {...defaults,theme:server} : clean(JSON.parse(saved)); }
    catch (_) { return {...defaults}; }
  };
  let state = read();
  const apply = () => {
    for (const field of ['theme','logo','header','sidebar']) document.documentElement.setAttribute('data-ui-' + field, state[field]);
    document.documentElement.classList.toggle('ui-collapsed', state.collapsed);
  };
  const set = (patch, persist = true) => {
    state = clean({...state,...patch}); apply();
    let saved = true;
    if (persist) try { localStorage.setItem(key, JSON.stringify(state)); } catch (_) { saved = false; }
    document.dispatchEvent(new CustomEvent('sed:theme', {detail:{...state,saved}}));
    return saved;
  };
  apply();
  window.SedTheme = {themes,regions,get:()=>({...state}),set,reset:()=>set(defaults)};
  addEventListener('storage', event => { if (event.key === key || event.key === null) {state = read(); apply(); document.dispatchEvent(new CustomEvent('sed:theme',{detail:{...state,saved:true}}));} });
})();
