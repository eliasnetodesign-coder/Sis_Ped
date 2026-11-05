# Campanhas — Conceito

> Especificação **conceitual** de campanhas comerciais (desconto e bonificação), independente de
> sistema. Define **o que é** uma campanha e **como ela deve se comportar**; telas, banco de dados,
> integrações e a forma de registrar os benefícios ficam a cargo do sistema que a implementar.
> Derivado de `campanhas-spec.md` (versão 9), sem as partes específicas de implementação.

**Versão:** 4 · **Última atualização:** 2026-11-05

---

## Glossário <a name="glossario"></a>

| Termo | Significado |
|-------|-------------|
| **Campanha** | Regra comercial que concede um **benefício** (desconto ou brinde) quando o pedido cumpre **condições** de compra. |
| **Condição** | Exigência de compra: um conjunto de produtos (filtro) + um mínimo (quantidade ou valor). |
| **Filtro de produtos** | Combinação de **Linha**, **Grupo**, **Subgrupo** e/ou **Produto** — a classificação comercial do produto. |
| **Alvo** | Produtos que recebem o desconto, quando são diferentes dos produtos das condições. |
| **Faixa** | Degrau de um desconto escalonado: mínimos maiores liberam um % maior. |
| **Multiplicador** | Quantas vezes o pedido cumpriu as condições — multiplica o brinde. |
| **Público** | Para quem a campanha vale: clientes de determinados canais de venda, ou clientes específicos. |
| **Vigência** | Período (início/fim) em que a campanha pode ser aplicada. |

---

## 1. Identificação e situação <a name="identificacao"></a>

### Regra definida
> Toda campanha tem um **ID** numérico fixo e um **Nome** único, e só é aplicada quando está
> **aprovada**, **ativa** e **dentro da vigência**.

| Atributo | Regra |
|----------|-------|
| **ID** | Número sequencial gerado na criação. **Nunca muda** (nem ao renomear) — é a referência estável da campanha. |
| **Nome*** | Obrigatório, **único** entre as campanhas, curto (até 50 caracteres). É o que aparece para quem usa. |
| **Ativa / Inativa** | Campanha inativa é ignorada em **toda** avaliação. |
| **Vigência** | Início e fim opcionais (vazio = sem limite naquele lado); fim ≥ início. Fora da vigência a campanha é ignorada, como se estivesse inativa. |
| **Tipo** | **Desconto** ou **Bonificação**. |
| **Mensagem ao cliente** | Opcional — texto comercial exibido ao cliente no lugar da descrição técnica das condições (ex.: *"Na compra de 504 un. de Coloração e 42 un. de Oxidação, ganhe até 12%"*). |

- **Status exibido:** **Ativa**, **Inativa**, **Agendada** (antes do início), **Encerrada** (após o fim), além da situação de aprovação (**Aguardando aprovação** / **Reprovada**). Agendada não deve parecer inativa — ela vai valer.
- **Vigência × data do pedido:** a vigência é conferida na **data da avaliação**, não na data em que o pedido foi feito — um pedido avaliado depois do fim da campanha não recebe mais a campanha.

---

## 2. Público da campanha <a name="publico"></a>

### Regra definida
> A campanha vale **ou** para os clientes de um ou mais **canais de venda**, **ou** para uma lista de **clientes específicos**.

- **Por canal de venda:** um ou **vários** canais (ex.: Distribuidor, Varejo). Nenhum canal escolhido = **todos os canais**. Com canais, vale para o cliente cujo canal for **qualquer um** deles.
- **Clientes específicos:** lista de clientes escolhidos um a um; obrigatório ao menos um. A campanha vale **só** para eles — o canal é ignorado. Outros clientes não recebem a campanha e **nem a veem**.

---

## 3. Condições (gatilho) <a name="condicoes"></a>

### Regra definida
> Cada condição é um **filtro composto** de produtos com um **mínimo por quantidade ou por valor**;
> as condições se combinam em **E** (todas) ou **OU** (qualquer uma).

