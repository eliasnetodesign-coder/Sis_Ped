# Campanhas — Especificação

> Documento de especificação funcional. Define **como deve se comportar**,
> não como foi implementado. Atualizar sempre que uma decisão for tomada.
> Relacionado: `../05-campanhas.md` (resumo), `../06-pedidos.md`, `../08-modulo-financeiro.md`,
> `../10-regras-de-negocio.md`, `../11-multimoeda-e-i18n.md`, `../12-banco-de-dados.md`,
> `../13-integracoes.md`, `produtos-spec.md`, `canal-venda-spec.md`.
> **Escopo:** cadastro de campanhas do Comercial (`admin/cadastros/campanhas.php`), a avaliação das
> campanhas nos pedidos do SisPed e o uso das mesmas campanhas sobre pedidos do A&M (Análise
> Financeira e "Importa Pedido"). O cadastro antigo de campanhas do A&M (`campanhas_am`), usado
> só pelo "Importa Pedido BF", fica fora deste documento (ver [Pendências](#pendencias)).
> **Acesso ao cadastro:** usuários `comercial`, `supervisor` e `tecnologia da informacao` (`requireComercial()`).

**Versão:** 3 · **Última atualização:** 2026-10-20 · **Status:** 🟡 Em definição

---

## Modelos de campanha (novo × legado) <a name="modelos"></a>

### Regra definida
> Se a campanha tem linhas em `campanha_condicoes`, ela é do **modelo novo**; senão, é **legado**.

- **Fonte de verdade da avaliação:** o PHP (`config.php`). O JS das telas de pedido apenas espelha a regra para a prévia — evita divergência entre o que o cliente vê e o que é gravado.
- **Migrações:** sempre aditivas (`ALTER TABLE ... ADD COLUMN` defensivo em `db()`) — nenhuma campanha existente deixa de funcionar quando uma coluna nova é criada.
- **Cuidado no legado:** loops legados por item devem ignorar `quantidade <= 0` — o cabeçalho do modelo novo grava `quantidade = 0` e categorias `NULL`, e sem essa barreira o desconto seria aplicado a **todos** os itens.
- **Impacto em Legados:** campanhas legadas continuam avaliadas pelo caminho antigo; hoje todas as campanhas cadastradas são do modelo novo.

---

## Cabeçalho da campanha <a name="cabecalho"></a>

### Regra definida
> Uma campanha é identificada pelo **Código da Campanha** e tem canal, situação, tipo e textos de exibição.

| Campo | Regra |
|-------|-------|
| **Código da Campanha*** | Obrigatório (`"Informe o código da campanha."`); agrupa todas as linhas da campanha (`codigo_campanha`) e é também o **nome** exibido nas telas. |
| **Aplicar a campanha** | `campanhas.aplicacao`: **No canal de venda** (`canal`, padrão) ou **Para clientes específicos** (`clientes`). |
| **Canal de Venda** | Só na aplicação "No canal de venda" — vazio = todos os canais; preenchido = só clientes desse canal (`canal_venda_id`). |
| **Clientes da campanha** | Só na aplicação "Para clientes específicos" — clientes adicionados **um a um** por busca (nome, código ou CNPJ/CPF), gravados em `campanha_clientes` (`codigo_campanha`, `cliente_id`). Obrigatório ao menos um (`"Adicione ao menos um cliente à campanha..."`). A campanha vale **só** para esses clientes; o canal é ignorado (gravado `NULL`). |
| **Ativa/Inativa** | `campanhas.ativo`; campanha inativa é ignorada em **toda** avaliação (pedidos, Análise Financeira, Importa Pedido). |
| **Tipo** | **Desconto** ou **Bonificação**. |
| **Período (Início / Fim)** | Opcionais (`campanhas.data_inicio` / `data_fim`, ao lado do Tipo); fim ≥ início. Fora do período a campanha é ignorada como se estivesse inativa (`campanhaVigenteSql()`, data de hoje) e a listagem mostra **Agendada** / **Encerrada**. Vazio = sem limite naquele lado. |
| **Observação** | Opcional, até 1000 caracteres — texto **para o cliente**: aparece nas telas de pedido (cliente e admin) no lugar do texto da condição. |
| **Observação para o A&M** | Opcional, até **255** caracteres (`campanhas.obs_am`) — texto levado ao campo **Obs** do pedido no A&M (ver [Observação no A&M](#obs-am)). |

- **Dois campos de observação separados** — o texto comercial para o cliente ("Obtenha de 5% até 12%…") não serve como anotação curta no A&M ("Desc 12% color"); misturar os dois obrigaria a escolher um público.
- **Impacto em Legados:** `obs_am` nasce `NULL` em todas as campanhas; enquanto vazio, nada é gravado no Obs do A&M. `data_inicio`/`data_fim` nascem `NULL` — as campanhas existentes continuam valendo sem limite de período. `aplicacao` nasce `canal` — as campanhas existentes mantêm a regra por canal.
- **Clientes específicos só no modelo novo:** a regra é conferida no gatilho das condições (`avaliarCampanhaTrigger()`); os loops do modelo legado continuam só por canal — toda campanha salva pelo formulário atual já é do modelo novo.
- **Período × data do pedido:** a vigência é conferida pela **data de hoje** (momento da avaliação), não pela data do pedido — um pedido do A&M analisado depois do fim da campanha não recebe mais a campanha.

---

## Condições (gatilho) <a name="condicoes"></a>

### Regra definida
> Cada condição é um **filtro composto** (Linha E Grupo E Subgrupo E Produto, cada um opcional)
> com mínimo por **quantidade** ou **valor**; as condições combinam em **E** ou **OU**.

- **Filtro composto:** o item entra na condição se bater em **todos** os campos preenchidos do filtro (`cond_linha`, `cond_grupo`, `cond_subgrupo`, `cond_produto_id`).
- **Mínimo por condição:** `criterio_modo` = `quantidade` (`quantidade`) ou `valor` (`valor_min`), somado entre os itens do pedido que batem no filtro.
- **Operador** (`cond_operador`): **E** = todas as condições precisam ser atingidas; **OU** = basta uma.
- **Mínimo total** (opcional, `cond_total_modo` + `cond_total_min`): soma de qtd ou valor dos itens de **todas** as condições, exigido além do E/OU.
- **Condição com mínimo 0** funciona só como filtro (conta para o mínimo total). É obrigatório informar o mínimo de cada condição **ou** um mínimo total.
- **Ao menos uma condição** é obrigatória (`"Adicione ao menos uma condição..."`).
- **Multiplicador** (bonificação): `floor(atingido / mínimo)` — no E, o menor entre as condições; no OU, o maior entre as atingidas.
- **Impacto em Legados:** campanhas criadas antes do operador têm `cond_operador = 'E'` (default) — comportamento idêntico ao anterior.

---

## Tipo Desconto <a name="desconto"></a>

### Regra definida
> O desconto incide nos itens das condições ou em **alvos** explícitos, com **% fixo** (padrão ou
> por alvo) ou por **faixas escalonadas**.

- **Onde o desconto incide:** o formulário pergunta *"O desconto será aplicado sobre os itens das condições?"* (`desc_itens_cond`):
  - **Sim, nos itens das condições** (padrão) — sem alvos; o desconto recai sobre os itens que batem nas condições.
  - **Não, escolher os produtos** — alvos explícitos (`campanha_desconto_alvo`, filtro composto como as condições); obrigatório ao menos um (`"Escolha ao menos um produto a conceder o desconto..."`). Permite premiar produtos diferentes dos que disparam a campanha.
- **% fixo:** % padrão da campanha, ou % próprio por alvo; o % padrão é obrigatório, a menos que todos os alvos tenham % próprio.
- **Faixas** (`desconto_modo = 'faixas'`, `campanha_faixas` + `campanha_faixa_minimos`): a Faixa 1 é a própria campanha (mínimos + % padrão); as Faixas 2..N trocam os mínimos das condições, o mínimo total e o %.
  - Todo % de faixa é obrigatório e **maior que o da faixa anterior**; toda faixa informa o mínimo de todas as condições (e o total, se usado).
  - Nas faixas, o % por alvo é ignorado — o % vem da faixa.
  - Vale a **maior faixa atingida**.
- **Mesmas quantidades em todas as condições** (`campanhas.cond_mesma_qtd`, opção na barra "Forma do desconto", vale para fixo e faixas): além dos mínimos de cada condição (e de cada faixa), o **pedido final** precisa somar a **mesma quantidade** em todas as condições — ex.: mínimo 10 + 10; 30 × 30 aciona, 30 × 24 não. Exige operador **E**, ao menos 2 condições e todas por Quantidade (recusado ao salvar). Servidor: `avaliarCampanhaTrigger()` / `campanhaQtdsIguais()`; JS: `_campQtdsIguais()` nos novos pedidos (portal mostra "As quantidades das condições precisam ser iguais"); Análise Financeira: linha "Quantidades iguais entre as condições" no detalhe.
- **Aplicação no pedido do SisPed:** multiplicativa, sobre o preço já líquido dos demais descontos; por item vale o **maior** desconto elegível (ver [Regras de Negócio](../10-regras-de-negocio.md#descontos-no-pedido)).
- **Impacto em Legados:** campanhas sem `desconto_modo` são tratadas como `fixo`; `cond_mesma_qtd` nasce `0` (desligado) em todas as campanhas existentes.

---

## Tipo Bonificação <a name="bonificacao"></a>

### Regra definida
> Ao atingir as condições, a campanha concede brinde **fixo** (lista) ou **selecionável** (o cliente escolhe até um limite).

- **Fixo** (`bonif_modo = 'fixo'`): produtos + quantidades de `campanha_bonificacao`, multiplicados pelo multiplicador.
- **Selecionável:** limite por quantidade ou valor (`bonif_limite_tipo` / `bonif_limite_valor`, obrigatório e > 0) × multiplicador; origem dos produtos (`bonif_selec_modo`) = lista de produtos (`campanha_bonificacao`) ou pool por categoria (`campanha_bonif_pool`). O pool usa **critério único** por linha (Produto, Linha, Grupo **ou** Subgrupo) — não é filtro composto como as condições. Obrigatório ao menos um produto na lista / um item no pool.
- **Pedido bonificado:** ao finalizar uma **venda nova** que aciona a campanha, cria-se um pedido separado (`tipo_venda = bonificacao`, lote próprio, status `comercial`, `cotacao = NULL`), valorizado pelo preço **Network** (`gerarBonificacaoCampanha()` / `criarPedidoBonificado()`).
  - **Não** ocorre em edição de pedido.
  - O selecionável passa por `cliente/bonificacao-selecionavel.php` (cliente e admin) antes de concluir.
- **Impacto em Legados:** nenhum — regra já vigente.

---

## Formulário e gravação <a name="formulario"></a>

### Regra definida
> Modal com exibição progressiva; **salvar substitui** todas as linhas do código; **excluir** passa a ser **inativar**.

- **Exibição progressiva** (`campStep()`): só o Código aparece de início; preenchido, abrem **Aplicar a campanha**, Canal (ou, em "Para clientes específicos", o bloco **Clientes da campanha** com busca e lista), Ativa/Inativa, Tipo, **Início / Fim** (na mesma linha do Tipo), Condições e Observações. Desconto e "onde incide" só para tipo **Desconto**; Bonificação só para tipo **Bonificação**.
- **Busca de clientes:** AJAX no próprio cadastro (`?buscar_cliente=`, mínimo 2 caracteres, até 20 resultados, ativos primeiro); cliente já adicionado aparece como "adicionado" e não se repete — a base tem milhares de clientes, por isso não é carregada inteira na página.
- **Salvar:** em transação, apaga e regrava cabeçalho, condições, alvos, pool, bonificação, faixas e clientes do código original e do novo (renomear o código não deixa sobra) — evita linhas órfãs de versões anteriores.
- **Excluir = Inativar** *(decidido em 2026-10-20)*: a ação marca `ativo = 0` em vez de apagar — a campanha some de toda avaliação, mas o histórico (condições, faixas, brindes) é preservado e ela pode ser reativada pelo campo Ativa/Inativa. Evita perder a regra que explicou descontos de pedidos já feitos.
- **Estado atual (a mudar):** hoje o botão apaga só as linhas de `campanhas` (hard delete); condições, alvos, faixas e bonificação ficam órfãs no banco.
- **Listagem:** agrupada por código (condições com o operador, canal — ou, em clientes específicos, "N cliente(s)" com os nomes —, tipo, desconto/bonificação, onde incide, status, observação). Status: **Ativa**, **Inativa**, **Agendada** (antes do início) ou **Encerrada** (após o fim), com o período abaixo (`dd/mm/aaaa a dd/mm/aaaa`, `…` no lado vazio); inativas e fora do período ficam esmaecidas. Campanhas com "Mesmas quantidades" mostram "Quantidades iguais entre as condições". Listas longas mostram 3 itens e "+N mais"; quando os alvos são iguais às condições, mostra "Aplica nos mesmos produtos das condições".
- **Erros de validação:** exibidos como mensagem (flash) no topo da tela; nada é gravado parcialmente (rollback). Período e "Mesmas quantidades" são conferidos também no navegador (`campValidar()`), sem perder o que foi digitado.
- **Impacto em Legados:** ao **editar** uma campanha legada, o formulário monta as condições a partir de produtos/categoria + quantidade antigos; ao salvar, ela passa a ser do modelo novo. Campanhas já apagadas antes da mudança não são recuperadas; as linhas órfãs delas podem ser removidas numa limpeza única.

---

## Exibição ao cliente (Novo Pedido do portal) <a name="exibicao-cliente"></a>

### Regra definida
> No novo pedido do portal do cliente, as campanhas ativas do canal aparecem num quadro com o
> progresso de cada condição, para o cliente ver o que falta para ganhar o desconto/brinde.

- **Quadro de campanhas** (`cliente/novo-pedido.php`): cartões em grade, com contador de campanhas atingidas; o quadro pode ser **recolhido**.
- **Barras de progresso:** uma por condição (comprado × mínimo) e uma para o mínimo total, se houver; até **3** condições visíveis e as demais em "ver mais".
- **Faixas:** cada barra mostra as marcas das faixas (atingidas em verde, próxima em destaque) e a frase do que falta para a próxima: *"Faltam X para liberar Y% OFF"*.
- **Observação da campanha:** quando preenchida, aparece no lugar do texto da condição.
- **Mesmas quantidades:** quando as condições somam quantidades diferentes, o cartão mostra *"As quantidades das condições precisam ser iguais"*; nas faixas, se os mínimos da próxima faixa já foram atingidos, a régua mostra esse aviso em vez de "Faltam X".
- **Período:** campanha fora do período não aparece no quadro (mesmo filtro da avaliação).
- **Clientes específicos:** a campanha só aparece no portal para os clientes da lista (`campanhaValeParaCliente()`). No novo pedido do **admin**, o chip indica "Só para clientes específicos" e a prévia em JS só aplica o desconto quando o cliente selecionado está na lista (`_campValeCliente()`).
- **Idiomas:** textos do quadro traduzidos via `lang.php` (pt/en/es).
- **Admin** *(decidido em 2026-10-20)*: o novo pedido do admin (`admin/novo-pedido.php`) passa a ter **o mesmo quadro**, com o mesmo comportamento — quem lança o pedido pelo cliente vê o que falta para cada campanha. **Estado atual (a mudar):** o admin aplica as campanhas, mas ainda não tem o quadro.
- **Impacto em Legados:** nenhum — apenas exibição.

---

## Desconto de campanha no pedido do SisPed <a name="pedido-sisped"></a>

### Regra definida
> Na criação do pedido (portal do cliente e admin), cada item recebe o **maior** % de campanha
> elegível, gravado em `pedidos.desconto_campanha` e aplicado por último, de forma multiplicativa.

- **Cálculo por item:** maior % entre as campanhas legadas (mínimo pela soma do pedido na mesma linha/grupo/subgrupo ou nos produtos da campanha) e as do modelo novo (`avaliarCampanhasDescontoAvancadas()` + alvos). O desconto de campanha não se soma a outro desconto de campanha — evita desconto acumulado em cascata entre campanhas.
- **Valor do item:** preço × (1 − cliente+canal) × (1 − comercial) × (1 − diretoria) × (1 − campanha) — ver [Regras de Negócio](../10-regras-de-negocio.md#descontos-no-pedido).
- **Tipo de venda Bonificação:** não recebe desconto de campanha (o item já sai com valor 0).
- **% vindo da prévia (JS)** *(decidido em 2026-10-20)*: **ignorado** — vale só o % calculado no servidor; a prévia é apenas visual. **Estado atual (a mudar):** o servidor aceita o % do navegador quando ele é igual ao % de **qualquer** campanha cadastrada, sem conferir se ela cobre o produto — permite forjar desconto alterando o envio.
- **Edição (portal e admin)** *(decidido em 2026-10-20)*: itens que já existiam e **não tiveram a quantidade alterada** mantêm o % de campanha gravado; itens **novos** ou com **quantidade alterada** são recalculados pelas campanhas vigentes — campanha encerrada ou alterada não muda pedido já feito, e o pedido não se comporta diferente conforme quem edita.
  - **Estado atual (a mudar):** o portal preserva o % de todo item existente (mesmo com qtd alterada); o admin recalcula todos os itens do lote (`recalcularDescontosCampanha()` / `melhorCampanhaItem()`).
- **Pedido importado do A&M** *(decidido em 2026-10-20)*: editar no admin **não aplica desconto de campanha** — o % da campanha já está no % Diretoria (ver [Importa Pedido](#importa-pedido)); aplicar de novo daria desconto em dobro. Identificação: observação do pedido começando com *"Importado do sistema A&M"*. **Estado atual (a mudar):** a edição reaplica a campanha por cima.
- **Exibição:** o detalhe do pedido no portal do cliente (`cliente/pedido.php`) mostra o quadro **"Campanhas Atingidas"** (informativo, recalculado pelos itens atuais: % ou brinde, alvo e o atingido de cada condição); os PDFs do pedido (admin e cliente) mostram o % de campanha de cada item.
- **Impacto em Legados:** pedidos já gravados mantêm o `desconto_campanha` da época; com a nova regra de edição, só itens novos ou com quantidade alterada passam a usar as campanhas atuais. Pedidos importados do A&M que já foram editados (e podem estar com desconto em dobro) **não** são corrigidos automaticamente.

---

## Bonificação no fechamento do pedido <a name="bonificacao-fechamento"></a>

### Regra definida
> Só em **venda nova** (não em edição, não em pedido de bonificação), ao concluir o pedido o sistema
> gera o brinde fixo e, havendo bonificação selecionável, leva o usuário à tela de escolha.

- **Bonificação fixa:** pedido bonificado criado automaticamente (`gerarBonificacaoCampanha()`), com observação *"Bonificação automática de campanha (ref. Nº) — códigos"*; o usuário vê o aviso com os itens gerados.
- **Bonificação selecionável:** redireciona para `cliente/bonificacao-selecionavel.php` (cliente **e** admin) com as campanhas atingidas, o limite de cada uma e os produtos elegíveis.
  - **Múltiplo de venda:** a quantidade escolhida é multiplicada pelo múltiplo do produto (`produtos.multiplo`).
  - **Limite:** por quantidade (soma das unidades) ou por valor (soma × preço exibido na seleção); passar do limite em qualquer campanha recusa a seleção com *"Campanha X: quantidade/valor selecionado excede o limite."* e volta para a tela.
  - **Gravação:** um pedido bonificado com observação *"Bonificação selecionável de campanha (ref. Nº) — códigos"*, valorizado pelo **preço exibido na seleção** (o mesmo que o cliente viu).
  - **Pular:** o usuário pode seguir sem escolher — nenhum brinde é criado.
  - **Retorno:** portal do cliente → PDF do pedido; admin → detalhe do pedido.
- **Canal de Exportação:** o bônus de exportação (% da venda) entra na **mesma tela** de escolha, como mais uma campanha selecionável por valor — ver [Canal de Venda](canal-venda-spec.md#bonus-exportacao).
- **Falha na bonificação:** não impede a conclusão da venda (erro ignorado) — o pedido do cliente é prioridade.
- **Impacto em Legados:** nenhum — vale para os pedidos concluídos a partir de agora; pedidos antigos não geram brinde retroativo.

---

## Avaliação sobre pedidos do A&M <a name="avaliacao-am"></a>

### Regra definida
> Pedidos do A&M são avaliados contra as **mesmas campanhas ativas do Comercial**, pelo mesmo motor dos pedidos do SisPed (`campanhasComercialAvaliarPedido()`).

- **Produto:** código do item no A&M → `produtos.codigo_produto`; linha/grupo/subgrupo vêm do cadastro do SisPed. Item sem cadastro não entra em nenhuma campanha.
- **Canal:** "Pedido Accademia" do Cadastro de Distribuidores do A&M — `SIM` = canal **Distribuidor**, `NAO` = **Varejo**; sem o dado, o canal do cliente no SisPed. Mesma leitura usada no check de ST — o canal do A&M é a referência mais atual do cliente.
- **Critério por valor:** usa o **"Valor Total" do item no próprio pedido do A&M** (já com os descontos), não o preço de tabela do SisPed — decisão do usuário em 2026-10-20, mantendo o comportamento do modelo antigo (`campanhas_am`).
- **% esperado por item:** maior % das campanhas de desconto acionadas cujo alvo cobre o item; sem campanha = **0%**. Só o **% Diretoria** do item é comparado (não o % Negociação).
- **Onde procurar o brinde** *(decidido em 2026-10-20)*: no **próprio pedido** de venda **e** nos pedidos **Tipo "B"** (bonificação) do mesmo cliente no período analisado — no A&M o brinde costuma ser lançado em pedido separado. **Estado atual (a mudar):** só no próprio pedido.
- **Bonificação fixa:** qtd encontrada ≥ qtd esperada; itens de brinde não passam pela regra do % Diretoria.
- **Bonificação selecionável** *(decidido em 2026-10-20)*: passa a ser **conferida pelo limite** — reprova se os brindes da campanha encontrados (produtos da lista/pool) passarem do limite (quantidade ou valor) × multiplicador; escolher **menos** que o limite não reprova. **Estado atual (a mudar):** não é conferida.
- [ ] Limite por **valor** na bonificação selecionável: qual preço usar para valorizar os brindes encontrados no A&M (preço Network do SisPed, como na tela de escolha, ou o valor do item no A&M)?
- **Sem filtro "BF":** todo pedido é avaliado; o Obs do pedido no A&M não interfere.
- **Período:** só entram campanhas vigentes **na data da análise** (ver [Cabeçalho](#cabecalho)).
- **Clientes específicos:** o cliente do pedido do A&M é localizado no SisPed — na Análise Financeira pelo **código do cliente** (`clientes.codigo_cliente`), no Importa Pedido pelo cliente identificado/selecionado na importação. Cliente não localizado não recebe campanhas de clientes específicos.
- **Impacto em Legados:** substitui, na Análise Financeira e no "Importa Pedido", o uso de `campanhas_am`. Resultados de buscas da Análise Financeira feitas antes da mudança (arquivos de job) não têm o detalhe novo — basta buscar de novo.

---

## Análise Financeira — check 4 (Campanhas) <a name="analise-financeira"></a>

### Regra definida
> Um pedido passa no check 4 quando **nenhum item** tem % Diretoria acima do esperado (+0,01 de
> tolerância) **e** todas as bonificações fixas acionadas estão com o brinde no pedido.

- **Motivos de item reprovado:** `acima_da_faixa` (acima do % da campanha que cobre o item), `campanha_nao_atingida` (produto de campanha que o pedido não atingiu), `sem_campanha` (nenhuma campanha cobre o item), `nao_cadastrado` (código fora do SisPed).
- **Peso na liberação:** o check 4 entra no "conforme" do pedido (checkbox pré-marcado) e no "aprovado" do cliente, junto com atrasos, limite de crédito e ST.
- **Tela — card "4) Campanhas":** botões **vermelhos** para pedidos reprovados e **verdes** para pedidos aprovados com campanha a mostrar; ambos abrem o mesmo modal.
- **Tela — modal do pedido:** itens fora de campanha; campanhas atingidas (linha **clicável** abre as condições); campanhas **não atingidas** em que o pedido tem produtos; bonificações.
- **Condições no modal:** para cada condição, comprado × mínimo da faixa atingida (ou da Faixa 1, se não atingida), ✓/✗ e os itens do pedido que entraram — explica por que a campanha foi ou não atingida sem abrir o cadastro. Com "Mesmas quantidades", aparece a linha extra **"Quantidades iguais entre as condições"** com as quantidades de cada condição (ex.: `30 · 24 un.`) e ✓/✗.
- **Resumo do cliente:** por campanha, a soma atingida entre os pedidos e o **maior %/faixa** atingidos — cada pedido é avaliado isoladamente (a faixa não é recalculada sobre a soma).
- **Impacto em Legados:** ver [Avaliação sobre pedidos do A&M](#avaliacao-am).

---

## Importa Pedido (A&M → SisPed) <a name="importa-pedido"></a>

### Regra definida
> O botão **"Importa Pedido"** tem o mesmo fluxo do "Importa Pedido BF", mas usa as campanhas do
> Comercial e avalia **todo** pedido, sem olhar o Obs.

- **Prévia:** por item, campanha e % Diretoria esperado; campanhas atingidas (com faixa); bonificações; texto que irá para o Obs do A&M.
- **"Aplicar descontos de campanha no % Diretoria":** só com esse clique o % Diretoria de cada item é trocado pelo % da campanha; item fora de campanha fica com **0**. Sem o clique, importa com o % Diretoria do A&M — mantém o comportamento anterior para quem não quer mexer.
- **Recalculado no servidor** na confirmação — não confia no % vindo do navegador.
- **"Gravar também no A&M"** (aparece após aplicar, marcado por padrão, com confirmação): grava o % Diretoria nos itens do pedido do A&M, valida e executa "Conclui Pedido"; registra em `descontos_diretoria_am_logs`.
- **Bloqueios da importação:** produto não cadastrado, sem valor na tabela de preços ou sem custo na competência do mês.
- **Observação no pedido do SisPed:** `"Importado do sistema A&M — Pedido Nº X"` (sem a tag "(BF)").
- **"Importa Pedido BF":** inalterado — continua com `campanhas_am` e o filtro "BF" no Obs.
- **Impacto em Legados:** pedidos importados antes desta mudança não são reprocessados.

---

## Observação no A&M <a name="obs-am"></a>

### Regra definida
> Ao gravar no A&M pelo "Importa Pedido", o campo **Obs** do pedido no A&M é **substituído** pela
> "Observação para o A&M" das campanhas atingidas, separadas por `", "`.

- **Só entram** campanhas atingidas com % > 0 e com o campo preenchido; textos repetidos aparecem uma vez.
- **`{pct}`** no texto vira o % atingido, já com o símbolo (`"Desc {pct} color"` → `"Desc 12% color"` na faixa de 12%) — o Obs acompanha a faixa e mudanças futuras de % sem editar o texto.
- **Sem prefixo:** vai exatamente o texto cadastrado (o "Importa Pedido BF" usa o prefixo `"BF-"`; este não).
- **Nenhuma campanha com o campo preenchido:** o Obs do A&M **não é alterado**.
- **% por alvo diferente na mesma campanha:** `{pct}` mostra o **maior** deles.
- **Exemplo recomendado:** Coloração + Oxidantes `Desc {pct} color` · Lavatório `Desc {pct} Lav` · Black Friday `Desc {pct} BF` → `Desc 12% color, Desc 20% Lav, Desc 15% BF`.
- **Limite de 50 caracteres** *(decidido em 2026-10-20)*: o A&M guarda só **50 caracteres** no Obs e corta o resto em silêncio — a tela não tem limite (`textarea` sem `maxlength`), mas os Obs gravados em 2026 param em exatamente 50, cortados no meio da palavra (ex.: `BF-Desc. 22% color, Desc. 5% selec, Desc. 10% lanc`). Se o texto montado passar de 50, o sistema **corta em 50** e **avisa na tela antes de gravar**, mostrando o texto que vai ficar. **Estado atual (a mudar):** envia o texto inteiro.
- **Recomendação de cadastro:** textos curtos (sigla + `{pct}`), porque várias campanhas dividem os mesmos 50 caracteres.
- **Impacto em Legados:** nenhum — campo novo, vazio por padrão.

---

## Helpers centrais (`config.php`) <a name="helpers"></a>

| Função | Papel |
|--------|-------|
| `campanhasAgrupadas()` | Campanhas ativas **e vigentes** agrupadas por código, com condições |
| `campanhaVigenteSql($alias)` | Filtro SQL: ativa e dentro do período (`data_inicio`/`data_fim`, data de hoje) — usado também nas consultas de campanhas de `admin/pedido.php`, `admin/novo-pedido.php` e `cliente/novo-pedido.php` |
| `ctxCampanha($itens, $canal, $clienteId)` | Contexto do pedido (qtd/valor por categoria + itens normalizados + canal e cliente) — todos os chamadores passam o cliente do pedido |
| `campanhaValeParaCliente($row, $canal, $clienteId)` | Aplicação da campanha: clientes específicos (lista `campanha_clientes`) ou canal (vazio = todos) — usado no gatilho, nas campanhas não atingidas do A&M e no filtro do portal |
| `campanhasClientes()` | Clientes específicos por campanha (`codigo => [cliente_id => true]`, cache por requisição) |
| `avaliarCampanhaTrigger($rows, $conds, $ctx)` | Gatilho (E/OU, mínimo total, mesmas quantidades, multiplicador) — espelhado em JS por `_campAvaliar()` / `_campQtdsIguais()` nos novos pedidos |
| `campanhaQtdsIguais($rows, $qtds)` | Regra "Mesmas quantidades": true se desligada ou se todas as condições somam a mesma quantidade |
| `campanhaFaixaAtingida($code, $rows, $conds, $ctx)` | Maior faixa atingida (sem faixas = a própria campanha) |
| `avaliarCampanhasDescontoAvancadas($ctx)` | % e alvos das campanhas de desconto acionadas (pedidos do SisPed) |
| `detectarBonificacaoSelecionavel($itens, $canal, $clienteId)` | Pool da bonificação selecionável |
| `campanhasComercialAvaliarPedido($itens, $canal, $clienteId)` | Avaliação de um pedido do A&M (check 4 e Importa Pedido) |
| `campanhasComercialTextoObsAM($atingidas)` | Monta o Obs do A&M a partir de `obs_am` |
| `canalVendaIdAEM($pedidoAccademia, $fallback)` | Canal do pedido do A&M (Distribuidor/Varejo) |

---

## Critérios de Aceite

- [ ] Dado uma campanha E com "Subgrupo COLORAÇÃO ≥ 360 un." e "Subgrupo OXIDAÇÃO PROFISSIONAL ≥ 30 un.", quando o pedido tem 400 un. de coloração e nenhum oxidante, então a campanha não é atingida e os itens de coloração têm % esperado = 0%.
- [ ] Dado a mesma campanha com faixas 5% / 8% / 12%, quando o pedido atinge os mínimos da faixa 3, então o % esperado dos itens é 12% e o modal mostra "(faixa 3)".
- [ ] Dado um pedido do A&M com item de % Diretoria 8% numa campanha atingida de 5%, quando a Análise Financeira roda, então o item aparece em vermelho com motivo "Acima da faixa" e o pedido não fica "conforme".
- [ ] Dado um pedido com % Diretoria > 0 num produto que não pertence a nenhuma campanha ativa, quando a Análise Financeira roda, então o motivo é "Nenhuma campanha atingida cobre este produto".
- [ ] Dado um pedido que passou no check 4 e tem campanha atingida, quando o usuário clica no botão verde do pedido e depois na linha da campanha, então vê cada condição com comprado, mínimo, ✓/✗ e itens.
- [ ] Dado uma campanha inativa, quando qualquer avaliação roda (pedido, Análise Financeira, Importa Pedido), então ela é ignorada.
- [ ] Dado "Pedido Accademia = SIM" no A&M e uma campanha restrita ao canal Varejo, quando o pedido é avaliado, então a campanha não se aplica.
- [ ] Dado o "Importa Pedido" sem clicar em "Aplicar", quando o usuário confirma, então o pedido é criado com o % Diretoria vindo do A&M.
- [ ] Dado o "Importa Pedido" com "Aplicar", quando o usuário confirma, então item coberto por campanha recebe o % da campanha e item fora de campanha recebe 0%.
- [ ] Dado as campanhas atingidas com `obs_am` "Desc {pct} color" (12%) e "Desc {pct} Lav" (20%), quando o usuário grava no A&M, então o Obs do A&M fica `Desc 12% color, Desc 20% Lav`.
- [ ] Dado nenhuma campanha atingida com `obs_am` preenchido, quando o usuário grava no A&M, então o Obs do A&M permanece inalterado.
- [ ] Dado uma Faixa 2 com % menor ou igual ao da Faixa 1, quando o usuário salva a campanha, então o sistema recusa com "Faixa 2: o percentual deve ser maior que o da faixa anterior."
- [ ] Dado uma campanha de desconto com "Não, escolher os produtos" e nenhum produto escolhido, quando o usuário salva, então o sistema recusa com "Escolha ao menos um produto a conceder o desconto...".
- [ ] Dado um usuário do tipo `financeiro`, quando acessa `admin/cadastros/campanhas.php`, então é redirecionado ao dashboard com "Acesso restrito ao módulo Comercial."
- [ ] Dado uma campanha por faixas com o cliente entre a Faixa 1 e a Faixa 2, quando ele monta o pedido no portal, então o quadro mostra "Faltam X para liberar Y% OFF" com o que falta para a Faixa 2.
- [ ] Dado dois produtos cobertos por campanhas de 10% e 15%, quando o pedido é criado, então o item recebe `desconto_campanha = 15` (o maior), não 25%.
- [ ] Dado um pedido do tipo Bonificação com itens de uma campanha atingida, quando é criado, então nenhum item recebe desconto de campanha.
- [ ] Dado um pedido com item a 12% de campanha, quando o cliente edita o pedido no portal e só adiciona outro item, então o item original continua com 12%.
- [ ] Dado um lote no status `comercial`, quando o admin altera a quantidade de um item, então o % de campanha de todos os itens do lote é recalculado pelas campanhas ativas.
- [ ] Dado uma campanha selecionável com limite de 10 un. e um produto com múltiplo 6, quando o usuário escolhe 2, então a seleção (12 un.) é recusada com "excede o limite".
- [ ] Dado uma venda nova que atinge uma bonificação fixa, quando o pedido é concluído, então um pedido bonificado separado é criado com a observação "Bonificação automática de campanha".
- [ ] Dado a edição de um pedido existente que atinge uma bonificação, quando é salvo, então nenhum pedido bonificado é gerado.
- [ ] Dado um pedido importado do A&M com % Diretoria 20% (Lavatório), quando o admin altera a quantidade de um item, então `desconto_campanha` fica 0 e o valor considera só os 20% do % Diretoria.
- [ ] Dado um item gravado com 12% de campanha e a campanha depois alterada para 8%, quando o pedido é editado (portal ou admin) sem mudar a quantidade desse item, então ele continua com 12%; quando a quantidade dele é alterada, então é recalculado com 8%.
- [ ] Dado um envio do formulário de novo pedido com `camp_desc = 20` num item que nenhuma campanha cobre, quando o pedido é criado, então o item é gravado com 0% de campanha.
- [ ] Dado uma campanha ativa, quando o usuário clica em Excluir e confirma, então ela fica com `ativo = 0`, some da avaliação e continua na listagem como Inativa, com condições e faixas preservadas.
- [ ] Dado o novo pedido do admin, quando o usuário adiciona itens de uma campanha por faixas, então vê o mesmo quadro de progresso do portal, com "Faltam X para liberar Y% OFF".
- [ ] Dado uma campanha selecionável com limite de 12 un. atingida 1 vez, quando o pedido de venda e os pedidos Tipo B do cliente somam 18 un. dos brindes elegíveis, então o check 4 reprova o pedido; com 12 un. ou menos, passa.
- [ ] Dado uma bonificação fixa cujo brinde está num pedido Tipo B do mesmo cliente no período, quando a Análise Financeira roda, então o brinde é encontrado e o check passa.
- [ ] Dado uma campanha ativa com período 01/11/2026 a 30/11/2026, quando um pedido é avaliado em 27/10/2026 ou em 01/12/2026, então ela é ignorada e a listagem mostra "Agendada" / "Encerrada"; em 15/11/2026 ela vale.
- [ ] Dado uma campanha com Fim anterior ao Início, quando o usuário salva, então o sistema recusa com "A data final do período deve ser igual ou posterior à data inicial."
- [ ] Dado uma campanha E com Coloração ≥ 10 un. e Oxidante ≥ 10 un. e "Mesmas quantidades" ligado, quando o pedido tem 30 × 30, então é atingida; com 30 × 24 ou 5 × 5, não.
- [ ] Dado "Mesmas quantidades" ligado com operador OU, uma única condição ou uma condição por valor, quando o usuário salva, então o sistema recusa a campanha.
- [ ] Dado uma campanha "Para clientes específicos" com o cliente A na lista, quando o cliente A faz um pedido que atinge as condições, então recebe a campanha; quando o cliente B (mesmo canal) faz o mesmo pedido, então não recebe e não vê a campanha no portal.
- [ ] Dado "Para clientes específicos" sem nenhum cliente adicionado, quando o usuário salva, então o sistema recusa com "Adicione ao menos um cliente à campanha (ou aplique no canal de venda)."
- [ ] Dado um pedido do A&M de um cliente da lista (pelo código do cliente), quando a Análise Financeira roda, então a campanha de clientes específicos é avaliada para ele; para outros clientes, não.
- [ ] Dado campanhas atingidas cujo Obs montado tem 62 caracteres, quando o usuário vai gravar no A&M, então a tela avisa e mostra o texto cortado em 50, e é esse texto que é gravado.

---

## Dependências e Impactos Cruzados

- **Cadastro de Clientes** (`clientes-spec.md`): campanhas de clientes específicos referenciam `clientes.id`; na avaliação do A&M o cliente é achado pelo `codigo_cliente` — alterar o código desfaz o vínculo com os pedidos do A&M.
- **Cadastro de Produtos** (`produtos-spec.md`): renomear linha/grupo/subgrupo ou trocar `codigo_produto` muda quais itens (do SisPed e do A&M) entram nas condições e alvos.
- **Canal de Venda** (`canal-venda-spec.md`): a avaliação do A&M procura os canais pelos nomes exatos **"Distribuidor"** e **"Varejo"** — renomeá-los faz campanhas restritas a canal deixarem de valer para pedidos do A&M.
- **Pedidos** (`../06-pedidos.md`, `../10-regras-de-negocio.md`): a nova regra de edição (preservar o % dos itens sem mudança de qtd; não aplicar campanha em pedido importado do A&M) muda `recalcularDescontosCampanha()`, `recalcularValorItem()` / `melhorCampanhaItem()` (`admin/pedido.php`) e a edição em `cliente/novo-pedido.php` — esses documentos precisam refletir a regra.
- **Módulo Financeiro** (`../08-modulo-financeiro.md`): o check 4 da Análise Financeira depende deste cadastro — desativar/editar uma campanha muda a pré-seleção de pedidos para liberação.
- **Integrações A&M** (`../13-integracoes.md`): "Gravar também no A&M" escreve % Diretoria, Obs e executa "Conclui Pedido" no A&M.

---

## Índice de Decisões já tomadas

- **Modelos:** condições em `campanha_condicoes` = modelo novo; PHP é a fonte de verdade — *Dev+Contexto* → [Ir para Regra](#modelos)
- **Cabeçalho:** código obrigatório; canal opcional; inativa é ignorada; duas observações (cliente e A&M) — *Usuário* → [Ir para Regra](#cabecalho)
- **Condições:** filtro composto, mínimo por qtd ou valor, operador E/OU, mínimo total opcional — *Dev+Contexto* → [Ir para Regra](#condicoes)
- **Desconto:** alvos opcionais, % fixo ou por alvo, faixas crescentes com maior faixa atingida — *Dev+Contexto* → [Ir para Regra](#desconto)
- **Bonificação:** fixa ou selecionável; pedido bonificado separado só em venda nova — *Dev+Contexto* → [Ir para Regra](#bonificacao)
- **Formulário:** salvar substitui todas as linhas do código (em transação); excluir apaga o cabeçalho; legada é convertida ao salvar — *Dev+Contexto* → [Ir para Regra](#formulario)
- **Onde o desconto incide:** "Sim, nos itens das condições" (padrão) ou "Não, escolher os produtos" (alvos obrigatórios) — *Dev+Contexto* → [Ir para Regra](#desconto)
- **Exibição ao cliente:** quadro de campanhas com progresso por condição, marcas de faixa e "o que falta", recolhível — *Dev+Contexto* → [Ir para Regra](#exibicao-cliente)
- **Desconto no pedido do SisPed:** maior % elegível por item em `desconto_campanha`, multiplicativo; sem desconto em pedido de bonificação; portal preserva o % na edição, admin recalcula o lote — *Dev+Contexto* → [Ir para Regra](#pedido-sisped)
- **Bonificação no fechamento:** só venda nova; fixa gera pedido bonificado automático; selecionável vai para a tela de escolha com limite e múltiplo; falha não bloqueia a venda — *Dev+Contexto* → [Ir para Regra](#bonificacao-fechamento)
- **Avaliação A&M:** mesmas campanhas do Comercial; canal pelo "Pedido Accademia"; só % Diretoria comparado; sem filtro "BF" — *Usuário* → [Ir para Regra](#avaliacao-am)
- **Critério por valor no A&M:** "Valor Total" do item no pedido do A&M (modelo antigo) — *Usuário, 2026-10-20* → [Ir para Regra](#avaliacao-am)
- **Análise Financeira:** check 4 com motivos por item; botões verdes/vermelhos; condições clicáveis; campanhas não atingidas visíveis — *Usuário* → [Ir para Regra](#analise-financeira)
- **Importa Pedido:** mesmo fluxo do BF com campanhas do Comercial, todo pedido; % só aplicado com "Aplicar"; BF inalterado — *Usuário* → [Ir para Regra](#importa-pedido)
- **Obs no A&M:** substitui o Obs pelo `obs_am` das campanhas atingidas, `{pct}` = % atingido, sem prefixo; vazio = não mexe — *Usuário* → [Ir para Regra](#obs-am)
- **Pedido importado do A&M:** edição no admin não aplica desconto de campanha (evita dobro) — *Usuário, 2026-10-20* → [Ir para Regra](#pedido-sisped)
- **Edição de pedido:** portal e admin preservam o % dos itens existentes sem mudança de qtd; recalculam itens novos ou com qtd alterada — *Usuário, 2026-10-20* → [Ir para Regra](#pedido-sisped)
- **% do navegador:** ignorado; vale só o cálculo do servidor — *Usuário, 2026-10-20* → [Ir para Regra](#pedido-sisped)
- **Excluir campanha:** vira inativar (`ativo = 0`), preservando o histórico — *Usuário, 2026-10-20* → [Ir para Regra](#formulario)
- **Quadro de progresso no admin:** igual ao do portal — *Usuário, 2026-10-20* → [Ir para Regra](#exibicao-cliente)
- **Bonificação selecionável na Análise Financeira:** conferida pelo limite × multiplicador; menos que o limite não reprova — *Usuário, 2026-10-20* → [Ir para Regra](#avaliacao-am)
- **Brinde na Análise Financeira:** procurado no próprio pedido e nos pedidos Tipo B do cliente no período — *Usuário, 2026-10-20* → [Ir para Regra](#avaliacao-am)
- **Período de vigência:** Início/Fim opcionais ao lado do Tipo; fora do período = ignorada (data de hoje); listagem Agendada/Encerrada — *Usuário, 2026-10-27* → [Ir para Regra](#cabecalho)
- **Mesmas quantidades:** opção na Forma do desconto; o pedido final precisa ter a mesma quantidade em todas as condições, além dos mínimos; só com E e condições por quantidade — *Usuário, 2026-10-27* → [Ir para Regra](#desconto)
- **Aplicação por clientes específicos:** ao criar, escolher "No canal de venda" ou "Para clientes específicos" (adicionados um a um); na segunda, só os clientes listados recebem a campanha e o canal é ignorado — *Usuário, 2026-10-27* → [Ir para Regra](#cabecalho)
- **Obs no A&M — limite:** 50 caracteres; texto maior é cortado em 50 com aviso antes de gravar — *Usuário, 2026-10-20* → [Ir para Regra](#obs-am)

---

## Pendências para decidir <a name="pendencias"></a>

- [ ] O cadastro antigo `campanhas_am` e o "Importa Pedido BF" serão migrados para as campanhas do Comercial ou desativados? (o botão "Campanhas" da tela de Pedidos ainda abre o cadastro antigo) — *adiado pelo usuário em 2026-10-20*
- [ ] Limite por **valor** na bonificação selecionável (Análise Financeira): qual preço usar para valorizar os brindes encontrados no A&M?

---

## Changelog

| Versão | Data | O que mudou |
|---|---|---|
| 1 | 2026-10-20 | Criação inicial — spec das Campanhas do Comercial, derivado do código atual (`admin/cadastros/campanhas.php`, `config.php`, `admin/pedidos.php`, `admin/financeiro/analise-financeira.php`) e do resumo `../05-campanhas.md`. Inclui as decisões de 2026-10-20: avaliação de pedidos do A&M pelas campanhas do Comercial (Análise Financeira — check 4 — e "Importa Pedido"), critério por valor = Valor Total do A&M, detalhe de condições clicável, campanhas não atingidas e o campo "Observação para o A&M" (`obs_am`, `{pct}`). |
| 2 | 2026-10-20 | Complemento após revisão contra o código: acesso ao cadastro, "onde o desconto incide", pool por critério único, exclusão (só o cabeçalho), conversão de legadas, exibição ao cliente no portal, desconto de campanha no pedido do SisPed (gravação, edição, exibição em pedido e PDF) e bonificação no fechamento (fixa, tela de escolha selecionável, exportação). Novas pendências: recálculo na edição pelo admin e quadro de progresso no admin. |
| 3 | 2026-10-20 | Pendências resolvidas com o usuário (Modo Interativo): edição de pedido importado do A&M sem campanha; edição preserva o % dos itens sem mudança de qtd (portal e admin); % do navegador ignorado; excluir vira inativar; quadro de progresso no admin; bonificação selecionável conferida pelo limite; brinde procurado também em pedidos Tipo B; Obs do A&M limitado a 50 caracteres (descoberto nos dados do A&M) com corte e aviso. Ficam pendentes: destino do cadastro antigo/BF e o preço do limite por valor da bonificação selecionável. As decisões novas ainda **não estão implementadas** (marcadas como "Estado atual (a mudar)"). |
| 4 | 2026-10-27 | **Período de vigência** (`data_inicio`/`data_fim`, ao lado do Tipo; `campanhaVigenteSql()` em toda avaliação; status Agendada/Encerrada na listagem) e **Mesmas quantidades em todas as condições** (`cond_mesma_qtd`, na Forma do desconto: o pedido final precisa ter a mesma quantidade em cada condição, além dos mínimos; servidor, JS dos novos pedidos, aviso no portal e linha extra na Análise Financeira). Implementados. |
| 5 | 2026-10-27 | **Aplicar a campanha:** "No canal de venda" ou "Para clientes específicos" (`campanhas.aplicacao` + tabela `campanha_clientes`, clientes adicionados um a um por busca AJAX). Avaliação via `campanhaValeParaCliente()`; `ctxCampanha()`, `detectarBonificacaoSelecionavel()` e `campanhasComercialAvaliarPedido()` passam a receber o cliente; A&M localiza o cliente pelo código. Implementado. |
