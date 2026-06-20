<?php if (usuario()): ?>
    </main>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= ASSETS_URL ?>/js/app.js"></script>

<?php $_uSenha = usuario(); if ($_uSenha && !empty($_uSenha['must_change'])): ?>
<!-- Troca de senha obrigatória no primeiro acesso -->
<div class="modal fade" id="modalTrocarSenhaObrig" tabindex="-1"
     data-bs-backdrop="static" data-bs-keyboard="false" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header text-white border-0" style="background:linear-gradient(135deg,#004733,#2b6a4d)">
                <h5 class="modal-title fw-bold"><i class="bi bi-shield-lock me-2"></i><?= et('Defina sua nova senha') ?></h5>
            </div>
            <form method="POST" action="<?= BASE_URL ?>/trocar-senha.php" id="formTrocarSenhaObrig">
                <div class="modal-body">
                    <p class="text-muted small mb-3">
                        <i class="bi bi-info-circle me-1"></i>
                        <?= et('Por segurança, no primeiro acesso é necessário alterar sua senha antes de continuar.') ?>
                    </p>
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?= et('Nova Senha') ?> <span class="text-danger">*</span></label>
                        <input type="password" name="nova_senha" id="ts_nova" class="form-control form-control-lg"
                               placeholder="••••••" autocomplete="new-password" minlength="4" required>
                    </div>
                    <div class="mb-1">
                        <label class="form-label fw-semibold"><?= et('Confirmar Senha') ?> <span class="text-danger">*</span></label>
                        <input type="password" name="confirmar_senha" id="ts_conf" class="form-control form-control-lg"
                               placeholder="••••••" autocomplete="new-password" minlength="4" required>
                    </div>
                    <div id="ts_erro" class="text-danger small mt-2" style="display:none"></div>
                </div>
                <div class="modal-footer border-0">
                    <button type="submit" class="btn btn-primary w-100 btn-lg fw-semibold">
                        <i class="bi bi-check-lg me-1"></i><?= et('Salvar nova senha') ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var m = new bootstrap.Modal(document.getElementById('modalTrocarSenhaObrig'));
    m.show();
    document.getElementById('formTrocarSenhaObrig').addEventListener('submit', function (e) {
        var nova = document.getElementById('ts_nova').value;
        var conf = document.getElementById('ts_conf').value;
        var erro = document.getElementById('ts_erro');
        erro.style.display = 'none';
        if (nova.length < 4)      { e.preventDefault(); erro.textContent = <?= json_encode(t('A senha deve ter pelo menos 4 caracteres.')) ?>; erro.style.display = ''; return; }
        if (nova !== conf)        { e.preventDefault(); erro.textContent = <?= json_encode(t('As senhas não conferem.')) ?>; erro.style.display = ''; return; }
        if (nova === '123')       { e.preventDefault(); erro.textContent = <?= json_encode(t('Escolha uma senha diferente da senha padrão.')) ?>; erro.style.display = ''; return; }
    });
});
</script>
<?php endif; ?>
</body>
</html>
