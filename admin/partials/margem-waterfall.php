<?php
/**
 * Corpo do modal "Margem" (waterfall por item) — compartilhado entre a tela do pedido
 * (admin/pedido.php) e o detalhe do relatório de pedidos faturados do A&M
 * (admin/relatorios/margem-am-faturados.php, endpoint ajax_margem).
 *
 * Espera no escopo as variáveis de extract(calcularMargemPedido()) / extract(calcularMargemPedidoAEM()):
 * $impItens, $impTotalProdutos, $impDeltaDescontos, $impDeltaCredito, $impDeltaNet, $impDeltaImpostos,
 * $impDeltaMP, $impDeltaDespesas, $impTotalFinal, $impMargemPct, $creditoUsadoAdmin, $competenciaPedido.
 * O CSS do acordeão (.imp-toggle / .imp-chevron) fica em cada página.
 */
?>
            <?php if (!$impItens): ?>
                <div class="text-center text-muted py-5">Nenhum item para detalhar.</div>
            <?php else: $pctFmt = fn($v) => rtrim(rtrim(number_format((float)$v, 2, ',', '.'), '0'), ',') . '%'; ?>
                <?php
                $netNomeGeral   = $impItens[0]['netNome'] ?? 'Network';
                $outraNomeGeral = $impItens[0]['blocosOutros'][0]['nome'] ?? 'Impostos';
                ?>
                <div class="d-flex justify-content-end mb-3">
                    <div class="border rounded p-3 bg-light" style="min-width:340px">
                        <div class="d-flex flex-column gap-1 mb-2 pb-2 border-bottom" style="font-size:.8rem">
                            <div class="d-flex justify-content-between text-muted">
                                <span>Total dos Produtos</span>
                                <span><?= moedaBR($impTotalProdutos) ?></span>
                            </div>
                            <div class="d-flex justify-content-between text-muted">
                                <span>Total após Descontos</span>
                                <span class="<?= $impDeltaDescontos >= 0 ? '' : 'text-danger' ?>"><?= moedaBR($impDeltaDescontos) ?></span>
                            </div>
                            <?php if ($creditoUsadoAdmin > 0): ?>
                            <div class="d-flex justify-content-between text-muted">
                                <span>Total após Crédito Aplicado</span>
                                <span class="<?= $impDeltaCredito >= 0 ? '' : 'text-danger' ?>"><?= moedaBR($impDeltaCredito) ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="d-flex justify-content-between text-muted">
                                <span>Total após <?= e($netNomeGeral) ?></span>
                                <span class="<?= $impDeltaNet >= 0 ? '' : 'text-danger' ?>"><?= moedaBR($impDeltaNet) ?></span>
                            </div>
                            <?php if ($impItens[0]['temAccademia'] ?? false): ?>
                            <div class="d-flex justify-content-between text-muted">
                                <span>Total após <?= e($outraNomeGeral) ?></span>
                                <span class="<?= $impDeltaImpostos >= 0 ? '' : 'text-danger' ?>"><?= moedaBR($impDeltaImpostos) ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="d-flex justify-content-between text-muted">
                                <span>Total após Custo MP</span>
                                <span class="<?= $impDeltaMP >= 0 ? '' : 'text-danger' ?>"><?= moedaBR($impDeltaMP) ?></span>
                            </div>
                            <div class="d-flex justify-content-between text-muted">
                                <span>Total após Despesas</span>
                                <span class="<?= $impDeltaDespesas >= 0 ? '' : 'text-danger' ?>"><?= moedaBR($impDeltaDespesas) ?></span>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between fs-5 fw-bold">
                            <span>Margem Total</span>
                            <span class="<?= $impTotalFinal >= 0 ? 'text-success' : 'text-danger' ?>" id="impTotalGeral"><?= moedaBR($impTotalFinal) ?></span>
                        </div>
                        <div class="text-muted text-end" style="font-size:.8rem">
                            (<span id="impMargemGeral"><?= $pctFmt($impMargemPct) ?></span> margem média)
                        </div>
                    </div>
                </div>
                <?php foreach ($impItens as $idx => $it):
                    $itMargem = $pctFmt($it['precoPadrao'] > 0 ? $it['resultadoIni'] / $it['precoPadrao'] * 100 : 0);
                ?>
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-light py-2 imp-toggle collapsed" role="button" style="cursor:pointer"
                         data-bs-toggle="collapse" data-bs-target="#impItem<?= $idx ?>"
                         aria-expanded="false" aria-controls="impItem<?= $idx ?>">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div>
                                <i class="bi bi-chevron-right imp-chevron me-1"></i>
                                <strong><?= e($it['codigo']) ?></strong> — <?= e($it['descricao']) ?>
                                <span class="badge bg-secondary ms-1">Qtd: <?= (int)$it['qtd'] ?></span>
                            </div>
                            <div class="text-end small">
                                <span class="fw-bold <?= $it['resultadoIni'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= moedaBR($it['resultadoIni']) ?></span>
                                <span class="text-muted">(<?= $itMargem ?> margem)</span>
                                <span class="text-muted">· Total <?= moedaBR($it['resultadoIni'] * $it['qtd']) ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="collapse" id="impItem<?= $idx ?>">
                    <div class="card-body p-0">
                        <table class="table table-sm mb-0" style="font-size:.85rem">
                            <thead>
                                <tr class="text-muted small">
                                    <th></th><th></th>
                                    <th class="text-end fw-normal">Valor Unitário</th>
                                    <th class="text-end fw-normal">Valor Total (Pedido)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="fw-semibold">
                                    <td>Valor por Produto</td><td></td>
                                    <td class="text-end"><?= moedaBR($it['precoPadrao']) ?></td>
                                    <td class="text-end text-muted"><?= moedaBR($it['precoPadrao'] * $it['qtd']) ?></td>
                                </tr>
                                <tr>
                                    <td class="ps-4 text-muted">(-) Desconto Canal (<?= $pctFmt($it['descCanalPct']) ?>)</td><td></td>
                                    <td class="text-end text-danger">-<?= moedaBR($it['vCanal']) ?></td>
                                    <td class="text-end text-danger text-opacity-75">-<?= moedaBR($it['vCanal'] * $it['qtd']) ?></td>
                                </tr>
                                <tr>
                                    <td class="ps-4 text-muted">(-) Desconto Cliente (<?= $pctFmt($it['descClientePct']) ?>)</td><td></td>
                                    <td class="text-end text-danger">-<?= moedaBR($it['vCliente']) ?></td>
                                    <td class="text-end text-danger text-opacity-75">-<?= moedaBR($it['vCliente'] * $it['qtd']) ?></td>
                                </tr>
                                <tr>
                                    <td class="ps-4 text-muted">(-) Desconto Pedido (<?= $pctFmt($it['descPedidoPct']) ?>)</td><td></td>
                                    <td class="text-end text-danger">-<?= moedaBR($it['vPedido']) ?></td>
                                    <td class="text-end text-danger text-opacity-75">-<?= moedaBR($it['vPedido'] * $it['qtd']) ?></td>
                                </tr>
                                <?php if ($it['descCampanhaPct'] > 0): ?>
                                <tr>
                                    <td class="ps-4 text-muted">(-) Desconto Campanha (<?= $pctFmt($it['descCampanhaPct']) ?>)</td><td></td>
                                    <td class="text-end text-danger">-<?= moedaBR($it['vCampanha']) ?></td>
                                    <td class="text-end text-danger text-opacity-75">-<?= moedaBR($it['vCampanha'] * $it['qtd']) ?></td>
                                </tr>
                                <?php endif; ?>
                                <tr class="table-light fw-semibold">
                                    <td>Resultado após Descontos</td><td></td>
                                    <td class="text-end"><?= moedaBR($it['resAposDescontos']) ?></td>
                                    <td class="text-end text-muted"><?= moedaBR($it['resAposDescontos'] * $it['qtd']) ?></td>
                                </tr>
                                <?php if ($it['vCredito'] > 0): ?>
                                <tr>
                                    <td class="ps-4 text-muted">(-) Crédito Aplicado (<?= $pctFmt($it['pctCredito']) ?>)</td><td></td>
                                    <td class="text-end text-danger">-<?= moedaBR($it['vCredito']) ?></td>
                                    <td class="text-end text-danger text-opacity-75">-<?= moedaBR($it['vCredito'] * $it['qtd']) ?></td>
                                </tr>
                                <tr class="table-light fw-semibold">
                                    <td>Resultado após Crédito</td><td></td>
                                    <td class="text-end"><?= moedaBR($it['resAposCredito']) ?></td>
                                    <td class="text-end text-muted"><?= moedaBR($it['resAposCredito'] * $it['qtd']) ?></td>
                                </tr>
                                <?php endif; ?>

                                <?php if ($it['netNome']): ?>
                                <tr>
                                    <td colspan="4" class="pt-3 pb-1 fw-semibold text-uppercase small text-muted">
                                        Imposto <?= e($it['netNome']) ?> <span class="text-muted text-lowercase fw-normal">— base: <?= e($it['netBaseLabel']) ?> <?= moedaBR($it['precoNetwork']) ?><?php if (!$it['temAccademia']): ?> (<?= moedaBR($it['resAposCredito']) ?> ÷ (1 + <?= $pctFmt($it['ipiNetPct']) ?>))<?php else: ?> (PIS/COFINS: <?= moedaBR($it['precoNetwork']) ?> − ICMS <?= moedaBR($it['icmsNetVal']) ?> = <?= moedaBR($it['pisCofinsBase']) ?>)<?php endif; ?></span>
                                    </td>
                                </tr>
                                <?php foreach ($it['netTaxes'] as $tx): ?>
                                <tr>
                                    <td class="ps-4 text-muted">
                                        (-) <?= e($tx['label']) ?> (<?= $pctFmt($tx['pct']) ?>)<?php if (!$it['temAccademia'] && in_array($tx['label'], ['PIS', 'COFINS'], true)): ?> <span style="font-size:.68rem">[(<?= moedaBR($it['precoNetwork']) ?> − ICMS <?= moedaBR($it['icmsNetVal']) ?>) × <?= $pctFmt($tx['pct']) ?>]</span><?php endif; ?>
                                    </td>
                                    <td></td>
                                    <td class="text-end text-danger">-<?= moedaBR($tx['val']) ?></td>
                                    <td class="text-end text-danger text-opacity-75">-<?= moedaBR($tx['val'] * $it['qtd']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <tr class="table-light fw-semibold">
                                    <td>Resultado após <?= e($it['netNome']) ?></td><td></td>
                                    <td class="text-end"><?= moedaBR($it['resAposNet']) ?></td>
                                    <td class="text-end text-muted"><?= moedaBR($it['resAposNet'] * $it['qtd']) ?></td>
                                </tr>
                                <?php endif; ?>

                                <?php foreach ($it['blocosOutros'] as $bl): ?>
                                <tr><td colspan="4" class="pt-3 pb-1 fw-semibold text-uppercase small text-muted">Impostos <?= e($bl['nome']) ?> <span class="text-muted text-lowercase fw-normal">— base: Result. após Crédito − Preço Network − IPI Network = <?= moedaBR($it['baseOutras']) ?></span></td></tr>
                                <?php foreach ($bl['taxes'] as $tx): ?>
                                <tr>
                                    <td class="ps-4 text-muted">(-) <?= e($tx['label']) ?> (<?= $pctFmt($tx['pct']) ?>)</td><td></td>
                                    <td class="text-end text-danger">-<?= moedaBR($tx['val']) ?></td>
                                    <td class="text-end text-danger text-opacity-75">-<?= moedaBR($tx['val'] * $it['qtd']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endforeach; ?>
                                <?php if ($it['temAccademia']): ?>
                                <tr class="table-light fw-semibold">
                                    <td>Resultado após Academia</td><td></td>
                                    <td class="text-end"><?= moedaBR($it['resAposImpostos']) ?></td>
                                    <td class="text-end text-muted"><?= moedaBR($it['resAposImpostos'] * $it['qtd']) ?></td>
                                </tr>
                                <?php endif; ?>

                                <tr>
                                    <td class="text-muted align-middle">
                                        (-) Custo MP
                                        <div class="text-muted" style="font-size:.68rem">
                                        <?php if ($it['custoMPAchado']): ?>
                                            módulo Custos dos Produtos — competência <?= e(date('m/Y', strtotime($competenciaPedido))) ?> (por unidade)
                                        <?php else: ?>
                                            sem custo cadastrado para <?= e(date('m/Y', strtotime($competenciaPedido))) ?> (por unidade)
                                        <?php endif; ?>
                                        </div>
                                    </td>
                                    <td></td>
                                    <td class="text-end text-danger"><?= $it['custoMP'] > 0 ? '-' . moedaBR($it['custoMP']) : '—' ?></td>
                                    <td class="text-end text-danger text-opacity-75"><?= $it['custoMP'] > 0 ? '-' . moedaBR($it['custoMP'] * $it['qtd']) : '—' ?></td>
                                </tr>
                                <tr class="table-light fw-semibold">
                                    <td>Resultado após Custo MP</td><td></td>
                                    <td class="text-end"><?= moedaBR($it['resAposMP']) ?></td>
                                    <td class="text-end text-muted"><?= moedaBR($it['resAposMP'] * $it['qtd']) ?></td>
                                </tr>

                                <tr>
                                    <td class="text-muted align-middle">
                                        (-) Custos Fixos (<?= $pctFmt($it['custoFixoPct']) ?>)
                                        <div class="text-muted" style="font-size:.68rem">
                                        % sobre Produto − Desc. Canal − Desc. Cliente (<?= moedaBR($it['baseCF']) ?>) —
                                        módulo Custos dos Produtos, competência <?= e(date('m/Y', strtotime($competenciaPedido))) ?>
                                        </div>
                                    </td>
                                    <td></td>
                                    <td class="text-end text-danger"><?= $it['vCF'] > 0 ? '-' . moedaBR($it['vCF']) : '—' ?></td>
                                    <td class="text-end text-danger text-opacity-75"><?= $it['vCF'] > 0 ? '-' . moedaBR($it['vCF'] * $it['qtd']) : '—' ?></td>
                                </tr>
                                <?php if ($it['descFinanceiroPct'] > 0): ?>
                                <tr>
                                    <td class="text-muted align-middle">
                                        (-) Desconto Financeiro (<?= $pctFmt($it['descFinanceiroPct']) ?>)
                                        <div class="text-muted" style="font-size:.68rem">
                                        % sobre Resultado após Crédito (<?= moedaBR($it['resAposCredito']) ?>) — pedido à vista (Pix)
                                        </div>
                                    </td>
                                    <td></td>
                                    <td class="text-end text-danger"><?= $it['vDescFinanceiro'] > 0 ? '-' . moedaBR($it['vDescFinanceiro']) : '—' ?></td>
                                    <td class="text-end text-danger text-opacity-75"><?= $it['vDescFinanceiro'] > 0 ? '-' . moedaBR($it['vDescFinanceiro'] * $it['qtd']) : '—' ?></td>
                                </tr>
                                <?php endif; ?>
                                <tr class="<?= $it['resultadoIni'] >= 0 ? 'table-success' : 'table-danger' ?> fw-bold" style="font-size:.95rem">
                                    <td>Resultado Final</td><td></td>
                                    <td class="text-end <?= $it['resultadoIni'] >= 0 ? '' : 'text-danger' ?>">
                                        <?= moedaBR($it['resultadoIni']) ?>
                                        <span class="text-muted small fw-normal d-block">(<?= $itMargem ?> margem)</span>
                                    </td>
                                    <td class="text-end <?= $it['resultadoIni'] >= 0 ? '' : 'text-danger' ?>"><?= moedaBR($it['resultadoIni'] * $it['qtd']) ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
