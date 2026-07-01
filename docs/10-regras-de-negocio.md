# 10 — Regras de Negócio

## Descontos no pedido

Camadas somadas + campanha multiplicativa:

1. **Desconto do Cliente** — `desconto_cliente` fixo no cadastro.
2. **Desconto do Canal** — `desconto_canal`, limitado ao teto `canal_venda.desconto`.
3. **Desconto Comercial** (por item) — `pedidos.desconto_comercial`, limitado ao teto `canal_venda.margem_negociacao`. Editável na etapa Comercial.
4. **Desconto Diretoria** (por item) — `pedidos.desconto_diretoria`, **sem limite**. Editável na etapa Comercial.
5. **Desconto de Campanha** — acionado pelo gatilho da campanha (ver [Campanhas](05-campanhas.md)); aplica o **maior** desconto elegível por item.

**Fórmula do valor por item:**

```
valor = qtd × preço × (1 − (dCliente + dCanal + dComercial + dDiretoria)/100) × (1 − campDesc/100)
```

Cliente + canal + comercial + diretoria **somam** (cap 100%); a campanha é **multiplicativa**. O `preço` é a coluna da moeda do cliente (ver [Multimoeda](11-multimoeda-e-i18n.md)).

Pedidos de **bonificação** usam o preço **Network** (`valor_total = qtd × preço Network`, sem descontos, `cotacao = NULL`).

## Pagamento, crédito e desconto Pix

- **Forma de pagamento:** modal ao finalizar (Pix, Boleto 30, 30/60, 30/60/90).
- **Crédito do cliente:** aplicado primeiro, **limitado à diferença** `valor do pedido − detalhamento fiscal (NF)`. O excedente fica para outro pedido.
- **Desconto Pix (5%):** só vendas; incide sobre o valor já líquido de crédito — `Pix = 5% × (Total − Crédito)`. Gravado em `pedidos.desconto_pagamento` (1º item do lote), sem alterar `valor_total`.
- **Total a Pagar** = `Total − Crédito − Pix`. O resumo/PDF exibem "Crédito aplicado" e "Desconto Pix (5%)".

## Múltiplo de venda

Quantidade visual (informada) × múltiplo do produto = quantidade real registrada. Exibido como badge; cálculo em tempo real no front.

## Lote de pedidos

- Pedido com múltiplos produtos: todos compartilham o `lote_id` (UUID `uniqid('L', true)`). Produto único: `lote_id = null`.
- Listagens agrupam por `COALESCE(lote_id, CAST(id AS CHAR))`.
- Aprovações/reprovações afetam **todo o lote**.
- `recalcularDescontosCampanha` considera as quantidades totais de todos os itens do lote.

## Log de pedidos (`pedido_logs`)

Toda mudança de status: pedido_id, numero_pedido, usuario_nome, usuario_tipo, acao, status_antes, status_depois, detalhes, created_at.

## Bônus de Desempenho

- **Trimestral**; aprovado/cancelado manualmente. Compara faturamento faturado no trimestre vs. meta.
- Percentual em `clientes.bonus_desempenho` (máx. 4%). Log em `bonus_desempenho_logs`.

## Bônus de Material de Apoio

- **Mensal**; só clientes com `material_apoio > 0`. Valor = faturamento do mês × % MA.
- Média de atraso nos pagamentos como indicador de risco (join com `contas_receber`). Log em `bonus_ma_logs` (com `valor_utilizado`).
- Notificação: popup no primeiro acesso ao dashboard do mês seguinte.

## Créditos a clientes

- Concedidos manualmente (cliente, descrição, data, valor, observação). Workflow em `creditos_logs`.
- `valor_utilizado` rastreia o consumo; `credito_utilizado` no pedido registra o aplicado.
- Crédito com `valor_utilizado > 0` **não pode** ser excluído.
- **Limite de uso por pedido:** só sobre a **diferença = valor do pedido − detalhamento fiscal (NF)** (`NF = Σ qtd × preço Network × (1 + IPI/100)`). Se o crédito disponível exceder a diferença, o sistema pergunta se quer usar só a diferença. Se a diferença ≤ 0, o crédito não pode ser aplicado.

## E-mail de cliente (chave única de login)

- E-mail é chave de login do portal cliente; único em `clientes`.
- Na importação, conflitos são resolvidos zerando o campo (a linha não é rejeitada).
- No cadastro manual, unicidade garantida pelo banco (UNIQUE).

Relacionado: [Pedidos](06-pedidos.md), [Campanhas](05-campanhas.md), [Multimoeda e i18n](11-multimoeda-e-i18n.md).
