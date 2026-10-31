(() => {
  const $ = (selector, root = document) => root.querySelector(selector);
  const loginView = $('#loginView');
  const appView = $('#appView');
  const content = $('#content');
  const notice = $('#notice');
  const loginError = $('#loginError');
  let csrfToken = '';
  let currentAdmin = null;
  let productsCache = [];
  let categoriesCache = [];
  let branchesCache = [];
  let activeView = 'products';

  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[char]));

  function showNotice(message, isError = false) {
    notice.textContent = message;
    notice.classList.toggle('error', isError);
    notice.hidden = !message;
  }

  function showLogin(message = '') {
    csrfToken = '';
    currentAdmin = null;
    appView.hidden = true;
    loginView.hidden = false;
    loginError.textContent = message;
  }

  function showApp() {
    loginView.hidden = true;
    appView.hidden = false;
    $('#adminLabel').textContent = currentAdmin ? currentAdmin.login + ' · ' + currentAdmin.role : '';
    showNotice('');
    renderView(activeView);
  }

  async function api(path, options = {}) {
    const method = (options.method || 'GET').toUpperCase();
    const headers = new Headers(options.headers || {});
    let body = options.body;
    const isForm = typeof FormData !== 'undefined' && body instanceof FormData;
    if (body && !isForm && typeof body !== 'string') {
      headers.set('Content-Type', 'application/json');
      body = JSON.stringify(body);
    }
    if (method !== 'GET' && csrfToken) headers.set('X-CSRF-Token', csrfToken);
    const response = await fetch('/api/v1' + path, {
      method,
      headers,
      body,
      credentials: 'same-origin',
      cache: 'no-store'
    });
    let payload = {};
    try { payload = await response.json(); } catch (_) {}
    if (!response.ok || payload.success === false) {
      const message = payload.error && payload.error.message ? payload.error.message : 'Ошибка запроса (' + response.status + ')';
      if (response.status === 401 && loginView.hidden) showLogin(message);
      throw new Error(message);
    }
    return payload.data || {};
  }

  function optionList(items, selected, emptyLabel = '— не выбрано —') {
    return '<option value="">' + esc(emptyLabel) + '</option>' + items.map((item) =>
      '<option value="' + esc(item.id) + '"' + (String(item.id) === String(selected ?? '') ? ' selected' : '') + '>' + esc(item.name) + '</option>'
    ).join('');
  }

  function tableOrEmpty(headers, rows, emptyText = 'Нет данных') {
    if (!rows.length) return '<div class="empty">' + esc(emptyText) + '</div>';
    return '<div class="table-wrap"><table><thead><tr>' + headers.map((h) => '<th>' + esc(h) + '</th>').join('') +
      '</tr></thead><tbody>' + rows.join('') + '</tbody></table></div>';
  }

  async function renderView(view) {
    activeView = view;
    showNotice('');
    document.querySelectorAll('#navigation button').forEach((button) => {
      button.classList.toggle('active', button.dataset.view === view);
    });
    content.innerHTML = '<div class="panel empty">Загрузка…</div>';
    try {
      if (view === 'products') await renderProducts();
      else if (view === 'categories') await renderCategories();
      else if (view === 'branches') await renderBranches();
      else if (view === 'stock') await renderStock();
      else if (view === 'imports') await renderImports();
      else if (view === 'reviews') await renderReviews();
    } catch (error) {
      content.innerHTML = '<div class="panel empty">' + esc(error.message) + '</div>';
      showNotice(error.message, true);
    }
  }

  async function loadLookups() {
    const [categories, branches] = await Promise.all([
      api('/admin/categories'),
      api('/admin/branches')
    ]);
    categoriesCache = categories.items || [];
    branchesCache = branches.items || [];
  }

  async function renderProducts(search = '') {
    await loadLookups();
    const query = search || '';
    const data = await api('/admin/products?per_page=100&q=' + encodeURIComponent(query));
    productsCache = data.items || [];
    const rows = productsCache.map((product) => '<tr>' +
      '<td><strong>' + esc(product.name) + '</strong><span class="muted">' + esc(product.slug) + '</span></td>' +
      '<td>' + esc(product.sku || '—') + '<span class="muted">' + esc(product.barcode || '') + '</span></td>' +
      '<td>' + esc(product.category_name || '—') + '</td>' +
      '<td>' + (Number(product.is_active) ? 'Активен' : 'Отключён') + '</td>' +
      '<td><button class="secondary" data-edit-product="' + esc(product.id) + '">Изменить</button></td></tr>');
    content.innerHTML = '<div class="columns">' +
      '<section class="panel"><div class="panel-head"><h2 id="productFormTitle">Новый товар</h2><button class="ghost" id="resetProduct">Очистить</button></div>' +
      '<form id="productForm" class="stack">' +
      '<input type="hidden" name="id">' +
      '<label>Наименование<input name="name" required maxlength="255"></label>' +
      '<label>URL-часть (slug)<input name="slug" maxlength="270"></label>' +
      '<label>Артикул<input name="sku" maxlength="100"></label>' +
      '<label>Штрихкод<input name="barcode" maxlength="64"></label>' +
      '<label>Единица измерения<input name="unit" value="шт." maxlength="30"></label>' +
      '<label>Категория<select name="category_id">' + optionList(categoriesCache, '') + '</select></label>' +
      '<label>Ссылка на изображение (HTTPS)<input name="image_url" maxlength="500"></label>' +
      '<label>Описание<textarea name="description" maxlength="20000"></textarea></label>' +
      '<label class="checkbox-row"><input type="checkbox" name="is_active" checked> Активен</label>' +
      '<button class="primary" type="submit">Сохранить товар</button></form></section>' +
      '<section class="panel"><div class="panel-head"><h2>Товары</h2><form id="productSearch" class="inline-controls"><label>Поиск<input name="q" value="' + esc(query) + '" placeholder="Название, артикул, штрихкод"></label><button class="secondary" type="submit">Найти</button></form></div>' +
      tableOrEmpty(['Товар', 'Идентификаторы', 'Категория', 'Статус', ''], rows) + '</section></div>';

    const form = $('#productForm');
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const fd = new FormData(form);
      const body = Object.fromEntries(fd.entries());
      body.is_active = form.elements.is_active.checked;
      try {
        await api('/admin/products/save', { method: 'POST', body });
        showNotice('Карточка товара сохранена.');
        await renderProducts(query);
      } catch (error) { showNotice(error.message, true); }
    });
    $('#resetProduct').addEventListener('click', () => {
      form.reset();
      form.elements.id.value = '';
      $('#productFormTitle').textContent = 'Новый товар';
    });
    $('#productSearch').addEventListener('submit', (event) => {
      event.preventDefault();
      renderProducts(new FormData(event.currentTarget).get('q'));
    });
    content.querySelectorAll('[data-edit-product]').forEach((button) => button.addEventListener('click', () => {
      const item = productsCache.find((p) => String(p.id) === button.dataset.editProduct);
      if (!item) return;
      form.elements.id.value = item.id;
      form.elements.name.value = item.name || '';
      form.elements.slug.value = item.slug || '';
      form.elements.sku.value = item.sku || '';
      form.elements.barcode.value = item.barcode || '';
      form.elements.unit.value = item.unit || 'шт.';
      form.elements.category_id.value = item.category_id || '';
      form.elements.image_url.value = item.image_url || '';
      form.elements.description.value = item.description || '';
      form.elements.is_active.checked = Number(item.is_active) === 1;
      $('#productFormTitle').textContent = 'Изменить товар #' + item.id;
      form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }));
  }

  async function renderCategories() {
    await loadLookups();
    const rows = categoriesCache.map((item) => '<tr><td><strong>' + esc(item.name) + '</strong><span class="muted">' + esc(item.slug) + '</span></td>' +
      '<td>' + esc(item.parent_name || '—') + '</td><td>' + (Number(item.is_active) ? 'Активна' : 'Отключена') + '</td>' +
      '<td><button class="secondary" data-edit-category="' + esc(item.id) + '">Изменить</button></td></tr>');
    content.innerHTML = '<div class="columns"><section class="panel"><div class="panel-head"><h2 id="categoryFormTitle">Новая категория</h2><button class="ghost" id="resetCategory">Очистить</button></div>' +
      '<form id="categoryForm" class="stack"><input type="hidden" name="id">' +
      '<label>Название<input name="name" required maxlength="180"></label><label>Slug<input name="slug" maxlength="180"></label>' +
      '<label>Родительская категория<select name="parent_id">' + optionList(categoriesCache, '') + '</select></label>' +
      '<label>Изображение (HTTPS)<input name="image_url" maxlength="500"></label><label>Порядок сортировки<input name="sort_order" type="number" value="0"></label>' +
      '<label class="checkbox-row"><input type="checkbox" name="is_active" checked> Активна</label><button class="primary" type="submit">Сохранить категорию</button></form></section>' +
      '<section class="panel"><h2>Категории</h2>' + tableOrEmpty(['Категория', 'Родитель', 'Статус', ''], rows) + '</section></div>';
    const form = $('#categoryForm');
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const body = Object.fromEntries(new FormData(form).entries());
      body.is_active = form.elements.is_active.checked;
      try { await api('/admin/categories/save', { method: 'POST', body }); showNotice('Категория сохранена.'); await renderCategories(); }
      catch (error) { showNotice(error.message, true); }
    });
    $('#resetCategory').addEventListener('click', () => { form.reset(); form.elements.id.value = ''; $('#categoryFormTitle').textContent = 'Новая категория'; });
    content.querySelectorAll('[data-edit-category]').forEach((button) => button.addEventListener('click', () => {
      const item = categoriesCache.find((x) => String(x.id) === button.dataset.editCategory);
      if (!item) return;
      form.elements.id.value = item.id; form.elements.name.value = item.name || ''; form.elements.slug.value = item.slug || '';
      form.elements.parent_id.value = item.parent_id || ''; form.elements.image_url.value = item.image_url || '';
      form.elements.sort_order.value = item.sort_order || 0; form.elements.is_active.checked = Number(item.is_active) === 1;
      $('#categoryFormTitle').textContent = 'Изменить категорию #' + item.id;
    }));
  }

  async function renderBranches() {
    await loadLookups();
    const rows = branchesCache.map((item) => '<tr><td><strong>' + esc(item.name) + '</strong><span class="muted">' + esc(item.slug) + '</span></td>' +
      '<td>' + esc(item.address || '—') + '<span class="muted">' + esc(item.phone || '') + '</span></td>' +
      '<td>' + (Number(item.is_active) ? 'Активен' : 'Отключён') + '</td>' +
      '<td><button class="secondary" data-edit-branch="' + esc(item.id) + '">Изменить</button></td></tr>');
    content.innerHTML = '<div class="columns"><section class="panel"><div class="panel-head"><h2 id="branchFormTitle">Новый магазин</h2><button class="ghost" id="resetBranch">Очистить</button></div>' +
      '<form id="branchForm" class="stack"><input type="hidden" name="id"><label>Название<input name="name" required maxlength="150"></label>' +
      '<label>Slug<input name="slug" maxlength="150"></label><label>Адрес<input name="address" maxlength="255"></label>' +
      '<label>Телефон<input name="phone" maxlength="40"></label><label>Порядок сортировки<input name="sort_order" type="number" value="0"></label>' +
      '<label class="checkbox-row"><input type="checkbox" name="is_active" checked> Активен</label><button class="primary" type="submit">Сохранить магазин</button></form></section>' +
      '<section class="panel"><h2>Магазины</h2>' + tableOrEmpty(['Магазин', 'Контакты', 'Статус', ''], rows) + '</section></div>';
    const form = $('#branchForm');
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const body = Object.fromEntries(new FormData(form).entries());
      body.is_active = form.elements.is_active.checked;
      try { await api('/admin/branches/save', { method: 'POST', body }); showNotice('Магазин сохранён.'); await renderBranches(); }
      catch (error) { showNotice(error.message, true); }
    });
    $('#resetBranch').addEventListener('click', () => { form.reset(); form.elements.id.value = ''; $('#branchFormTitle').textContent = 'Новый магазин'; });
    content.querySelectorAll('[data-edit-branch]').forEach((button) => button.addEventListener('click', () => {
      const item = branchesCache.find((x) => String(x.id) === button.dataset.editBranch);
      if (!item) return;
      form.elements.id.value = item.id; form.elements.name.value = item.name || ''; form.elements.slug.value = item.slug || '';
      form.elements.address.value = item.address || ''; form.elements.phone.value = item.phone || '';
      form.elements.sort_order.value = item.sort_order || 0; form.elements.is_active.checked = Number(item.is_active) === 1;
      $('#branchFormTitle').textContent = 'Изменить магазин #' + item.id;
    }));
  }

  async function renderStock() {
    await loadLookups();
    const productData = await api('/admin/products?per_page=100');
    productsCache = productData.items || [];
    const defaultBranch = branchesCache[0] ? branchesCache[0].id : '';
    content.innerHTML = '<section class="panel"><div class="panel-head"><h2>Цены и остатки</h2></div>' +
      '<div class="inline-controls"><label>Магазин<select id="stockBranch">' + optionList(branchesCache, defaultBranch, 'Выберите магазин') + '</select></label></div>' +
      '<form id="stockForm" class="inline-controls"><label>Товар<select name="product_id" required>' + optionList(productsCache, '') + '</select></label>' +
      '<label>Цена<input class="row-input" name="price" inputmode="decimal" required placeholder="0,00"></label>' +
      '<label>Остаток<input class="row-input" name="quantity" inputmode="decimal" required placeholder="0"></label>' +
      '<button class="primary" type="submit">Сохранить цену и остаток</button></form></section>' +
      '<section class="panel"><h2>Текущие остатки</h2><div id="stockTable" class="empty">Выберите магазин</div></section>';
    const branchSelect = $('#stockBranch');
    async function loadStockRows() {
      if (!branchSelect.value) { $('#stockTable').innerHTML = '<div class="empty">Выберите магазин</div>'; return; }
      const data = await api('/admin/stock?branch_id=' + encodeURIComponent(branchSelect.value));
      const rows = (data.items || []).map((item) => '<tr><td><strong>' + esc(item.product_name) + '</strong><span class="muted">' + esc(item.sku || item.barcode || '') + '</span></td>' +
        '<td>' + esc(item.price ?? '—') + '</td><td>' + esc(item.quantity) + '</td><td><button class="secondary" data-stock-product="' + esc(item.product_id) + '">В форму</button></td></tr>');
      $('#stockTable').innerHTML = tableOrEmpty(['Товар', 'Цена', 'Остаток', ''], rows);
      $('#stockTable').querySelectorAll('[data-stock-product]').forEach((button) => button.addEventListener('click', () => {
        const item = data.items.find((x) => String(x.product_id) === button.dataset.stockProduct);
        if (!item) return;
        $('#stockForm').elements.product_id.value = item.product_id;
        $('#stockForm').elements.price.value = item.price ?? '';
        $('#stockForm').elements.quantity.value = item.quantity ?? '';
        $('#stockForm').scrollIntoView({ behavior: 'smooth', block: 'center' });
      }));
    }
    branchSelect.addEventListener('change', () => loadStockRows().catch((e) => showNotice(e.message, true)));
    $('#stockForm').addEventListener('submit', async (event) => {
      event.preventDefault();
      if (!branchSelect.value) { showNotice('Сначала выберите магазин.', true); return; }
      const body = Object.fromEntries(new FormData(event.currentTarget).entries());
      body.branch_id = Number(branchSelect.value);
      try { await api('/admin/stock/save', { method: 'POST', body }); showNotice('Цена и остаток сохранены.'); await loadStockRows(); }
      catch (error) { showNotice(error.message, true); }
    });
    await loadStockRows();
  }

  async function renderImports() {
    await loadLookups();
    const defaultBranch = branchesCache[0] ? branchesCache[0].id : '';
    content.innerHTML = '<section class="panel"><h2>Загрузка Excel</h2><form id="importForm" class="stack">' +
      '<label>Магазин<select name="branch_id" required>' + optionList(branchesCache, defaultBranch, 'Выберите магазин') + '</select></label>' +
      '<div class="file-box"><label>Файл .xlsx (до 5 МБ)<input type="file" name="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required></label>' +
      '<p class="hint">Каждый файл относится только к выбранному магазину. Одинаковый успешно обработанный файл повторно не импортируется.</p></div>' +
      '<button class="primary" type="submit">Загрузить и обработать</button></form></section>' +
      '<section class="panel"><div class="panel-head"><h2>История импортов</h2><button id="refreshImports" class="secondary">Обновить</button></div><div id="importTable" class="empty">Загрузка…</div></section>';
    async function loadHistory() {
      const branchId = $('#importForm').elements.branch_id.value;
      const data = await api('/admin/imports' + (branchId ? '?branch_id=' + encodeURIComponent(branchId) : ''));
      const rows = (data.items || []).map((item) => '<tr><td>#' + esc(item.id) + '<span class="muted">' + esc(item.original_filename) + '</span></td>' +
        '<td>' + esc(item.branch_name) + '</td><td>' + esc(item.status) + '</td><td>' + esc(item.rows_total) + '</td>' +
        '<td>' + esc(item.rows_created) + ' / ' + esc(item.rows_updated) + ' / ' + esc(item.rows_errors) + '</td>' +
        '<td><button class="secondary" data-import-errors="' + esc(item.id) + '">Ошибки</button></td></tr>');
      $('#importTable').innerHTML = tableOrEmpty(['Импорт', 'Магазин', 'Статус', 'Строк', 'Создано / изменено / ошибок', ''], rows);
      $('#importTable').querySelectorAll('[data-import-errors]').forEach((button) => button.addEventListener('click', async () => {
        try {
          const errors = await api('/admin/import-errors?import_id=' + encodeURIComponent(button.dataset.importErrors));
          const lines = (errors.errors || []).map((e) => 'Строка ' + e.row_number + ': ' + e.message);
          showNotice(lines.length ? lines.join(' · ') : 'Ошибок строк нет.');
        } catch (error) { showNotice(error.message, true); }
      }));
    }
    $('#importForm').addEventListener('submit', async (event) => {
      event.preventDefault();
      const formData = new FormData(event.currentTarget);
      try {
        const result = await api('/admin/imports', { method: 'POST', body: formData });
        showNotice(result.duplicate ? 'Этот файл уже был успешно импортирован.' : 'Импорт завершён: строк ' + result.rows_total + ', создано ' + result.rows_created + ', обновлено ' + result.rows_updated + ', ошибок ' + result.rows_errors + '.');
        event.currentTarget.elements.file.value = '';
        await loadHistory();
      } catch (error) { showNotice(error.message, true); }
    });
    $('#refreshImports').addEventListener('click', () => loadHistory().catch((e) => showNotice(e.message, true)));
    $('#importForm').elements.branch_id.addEventListener('change', () => loadHistory().catch((e) => showNotice(e.message, true)));
    await loadHistory();
  }

  async function renderReviews() {
    const data = await api('/admin/reviews?status=pending&per_page=100');
    const rows = (data.items || []).map((item) => '<tr><td><strong>' + esc(item.product_name) + '</strong><span class="muted">Отзыв #' + esc(item.id) + ' · ' + esc(item.user_name || 'Пользователь') + '</span></td>' +
      '<td>' + esc(item.rating) + ' / 5</td><td>' + esc(item.body) + '</td><td><div class="actions">' +
      '<button class="success" data-review-action="published" data-review-id="' + esc(item.id) + '">Опубликовать</button>' +
      '<button class="danger" data-review-action="rejected" data-review-id="' + esc(item.id) + '">Отклонить</button></div></td></tr>');
    content.innerHTML = '<section class="panel"><div class="panel-head"><h2>Отзывы на модерации</h2><button id="refreshReviews" class="secondary">Обновить</button></div>' +
      tableOrEmpty(['Товар и автор', 'Оценка', 'Текст', 'Действие'], rows, 'Нет отзывов, ожидающих модерации.') + '</section>';
    content.querySelectorAll('[data-review-action]').forEach((button) => button.addEventListener('click', async () => {
      try {
        await api('/admin/reviews/moderate', {
          method: 'POST',
          body: { review_id: Number(button.dataset.reviewId), status: button.dataset.reviewAction }
        });
        showNotice(button.dataset.reviewAction === 'published' ? 'Отзыв опубликован.' : 'Отзыв отклонён.');
        await renderReviews();
      } catch (error) { showNotice(error.message, true); }
    }));
    $('#refreshReviews').addEventListener('click', () => renderReviews().catch((e) => showNotice(e.message, true)));
  }

  $('#loginForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    loginError.textContent = '';
    const form = new FormData(event.currentTarget);
    try {
      const csrf = await api('/admin/csrf');
      csrfToken = csrf.csrf_token || '';
      const result = await api('/admin/login', {
        method: 'POST',
        body: { login: form.get('login'), password: form.get('password') }
      });
      currentAdmin = result.admin;
      csrfToken = result.csrf_token || csrfToken;
      event.currentTarget.reset();
      showApp();
    } catch (error) {
      loginError.textContent = error.message;
    }
  });

  $('#logoutButton').addEventListener('click', async () => {
    try { await api('/admin/logout', { method: 'POST', body: {} }); } catch (_) {}
    showLogin('Вы вышли из панели.');
  });

  $('#navigation').addEventListener('click', (event) => {
    const button = event.target.closest('button[data-view]');
    if (button) renderView(button.dataset.view);
  });

  async function init() {
    try {
      const result = await api('/admin/me');
      currentAdmin = result.admin;
      csrfToken = result.csrf_token || '';
      showApp();
    } catch (_) {
      showLogin();
    }
  }
  init();
})();