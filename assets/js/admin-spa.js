(function(){
  'use strict';

  var root = document.getElementById('admin-root');
  var apiBase = 'api/admin.php?action=';
  var authBase = 'api/auth.php?action=';
  var listenersBound = false;

  var navItems = [
    {id:'dashboard', label:'Обзор', mark:'О', sub:'Сводка по продажам и заявкам'},
    {id:'orders', label:'Заказы', mark:'З', sub:'Клиентские заказы, статусы и заметки'},
    {id:'guests', label:'Гостевые', mark:'Г', sub:'Быстрые заявки без регистрации'},
    {id:'clients', label:'Клиенты', mark:'К', sub:'Покупатели, роли и бонусы'},
    {id:'promo', label:'Промо', mark:'П', sub:'Правила ретро-бонуса'},
    {id:'analytics', label:'Аналитика', mark:'А', sub:'Динамика, статусы и лидеры'},
    {id:'products', label:'Товары', mark:'Т', sub:'Карточки, описания, фото и видимость'},
    {id:'carousel', label:'Карусель', mark:'С', sub:'Товары в ленте на главной'},
    {id:'settings', label:'Настройки', mark:'Н', sub:'Telegram, email и бонусная система'}
  ];

  var statusLabels = {
    new:'Новый',
    confirmed:'Подтвержден',
    in_progress:'В обработке',
    shipped:'Отгружен',
    completed:'Выполнен',
    cancelled:'Отменен',
    in_stock:'В наличии',
    out_of_stock:'Нет в наличии'
  };

  var state = {
    user:null,
    view:'dashboard',
    stats:null,
    charts:[],
    orders:{page:1,total:0,search:'',status:'',date_from:'',date_to:'',items:[]},
    guests:{page:1,total:0,items:[]},
    users:[],
    promos:[],
    products:[],
    productTotal:0,
    productSearch:'',
    productGroup:'',
    productStatus:'',
    carouselIds:[],
    settings:{},
    editor:null
  };

  function esc(value){
    return String(value == null ? '' : value)
      .replace(/&/g,'&amp;')
      .replace(/</g,'&lt;')
      .replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;')
      .replace(/'/g,'&#39;');
  }

  function money(value){
    var n = Number(value || 0);
    return n.toLocaleString('ru-RU') + ' ₽';
  }

  function dateShort(value){
    if(!value) return '—';
    return String(value).replace('T',' ').slice(0,16);
  }

  function qs(params){
    var parts = [];
    Object.keys(params || {}).forEach(function(key){
      var value = params[key];
      if(value !== undefined && value !== null && value !== '') {
        parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(value));
      }
    });
    return parts.length ? '&' + parts.join('&') : '';
  }

  async function fetchJson(url, options){
    var opts = options || {};
    opts.credentials = 'same-origin';
    if(opts.body && !(opts.body instanceof FormData)) {
      opts.headers = Object.assign({'Content-Type':'application/json'}, opts.headers || {});
      opts.body = JSON.stringify(opts.body);
    }
    var response = await fetch(url, opts);
    var data = null;
    try { data = await response.json(); } catch(e) {}
    if(!response.ok || (data && data.ok === false)) {
      var message = (data && data.error) ? data.error : ('HTTP ' + response.status);
      if(response.status === 401 || response.status === 403) {
        renderLogin('Сессия закончилась или у аккаунта нет доступа администратора.');
      }
      throw new Error(message);
    }
    return data || {};
  }

  function api(action, options){ return fetchJson(apiBase + action, options); }
  function auth(action, options){ return fetchJson(authBase + action, options); }

  function titleFor(id){
    return navItems.find(function(item){ return item.id === id; }) || navItems[0];
  }

  function viewEl(){ return document.getElementById('admin-view'); }
  function drawerEl(){ return document.getElementById('drawer'); }
  function modalEl(){ return document.getElementById('modal'); }

  function setTopTitle(id){
    var item = titleFor(id);
    var title = document.getElementById('page-title');
    var sub = document.getElementById('page-subtitle');
    if(title) title.textContent = item.label;
    if(sub) sub.textContent = item.sub;
  }

  function renderLogin(message){
    destroyCharts();
    root.innerHTML =
      '<div class="login-wrap">' +
        '<form class="login-card" id="login-form">' +
          '<div class="login-brand">Ritual B2B</div>' +
          '<div class="login-note">Войдите в административную панель</div>' +
          '<label class="field"><span>Логин или телефон</span><input class="input" name="phone" autocomplete="username" required></label>' +
          '<label class="field"><span>Пароль</span><input class="input" name="password" type="password" autocomplete="current-password" required></label>' +
          '<button class="btn primary" type="submit">Войти</button>' +
          '<div class="error-line" id="login-error">' + esc(message || '') + '</div>' +
        '</form>' +
      '</div>';
    bindRootEvents();
  }

  async function loginFromForm(form){
    var err = document.getElementById('login-error');
    if(err) err.textContent = '';
    var payload = {
      phone: form.phone.value.trim(),
      password: form.password.value
    };
    try {
      var data = await auth('login', {method:'POST', body:payload});
      if(!data.user || data.user.role !== 'admin') {
        renderLogin('Этот аккаунт не имеет роли администратора.');
        return;
      }
      state.user = data.user;
      renderApp();
      await switchView('dashboard');
    } catch(e) {
      if(err) err.textContent = e.message;
    }
  }

  function renderApp(){
    root.innerHTML =
      '<div class="admin-shell">' +
        '<aside class="sidebar" id="sidebar">' +
          '<div class="brand"><div class="brand-title">Ritual B2B</div><div class="brand-sub">Панель управления</div></div>' +
          '<nav class="nav">' + navItems.map(function(item){
            return '<button class="nav-btn" type="button" data-view="' + item.id + '" data-mark="' + item.mark + '">' + item.label + '</button>';
          }).join('') + '</nav>' +
          '<div class="sidebar-foot">' +
            '<div class="user-name">' + esc(state.user && state.user.name || 'Администратор') + '</div>' +
            '<div class="user-meta">' + esc(state.user && state.user.phone || '') + '</div>' +
          '</div>' +
        '</aside>' +
        '<main class="main">' +
          '<header class="topbar">' +
            '<div>' +
              '<button class="btn ghost small mobile-menu" type="button" data-action="mobile-menu">Меню</button>' +
              '<div class="page-title" id="page-title">Обзор</div>' +
              '<div class="page-subtitle" id="page-subtitle">Сводка по продажам и заявкам</div>' +
            '</div>' +
            '<div class="top-actions">' +
              '<a class="btn ghost small" href="/" target="_blank" rel="noopener">Открыть сайт</a>' +
              '<button class="btn soft small" type="button" data-action="send-report">Отчет в Telegram</button>' +
              '<button class="btn ghost small" type="button" data-action="refresh">Обновить</button>' +
              '<button class="btn danger small" type="button" data-action="logout">Выйти</button>' +
            '</div>' +
          '</header>' +
          '<section class="view" id="admin-view"></section>' +
        '</main>' +
        '<div class="drawer" id="drawer"></div>' +
        '<div class="modal" id="modal"></div>' +
        '<div class="toast" id="toast"></div>' +
      '</div>';
    bindRootEvents();
  }

  function bindRootEvents(){
    if(listenersBound) return;
    listenersBound = true;
    root.addEventListener('click', onRootClick);
    root.addEventListener('submit', onRootSubmit);
    root.addEventListener('change', onRootChange);
    root.addEventListener('input', onRootInput);
  }

  async function onRootClick(event){
    var viewBtn = event.target.closest('[data-view]');
    if(viewBtn) {
      await switchView(viewBtn.getAttribute('data-view'));
      var sidebar = document.getElementById('sidebar');
      if(sidebar) sidebar.classList.remove('open');
      return;
    }
    var actionEl = event.target.closest('[data-action]');
    if(!actionEl) return;
    var action = actionEl.getAttribute('data-action');
    if(action === 'logout') return logout();
    if(action === 'mobile-menu') return document.getElementById('sidebar').classList.toggle('open');
    if(action === 'refresh') return switchView(state.view);
    if(action === 'send-report') return sendReport(actionEl);
    if(action === 'order-page') return renderOrders(Number(actionEl.getAttribute('data-page') || 1));
    if(action === 'guest-page') return renderGuests(Number(actionEl.getAttribute('data-page') || 1));
    if(action === 'save-note') return saveOrderNote(actionEl);
    if(action === 'user-detail') return showUserDetail(actionEl.getAttribute('data-id'));
    if(action === 'modal-close') return closeModal();
    if(action === 'promo-delete') return deletePromo(actionEl.getAttribute('data-id'));
    if(action === 'product-add') return openProductEditor(null);
    if(action === 'product-edit') return openProductEditor(actionEl.getAttribute('data-sku'));
    if(action === 'product-delete') return deleteCustomProduct(actionEl.getAttribute('data-sku'));
    if(action === 'drawer-close') return closeDrawer();
    if(action === 'photo-remove') return removeEditorPhoto(Number(actionEl.getAttribute('data-index')));
    if(action === 'photo-up') return moveEditorPhoto(Number(actionEl.getAttribute('data-index')), -1);
    if(action === 'photo-down') return moveEditorPhoto(Number(actionEl.getAttribute('data-index')), 1);
    if(action === 'photo-manual') return addManualPhoto();
    if(action === 'carousel-save') return saveCarousel();
  }

  async function onRootSubmit(event){
    var form = event.target;
    if(form.id === 'login-form') {
      event.preventDefault();
      return loginFromForm(form);
    }
    if(form.id === 'order-filters') {
      event.preventDefault();
      state.orders.search = form.search.value.trim();
      state.orders.status = form.status.value;
      state.orders.date_from = form.date_from.value;
      state.orders.date_to = form.date_to.value;
      return renderOrders(1);
    }
    if(form.id === 'product-filters') {
      event.preventDefault();
      state.productSearch = form.search.value.trim();
      state.productGroup = form.group.value;
      state.productStatus = form.status.value;
      return renderProducts();
    }
    if(form.id === 'promo-form') {
      event.preventDefault();
      return createPromo(form);
    }
    if(form.id === 'settings-form') {
      event.preventDefault();
      return saveSettings(form);
    }
    if(form.id === 'product-editor-form') {
      event.preventDefault();
      return saveProductEditor(form);
    }
  }

  async function onRootChange(event){
    var target = event.target;
    if(target.matches('[data-order-status]')) {
      return saveOrderStatus(target.getAttribute('data-id'), target.value);
    }
    if(target.matches('[data-role-select]')) {
      return saveRole(target.getAttribute('data-id'), target.value);
    }
    if(target.matches('[data-promo-active]')) {
      return togglePromo(target.getAttribute('data-id'), target.checked ? 1 : 0);
    }
    if(target.matches('[data-product-active]')) {
      return toggleProduct(target.getAttribute('data-sku'), target.checked ? 1 : 0);
    }
    if(target.id === 'editor-photo-input') {
      return uploadEditorPhotos(target);
    }
    if(state.editor && target.form && target.form.id === 'product-editor-form') {
      refreshEditorPreview(target.form);
    }
  }

  function onRootInput(event){
    var target = event.target;
    if(state.editor && target.form && target.form.id === 'product-editor-form') {
      refreshEditorPreview(target.form);
    }
  }

  async function logout(){
    try { await auth('logout', {method:'POST'}); } catch(e) {}
    state.user = null;
    renderLogin();
  }

  function setActiveNav(id){
    document.querySelectorAll('.nav-btn').forEach(function(btn){
      btn.classList.toggle('active', btn.getAttribute('data-view') === id);
    });
  }

  async function switchView(id){
    state.view = id || 'dashboard';
    setActiveNav(state.view);
    setTopTitle(state.view);
    closeDrawer();
    closeModal();
    destroyCharts();
    var el = viewEl();
    if(el) el.innerHTML = '<div class="loading">Загружаю данные</div>';
    try {
      if(state.view === 'dashboard') await renderDashboard();
      else if(state.view === 'orders') await renderOrders(state.orders.page || 1);
      else if(state.view === 'guests') await renderGuests(state.guests.page || 1);
      else if(state.view === 'clients') await renderClients();
      else if(state.view === 'promo') await renderPromo();
      else if(state.view === 'analytics') await renderAnalytics();
      else if(state.view === 'products') await renderProducts();
      else if(state.view === 'carousel') await renderCarousel();
      else if(state.view === 'settings') await renderSettings();
    } catch(e) {
      renderError(e.message);
    }
  }

  function renderError(message){
    viewEl().innerHTML = '<div class="empty">Не удалось загрузить раздел: ' + esc(message) + '</div>';
  }

  async function loadStats(){
    var data = await api('stats');
    state.stats = data.stats || {};
    return state.stats;
  }

  function kpis(stats){
    var items = [
      ['Всего заказов', stats.orders_count || 0, 'accent'],
      ['Новые', stats.orders_new || 0, ''],
      ['Сегодня', stats.orders_today || 0, ''],
      ['Выручка', money(stats.orders_revenue || 0), 'accent'],
      ['Клиенты', stats.users_count || 0, ''],
      ['Гостевые', stats.guests_count || 0, '']
    ];
    return '<div class="grid kpis">' + items.map(function(item){
      return '<div class="kpi ' + item[2] + '"><div class="kpi-value">' + esc(item[1]) + '</div><div class="kpi-label">' + esc(item[0]) + '</div></div>';
    }).join('') + '</div>';
  }

  async function renderDashboard(){
    var data = await Promise.all([
      loadStats(),
      api('analytics&days=14'),
      api('orders' + qs({page:1}))
    ]);
    var stats = data[0];
    var analytics = data[1];
    var orders = (data[2].orders || []).slice(0, 8);
    viewEl().innerHTML =
      kpis(stats) +
      '<div class="grid two">' +
        '<section class="panel">' +
          '<div class="panel-head"><div><div class="panel-title">Динамика за 14 дней</div><div class="panel-note">Заказы и выручка по датам</div></div></div>' +
          '<div class="chart-box"><canvas id="dash-chart"></canvas></div>' +
        '</section>' +
        '<section class="panel">' +
          '<div class="panel-head"><div><div class="panel-title">Последние заказы</div><div class="panel-note">Свежие заявки из кабинета клиента</div></div><button class="btn ghost small" type="button" data-view="orders">Все заказы</button></div>' +
          renderOrdersMini(orders) +
        '</section>' +
      '</div>';
    drawDailyChart('dash-chart', analytics.daily || []);
  }

  function renderOrdersMini(orders){
    if(!orders.length) return '<div class="empty">Заказов пока нет</div>';
    return '<div class="table-wrap"><table class="data-table"><thead><tr><th>Номер</th><th>Клиент</th><th>Сумма</th><th>Статус</th></tr></thead><tbody>' +
      orders.map(function(order){
        return '<tr><td class="mono">SH-' + String(order.id).padStart(5,'0') + '</td><td>' + esc(order.user_name || '') + '</td><td class="price">' + money(order.total) + '</td><td>' + statusPill(order.status) + '</td></tr>';
      }).join('') +
      '</tbody></table></div>';
  }

  async function renderOrders(page){
    state.orders.page = page || 1;
    var data = await api('orders' + qs({
      page:state.orders.page,
      search:state.orders.search,
      status:state.orders.status,
      date_from:state.orders.date_from,
      date_to:state.orders.date_to
    }));
    state.orders.items = data.orders || [];
    state.orders.total = data.total || 0;
    var pages = Math.max(1, Math.ceil(state.orders.total / 50));
    viewEl().innerHTML =
      '<form class="toolbar" id="order-filters">' +
        '<div class="filters">' +
          '<input class="input search" name="search" placeholder="Поиск по номеру или клиенту" value="' + esc(state.orders.search) + '">' +
          '<select class="select" name="status">' + statusFilterOptions(state.orders.status) + '</select>' +
          '<input class="input" type="date" name="date_from" value="' + esc(state.orders.date_from) + '">' +
          '<input class="input" type="date" name="date_to" value="' + esc(state.orders.date_to) + '">' +
          '<button class="btn primary" type="submit">Показать</button>' +
        '</div>' +
        '<a class="btn ghost" href="api/admin.php?action=export_orders_csv' + qs({search:state.orders.search,status:state.orders.status,date_from:state.orders.date_from,date_to:state.orders.date_to}) + '">CSV</a>' +
      '</form>' +
      '<section class="panel">' +
        '<div class="panel-head"><div><div class="panel-title">Заказы</div><div class="panel-note">Найдено: ' + state.orders.total + '</div></div></div>' +
        renderOrdersTable(state.orders.items) +
        pagination(state.orders.page, pages, 'order-page') +
      '</section>';
  }

  function renderOrdersTable(orders){
    if(!orders.length) return '<div class="empty">По фильтрам заказов нет</div>';
    return '<div class="table-wrap"><table class="data-table"><thead><tr><th>Заказ</th><th>Клиент</th><th>Состав</th><th>Сумма</th><th>Статус</th><th>Заметка</th></tr></thead><tbody>' +
      orders.map(function(order){
        return '<tr>' +
          '<td><div class="mono">SH-' + String(order.id).padStart(5,'0') + '</div><div class="muted">' + dateShort(order.created_at) + '</div></td>' +
          '<td><b>' + esc(order.user_name || '') + '</b><div class="muted">' + esc(order.user_phone || '') + '</div></td>' +
          '<td>' + renderItems(order.items) + '</td>' +
          '<td class="price nowrap">' + money(order.total) + '</td>' +
          '<td><select class="select" data-order-status data-id="' + order.id + '">' + statusOptions(order.status) + '</select></td>' +
          '<td><textarea class="textarea" id="note-' + order.id + '">' + esc(order.admin_note || '') + '</textarea><button class="btn ghost small" type="button" data-action="save-note" data-id="' + order.id + '">Сохранить</button></td>' +
        '</tr>';
      }).join('') +
      '</tbody></table></div>';
  }

  function renderItems(items){
    var list = items || [];
    if(!list.length) return '<span class="muted">Состав не указан</span>';
    return list.map(function(item){
      return '<div>' + esc(item.product_name) + ' <span class="muted">x' + esc(item.qty) + '</span></div>';
    }).join('');
  }

  function statusFilterOptions(current){
    return '<option value="">Все статусы</option>' + Object.keys(statusLabels).filter(function(key){
      return ['new','confirmed','in_progress','shipped','completed','cancelled'].indexOf(key) !== -1;
    }).map(function(key){
      return '<option value="' + key + '"' + (key === current ? ' selected' : '') + '>' + statusLabels[key] + '</option>';
    }).join('');
  }

  function statusOptions(current){
    return Object.keys(statusLabels).filter(function(key){
      return ['new','confirmed','in_progress','shipped','completed','cancelled'].indexOf(key) !== -1;
    }).map(function(key){
      return '<option value="' + key + '"' + (key === current ? ' selected' : '') + '>' + statusLabels[key] + '</option>';
    }).join('');
  }

  function statusPill(status){
    return '<span class="status ' + esc(status) + '">' + esc(statusLabels[status] || status || '—') + '</span>';
  }

  function pagination(page, pages, action){
    if(pages <= 1) return '';
    var prev = Math.max(1, page - 1);
    var next = Math.min(pages, page + 1);
    return '<div class="toolbar" style="margin-top:14px">' +
      '<button class="btn ghost small" type="button" data-action="' + action + '" data-page="' + prev + '"' + (page <= 1 ? ' disabled' : '') + '>Назад</button>' +
      '<span class="muted">Страница ' + page + ' из ' + pages + '</span>' +
      '<button class="btn ghost small" type="button" data-action="' + action + '" data-page="' + next + '"' + (page >= pages ? ' disabled' : '') + '>Дальше</button>' +
    '</div>';
  }

  async function saveOrderStatus(id, status){
    try {
      await api('order_status', {method:'POST', body:{order_id:Number(id), status:status}});
      toast('Статус заказа обновлен');
    } catch(e) { toast(e.message, true); }
  }

  async function saveOrderNote(button){
    var id = button.getAttribute('data-id');
    var area = document.getElementById('note-' + id);
    try {
      await api('admin_note', {method:'POST', body:{order_id:Number(id), note:area ? area.value : ''}});
      toast('Заметка сохранена');
    } catch(e) { toast(e.message, true); }
  }

  async function renderGuests(page){
    state.guests.page = page || 1;
    var data = await api('guest_orders' + qs({page:state.guests.page}));
    state.guests.items = data.orders || [];
    state.guests.total = data.total || 0;
    var pages = Math.max(1, Math.ceil(state.guests.total / 50));
    viewEl().innerHTML =
      '<section class="panel">' +
        '<div class="panel-head"><div><div class="panel-title">Гостевые заявки</div><div class="panel-note">Найдено: ' + state.guests.total + '</div></div></div>' +
        renderGuestsTable(state.guests.items) +
        pagination(state.guests.page, pages, 'guest-page') +
      '</section>';
  }

  function renderGuestsTable(orders){
    if(!orders.length) return '<div class="empty">Гостевых заявок пока нет</div>';
    return '<div class="table-wrap"><table class="data-table"><thead><tr><th>Заявка</th><th>Клиент</th><th>Состав</th><th>Сумма</th><th>Комментарий</th></tr></thead><tbody>' +
      orders.map(function(order){
        return '<tr><td class="mono">G-' + String(order.id).padStart(5,'0') + '<div class="muted">' + dateShort(order.created_at) + '</div></td>' +
          '<td><b>' + esc(order.name) + '</b><div class="muted">' + esc(order.phone) + '</div><div class="muted">' + esc(order.client_tg || '') + '</div></td>' +
          '<td>' + renderItems(order.items) + '</td>' +
          '<td class="price nowrap">' + money(order.total) + '</td>' +
          '<td>' + esc(order.comment || '') + '</td></tr>';
      }).join('') + '</tbody></table></div>';
  }

  async function renderClients(){
    var data = await api('users');
    state.users = data.users || [];
    viewEl().innerHTML =
      '<section class="panel">' +
        '<div class="panel-head"><div><div class="panel-title">Клиенты</div><div class="panel-note">Всего аккаунтов: ' + state.users.length + '</div></div></div>' +
        renderUsersTable(state.users) +
      '</section>';
  }

  function renderUsersTable(users){
    if(!users.length) return '<div class="empty">Клиентов пока нет</div>';
    return '<div class="table-wrap"><table class="data-table"><thead><tr><th>Клиент</th><th>Контакты</th><th>Роль</th><th>Бонусы</th><th>Заказы</th><th></th></tr></thead><tbody>' +
      users.map(function(user){
        return '<tr><td><b>' + esc(user.name) + '</b><div class="muted">' + dateShort(user.created_at) + '</div></td>' +
          '<td>' + esc(user.phone) + '<div class="muted">' + esc(user.telegram || '') + '</div></td>' +
          '<td><select class="select" data-role-select data-id="' + user.id + '">' +
            '<option value="client"' + (user.role === 'client' ? ' selected' : '') + '>Клиент</option>' +
            '<option value="admin"' + (user.role === 'admin' ? ' selected' : '') + '>Админ</option>' +
          '</select></td>' +
          '<td class="price nowrap">' + money(user.bonus_balance) + '</td>' +
          '<td>' + esc(user.order_count || 0) + '<div class="muted">' + money(user.total_spent) + '</div></td>' +
          '<td><button class="btn ghost small" type="button" data-action="user-detail" data-id="' + user.id + '">Открыть</button></td></tr>';
      }).join('') + '</tbody></table></div>';
  }

  async function saveRole(id, role){
    try {
      await api('set_role', {method:'POST', body:{user_id:Number(id), role:role}});
      toast('Роль обновлена');
    } catch(e) { toast(e.message, true); }
  }

  async function showUserDetail(id){
    try {
      var data = await api('user_detail' + qs({user_id:id}));
      var user = data.user || {};
      modalEl().innerHTML =
        '<div class="modal-card">' +
          '<div class="panel-head"><div><div class="panel-title">' + esc(user.name || 'Клиент') + '</div><div class="panel-note">' + esc(user.phone || '') + '</div></div><button class="btn ghost small" type="button" data-action="modal-close">Закрыть</button></div>' +
          '<div class="grid two">' +
            '<div><div class="panel-title">Заказы</div>' + renderOrdersMini(data.orders || []) + '</div>' +
            '<div><div class="panel-title">Бонусы: ' + money(data.bonus_balance || 0) + '</div>' + renderBonusLog(data.bonus_log || []) + '</div>' +
          '</div>' +
        '</div>';
      modalEl().classList.add('show');
    } catch(e) { toast(e.message, true); }
  }

  function renderBonusLog(rows){
    if(!rows.length) return '<div class="empty">Операций нет</div>';
    return '<div class="bars">' + rows.map(function(row){
      return '<div><b>' + money(row.amount) + '</b><div class="muted">' + esc(row.description || row.type || '') + ' · ' + dateShort(row.created_at) + '</div></div>';
    }).join('') + '</div>';
  }

  function closeModal(){
    var modal = modalEl();
    if(modal) { modal.classList.remove('show'); modal.innerHTML = ''; }
  }

  async function renderPromo(){
    var data = await api('promo_list');
    state.promos = data.rules || [];
    viewEl().innerHTML =
      '<div class="grid two">' +
        '<section class="panel">' +
          '<div class="panel-head"><div><div class="panel-title">Правила бонусов</div><div class="panel-note">Активные правила участвуют в расчете ретро-бонуса</div></div></div>' +
          renderPromoTable(state.promos) +
        '</section>' +
        '<section class="panel">' +
          '<div class="panel-head"><div><div class="panel-title">Новое правило</div><div class="panel-note">Минимум полей, которые реально нужны</div></div></div>' +
          '<form id="promo-form">' +
            '<label class="field"><span>Название</span><input class="input" name="name" required></label>' +
            '<label class="field"><span>Процент</span><input class="input" name="bonus_percent" type="number" min="0" step="0.1" value="3"></label>' +
            '<label class="field"><span>Группа товара</span><select class="select" name="product_group"><option value="">Все</option><option value="venki">Венки</option><option value="korzinki">Корзинки</option></select></label>' +
            '<label class="field"><span>Минимальная сумма</span><input class="input" name="min_order" type="number" min="0" value="0"></label>' +
            '<button class="btn primary" type="submit">Создать</button>' +
          '</form>' +
        '</section>' +
      '</div>';
  }

  function renderPromoTable(rules){
    if(!rules.length) return '<div class="empty">Правил пока нет</div>';
    return '<div class="table-wrap"><table class="data-table"><thead><tr><th>Правило</th><th>Условия</th><th>Активно</th><th></th></tr></thead><tbody>' +
      rules.map(function(rule){
        return '<tr><td><b>' + esc(rule.name) + '</b><div class="muted">' + dateShort(rule.created_at) + '</div></td>' +
          '<td>' + esc(rule.bonus_percent) + '% · ' + esc(rule.product_group || 'все группы') + '<div class="muted">от ' + money(rule.min_order || 0) + '</div></td>' +
          '<td><label class="switch"><input type="checkbox" data-promo-active data-id="' + rule.id + '"' + (Number(rule.active) ? ' checked' : '') + '><span></span></label></td>' +
          '<td><button class="btn ghost small" type="button" data-action="promo-delete" data-id="' + rule.id + '">Удалить</button></td></tr>';
      }).join('') + '</tbody></table></div>';
  }

  async function createPromo(form){
    try {
      await api('promo_create', {method:'POST', body:{
        name:form.name.value.trim(),
        bonus_percent:form.bonus_percent.value,
        product_group:form.product_group.value,
        min_order:form.min_order.value,
        active:1
      }});
      toast('Правило создано');
      await renderPromo();
    } catch(e) { toast(e.message, true); }
  }

  async function togglePromo(id, active){
    try {
      await api('promo_toggle', {method:'POST', body:{id:Number(id), active:active}});
      toast(active ? 'Правило включено' : 'Правило выключено');
    } catch(e) { toast(e.message, true); }
  }

  async function deletePromo(id){
    if(!confirm('Удалить это промо-правило?')) return;
    try {
      await api('promo_delete', {method:'POST', body:{id:Number(id)}});
      toast('Правило удалено');
      await renderPromo();
    } catch(e) { toast(e.message, true); }
  }

  async function renderAnalytics(){
    var data = await api('analytics&days=30');
    viewEl().innerHTML =
      '<div class="grid two">' +
        '<section class="panel"><div class="panel-head"><div><div class="panel-title">30 дней</div><div class="panel-note">Заказы и выручка</div></div></div><div class="chart-box"><canvas id="analytics-daily"></canvas></div></section>' +
        '<section class="panel"><div class="panel-head"><div><div class="panel-title">Статусы</div><div class="panel-note">Распределение всех заказов</div></div></div><div class="chart-box"><canvas id="analytics-status"></canvas></div></section>' +
      '</div>' +
      '<section class="panel" style="margin-top:14px"><div class="panel-head"><div><div class="panel-title">Товары-лидеры</div><div class="panel-note">По выручке за выбранный период</div></div></div>' + renderTopProducts(data.top_products || []) + '</section>';
    drawDailyChart('analytics-daily', data.daily || []);
    drawStatusChart('analytics-status', data.by_status || []);
  }

  function renderTopProducts(rows){
    if(!rows.length) return '<div class="empty">Данных пока нет</div>';
    var max = Math.max.apply(null, rows.map(function(row){ return Number(row.rev || 0); }));
    return '<div class="bars">' + rows.map(function(row){
      var width = max ? Math.round(Number(row.rev || 0) / max * 100) : 0;
      return '<div class="bar-row"><div>' + esc(row.product_name) + '</div><div class="bar-track"><div class="bar-fill" style="width:' + width + '%"></div></div><div class="right">' + money(row.rev) + '</div></div>';
    }).join('') + '</div>';
  }

  function drawDailyChart(id, rows){
    if(!window.Chart) return;
    var el = document.getElementById(id);
    if(!el) return;
    state.charts.push(new Chart(el, {
      type:'line',
      data:{
        labels:rows.map(function(r){ return r.day; }),
        datasets:[
          {label:'Заказы', data:rows.map(function(r){ return Number(r.cnt || 0); }), borderColor:'#5f7f5f', backgroundColor:'rgba(95,127,95,.12)', tension:.3, fill:true},
          {label:'Выручка', data:rows.map(function(r){ return Number(r.rev || 0); }), borderColor:'#b1843f', backgroundColor:'rgba(177,132,63,.10)', tension:.3, yAxisID:'y1'}
        ]
      },
      options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}},scales:{y:{beginAtZero:true},y1:{beginAtZero:true,position:'right',grid:{drawOnChartArea:false}}}}
    }));
  }

  function drawStatusChart(id, rows){
    if(!window.Chart) return;
    var el = document.getElementById(id);
    if(!el) return;
    state.charts.push(new Chart(el, {
      type:'doughnut',
      data:{
        labels:rows.map(function(r){ return statusLabels[r.status] || r.status; }),
        datasets:[{data:rows.map(function(r){ return Number(r.cnt || 0); }), backgroundColor:['#b1843f','#2f6faa','#5f7f5f','#657080','#2d7d46','#b54747']}]
      },
      options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}}}
    }));
  }

  function destroyCharts(){
    state.charts.forEach(function(chart){ try { chart.destroy(); } catch(e) {} });
    state.charts = [];
  }

  async function loadProducts(){
    var data = await api('products_list' + qs({search:state.productSearch}));
    state.products = data.products || [];
    state.productTotal = data.total || state.products.length;
    return state.products;
  }

  async function renderProducts(){
    await loadProducts();
    var list = filteredProducts();
    var hidden = state.products.filter(function(p){ return !productActive(p); }).length;
    viewEl().innerHTML =
      '<form class="toolbar" id="product-filters">' +
        '<div class="filters">' +
          '<input class="input search" name="search" placeholder="Название, SKU, серия" value="' + esc(state.productSearch) + '">' +
          '<select class="select" name="group">' +
            '<option value="">Все группы</option><option value="venki"' + (state.productGroup === 'venki' ? ' selected' : '') + '>Венки</option><option value="korzinki"' + (state.productGroup === 'korzinki' ? ' selected' : '') + '>Корзинки</option><option value="custom"' + (state.productGroup === 'custom' ? ' selected' : '') + '>Ручные</option>' +
          '</select>' +
          '<select class="select" name="status">' +
            '<option value="">Все статусы</option><option value="active"' + (state.productStatus === 'active' ? ' selected' : '') + '>На сайте</option><option value="hidden"' + (state.productStatus === 'hidden' ? ' selected' : '') + '>Скрытые</option>' +
          '</select>' +
          '<button class="btn primary" type="submit">Показать</button>' +
        '</div>' +
        '<button class="btn soft" type="button" data-action="product-add">Добавить товар</button>' +
      '</form>' +
      '<section class="catalog-panel">' +
        '<div class="panel-head"><div><div class="panel-title">Каталог</div><div class="panel-note">Товаров: ' + state.productTotal + ' · показано: ' + list.length + ' · скрыто: ' + hidden + '</div></div></div>' +
        renderProductsTable(list) +
      '</section>';
  }

  function filteredProducts(){
    return state.products.filter(function(product){
      if(state.productGroup === 'custom' && !product._custom) return false;
      if(state.productGroup && state.productGroup !== 'custom' && product.group !== state.productGroup) return false;
      if(state.productStatus === 'active' && !productActive(product)) return false;
      if(state.productStatus === 'hidden' && productActive(product)) return false;
      return true;
    });
  }

  function renderProductsTable(products){
    if(!products.length) return '<div class="empty">Товары не найдены</div>';
    return '<div class="product-list">' +
      products.map(function(product){
        var e = effectiveProduct(product);
        var photos = productPhotos(product);
        return '<article class="product-card">' +
          '<div class="product-photo-col">' +
            productImage(photos[0], e.model) +
            renderProductThumbs(photos) +
          '</div>' +
          '<div class="product-main">' +
            '<div class="product-topline"><span class="pill">' + esc(product._custom ? 'Ручной' : (product.group || '—')) + '</span>' + statusPill(e.stock) + '</div>' +
            '<div class="prod-name">' + esc(e.model) + '</div>' +
            '<div class="prod-sku mono">' + esc(product.sku) + '</div>' +
            '<div class="product-desc">' + esc(e.descShort || e.descLong || 'Описание не заполнено') + '</div>' +
          '</div>' +
          '<div class="product-side">' +
            '<div class="price">' + money(e.price) + '</div>' +
            '<div class="photo-count">' + photos.length + ' фото</div>' +
            '<label class="visibility-toggle"><span>На сайте</span><span class="switch"><input type="checkbox" data-product-active data-sku="' + esc(product.sku) + '"' + (productActive(product) ? ' checked' : '') + '><span></span></span></label>' +
            '<div class="product-actions"><button class="btn primary small" type="button" data-action="product-edit" data-sku="' + esc(product.sku) + '">Редактировать</button>' +
            (product._custom ? '<button class="btn ghost small" type="button" data-action="product-delete" data-sku="' + esc(product.sku) + '">Удалить</button>' : '') + '</div>' +
          '</div>' +
        '</article>';
      }).join('') + '</div>';
  }

  function renderProductThumbs(photos){
    if(!photos.length) return '<div class="thumb-row muted">Нет фото</div>';
    return '<div class="thumb-row">' + photos.slice(0, 4).map(function(photo){
      return '<img src="' + imgUrl(photo, 80) + '" alt="">';
    }).join('') + (photos.length > 4 ? '<span>+' + (photos.length - 4) + '</span>' : '') + '</div>';
  }

  function parseList(value){
    if(!value) return [];
    if(Array.isArray(value)) return value;
    if(typeof value === 'string') {
      try {
        var parsed = JSON.parse(value);
        if(Array.isArray(parsed)) return parsed;
      } catch(e) {}
      return value.split(/[\n,]+/);
    }
    return [];
  }

  function cleanList(list){
    var seen = {};
    return (list || []).map(function(item){ return String(item || '').trim(); }).filter(function(item){
      if(!item || seen[item]) return false;
      seen[item] = true;
      return true;
    });
  }

  function productPhotos(product){
    var ov = product._override || {};
    var photos = cleanList(parseList(ov.photos_override));
    if(!photos.length) photos = cleanList(parseList(product.photos));
    if(!photos.length && product.photo) photos = [product.photo];
    return photos;
  }

  function parseBenefits(value){
    if(Array.isArray(value)) return value;
    if(!value) return [];
    if(typeof value === 'string') {
      try {
        var parsed = JSON.parse(value);
        if(Array.isArray(parsed)) return parsed;
      } catch(e) {}
      return value.split('\n').map(function(x){ return x.trim(); }).filter(Boolean);
    }
    return [];
  }

  function effectiveProduct(product){
    var ov = product._override || {};
    return {
      model:ov.model_override || product.model || '',
      brand:ov.brand_override || product.brand || '',
      price:Number(ov.price_override || 0) > 0 ? Number(ov.price_override) : Number(product.price || 0),
      stock:ov.stock_override || product.stock || 'in_stock',
      size:ov.size_override || product.size || '',
      descShort:ov.desc_short || product.descShort || '',
      descLong:ov.desc_long_override || product.descLong || '',
      dimensions:ov.dimensions || '',
      badge:ov.badge || '',
      badgeLabel:ov.badge_label || '',
      benefits:ov.benefits_override ? parseBenefits(ov.benefits_override) : parseBenefits(product.benefits)
    };
  }

  function productActive(product){
    var raw = product._override && product._override.active;
    return !(raw === 0 || raw === '0');
  }

  function imgUrl(photo, width){
    return 'api/img.php?f=' + encodeURIComponent(photo) + '&w=' + (width || 160);
  }

  function productImage(photo, alt){
    if(!photo) return '<div class="prod-img prod-placeholder"></div>';
    return '<img class="prod-img" src="' + imgUrl(photo, 180) + '" alt="' + esc(alt || '') + '" decoding="async">';
  }

  async function toggleProduct(sku, active){
    try {
      await api('product_toggle_active', {method:'POST', body:{sku:sku, active:active}});
      var p = state.products.find(function(item){ return item.sku === sku; });
      if(p) {
        p._override = p._override || {};
        p._override.active = active;
        if(!active) state.carouselIds = state.carouselIds.filter(function(id){ return id !== p.id; });
      }
      toast(active ? 'Товар показан на сайте' : 'Товар скрыт с сайта');
    } catch(e) { toast(e.message, true); }
  }

  function openProductEditor(sku){
    var product = sku ? state.products.find(function(item){ return item.sku === sku; }) : null;
    var isCustom = !product || !!product._custom;
    var base = product || {
      sku:'',
      model:'',
      brand:'Ручная работа',
      brandCode:'custom',
      series:'',
      group:'venki',
      size:'-',
      price:0,
      stock:'in_stock',
      descShort:'',
      cardBenef:'',
      descLong:'',
      benefits:[],
      _custom:true,
      _override:{active:1}
    };
    state.editor = {
      product:base,
      isNew:!product,
      isCustom:isCustom,
      photos:productPhotos(base)
    };
    renderProductDrawer();
  }

  function renderProductDrawer(){
    var ed = state.editor;
    var product = ed.product;
    var e = effectiveProduct(product);
    var title = ed.isNew ? 'Новый товар' : e.model;
    drawerEl().innerHTML =
      '<div class="drawer-backdrop" data-action="drawer-close"></div>' +
      '<aside class="drawer-panel">' +
        '<div class="drawer-head"><div><div class="drawer-title">' + esc(title || 'Товар') + '</div><div class="panel-note">' + (ed.isCustom ? 'Ручная карточка' : 'Базовый товар с override') + '</div></div><button class="btn ghost small" type="button" data-action="drawer-close">Закрыть</button></div>' +
        '<form id="product-editor-form">' +
          '<div class="drawer-body">' +
            '<div id="editor-preview">' + renderEditorPreview(e, ed.photos) + '</div>' +
            '<div class="form-grid">' +
              '<label class="field"><span>SKU</span><input class="input" name="sku" value="' + esc(product.sku || '') + '"' + (!ed.isNew ? ' readonly' : '') + ' required></label>' +
              '<label class="field"><span>Название</span><input class="input" name="model" value="' + esc(e.model) + '" required></label>' +
              '<label class="field"><span>Бренд</span><input class="input" name="brand" value="' + esc(e.brand || 'Ручная работа') + '"></label>' +
              '<label class="field"><span>Цена</span><input class="input" name="price" type="number" min="0" value="' + esc(e.price) + '"></label>' +
              '<label class="field"><span>Наличие</span><select class="select" name="stock"><option value="in_stock"' + (e.stock === 'in_stock' ? ' selected' : '') + '>В наличии</option><option value="out_of_stock"' + (e.stock === 'out_of_stock' ? ' selected' : '') + '>Нет в наличии</option></select></label>' +
              '<label class="field"><span>Размер</span><input class="input" name="size" value="' + esc(e.size) + '"></label>' +
              (ed.isCustom ? customProductFields(product) : overrideProductFields(e)) +
              '<label class="field wide"><span>Краткое описание</span><input class="input" name="descShort" value="' + esc(e.descShort) + '"></label>' +
              '<label class="field wide"><span>Полное описание</span><textarea class="textarea" name="descLong">' + esc(e.descLong) + '</textarea></label>' +
              '<label class="field wide"><span>Преимущества</span><textarea class="textarea" name="benefits">' + esc((e.benefits || []).join('\n')) + '</textarea></label>' +
              '<div class="field wide"><span>Фотографии</span><div class="photo-manager"><div class="upload-box">Можно загрузить до 12 изображений на карточку.<input id="editor-photo-input" type="file" accept="image/*" multiple><div class="split-line"></div><input class="input" id="manual-photo-name" placeholder="Имя файла из assets/img/products"><button class="btn ghost small" type="button" data-action="photo-manual">Добавить по имени</button></div><div id="photo-manager">' + renderPhotoManager() + '</div></div></div>' +
            '</div>' +
          '</div>' +
          '<div class="drawer-foot">' +
            '<label class="switch"><input type="checkbox" name="active"' + (productActive(product) ? ' checked' : '') + '><span></span></label>' +
            '<div class="filters"><button class="btn ghost" type="button" data-action="drawer-close">Отмена</button><button class="btn primary" type="submit">Сохранить</button></div>' +
          '</div>' +
        '</form>' +
      '</aside>';
    drawerEl().classList.add('show');
  }

  function customProductFields(product){
    return '<label class="field"><span>Группа</span><select class="select" name="group"><option value="venki"' + (product.group === 'venki' ? ' selected' : '') + '>Венки</option><option value="korzinki"' + (product.group === 'korzinki' ? ' selected' : '') + '>Корзинки</option></select></label>' +
      '<label class="field"><span>Серия</span><input class="input" name="series" value="' + esc(product.series || '') + '"></label>' +
      '<label class="field wide"><span>Текст на карточке</span><input class="input" name="cardBenef" value="' + esc(product.cardBenef || '') + '"></label>';
  }

  function overrideProductFields(e){
    return '<label class="field"><span>Габариты</span><input class="input" name="dimensions" value="' + esc(e.dimensions) + '"></label>' +
      '<label class="field"><span>Бейдж</span><select class="select" name="badge"><option value="">Без бейджа</option><option value="new"' + (e.badge === 'new' ? ' selected' : '') + '>Новинка</option><option value="sale"' + (e.badge === 'sale' ? ' selected' : '') + '>Акция</option><option value="clearance"' + (e.badge === 'clearance' ? ' selected' : '') + '>Распродажа</option></select></label>' +
      '<label class="field wide"><span>Текст бейджа</span><input class="input" name="badge_label" value="' + esc(e.badgeLabel) + '"></label>';
  }

  function renderEditorPreview(e, photos){
    var mainPhoto = photos && photos.length ? photos[0] : '';
    return '<section class="editor-preview-card">' +
      '<div class="editor-preview-photo">' + (mainPhoto ? '<img src="' + imgUrl(mainPhoto, 520) + '" alt="' + esc(e.model) + '">' : '<div>Нет фото</div>') + '</div>' +
      '<div class="editor-preview-info">' +
        '<div class="panel-note">Предпросмотр на витрине</div>' +
        '<div class="editor-preview-title">' + esc(e.model || 'Новый товар') + '</div>' +
        '<div class="editor-preview-desc">' + esc(e.descShort || e.descLong || 'Краткое описание появится здесь') + '</div>' +
        '<div class="editor-preview-meta">' + statusPill(e.stock) + '<span class="price">' + money(e.price) + '</span><span class="pill">' + (photos.length || 0) + ' фото</span></div>' +
      '</div>' +
    '</section>';
  }

  function renderPhotoManager(){
    var photos = state.editor ? state.editor.photos : [];
    if(!photos.length) return '<div class="empty">Фотографии не добавлены</div>';
    return '<div class="photo-strip">' + photos.map(function(photo, index){
      return '<div class="photo-card">' +
        '<img src="' + imgUrl(photo, 240) + '" alt="">' +
        (index === 0 ? '<div class="main-badge">Основное фото</div>' : '<div class="photo-count">' + esc(photo) + '</div>') +
        '<div class="photo-actions">' +
          '<button class="btn ghost" type="button" data-action="photo-up" data-index="' + index + '">Вверх</button>' +
          '<button class="btn ghost" type="button" data-action="photo-down" data-index="' + index + '">Вниз</button>' +
          '<button class="btn ghost" type="button" data-action="photo-remove" data-index="' + index + '">Убрать</button>' +
        '</div>' +
      '</div>';
    }).join('') + '</div>';
  }

  function refreshPhotoManager(){
    var el = document.getElementById('photo-manager');
    if(el) el.innerHTML = renderPhotoManager();
    refreshEditorPreview(document.getElementById('product-editor-form'));
  }

  function refreshEditorPreview(form){
    var preview = document.getElementById('editor-preview');
    if(!preview || !state.editor) return;
    var e = effectiveProduct(state.editor.product);
    if(form) {
      e.model = form.model ? form.model.value.trim() : e.model;
      e.brand = form.brand ? form.brand.value.trim() : e.brand;
      e.price = form.price ? Number(form.price.value || 0) : e.price;
      e.stock = form.stock ? form.stock.value : e.stock;
      e.size = form.size ? form.size.value.trim() : e.size;
      e.descShort = form.descShort ? form.descShort.value.trim() : e.descShort;
      e.descLong = form.descLong ? form.descLong.value.trim() : e.descLong;
    }
    preview.innerHTML = renderEditorPreview(e, state.editor.photos);
  }

  async function uploadEditorPhotos(input){
    if(!state.editor || !input.files || !input.files.length) return;
    var remaining = 12 - state.editor.photos.length;
    if(remaining <= 0) {
      toast('В карточке уже 12 фотографий', true);
      input.value = '';
      return;
    }
    try {
      for(var i = 0; i < Math.min(input.files.length, remaining); i++) {
        var fd = new FormData();
        fd.append('photo', input.files[i]);
        var data = await fetchJson(apiBase + 'upload_photo', {method:'POST', body:fd});
        if(data.filename && state.editor.photos.indexOf(data.filename) === -1) state.editor.photos.push(data.filename);
      }
      refreshPhotoManager();
      toast(input.files.length > remaining ? 'Загружено ' + remaining + ' фото из ' + input.files.length : 'Фотографии загружены');
    } catch(e) { toast(e.message, true); }
    input.value = '';
  }

  function addManualPhoto(){
    var input = document.getElementById('manual-photo-name');
    if(!input || !state.editor) return;
    var value = input.value.trim();
    if(!value) return;
    if(state.editor.photos.length >= 12) return toast('В карточке уже 12 фотографий', true);
    if(!/\.(jpe?g|png|webp|gif)$/i.test(value)) return toast('Укажите файл изображения', true);
    if(state.editor.photos.indexOf(value) === -1) state.editor.photos.push(value);
    input.value = '';
    refreshPhotoManager();
  }

  function removeEditorPhoto(index){
    if(!state.editor) return;
    state.editor.photos.splice(index, 1);
    refreshPhotoManager();
  }

  function moveEditorPhoto(index, delta){
    if(!state.editor) return;
    var next = index + delta;
    if(next < 0 || next >= state.editor.photos.length) return;
    var tmp = state.editor.photos[index];
    state.editor.photos[index] = state.editor.photos[next];
    state.editor.photos[next] = tmp;
    refreshPhotoManager();
  }

  async function saveProductEditor(form){
    if(!state.editor) return;
    var ed = state.editor;
    var sku = form.sku.value.trim();
    if(!sku) return toast('Укажите SKU', true);
    var benefits = form.benefits.value.trim();
    try {
      if(ed.isCustom) {
        await api('custom_product_save', {method:'POST', body:{
          sku:sku,
          model:form.model.value.trim(),
          brand:form.brand.value.trim() || 'Ручная работа',
          brandCode:'custom',
          series:form.series ? form.series.value.trim() : '',
          group:form.group ? form.group.value : 'venki',
          size:form.size.value.trim() || '-',
          price:Number(form.price.value || 0),
          stock:form.stock.value,
          descShort:form.descShort.value.trim(),
          cardBenef:form.cardBenef ? form.cardBenef.value.trim() : '',
          benefits_text:benefits,
          descLong:form.descLong.value.trim(),
          photos:ed.photos
        }});
      } else {
        await api('product_save_override', {method:'POST', body:{
          sku:sku,
          description:form.descShort.value.trim(),
          badge:form.badge ? form.badge.value : '',
          badge_label:form.badge_label ? form.badge_label.value.trim() : '',
          price_override:form.price.value,
          stock_override:form.stock.value,
          size_override:form.size.value.trim(),
          desc_short:form.descShort.value.trim(),
          model_override:form.model.value.trim(),
          brand_override:form.brand.value.trim(),
          dimensions:form.dimensions ? form.dimensions.value.trim() : '',
          desc_long_override:form.descLong.value.trim(),
          benefits_override:benefits,
          photos_override:ed.photos
        }});
      }
      await api('product_toggle_active', {method:'POST', body:{sku:sku, active:form.active.checked ? 1 : 0}});
      closeDrawer();
      toast('Товар сохранен');
      await renderProducts();
    } catch(e) { toast(e.message, true); }
  }

  function closeDrawer(){
    var drawer = drawerEl();
    if(drawer) { drawer.classList.remove('show'); drawer.innerHTML = ''; }
    state.editor = null;
  }

  async function deleteCustomProduct(sku){
    if(!confirm('Удалить ручной товар ' + sku + '?')) return;
    try {
      await api('custom_product_delete', {method:'POST', body:{sku:sku}});
      toast('Товар удален');
      await renderProducts();
    } catch(e) { toast(e.message, true); }
  }

  async function renderCarousel(){
    var data = await Promise.all([loadProducts(), api('carousel_get')]);
    state.carouselIds = data[1].ids || [];
    var set = {};
    state.carouselIds.forEach(function(id){ set[id] = true; });
    var activeProducts = state.products.filter(productActive);
    viewEl().innerHTML =
      '<section class="panel">' +
        '<div class="panel-head"><div><div class="panel-title">Карусель на главной</div><div class="panel-note">Выбрано: ' + state.carouselIds.length + ' · доступно активных: ' + activeProducts.length + '</div></div><button class="btn primary" type="button" data-action="carousel-save">Сохранить</button></div>' +
        '<div class="table-wrap"><table class="data-table product-table"><thead><tr><th>В ленте</th><th>Товар</th><th>Группа</th><th>Цена</th></tr></thead><tbody>' +
          activeProducts.map(function(product){
            var e = effectiveProduct(product);
            var photos = productPhotos(product);
            return '<tr><td><label class="switch"><input type="checkbox" data-carousel-id="' + esc(product.id) + '"' + (set[product.id] ? ' checked' : '') + '><span></span></label></td>' +
              '<td><div class="prod-cell">' + productImage(photos[0], e.model) + '<div><div class="prod-name">' + esc(e.model) + '</div><div class="prod-sku mono">' + esc(product.sku) + '</div></div></div></td>' +
              '<td><span class="pill">' + esc(product.group || '—') + '</span></td><td class="price nowrap">' + money(e.price) + '</td></tr>';
          }).join('') +
        '</tbody></table></div>' +
      '</section>';
  }

  async function saveCarousel(){
    var activeIds = {};
    state.products.filter(productActive).forEach(function(product){ activeIds[product.id] = true; });
    var ids = Array.prototype.slice.call(document.querySelectorAll('[data-carousel-id]:checked')).map(function(input){
      return input.getAttribute('data-carousel-id');
    }).filter(function(id){ return activeIds[id]; });
    try {
      await api('carousel_save', {method:'POST', body:{ids:ids}});
      state.carouselIds = ids;
      toast('Карусель сохранена');
      await renderCarousel();
    } catch(e) { toast(e.message, true); }
  }

  async function renderSettings(){
    var data = await api('settings_get');
    state.settings = data.settings || {};
    viewEl().innerHTML =
      '<section class="panel">' +
        '<div class="panel-head"><div><div class="panel-title">Настройки интеграций</div><div class="panel-note">Сохраняются в config.php и app_settings</div></div></div>' +
        '<form id="settings-form" class="form-grid">' +
          settingsField('BOT_TOKEN','Telegram bot token') +
          settingsField('CHAT_ID','Telegram chat id') +
          settingsField('TG_ADMIN_ID','Telegram admin id') +
          settingsField('EMAIL_TO','Email для заявок') +
          settingsField('CRON_SECRET','Секрет cron') +
          settingsField('WEBHOOK_SECRET','Webhook secret') +
          settingsField('ALLOWED_ORIGIN','Разрешенный origin') +
          '<label class="field wide"><span>Бонусная система</span><label class="switch"><input type="checkbox" name="bonuses_enabled"' + (String(state.settings.bonuses_enabled || '1') === '1' ? ' checked' : '') + '><span></span></label></label>' +
          '<div class="wide"><button class="btn primary" type="submit">Сохранить настройки</button></div>' +
        '</form>' +
      '</section>';
  }

  function settingsField(key, label){
    var secret = /TOKEN|SECRET/.test(key);
    return '<label class="field"><span>' + esc(label) + '</span><input class="input mono" type="' + (secret ? 'password' : 'text') + '" name="' + key + '" value="' + esc(state.settings[key] || '') + '"></label>';
  }

  async function saveSettings(form){
    var payload = {};
    ['BOT_TOKEN','CHAT_ID','TG_ADMIN_ID','EMAIL_TO','CRON_SECRET','WEBHOOK_SECRET','ALLOWED_ORIGIN'].forEach(function(key){
      payload[key] = form[key] ? form[key].value.trim() : '';
    });
    payload.bonuses_enabled = form.bonuses_enabled.checked ? '1' : '0';
    try {
      await api('settings_save', {method:'POST', body:payload});
      toast('Настройки сохранены');
    } catch(e) { toast(e.message, true); }
  }

  async function sendReport(button){
    var old = button.textContent;
    button.disabled = true;
    button.textContent = 'Отправляю';
    try {
      await api('send_report', {method:'POST'});
      toast('Отчет отправлен в Telegram');
    } catch(e) { toast(e.message, true); }
    button.disabled = false;
    button.textContent = old;
  }

  function toast(message, isError){
    var box = document.getElementById('toast');
    if(!box) return;
    var item = document.createElement('div');
    item.className = 'toast-item' + (isError ? ' error' : '');
    item.textContent = message;
    box.appendChild(item);
    setTimeout(function(){ item.remove(); }, 3600);
  }

  async function init(){
    try {
      var data = await auth('profile');
      if(!data.user || data.user.role !== 'admin') {
        renderLogin('Войдите под администратором.');
        return;
      }
      state.user = data.user;
      renderApp();
      await switchView('dashboard');
    } catch(e) {
      renderLogin();
    }
  }

  init();
})();
