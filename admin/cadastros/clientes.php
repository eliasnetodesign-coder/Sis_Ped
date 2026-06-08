<?php
require_once __DIR__ . '/../../config.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    try {
        $d = [
            $_POST['codigo_cliente'], $_POST['cnpj'], $_POST['cpf'], $_POST['razao_social'],
            $_POST['cep'], $_POST['endereco'], $_POST['numero'], $_POST['complemento'],
            $_POST['bairro'], $_POST['cidade'], $_POST['estado'], $_POST['pais'],
            $_POST['telefone1'], $_POST['telefone2'], $_POST['email'],
            $_POST['vendedor'], $_POST['canal_venda_id'] ?: null,
            (float)$_POST['desconto_cliente'], (float)$_POST['limite_credito'],
            $_POST['idioma'], $_POST['moeda'], $_POST['status'],
            min(4.0, max(0.0, (float)($_POST['bonus_desempenho'] ?? 0))),
            min(5,   max(0,   (int)  ($_POST['material_apoio']   ?? 0))),
        ];
        // Cap desconto_canal to canal's maximum
        $descanal = (float)($_POST['desconto_canal'] ?? 0);
        $canalId  = intval($_POST['canal_venda_id'] ?? 0);
        if ($canalId) {
            $capRow = db()->prepare('SELECT desconto FROM canal_venda WHERE id = ?');
            $capRow->execute([$canalId]);
            $capRow = $capRow->fetch();
            if ($capRow && $descanal > (float)$capRow['desconto']) {
                $descanal = (float)$capRow['desconto'];
            }
        }
        // Insert desconto_canal at position 18 (after desconto_cliente)
        array_splice($d, 18, 0, [$descanal]);
        if ($a === 'criar') {
            if (!$_POST['senha']) throw new Exception('Senha obrigatória.');
            $d[] = $_POST['senha'];
            db()->prepare('INSERT INTO clientes (codigo_cliente,cnpj,cpf,razao_social,cep,endereco,numero,complemento,bairro,cidade,estado,pais,telefone1,telefone2,email,vendedor,canal_venda_id,desconto_cliente,desconto_canal,limite_credito,idioma,moeda,status,bonus_desempenho,material_apoio,senha) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute($d);
            flash('success', 'Cliente criado com sucesso!');
        } elseif ($a === 'editar') {
            $d[] = (int)$_POST['id'];
            db()->prepare('UPDATE clientes SET codigo_cliente=?,cnpj=?,cpf=?,razao_social=?,cep=?,endereco=?,numero=?,complemento=?,bairro=?,cidade=?,estado=?,pais=?,telefone1=?,telefone2=?,email=?,vendedor=?,canal_venda_id=?,desconto_cliente=?,desconto_canal=?,limite_credito=?,idioma=?,moeda=?,status=?,bonus_desempenho=?,material_apoio=? WHERE id=?')->execute($d);
            if ($_POST['senha']) {
                db()->prepare('UPDATE clientes SET senha=? WHERE id=?')->execute([$_POST['senha'], (int)$_POST['id']]);
            }
            flash('success', 'Cliente atualizado!');
        } elseif ($a === 'excluir') {
            db()->prepare('DELETE FROM clientes WHERE id=?')->execute([(int)$_POST['id']]);
            flash('success', 'Cliente excluído!');
        } elseif ($a === 'importar') {
            $dados = json_decode($_POST['dados'] ?? '[]', true);
            if (!is_array($dados)) throw new Exception('Dados inválidos.');
            $ins = 0; $upd = 0; $skip = 0; $emailConflitos = 0;
            $seenEmails = [];
            foreach ($dados as $row) {
                $razao = trim($row['razao_social'] ?? '');
                if (!$razao) { $skip++; continue; }
                $cod    = trim($row['codigo_cliente'] ?? '');
                $cnpj   = trim($row['cnpj'] ?? '');
                $status = strtolower(trim($row['status'] ?? '')) === 'inativo' ? 'inativo' : 'ativo';
                // Verifica duplicata por código ou CNPJ
                $existing = false;
                if ($cod) {
                    $chk = db()->prepare('SELECT id FROM clientes WHERE codigo_cliente = ?');
                    $chk->execute([$cod]); $existing = $chk->fetchColumn();
                }
                if (!$existing && $cnpj) {
                    $chk = db()->prepare('SELECT id FROM clientes WHERE cnpj = ?');
                    $chk->execute([$cnpj]); $existing = $chk->fetchColumn();
                }
                // Resolve conflito de e-mail duplicado
                $email = trim($row['email'] ?? '');
                if ($email) {
                    if (isset($seenEmails[$email])) {
                        // Já usado neste lote — limpa para evitar violação de unique
                        $email = '';
                        $emailConflitos++;
                    } else {
                        $chk = db()->prepare('SELECT id FROM clientes WHERE email = ?');
                        $chk->execute([$email]);
                        $dbId = $chk->fetchColumn();
                        if ($dbId && $dbId != $existing) {
                            // E-mail pertence a outro cliente no banco — limpa
                            $email = '';
                            $emailConflitos++;
                        } else {
                            $seenEmails[$email] = true;
                        }
                    }
                }
                $f = [
                    'codigo_cliente' => $cod,
                    'cnpj'           => $cnpj,
                    'razao_social'   => $razao,
                    'cep'            => trim($row['cep']          ?? ''),
                    'endereco'       => trim($row['endereco']      ?? ''),
                    'numero'         => trim($row['numero']        ?? ''),
                    'complemento'    => trim($row['complemento']   ?? ''),
                    'bairro'         => trim($row['bairro']        ?? ''),
                    'cidade'         => trim($row['cidade']        ?? ''),
                    'estado'         => trim($row['estado']        ?? ''),
                    'pais'           => trim($row['pais']          ?? '') ?: 'Brasil',
                    'telefone1'      => trim($row['telefone1']     ?? ''),
                    'email'          => $email ?: null,
                    'vendedor'       => trim($row['vendedor']      ?? ''),
                    'status'         => $status,
                ];
                if ($existing) {
                    $cols = array_map(function($k){ return "$k=?"; }, array_keys($f));
                    $vals = array_values($f); $vals[] = $existing;
                    db()->prepare('UPDATE clientes SET ' . implode(',', $cols) . ' WHERE id=?')->execute($vals);
                    $upd++;
                } else {
                    $senha = str_pad(rand(0, 9999999), 8, '0', STR_PAD_LEFT);
                    db()->prepare('INSERT INTO clientes (codigo_cliente,cnpj,cpf,razao_social,cep,endereco,numero,complemento,bairro,cidade,estado,pais,telefone1,telefone2,email,vendedor,canal_venda_id,desconto_cliente,limite_credito,idioma,moeda,status,senha) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                        ->execute([$f['codigo_cliente'],$f['cnpj'],'',$f['razao_social'],$f['cep'],$f['endereco'],$f['numero'],$f['complemento'],$f['bairro'],$f['cidade'],$f['estado'],$f['pais'],$f['telefone1'],'',$f['email'],$f['vendedor'],null,0,0,'pt','BRL',$f['status'],$senha]);
                    $ins++;
                }
            }
            $msg = "Importação concluída: $ins inserido(s), $upd atualizado(s)";
            if ($skip > 0)           $msg .= ", $skip linha(s) sem Razão Social ignorada(s)";
            if ($emailConflitos > 0) $msg .= ", $emailConflitos e-mail(s) duplicado(s) ignorado(s)";
            flash('success', $msg . '.');
        }
    } catch (Exception $e) {
        flash('danger', 'Erro: ' . $e->getMessage());
    }
    $rp = array_filter([
        'q'      => trim($_POST['_q']      ?? ''),
        'status' => trim($_POST['_status'] ?? ''),
        'canal'  => trim($_POST['_canal']  ?? ''),
    ], fn($v) => $v !== '');
    header('Location: ' . BASE_URL . '/admin/cadastros/clientes.php' . ($rp ? '?' . http_build_query($rp) : ''));
    exit;
}

