# 11 — Multimoeda, i18n e Bônus de Exportação

## Multimoeda (BRL / USD / EUR)

A **moeda do cliente** (`clientes.moeda`) determina em qual moeda o pedido é feito.

### Seleção de preço

`colPrecoMoeda($moeda, $bonificacao)` mapeia a moeda → coluna de preço:

| Moeda | Coluna |
|-------|--------|
| BRL | `preco_padrao` |
| USD | `preco_dolar` (calculada) |
| EUR | `preco_euro` (calculada) |
| Bonificação (qualquer moeda) | `preco_network` |

### Câmbio de segurança

- `dolar_seguranca` e `euro_seguranca` ficam na tabela `configuracoes` (chave/valor), editados no topo de `admin/cadastros/tabela-precos.php` (action `cambio`). Helpers `getConfig()`/`setConfig()`.
- Colunas **calculadas** em `tabela_precos`:
  - `preco_dolar = preco_auxiliar / dolar_seguranca`
  - `preco_euro = preco_auxiliar / euro_seguranca`
  - (NULL se o auxiliar estiver vazio ou o câmbio ≤ 0)
- Recalculadas ao salvar/importar preço e ao salvar o câmbio.

### Cotação do dia

- `buscarCotacaoAPI()` consulta a **AwesomeAPI** (USD-BRL / EUR-BRL via cURL); `cotacaoDia($moeda)` cacheia a cotação do dia em `configuracoes` (`cotacao_usd`, `cotacao_eur`, `cotacao_data`) — **1 chamada por dia**, com fallback ao último valor.
- Botão "Buscar cotação" em `tabela-precos.php` (`?cotacao=1`) atualiza o cache manualmente.
- Na criação do pedido grava-se `pedidos.cotacao` (cotação da moeda; **NULL** para BRL e bonificação).

### Conversão e exibição

- `pedidos.moeda` é gravada na criação (todos os INSERTs). `simboloMoeda()` → R$ / US$ / €; `moedaBR()` / `moedaCorrente()` formatam por moeda.
- **Totais em BRL:** toda agregação de `pedidos.valor_total` converte USD/EUR → BRL:

  ```sql
  valor_total * (CASE WHEN moeda <> 'BRL' AND cotacao > 0 THEN cotacao ELSE 1 END)
  ```

  Aplicada em: cards de dashboard/pedidos, relatórios de faturamento, Bônus MA e Bônus Desempenho. Bonificação (`cotacao = NULL`) fica **fora** da conversão.
- Áreas admin (detalhe e lista) exibem a conversão `valor × cotacao` em R$ além do valor em moeda estrangeira.

### O que permanece em R$ (base BR)

Seção fiscal/NF (ICMS/IPI/PIS/COFINS, preço Network), saldos de crédito e o financeiro (contas a receber/pagar têm tabelas próprias).

## Internacionalização (i18n) — Área do Cliente

A **área do cliente** é traduzida conforme `clientes.idioma` (pt|en|es). Admin e telas pré-login ficam **sempre em PT**.

- `idiomaAtual()` resolve o idioma do cliente logado (query cacheada; pt para admin/visitante).
- `t($pt, ...$args)` traduz usando **a própria frase PT como chave** — em `pt` devolve a original; em `en`/`es` busca em `lang.php` (arrays `['en'=>[...], 'es'=>[...]]`) com fallback para PT.
- `et()` = `e(t())` (já escapa HTML); `htmlLang()` define `<html lang>`.
- **Placeholders:** `%s` (sprintf) no PHP; em JS usa `%1..%4` + helper `_tfmt()` / objeto `T`. Strings JS com `\n` usam aspas duplas.
- `statusBadge()` traduz os rótulos de status.

> **Produtos têm tradução própria** nos cadastros (`produtos.desc_cliente_pt/_en/_es`) — **não** usam `t()`.

Arquivos instrumentados: `layout/header.php`, `layout/footer.php`, `trocar-senha.php` e todo `cliente/*.php`.

## Bônus de Exportação

Clientes do canal **Exportação** (`canal_venda` cujo nome contém "export", via `canalEhExportacao()`), ao finalizar uma **venda nova na área do cliente**, recebem um **bônus selecionável por valor = 5% do valor da venda** (`BONUS_EXPORTACAO_PCT`, `bonusExportacaoSelecionavel()`), escolhendo entre **todos os produtos ativos**.

- **Respeita a moeda do cliente:** limite e preços na moeda do cliente (`colPrecoMoeda`), sem conversão ≈R$ na área do cliente.
- Reutiliza `cliente/bonificacao-selecionavel.php`; ao confirmar, `criarPedidoBonificado()` grava o pedido bonificado (status comercial) com o mesmo preço exibido na seleção (fallback `preco_network` quando não há preço na moeda); `cotacao = NULL`.
- **Escopo:** apenas vendas novas do cliente (não em edição, não em bonificação/MA).

Relacionado: [Campanhas](05-campanhas.md), [Pedidos](06-pedidos.md), [Portal do Cliente](09-portal-cliente.md).
