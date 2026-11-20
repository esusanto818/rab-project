/* Reusable App Engine Core */
const $ = (id) => document.getElementById(id);

// Universal API Fetcher
async function apiCall(endpoint, task, payload = {}) {
  try {
    const formData = new FormData();
    formData.append('task', task);
    for (const key in payload) {
      if (payload[key] !== null && payload[key] !== undefined) {
        formData.append(key, typeof payload[key] === 'object' ? JSON.stringify(payload[key]) : payload[key]);
      }
    }
    const response = await fetch(endpoint, { method: 'POST', body: formData });
    return await response.json();
  } catch (e) {
    return { success: false, message: 'Gagal terhubung ke server.' };
  }
}

// Universal Modal Controller
const UIModal = {
  show(modalId) { $(modalId)?.classList.add('show'); },
  hide(modalId) { $(modalId)?.classList.remove('show'); },
  showErr(boxId, msg) {
    const box = $(boxId);
    if (box) { box.textContent = msg; box.style.display = 'block'; }
  },
  hideErr(boxId) {
    const box = $(boxId);
    if (box) { box.style.display = 'none'; }
  }
};

// Formatter Utilitas
const UIFormat = {
  currency(val) {
    return Number(val || 0).toLocaleString('id-ID', { minimumFractionDigits: 2 });
  }
};
// Global ExtJS Grid Cell Word-Wrap Injection
if (typeof Ext !== 'undefined' && Ext.util && Ext.util.CSS) {
    Ext.util.CSS.createStyleSheet('.x-grid3-cell-inner { white-space: normal !important; word-wrap: break-word !important; overflow-wrap: break-word !important; line-height: 1.35 !important; }', 'grid-wrap-fix');
}
