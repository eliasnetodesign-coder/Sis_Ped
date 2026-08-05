# Tabela de Preços — Especificação

> Documento de especificação funcional. Define **como deve se comportar**,
> não como foi implementado. Atualizar sempre que uma decisão for tomada.
> Relacionado: `tabela-precos.md` (resumo), `produtos-spec.md`,
> `cotacao-cambio-spec.md`, `../06-pedidos.md`, `../11-multimoeda-e-i18n.md`.
> **Escopo:** CRUD de preços por produto (Padrão/Network/Auxiliar), câmbio
> de segurança, cálculo de preços em Dólar/Euro e importação em massa.
> Identificação/CRUD do produto em si é gerenciado em `produtos-spec.md`.
> A consulta/cache da cotação diária USD/EUR via AwesomeAPI (botão "Buscar
> cotação") é documentada separadamente em `cotacao-cambio-spec.md`.

**Versão:** 5 · **Última atualização:** 2026-08-04 · **Status:** 🟢 Estável

---

## Acesso <a name="acesso"></a>

### Regra definida
> Acesso restrito às mesmas roles do módulo Comercial como um todo.

- **Roles permitidas:** `comercial`, `supervisor`, `tecnologia da informacao` (`requireComercial()`) — não há um nível de acesso mais restrito específico para preços, mesmo sendo dado comercialmente sensível.
- **Bloqueio:** usuário fora dessas roles é redirecionado ao dashboard com mensagem "Acesso restrito ao módulo Comercial."
- **Impacto em Legados:** não aplicável (regra de acesso não é versionada no banco).

---

## Estrutura de Preços por Produto <a name="estrutura"></a>

### Regra definida
> Cada produto tem no máximo uma linha em `tabela_precos`, associando três
> faixas editáveis e duas faixas calculadas.

- **Preço Padrão** (`preco_padrao`): decimal(10,2), obrigatório no formulário desta tela (default `0` na criação) — base para vendas em BRL.
- **Preço Network** (`preco_network`): decimal(10,2), opcional (NULL) — usado como preço de bonificação (qualquer moeda do cliente) e como referência fiscal "somente BR" citada em `../11-multimoeda-e-i18n.md`.
- **Preço Auxiliar** (`preco_auxiliar`): decimal(10,2), opcional (NULL) — não é exibido/usado diretamente em pedidos; serve exclusivamente de base para calcular Preço Dólar e Preço Euro.
- **Preço Dólar / Preço Euro** (`preco_dolar`, `preco_euro`): calculados, não editáveis diretamente — ver regra de Câmbio de Segurança.
- **Status da linha** (`status`): enum `ativo`/`inativo`, `NOT NULL DEFAULT 'ativo'` — controla o soft delete do registro de preço, ver regra de Exclusão.
- **Um registro por produto, sem constraint de unicidade no banco:** a unicidade é garantida apenas em nível de aplicação (`SELECT id FROM tabela_precos WHERE produto_id=?` antes de inserir); a tabela **não** tem `UNIQUE KEY` em `produto_id`, apenas o índice simples da FK.
- **Duas telas escrevem em `tabela_precos`:** esta tela (todas as faixas) e o formulário de Produto (`produtos.php`, apenas `preco_padrao` — cria a linha com as demais faixas em NULL se ainda não existir).
- **Unicidade garantida em nível de banco:** `tabela_precos.produto_id` recebe uma constraint `UNIQUE KEY`; o upsert (aqui e em `produtos.php`) passa a usar `INSERT ... ON DUPLICATE KEY UPDATE` em vez de `SELECT` seguido de `INSERT`/`UPDATE` — elimina a janela de corrida entre dois salvamentos simultâneos do mesmo produto, que hoje pode gerar duas linhas.
- **Impacto em Legados:** produtos cadastrados antes de um preço ser salvo (por aqui ou pelo formulário de Produto) não têm linha em `tabela_precos`; nesse caso, `admin/novo-pedido.php` usa fallback `produtos.vendas_varejo` (coluna legada) — ver regra de Uso em Pedidos. Antes de aplicar a `UNIQUE KEY`, é preciso auditar a base atual e resolver manualmente eventuais duplicatas de `produto_id` já existentes (mantendo a linha mais recente), pois a migration falhará se houver duplicidade.

---

## Câmbio de Segurança e Preços em Moeda Estrangeira <a name="cambio"></a>

### Regra definida
> Um único câmbio de segurança global (Dólar e Euro) converte o Preço
> Auxiliar em Preço Dólar/Euro de todos os produtos ao mesmo tempo, sem
> histórico de câmbios anteriores.

- **Armazenamento:** `dolar_seguranca` e `euro_seguranca` em `configuracoes` (chave/valor via `getConfig()`/`setConfig()`) — não são colunas próprias nem versionadas.
- **Fórmula:** `preco_dolar = ROUND(preco_auxiliar / dolar_seguranca, 2)` e `preco_euro = ROUND(preco_auxiliar / euro_seguranca, 2)`.
- **Casos NULL:** se `preco_auxiliar` for NULL ou o câmbio correspondente for `≤ 0`, o preço calculado fica NULL — nunca gera erro/divisão por zero.
- **Recalculo em massa:** salvar o câmbio (ação `cambio`) dispara um único `UPDATE` sem `WHERE`, recalculando `preco_dolar`/`preco_euro` de **todos** os produtos da tabela de uma vez.
- **Recalculo pontual:** salvar/editar/importar um preço individual também recalcula `preco_dolar`/`preco_euro` daquele produto, usando o câmbio de segurança vigente no momento.
- **Confirmação:** salvar o câmbio exige confirmação via `confirm()` do navegador, alertando que recalculará os preços de todos os produtos.
- **Impacto em Legados:** não há histórico de câmbios usados anteriormente — ao alterar `dolar_seguranca`/`euro_seguranca`, os pedidos **já criados** não são afetados (valor congelado na criação, ver Uso em Pedidos); apenas os preços futuros exibidos em `tabela_precos` mudam.

---

## Uso do Preço na Criação de Pedidos <a name="uso-pedidos"></a>

### Regra definida
> A moeda do cliente e o tipo de venda determinam qual coluna de
> `tabela_precos` vira o preço do item; o valor é congelado no pedido no
> momento da criação.

- **Mapeamento (`colPrecoMoeda`):** BRL → `preco_padrao`; USD → `preco_dolar`; EUR → `preco_euro`; bonificação (qualquer moeda do cliente) → `preco_network` — detalhado em `../11-multimoeda-e-i18n.md`.
- **Fallback:** produto sem linha em `tabela_precos` (ou com a coluna correspondente NULL) usa `produtos.vendas_varejo`.
- **Congelamento:** `pedidos.valor_total` é gravado no momento da criação do pedido; alterações posteriores em `tabela_precos` **não** afetam pedidos já criados.
- **Impacto em Legados:** nenhuma migração necessária; o fallback `vendas_varejo` cobre produtos legados sem preço cadastrado nesta tela.

---

## Cadastro Individual — Adicionar/Editar <a name="cadastro"></a>

### Regra definida
> Adicionar exige selecionar o produto; editar não permite trocar o
> produto vinculado à linha.

- **Adicionar (`criar`):** campos Produto (select obrigatório, lista apenas produtos `status='ativo'`), Preço Padrão (obrigatório, default `0`), Preço Network (opcional), Preço Auxiliar (opcional) — se já existir linha para o produto escolhido, a ação faz `UPDATE` em vez de duplicar (upsert por `produto_id`).
- **Reativação via upsert:** se a linha existente para o produto estiver com `status='inativo'` (soft-deleted anteriormente), o mesmo upsert (`ON DUPLICATE KEY UPDATE`) volta `status` para `'ativo'` junto com a atualização dos valores — não cria uma segunda linha e não exige um passo manual de "restaurar".
- **Editar (`editar`):** identifica a linha por `id` (não por `produto_id`); o campo Produto não é reenviado — não é possível trocar o produto de uma linha existente pela UI.
- **Validação de valores negativos:** o backend rejeita explicitamente Preço Padrão, Network ou Auxiliar `< 0` nas ações `criar` e `editar` — retorna erro via `flash('danger', ...)` e não grava a linha; o `min="0"` do HTML deixa de ser a única barreira.
- **Impacto em Legados:** não aplicável.

---

## Listagem e Busca <a name="listagem"></a>

### Regra definida
> Lista os produtos que já têm preço cadastrado, unidos por produto e
> ordenados por descrição.

- **Fonte:** `INNER JOIN` entre `tabela_precos` e `produtos` — produtos **sem** linha em `tabela_precos` não aparecem nesta listagem, mesmo que ativos. Produtos com linha mas `tabela_precos.status='inativo'` (preço soft-deleted) também somem da listagem padrão.
- **Busca:** correspondência parcial (`LIKE`) em Código do Produto OU Descrição PT.
- **Ordenação:** por Descrição PT.
- **Colunas:** Código, Produto, Preço Padrão, Preço Network, Preço Auxiliar, Preço Dólar, Preço Euro, Ações — colunas opcionais/calculadas exibem "—" quando NULL.
- **Filtro de Status do produto:** select igual ao de Produtos (Ativo / Inativo / Todos), **default `ativo`** — filtra pelo `produtos.status`; produtos inativos com preço cadastrado somem da listagem padrão e só reaparecem ao filtrar por "Inativo" ou "Todos".
- **Filtro de Status do Preço:** segundo select, independente do anterior (Ativo / Inativo / Todos), **default `ativo`** — filtra pelo `tabela_precos.status`. Os dois filtros combinam com **AND**: uma linha só aparece se o status do produto **e** o status do preço estiverem dentro dos filtros selecionados.
- **Impacto em Legados:** telas/relatórios que hoje dependem de ver produtos inativos por padrão nesta listagem passam a precisar do filtro explícito "Todos" após esta mudança. Linhas existentes em `tabela_precos` recebem `status='ativo'` na migration (nenhuma some da listagem por causa do novo filtro).

---

## Importação Excel <a name="import"></a>

### Regra definida
> Importa por drag-and-drop de planilha `.xlsx/.xls`, upsert por Código do
> Produto, com detecção automática de cabeçalho e prévia antes de gravar.

- **Colunas fixas por posição:** A = Código do Produto, B = Preço Padrão, C = Preço Network, D = Preço Auxiliar — não há mapeamento configurável.
- **Detecção de cabeçalho:** se a célula A1 estiver vazia ou não for numérica, a primeira linha é tratada como cabeçalho e ignorada; caso contrário, os dados começam já na linha 1.
- **Parseamento BRL:** valores no formato `"1.234,56"` são convertidos (remove `.`, troca `,` por `.`); valores vazios, `"-"` ou `"—"` viram vazio (não zero).
- **Preço Padrão vazio na importação:** diferente do cadastro manual (obrigatório), a importação grava `0` quando a coluna B vem vazia — não bloqueia a linha.
- **Upsert:** localizado por `codigo_produto` → busca `produtos.id`; se o código não existir em `produtos`, a linha é **ignorada** (contabilizada como "não encontrado") — nada é criado em `produtos` (diferente da importação de Produtos, que cria um NCM mínimo quando não encontra; aqui não há criação de produto).
- **Preço negativo na importação:** linha com Preço Padrão, Network ou Auxiliar `< 0` é **ignorada** (contabilizada separadamente de "código não encontrado" no relatório final), consistente com a validação do cadastro manual.
- **Prévia:** exibe até **200 linhas**; aviso quando o total importado excede 200, mas **todas** são enviadas ao confirmar.
- **Relatório final:** mensagem com total inserido / atualizado / ignorado por código não encontrado / ignorado por preço negativo.
- **Impacto em Legados:** produtos com código divergente do cadastrado (renomeado, com espaços, etc.) são silenciosamente ignorados — sem relatório detalhado de quais códigos falharam, apenas a contagem total.

---

## Exclusão <a name="exclusao"></a>

### Regra definida
> A exclusão passa a ser soft delete (`status='inativo'`), igual ao padrão
> já usado em Produtos — sem mais `DELETE` físico.

- **Armazenamento:** nova coluna `tabela_precos.status` enum `ativo`/`inativo`, `NOT NULL DEFAULT 'ativo'` (ver regra de Estrutura).
- **Comportamento:** ação `excluir` passa a executar `UPDATE tabela_precos SET status='inativo' WHERE id=?` em vez de `DELETE` — a linha permanece no banco, apenas some das telas que filtram por status ativo (ver regra de Listagem).
- **Confirmação:** mantém `confirm()` do navegador antes de enviar o formulário de exclusão; o texto deve deixar claro que é uma inativação (o preço pode ser restaurado depois), não uma remoção definitiva.
- **Sem bloqueio por uso:** mantém-se — ao contrário de Produtos (bloqueado por pedido ativo), inativar um preço nunca é bloqueado; pedidos já criados mantêm `valor_total` congelado e não referenciam `tabela_precos.id` (sem FK); inativar a linha apenas remove a base para **novos** pedidos (fallback a `vendas_varejo` volta a valer).
- **Reativação:** não há botão dedicado de "restaurar" — reaparece automaticamente ao cadastrar preço de novo para o mesmo produto (upsert reativa a linha, ver regra de Cadastro Individual) ou ao editar manualmente o `status` no banco.
- **Cascade via Produto:** a FK `tabela_precos.produto_id` mantém `ON DELETE CASCADE`, mas Produtos usa soft delete (`status=inativo`), nunca `DELETE` físico — na prática atual do sistema esse cascade nunca é acionado; documentado para o caso de um hard delete futuro em Produtos. Não relacionado ao soft delete desta tabela.
- **Impacto em Legados:** migration adiciona `status` com `DEFAULT 'ativo'`, então todas as linhas existentes continuam visíveis nas listagens sem ação manual; pedidos já criados preservam seu valor independente do status da linha de preço. Diferente da v4 (hard delete): a partir desta versão, "excluir" não é mais irreversível pela própria aplicação.

---

## Critérios de Aceite

- [ ] Dado um produto sem linha em `tabela_precos`, quando o usuário salva um Preço Padrão pela tela de Produto, então uma linha é inserida em `tabela_precos` com `preco_network` e `preco_auxiliar` NULL.
- [ ] Dado um produto já com linha em `tabela_precos`, quando o usuário adiciona preço novamente pela tela de Tabela de Preços selecionando o mesmo produto, então a linha existente é atualizada (não duplicada).
- [ ] Dado `preco_auxiliar = 100` e `dolar_seguranca = 5`, quando o registro é salvo, então `preco_dolar = 20.00`.
- [ ] Dado `preco_auxiliar` preenchido e `dolar_seguranca = 0`, quando o registro é salvo, então `preco_dolar` fica NULL.
- [ ] Dado o câmbio de segurança salvo com novo valor, quando a ação `cambio` é executada, então `preco_dolar` e `preco_euro` de todos os produtos com `preco_auxiliar` preenchido são recalculados em um único UPDATE.
- [ ] Dado um cliente com moeda USD fazendo uma venda normal, quando o pedido é criado, então o item usa `preco_dolar` do produto (ou `vendas_varejo` como fallback se NULL).
- [ ] Dado um pedido do tipo bonificação, quando criado independente da moeda do cliente, então o item usa `preco_network` (ou `vendas_varejo` como fallback).
- [ ] Dado um pedido já faturado, quando o preço do produto é alterado posteriormente em `tabela_precos`, então o `valor_total` do pedido já criado permanece inalterado.
- [ ] Dado uma planilha de importação com célula A1 contendo texto (ex.: "Código"), quando importada, então a primeira linha é tratada como cabeçalho e ignorada.
- [ ] Dado uma linha da planilha com código de produto que não existe em `produtos`, quando importada, então é contabilizada como ignorada e nenhum registro é criado.
- [ ] Dado um preço existente com `status='ativo'`, quando a ação `excluir` é confirmada, então `tabela_precos.status` muda para `'inativo'`, a linha permanece no banco (sem `DELETE`) e não há bloqueio mesmo se o produto tiver pedidos ativos.
- [ ] Dado um preço com `status='inativo'` (soft-deleted), quando o usuário cadastra preço novamente para o mesmo produto pela tela de Tabela de Preços, então a linha existente é reativada (`status` volta para `'ativo'`) e os valores são atualizados, sem criar uma segunda linha.
- [ ] Dado a listagem sem filtro de Status do Preço aplicado, quando a tela carrega, então exibe apenas linhas com `status='ativo'`; ao filtrar por "Inativo" ou "Todos", preços inativos aparecem.
- [ ] Dado dois `POST` simultâneos da ação `criar` para o mesmo `produto_id`, quando ambos chegam ao banco, então a `UNIQUE KEY` garante uma única linha em `tabela_precos` (o segundo vira `UPDATE` via `ON DUPLICATE KEY UPDATE`).
- [ ] Dado um `POST` da ação `criar` ou `editar` com Preço Padrão `-10`, quando processado, então o backend rejeita com mensagem de erro e nenhuma linha é gravada/atualizada.
- [ ] Dado uma linha de importação com Preço Network `-5`, quando processada, então é ignorada e contabilizada como "preço negativo" no relatório final.
- [ ] Dado a listagem de Tabela de Preços sem filtro de Status do Produto aplicado, quando a tela carrega, então exibe apenas preços de produtos ativos; ao filtrar por "Inativo" ou "Todos", produtos inativos com preço aparecem.

---

## Dependências e Impactos Cruzados

- **Produtos** (`produtos-spec.md`): a tela de Produto também escreve `preco_padrao` em `tabela_precos` (upsert simplificado, sem tocar nas demais faixas); soft delete de Produto não aciona o `ON DELETE CASCADE` desta tabela.
- **Cotação de Câmbio** (`cotacao-cambio-spec.md`): documento próprio para `buscarCotacaoAPI()`/`cotacaoDia()`/`cotacaoExibicaoPedido()`; este spec mantém apenas o Câmbio de Segurança (`dolar_seguranca`/`euro_seguranca`).
- **Pedidos** (`../06-pedidos.md`, `../11-multimoeda-e-i18n.md`): `colPrecoMoeda()` decide a coluna de preço usada por moeda/tipo de venda; alterar o câmbio de segurança ou inativar (soft delete) uma linha de preço não afeta pedidos já criados.
- **Configurações** (`configuracoes`): `dolar_seguranca`, `euro_seguranca` são chaves globais compartilhadas com o restante do sistema via `getConfig()`/`setConfig()` — não exclusivas desta tela. `cotacao_usd`/`cotacao_eur`/`cotacao_data`/`cotacao_atualizado` são chaves distintas, documentadas em `cotacao-cambio-spec.md`.
- **Bônus de Exportação** (`../11-multimoeda-e-i18n.md`): usa `colPrecoMoeda()` e o mesmo fallback `preco_network` para o pedido bonificado gerado na área do cliente.

---

## Índice de Decisões já tomadas

- **Acesso:** comercial/supervisor/tecnologia da informação, via `requireComercial()` — → [Ir para Regra](#acesso)
- **Estrutura:** 1 linha por produto, `UNIQUE KEY` em `produto_id` + upsert via `ON DUPLICATE KEY UPDATE`, Padrão obrigatório, Network/Auxiliar opcionais, Dólar/Euro calculados, `status` ativo/inativo (default ativo) — → [Ir para Regra](#estrutura)
- **Câmbio de Segurança:** global (2 chaves em `configuracoes`), fórmula = auxiliar/câmbio arredondado a 2 casas, NULL se auxiliar vazio ou câmbio ≤ 0, recalculo em massa ao salvar câmbio — → [Ir para Regra](#cambio)
- **Uso em Pedidos:** BRL→padrão, USD→dólar, EUR→euro, bonificação→network; fallback `vendas_varejo`; valor congelado no pedido — → [Ir para Regra](#uso-pedidos)
- **Cadastro Individual:** produto fixo após criação (editar não permite trocar); backend rejeita preço negativo em `criar`/`editar` — → [Ir para Regra](#cadastro)
- **Listagem:** INNER JOIN (produto sem preço não aparece); busca código/descrição; filtro de Status do Produto (Ativo/Inativo/Todos, default Ativo) **e** filtro de Status do Preço (Ativo/Inativo/Todos, default Ativo), combinados com AND — → [Ir para Regra](#listagem)
- **Importação:** colunas fixas A-D; upsert por código; código não encontrado é ignorado; preço negativo é ignorado; preview 200 — → [Ir para Regra](#import)
- **Exclusão:** soft delete via `tabela_precos.status` (ativo/inativo); `excluir` faz UPDATE, não DELETE; reativado automaticamente ao recadastrar o preço; sem bloqueio por uso — → [Ir para Regra](#exclusao)

---

## Pendências para decidir

Nenhuma pendência aberta — ver Changelog para o histórico de decisões fechadas.

---

## Changelog

| Versão | Data | O que mudou |
|---|---|---|
| 1 | 2026-08-04 | Criação inicial — spec derivado do código do protótipo (`admin/cadastros/tabela-precos.php`, `admin/cadastros/produtos.php`, `config.php`) e dos docs `tabela-precos.md`, `produtos-spec.md`, `../11-multimoeda-e-i18n.md`. 3 pendências identificadas. |
| 2 | 2026-08-04 | Pendências fechadas: `UNIQUE KEY` em `tabela_precos.produto_id` com upsert via `ON DUPLICATE KEY UPDATE`; backend passa a rejeitar preço negativo em `criar`/`editar`/`importar`; listagem ganha filtro de Status (default Ativo), igual ao de Produtos. Status → 🟢 Estável. |
| 3 | 2026-08-04 | Seção "Cotação do Dia" extraída para spec próprio `cotacao-cambio-spec.md` (fonte da API, cache, atualização manual, fallback, uso em pedidos e exibição). Câmbio de Segurança (`dolar_seguranca`/`euro_seguranca`) permanece integralmente neste documento. |
| 4 | 2026-08-04 | Removido o stub da seção "Cotação do Dia" (já 100% coberto por `cotacao-cambio-spec.md`); referência mantida apenas em "Dependências e Impactos Cruzados". Câmbio de Segurança (`dolar_seguranca`/`euro_seguranca`) não foi alterado. |
| 5 | 2026-08-04 | Exclusão muda de hard delete para soft delete: nova coluna `tabela_precos.status` (ativo/inativo, default ativo); ação `excluir` passa a fazer `UPDATE` em vez de `DELETE`; upsert de `criar` reativa automaticamente linha inativa; listagem ganha filtro de Status do Preço (Ativo/Inativo/Todos, default Ativo), independente do filtro de Status do Produto já existente. |
