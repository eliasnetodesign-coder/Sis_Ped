# 07 — Relatórios

Módulo `admin/relatorios/*`. Salvo indicação, todos filtram **somente pedidos com status `faturado`**, exibem **percentual de participação** com barra de progresso e convertem valores USD/EUR para **BRL** (ver [Multimoeda](11-multimoeda-e-i18n.md)).

| Relatório | Arquivo | Filtro | Destaques |
|-----------|---------|--------|-----------|
| **Status dos Pedidos** | `status-pedidos.php` | — (todos os status) | Cards por status (qtd + valor); tabela dos **últimos 20 pedidos** |
| **Faturamento Diário** | `faturamento-diario.php` | Data inicial/final (padrão mês corrente) | Agrupado por dia (DESC); Data, Pedidos, Clientes, Valor + total |
| **Faturamento Mensal** | `faturamento-mensal.php` | Ano (dropdown 5 anos) | Agrupado por mês; total anual; % do total |
| **Faturamento Anual** | `faturamento-anual.php` | — | Ano, Pedidos, Clientes, Valor, Participação % |
| **Faturamento por Cliente** | `faturamento-cliente.php` | Ano | Rank por valor DESC; badge dourado (#1–#3); Cidade/UF, supervisor |
| **Faturamento por Canal** | `faturamento-canal.php` | Ano | Canal, Clientes, Pedidos, Valor, Participação % |
| **Faturamento por Supervisor** | `faturamento-supervisor.php` | Ano | Agrupa por `COALESCE(supervisor, vendedor)`; rank + badge dourado |
| **Faturamento por Estado** | `faturamento-estado.php` | Ano | UF (badge), Clientes, Pedidos, Valor, Participação % |
| **Faturamento por Região** | `faturamento-regiao.php` | Ano | Mapa UF→Região; ordenado por valor DESC |

## Mapeamento de Regiões (Faturamento por Região)

| Região | UFs |
|--------|-----|
| Centro-Oeste | DF, GO, MT, MS |
| Norte | AC, AP, AM, PA, RO, RR, TO |
| Nordeste | AL, BA, CE, MA, PB, PE, PI, RN, SE |
| Sul | PR, RS, SC |
| Sudeste | ES, MG, RJ, SP |
| Outros | UFs não mapeadas |

Relacionado: [Pedidos](06-pedidos.md), [Portal Administrativo](03-portal-admin.md).
