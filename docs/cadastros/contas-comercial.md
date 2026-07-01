# Contas a Receber / Pagar — Comercial

Telas de contas acessíveis pelo módulo Comercial (`requireAdmin()`). Espelham as telas do [Módulo Financeiro](../08-modulo-financeiro.md).

## Contas a Receber — Comercial (`admin/cadastros/contas-receber.php`)

**Campos:** Nº Documento, Cliente, Valor a Receber, Descontos, Data Emissão, Data Vencimento, Data Pagamento, Situação (aberto / pago / vencido / cancelado).

- Operações: Criar / Editar / Excluir.
- Filtro por situação (botões).
- Coluna **Líquido** = Valor − Descontos.
- Linha destacada em vermelho quando a situação = vencido.

## Contas a Pagar — Comercial (`admin/cadastros/contas-pagar.php`)

**Campos:** Nº Documento, Fornecedor (dropdown ativos), Valor a Pagar, Descontos, Juros, Data Emissão, Data Vencimento, Data Pagamento, Situação.

- Operações: Criar / Editar / Excluir.
- Filtro por situação (botões).
- Linha destacada em vermelho quando vencido.

---

Voltar ao [índice de Cadastros](../04-cadastros-comerciais.md) · Ver também [Módulo Financeiro](../08-modulo-financeiro.md).
