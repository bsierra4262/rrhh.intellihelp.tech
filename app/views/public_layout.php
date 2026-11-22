<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'Verificación de documentos') ?> · Intellihelp</title>
<meta name="robots" content="noindex, nofollow, noarchive">
<meta name="referrer" content="no-referrer">
<link rel="icon" href="<?= url('/assets/img/favicon.png') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Saira:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= url('/assets/css/app.css') ?>?v=<?= filemtime(dirname(APP_ROOT) . '/public_html/assets/css/app.css') ?>">
<meta name="theme-color" content="#663399">
</head>
<body class="pub-body">
<header class="pub-top">
  <div class="pub-brand">
    <img src="<?= url('/assets/img/logo-ith-mark.png') ?>" alt="ITH" width="46" height="26">
    <span><b>Intellihelp SA</b><small>Verificación de documentos</small></span>
  </div>
</header>
<main class="pub-main">
  <?php foreach (take_flashes() as $f): ?>
    <div class="alert alert-<?= e($f['type']) ?>"><?= icon($f['type'] === 'error' ? 'x-circle' : 'info') ?><span><?= e($f['msg']) ?></span></div>
  <?php endforeach ?>
  <?= $content ?>
</main>
<footer class="pub-foot">
  <span><?= icon('shield', 14) ?> Conexión cifrada · No se guardan los archivos que se suben para verificar</span>
  <span>INTELLIHELP SA · CUIT 30-71140975-7</span>
</footer>
<script src="<?= url('/assets/js/ui.js') ?>?v=<?= filemtime(dirname(APP_ROOT) . '/public_html/assets/js/ui.js') ?>"></script>
<script src="<?= url('/assets/js/app.js') ?>?v=<?= filemtime(dirname(APP_ROOT) . '/public_html/assets/js/app.js') ?>"></script>
</body>
</html>