- **Filtro composto:** o item do pedido entra na condição se bater em **todos** os campos preenchidos (Linha E Grupo E Subgrupo E Produto, cada um opcional). Ex.: "Linha X do Grupo Y".
- **Mínimo:** por **quantidade** (soma das unidades) ou por **valor**, entre os itens do pedido que batem no filtro.
  - **Valor = quantidade × preço de tabela**, sem nenhum desconto. Assim o mínimo é o mesmo para todos os clientes, seja qual for o desconto negociado de cada um. Vale também para o mínimo total.
- **Operador:** **E** = todas as condições precisam ser atingidas; **OU** = basta uma.
- **Mínimo total** (opcional): soma de quantidade ou valor dos itens de **todas** as condições, exigida **além** do E/OU.
- **Condição com mínimo 0** funciona só como filtro (os itens contam para o mínimo total). É obrigatório ter o mínimo de cada condição **ou** um mínimo total.
- **Ao menos uma condição** por campanha.
- **Multiplicador** (usado na bonificação): `piso(atingido ÷ mínimo)` por condição; no **E** vale o **menor** entre as condições, no **OU** o **maior** entre as atingidas.
- **Mesmas quantidades** (opcional, só desconto): além dos mínimos, o pedido precisa somar a **mesma quantidade** em todas as condições. Ex.: mínimos 10 + 10 → 30 × 30 aciona; 30 × 24 não. Só faz sentido com operador **E**, 2+ condições e todas por quantidade — a combinação inválida é recusada no cadastro.

---

## 4. Benefício: Desconto <a name="desconto"></a>

### Regra definida
> O desconto incide nos **itens das condições** ou em **alvos** escolhidos, com **% fixo** ou por
> **faixas escalonadas**.

- **Onde incide:**
  - **Nos itens das condições** (padrão) — premia os próprios produtos que dispararam a campanha.
  - **Em outros produtos (alvos)** — filtro composto como as condições; obrigatório ao menos um alvo. Permite "compre X, ganhe desconto em Y".
- **% fixo:** um % padrão da campanha, ou % próprio por alvo. O % padrão é obrigatório, a menos que todos os alvos tenham % próprio.
- **Faixas:** a Faixa 1 é a própria campanha (mínimos + % padrão); as Faixas 2..N redefinem os mínimos de cada condição (e o mínimo total, se houver) e o %.
  - Todo % de faixa é obrigatório e **maior que o da faixa anterior**; toda faixa informa o mínimo de todas as condições.
  - Nas faixas o % por alvo não se aplica — o % vem da faixa.
  - Vale a **maior faixa atingida**.
