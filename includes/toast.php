<?php
// includes/toast.php — Toast notification system
// Include in any dashboard page with: require_once '../../includes/toast.php';
?>
<div id="toast-container" style="position:fixed;bottom:1.5rem;right:1.5rem;z-index:9999;display:flex;flex-direction:column;gap:.6rem;pointer-events:none"></div>
<style>
.toast{display:flex;align-items:center;gap:.8rem;padding:.85rem 1.2rem;border-radius:2px;font-family:'DM Sans',sans-serif;font-size:.83rem;font-weight:500;min-width:260px;max-width:380px;pointer-events:all;animation:toastIn .3s ease;box-shadow:0 8px 32px rgba(0,0,0,.4);border-left:3px solid}
.toast-success{background:#0c1018;color:#4caf82;border-color:#4caf82}
.toast-error{background:#0c1018;color:#e05c5c;border-color:#e05c5c}
.toast-warning{background:#0c1018;color:#e0a050;border-color:#e0a050}
.toast-info{background:#0c1018;color:#4a6fa5;border-color:#4a6fa5}
.toast-icon{font-size:1rem;flex-shrink:0}
.toast-msg{flex:1}
.toast-close{background:none;border:none;color:inherit;opacity:.6;cursor:pointer;font-size:1rem;padding:0;line-height:1}
.toast-close:hover{opacity:1}
@keyframes toastIn{from{opacity:0;transform:translateX(20px)}to{opacity:1;transform:translateX(0)}}
@keyframes toastOut{from{opacity:1}to{opacity:0;transform:translateX(20px)}}
</style>
<script>
function toast(type, message, duration) {
  duration = duration || 3500;
  const icons = { success:'✓', error:'✗', warning:'⚠', info:'ℹ' };
  const container = document.getElementById('toast-container');
  if (!container) return;
  const el = document.createElement('div');
  el.className = 'toast toast-' + type;
  el.innerHTML = '<span class="toast-icon">' + (icons[type]||'•') + '</span>' +
    '<span class="toast-msg">' + message + '</span>' +
    '<button class="toast-close" onclick="this.parentElement.remove()">✕</button>';
  container.appendChild(el);
  setTimeout(() => {
    el.style.animation = 'toastOut .3s ease forwards';
    setTimeout(() => el.remove(), 300);
  }, duration);
}
function toastConfirm(message, onConfirm) {
  const container = document.getElementById('toast-container');
  if (!container) { if (confirm(message)) onConfirm(); return; }
  const el = document.createElement('div');
  el.className = 'toast toast-warning';
  el.style.cssText += ';flex-direction:column;align-items:flex-start;gap:.6rem;max-width:320px';
  el.innerHTML = '<div style="display:flex;align-items:center;gap:.6rem"><span class="toast-icon">⚠</span><span>' + message + '</span></div>' +
    '<div style="display:flex;gap:.5rem;width:100%;justify-content:flex-end">' +
    '<button onclick="this.closest(\'.toast\').remove()" style="background:transparent;border:1px solid rgba(255,255,255,.1);color:var(--muted);padding:.3rem .8rem;border-radius:2px;cursor:pointer;font-size:.78rem">Cancel</button>' +
    '<button id="confirm-yes" style="background:rgba(224,92,92,.15);border:1px solid rgba(224,92,92,.3);color:#e05c5c;padding:.3rem .8rem;border-radius:2px;cursor:pointer;font-size:.78rem">Confirm</button>' +
    '</div>';
  container.appendChild(el);
  el.querySelector('#confirm-yes').onclick = () => { el.remove(); onConfirm(); };
}
</script>
