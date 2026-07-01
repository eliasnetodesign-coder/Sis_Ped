# 05 — Campanhas

Módulo `admin/cadastros/campanhas.php`. Reestruturado em **jun/2026**. Convivem dois modelos:

> **Se a campanha tem linhas em `campanha_condicoes` ⇒ modelo novo; senão ⇒ legado.**

As migrações são apenas **aditivas** e o **PHP (`config.php`) é a fonte de verdade** da avaliação — o JS apenas espelha para preview. Este documento descreve o **modelo novo**.

## Cabeçalho da campanha

| Campo | Descrição |
|-------|-----------|
| Código da Campanha* | Agrupa todas as linhas da campanha (`codigo_campanha`) |
| Canal de Venda | Opcional — "Todos" ou canal específico; com canal, afeta só clientes desse canal |
| Ativa/Inativa | `campanhas.ativo`; campanhas inativas são ignoradas na avaliação |
| Tipo de Campanha | **Desconto** ou **Bonificação** |

No modelo novo, o cabeçalho grava produto/linha/grupo/subgrupo = NULL e `quantidade = 0` (o gatilho fica nas condições).

## Condições (gatilho) — `campanha_condicoes`

Cada condição é um **filtro composto** combinado em **E**:

- **Linha + Grupo + Subgrupo + Produto** (cada um opcional, "— qualquer —").
  - Ex.: "Linha Itallian Color · Grupo Coloração".
- **Modo** (`criterio_modo`): **Quantidade** ou **Valor**.
- **Mínimo** (`quantidade` ou `valor_min`): somado entre os itens do pedido que satisfazem o filtro.

**Todas as condições combinam em E** — ex.: "Grupo Coloração ≥ 10 un." **E** "Grupo Oxidante ≥ 5 un.". A campanha só dispara quando **todas** são atingidas.

Colunas: `cond_linha`, `cond_grupo`, `cond_subgrupo`, `cond_produto_id`, `criterio_modo`, `quantidade`, `valor_min`. (As colunas `criterio_tipo`/`criterio_valor` são fallback legado de filtro único.)

## Tipo Desconto

- Campo **Desconto %** + **alvos opcionais** (`campanha_desconto_alvo`) que definem **onde** o desconto incide (por linha/grupo/subgrupo/produto).
- Sem alvo definido, o desconto recai sobre os itens que satisfazem as condições.
- Aplicado de forma **multiplicativa** sobre o preço já líquido dos demais descontos. Ver [Regras de Negócio](10-regras-de-negocio.md#descontos-no-pedido).

## Tipo Bonificação

- **Multiplicador:** `mult = floor(qtd_alvo / mínimo)` (menor múltiplo entre as condições).
- **Modo fixo (lista):** produtos + quantidades fixos como brinde (`campanha_bonificacao`).
- **Modo selecionável:** o cliente escolhe o brinde até um **limite** (`bonif_limite_tipo`/`bonif_limite_valor`, por quantidade ou valor). Origem dos produtos (`bonif_selec_modo`):
  - **lista** de produtos (`campanha_bonificacao`), ou
  - **pool por categoria** (`campanha_bonif_pool`, filtro por linha/grupo/subgrupo/produto).

### Geração do pedido bonificado

Ao finalizar uma **venda nova** que aciona a campanha, o sistema cria um **pedido bonificado separado**:
- `tipo_venda = bonificacao`, lote próprio, status `comercial`, `cotacao = NULL`.
- Valor pelo preço **Network** (`criarPedidoBonificado` / `gerarBonificacaoCampanha`).
- **Não** ocorre em edição de pedido.
- Bonificação **selecionável** redireciona para `cliente/bonificacao-selecionavel.php` (usado por cliente **e** admin) antes de concluir.

## Formulário

Modal `modal-xl` com **exibição progressiva** (`campStep()`): Código → Canal → Ativa/Inativa → Tipo.
- **Condições:** tabela com selects de Linha/Grupo/Subgrupo/Produto + Modo + Mínimo (linhas adicionáveis).
- **Desconto:** percentual + alvos.
- **Bonificação:** fixo (lista) ou selecionável (lista/pool) + limite.

Salvar **substitui** todas as linhas do código; excluir remove todas elas. A listagem é agrupada por código (condições, canal, tipo, desconto/bonificação, status).

## Helpers centrais (`config.php`)

| Função | Papel |
|--------|-------|
| `campanhasAgrupadas()` | Filtra inativas, carrega condições |
| `ctxCampanha($itens, $canal)` | Monta o contexto do pedido (qtd/valor por categoria + lista de itens normalizada) |
| `avaliarCampanhaTrigger($rows, $conds, $ctx)` | Avalia o gatilho (E entre condições; filtro composto) |
| `avaliarCampanhasDescontoAvancadas($ctx)` | Resolve alvos do desconto |
| `detectarBonificacaoSelecionavel($itens, $canal)` | Monta o pool selecionável |

> **Cuidado (legado):** loops legados por item devem barrar `camp['quantidade'] <= 0` para não aplicar o cabeçalho do modelo novo (quantidade=0, categorias NULL) a **todos** os itens.

Relacionado: [Pedidos](06-pedidos.md), [Multimoeda e i18n](11-multimoeda-e-i18n.md), [Regras de Negócio](10-regras-de-negocio.md).
