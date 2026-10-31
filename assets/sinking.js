(() => {
  'use strict';
  const linePicker = document.querySelector('[data-line-picker]');
  const lineForm = document.querySelector('#default-line-form');
  if (linePicker && lineForm) {
    linePicker.hidden = false; lineForm.hidden = true;
    linePicker.addEventListener('click', () => {
      lineForm.hidden = !lineForm.hidden;
      linePicker.setAttribute('aria-expanded', String(!lineForm.hidden));
      if (!lineForm.hidden) lineForm.querySelector('select').focus();
    });
  }
  // Progressive enhancement: forms still submit normally without JavaScript.
  document.querySelectorAll('.auth-shell input[type="password"]').forEach(input => {
    const wrap = document.createElement('span'); wrap.className = 'password-field';
    input.replaceWith(wrap); wrap.append(input);
    const toggle = document.createElement('button'); toggle.type = 'button'; toggle.className = 'password-toggle';
    toggle.textContent = '显示'; toggle.setAttribute('aria-label', '显示密码'); toggle.setAttribute('aria-pressed', 'false');
    toggle.addEventListener('click', () => {
      const visible = input.type === 'password'; input.type = visible ? 'text' : 'password';
      toggle.textContent = visible ? '隐藏' : '显示'; toggle.setAttribute('aria-label', visible ? '隐藏密码' : '显示密码'); toggle.setAttribute('aria-pressed', String(visible));
    }); wrap.append(toggle);
  });
  const dialog = document.querySelector('.ui-dialog');
  if (!dialog) return;
  const groups = [...dialog.querySelectorAll('.ui-palette-group')];
  const layout = document.createElement('div'); layout.className = 'ui-settings-layout';
  const nav = document.createElement('div'); nav.className = 'ui-settings-nav'; nav.setAttribute('role', 'tablist'); nav.setAttribute('aria-label', '外观设置分类');
  const panels = document.createElement('div'); panels.className = 'ui-settings-panels';
  const labels = ['整体主题', '品牌区域', '顶部导航', '侧边导航'];
  const select = index => {
    [...nav.children].forEach((tab, i) => { tab.setAttribute('aria-selected', String(i === index)); tab.tabIndex = i === index ? 0 : -1; groups[i].hidden = i !== index; });
  };
  groups.forEach((group, i) => {
    const tab = document.createElement('button'); tab.type = 'button'; tab.textContent = labels[i]; tab.id = 'appearance-tab-' + i; tab.setAttribute('role', 'tab'); tab.setAttribute('aria-controls', 'appearance-panel-' + i);
    group.id = 'appearance-panel-' + i; group.setAttribute('role', 'tabpanel'); group.setAttribute('aria-labelledby', tab.id);
    tab.addEventListener('click', () => select(i));
    tab.addEventListener('keydown', event => {
      let next;
      if (event.key === 'ArrowDown' || event.key === 'ArrowRight') next = (i + 1) % groups.length;
      if (event.key === 'ArrowUp' || event.key === 'ArrowLeft') next = (i + groups.length - 1) % groups.length;
      if (event.key === 'Home') next = 0;
      if (event.key === 'End') next = groups.length - 1;
      if (next !== undefined) {event.preventDefault(); select(next); nav.children[next].focus();}
    }); nav.append(tab); panels.append(group);
  });
  layout.append(nav, panels); dialog.querySelector('.ui-theme-footer').before(layout); select(0);
})();
