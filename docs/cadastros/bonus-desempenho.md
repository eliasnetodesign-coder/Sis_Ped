# Bônus de Desempenho

`admin/cadastros/bonus-desempenho.php` — acesso `comercial`, `financeiro` ou `tecnologia da informacao`.

Avaliação **trimestral** por cliente vs. meta cadastrada.

## Filtros

- Trimestre (1–4) e Ano — padrão = trimestre atual.
- Busca por nome/código do cliente.

## Colunas

Código, cliente, supervisor, canal, faturamento no trimestre (pedidos faturados, convertido para BRL), meta, atingimento %, último log.

## Workflow

- Ações: **Aprovar** ou **Cancelar** por cliente/trimestre/ano — registradas em `bonus_desempenho_logs`.
- Requer **aprovação do Comercial** e, depois, do **Financeiro**.
- Após aprovado por ambos, grava Valor %BD, Valor BD e Méd. Atrasos.
- Log exibe: status (aprovado/cancelado), nome do gestor, data/hora.

## Parâmetro

Percentual do bônus configurado no cadastro do cliente (`clientes.bonus_desempenho`, máx. **4%**).

Ver também [Metas](metas.md) e [Regras de Negócio](../10-regras-de-negocio.md#bônus-de-desempenho).

---

Voltar ao [índice de Cadastros](../04-cadastros-comerciais.md).