$u             = usuario();
$busca         = $_GET['q']      ?? '';
$filtro_status = $_GET['status'] ?? 'ativo';
$filtro_canal  = $_GET['canal']  ?? '';

$conditions = [];
$params     = [];

if ($u['tipo'] === 'vendedor') {
    $conditions[] = 'c.vendedor = ?';
    $params[] = $u['nome'];
}
if ($busca) {
    $conditions[] = '(c.razao_social LIKE ? OR c.cnpj LIKE ? OR c.email LIKE ?)';
    $params[] = "%$busca%"; $params[] = "%$busca%"; $params[] = "%$busca%";
}
if ($filtro_status) {
    $conditions[] = 'c.status = ?';
    $params[] = $filtro_status;
}
if ($filtro_canal) {
    $conditions[] = 'cv.canal = ?';
    $params[] = $filtro_canal;
}

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$clientes = db()->prepare("SELECT c.*, cv.canal FROM clientes c LEFT JOIN canal_venda cv ON cv.id=c.canal_venda_id $where ORDER BY c.razao_social");
$clientes->execute($params);
$clientes = $clientes->fetchAll();

$canais     = db()->query('SELECT * FROM canal_venda ORDER BY canal')->fetchAll();
$vendedores = db()->query("SELECT nome FROM usuarios WHERE tipo_acesso='vendedor' AND status='ativo' ORDER BY nome")->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'Cadastro de Clientes';
require_once LAYOUT_PATH . '/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-people me-2"></i>Cadastro de Clientes</h4>
    <div class="d-flex gap-2 flex-wrap">
        <button type="button" class="btn btn-outline-secondary" onclick="exportarClientes()">
            <i class="bi bi-file-earmark-excel me-1"></i>Exportar
        </button>
        <button class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#modalImport">
            <i class="bi bi-file-earmark-arrow-up me-1"></i>Importar Excel
        </button>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal" onclick="novoReg()">
            <i class="bi bi-plus-lg me-1"></i>Novo Cliente
        </button>
    </div>
