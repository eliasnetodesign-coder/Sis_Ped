# 06 — Pedidos

## Novo Pedido Admin (`admin/novo-pedido.php`)

Acesso: `requireComercial()` (`comercial`, `supervisor`, `tecnologia da informacao`).

**Etapa 1 — Seleção de cliente e produtos:**
- Busca de cliente com **autocomplete** (nome/código); exibe código como badge.
- Após selecionar, alerta verde com os descontos aplicados (Cliente % | Canal %).
- Campanhas ativas exibidas em alerta informativo (código, alvo, desconto%).
- Produtos organizados em **abas por Linha** (nav-tabs com scroll); filtro rápido por nome/código.
- Tabela por linha: Código, Cód. Barras, Produto, Preço Unit. (com descontos; badge verde de campanha), Múltiplo (badge se > 1), Campo Qtd., Quantidade Total (visual × múltiplo), Total R$.
- **Moeda:** preços na moeda do cliente (troca via JS — `data-preco-usd/eur`). Ver [Multimoeda](11-multimoeda-e-i18n.md).

**Carrinho (offcanvas):** lista itens (qtd visual × múltiplo, preço, subtotal, badge de campanha), total, botão Avançar. Valida cliente + ≥1 produto.

**Etapa 2 — Resumo:** tabela agrupada por linha com subtotais, campo de Observação, botão Finalizar.

**Servidor:**
- Fórmula: `valor = qtd × preço × (1 − (dCliente + dCanal + dComercial + dDiretoria)/100) × (1 − campDesc/100)`.
- Campanha validada server-side.
- Múltiplos produtos recebem o mesmo `lote_id` (UUID); produto único tem `lote_id = null`.
- Status inicial: `comercial`. Número: `PED-YYYY-NNNN` (com rand 1000–9999).

## Lista de Pedidos (`admin/pedidos.php`)

- **Cards clicáveis por status** (Total, Ag. Comercial, Ag. Financeiro, Ag. Faturamento, Cancelados) com qtd e valor; card ativo com borda colorida.
- **Filtro combinado:** status (botões) + período (data inicial/final; padrão mês atual) + **cliente** (nome/código/CNPJ).
- Pedidos agrupados por `lote_id` (valor total do lote; badge "N itens").
- Colunas: Nº, Código, Cliente, Supervisor, Data, Tipo (Venda/Bonificação), Valor, Status, Observações, Ações.
- **Colunas extras (financeiro/TI):** Crédito Aplicado, **Detalhamento Fiscal** (Total NF), **Accademia** (Valor − NF; negativo em vermelho), **% Desconto** (5% no Pix) e **Valor Desconto** (Pix do lote).
- **Grupo de empresas:** no perfil financeiro, filtrar por um cliente de grupo inclui os pedidos de todos os clientes do mesmo grupo.
- **Ações inline por perfil/status:**
  - `comercial`/`supervisor`/TI + status `comercial`: Aprovar → Financeiro (cancelar permanece com comercial/TI).
  - `financeiro`/TI + status `financeiro`: Aprovar → Faturado, Retornar ao Comercial, Cancelar.

## Detalhe do Pedido (`admin/pedido.php`)

- Exibe todos os itens do lote e o **log de ações** (`pedido_logs`).
- **Ações** conforme perfil e status (Aprovar / Cancelar / Retornar / Faturar).
- **Botão Impostos:** por item do pedido, mostra o "waterfall" de preço até o resultado final:
  1. **Valor por Produto** — `tabela_precos.preco_padrao`.
  2. **Descontos** (Canal, Cliente, Pedido = Comercial + Diretoria; Campanha só aparece se houver) — cada um calculado sobre o Valor por Produto.
  3. **Imposto da empresa "Network"** (identificada pelo nome no cadastro de [Impostos](cadastros/impostos.md)) — ICMS (por NCM + UF do cliente, local ou interestadual conforme `EMPRESA_UF`) + IPI/PIS/COFINS do NCM do produto (`ncm.ipi/pis/cofins`) + IRPJ/CSLL/ISS da própria empresa, sobre o resultado após descontos.
  4. **Impostos das demais empresas cadastradas** (ex.: Accademia) — PIS/COFINS do NCM específicos dessa empresa (`ncm.pis_accademia/cofins_accademia`, sem IPI) + IRPJ/CSLL/ISS próprios, em cascata sobre o resultado da etapa anterior.
  5. **Custo MP** — campo manual editável (ainda sem cadastro no sistema), recalcula ao vivo via JS.
  6. **Custos Fixos** — percentual manual editável, aplicado sobre `Valor por Produto − Desconto Canal − Desconto Cliente`.
  7. **Resultado Final** por produto.
- **Descontos e Campanhas (card):** percentuais usados (Cliente, Canal, Comercial) + campanhas atingidas (código, alvo, qtd × mínimo, desconto% ou brindes × multiplicador).
- **Descontos extras por item (etapa Comercial):**
  - **Desconto Comercial** (`pedidos.desconto_comercial`) — limitado por `canal_venda.margem_negociacao` (clamp no servidor + `max` no input).
  - **Desconto Diretoria** (`pedidos.desconto_diretoria`) — **sem limite**.
  - Editável só na etapa `comercial` por quem edita itens (comercial/TI).
  - Colunas da tabela de itens: Preço Unit.(bruto) | Desc. Comercial | Desc. Diretoria | Valor Unit. c/ Desc. | Desconto (campanha) | Total.
- Recalcula descontos ao aprovar/alterar (`recalcularDescontosCampanha`, `recalcularValorItem`, `melhorCampanhaItem`).
- **Forma de Pagamento** e **Crédito utilizado** registráveis. Botão para gerar **PDF**.

### Detalhamento Fiscal (modal)

Tabela por item com Código, Descrição, UN, Quantidade, **Valor Unitário** (sempre o preço Network original — `tabela_precos.preco_network`; produtos sem Network ficam zerados), Valor Total, Alíq./Valor de **ICMS**, **IPI**, **PIS** e **COFINS**, com linha de totais.
- IPI/PIS/COFINS vêm do cadastro **NCM** do produto.
- ICMS vem de `ncm_estados` pela UF do cliente: `icms_local` quando UF = `EMPRESA_UF`, senão `icms_interestadual`. Sem cadastro, alíquota 0.
- **Total da NF** = Total dos Produtos **+ IPI** (ICMS, PIS e COFINS estão embutidos no preço).

## Fluxo de status

```
[Criado] → comercial → financeiro → faturamento → faturado
                    ↘ cancelado ↙
         financeiro pode retornar → comercial
```

Toda mudança de status é registrada em `pedido_logs` (pedido_id, numero_pedido, usuario_nome, usuario_tipo, acao, status_antes, status_depois, detalhes, created_at).

## PDF do Pedido (`admin/pedido-pdf.php`, `cliente/pedido-pdf.php`)

Geração server-side com layout do pedido, dados do cliente e itens. No resumo/PDF aparecem as linhas "Crédito aplicado" e "Desconto Pix (5%)" quando aplicáveis.

Relacionado: [Campanhas](05-campanhas.md), [Regras de Negócio](10-regras-de-negocio.md), [Multimoeda e i18n](11-multimoeda-e-i18n.md).