- **Não acumula entre campanhas:** se um item é coberto por mais de uma campanha de desconto, recebe o **maior** % elegível, nunca a soma (10% e 15% → 15%, não 25%). Ver também [Várias campanhas no mesmo pedido](#combinacao).
- **Pedido de bonificação** (itens já sem valor) não recebe desconto de campanha.

---

## 5. Benefício: Bonificação (brinde) <a name="bonificacao"></a>

### Regra definida
> Ao atingir as condições, a campanha concede brinde **fixo** (lista definida) ou **selecionável**
> (o cliente escolhe até um limite), sempre multiplicado pelo multiplicador.

- **Fixo:** lista de produtos + quantidades, × multiplicador. Ex.: "a cada 100 un., 6 un. de brinde" → 250 un. = 2 × = 12 un.
- **Selecionável:** o cliente escolhe os brindes até um **limite** por **quantidade** ou por **valor** (obrigatório, > 0), × multiplicador.
  - **Limite por valor:** os brindes escolhidos são valorizados por um **preço de referência único para brindes**, igual para todos os clientes (uma tabela de preço própria, definida pela empresa) — não pelo preço de venda do cliente. O mesmo preço aparece para o cliente na escolha.
  - **Origem dos brindes:** uma lista de produtos **ou** um conjunto por categoria (cada linha do conjunto é **um** critério: um Produto, uma Linha, um Grupo **ou** um Subgrupo — não é filtro composto).
  - **Múltiplo de venda:** a quantidade escolhida respeita o múltiplo do produto (ex.: múltiplo 6, escolher 2 = 12 un.).
  - Passar do limite recusa a escolha, informando qual campanha excedeu. Escolher **menos** que o limite é permitido; o cliente pode também **não escolher** nada.
- **Quando o brinde é gerado:** só no **fechamento de uma venda nova** — não na edição de pedido, nem em pedido que já é de bonificação. Pedidos antigos não geram brinde retroativo.
- **Registro do brinde:** separado dos itens vendidos e identificável como brinde de campanha (com referência à venda de origem). A forma de registro é do sistema.
- **Falha ao gerar o brinde não impede a venda** — o pedido do cliente é prioridade.

---

## 6. Várias campanhas no mesmo pedido <a name="combinacao"></a>

### Regra definida
> Cada campanha é avaliada **de forma independente**; os descontos **não se somam** (vale o maior por
> item) e os brindes **se somam** (cada bonificação atingida dá o seu).

- **Desconto × desconto:** por item, o **maior** % entre as campanhas de desconto que o cobrem.
- **Bonificação × bonificação:** **todas** as campanhas de bonificação atingidas concedem seus brindes.
- **Desconto × bonificação:** combinam — um item pode receber o desconto de uma campanha e ainda contar para atingir a bonificação de outra.
- **Um item conta para várias campanhas:** o mesmo item soma nas condições de todas as campanhas em que se encaixa; ele não é "consumido" por uma campanha.

---

## 7. Aplicação no pedido <a name="aplicacao-pedido"></a>

### Regra definida
> O benefício é **sempre calculado pelo sistema** a partir das campanhas vigentes; o que é exibido ao
> cliente durante a montagem do pedido é só uma prévia.

- **Cálculo autoritativo:** o % de campanha de cada item é calculado no fechamento; um valor enviado de fora (prévia, tela, integração) **não é aceito** como verdade — evita forjar desconto.
- **Ordem dos descontos:** o desconto de campanha é aplicado **por último**, de forma **multiplicativa**, sobre o preço já líquido dos demais descontos.
- **Edição de pedido já feito:**
  - Itens existentes **sem mudança de quantidade** mantêm o % de campanha gravado — mudar ou encerrar a campanha depois não altera pedido já feito.
  - Itens **novos** ou com **quantidade alterada** são recalculados pelas campanhas vigentes.
  - O comportamento é o mesmo, seja quem for que edite (cliente ou equipe interna).

---

## 8. Exibição ao cliente <a name="exibicao"></a>

### Regra definida
> Ao montar o pedido, o cliente vê as campanhas que valem para ele e **o que falta** para ganhar cada benefício.

- Só aparecem campanhas **aprovadas, ativas, vigentes** e do **público** do cliente.
- **Progresso por condição** (comprado × mínimo) e do mínimo total, se houver.
- **Faixas:** marcas de cada faixa (atingidas / próxima) e a frase do que falta: *"Faltam X para liberar Y% OFF"*.
- **Mesmas quantidades:** quando as quantidades diferem, avisar *"As quantidades das condições precisam ser iguais"*.
- Havendo **mensagem ao cliente**, ela substitui a descrição técnica das condições.
- No pedido concluído, informar as **campanhas atingidas** e o % de campanha de cada item.
- A equipe interna que lança pedidos pelo cliente vê a mesma informação.

---

## 9. Ciclo de vida <a name="ciclo-de-vida"></a>

### Regra definida
> Campanha não é apagada — é **inativada**; pode ser **duplicada**; e toda criação passa por **aprovação**.

- **Excluir = Inativar:** a campanha deixa de valer, mas suas regras ficam preservadas (explicam descontos de pedidos já feitos) e ela pode ser reativada.
- **Duplicar:** cria uma campanha **nova** com todas as regras de outra (público, vigência, condições, faixas, alvos, brindes, mensagem), nome sugerido *"<nome> (cópia)"* e **novo ID**. A original não muda. É o caminho para alterar as regras de uma campanha já aprovada.

- **Quem cadastra:** criar, editar, duplicar e inativar campanhas é permitido aos perfis **comerciais** (Comercial, Supervisor) e à **TI**. O aprovador aprova e reprova, mas não precisa ter acesso ao cadastro.

### Aprovação <a name="aprovacao"></a>

- **Toda campanha criada** (inclusive duplicada) fica **Aguardando aprovação** e **não vale** em nenhuma avaliação até ser aprovada — mesmo ativa e vigente.
- **Aprovador:** definido **uma vez**, nas configurações, para todas as campanhas — um **usuário** específico **ou** um **grupo**, sendo grupo = **perfil de acesso** (ex.: Diretoria). No grupo, basta **um** integrante aprovar.
- **Quem cria não aprova** a própria campanha, mesmo pertencendo ao grupo aprovador.
- **Reprovação** exige **motivo**, que fica visível para quem criou. Campanha pendente ou reprovada pode ser editada por quem criou; salvar uma reprovada a devolve para aprovação.
- **Campanha aprovada não pode ser editada.** O único ajuste permitido é a **vigência** (início/fim):
  - a mudança vai para um **histórico** (quem, quando, período anterior → novo) e exige **nova aprovação**;
  - enquanto isso, a campanha **continua valendo no período já aprovado**; aprovada a mudança, vale o novo; reprovada, fica o antigo.
- **Inativar** uma campanha aprovada vale **na hora** (retirar benefício é seguro); **reativar** volta para aprovação. Ambos entram no histórico.
- **Aviso ao aprovador:** lista de campanhas pendentes e um aviso no painel inicial do aprovador, com a quantidade e acesso direto a elas.
- **Registro:** quem aprovou/reprovou e quando.
- **Campanhas já existentes** ao implantar a aprovação entram como **aprovadas**.

---

## 10. Fluxo de cadastro (perguntas) <a name="fluxo"></a>

### Regra definida
> O cadastro é feito por um **passo a passo de 6 etapas**, cada uma com perguntas curtas; as
> perguntas seguintes aparecem conforme as respostas anteriores.

- **Respostas com poucas opções** são apresentadas como **cartões clicáveis** (título + exemplo curto), não como listas de seleção.
- **Cada etapa é validada antes de avançar**, com as mesmas regras usadas ao salvar; a mensagem diz o que falta e o que foi digitado não se perde.

### Etapa 1 — Identificação
| Pergunta | Resposta | Validação |
|---|---|---|
| **Qual o nome da campanha?** | Texto livre (ex.: *Coloração Novembro*). | Obrigatório, único, até 50 caracteres. |
| **Em qual período ela vale?** | Início e Fim (em branco = sem limite naquele lado). | Fim ≥ Início. |
| **A campanha já fica ativa?** | Sim / Não. | — |

### Etapa 2 — Para quem
| Pergunta | Resposta | Aparece quando |
|---|---|---|
| **Para quem é a campanha?** | **Clientes de um canal de venda** ou **Clientes específicos**. | Sempre. |
| **Quais canais de venda?** | Um canal (ou "Todos os canais") e o botão **Adicionar canal** para incluir outros; canal já escolhido não se repete; cada um pode ser removido. | "Clientes de um canal de venda". |
| **Quais clientes?** | Busca por nome, código ou documento; clique adiciona o cliente à lista; cliente já adicionado não se repete. | "Clientes específicos" — obrigatório ao menos um. |

### Etapa 3 — Benefício
| Pergunta | Resposta | Aparece quando |
|---|---|---|
| **O que o cliente ganha?** | **Desconto** ou **Produtos bonificados**. | Sempre. |
| **Como é o desconto?** | **Percentual fixo** ou **Por faixas** (o % aumenta conforme a compra). | Desconto. |
| **Como é o brinde?** | **Produtos definidos** (fixo) ou **O cliente escolhe** (selecionável). | Bonificação. |

### Etapa 4 — O que comprar
| Pergunta | Resposta | Aparece quando |
|---|---|---|
| **Condições** | Tabela já aberta com uma linha: Linha, Grupo, Subgrupo, Produto (filtro composto), **Mínimo por** (Quantidade / Valor) e o **Mínimo**. Com faixas, uma coluna de mínimo por faixa. | Sempre — ao menos uma condição com algum filtro preenchido. |
| **O cliente precisa cumprir:** | **Todas as condições** (E) ou **Qualquer uma delas** (OU). | 2+ condições. |
| **As quantidades precisam ser iguais?** | Sim / Não ([Mesmas quantidades](#condicoes)). | Desconto, 2+ condições e "Todas as condições" — exige todas por Quantidade. Ao trocar para "Qualquer uma" ou voltar a 1 condição, a pergunta some e a opção é desligada. |
| **Mínimo total** (em "Mais opções", recolhido) | Por Quantidade ou Valor + o mínimo (e um por faixa, com faixas). | Opcional. |

Validação: o mínimo de cada condição **ou** um mínimo total; com faixas, ao menos a Faixa 2 e o mínimo de cada condição em cada faixa.

### Etapa 5 — Detalhes
| Pergunta | Resposta | Aparece quando |
|---|---|---|
| **Qual o percentual de desconto?** | Um % (fixo) ou um % por faixa. | Desconto — obrigatório; nas faixas, cada % maior que o anterior. |
| **Em quais produtos o desconto é aplicado?** | **Nos itens das condições** ou **Em outros produtos** (abre a tabela de alvos, com % próprio opcional por alvo). | Desconto — em "outros produtos", ao menos um alvo; sem % padrão, todo alvo precisa de % próprio. |
| **Quais produtos de brinde?** | Lista de produtos + quantidade (por vez que a campanha é atingida). | Bonificação fixa — ao menos um. |
| **Qual o limite que o cliente pode escolher?** | Por Quantidade ou Valor + o limite. | Bonificação selecionável — obrigatório, > 0. |
| **De onde o cliente escolhe?** | **Lista de produtos** ou **Por categoria** (cada linha um Produto, Linha, Grupo ou Subgrupo). | Bonificação selecionável — ao menos um item. |

### Etapa 6 — Revisão
- **Resumo legível** da campanha: nome e situação, período, para quem, o que precisa comprar (com o operador, mesmas quantidades e mínimo total), o que ganha e onde aplica.
- **Mensagem ao cliente** (opcional), preenchida aqui.
- **Salvar.**

### Navegação
- **Voltar / Próximo** e uma barra com as etapas no topo. "Próximo" só avança com a etapa válida; Enter em um campo avança em vez de salvar.
- **Criação:** só dá para ir a etapas já visitadas; **Salvar** só na Revisão, e ao salvar todas as etapas são conferidas de novo — havendo erro, o fluxo volta para a etapa com problema.
- **Edição** (campanha pendente ou reprovada) e **Duplicar:** abre com tudo preenchido, todas as etapas liberadas e Salvar disponível em qualquer etapa. Na duplicação o nome vem como *"<nome> (cópia)"*.
- **Campanha aprovada:** o fluxo abre só para leitura, com apenas o **período** editável (ver [Aprovação](#aprovacao)).
- **Ao salvar, nada sai aprovado** — sempre vai para o aprovador:
  - **criação** e **duplicação** → **Aguardando aprovação** (a cópia de uma campanha aprovada **não** herda a aprovação);
  - **edição de pendente** → continua **Aguardando aprovação**, e o aprovador passa a ver a versão editada;
  - **edição de reprovada** → volta para **Aguardando aprovação** (nova solicitação).

---

## Critérios de Aceite

- [ ] Dado uma campanha E com "Subgrupo COLORAÇÃO ≥ 360 un." e "Subgrupo OXIDAÇÃO ≥ 30 un.", quando o pedido tem 400 un. de coloração e nenhum oxidante, então a campanha não é atingida.
- [ ] Dado a mesma campanha com faixas 5% / 8% / 12%, quando o pedido atinge os mínimos da faixa 3, então os itens recebem 12%.
- [ ] Dado uma Faixa 2 com % menor ou igual ao da Faixa 1, quando a campanha é salva, então é recusada.
- [ ] Dado dois produtos cobertos por campanhas de 10% e 15%, quando o pedido é fechado, então o item recebe 15%, não 25%.
- [ ] Dado uma campanha E com mínimos 10 + 10 e "Mesmas quantidades", quando o pedido tem 30 × 30, então é atingida; com 30 × 24 ou 5 × 5, não.
- [ ] Dado "Mesmas quantidades" com operador OU, uma única condição ou condição por valor, quando a campanha é salva, então é recusada.
- [ ] Dado uma campanha de desconto em "outros produtos" sem nenhum alvo, quando é salva, então é recusada.
- [ ] Dado uma campanha nos canais Distribuidor e Varejo, quando clientes desses dois canais atingem as condições, então ambos recebem; um cliente de outro canal, não.
- [ ] Dado uma campanha para clientes específicos com o cliente A, quando A e B (mesmo canal) fazem o mesmo pedido, então só A recebe e só A vê a campanha.
- [ ] Dado uma campanha inativa, fora da vigência ou não aprovada, quando qualquer avaliação roda, então ela é ignorada.
- [ ] Dado uma campanha vigente de 01/11 a 30/11, quando o pedido é avaliado em 27/10 ou em 01/12, então ela é ignorada e aparece como Agendada / Encerrada; em 15/11 ela vale.
- [ ] Dado um brinde fixo de 6 un. a cada 100 un., quando a venda nova tem 250 un., então são gerados 12 un. de brinde; na edição desse pedido, nenhum brinde novo é gerado.
- [ ] Dado um brinde selecionável com limite de 10 un. e um produto de múltiplo 6, quando o cliente escolhe 2, então a escolha (12 un.) é recusada por exceder o limite.
- [ ] Dado um item gravado com 12% de campanha e a campanha depois alterada para 8%, quando o pedido é editado sem mudar a quantidade do item, então ele continua com 12%; ao mudar a quantidade, recalcula com 8%.
- [ ] Dado um % de campanha enviado de fora num item que nenhuma campanha cobre, quando o pedido é fechado, então o item fica com 0% de campanha.
- [ ] Dado uma campanha por faixas com o cliente entre a Faixa 1 e a 2, quando ele monta o pedido, então vê "Faltam X para liberar Y% OFF".
- [ ] Dado uma campanha "Black Friday" existente, quando outra é criada ou renomeada com esse nome, então é recusada.
- [ ] Dado uma campanha duplicada e salva sem mudanças, então surge "<nome> (cópia)" com novo ID e as mesmas regras, aguardando aprovação; a original não muda.
- [ ] Dado uma campanha nova, ativa e vigente, quando um pedido atinge as condições antes da aprovação, então ela é ignorada; aprovada, passa a valer.
- [ ] Dado o grupo aprovador Diretoria com os usuários A e B, quando A aprova, então a campanha fica aprovada sem B; se A criou a campanha, A não pode aprová-la.
- [ ] Dado uma campanha aprovada, quando o usuário tenta editá-la, então só a vigência é editável; a nova vigência vai para o histórico e para aprovação, e a campanha segue valendo no período antigo até lá.
- [ ] Dado uma condição "Coloração ≥ R$ 5.000", quando dois clientes com descontos negociados diferentes compram a mesma quantidade, cujo valor a preço de tabela é R$ 5.000, então os dois atingem a condição.
- [ ] Dado um brinde selecionável com limite de R$ 500, quando o cliente escolhe brindes que somam R$ 520 pelo preço de referência de brindes, então a escolha é recusada.
- [ ] Dado um pedido que atinge duas campanhas de bonificação e uma de desconto, quando é fechado, então os dois brindes são gerados e o desconto é aplicado, inclusive em itens que também contaram para os brindes.
- [ ] Dado um usuário de perfil fora do Comercial, Supervisor e TI, quando tenta cadastrar ou editar uma campanha, então o acesso é negado.
- [ ] Dado uma nova campanha, quando o usuário clica em "Próximo" sem preencher o obrigatório da etapa (nome, clientes, condição, percentual, brindes), então o fluxo não avança e explica o que falta.
- [ ] Dado uma campanha de desconto com 2 condições em "Todas as condições", quando o usuário está em "O que comprar", então vê "As quantidades precisam ser iguais?"; com "Qualquer uma delas" ou 1 condição, a pergunta some e a opção fica desligada.
- [ ] Dado uma campanha pendente, quando o usuário a edita, então o fluxo abre na etapa 1 com tudo preenchido, todas as etapas liberadas e Salvar disponível.
- [ ] Dado uma campanha aprovada, quando é inativada, então deixa de valer na hora; quando é reativada, aguarda aprovação.

---

## Índice de Decisões

- **Identificação:** ID numérico fixo + nome único — [Ir](#identificacao)
- **Vigência:** opcional nos dois lados; conferida na data da avaliação — [Ir](#identificacao)
- **Público:** um ou vários canais (nenhum = todos) ou clientes específicos — [Ir](#publico)
- **Condições:** filtro composto, mínimo por qtd ou valor, E/OU, mínimo total, multiplicador, mesmas quantidades — [Ir](#condicoes)
- **Desconto:** itens das condições ou alvos; % fixo/por alvo ou faixas crescentes; maior % por item, sem acumular — [Ir](#desconto)
- **Bonificação:** fixa ou selecionável (lista ou categoria, limite × multiplicador, múltiplo de venda); só em venda nova; falha não bloqueia a venda — [Ir](#bonificacao)
- **Valor nas condições:** quantidade × preço de tabela, sem descontos — [Ir](#condicoes)
- **Limite por valor do brinde selecionável:** preço de referência único para brindes — [Ir](#bonificacao)
- **Várias campanhas:** descontos não somam (maior por item); brindes somam; desconto e brinde combinam — [Ir](#combinacao)
- **Quem cadastra:** Comercial, Supervisor e TI; o aprovador não precisa de acesso ao cadastro — [Ir](#ciclo-de-vida)
- **Aplicação no pedido:** cálculo sempre pelo sistema; campanha por último e multiplicativa; edição preserva itens sem mudança de qtd — [Ir](#aplicacao-pedido)
- **Exibição:** progresso por condição e "o que falta", igual para cliente e equipe interna — [Ir](#exibicao)
- **Fluxo de cadastro:** 6 etapas (Identificação, Para quem, Benefício, O que comprar, Detalhes, Revisão), perguntas condicionais, validação por etapa — [Ir](#fluxo)
- **Ciclo de vida:** excluir = inativar; duplicar; aprovação por usuário ou perfil, aprovada só muda vigência (com histórico), inativar livre, reativar com aprovação — [Ir](#ciclo-de-vida)

---

## Changelog

| Versão | Data | O que mudou |
|---|---|---|
| 1 | 2026-11-05 | Criação — conceito extraído de `campanhas-spec.md` v9, sem referências ao sistema A&M nem a telas, tabelas e funções do SisPed. |
| 2 | 2026-11-05 | Removidas a conferência de pedidos (auditoria) e a regra de pedido vindo de outro sistema — fora do escopo do conceito. |
| 3 | 2026-11-05 | Pendências resolvidas: valor das condições = preço de tabela; limite por valor do brinde selecionável = preço de referência único; nova seção "Várias campanhas no mesmo pedido" (descontos não somam, brindes somam, desconto e brinde combinam); permissão de cadastro para Comercial, Supervisor e TI. |
| 4 | 2026-11-05 | Incluído o **fluxo de cadastro por perguntas** (6 etapas, quando cada pergunta aparece, validação por etapa e navegação na criação, edição, duplicação e campanha aprovada). |
