# NCM

`admin/cadastros/ncm.php` — acesso `comercial`, `supervisor` ou `tecnologia da informacao`.

Tabela auxiliar fiscal, vinculada ao produto.

## Campos

- Nome da Categoria
- **NCM*** (código)
- CEST
- **IPI** (%, 4 casas decimais)
- **PIS / COFINS (Network)** — `ncm.pis` / `ncm.cofins`
- **PIS / COFINS (Accademia)** — `ncm.pis_accademia` / `ncm.cofins_accademia`

## Uso fiscal no pedido

O cadastro NCM alimenta o **Detalhamento Fiscal** do pedido:

- **IPI / PIS / COFINS** vêm de `ncm.ipi/pis/cofins`.
- **ICMS** vem da tabela `ncm_estados` pela UF do cliente: usa `icms_local` quando a UF do cliente = `EMPRESA_UF`, senão `icms_interestadual`. Sem NCM/estado cadastrado, alíquota 0.
- **Total da Nota Fiscal** = Total dos Produtos **+ IPI** (ICMS, PIS e COFINS estão embutidos no preço e não somam à NF).

Também alimenta o botão **Impostos** do pedido: o bloco da empresa "Network" usa `ncm.pis/cofins`; os blocos das demais empresas cadastradas usam `ncm.pis_accademia/cofins_accademia`. Ver [Pedidos → Detalhamento Fiscal](../06-pedidos.md#detalhamento-fiscal-modal) e [Pedidos → Botão Impostos](../06-pedidos.md#detalhe-do-pedido-adminpedidophp).

## Interface

Cada NCM é um item de **accordion** (mesmo padrão do Grupo de Empresas).

---

Voltar ao [índice de Cadastros](../04-cadastros-comerciais.md).
