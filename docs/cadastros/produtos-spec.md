# Cadastro de Produtos — Especificação

> Documento de especificação funcional. Define **como deve se comportar**,
> não como foi implementado. Atualizar sempre que uma decisão for tomada.
> Relacionado: `produtos.md` (resumo), `ncm-spec.md`, `tabela-precos.md`,
> `../06-pedidos.md`, `../10-regras-de-negocio.md`, `../11-multimoeda-e-i18n.md`.
> **Escopo:** CRUD de Produtos, composição de KIT e preço padrão inline.
> Preços Network, Auxiliar, Dólar e Euro são gerenciados em `tabela-precos.md`.

**Versão:** 2 · **Última atualização:** 2026-08-04 · **Status:** 🟢 Estável

---

## Campos de Identificação <a name="campos"></a>

### Regra definida
> O produto é identificado por **Código do Produto** (obrigatório e único) e por
> **Descrição em Português** (obrigatória); os demais campos são opcionais.

- **Código do Produto:** texto, **obrigatório** e **único** (UNIQUE no banco) — chave primária de negócio; usado como critério de upsert na importação e como referência em pedidos, campanhas e kit. Sistema rejeita criação/edição com código já existente entre produtos ativos.
- **Descrição (PT):** texto, **obrigatória** — rótulo principal do produto em todo o sistema; exibida na listagem, pedidos e portal do cliente.
- **Descrição (EN) / Descrição (ES):** texto, opcionais — usadas na área admin para referência interna multilíngue.
- **Código de Barras:** texto, opcional.
- **Nuance:** texto, opcional — variação/cor do produto.
- **NCM:** FK para `ncm` (ativos), opcional — vínculo fiscal; sem NCM as alíquotas fiscais são zero no Detalhamento Fiscal.
- **CEST:** texto, opcional — pode ser informado diretamente no produto, independentemente do CEST do NCM vinculado.
- **Status:** `ativo` | `inativo` — default `ativo`.

---

## Hierarquia: Linha, Grupo e Subgrupo <a name="hierarquia"></a>

### Regra definida
> Linha, Grupo e Subgrupo são campos de texto livre que organizam o catálogo;
> Grupo tem impacto funcional quando igual a "KIT".

- **Linha:** texto livre, opcional — agrupa produtos na listagem (cabeçalho colorido por linha) e nas abas do portal de pedidos.
- **Grupo:** texto livre, opcional — quando o valor normalizado (sem caracteres não-letras, uppercase) for `"KIT"`, ativa a aba de Composição do Kit no formulário. Exemplos que ativam: `"KIT"`, `"-KIT"`, `"A-KIT"`.
- **Subgrupo:** texto livre, opcional — refinamento do grupo; usado em filtros de campanha.
- **Uso em campanhas:** Linha, Grupo e Subgrupo são os eixos de filtro e alvo em campanhas de desconto e bonificação — ver `../05-campanhas.md`.

---

## Múltiplo de Venda <a name="multiplo"></a>

### Regra definida
> O Múltiplo define a quantidade mínima vendável por embalagem; o pedido
> aceita apenas múltiplos desse valor.

- **Múltiplo:** inteiro, **mínimo 1**, default `1` — exibido como badge na tela de pedido quando > 1; a quantidade total do item no pedido é sempre `qtd_visual × múltiplo`.
- **Valor 1:** produto sem restrição de embalagem (unidade avulsa).

---

## Flags de Canal de Venda <a name="flags-venda"></a>

### Regra definida
> Três flags booleanas controlam em quais canais o produto pode ser vendido;
> alteráveis inline na listagem sem recarregar a página.

- **Venda Distribuidor / Venda Varejo / Venda Exportação:** booleano (`0` | `1`), default `0` — determinam a elegibilidade do produto por canal; usados em filtros de campanha e como critério de exibição futura.
- **Toggle inline:** na listagem, cada flag é um badge clicável que envia requisição AJAX e atualiza o valor sem reload de página — evita editar o formulário completo apenas para ativar/desativar um canal.

---

## Descrição para a Área do Cliente <a name="desc-cliente"></a>

### Regra definida
> A descrição exibida ao cliente no portal é independente da descrição interna
> e possui versões em PT, EN e ES.

