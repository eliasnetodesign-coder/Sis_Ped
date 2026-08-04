# Cadastro de Clientes — Especificação

> Documento de especificação funcional. Define **como deve se comportar**,
> não como foi implementado. Atualizar sempre que uma decisão for tomada.
> Relacionado: `clientes.md` (resumo), `canal-venda-spec.md`, `grupo-empresas.md`,
> `../06-pedidos.md`, `../10-regras-de-negocio.md`, `../11-multimoeda-e-i18n.md`.
> **Escopo:** apenas o CRUD do cliente (campos, validações, listagem, importação/exportação,
> exclusão). Regras de uso no fluxo de Pedidos estão em `../06-pedidos.md` e
> `../10-regras-de-negocio.md`.

**Versão:** 4 · **Última atualização:** 2026-08-04 · **Status:** 🟢 Estável

---

## Campos de Identificação <a name="campos"></a>

### Regra definida
> O cliente é descrito por **Razão Social** (obrigatória); os demais campos de
> identificação são opcionais e usados para conciliação com sistemas legados.

- **Razão Social:** obrigatória — é o rótulo do cliente em toda listagem, pedidos e exportação Excel; sem ela o registro não faz sentido.
- **E-mail:** opcional, mas **único quando informado** (comparação case-insensitive) — serve de login do portal do cliente; pode ficar vazio para clientes sem acesso ao portal.
- **Código do Cliente:** opcional — chave de conciliação com o ERP legado; usado como critério primário no upsert de importação.
- **CNPJ / CPF:** opcionais — critério secundário no upsert de importação; sem unicidade forçada no cadastro manual.
- **Endereço completo:** CEP, Endereço, Número, Complemento, Bairro, Cidade, UF (máx. 2 chars), País — todos opcionais.
- **Telefone 1 / Telefone 2:** opcionais.
- **Status:** `ativo` | `inativo` — default `ativo`; controla visibilidade nos filtros padrão.
- **Impacto em Legados:** E-mail deixa de ser obrigatório — a coluna deve aceitar `NULL`; a unicidade se aplica apenas a valores preenchidos. Registros antigos com e-mail continuam válidos sem migração.

---

## Canal de Venda <a name="canal"></a>

### Regra definida
> O cliente é obrigatoriamente vinculado a um Canal de Venda; esse vínculo governa
> os tetos de desconto aplicáveis ao cliente.

- **Canal de Venda:** select dos canais com `status = 'ativo'`; **salvar sem canal exibe aviso de confirmação** ("Cliente sem canal de venda. Deseja continuar?") — o usuário pode prosseguir, mas fica ciente de que tetos de desconto não serão aplicados.
- **Desconto do Canal %:** armazenado no cliente (`desconto_canal`); limitado ao `desconto_maximo` do canal selecionado — o sistema rejeita valores acima do teto no momento da criação/edição.
- **Mudança de canal:** ao trocar o canal para um com teto menor, o `desconto_canal` é **cortado automaticamente** para o novo teto — sem aviso adicional; o valor salvo nunca excede o teto do canal vigente.
- **Dependência:** o campo `desconto_maximo` do canal é definido em `canal-venda-spec.md#campos`; mudança nesse teto **não** reprocessa os clientes já cadastrados — a validação ocorre apenas no salvamento do cliente.

---

## Descontos e Bônus <a name="descontos"></a>

### Regra definida
> Cada cliente tem descontos e bônus percentuais com tetos fixos, todos validados
> no servidor.