</div>
<form class="mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-5">
            <label class="form-label fw-semibold small mb-1">Buscar</label>
            <input type="text" name="q" class="form-control" placeholder="Razão social, CNPJ ou e-mail..." value="<?= e($busca) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold small mb-1">Canal</label>
            <select name="canal" class="form-select">
                <option value="">Todos os canais</option>
                <?php foreach ($canais as $cv): ?>
                <option value="<?= e($cv['canal']) ?>" <?= $filtro_canal === $cv['canal'] ? 'selected' : '' ?>><?= e($cv['canal']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label fw-semibold small mb-1">Status</label>
            <select name="status" class="form-select">
                <option value="">Todos</option>
                <option value="ativo"   <?= $filtro_status === 'ativo'   ? 'selected' : '' ?>>Ativo</option>
                <option value="inativo" <?= $filtro_status === 'inativo' ? 'selected' : '' ?>>Inativo</option>
            </select>
        </div>
        <div class="col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-outline-primary w-100">Filtrar</button>
            <?php if (!empty($_GET)): ?>
            <a href="?" class="btn btn-outline-secondary" title="Limpar filtros"><i class="bi bi-x-lg"></i></a>
            <?php endif; ?>
        </div>
    </div>
</form>
<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr><th>Código</th><th>Razão Social</th><th>CNPJ/CPF</th><th>Cidade/UF</th><th>E-mail</th><th>Vendedor</th><th>Canal</th><th>Status</th><th>Ações</th></tr>
            </thead>
            <tbody>
            <?php if ($clientes): foreach ($clientes as $c): ?>
                <tr>
                    <td><?= e($c['codigo_cliente']) ?></td>
                    <td><strong><?= e($c['razao_social']) ?></strong></td>
                    <td><small><?= e($c['cnpj'] ?: $c['cpf']) ?></small></td>
                    <td><?= e($c['cidade']) ?><?= $c['estado'] ? '/' . e($c['estado']) : '' ?></td>
                    <td><small><?= e($c['email']) ?></small></td>
                    <td><?= e($c['vendedor']) ?></td>
                    <td><?= e($c['canal'] ?? '—') ?></td>
                    <td><?= statusBadge($c['status']) ?></td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary" onclick="editarReg(<?= htmlspecialchars(json_encode($c), ENT_QUOTES) ?>)"><i class="bi bi-pencil"></i></button>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Excluir cliente?')">
                            <input type="hidden" name="action" value="excluir">
                            <input type="hidden" name="id" value="<?= $c['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="9" class="text-center text-muted py-4">Nenhum cliente cadastrado.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" id="fa" value="criar">
                <input type="hidden" name="id" id="fi">
                <input type="hidden" name="_q" value="<?= e($busca) ?>">
                <input type="hidden" name="_status" value="<?= e($filtro_status) ?>">
                <input type="hidden" name="_canal" value="<?= e($filtro_canal) ?>">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="mt">Novo Cliente</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-2"><label class="form-label fw-semibold">Código</label><input type="text" name="codigo_cliente" id="f0" class="form-control"></div>
                        <div class="col-md-4"><label class="form-label fw-semibold">CNPJ</label><input type="text" name="cnpj" id="f1" class="form-control"></div>
                        <div class="col-md-3"><label class="form-label fw-semibold">CPF</label><input type="text" name="cpf" id="f2" class="form-control"></div>
                        <div class="col-md-3"><label class="form-label fw-semibold">Status</label><select name="status" id="fstatus" class="form-select"><option value="ativo">Ativo</option><option value="inativo">Inativo</option></select></div>
                        <div class="col-md-12"><label class="form-label fw-semibold">Razão Social <span class="text-danger">*</span></label><input type="text" name="razao_social" id="f3" class="form-control" required></div>
                        <div class="col-md-3"><label class="form-label fw-semibold">CEP</label><input type="text" name="cep" id="f4" class="form-control"></div>
                        <div class="col-md-5"><label class="form-label fw-semibold">Endereço</label><input type="text" name="endereco" id="f5" class="form-control"></div>
                        <div class="col-md-2"><label class="form-label fw-semibold">Número</label><input type="text" name="numero" id="f6" class="form-control"></div>
                        <div class="col-md-2"><label class="form-label fw-semibold">Complemento</label><input type="text" name="complemento" id="f7" class="form-control"></div>
                        <div class="col-md-3"><label class="form-label fw-semibold">Bairro</label><input type="text" name="bairro" id="f8" class="form-control"></div>
                        <div class="col-md-3"><label class="form-label fw-semibold">Cidade</label><input type="text" name="cidade" id="f9" class="form-control"></div>
                        <div class="col-md-2"><label class="form-label fw-semibold">UF</label><input type="text" name="estado" id="f10" class="form-control" maxlength="2"></div>
                        <div class="col-md-2"><label class="form-label fw-semibold">País</label><input type="text" name="pais" id="f11" class="form-control" value="Brasil"></div>
                        <div class="col-md-3"><label class="form-label fw-semibold">Telefone 1</label><input type="text" name="telefone1" id="f12" class="form-control"></div>
                        <div class="col-md-3"><label class="form-label fw-semibold">Telefone 2</label><input type="text" name="telefone2" id="f13" class="form-control"></div>
                        <div class="col-md-6"><label class="form-label fw-semibold">E-mail (login) <span class="text-danger">*</span></label><input type="email" name="email" id="f14" class="form-control" required></div>
                        <div class="col-md-4"><label class="form-label fw-semibold">Vendedor</label>
                            <select name="vendedor" id="f15" class="form-select">
                                <option value="">— Nenhum —</option>
                                <?php foreach ($vendedores as $v): ?><option value="<?= e($v) ?>"><?= e($v) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4"><label class="form-label fw-semibold">Canal de Venda</label>
                            <select name="canal_venda_id" id="f16" class="form-select" onchange="atualizarDescCanal(true)">
                                <option value="">Selecione...</option>
                                <?php foreach ($canais as $cv): ?><option value="<?= $cv['id'] ?>" data-desconto="<?= e($cv['desconto']) ?>"><?= e($cv['canal']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">Desconto do Cliente %</label>
                            <input type="number" step="0.01" min="0" name="desconto_cliente" id="f17" class="form-control" value="0">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">Desconto do Canal %</label>
                            <input type="number" step="0.01" min="0" name="desconto_canal" id="f_desc_canal" class="form-control" value="0">
                            <div id="desc-canal-max-info" class="text-muted small mt-1"></div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">Bônus Desempenho %</label>
                            <input type="number" step="0.01" min="0" max="4" name="bonus_desempenho" id="f_bonus_desemp" class="form-control" value="0">
                            <div class="text-muted small mt-1">Limite: 4%</div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">Bônus Mat. Apoio %</label>
                            <input type="number" step="1" min="0" max="5" name="material_apoio" id="f_bonus_ma" class="form-control" value="0">
                            <div class="text-muted small mt-1">Limite: 5%</div>
                        </div>
                        <div class="col-md-2"><label class="form-label fw-semibold">Limite Créd.</label><input type="number" step="0.01" name="limite_credito" id="f18" class="form-control" value="0"></div>
                        <div class="col-md-2"><label class="form-label fw-semibold">Idioma</label><select name="idioma" id="f19" class="form-select"><option value="pt">PT</option><option value="en">EN</option><option value="es">ES</option></select></div>
                        <div class="col-md-2"><label class="form-label fw-semibold">Moeda</label><select name="moeda" id="f20" class="form-select"><option value="BRL">BRL</option><option value="USD">USD</option><option value="EUR">EUR</option></select></div>
                        <div class="col-md-4"><label class="form-label fw-semibold">Senha <small class="text-muted">(deixe em branco para manter)</small></label><input type="text" name="senha" id="f21" class="form-control"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Importar Excel -->
<div class="modal fade" id="modalImport" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-file-earmark-excel me-2 text-success"></i>Importar Clientes — Excel</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" onclick="resetImport()"></button>
            </div>
            <div class="modal-body">

                <!-- Passo 1: selecionar arquivo -->
                <div id="imp-step1">
                    <div class="alert alert-info py-2 mb-3">
                        <i class="bi bi-info-circle me-1"></i>
                        O sistema lê a <strong>primeira aba</strong> da planilha .xlsx/.xls, ignorando as <strong>5 primeiras linhas</strong> (metadados do relatório + cabeçalho de colunas). Os dados começam na linha 6.
                        Se o cliente já existir (mesmo Código ou CNPJ) os dados serão <strong>atualizados</strong>; caso contrário será <strong>inserido</strong>.
                    </div>
                    <div class="mb-3">
                        <strong>Colunas lidas:</strong>
                        <div class="d-flex flex-wrap gap-1 mt-2">
                            <?php foreach ([
                                'A'=>'Código Cliente','C'=>'Razão Social','E'=>'CNPJ',
                                'H'=>'Endereço','I'=>'Número','J'=>'Complemento',
                                'K'=>'Bairro','L'=>'Cidade','N'=>'Estado','O'=>'País',
                                'P'=>'CEP','Q'=>'Telefone 1','Y'=>'E-mail',
                                'AC'=>'Vendedor','BW'=>'Status'
                            ] as $col => $label): ?>
                            <span class="badge bg-light text-dark border">
                                <span class="fw-bold text-primary"><?= $col ?></span> → <?= $label ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="border-2 border-dashed rounded p-5 text-center" id="dropZone"
                         style="border:2px dashed #6c757d;cursor:pointer"
                         onclick="document.getElementById('impFile').click()"
                         ondragover="event.preventDefault();this.style.background='#f0faf5'"
                         ondragleave="this.style.background=''"
                         ondrop="handleDrop(event)">
                        <i class="bi bi-file-earmark-excel text-success" style="font-size:3rem"></i>
                        <div class="mt-2 fw-semibold">Clique ou arraste o arquivo aqui</div>
                        <div class="text-muted small">Suporta .xlsx e .xls</div>
                        <input type="file" id="impFile" accept=".xlsx,.xls" class="d-none" onchange="lerArquivo(this.files[0])">
                    </div>
                    <div id="imp-loading" class="text-center mt-3 d-none">
                        <div class="spinner-border text-primary" role="status"></div>
                        <div class="mt-2 text-muted">Lendo planilha...</div>
                    </div>
                </div>

                <!-- Passo 2: preview -->
                <div id="imp-step2" class="d-none">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <span class="fw-semibold">Prévia —</span>
                            <span id="imp-total-label" class="text-muted"></span>
                        </div>
                        <button class="btn btn-sm btn-outline-secondary" onclick="resetImport()">
                            <i class="bi bi-arrow-left me-1"></i>Trocar arquivo
                        </button>
                    </div>
                    <div style="max-height:380px;overflow-y:auto">
                        <table class="table table-sm table-hover mb-0" id="impPreviewTable">
                            <thead class="table-light" style="position:sticky;top:0;z-index:1">
                                <tr>
                                    <th>#</th>
                                    <th>Código</th>
                                    <th>Razão Social</th>
                                    <th>CNPJ</th>
                                    <th>Cidade/UF</th>
                                    <th>Telefone</th>
                                    <th>E-mail</th>
                                    <th>Vendedor</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody id="impPreviewBody"></tbody>
                        </table>
                    </div>
                    <div class="alert alert-warning mt-3 py-2 d-none" id="imp-warn-rows">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        <span id="imp-warn-text"></span>
                    </div>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" onclick="resetImport()">Cancelar</button>
                <form method="POST" id="formImport" style="display:contents">
                    <input type="hidden" name="action" value="importar">
                    <input type="hidden" name="dados" id="imp-dados">
                    <button type="submit" class="btn btn-success d-none" id="imp-btn-submit"
                            onclick="document.getElementById('imp-dados').value=JSON.stringify(importData)">
                        <i class="bi bi-upload me-1"></i>
                        <span id="imp-btn-label">Importar</span>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
var _exportClientes = <?= json_encode(array_map(function($c) {
    return [
        'Código'             => $c['codigo_cliente'] ?? '',
        'Razão Social'       => $c['razao_social']   ?? '',
        'CNPJ'               => $c['cnpj']           ?? '',
        'CPF'                => $c['cpf']            ?? '',
        'Canal de Venda'     => $c['canal']          ?? '',
        'Desconto Cliente %' => (float)($c['desconto_cliente'] ?? 0),
        'Desconto Canal %'   => (float)($c['desconto_canal']   ?? 0),
        'Limite de Crédito'  => (float)($c['limite_credito']   ?? 0),
        'E-mail'             => $c['email']          ?? '',
        'Telefone 1'         => $c['telefone1']      ?? '',
        'Telefone 2'         => $c['telefone2']      ?? '',
        'Vendedor'           => $c['vendedor']       ?? '',
        'CEP'                => $c['cep']            ?? '',
        'Endereço'           => $c['endereco']       ?? '',
        'Número'             => $c['numero']         ?? '',
        'Complemento'        => $c['complemento']    ?? '',
        'Bairro'             => $c['bairro']         ?? '',
        'Cidade'             => $c['cidade']         ?? '',
        'Estado'             => $c['estado']         ?? '',
        'País'               => $c['pais']           ?? '',
        'Status'             => $c['status']         ?? '',
    ];
}, $clientes), JSON_UNESCAPED_UNICODE) ?>;

function exportarClientes() {
    if (!_exportClientes.length) { alert('Nenhum registro para exportar.'); return; }
    var ws = XLSX.utils.json_to_sheet(_exportClientes);
    var keys = Object.keys(_exportClientes[0]);
    ws['!cols'] = keys.map(function(k) {
        var max = k.length;
        _exportClientes.forEach(function(r) { max = Math.max(max, String(r[k] || '').length); });
        return { wch: Math.min(max + 2, 45) };
    });
    var wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Clientes');
    XLSX.writeFile(wb, 'clientes_<?= date('Y-m-d') ?>.xlsx');
}
</script>
<script>
// Mapeamento: índice de coluna (0-based) → campo
var IMP_MAP = {
    0:  'codigo_cliente',
    2:  'razao_social',
    4:  'cnpj',
    7:  'endereco',
    8:  'numero',
    9:  'complemento',
    10: 'bairro',
    11: 'cidade',
    13: 'estado',
    14: 'pais',
    15: 'cep',
    16: 'telefone1',
    24: 'email',
    28: 'vendedor',
    74: 'status'
};

var importData = [];

function handleDrop(ev) {
    ev.preventDefault();
    document.getElementById('dropZone').style.background = '';
    var file = ev.dataTransfer.files[0];
    if (file) lerArquivo(file);
}

function lerArquivo(file) {
    if (!file) return;
    document.getElementById('imp-loading').classList.remove('d-none');
    var reader = new FileReader();
    reader.onload = function(e) {
        try {
            var wb   = XLSX.read(new Uint8Array(e.target.result), {type: 'array'});
            var ws   = wb.Sheets[wb.SheetNames[0]];
            var rows = XLSX.utils.sheet_to_json(ws, {header: 1, defval: '', raw: false});
            processarLinhas(rows);
        } catch(err) {
            alert('Erro ao ler o arquivo: ' + err.message);
            document.getElementById('imp-loading').classList.add('d-none');
        }
    };
    reader.readAsArrayBuffer(file);
}

function primeiroEmail(raw) {
    // Tenta separar por delimitadores comuns e pega o primeiro fragmento válido
    var parts = raw.split(/[\s;,\/]+/);
    for (var j = 0; j < parts.length; j++) {
        var p = parts[j].trim().toLowerCase();
        // válido: tem exatamente um @, tem ponto depois do @, sem espaços
        if (p.indexOf('@') !== -1 && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(p) && (p.match(/@/g)||[]).length === 1) {
            return p;
        }
    }
    // Fallback: extrai o primeiro padrão email do texto bruto
    var m = raw.match(/[\w.+\-]+@[\w\-]+\.[a-z]{2,6}/i);
    return m ? m[0].toLowerCase() : '';
}

function processarLinhas(rows) {
    importData = [];
    var skipped = 0;
    for (var i = 5; i < rows.length; i++) {
        var row = rows[i];
        var obj = {};
        for (var col in IMP_MAP) {
            obj[IMP_MAP[col]] = (row[col] !== undefined && row[col] !== null) ? String(row[col]).trim() : '';
        }
        obj.email = primeiroEmail(obj.email);
        if (!obj.razao_social) { skipped++; continue; }
        importData.push(obj);
    }

    document.getElementById('imp-loading').classList.add('d-none');
    document.getElementById('imp-step1').classList.add('d-none');
    document.getElementById('imp-step2').classList.remove('d-none');
    document.getElementById('imp-btn-submit').classList.remove('d-none');

    var total = importData.length;
    document.getElementById('imp-total-label').textContent =
        total + ' registro(s) encontrado(s)' + (skipped > 0 ? ' (' + skipped + ' linha(s) sem Razão Social ignoradas)' : '');
    document.getElementById('imp-btn-label').textContent = 'Importar ' + total + ' registro(s)';

    // Preview: máx 200 linhas na tabela
    var body    = document.getElementById('impPreviewBody');
    body.innerHTML = '';
    var preview = importData.slice(0, 200);
    preview.forEach(function(obj, idx) {
        var tr = document.createElement('tr');
        var cidade_uf = obj.cidade + (obj.estado ? '/' + obj.estado : '');
        tr.innerHTML =
            '<td class="text-muted small">' + (idx + 1) + '</td>' +
            '<td><small>' + esc(obj.codigo_cliente) + '</small></td>' +
            '<td><strong><small>' + esc(obj.razao_social) + '</small></strong></td>' +
            '<td><small>' + esc(obj.cnpj) + '</small></td>' +
            '<td><small>' + esc(cidade_uf) + '</small></td>' +
            '<td><small>' + esc(obj.telefone1) + '</small></td>' +
            '<td><small>' + esc(obj.email) + '</small></td>' +
            '<td><small>' + esc(obj.vendedor) + '</small></td>' +
            '<td><span class="badge bg-' + (obj.status.toLowerCase() === 'inativo' ? 'secondary' : 'success') + '">' + (obj.status || 'Ativo') + '</span></td>';
        body.appendChild(tr);
    });

    if (importData.length > 200) {
        document.getElementById('imp-warn-rows').classList.remove('d-none');
        document.getElementById('imp-warn-text').textContent =
            'Exibindo apenas os primeiros 200 registros. Todos os ' + importData.length + ' serão importados.';
    }
}

function esc(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function resetImport() {
    importData = [];
    document.getElementById('imp-step1').classList.remove('d-none');
    document.getElementById('imp-step2').classList.add('d-none');
    document.getElementById('imp-loading').classList.add('d-none');
    document.getElementById('imp-btn-submit').classList.add('d-none');
    document.getElementById('imp-warn-rows').classList.add('d-none');
    document.getElementById('impPreviewBody').innerHTML = '';
    document.getElementById('impFile').value = '';
    document.getElementById('dropZone').style.background = '';
}
</script>

<script>
var campos = ['f0','f1','f2','f3','f4','f5','f6','f7','f8','f9','f10','f11','f12','f13','f14','f15','f16','f17','f18','f19','f20','f21'];
var chaves = ['codigo_cliente','cnpj','cpf','razao_social','cep','endereco','numero','complemento','bairro','cidade','estado','pais','telefone1','telefone2','email','vendedor','canal_venda_id','desconto_cliente','limite_credito','idioma','moeda',''];
function atualizarDescCanal(autofill) {
    var sel = document.getElementById('f16');
    var opt = sel.options[sel.selectedIndex];
    var maxDesc = (opt && opt.value && opt.getAttribute('data-desconto') !== null)
        ? parseFloat(opt.getAttribute('data-desconto')) : null;
    var maxEl     = document.getElementById('desc-canal-max-info');
    var canalDesEl = document.getElementById('f_desc_canal');
    if (maxDesc !== null && maxDesc > 0) {
        maxEl.textContent = 'Máx. ' + maxDesc.toFixed(2).replace('.', ',') + '%';
        canalDesEl.max = maxDesc;
        if (autofill) {
            canalDesEl.value = maxDesc;
        } else if (parseFloat(canalDesEl.value) > maxDesc) {
            canalDesEl.value = maxDesc;
        }
    } else {
        maxEl.textContent = '';
        canalDesEl.removeAttribute('max');
        if (autofill) canalDesEl.value = 0;
    }
}
function novoReg() {
    document.getElementById('mt').textContent='Novo Cliente';
    document.getElementById('fa').value='criar';
    document.getElementById('fi').value='';
    campos.forEach(function(id){ document.getElementById(id).value=''; });
    document.getElementById('f11').value='Brasil';
    document.getElementById('f17').value='0';
    document.getElementById('f_desc_canal').value='0';
    document.getElementById('f_bonus_desemp').value='0';
    document.getElementById('f_bonus_ma').value='0';
    document.getElementById('f18').value='0';
    document.getElementById('fstatus').value='ativo';
    document.getElementById('f19').value='pt';
    document.getElementById('f20').value='BRL';
    atualizarDescCanal(false);
}
function editarReg(d) {
    document.getElementById('mt').textContent='Editar Cliente';
    document.getElementById('fa').value='editar';
    document.getElementById('fi').value=d.id;
    document.getElementById('f0').value=d.codigo_cliente||'';
    document.getElementById('f1').value=d.cnpj||'';
    document.getElementById('f2').value=d.cpf||'';
    document.getElementById('f3').value=d.razao_social||'';
    document.getElementById('f4').value=d.cep||'';
    document.getElementById('f5').value=d.endereco||'';
    document.getElementById('f6').value=d.numero||'';
    document.getElementById('f7').value=d.complemento||'';
    document.getElementById('f8').value=d.bairro||'';
    document.getElementById('f9').value=d.cidade||'';
    document.getElementById('f10').value=d.estado||'';
    document.getElementById('f11').value=d.pais||'Brasil';
    document.getElementById('f12').value=d.telefone1||'';
    document.getElementById('f13').value=d.telefone2||'';
    document.getElementById('f14').value=d.email||'';
    document.getElementById('f15').value=d.vendedor||'';
    document.getElementById('f16').value=d.canal_venda_id||'';
    document.getElementById('f17').value=d.desconto_cliente||0;
    document.getElementById('f_desc_canal').value=d.desconto_canal||0;
    document.getElementById('f_bonus_desemp').value=d.bonus_desempenho||0;
    document.getElementById('f_bonus_ma').value=d.material_apoio||0;
    document.getElementById('f18').value=d.limite_credito||0;
    document.getElementById('f19').value=d.idioma||'pt';
    document.getElementById('f20').value=d.moeda||'BRL';
    document.getElementById('f21').value='';
    document.getElementById('fstatus').value=d.status||'ativo';
    atualizarDescCanal(false);
    new bootstrap.Modal(document.getElementById('modal')).show();
}
</script>
<?php require_once LAYOUT_PATH . '/footer.php'; ?>