- **`desc_cliente_pt` / `desc_cliente_en` / `desc_cliente_es`:** textarea, opcionais — texto rico/longo exibido ao cliente no portal; diferente da `descricao_*` interna (admin).
- **Fallback:** se `desc_cliente_pt` estiver vazio, o portal exibe `descricao_pt` (comportamento definido em `../11-multimoeda-e-i18n.md`).
- **Aba separada:** editadas em aba própria ("Descrição Área do Cliente") no modal de produto, para não poluir os dados principais.

---

## Preço Padrão <a name="preco"></a>

### Regra definida
> O Preço Padrão é gerenciado inline no formulário de produto; os demais
> preços (Network, Auxiliar, Dólar, Euro) são gerenciados em Tabela de Preços.

- **Preço Padrão (`preco_padrao`):** decimal, opcional na criação — armazenado em `tabela_precos`; quando preenchido no formulário de produto, faz upsert em `tabela_precos`. Produto sem preço cadastrado exibe zero na listagem.
- **Demais preços:** Network, Auxiliar, Dólar e Euro são editados exclusivamente em `admin/cadastros/tabela-precos.php` — fora do escopo deste CRUD.

---

## Composição de KIT <a name="kit"></a>

### Regra definida
> Produtos cujo grupo normaliza para "KIT" possuem uma composição de
> produtos componentes gerenciada em `kit_composicao`; a composição é
> substituída por completo ao salvar.

- **Aba KIT:** exibida no modal apenas quando o campo Grupo normalizar para `"KIT"` — aparece dinamicamente ao digitar o grupo em novo produto, e ao abrir a edição de produto KIT.
- **Adicionar componente:** autocomplete por código ou descrição filtrando apenas produtos **ativos**; componente já presente na composição não aparece nas sugestões (evita duplicata).
- **Campos por componente:** Código do Produto (obrigatório via autocomplete), Nome (snapshot do nome atual), Quantidade (inteiro, mínimo 1, default 1).
- **Substituição completa:** ao salvar, todos os registros de `kit_composicao` para o `kit_codigo` são apagados e reinseridos — simplifica a edição; a quantidade é editável diretamente na tabela antes de salvar.
- **Nome do componente:** resolvido pelo nome atual do produto; se o produto componente for renomeado, o nome atualizado é exibido (com fallback ao snapshot gravado em `kit_composicao.nome`).
- **Produto não-KIT editado para KIT:** ao trocar o grupo para KIT, a aba aparece e a composição começa vazia.
- **Produto KIT editado para não-KIT:** a composição existente permanece em `kit_composicao` no banco, mas deixa de ser exibida e gerenciada — a composição só é sobrescrita quando o grupo retorna a KIT e o produto é salvo.

---

## Filtros e Listagem <a name="listagem"></a>

### Regra definida
> A listagem é agrupada por Linha, filtrável por busca, Linha e Status;
> exibe por padrão apenas produtos ativos.

- **Agrupamento por Linha:** produtos são ordenados por Linha e depois por Descrição PT; cada mudança de Linha gera um cabeçalho de separação visual — facilita a navegação em catálogos grandes.
- **Busca:** correspondência parcial em Código do Produto ou Descrição PT.
- **Filtro de Linha:** select dinâmico com as linhas distintas cadastradas.
- **Filtro de Status:** default `ativo`; opções: Ativo, Inativo, Todos.
- **Colunas:** Código, Descrição, Preço Padrão, V.Distribuidor (badge toggle), V.Varejo (badge toggle), V.Exportação (badge toggle), Status, Ações.

---

## Exportação Excel <a name="export"></a>

### Regra definida
> Exporta a listagem atual (com filtros aplicados) para `.xlsx`.

- **Formato:** arquivo `produtos_AAAA-MM-DD.xlsx`.
- **Colunas:** Código, Descrição PT, Linha, Grupo, Subgrupo, Cód. Barras, Múltiplo, Preço Padrão, Venda Distribuidor, Venda Varejo, Venda Exportação, NCM, CEST, Nuance, Status.
- **Largura de coluna:** automática com máx. 45 caracteres.
- **Estado vazio:** sem registros, aborta com aviso "Nenhum registro para exportar".

---

## Importação Excel <a name="import"></a>

### Regra definida
> Importa produtos de planilha `.xlsx/.xls` por drag-and-drop, fazendo upsert
> por Código do Produto, com prévia antes de gravar.

