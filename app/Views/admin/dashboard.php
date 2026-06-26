<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : ($flash['type'] === 'warning' ? 'warning' : 'danger') ?> alert-dismissible fade show">
  <?= $flash['message'] ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="d-flex align-items-center justify-content-between mb-4">
  <h4 class="fw-bold mb-0">Dashboard</h4>
  <a href="<?= APP_URL ?>/admin/licencas" class="btn btn-accent">
    <i class="bi bi-key me-1"></i>Gerenciar Licenças
  </a>
</div>

<!-- Estatísticas de licenças -->
<p class="text-muted small fw-semibold text-uppercase mb-2" style="letter-spacing:.8px">Licenças</p>
<div class="row g-3 mb-2">
  <div class="col-6 col-md-4 col-xl-2">
    <div class="card stat-card text-center h-100">
      <div class="value"><?= (int)($stats['total'] ?? 0) ?></div>
      <div class="label">Total</div>
    </div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="card stat-card text-center h-100">
      <div class="value text-success"><?= (int)($stats['vitalicias'] ?? 0) ?></div>
      <div class="label">Vitalícias</div>
    </div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="card stat-card text-center h-100">
      <div class="value text-warning"><?= (int)($stats['trial'] ?? 0) ?></div>
      <div class="label">Trial</div>
    </div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="card stat-card text-center h-100">
      <div class="value text-primary"><?= (int)($stats['mensal'] ?? 0) ?></div>
      <div class="label">Mensal</div>
    </div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="card stat-card text-center h-100">
      <div class="value text-info"><?= (int)($stats['anual'] ?? 0) ?></div>
      <div class="label">Anual</div>
    </div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="card stat-card text-center h-100">
      <div class="value text-secondary"><?= (int)($stats['expiradas'] ?? 0) ?></div>
      <div class="label">Expiradas</div>
    </div>
  </div>
</div>

<!-- Alertas e uso real -->
<div class="row g-3 mb-4">
  <?php if ((int)($stats['expirando_7d'] ?? 0) > 0): ?>
  <div class="col-6 col-md-4 col-xl-3">
    <div class="card stat-card text-center h-100 border-danger">
      <div class="value text-danger"><?= (int)$stats['expirando_7d'] ?></div>
      <div class="label">Expirando em 7 dias</div>
    </div>
  </div>
  <?php endif; ?>
  <div class="col-6 col-md-4 col-xl-3">
    <div class="card stat-card text-center h-100">
      <div class="value text-success"><?= (int)($stats['dispositivos_ativos'] ?? 0) ?></div>
      <div class="label">Apps em uso</div>
    </div>
  </div>
  <?php if ((int)($stats['revogadas'] ?? 0) > 0): ?>
  <div class="col-6 col-md-4 col-xl-3">
    <div class="card stat-card text-center h-100">
      <div class="value text-danger"><?= (int)$stats['revogadas'] ?></div>
      <div class="label">Revogadas</div>
    </div>
  </div>
  <?php endif; ?>
  <div class="col-6 col-md-4 col-xl-3">
    <div class="card stat-card text-center h-100">
      <div class="value text-primary"><?= (int)$empresas ?></div>
      <div class="label">Empresas</div>
    </div>
  </div>
</div>

<!-- Licenças recentes -->
<div class="card">
  <div class="card-body">
    <div class="d-flex align-items-center justify-content-between mb-3">
      <h6 class="fw-bold mb-0">Licenças Recentes</h6>
      <a href="<?= APP_URL ?>/admin/licencas" class="small text-muted">Ver todas →</a>
    </div>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead class="table-light">
          <tr><th>Chave</th><th>Empresa</th><th>Tipo</th><th>Status</th><th>Dispositivo</th><th>Expira</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($recentes as $l): ?>
          <tr>
            <td><code><?= $l['chave'] ?></code></td>
            <td><?= htmlspecialchars($l['empresa_nome'] ?? '—') ?></td>
            <td><?= ucfirst($l['tipo']) ?></td>
            <td><span class="badge badge-<?= $l['status'] ?>"><?= ucfirst($l['status']) ?></span></td>
            <td><?= $l['device_id'] ? '<i class="bi bi-phone-fill text-success"></i> Vinculado' : '<span class="text-muted">Livre</span>' ?></td>
            <td>
              <?php if ($l['tipo'] === 'vitalicia'): ?>
                <span class="text-success">∞</span>
              <?php elseif ($l['expira_em']): ?>
                <?= date('d/m/Y', strtotime($l['expira_em'])) ?>
              <?php else: ?>
                —
              <?php endif; ?>
            </td>
            <td><a href="<?= APP_URL ?>/admin/licencas/<?= $l['id'] ?>" class="btn btn-sm btn-outline-secondary">Ver</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
