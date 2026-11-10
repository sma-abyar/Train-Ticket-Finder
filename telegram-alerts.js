(function () {
  'use strict';
  const digits = value => String(value).replace(/[۰-۹٠-٩]/g, char => {
    const code = char.charCodeAt(0);
    return String(code >= 1776 ? code - 1776 : code - 1632);
  });
  const canonical = (mode, selected, excluded = new Set()) => JSON.stringify([
    mode === 'selected' && selected.size === 0 ? 'none' : mode === 'except' && excluded.size === 0 ? 'all' : mode,
    [...selected].sort(), [...excluded].sort()
  ]);
  const matches = (item, query) => digits([item.number, item.wagon, item.company, item.time].join(' ')).includes(digits(query.trim()));
  if (typeof module !== 'undefined') module.exports = { canonical, matches, digits };
  if (typeof document === 'undefined') return;

  const tg = window.Telegram && window.Telegram.WebApp;
  const $ = id => document.getElementById(id);
  let data = null, mode = 'all', selected = new Set(), excluded = new Set(), saved = '', busy = false, dirty = false;
  const number = value => Number(value).toLocaleString('fa-IR');
  const tripId = Number(new URLSearchParams(window.location.search).get('trip_id'));

  function notice(text, type = '') {
    $('notice').textContent = text;
    $('notice').className = 'notice ' + type;
    $('notice').hidden = !text;
  }
  function setBusy(value) {
    busy = value;
    $('save').disabled = value;
    $('refresh').disabled = value;
  }
  function markDirty() {
    dirty = canonical(mode, selected, excluded) !== saved;
    $('dirty-state').textContent = dirty ? 'تغییرات ثبت نشده' : 'تنظیمات ذخیره‌شده';
    if (tg && typeof tg.enableClosingConfirmation === 'function') {
      dirty ? tg.enableClosingConfirmation() : tg.disableClosingConfirmation();
    }
  }
  function updateCounts() {
    const count = mode === 'all' ? 'همهٔ قطارها' : mode === 'except' ? 'همه به‌جز ' + number(excluded.size) + ' قطار'
      : mode === 'none' || selected.size === 0 ? 'هشدار خاموش' : number(selected.size) + ' قطار انتخابی';
    $('selected-count').textContent = mode === 'all' ? 'همه فعال‌اند' : mode === 'except' ? number(excluded.size) + ' قطار خاموش' : number(selected.size) + ' انتخاب';
    $('footer-count').textContent = count;
    $('except-mode').hidden = excluded.size === 0 && mode !== 'except';
    document.querySelectorAll('input[name=mode]').forEach(input => { input.checked = input.value === mode; });
    markDirty();
  }
  function render() {
    const list = $('trains');
    list.replaceChildren();
    const items = data.options.filter(item => matches(item, $('search').value));
    $('empty').hidden = items.length !== 0;
    $('total').textContent = number(data.options.length);
    items.forEach(item => {
      const card = document.createElement('label'); card.className = 'train-card';
      const check = document.createElement('input'); check.type = 'checkbox';
      check.checked = mode === 'all' || (mode === 'except' && !excluded.has(item.key)) || (mode === 'selected' && selected.has(item.key));
      check.setAttribute('aria-label', 'هشدار برای قطار ' + item.number + ' ساعت ' + item.time);
      check.addEventListener('change', () => {
        if (mode === 'all' || mode === 'except') {
          check.checked ? excluded.delete(item.key) : excluded.add(item.key);
          mode = excluded.size ? 'except' : 'all';
        } else {
          check.checked ? selected.add(item.key) : selected.delete(item.key);
          mode = 'selected';
        }
        updateCounts(); notice('');
      });
      const body = document.createElement('div'); body.className = 'train-body';
      const top = document.createElement('div'); top.className = 'card-top';
      const time = document.createElement('span'); time.className = 'time'; time.textContent = item.time || '—';
      const status = document.createElement('span'); status.className = 'status' + (!item.missing && item.capacity > 0 ? ' available' : '');
      status.textContent = item.missing ? 'فعلاً در فهرست نیست' : item.capacity > 0 ? number(item.capacity) + ' صندلی' : 'ناموجود';
      top.append(time, status);
      const name = document.createElement('div'); name.className = 'train-name'; name.textContent = item.wagon || 'قطار ' + item.number;
      const meta = document.createElement('div'); meta.className = 'train-meta'; meta.textContent = [item.company, 'شمارهٔ ' + item.number].filter(Boolean).join(' · ');
      body.append(top, name, meta);
      if (item.price !== null && Number.isFinite(Number(item.price))) {
        const price = document.createElement('div'); price.className = 'price';
        const amount = document.createElement('strong'); amount.textContent = number(item.price);
        price.append(amount, document.createTextNode(' تومان / نفر')); body.append(price);
      }
      card.append(check, body); list.append(card);
    });
    updateCounts();
  }
  async function request(action, extra = {}) {
    const endpoint = new URL('telegram-alerts.php?api=1', window.location.href);
    const response = await fetch(endpoint, {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, cache: 'no-store', credentials: 'same-origin',
      body: JSON.stringify({ action, trip_id: tripId, init_data: tg.initData, ...extra })
    });
    const result = await response.json();
    if (!response.ok || !result.ok) {
      const error = new Error(result.error || 'ارتباط برقرار نشد؛ دوباره تلاش کنید.'); error.status = response.status; throw error;
    }
    return result.data;
  }
  function setData(next, keepDraft = false) {
    data = next;
    if (!keepDraft) {
      mode = data.mode; selected = new Set(data.selected); excluded = new Set(data.excluded || []); saved = canonical(mode, selected, excluded);
    }
    const date = document.createElement('bdi'); date.dir = 'ltr';
    date.textContent = digits(data.date).replace(/\d/g, digit => '۰۱۲۳۴۵۶۷۸۹'[digit]);
    const route = document.createElement('span'); route.textContent = data.route_title || data.route;
    $('trip-summary').replaceChildren(route, document.createTextNode(' · '), date);
    $('updated').textContent = data.updated_at ? 'آخرین بررسی: ' + new Date(data.updated_at * 1000).toLocaleString('fa-IR') : 'فهرست هنوز آماده نیست؛ کمی بعد تازه کنید.';
    $('controls').hidden = false; $('footer').hidden = false;
    render();
  }
  document.querySelectorAll('input[name=mode]').forEach(input => input.addEventListener('change', () => {
    mode = input.value;
    if (mode === 'all') excluded.clear();
    render(); notice('');
  }));
  $('search').addEventListener('input', () => data && render());
  $('select-all').addEventListener('click', () => {
    data.options.filter(item => matches(item, $('search').value)).forEach(item => selected.add(item.key));
    mode = 'selected'; render(); notice('');
  });
  $('clear').addEventListener('click', () => { selected.clear(); excluded.clear(); mode = 'none'; render(); notice(''); });
  $('refresh').addEventListener('click', async () => {
    if (busy) return;
    setBusy(true);
    try {
      const next = await request('refresh');
      if (next.revision !== data.revision) {
        notice('تنظیمات در جای دیگری تغییر کرده است. برای ادامه صفحه را دوباره از ربات باز کنید.', 'error');
      } else {
        setData(next, true);
        notice(next.stale ? 'آخرین فهرست در دسترس نمایش داده می‌شود؛ وضعیت ظرفیت ممکن است تغییر کرده باشد.' : 'فهرست تازه شد؛ انتخاب‌هایتان حفظ شد.');
      }
    } catch (error) { notice(error.message, 'error'); }
    finally { setBusy(false); }
  });
  $('save').addEventListener('click', async () => {
    if (busy || !data) return;
    setBusy(true);
    try {
      const next = await request('save', { mode, keys: [...selected], excluded_keys: [...excluded], revision: data.revision });
      setData(next); notice('انتخاب‌ها ثبت شد. از بررسی بعدی مطابق همین تنظیمات خبرت می‌کنیم.', 'success');
      if (tg.HapticFeedback) tg.HapticFeedback.notificationOccurred('success');
    } catch (error) { notice(error.message, 'error'); }
    finally { setBusy(false); }
  });
  window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
  if (!tg || !tg.initData || !Number.isSafeInteger(tripId) || tripId < 1) {
    notice('این صفحه را از دکمهٔ «قطارهای هشدار» در ربات تلگرام باز کنید.', 'error');
    $('trip-summary').textContent = 'برای نمایش سفر، ورود از تلگرام لازم است.';
    return;
  }
  const applyTheme = () => { document.documentElement.style.colorScheme = tg.colorScheme === 'dark' ? 'dark' : 'light'; };
  applyTheme();
  if (typeof tg.onEvent === 'function') tg.onEvent('themeChanged', applyTheme);
  tg.ready(); tg.expand();
  setBusy(true);
  request('load').then(next => {
    setData(next);
    if (next.stale) notice('آخرین فهرست در دسترس نمایش داده می‌شود؛ می‌توانید آن را تازه کنید.');
  }).catch(error => notice(error.message, 'error')).finally(() => setBusy(false));
})();
