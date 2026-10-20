<?php
$me = current_user();
$path = '/' . trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$unread = $me ? unread_count() : 0;
$pendingN = $me ? pending_count() : 0;
$active = function (string $prefix) use ($path): string {
    return ($prefix === '/' ? $path === '/' : str_starts_with($path, $prefix)) ? ' active' : '';
};
$nav = [
    ['/', 'home', 'Inicio', 0],
    ['/solicitudes/nueva', 'plus', 'Nueva solicitud', 0],
    ['/solicitudes', 'list', 'Mis solicitudes', 0],
    ['/calendario', 'calendar', 'Calendario', 0],
];
if (is_approver()) {
    $nav[] = ['/aprobaciones', 'check-circle', 'Por autorizar', $pendingN];
    $nav[] = ['/equipo', 'users', is_hr() ? 'Todas las licencias' : 'Mi equipo', 0];
}
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'Portal RRHH') ?> · RRHH Intellihelp</title>
<link rel="icon" href="<?= url('/assets/img/favicon.png') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Saira:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= url('/assets/css/app.css') ?>?v=<?= filemtime(dirname(APP_ROOT) . '/public_html/assets/css/app.css') ?>">
<meta name="theme-color" content="#663399">
</head>
<body>
<div class="shell">
  <aside class="sidebar" id="sidebar">
    <a class="brand" href="<?= url('/') ?>">
      <img src="<?= url('/assets/img/logo-ith-mark.png') ?>" alt="ITH" width="46" height="26">
      <span><b>Intellihelp</b><small>Recursos Humanos</small></span>
    </a>
    <nav class="nav">
      <?php foreach ($nav as [$href, $ic, $label, $badge]):
          // "Mis solicitudes" solo se marca en el detalle de una solicitud propia.
          $isActive = $href === '/solicitudes'
              ? ($path === '/solicitudes' || (preg_match('#^/solicitudes/\d+#', $path) && (int) ($r['user_id'] ?? 0) === uid()))
              : ($active($href) !== '');
      ?>
        <a href="<?= url($href) ?>" class="<?= $isActive ? 'active' : '' ?>"><?= icon($ic) ?><span><?= e($label) ?></span><?php if ($badge): ?><em class="count"><?= $badge ?></em><?php endif ?></a>
      <?php endforeach ?>
      <?php if (is_hr()): ?>
        <div class="nav-title">Administración</div>
        <?php $pendAcc = (int) val('SELECT COUNT(*) FROM users WHERE pending_approval = 1'); ?>
        <a href="<?= url('/admin/usuarios') ?>" class="<?= trim($active('/admin/usuarios')) ?>"><?= icon('user') ?><span>Personas</span><?php if ($pendAcc): ?><em class="count"><?= $pendAcc ?></em><?php endif ?></a>
        <a href="<?= url('/admin/tipos') ?>" class="<?= trim($active('/admin/tipos')) ?>"><?= icon('tag') ?><span>Tipos de licencia</span></a>
        <a href="<?= url('/admin/feriados') ?>" class="<?= trim($active('/admin/feriados')) ?>"><?= icon('flag') ?><span>Feriados</span></a>
        <a href="<?= url('/admin/configuracion') ?>" class="<?= trim($active('/admin/configuracion')) ?>"><?= icon('settings') ?><span>Configuración</span></a>
      <?php endif ?>
    </nav>
    <div class="sidebar-foot">
      <a href="<?= url('/perfil') ?>" class="me">
        <?= avatar($me['name']) ?>
        <span><b><?= e($me['name']) ?></b><small><?= e(ROLE_LABELS[$me['role']]) ?></small></span>
      </a>
      <form method="post" action="<?= url('/logout') ?>"><?= csrf_field() ?><button class="icon-btn" title="Cerrar sesión" aria-label="Cerrar sesión"><?= icon('logout') ?></button></form>
    </div>
  </aside>
  <div class="scrim" data-close-nav></div>

  <div class="main">
    <header class="topbar">
      <button class="icon-btn menu-btn" data-toggle-nav aria-label="Menú"><?= icon('menu', 22) ?></button>
      <div class="topbar-title"><?= e($title ?? '') ?></div>
      <div class="topbar-actions">
        <a class="btn btn-primary btn-sm hide-sm" href="<?= url('/solicitudes/nueva') ?>"><?= icon('plus', 16) ?> Nueva solicitud</a>
        <a class="icon-btn bell" href="<?= url('/notificaciones') ?>" aria-label="Notificaciones"><?= icon('bell', 20) ?><?php if ($unread): ?><em><?= $unread > 9 ? '9+' : $unread ?></em><?php endif ?></a>
      </div>
    </header>
    <main class="content">
      <?php foreach (take_flashes() as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?>" role="status"><?= icon(match ($f['type']) { 'success' => 'check-circle', 'error' => 'x-circle', 'warning' => 'alert', default => 'info' }) ?><span><?= e($f['msg']) ?></span><button class="alert-x" data-dismiss aria-label="Cerrar"><?= icon('x', 16) ?></button></div>
      <?php endforeach ?>
      <?= $content ?>
    </main>
  </div>
</div>
<script src="<?= url('/assets/js/app.js') ?>?v=<?= filemtime(dirname(APP_ROOT) . '/public_html/assets/js/app.js') ?>"></script>
</body>
</html>
