# Bônus de Material de Apoio (MA)

`admin/cadastros/bonus-ma.php` — acesso `comercial`, `supervisor` ou `tecnologia da informacao`.

Avaliação **mensal** por cliente.

## Período

- Padrão: mês **anterior** ao atual (o bônus é retroativo).
- Navegação por Mês/Ano com botões "Mês Anterior" e "Próximo".

## Filtros

- Busca por nome/código do cliente.
- Somente clientes com `material_apoio > 0` e status ativo.

## Colunas

Código, cliente, supervisor, canal, desconto do canal, faturamento do mês (BRL), % MA, valor do bônus MA, **média de atraso** (dias médios de atraso nos pagamentos — semáforo: verde ≤3d, amarelo ≤5, vermelho >6d), ação, log.

- **Cards de resumo:** período, clientes elegíveis, total faturado, total bônus MA.
- Total de rodapé: faturamento total e total de bônus do período.

## Workflow

- Ações: **Aprovar** ou **Cancelar** — registradas em `bonus_ma_logs` (com `valor_utilizado`).
- Última ação exibida por cliente/mês.
- Após aprovado, grava supervisor, Canal, Desconto Canal, %MA, Valor MA, Méd. Atraso.

## Cálculo

`valor do bônus = faturamento do mês × material_apoio / 100` (`clientes.material_apoio`, máx. **5%**).

## Notificação ao cliente

Popup no **primeiro acesso ao dashboard** do mês seguinte, quando aprovado. Ver [Portal do Cliente](../09-portal-cliente.md#dashboard-dashboardphp).

---

Voltar ao [índice de Cadastros](../04-cadastros-comerciais.md).