- **Leitura:** primeira aba; **ignora as 5 primeiras linhas**; dados a partir da **linha 6**.
- **Mapeamento por índice de coluna:**

  | Coluna | Campo |
  |--------|-------|
  | A (0) | Código do Produto |
  | B (1) | Descrição PT |
  | C (2) | Status |
  | E (4) | Código de Barras |
  | O (14) | Grupo |
  | P (15) | Subgrupo |
  | Q (16) | Linha |
  | AO (40) | NCM (código) |
  | BF (57) | Múltiplo |
  | CF (83) | CEST |
  | CG (84) | Desc. Cliente PT |
  | CH (85) | Desc. Cliente EN |
  | CI (86) | Desc. Cliente ES |

- **Linha ignorada:** sem Código E sem Descrição PT — contabilizada como ignorada.
- **Upsert:** localiza por Código do Produto; se existir, atualiza; senão, insere.
- **Campos atualizados no upsert:** Código, Linha, Grupo, Subgrupo, Cód. Barras, Descrição PT, Múltiplo, NCM, CEST, Status, Desc. Cliente PT/EN/ES. **Não atualizados:** Nuance, Vendas Distribuidor/Varejo/Exportação, Preço Padrão — preserva configurações comerciais já definidas.
- **Novos registros:** recebem Nuance vazio, flags de venda `0`, sem preço.
- **NCM via código:** normaliza o código (remove não-dígitos) e busca em `ncm`; se não encontrar, cria um registro mínimo de NCM com o código informado — requer preenchimento manual posterior.
- **Linha/Grupo/Subgrupo:** remove prefixos numéricos (ex: `"01 LINHA"` → `"LINHA"`).
- **Prévia:** exibe até **200 linhas**; se houver mais, avisa que todos serão importados.
- **Relatório final:** total de inseridos / atualizados / ignorados.

---

## Exclusão <a name="excluir"></a>

### Regra definida
> A exclusão é sempre um soft delete (`status = inativo`), bloqueada se o
> produto estiver vinculado a pedidos ativos.

- **Comportamento:** a ação marca o registro como `status = 'inativo'` — preserva o histórico em pedidos já emitidos e mantém os dados disponíveis para exportação e auditoria.
- **Bloqueio:** se existir ao menos um pedido com `status` ativo (`comercial`, `financeiro` ou `faturamento`) referenciando o produto, a exclusão é **bloqueada** com mensagem de erro. Pedidos já `faturado` ou `cancelado` não impedem a exclusão.
- **Aviso:** quando não houver bloqueio, exibe confirmação antes de efetivar o soft delete.
- **`kit_composicao`:** não é apagada no soft delete — o produto pode ser reativado e retomar sua composição.
- **Produto inativo em KIT:** ao montar o autocomplete de componentes de KIT, apenas produtos **ativos** aparecem nas sugestões; composições que já referenciam um produto inativo permanecem gravadas, mas o produto inativo fica visível na composição com indicação de status.
- **Listagem:** produtos com `status = 'inativo'` somem do filtro padrão; reaparecem ao filtrar por "Inativo" ou "Todos".
- **Impacto em Legados:** o protótipo usava hard delete sem coluna `status` em `produtos`; a coluna `status` já existe — nenhuma migration adicional necessária para o soft delete.

---

## Critérios de Aceite

- [ ] Dado um código de produto já existente entre os ativos, quando o usuário tenta criar outro produto com o mesmo código, então o sistema rejeita com mensagem de código duplicado.
- [ ] Dado um produto com `grupo = "-KIT"`, quando o formulário abre, então a aba KIT é exibida automaticamente com a composição atual.
- [ ] Dado um produto com `grupo = "Shampoo"`, quando o formulário abre, então a aba KIT **não** é exibida.
- [ ] Dado a aba KIT com composição preenchida, quando o usuário salva, então os registros anteriores de `kit_composicao` são substituídos pelos novos.
- [ ] Dado o badge V.Distribuidor clicado na listagem, quando a requisição retorna sucesso, então o badge alterna entre Sim/Não sem recarregar a página.
- [ ] Dado a listagem sem filtros aplicados, quando a tela carrega, então exibe apenas produtos ativos, agrupados por Linha e ordenados por Descrição PT.
- [ ] Dado um produto vinculado a pedido com status `comercial`, quando o usuário tenta excluir, então o sistema bloqueia com mensagem de erro — o soft delete não é efetuado.
- [ ] Dado um produto sem pedidos ativos (ou com pedidos apenas `faturado`/`cancelado`), quando o usuário confirma a exclusão, então o produto recebe `status = inativo` e some da listagem padrão; a composição em `kit_composicao` permanece intacta.
- [ ] Dado produto sem Preço Padrão informado na criação, quando salvo, então o produto é criado sem registro em `tabela_precos` e exibe zero na listagem — não bloqueia.
- [ ] Dado uma planilha com coluna AO = `"3304.20"`, quando importada, então o NCM é localizado pelo código normalizado `"330420"`; se não encontrado, cria NCM mínimo.
- [ ] Dado uma linha da planilha sem Código e sem Descrição PT, quando importada, então é ignorada e contabilizada no relatório.

