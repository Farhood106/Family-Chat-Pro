// assets/app.js
// Vanilla JS chat client (polling). No websockets / no dependencies.

(function(){
  // Recent chats (store up to 4) - used for 'Continue conversation' cards on index.
  function fc_storeRecentChat(obj){
    if (!obj || !obj.url || !obj.title) return;
    const item = { type: obj.type || 'direct', title: String(obj.title).trim(), url: String(obj.url), ts: Date.now() };
    try{
      const raw = localStorage.getItem('fc_recent_chats_v1') || '';
      let arr = [];
      if (raw){
        const parsed = JSON.parse(raw);
        if (Array.isArray(parsed)) arr = parsed;
      }
      arr = arr.filter(x => x && x.url && x.url !== item.url);
      arr.unshift(item);
      arr = arr.slice(0,4);
      localStorage.setItem('fc_recent_chats_v1', JSON.stringify(arr));
      // keep legacy single item for backward compatibility
      localStorage.setItem('fc_last_chat_v1', JSON.stringify({ type: item.type, title: item.title, url: item.url }));
    }catch(e){}
  }
  function fc_readRecentChats(){
    try{
      const raw = localStorage.getItem('fc_recent_chats_v1') || '';
      if (raw){
        const arr = JSON.parse(raw);
        if (Array.isArray(arr)) return arr;
      }
    }catch(e){}
    // migrate legacy single item if exists
    try{
      const raw1 = localStorage.getItem('fc_last_chat_v1') || '';
      if (!raw1) return [];
      const obj = JSON.parse(raw1);
      if (obj && obj.url && obj.title) return [{ type: obj.type || 'direct', title: obj.title, url: obj.url, ts: Date.now() }];
    }catch(e){}
    return [];
  }
  window.fc_storeRecentChat = fc_storeRecentChat;
  window.fc_readRecentChats = fc_readRecentChats;

  // Initialize the mobile drawer sidebar on any page that has .app layout.
  const appRoot = document.querySelector('.app');
  if (appRoot) {
    (function initDrawer(root){
      const openBtns = root.querySelectorAll('[data-open-sidebar]');
      const closeBtns = root.querySelectorAll('[data-close-sidebar]');
      const setOpen = (v)=>{
        if (v) root.classList.add('sidebar-open');
        else root.classList.remove('sidebar-open');
      };
      openBtns.forEach(b=>b.addEventListener('click', ()=>setOpen(true)));
      closeBtns.forEach(b=>b.addEventListener('click', ()=>setOpen(false)));
      // Close drawer when a sidebar item is selected.
      root.addEventListener('click', (e)=>{
        const a = e.target.closest('a[data-close-sidebar]');
        if (a) {
          // Store recent chat when selecting a user/group in the sidebar.
          try{
            const titleEl = a.querySelector('.sb-item-title');
            const title = titleEl ? titleEl.textContent.trim() : (a.getAttribute('data-title')||'');
            const href = a.getAttribute('href') || '';
            const type = (href.indexOf('group.php') !== -1) ? 'group' : 'direct';
            if (window.fc_storeRecentChat) window.fc_window.fc_storeRecentChat({ type, title, url: href });
          }catch(_e){}
          setOpen(false);
        }
      });
      document.addEventListener('keydown', (e)=>{
        if (e.key === 'Escape') setOpen(false);
      });
    })(appRoot);

    // Sidebar tabs + search filtering (index page UX)
    (function initSidebarUX(root){
      const tabs = Array.from(root.querySelectorAll('.sb-tab[data-tab]'));
      const sections = Array.from(root.querySelectorAll('.sb-section[data-section]'));
      const search = root.querySelector('#sbSearch');
      if (!tabs.length && !search) return;

      // Persist UX state (last tab + scroll position) so the sidebar feels Telegram-like.
      const LS_KEY_TAB = 'fc_sb_tab_v1';
      const LS_KEY_SCROLL_DIRECT = 'fc_sb_scroll_direct_v1';
      const LS_KEY_SCROLL_GROUPS = 'fc_sb_scroll_groups_v1';

      function getScrollKey(tab){
        return tab === 'groups' ? LS_KEY_SCROLL_GROUPS : LS_KEY_SCROLL_DIRECT;
      }

      function readLS(k){
        try { return window.localStorage.getItem(k) || ''; } catch(_e){ return ''; }
      }

      function writeLS(k, v){
        try { window.localStorage.setItem(k, String(v)); } catch(_e){}
      }
      function setTab(name){
        writeLS(LS_KEY_TAB, String(name||'direct'));
        tabs.forEach(t=>{
          const on = (t.getAttribute('data-tab') === name);
          t.classList.toggle('is-active', on);
          t.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        sections.forEach(s=>{
          const show = (s.getAttribute('data-section') === name);
          s.style.display = show ? '' : 'none';
        });

        // Restore scroll position for the active tab.
        const active = sections.find(s=>s.style.display !== 'none');
        const scroller = active ? (active.querySelector('.sb-list') || active) : null;
        if (scroller){
          const k = getScrollKey(String(name||'direct'));
          const v = Number(readLS(k) || '0');
          if (!Number.isNaN(v) && v > 0) scroller.scrollTop = v;
        }
      }

      // Default tab: restore last tab if possible
      let initialTab = 'direct';
      const savedTab = readLS(LS_KEY_TAB);
      if (savedTab === 'groups' || savedTab === 'direct') initialTab = savedTab;
      if (tabs.length && sections.length) setTab(initialTab);

      tabs.forEach(t=>{
        t.addEventListener('click', ()=>{
          setTab(String(t.getAttribute('data-tab')||'direct'));
        });
      });

      // Save scroll positions while scrolling (for both tabs)
      sections.forEach(s=>{
        const name = String(s.getAttribute('data-section')||'');
        const scroller = s.querySelector('.sb-list') || s;
        if (!name || !scroller) return;
        const key = (name==='groups') ? LS_KEY_SCROLL_GROUPS : LS_KEY_SCROLL_DIRECT;
        scroller.addEventListener('scroll', ()=>{
          try{ localStorage.setItem(key, String(scroller.scrollTop||0)); }catch(_e){}
        }, { passive: true });
      });

      // Search: filters within the currently visible section.
      if (search) {
        search.addEventListener('input', ()=>{
          const q = String(search.value||'').trim().toLowerCase();
          const visible = sections.find(s=>s.style.display !== 'none') || sections[0];
          if (!visible) return;
          const items = Array.from(visible.querySelectorAll('.sb-item[data-name]'));
          items.forEach(a=>{
            const hay = String(a.getAttribute('data-name')||'').toLowerCase();
            const ok = !q || hay.includes(q);
            a.style.display = ok ? '' : 'none';
          });
        });
      }
    })(appRoot);

    // Presence (online/last seen) in the index sidebar user list.
    (function initIndexPresence(root){
      const items = Array.from(root.querySelectorAll('.sb-item[data-user-id]'));
      if (!items.length) return;

      // Quick resume last chat (stored by chat pages)
      
// Recent chats (rendered inside the main empty-state area).
function renderRecentChats(){
  const host = document.getElementById('recentChatsMain');
  const list = document.getElementById('recentChatsList');
  if (!host || !list) return;

  const isRtl = (document.documentElement.dir === 'rtl');
  const labels = {
    head: isRtl ? 'ادامه گفتگو' : 'Continue',
  };

  const items = (window.fc_readRecentChats ? window.fc_readRecentChats() : [])
    
    .filter(x => x && x.url && x.title)
    .sort((a,b)=>(b.ts||0)-(a.ts||0))
    .slice(0,4);

  if (!items.length){
    host.style.display = 'none';
    list.innerHTML = '';
    return;
  }

  host.style.display = '';
  const head = host.querySelector('.recent-head');
  if (head) head.textContent = labels.head;

  list.innerHTML = items.map((it)=>{
    const safeTitle = String(it.title || '').replace(/[<>&"]/g, s => ({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;'}[s]));
    const sub = (it.type === 'group')
      ? (isRtl ? 'گروه' : 'Group')
      : (isRtl ? 'چت خصوصی' : 'Direct');
    return `
      <a class="recent-card" href="${it.url}">
        <div class="recent-mainline">
          <div class="recent-name">${safeTitle}</div>
          <div class="recent-badge">${sub}</div>
        </div>
      </a>
    `;
  }).join('');
}

renderRecentChats();;

      // Absolute base URL injected by PHP (works from any nested folder).
      const APP_BASE = (window.APP_BASE || '').toString().replace(/\/+$/, '');
      const api = (p)=> (APP_BASE ? (APP_BASE + '/' + String(p).replace(/^\/+/, '')) : String(p));

      const ids = items.map(a=>Number(a.getAttribute('data-user-id')||0)).filter(x=>x>0);
      const uniq = Array.from(new Set(ids));
      if (!uniq.length) return;

      // Poll presence + unread counts for the sidebar.
      // NOTE: Use POST to avoid proxy/cache issues on some shared hosting setups.
      async function poll(){
        try{
          const lang = (root.getAttribute('data-lang') || 'fa');
          const url = api('api/presence_bulk.php') + '?_t=' + Date.now();
          const body = 'ids=' + encodeURIComponent(uniq.join(',')) + '&lang=' + encodeURIComponent(lang);
          const r = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body
          });
          const data = await r.json();
          if (!data || !data.ok || !data.users) return;
          items.forEach(a=>{
            const uid = Number(a.getAttribute('data-user-id')||0);
            const st = data.users[String(uid)] || data.users[uid];
            if (!st) return;
            const dot = a.querySelector('.status-dot');
            const line = a.querySelector('.presence-text');
            const isOnline = !!st.is_online;
            if (dot){
              dot.classList.toggle('on', isOnline);
              dot.classList.toggle('off', !isOnline);
            }
            if (line){
              // Server returns a Telegram-like label already localized.
              if (st.label) line.textContent = String(st.label);
              else line.textContent = isOnline ? ((document.documentElement.dir==='rtl') ? 'آنلاین' : 'Online') : ((document.documentElement.dir==='rtl') ? 'آخرین بازدید اخیراً' : 'Last seen recently');
            }
            // Unread badge (direct chats) if provided by server.
            const badge = a.querySelector('.unread-badge');
            if (badge && typeof st.unread_count !== 'undefined') {
              const n = Number(st.unread_count) || 0;
              if (n > 0) {
                badge.style.display = '';
                badge.textContent = String(n > 99 ? '99+' : n);
              } else {
                badge.style.display = 'none';
                badge.textContent = '';
              }
            }
          });

          // Group unread badges (optional)
          try{
            const gBadges = Array.from(root.querySelectorAll('.unread-badge-group[data-group-id]'));
            const gIds = gBadges.map(b=>Number(b.getAttribute('data-group-id')||0)).filter(x=>x>0);
            const gUniq = Array.from(new Set(gIds));
            if (gUniq.length){
              const gUrl = api('api/group_unread_bulk.php') + '?_t=' + Date.now();
              const gBody = 'ids=' + encodeURIComponent(gUniq.join(','));
              const gr = await fetch(gUrl, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: gBody
              });
              const gd = await gr.json();
              if (gd && gd.ok && gd.groups){
                gBadges.forEach(b=>{
                  const gid = Number(b.getAttribute('data-group-id')||0);
                  const n = Number(gd.groups[String(gid)] ?? gd.groups[gid] ?? 0) || 0;
                  if (n > 0){
                    b.style.display = '';
                    b.textContent = String(n > 99 ? '99+' : n);
                  } else {
                    b.style.display = 'none';
                    b.textContent = '';
                  }
                });
              }
            }
          }catch(_e){}
        }catch(_e){
          // Do nothing (avoid breaking the UI).
        }
      }

      // First run immediately.
      poll();

      // Update frequently so unread badges feel instant (Telegram-like),
      // but keep it light (single small request).
      const intervalMs = 3000;
      setInterval(poll, intervalMs);

      // Also refresh when the tab becomes active again.
      document.addEventListener('visibilitychange', ()=>{
        if (!document.hidden) poll();
      });
      window.addEventListener('focus', poll);
    })(appRoot);
  }

  // The rest of the file is chat-specific.
  const root = document.querySelector('.app.chat');
  if (!root) return;

  // Absolute base URL injected by PHP (works from any nested folder).
  const APP_BASE = (window.APP_BASE || '').toString().replace(/\/+$/, '');
  const api = (p)=> (APP_BASE ? (APP_BASE + '/' + String(p).replace(/^\/+/, '')) : String(p));

  const lang = root.getAttribute('data-lang') || 'fa';
  const tr = (fa,en)=> (lang==='fa'?fa:en);

  const meta = {
    chatType: root.getAttribute('data-chat-type'),
    chatId: Number(root.getAttribute('data-chat-id')||0),
    me: Number(root.getAttribute('data-me')||0),
    canSend: root.getAttribute('data-can-send') === '1',
    filesEnabled: root.getAttribute('data-files') === '1',
    typingEnabled: root.getAttribute('data-typing') === '1',
    searchEnabled: root.getAttribute('data-search') === '1',
    deleteEnabled: root.getAttribute('data-delete') === '1',
    perPage: Number(root.getAttribute('data-per-page')||30)
  };

  // Persist last opened chat so the user can jump back quickly from the index.
  (function rememberLastChat(){
    try{
      const titleNode = root.querySelector('.chat-name');
      const title = (titleNode ? titleNode.textContent : '') || '';
      const url = window.location.pathname + window.location.search;
      window.fc_storeRecentChat({ type: meta.chatType, title: title.trim(), url });
    }catch(_e){}
  })();
  meta.directId = meta.chatType === 'direct' ? meta.chatId : 0;
  meta.groupId  = meta.chatType === 'group'  ? meta.chatId : 0;
  meta.otherId  = Number(root.getAttribute('data-other-id')||0);

  const el = {
    messages: document.getElementById('messages'),
    sendForm: document.getElementById('sendForm'),
    messageInput: document.getElementById('messageInput'),
    fileInput: document.getElementById('fileInput'),
    filePreview: document.getElementById('filePreview'),
    typingLine: document.getElementById('typingLine'),
    presenceLine: document.getElementById('presenceLine'),
    loadMoreBtn: document.getElementById('loadMoreBtn'),
    searchInput: document.getElementById('searchInput'),
    joinBtn: document.getElementById('joinBtn'),
    leaveBtn: document.getElementById('leaveBtn'),
    attachBtn: document.getElementById('attachBtn'),
    newMsgsBtn: document.getElementById('newMsgsBtn'),
    moreBtn: document.getElementById('chatMoreBtn'),
    moreMenu: document.getElementById('chatMoreMenu')
  };

  // Drawer is already initialized globally above.

  const csrf = (()=>{
    const inp = el.sendForm ? el.sendForm.querySelector('input[name="_csrf"]') : null;
    return inp ? String(inp.value||'') : '';
  })();

  if (!el.messages) return;

  // Swipe gesture to go back to the chat list (Telegram-like).
  (function enableSwipeBack(){
    const isMobile = window.matchMedia('(max-width: 860px)').matches;
    if (!isMobile) return;

    const goBack = ()=>{
      const target = (APP_BASE ? (APP_BASE + '/index.php') : 'index.php');
      window.location.href = target;
    };

    let startX = 0;
    let startY = 0;
    let tracking = false;

    el.messages.addEventListener('touchstart', (ev)=>{
      if (!ev.touches || !ev.touches[0]) return;
      const t = ev.touches[0];
      startX = t.clientX;
      startY = t.clientY;

      const w = window.innerWidth || document.documentElement.clientWidth || 0;
      const dir = document.documentElement.dir || 'ltr';
      // Start near the edge so normal horizontal swipes inside message bubbles are not affected.
      tracking = (dir === 'rtl') ? (startX > (w - 24)) : (startX < 24);
    }, {passive: true});

    el.messages.addEventListener('touchmove', (ev)=>{
      if (!tracking || !ev.touches || !ev.touches[0]) return;
      const t = ev.touches[0];
      const dx = t.clientX - startX;
      const dy = t.clientY - startY;
      // Require a mostly-horizontal swipe.
      if (Math.abs(dy) > 70) tracking = false;
      // Trigger when swipe distance is large enough.
      const dir = document.documentElement.dir || 'ltr';
      if (dir === 'rtl') {
        if (dx < -90 && Math.abs(dy) < 70) {
          tracking = false;
          goBack();
        }
      } else {
        if (dx > 90 && Math.abs(dy) < 70) {
          tracking = false;
          goBack();
        }
      }
    }, {passive: true});
  })();

  let lastId = 0;
  let oldestId = 0;
  let pollTimer = null;
  let typingCooldown = 0;
  let typingStopTimer = null;

  // New messages indicator when user is not at the bottom.
  let newMsgCount = 0;

  function showNewMsgsBtn(){
    if (!el.newMsgsBtn) return;
    el.newMsgsBtn.style.display = '';
    const base = tr('↓ پیام‌های جدید','↓ New messages');
    el.newMsgsBtn.textContent = newMsgCount > 0 ? (base + (newMsgCount > 99 ? ' (99+)' : ` (${newMsgCount})`)) : base;
  }

  function hideNewMsgsBtn(){
    if (!el.newMsgsBtn) return;
    el.newMsgsBtn.style.display = 'none';
    el.newMsgsBtn.textContent = '';
    newMsgCount = 0;
  }

  // Chat overflow menu (three-dots)
  (function initMoreMenu(){
    if (!el.moreBtn || !el.moreMenu) return;

    const menu = el.moreMenu;
    const btn = el.moreBtn;

    // Ensure the menu is not clipped by parent containers (position it as a fixed "portal" to <body>).
    if (menu.parentElement !== document.body) {
      document.body.appendChild(menu);
    }

    const PADDING = 12;

    function close(){
      menu.classList.remove('open');
      menu.style.left = '';
      menu.style.top = '';
      menu.style.right = '';
      menu.style.position = '';
      menu.style.zIndex = '';
      menu.style.maxHeight = '';
      menu.style.overflowY = '';
    }

    function measureWidth(){
      const wasOpen = menu.classList.contains('open');
      if (!wasOpen) {
        menu.style.visibility = 'hidden';
        menu.classList.add('open');
      }
      const w = menu.getBoundingClientRect().width || 240;
      if (!wasOpen) {
        menu.classList.remove('open');
        menu.style.visibility = '';
      }
      return w;
    }

    function open(){
      // Open first to measure height/width correctly.
      menu.classList.add('open');

      // Fixed positioning relative to viewport
      menu.style.position = 'fixed';
      menu.style.zIndex = '9999';

      // Make it scrollable if very tall
      const maxH = Math.max(160, window.innerHeight - 80);
      menu.style.maxHeight = String(maxH) + 'px';
      menu.style.overflowY = 'auto';

      const r = btn.getBoundingClientRect();
      const w = measureWidth();

      // Prefer aligning the menu's right edge with the button's right edge (Telegram-like).
      let left = (r.right - w);

      // Clamp inside viewport
      left = Math.max(PADDING, Math.min(left, window.innerWidth - w - PADDING));

      // Place under the button, but keep inside viewport vertically too.
      let top = r.bottom + 8;
      const menuRect = menu.getBoundingClientRect();
      if (top + menuRect.height > window.innerHeight - PADDING) {
        // If it would overflow bottom, flip upward
        top = Math.max(PADDING, r.top - menuRect.height - 8);
      }

      menu.style.left = left + 'px';
      menu.style.top  = top + 'px';
    }

    btn.addEventListener('click', (e)=>{
      e.preventDefault();
      e.stopPropagation();
      if (menu.classList.contains('open')) close(); else open();
    });

    // Close on outside click
    document.addEventListener('click', (e)=>{
      if (!menu.classList.contains('open')) return;
      const inside = e.target.closest('#chatMoreMenu') || e.target.closest('#chatMoreBtn');
      if (!inside) close();
    });

    // Close on ESC
    document.addEventListener('keydown', (e)=>{
      if (e.key === 'Escape') close();
    });

    // Close after picking an item
    menu.addEventListener('click', (e)=>{
      const item = e.target.closest('a,button');
      if (item) close();
    });

    // Reposition on resize/scroll to avoid drifting off-screen
    window.addEventListener('resize', ()=>{
      if (menu.classList.contains('open')) open();
    }, { passive: true });

    window.addEventListener('scroll', ()=>{
      if (menu.classList.contains('open')) open();
    }, { passive: true });
  })();;

  function qs(obj){
    const p = new URLSearchParams();
    Object.keys(obj).forEach(k=>{
      if (obj[k] !== undefined && obj[k] !== null) p.append(k, String(obj[k]));
    });
    return p.toString();
  }

  async function apiJson(url, opts){
    const o = opts || {};
    // Ensure cookies/session are always included + avoid caching issues.
    if (!('credentials' in o)) o.credentials = 'same-origin';
    if (!('cache' in o)) o.cache = 'no-store';
    const r = await fetch(url, o);
    return await r.json();
  }

  function escapeHtml(s){
    return String(s||'').replace(/[&<>"']/g, c=>({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'
    }[c]));
  }

  function linkify(text){
    const urlRe = /(https?:\/\/[^\s]+)/g;
    return text.replace(urlRe, (u)=>`<a href="${escapeHtml(u)}" target="_blank" rel="noopener">${escapeHtml(u)}</a>`);
  }

  function nearBottom(){
    const d = el.messages;
    return (d.scrollHeight - d.scrollTop - d.clientHeight) < 140;
  }

  function scrollBottom(){
    el.messages.scrollTop = el.messages.scrollHeight;
  }

  // Show/hide the new-messages button based on scroll position.
  (function initNewMsgsUX(){
    if (el.newMsgsBtn) {
      el.newMsgsBtn.addEventListener('click', ()=>{
        scrollBottom();
        hideNewMsgsBtn();
      });
    }
    // When user scrolls near bottom, clear the indicator.
    el.messages.addEventListener('scroll', ()=>{
      if (nearBottom()) hideNewMsgsBtn();
    }, { passive: true });
  })();

  // NOTE: Time formatting is done on the server using the configured timezone offset.
  // We intentionally avoid client-side conversion to prevent mismatches.

  // Telegram-like day dividers.
  // We rely ONLY on server-provided day_key/day_label to avoid timezone issues.
  let lastDayKeyRendered = '';

  function dayDividerHTML(dayKey, dayLabel){
    const key = String(dayKey || '');
    const label = escapeHtml(dayLabel || dayKey || '');
    if (!key) return '';
    return `<div class="day-divider" data-day="${escapeHtml(key)}"><span>${label}</span></div>`;
  }

  function firstExistingDayKey(){
    const firstMsg = el.messages.querySelector('.msg-row');
    return firstMsg ? String(firstMsg.dataset.day || '') : '';
  }

  function messageHTML(m){
    const mine = !!m.mine;
    const deleted = !!m.deleted;

    const sender = mine ? tr('شما','You') : escapeHtml(m.sender_name||'');
    const baseTime = String(m.time_text||'');
    const dayLabel = String(m.day_label||'');
    const timeShown = (dayLabel && dayLabel !== tr('امروز','Today')) ? `${dayLabel} ${baseTime}` : baseTime;
    const time = escapeHtml(timeShown);

    const status = (meta.chatType==='direct' && mine)
      ? (m.seen ? '✓✓' : '✓')
      : '';

    const delBtn = (meta.deleteEnabled && m.can_delete)
      ? `<button class="btn ghost mini" data-del="${m.id}" title="${tr('حذف','Delete')}">🗑️</button>`
      : '';

    let file = '';
    if (!deleted && m.file && m.file.url){
      if (m.file.is_image){
        file = `<div class="file"><a href="${m.file.url}" target="_blank" rel="noopener"><img class="preview-img" src="${m.file.url}" alt=""></a></div>`;
      } else {
        file = `<div class="file"><a href="${m.file.url}" target="_blank" rel="noopener">📎 ${escapeHtml(m.file.name||'file')}</a></div>`;
      }
    }

    const body = deleted
      ? `<div class="text deleted">${tr('این پیام حذف شده است','This message was deleted')}</div>`
      : (m.body ? `<div class="text">${linkify(escapeHtml(m.body))}</div>` : '');

    const dtTitle = m.datetime_text ? `title="${escapeHtml(m.datetime_text)}"` : '';
    const metaLine = `<div class="meta"><span class="sender">${sender}</span><span class="time" ${dtTitle}>${time}</span><span class="status">${status}</span>${delBtn}</div>`;

    const dayKey = escapeHtml(String(m.day_key||''));
    return `<div class="msg-row ${mine?'mine':''}" data-id="${m.id}" data-day="${dayKey}"><div class="bubble">${body}${file}${metaLine}</div></div>`;
  }

  function append(msgs){
    if (!msgs || !msgs.length) return;
    const atBottom = nearBottom();
    let html = '';
    for (const m of msgs){
      lastId = Math.max(lastId, m.id);
      oldestId = oldestId ? Math.min(oldestId, m.id) : m.id;

      const dk = String(m.day_key||'');
      if (dk && dk !== lastDayKeyRendered) {
        html += dayDividerHTML(dk, String(m.day_label||dk));
        lastDayKeyRendered = dk;
      }
      html += messageHTML(m);
    }
    el.messages.insertAdjacentHTML('beforeend', html);
    if (atBottom) {
      scrollBottom();
      hideNewMsgsBtn();
    } else {
      // User is reading older messages; show an indicator instead of forcing scroll.
      newMsgCount += msgs.length;
      showNewMsgsBtn();
    }
  }

  function prepend(msgs){
    if (!msgs || !msgs.length) return;
    const prevH = el.messages.scrollHeight;

    // If the newest message in this chunk belongs to the same day as the current
    // first message, remove the existing top divider for that day to avoid duplicates.
    const existingTopDay = firstExistingDayKey();
    const newestInChunkDay = String(msgs[msgs.length-1].day_key||'');
    if (existingTopDay && newestInChunkDay && existingTopDay === newestInChunkDay) {
      const firstDivider = el.messages.querySelector('.day-divider');
      if (firstDivider && String(firstDivider.dataset.day||'') === existingTopDay) {
        firstDivider.remove();
      }
    }

    let html = '';
    let prevDay = '';
    for (const m of msgs){
      oldestId = oldestId ? Math.min(oldestId, m.id) : m.id;

      const dk = String(m.day_key||'');
      if (dk && dk !== prevDay) {
        html += dayDividerHTML(dk, String(m.day_label||dk));
        prevDay = dk;
      }
      html += messageHTML(m);
    }
    el.messages.insertAdjacentHTML('afterbegin', html);
    const newH = el.messages.scrollHeight;
    el.messages.scrollTop += (newH - prevH);
  }

  function setTyping(names){
    if (!el.typingLine) return;
    if (!names || names.length===0) {
      el.typingLine.textContent = '';
      return;
    }
    el.typingLine.textContent = `${tr('در حال تایپ...','typing...')} ${names.join(', ')}`;
  }

  async function fetchNew(){
    const data = await apiJson(api('api/fetch_messages.php') + '?' + qs({
      chat_type: meta.chatType,
      direct_id: meta.directId,
      group_id: meta.groupId,
      since_id: lastId,
      include_typing: meta.typingEnabled ? 1 : 0,
      _t: Date.now()
    }));

    if (!data.ok) return;
    append(data.messages||[]);
    if (meta.chatType==='direct' && typeof data.other_last_read_message_id === 'number') {
      updateSeenUI(data.other_last_read_message_id);
    }
    if (data.typing) setTyping(data.typing);
    maybeMarkSeen();
  }

  async function fetchInitial(){
    try {
    const data = await apiJson(api('api/fetch_messages.php') + '?' + qs({
      chat_type: meta.chatType,
      direct_id: meta.directId,
      group_id: meta.groupId,
      since_id: 0,
      before_id: 0,
      include_typing: meta.typingEnabled ? 1 : 0,
      _t: Date.now()
    }));
    if (!data.ok) return;
    el.messages.innerHTML = '';
    lastId = 0; oldestId = 0;
    lastDayKeyRendered = '';
    append(data.messages||[]);
    if (meta.chatType==='direct' && typeof data.other_last_read_message_id === 'number') {
      updateSeenUI(data.other_last_read_message_id);
    }
    scrollBottom();
    maybeMarkSeen();
    } catch(e){ console.warn('fetchInitial failed', e); }
  }

  function updateSeenUI(otherLastRead){
    if (meta.chatType !== 'direct') return;
    const rows = el.messages.querySelectorAll('.msg-row.mine');
    rows.forEach(r=>{
      const id = Number(r.getAttribute('data-id')||0);
      const st = r.querySelector('.status');
      if (!st) return;
      if (id > 0 && id <= otherLastRead) st.textContent = '✓✓';
      else st.textContent = '✓';
    });
  }

  async function fetchOlder(){
    if (!oldestId) return;
    const data = await apiJson(api('api/fetch_messages.php') + '?' + qs({
      chat_type: meta.chatType,
      direct_id: meta.directId,
      group_id: meta.groupId,
      before_id: oldestId,
      _t: Date.now()
    }));
    if (!data.ok) return;
    prepend(data.messages||[]);
  }

  function startPolling(){
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = setInterval(fetchNew, 1200);
  }

  // Presence (online/last seen)
  async function presencePing(){
    try {
      await apiJson(api('api/presence_ping.php'), {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: qs({_csrf: csrf})
      });
    } catch(e) {}
  }

  function formatLastSeen(iso){
    if (!iso) return '';
    const ts = Date.parse(iso.replace(' ', 'T') + 'Z');
    if (Number.isNaN(ts)) return iso;
    const d = new Date(ts);
    const hh = String(d.getHours()).padStart(2,'0');
    const mm = String(d.getMinutes()).padStart(2,'0');
    return `${hh}:${mm}`;
  }

  async function presenceGet(){
    if (!el.presenceLine) return;
    if (meta.chatType==='direct' && meta.otherId>0){
      try {
        const data = await apiJson(api('api/presence_get.php') + '?' + qs({user_id: meta.otherId, lang: lang, _t: Date.now()}));
        if (!data.ok) return;
        if (data.label) {
          el.presenceLine.textContent = String(data.label);
        } else if (data.online){
          el.presenceLine.textContent = tr('آنلاین','Online');
        } else {
          el.presenceLine.textContent = tr('آخرین بازدید: ','Last seen: ') + formatLastSeen(data.last_seen_at);
        }
      } catch(e) {}
      return;
    }
    if (meta.chatType==='group'){
      try {
        const data = await apiJson(api('api/group_presence_get.php') + '?' + qs({group_id: meta.groupId, _t: Date.now()}));
        if (!data.ok) return;
        el.presenceLine.textContent = tr('آنلاین: ','Online: ') + String(data.online_count||0);
      } catch(e) {}
    }
  }

  // Seen (read pointers) - direct + group (for unread badges)
  let seenCooldown = 0;
  async function maybeMarkSeen(){
    if (!lastId) return;
    // Mark as seen only when user is near bottom (actually looking at latest messages)
    if (!nearBottom()) return;
    const now = Date.now();
    if (now < seenCooldown) return;
    seenCooldown = now + 1500;
    try {
      await apiJson(api('api/mark_seen.php'), {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: qs({
          _csrf: csrf,
          chat_type: meta.chatType,
          direct_id: meta.directId,
          group_id: meta.groupId,
          last_read_message_id: lastId
        })
      });
    } catch(e) {}
  }

  // Click delete
  el.messages.addEventListener('click', async (ev)=>{
    const btn = ev.target.closest('[data-del]');
    if (!btn) return;
    const id = Number(btn.getAttribute('data-del'));
    if (!id) return;
    const ok = confirm(tr('پیام حذف شود؟','Delete this message?'));
    if (!ok) return;
    const data = await apiJson(api('api/delete_message.php'), {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: qs({_csrf: csrf, id})
    });
    if (data.ok){
      // update in UI
      const node = el.messages.querySelector(`.msg-row[data-id="${id}"]`);
      if (node){
        const txt = node.querySelector('.text');
        if (txt) {
          txt.classList.add('deleted');
          txt.textContent = tr('این پیام حذف شده است','This message was deleted');
        } else {
          node.querySelector('.bubble')?.insertAdjacentHTML('afterbegin', `<div class="text deleted">${tr('این پیام حذف شده است','This message was deleted')}</div>`);
        }
        const file = node.querySelector('.file');
        if (file) file.remove();
      }
    }
  });

  // Join/leave group
  if (el.joinBtn){
    el.joinBtn.addEventListener('click', async ()=>{
      const gid = Number(el.joinBtn.getAttribute('data-gid'));
      const data = await apiJson(api('api/join_group.php'), {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: qs({_csrf: csrf, group_id: gid})
      });
      if (data.ok){
        location.reload();
      }
    });
  }
  if (el.leaveBtn){
    el.leaveBtn.addEventListener('click', async ()=>{
      const gid = Number(el.leaveBtn.getAttribute('data-gid'));
      const ok = confirm(tr('از گروه خارج می‌شوید؟','Leave the group?'));
      if (!ok) return;
      const data = await apiJson(api('api/leave_group.php'), {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: qs({_csrf: csrf, group_id: gid})
      });
      if (data.ok){
        location.reload();
      }
    });
  }

  // File attach + preview
  function clearPreview(){
    if (!el.filePreview) return;
    el.filePreview.style.display = 'none';
    el.filePreview.innerHTML = '';
  }

  if (el.attachBtn && el.fileInput){
    el.attachBtn.addEventListener('click', ()=> el.fileInput.click());
    el.fileInput.addEventListener('change', ()=>{
      clearPreview();
      const f = el.fileInput.files && el.fileInput.files[0];
      if (!f) return;
      if (!el.filePreview) return;
      el.filePreview.style.display = 'block';
      if (f.type.startsWith('image/')){
        const url = URL.createObjectURL(f);
        el.filePreview.innerHTML = `<div class="pv"><img src="${url}"><div class="pv-meta">${escapeHtml(f.name)} (${Math.round(f.size/1024)} KB)</div><button type="button" class="btn ghost pv-clear">✖</button></div>`;
      } else {
        el.filePreview.innerHTML = `<div class="pv"><div class="pv-file">📎 ${escapeHtml(f.name)} (${Math.round(f.size/1024)} KB)</div><button type="button" class="btn ghost pv-clear">✖</button></div>`;
      }
    });
  }

  if (el.filePreview){
    el.filePreview.addEventListener('click', (ev)=>{
      if (ev.target.closest('.pv-clear')){
        if (el.fileInput) el.fileInput.value = '';
        clearPreview();
      }
    });
  }

  // Typing ping
  async function typingPing(){
    if (!meta.typingEnabled) return;
    const now = Date.now();
    if (now < typingCooldown) return;
    typingCooldown = now + 1500;
    await apiJson(api('api/typing.php'), {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: qs({_csrf: csrf, chat_type: meta.chatType, direct_id: meta.directId, group_id: meta.groupId})
    });
  }

  if (el.messageInput){
    el.messageInput.addEventListener('input', ()=>{
      if (!meta.canSend) return;
      typingPing();
      if (typingStopTimer) clearTimeout(typingStopTimer);
      typingStopTimer = setTimeout(()=>{ /* silence */ }, 3000);
    });
  }

  // Send form
  if (el.sendForm){
    el.sendForm.addEventListener('submit', async (ev)=>{
      ev.preventDefault();
      if (!meta.canSend) return;

      const fd = new FormData(el.sendForm);
      fd.set('chat_type', meta.chatType);
      if (meta.chatType==='direct') fd.set('direct_id', String(meta.directId));
      if (meta.chatType==='group') fd.set('group_id', String(meta.groupId));

      const body = (el.messageInput?.value||'').trim();
      const hasFile = (el.fileInput && el.fileInput.files && el.fileInput.files.length>0);
      if (!body && !hasFile) return;

      el.messageInput && (el.messageInput.disabled = true);

      const res = await fetch(api('api/send_message.php'), {method:'POST', body: fd, credentials:'same-origin', cache:'no-store'});
      const data = await res.json();

      el.messageInput && (el.messageInput.disabled = false);

      if (!data.ok){
        alert(tr('خطا در ارسال پیام','Send failed'));
        return;
      }

      if (el.messageInput) el.messageInput.value = '';
      if (el.fileInput) el.fileInput.value = '';
      clearPreview();

      // Append immediately if server returns message
      if (data.message){
        append([data.message]);
        scrollBottom();
      } else {
        fetchNew();
      }
    });
  }

  // Load older
  if (el.loadMoreBtn){
    el.loadMoreBtn.addEventListener('click', (ev)=>{
      ev.preventDefault();
      fetchOlder();
    });
  }

  // Search
  let searchTimer = null;
  let searchPanel = null;

  function ensureSearchPanel(){
    if (searchPanel) return searchPanel;
    searchPanel = document.createElement('div');
    searchPanel.className = 'search-panel';
    searchPanel.style.display = 'none';
    const hdr = document.querySelector('.chat-header');
    if (hdr) hdr.appendChild(searchPanel);
    searchPanel.addEventListener('click', (ev)=>{
      const it = ev.target.closest('[data-jump]');
      if (!it) return;
      const id = Number(it.getAttribute('data-jump'));
        const node = el.messages.querySelector(`.msg-row[data-id="${id}"]`);
      if (node){
        node.scrollIntoView({behavior:'smooth', block:'center'});
        node.classList.add('flash');
        setTimeout(()=>node.classList.remove('flash'), 900);
      }
    });
    return searchPanel;
  }

  async function doSearch(q){
    const panel = ensureSearchPanel();
    if (!q || q.length < 2){
      panel.style.display = 'none';
      panel.innerHTML = '';
      return;
    }
    const data = await apiJson(api('api/search_messages.php') + '?' + qs({
      q,
      chat_type: meta.chatType,
      direct_id: meta.directId,
      group_id: meta.groupId
    }));
    if (!data.ok){
      panel.style.display = 'none';
      panel.innerHTML = '';
      return;
    }
    const items = data.messages||[];
    panel.innerHTML = items.slice(0,12).map(m=>{
      const txt = m.deleted ? tr('این پیام حذف شده است','This message was deleted') : (m.body||'');
      return `<div class="search-item" data-jump="${m.id}"><div class="si-title">${escapeHtml(m.sender_name||'')}</div><div class="si-body">${escapeHtml(txt).slice(0,80)}</div></div>`;
    }).join('') || `<div class="search-empty">${tr('چیزی پیدا نشد','No results')}</div>`;
    panel.style.display = 'block';
  }

  if (el.searchInput && meta.searchEnabled){
    el.searchInput.addEventListener('input', ()=>{
      if (!meta.canSend && meta.chatType==='group') return; // not a member
      const q = el.searchInput.value.trim();
      if (searchTimer) clearTimeout(searchTimer);
      searchTimer = setTimeout(()=>doSearch(q), 400);
    });
    el.searchInput.addEventListener('blur', ()=>{
      setTimeout(()=>{ if (searchPanel) searchPanel.style.display='none'; }, 250);
    });
    el.searchInput.addEventListener('focus', ()=>{
      if (searchPanel && searchPanel.innerHTML.trim()) searchPanel.style.display='block';
    });
  }

  // Start
  if (meta.chatType === 'group' && !meta.canSend){
    // Not member: do not poll
    return;
  }

  fetchInitial();
  startPolling();
  presencePing();
  presenceGet();
  setInterval(presencePing, 30000);
  setInterval(presenceGet, 10000);
})();