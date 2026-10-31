document.querySelectorAll('.node-override-form').forEach((form) => {
  form.addEventListener('submit', (event) => {
    const payload = {};
    form.querySelectorAll('input[name^="custom_name["]').forEach((input) => {
      const key = input.name.slice(12, -1);
      payload[key] = {
        name: input.value,
        enabled: form.elements.namedItem('node_enabled[' + key + ']').checked,
        sort: form.elements.namedItem('node_sort_order[' + key + ']').value
      };
    });
    if (!Object.keys(payload).length) { event.preventDefault(); return; }
    form.elements.namedItem('node_payload').value = JSON.stringify(payload);
    // A single payload avoids PHP max_input_vars truncating large node lists.
    form.querySelectorAll('input[name^="custom_name["],input[name^="node_enabled["],input[name^="node_sort_order["]').forEach((input) => { input.disabled = true; });
  });
});

document.querySelectorAll('[data-copy]').forEach((button) => {
  button.addEventListener('click', async () => {
    const text = button.getAttribute('data-copy') || '';
    try {
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(text);
      } else {
        const input = document.createElement('textarea');
        input.value = text;
        input.setAttribute('readonly', '');
        input.style.position = 'fixed';
        input.style.opacity = '0';
        document.body.appendChild(input);
        input.select();
        const copied = document.execCommand('copy');
        input.remove();
        if (!copied) throw new Error('copy failed');
      }
      document.querySelectorAll('.copy-toast').forEach((item) => item.remove());
      const toast = document.createElement('div');
      toast.className = 'copy-toast';
      toast.textContent = '已复制';
      document.body.appendChild(toast);
      requestAnimationFrame(() => toast.classList.add('show'));
      setTimeout(() => toast.remove(), 1500);
    } catch (error) {
      const toast = document.createElement('div');
      toast.className = 'copy-toast';
      toast.textContent = '复制失败，请长按链接复制';
      document.body.appendChild(toast);
      requestAnimationFrame(() => toast.classList.add('show'));
      setTimeout(() => toast.remove(), 2200);
    }
  });
});

document.querySelectorAll('[data-avatar-input]').forEach((input) => {
  const avatar = document.querySelector('[data-qq-avatar]');
  if (!avatar) return;

  const updateAvatar = () => {
    const qq = input.value.trim();
    if (/^[1-9]\d{4,19}$/.test(qq) && !/^1[3-9]\d{9}$/.test(qq)) {
      avatar.src = `https://q1.qlogo.cn/g?b=qq&nk=${encodeURIComponent(qq)}&s=100`;
    }
  };

  input.addEventListener('input', updateAvatar);
  updateAvatar();
});

const announcement = document.querySelector('[data-announcement]');
const announcementCloseButtons = document.querySelectorAll('[data-announcement-close]');

if (announcement) {
  const closeAnnouncement = () => announcement.remove();

  announcementCloseButtons.forEach((button) => {
    button.addEventListener('click', closeAnnouncement);
  });
  announcement.addEventListener('click', (event) => {
    if (event.target === announcement) closeAnnouncement();
  });
}
