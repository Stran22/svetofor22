<?php
declare(strict_types=1);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Svetofor22 — административная панель</title>
  <link rel="stylesheet" href="/admin/admin.css">
  <script src="/admin/admin.js" defer></script>
</head>
<body>
  <main class="shell">
    <section id="loginView" class="login-card" hidden>
      <div class="brand-mark">S22</div>
      <h1>Административная панель</h1>
      <p class="muted">Войдите под учётной записью администратора.</p>
      <form id="loginForm">
        <label>Логин<input name="login" autocomplete="username" required maxlength="100"></label>
        <label>Пароль<input name="password" type="password" autocomplete="current-password" required></label>
        <button class="primary" type="submit">Войти</button>
      </form>
      <p id="loginError" class="error" role="alert"></p>
    </section>

    <section id="appView" hidden>
      <header class="topbar">
        <div>
          <div class="brand">Svetofor22</div>
          <div class="muted small">Управление каталогом магазинов</div>
        </div>
        <div class="user-tools">
          <span id="adminLabel" class="muted small"></span>
          <button id="logoutButton" class="ghost">Выйти</button>
        </div>
      </header>
      <nav id="navigation" class="navigation" aria-label="Разделы панели">
        <button data-view="products" class="active">Товары</button>
        <button data-view="categories">Категории</button>
        <button data-view="branches">Магазины</button>
        <button data-view="stock">Цены и остатки</button>
        <button data-view="imports">Импорт Excel</button>
        <button data-view="reviews">Отзывы</button>
      </nav>
      <div id="notice" class="notice" role="status" hidden></div>
      <section id="content" class="content" aria-live="polite"></section>
    </section>
  </main>
</body>
</html>