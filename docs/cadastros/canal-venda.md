# Canal de Venda

`admin/cadastros/canal-venda.php` — acesso `comercial`, `supervisor` ou `tecnologia da informacao`.

## Campos

- **Canal***
- **Faixa de Faturamento** (texto, ex.: "Acima de R$ 50.000")
- **Desconto Máximo %** (`canal_venda.desconto`)
- **Margem de Negociação %** (`canal_venda.margem_negociacao`)

## Regras

- **Desconto Máximo %** serve de teto para o `desconto_canal` dos clientes.
- **Margem de Negociação %** serve de teto para o **Desconto Comercial** aplicável por item no pedido (ver [Pedidos](../06-pedidos.md#detalhe-do-pedido-adminpedidophp) e [Regras de Negócio](../10-regras-de-negocio.md#descontos-no-pedido)).
- O canal cujo nome contém **"export"** é tratado como canal de **Exportação** e concede o bônus de exportação (ver [Multimoeda e i18n](../11-multimoeda-e-i18n.md#bônus-de-exportação)).

---

Voltar ao [índice de Cadastros](../04-cadastros-comerciais.md) · Ver também [Clientes](clientes.md).