- **Desconto do Cliente %:** numérico, faixa **0 a 100%**, default `0` — desconto comercial próprio do cliente, aplicado cumulativamente com os demais no pedido.
- **Desconto do Canal %:** numérico, faixa **0 ao teto do canal**, default `0` — ver [Canal de Venda](#canal).
- **Bônus Desempenho %:** numérico, faixa **0 a 4%** (aceita decimal, ex: 2.5%), default `0` — percentual do BD trimestral aprovado; teto comercial fixo de 4%.
- **Bônus Material de Apoio %:** numérico, faixa **0 a 5%**, **valor inteiro** (sem casas decimais), default `0` — teto comercial de 5%; inteiro pois o cálculo mensal de MA opera em faixas inteiras. Valor decimal é **rejeitado no servidor** com mensagem de erro.
- **Limite de Crédito:** numérico, default `0` — valor em BRL; controla o crédito disponível para uso no checkout do portal (ver `../10-regras-de-negocio.md`).

---

## Idioma, Moeda e País <a name="i18n"></a>

### Regra definida
> Idioma e Moeda do cliente governam a experiência do portal; País tem default Brasil.

- **Idioma:** `pt` | `en` | `es` — default `pt`; define o idioma da área do cliente no portal.
- **Moeda:** `BRL` | `USD` | `EUR` — default `BRL`; define a moeda de exibição de preços no portal e a moeda dos pedidos.
- **País:** texto livre, default `Brasil`.
- **Impacto em Legados:** registros sem idioma/moeda assumem os defaults (`pt` / `BRL`) na leitura — nenhuma migração necessária. Ver `../11-multimoeda-e-i18n.md`.

---

## Supervisor <a name="supervisor"></a>

### Regra definida
> Supervisor é um select de usuários internos com perfil "Supervisor" ativos;
> o cliente armazena o **ID** do usuário, não o nome.

- **Fonte do select:** usuários com `tipo_acesso = 'supervisor'` e `status = 'ativo'`, ordenados por nome — garante que apenas supervisores válidos apareçam na opção.
- **Armazenamento:** o cliente guarda o **ID do usuário** (FK para `usuarios`) — o nome é exibição, resolvido dinamicamente; permite renomear o usuário sem alterar o vínculo.
- **Opcional:** o cliente pode ficar sem supervisor ("— Nenhum —").

---

## Senha e Acesso ao Portal <a name="senha"></a>

### Regra definida
> A senha do cliente não é exibida nem armazenada em texto puro; novos clientes
> com e-mail recebem um e-mail de definição de senha.

- **Criação:** ao criar um cliente com e-mail, o sistema envia um e-mail ao cliente com link/instruções para cadastrar a própria senha — a tela de cadastro admin não exige nem exibe senha.
- **Edição:** campo senha não é exibido na edição admin — redefinição é feita pelo próprio cliente ou via fluxo "esqueci a senha".
- **Cliente sem e-mail:** não recebe e-mail e não acessa o portal — cadastro apenas para fins administrativos/histórico.
- **`senha_temporaria`:** campo interno de suporte a fluxo de redefinição; não exibido no formulário admin.

---

## Filtros e Listagem <a name="listagem"></a>

### Regra definida
> A listagem é filtrável por busca textual, canal e status; exibe por padrão
> só clientes ativos, ordenados por Razão Social.

- **Busca:** correspondência parcial em Razão Social, CNPJ, E-mail ou Código do Cliente — cobre os campos de identificação mais usados em buscas operacionais.
- **Filtro de Canal:** filtra pelo canal de venda; opcional.
- **Filtro de Status:** default `ativo` — a tela abre mostrando apenas clientes ativos; o usuário pode escolher `inativo` ou "Todos".
- **Ordenação:** alfabética por Razão Social.

---

## Exportação Excel <a name="export"></a>

### Regra definida
> Exporta a listagem atual (com os filtros aplicados) para `.xlsx` com colunas
> de identificação, canal, descontos, contato e endereço.

- **Formato:** arquivo `.xlsx` nomeado `clientes_AAAA-MM-DD.xlsx`.
- **Colunas:** Código, Razão Social, CNPJ, CPF, Canal de Venda, Desconto Cliente %, Desconto Canal %, Limite de Crédito, E-mail, Telefone 1, Telefone 2, Supervisor, CEP, Endereço, Número, Complemento, Bairro, Cidade, Estado, País, Status.
- **Largura de coluna:** automática com máx. 45 caracteres — evita colunas excessivamente largas.
- **Estado vazio:** sem registros na listagem, a exportação é abortada com aviso "Nenhum registro para exportar".

---

## Importação Excel <a name="import"></a>

### Regra definida
> Importa clientes de uma planilha `.xlsx/.xls` por drag-and-drop, fazendo upsert
> por Código → CNPJ, com prévia antes de gravar.

- **Leitura:** primeira aba da planilha; **ignora as 5 primeiras linhas** (metadados + cabeçalho); dados a partir da **linha 6**.
- **Mapeamento por índice de coluna:** Código (A), Razão Social (C), CNPJ (E), Endereço (H), Número (I), Complemento (J), Bairro (K), Cidade (L), Estado (N), País (O), CEP (P), Telefone 1 (Q), E-mail (Y), Supervisor (AC), Status (BW).
- **E-mail:** extrai o **primeiro e-mail válido** do conteúdo da célula (aceita listas separadas por espaço / `;` / `,` / `/`).
- **Linha sem Razão Social:** ignorada e contabilizada como "ignorada".
- **Upsert:** localiza duplicata primeiro por **Código do Cliente**, depois por **CNPJ**; se existir, atualiza; senão, insere.
- **Campos atualizados no upsert:** apenas os mapeados (identificação, endereço, telefone1, e-mail, supervisor, status) — descontos, canal, bônus, idioma e moeda **não** são alterados na atualização, para preservar configurações comerciais já definidas.
- **Novos registros:** recebem canal vazio, descontos `0`, Idioma `pt`, Moeda `BRL`.
- **Conflito de e-mail:** e-mail repetido no mesmo lote, ou já pertencente a outro cliente no banco, é **descartado** — o registro é inserido/atualizado normalmente sem e-mail; o conflito é contabilizado no relatório final.
- **Prévia:** exibe até **200 linhas** na tela; se houver mais, avisa que todas serão importadas.
- **Relatório final:** total de inseridos / atualizados / ignorados (sem Razão Social) / e-mails conflitantes descartados.

---

## Exclusão (Soft Delete) <a name="excluir"></a>

### Regra definida
> A exclusão de um cliente é um soft delete (`status = inativo`), com aviso de
> confirmação sempre exibido antes de efetivar.

- **Comportamento:** a ação marca o registro como `status = 'inativo'` em vez de `DELETE` físico — preserva o vínculo histórico com pedidos, contas a receber e créditos já associados ao cliente.
- **Aviso:** toda ação de exclusão exibe confirmação ao usuário antes de efetivar, **sempre** — independente de existir ou não histórico de pedidos.
- **Listagem:** clientes com `status = 'inativo'` deixam de aparecer nos filtros padrão (ver [Filtros e Listagem](#listagem)).
- **Impacto em Legados:** a coluna `status` já existe em `clientes` com os valores `ativo`/`inativo` — nenhuma migration necessária para o soft delete em si.

---

## Critérios de Aceite

- [ ] Dado um cliente sem e-mail, quando o usuário salva com Razão Social preenchida, então o cliente é criado sem e-mail.
- [ ] Dado um e-mail já usado por outro cliente, quando o usuário tenta salvar, então o sistema rejeita com mensagem de e-mail duplicado.
- [ ] Dado um canal com `desconto_maximo = 10%`, quando o usuário preenche Desconto do Canal com `15%`, então o sistema rejeita por exceder o teto do canal.
- [ ] Dado `bonus_desempenho = 4.5%`, quando o usuário salva, então o sistema rejeita por exceder o teto de 4%.
- [ ] Dado `material_apoio = 3.5`, quando o usuário salva, então o sistema rejeita por não ser inteiro (ou arredonda para inteiro — decidir no CRUD).
- [ ] Dado um cliente com e-mail preenchido sendo criado, quando o admin salva, então o sistema envia e-mail de definição de senha ao cliente.
- [ ] Dada a listagem sem filtros aplicados, quando a tela carrega, então só aparecem clientes com `status = ativo`, ordenados por Razão Social.
- [ ] Dado um cliente com histórico de pedidos, quando o admin clica em excluir, então um aviso de confirmação é exibido e, ao confirmar, o cliente recebe `status = inativo` (não é deletado fisicamente).
- [ ] Dado um cliente sem nenhum pedido, quando o admin clica em excluir, então o mesmo aviso de confirmação é exibido — a regra vale sempre.
- [ ] Dada uma planilha com 3 linhas de dados a partir da linha 6 (1 sem Razão Social), quando importada, então o relatório informa 1 ignorada e processa as outras 2 por upsert (Código → CNPJ).
- [ ] Dada uma planilha com dois clientes novos usando o mesmo e-mail, quando importada, então o segundo tem o e-mail descartado e é contabilizado como conflito no relatório.
- [ ] Dado o select de Supervisor no formulário, quando abre, então lista apenas usuários com `tipo_acesso = supervisor` e `status = ativo`, ordenados por nome, mais "— Nenhum —"; ao salvar, grava o **ID** do usuário.

---

## Dependências e Impactos Cruzados

- **Canal de Venda** (`canal-venda-spec.md`): o campo `desconto_maximo` do canal é o teto de `desconto_canal` do cliente — mudança no teto do canal não reprocessa clientes existentes.
- **Pedidos** (`../06-pedidos.md`): `cliente_id` em pedidos; cliente inativo continua resolvendo normalmente em pedidos históricos (soft delete não remove FK).
- **Créditos / Bônus MA / BD** (`../10-regras-de-negocio.md`): campos `material_apoio` e `bonus_desempenho` do cliente são a base de cálculo dos bônus mensais e trimestrais.
- **Multimoeda** (`../11-multimoeda-e-i18n.md`): campos `idioma` e `moeda` governam o portal do cliente; defaults `pt` / `BRL` se não preenchidos.
- **Usuários**: campo `supervisor` do cliente é FK para `usuarios`; mudança de nome do usuário não quebra o vínculo (ID salvo).
- **Grupo de Empresas** (`grupo-empresas.md`): clientes podem pertencer a um grupo para login multi-CNPJ — escopo fora deste spec.

---

## Índice de Decisões já tomadas

- **Identidade:** Razão Social obrigatória; E-mail opcional e único (case-insensitive) quando informado — → [Ir para Regra](#campos)
- **Canal:** opcional com aviso de confirmação se vazio; `desconto_canal` capado ao `desconto_maximo`; troca de canal corta automaticamente o desconto ao novo teto — → [Ir para Regra](#canal)
- **Descontos:** Desconto do Cliente 0–100%; Bônus Desempenho ≤ 4% (decimal); Material de Apoio ≤ 5% inteiro (decimal rejeitado no servidor); Limite de Crédito numérico — → [Ir para Regra](#descontos)
- **i18n:** Idioma pt/en/es (default pt); Moeda BRL/USD/EUR (default BRL); País default Brasil — → [Ir para Regra](#i18n)
- **Supervisor:** select de usuários com `tipo_acesso = supervisor` ativos; grava o **ID** do usuário — → [Ir para Regra](#supervisor)
- **Senha:** admin não define senha; novo cliente com e-mail recebe link por e-mail; sem campo senha na edição — → [Ir para Regra](#senha)
- **Listagem:** busca por razão/CNPJ/e-mail/código; filtro canal e status; default só ativos; ordena por razão — → [Ir para Regra](#listagem)
- **Exportação:** `.xlsx` com 21 colunas; largura auto máx. 45 chars; aborta se vazio — → [Ir para Regra](#export)
- **Importação:** ignora 5 linhas; mapeamento por índice; upsert Código→CNPJ; preview 200; e-mail conflitante descartado sem abortar; relatório final — → [Ir para Regra](#import)
- **Exclusão:** soft delete (`status = inativo`); aviso de confirmação sempre exibido — → [Ir para Regra](#excluir)

---

## Pendências para decidir

Nenhuma pendência aberta — todas as decisões deste documento foram fechadas na v4.

---

## Changelog

| Versão | Data | O que mudou |
|---|---|---|
| 1 | 2026-07-07 | Criação inicial — spec do CRUD de Cliente, derivado do código e do resumo `clientes.md`. |
| 2 | 2026-07-07 | Resolvidas pendências: E-mail passa a ser opcional (único quando informado); Supervisor vinculado por ID; sem hash de senha no admin; exclusão sem bloqueio por histórico. Status → 🟢 Estável. |
| 3 | 2026-08-04 | Reestruturação para o padrão `erp-spec-doc`: adicionadas seções Canal de Venda, Senha e Acesso, Exclusão (soft delete). Corrigido teto de Material de Apoio (2% → 5%). Critérios de aceite completos. Pendências abertas identificadas (material_apoio decimal, canal obrigatório, comportamento na troca de canal). |
| 4 | 2026-08-04 | Pendências fechadas: `material_apoio` decimal rejeitado no servidor; canal sem vínculo exibe aviso de confirmação (não bloqueia); troca de canal com teto menor corta automaticamente `desconto_canal`. Status → 🟢 Estável. |
