# Tabela de Preços

`admin/cadastros/tabela-precos.php` — acesso `comercial`, `supervisor` ou `tecnologia da informacao`.

## Faixas de preço

Associa cada produto a três faixas:

- **Preço Padrão*** (`preco_padrao`)
- **Preço Network*** (`preco_network`)
- **Preço Auxiliar** (`preco_auxiliar`, opcional — base das moedas estrangeiras)

Busca por código ou descrição.

## Importar Excel

- Colunas: A=Código do Produto, B=Preço Padrão, C=Preço Network, D=Preço Auxiliar.
- Auto-detecção de cabeçalho; parseamento de formato BRL (`1.234,56`); upsert por produto.
- Preview de até 200 linhas; aviso quando o arquivo excede 200.
- Não é possível criar duas entradas para o mesmo produto (faz upsert).

## Câmbio de segurança e moedas estrangeiras

- No topo da tela edita-se o **câmbio de segurança**: `dolar_seguranca` e `euro_seguranca` (action `cambio`), na tabela `configuracoes`.
- Colunas **calculadas** em `tabela_precos`:
  - `preco_dolar = preco_auxiliar / dolar_seguranca`
  - `preco_euro = preco_auxiliar / euro_seguranca`
  - (NULL se o auxiliar estiver vazio ou o câmbio ≤ 0)
- Recalculadas ao salvar/importar preço e ao salvar o câmbio.

## Cotação do dia

- Botão **"Buscar cotação"** (`?cotacao=1`): consulta a AwesomeAPI (USD-BRL / EUR-BRL) e cacheia a cotação do dia em `configuracoes`.

Detalhes completos em [Multimoeda e i18n](../11-multimoeda-e-i18n.md#multimoeda-brl--usd--eur).

---

Voltar ao [índice de Cadastros](../04-cadastros-comerciais.md) · Ver também [Produtos](produtos.md), [Canal de Venda](canal-venda.md).
