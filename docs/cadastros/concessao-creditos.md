# Concessão de Créditos

`admin/cadastros/concessao-creditos.php` — acesso `comercial`, `supervisor` ou `tecnologia da informacao`.

## Campos do crédito

- Cliente (busca autocomplete)
- Crédito Referente (descrição, ex.: "Bônus de desempenho Q2/2026")
- Data*
- Valor (R$)*
- Observação Interna (textarea)

## Filtros

Busca por nome/código do cliente, data inicial e final (padrão: mês atual). Total do período exibido no cabeçalho.

## Colunas da listagem

Data, Código, Cliente, Crédito Referente, Observação Interna, Média de Atraso (semáforo igual ao Bônus MA), Valor do Crédito, Solicitante (usuário que criou), Ações, Log.

## Workflow

- **Aprovar** ou **Cancelar** — registrados em `creditos_logs`; último log exibido.
- **Regra de exclusão:** crédito com `valor_utilizado > 0` **não pode** ser excluído (botão desabilitado com tooltip).

## Uso do crédito no pedido

O crédito só pode ser usado sobre a **diferença = valor do pedido − detalhamento fiscal (NF)**. Ver a regra completa em [Regras de Negócio → Créditos a clientes](../10-regras-de-negocio.md#créditos-a-clientes).

## Modelo de dados

- `creditos` — id, cliente_id (FK), descricao, observacao_interna, valor, valor_utilizado, data, usuario_id (FK).
- `creditos_logs` — id, credito_id (FK), acao, usuario_nome, created_at.

---

Voltar ao [índice de Cadastros](../04-cadastros-comerciais.md).
