<div class="checkout-card text-center">
  <div class="checkout-card-body" style="padding:40px 28px">

    <?php if ($pendente ?? false): ?>
    <!-- Aguardando confirmação do pagamento (ex: PIX) -->
    <div class="mb-4">
      <div style="width:72px;height:72px;background:#fef9c3;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto">
        <i class="bi bi-hourglass-split text-warning" style="font-size:2rem"></i>
      </div>
    </div>
    <h4 class="fw-bold mb-2">Aguardando confirmação…</h4>
    <p class="text-muted mb-4">Assim que o pagamento for confirmado, sua licença será ativada automaticamente.</p>
    <div id="statusPendente" class="badge-device mb-4">
      <div class="spinner-border spinner-border-sm text-warning me-2" role="status"></div>
      <span>Verificando pagamento…</span>
    </div>
    <script>
    (function poll() {
      const id  = <?= (int)($licencaId ?? 0) ?>;
      const h   = <?= json_encode($token ?? '') ?>;
      if (!id) return;
      fetch('/checkout/status?id=' + id + '&h=' + h)
        .then(r => r.json())
        .then(data => {
          if (data.status === 'ativa') {
            location.reload();
          } else {
            setTimeout(poll, 3000);
          }
        })
        .catch(() => setTimeout(poll, 5000));
    })();
    </script>

    <?php elseif (!empty($chave)): ?>
    <!-- Pagamento aprovado e licença ativa — mostra deep link -->
    <div class="mb-4">
      <div style="width:72px;height:72px;background:#d1fae5;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto">
        <i class="bi bi-check-lg text-success" style="font-size:2rem"></i>
      </div>
    </div>
    <h4 class="fw-bold mb-2">Pagamento aprovado!</h4>
    <p class="text-muted mb-3">Sua licença foi ativada com sucesso.</p>

    <div class="badge-device mb-4">
      <i class="bi bi-phone-fill text-success me-2"></i>
      <span>Licença vinculada ao seu dispositivo automaticamente</span>
    </div>

    <!-- Botão principal: abre o app e ativa a licença automaticamente -->
    <a href="emuladortelnet://payment/sucesso?chave=<?= htmlspecialchars($chave) ?>"
       class="btn-pay d-block mb-3" style="text-decoration:none">
      <i class="bi bi-phone-fill me-2"></i>Abrir ScanTE e ativar licença
    </a>

    <p class="text-muted" style="font-size:.82rem">
      Se o botão acima não abrir o app, copie a chave abaixo e use a opção
      <strong>"Ativar com chave"</strong> no ScanTE:
    </p>

    <div class="input-group mb-3" style="max-width:360px;margin:0 auto">
      <input type="text" id="chaveInput" class="form-control text-center fw-bold font-monospace"
             value="<?= htmlspecialchars($chave) ?>" readonly>
      <button class="btn btn-outline-secondary" type="button" onclick="copiarChave()">
        <i class="bi bi-clipboard"></i>
      </button>
    </div>
    <div id="copiadoMsg" class="text-success mb-3" style="display:none;font-size:.85rem">
      <i class="bi bi-check2"></i> Chave copiada!
    </div>

    <div class="security-note">
      Você receberá um e-mail de confirmação em breve.<br>
      Em caso de dúvidas, entre em contato com o suporte.
    </div>

    <script>
    function copiarChave() {
      const el = document.getElementById('chaveInput');
      navigator.clipboard?.writeText(el.value).catch(() => {
        el.select(); document.execCommand('copy');
      });
      document.getElementById('copiadoMsg').style.display = 'block';
    }
    // Tenta abrir o app automaticamente ao carregar a página
    window.addEventListener('load', function () {
      setTimeout(function () {
        window.location.href = 'emuladortelnet://payment/sucesso?chave=<?= htmlspecialchars($chave) ?>';
      }, 800);
    });
    </script>

    <?php else: ?>
    <!-- Sucesso genérico (sem chave disponível) -->
    <div class="mb-4">
      <div style="width:72px;height:72px;background:#d1fae5;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto">
        <i class="bi bi-check-lg text-success" style="font-size:2rem"></i>
      </div>
    </div>
    <h4 class="fw-bold mb-2">Pagamento aprovado!</h4>
    <p class="text-muted mb-4">Sua licença foi ativada com sucesso. Volte ao aplicativo ScanTE e continue usando normalmente.</p>
    <div class="badge-device mb-4">
      <i class="bi bi-phone-fill text-success me-2"></i>
      <span>Licença vinculada ao seu dispositivo automaticamente</span>
    </div>
    <div class="security-note">
      Você receberá um e-mail de confirmação em breve.<br>
      Em caso de dúvidas, entre em contato com o suporte.
    </div>
    <?php endif; ?>

  </div>
</div>
