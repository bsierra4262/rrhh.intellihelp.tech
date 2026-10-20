<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'Iniciar sesión') ?> · Capital Humano · Intellihelp</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="<?= url('/assets/img/favicon.png') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Saira:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= url('/assets/css/app.css') ?>?v=<?= filemtime(dirname(APP_ROOT) . '/public_html/assets/css/app.css') ?>">
<meta name="theme-color" content="#663399">
</head>
<body class="auth-body">
<div class="auth">
  <section class="auth-hero">
    <header class="auth-brand">
      <img src="<?= url('/assets/img/logo-ith-mark.png') ?>" alt="ITH" class="auth-logo">
      <span>Intellihelp<small>Capital Humano</small></span>
    </header>

    <div class="auth-copy">
      <span class="auth-eyebrow">Portal de Recursos Humanos</span>
      <h1>Gestión de licencias<br><span>y ausencias</span></h1>
      <p>Plataforma interna para solicitar, aprobar y registrar vacaciones, licencias por estudio, enfermedad y demás ausencias, con circuitos de aprobación definidos y trazabilidad completa.</p>

      <ul class="auth-features">
        <li>
          <span class="af-ic"><?= icon('check-circle', 20) ?></span>
          <div><b>Circuito de aprobación</b><small>Cada solicitud llega a quien autoriza por Google Chat y queda registrada en Google Calendar.</small></div>
        </li>
        <li>
          <span class="af-ic"><?= icon('file', 20) ?></span>
          <div><b>Documentación respaldatoria</b><small>Certificados médicos y constancias resguardados y vinculados a cada licencia.</small></div>
        </li>
        <li>
          <span class="af-ic"><?= icon('chart', 20) ?></span>
          <div><b>Vacaciones conforme a la LCT</b><small>Días disponibles calculados según antigüedad, siempre actualizados.</small></div>
        </li>
      </ul>
    </div>

    <svg class="auth-waves" viewBox="0 0 220 160" aria-hidden="true"><g fill="none" stroke="#ffffff" stroke-width="6" stroke-linecap="round"><path d="M10 70a140 140 0 0 1 200 0"/><path d="M45 105a90 90 0 0 1 130 0"/><path d="M80 138a40 40 0 0 1 60 0"/></g></svg>
    <footer class="auth-foot">
      <span><?= icon('shield', 14) ?> Conexión cifrada · Acceso exclusivo para colaboradores</span>
      <span>© <?= date('Y') ?> Intellihelp</span>
    </footer>
  </section>

  <section class="auth-panel">
    <div class="auth-card">
      <?php foreach (take_flashes() as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?>"><?= icon($f['type'] === 'success' ? 'check-circle' : ($f['type'] === 'error' ? 'x-circle' : 'info')) ?><span><?= e($f['msg']) ?></span></div>
      <?php endforeach ?>
      <?= $content ?>
      <p class="auth-help">¿Problemas para ingresar? Escribí a <a href="mailto:<?= e(support_email()) ?>"><?= e(support_email()) ?></a></p>
    </div>
  </section>
</div>
<script src="<?= url('/assets/js/app.js') ?>"></script>
</body>
</html>
