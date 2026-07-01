# 03 — Portal Administrativo

Portal da equipe interna. Layout compartilhado (`layout/header.php` + `footer.php`) com sidebar. O menu varia conforme o perfil.

## Dashboard (`admin/dashboard.php`)

- **Cards de resumo:** Aguardando Comercial, No Financeiro, No Faturamento, Faturados, Cancelados, Total de Pedidos, Valor Total Faturado (convertido para BRL — ver [Multimoeda](11-multimoeda-e-i18n.md)).
- **Tabela dos 10 últimos pedidos:** nº, cliente, produto, data, valor, status + link para detalhes.
- Perfil `supervisor`: vê **apenas** seus pedidos e clientes.
- **Alerta de ação rápida** (botão) exibido quando há pedidos aguardando Comercial (para perfis `comercial`, `financeiro` e `tecnologia da informacao`).

## Navegação (sidebar)

A sidebar é uma `offcanvas-md`: drawer no mobile, fixa no desktop. Os submenus são **flyouts** que abrem à direita no desktop.

### Menu — Comercial / Supervisor / TI

- **Dashboard**, **Pedidos**, **Novo Pedido**
- **Comercial → Cadastros** (flyout): Produtos, Clientes, Grupo de Empresas, Tabela de Preços, Campanhas, Canal de Venda, NCM, Metas, Bônus Desempenho, Bônus Mat. Apoio, Concessão de Créditos
- **Relatórios** (flyout): 9 relatórios de faturamento
- **Administração** (flyout): Usuários

### Menu — Financeiro / TI

- **Dashboard**, **Pedidos**
- **Financeiro** (flyout): Clientes, Fornecedores, Contas a Receber, Contas a Pagar, Ordens de Pagamento, Ordens de Investimento
- **Bônus Desempenho**
- **Administração** (flyout): Usuários

> O perfil **TI** enxerga os menus Comercial **e** Financeiro simultaneamente.

## Tema claro/escuro

- Botão na navbar (apenas admin) alterna entre claro e escuro (`data-bs-theme`).
- A preferência é persistida em `localStorage` (`sisped-theme`) e aplicada antes da renderização para evitar flash.

## Troca de empresa (grupo)

- Quando o usuário (cliente) pertence a um grupo com mais de uma empresa, a navbar exibe um botão para **trocar de CNPJ** (modal). Ver [Portal do Cliente](09-portal-cliente.md#grupo-de-empresas).

## Submódulos

- [Cadastros Comerciais](04-cadastros-comerciais.md)
- [Campanhas](05-campanhas.md)
- [Pedidos](06-pedidos.md)
- [Relatórios](07-relatorios.md)
- [Módulo Financeiro](08-modulo-financeiro.md)