---

## Dependências e Impactos Cruzados

- **NCM** (`ncm-spec.md`): `ncm_id` FK de `produtos` → soft delete de NCM é bloqueado por produto ativo vinculado.
- **Tabela de Preços** (`tabela-precos.md`): `preco_padrao` é gerenciado inline aqui; demais preços gerenciados no CRUD próprio.
- **Pedidos** (`../06-pedidos.md`): `produto_id` FK em `pedidos`; `linha`, `grupo`, `subgrupo` usados em campanhas; `multiplo` governa quantidade no pedido.
- **Campanhas** (`../05-campanhas.md`): `linha`, `grupo`, `subgrupo`, `produto_id` são os eixos de gatilho e alvo — renomear uma linha afeta o matching de campanhas ativas.
- **Portal do Cliente**: `desc_cliente_pt/en/es` e `multiplo` exibidos na tela de pedido; `vendas_distribuidor/varejo/exportacao` podem filtrar produtos visíveis por canal (implementação futura).
- **Kit Composicao**: produto excluído que seja componente de um KIT: comportamento definido na decisão de exclusão.

---

## Índice de Decisões já tomadas

- **Identificação:** Código único (UNIQUE entre ativos), Descrição PT obrigatória; demais campos opcionais — → [Ir para Regra](#campos)
- **Hierarquia:** Linha/Grupo/Subgrupo texto livre; grupo normalizado para "KIT" ativa composição — → [Ir para Regra](#hierarquia)
- **Múltiplo:** inteiro ≥ 1, default 1 — → [Ir para Regra](#multiplo)
- **Flags de venda:** 3 booleanas, default 0; toggle inline AJAX na listagem — → [Ir para Regra](#flags-venda)
- **Desc. Cliente:** textarea PT/EN/ES, opcional, aba separada — → [Ir para Regra](#desc-cliente)
- **Preço Padrão:** opcional, upsert em `tabela_precos`; demais preços fora deste CRUD — → [Ir para Regra](#preco)
- **KIT:** composição em `kit_composicao`, substituição completa ao salvar, autocomplete de produtos ativos — → [Ir para Regra](#kit)
- **Listagem:** agrupada por Linha; busca código/descrição; filtro Linha e Status; default ativo — → [Ir para Regra](#listagem)
- **Exportação:** `.xlsx` com 15 colunas; largura auto máx. 45 chars — → [Ir para Regra](#export)
- **Importação:** ignora 5 linhas; mapeamento por índice; upsert por código; NCM criado automaticamente se não existir; preview 200; relatório final — → [Ir para Regra](#import)
- **Exclusão:** soft delete sempre (`status = inativo`); bloqueado por pedido ativo (`comercial`/`financeiro`/`faturamento`); `kit_composicao` preservada — → [Ir para Regra](#excluir)

---

## Pendências para decidir <a name="pendencias"></a>

Nenhuma pendência aberta — todas as decisões deste documento foram fechadas na v2.

---

## Changelog

| Versão | Data | O que mudou |
|---|---|---|
| 1 | 2026-08-04 | Criação inicial — spec do CRUD de Produtos, derivado do código do protótipo (`admin/cadastros/produtos.php`) e dos docs `produtos.md`, `tabela-precos.md`, `../06-pedidos.md`. 2 pendências identificadas. |
| 2 | 2026-08-04 | Pendências fechadas: Código do Produto UNIQUE entre ativos; exclusão sempre soft delete bloqueado por pedido ativo (`comercial`/`financeiro`/`faturamento`), `kit_composicao` preservada. Status → 🟢 Estável. |
