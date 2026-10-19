<div class="page-head">
  <div><h1>Personas</h1><p class="muted"><?= count(array_filter($users, fn($u) => $u['active'])) ?> activas</p></div>
  <a class="btn btn-primary" href="<?= url('/admin/usuarios/nuevo') ?>"><?= icon('plus', 16) ?> Agregar persona</a>
</div>
<section class="card">
  <form class="filters" method="get">
    <label class="field sm grow"><span>Buscar</span><input name="q" value="<?= e($q) ?>" placeholder="Nombre, email o área"></label>
    <button class="btn btn-ghost btn-sm"><?= icon('search', 16) ?> Buscar</button>
  </form>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Persona</th><th>Rol</th><th>Empresa</th><th>Área</th><th>Responsable</th><th class="num">Vacaciones <?= date('Y') ?></th><th></th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr data-href="<?= url('/admin/usuarios/' . $u['id']) ?>" class="<?= $u['active'] ? '' : 'inactive' ?>">
          <td><div class="cell-person"><?= avatar($u['name'], 'sm') ?><span><b><?= e($u['name']) ?></b><small><?= e($u['email']) ?></small></span></div></td>
          <td><span class="role role-<?= e($u['role']) ?>"><?= e(ROLE_LABELS[$u['role']]) ?></span><?= $u['pending_approval'] ? ' <span class="badge badge-pending">Espera aprobación</span>' : ($u['pending_activation'] ? ' <span class="badge badge-pending">Sin confirmar</span>' : ($u['active'] ? '' : ' <span class="badge badge-cancelled">Inactiva</span>')) ?></td>
          <td><?= e($u['company'] ?: '—') ?></td>
          <td><?= e($u['department'] ?: '—') ?></td>
          <td><?= e($u['manager_name'] ?: 'RRHH') ?></td>
          <td class="num"><?= e(rtrim(rtrim(number_format($u['vac_used'], 1, ',', ''), '0'), ',')) ?> / <?= (int) $u['vac_total'] ?></td>
          <td><a class="icon-btn" href="<?= url('/admin/usuarios/' . $u['id']) ?>" aria-label="Editar"><?= icon('edit', 16) ?></a></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>
</section>
