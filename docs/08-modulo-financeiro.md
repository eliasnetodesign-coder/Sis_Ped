# 08 — Módulo Financeiro (Admin)

Módulo `admin/financeiro/*`. Acesso: `financeiro` ou `tecnologia da informacao` (menu); `comercial` também acessa as rotas diretamente.

## Clientes — Financeiro (`clientes.php`)

Visão dos clientes sob a ótica financeira (títulos, saldo devedor, limite de crédito).

## Contas a Receber (`contas-receber.php`)

- **Cards de resumo:** Em Aberto, Vencido, Pago (valores líquidos totais, sem filtro de período).
- Filtro por situação (Todos / Aberto / Vencido / Pago / Cancelado).
- Ordenado por `data_vencimento ASC`; linha em vermelho quando **vencido**.
- Colunas: Documento, Cliente, Valor, Desconto, **Líquido** (Valor − Desconto), Emissão, Vencimento, Pagamento, Situação, Ações.
- Operações: Criar / Editar / Excluir.

## Contas a Pagar (`contas-pagar.php`)

- Filtro por situação (botões); linha em vermelho quando vencido.
- Colunas: Documento, Fornecedor, Valor, Desconto, **Juros**, Emissão, Vencimento, Pagamento, Situação, Ações.
- Operações: Criar / Editar / Excluir.

## Fornecedores (`fornecedores.php`)

**Campos:** Código, Razão Social*, CNPJ, E-mail, Telefone, Cidade, UF, Status.
- Busca por razão social ou CNPJ. Operações: Criar / Editar / Excluir.

## Ordens de Pagamento (`ordens-pagamento.php`)

**Campos:** Número da Ordem, Descrição, Valor, Data, Status (pendente/aprovado/cancelado).
- Filtro por status. Operações: Criar / Editar / Excluir.

## Ordens de Investimento (`ordens-investimento.php`)

**Campos:** Número da Ordem, Descrição, Valor do Investimento, Retorno Esperado, Data, Status.
- **Cards de resumo** (apenas status `aprovado`): Total Investido, Retorno Esperado, **ROI Esperado** = `(retorno/investimento − 1) × 100%`.
- Filtro por status. Operações: Criar / Editar / Excluir.

> Os valores deste módulo permanecem em **R$** (tabelas próprias, sem conversão multimoeda). Ver [Multimoeda](11-multimoeda-e-i18n.md).

Relacionado: [Cadastros Comerciais](04-cadastros-comerciais.md), [Regras de Negócio](10-regras-de-negocio.md).
