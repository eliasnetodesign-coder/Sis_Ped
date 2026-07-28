# Cadastro de Clientes — Especificação

> Documento de especificação funcional. Define **como deve se comportar**,
> não como foi implementado. Atualizar sempre que uma decisão for tomada.
> Relacionado: `clientes.md` (resumo), `grupo-empresas.md`, `../11-multimoeda-e-i18n.md`.
> **Escopo:** apenas o CRUD do cliente. Regras de controle de acesso e campos
> do módulo de usuários estão fora deste documento.

**Versão:** 2 · **Última atualização:** 2026-07-07 · **Status:** 🟢 Estável

---

## Campos do Cadastro <a name="campos"></a>

### Regra definida
> O cliente é descrito por **Razão Social** (obrigatória); o **E-mail** é opcional e, quando informado, é o login único do portal.

- **Razão Social:** obrigatória — é o rótulo do cliente em toda a listagem, pedidos e Excel; sem ela o registro não faz sentido.
- **E-mail:** **opcional**, mas **único quando informado** — quando preenchido, serve de login do portal do cliente e não pode repetir; pode ficar vazio.
- **Código, CNPJ, CPF:** opcionais — usados para conciliação com o ERP legado e para o upsert de importação (ver [Importação](#import)).
- **Endereço completo:** CEP, Endereço, Número, Complemento, Bairro, Cidade, UF (máx. 2 caracteres), País — todos opcionais.
- **Telefone 1 / Telefone 2:** opcionais.
- **Status:** `ativo` | `inativo` — default `ativo`; controla se o cliente aparece nos filtros padrão.
- **Impacto em Legados:** o E-mail deixa de ser obrigatório — a coluna precisa aceitar `NULL` (hoje é `NOT NULL UNIQUE`); a unicidade só se aplica a valores preenchidos. Registros antigos com e-mail continuam válidos.

---


## Descontos e Bônus <a name="descontos"></a>

### Regra definida
> Cada cliente tem descontos e bônus percentuais com tetos fixos, validados no servidor.

- **Desconto do Cliente %:** livre, default `0` — desconto comercial próprio do cliente.
- **Bônus Desempenho %:** faixa `0` a `4%` (decimal) — valores fora da faixa são fixados no limite; o teto de 4% é regra comercial.
- **Bônus Material de Apoio %:** faixa `0` a `2%`, **valor inteiro** (sem casas decimais) — teto comercial de 2%.

---

## Idioma, Moeda e País <a name="i18n"></a>

### Regra definida
> Idioma e Moeda do cliente governam a experiência do portal; País tem default Brasil.

- **Idioma:** `pt` | `en` | `es` — default `pt`; define o idioma da área do cliente.
- **Moeda:** `BRL` | `USD` | `EUR` — default `BRL`; define a moeda de preços do portal.
- **País:** texto livre, default `Brasil`.
- **Impacto em Legados:** registros sem idioma/moeda assumem os defaults (`pt` / `BRL`) na leitura. Ver `../11-multimoeda-e-i18n.md`.

---

## Campo Supervisor <a name="supervisor"></a>

### Regra definida
> Supervisor é um select alimentado pelo módulo de Usuários (perfil "Supervisor" ativos); o cliente guarda o **ID do usuário**, não o nome.

- **Fonte do select:** usuários do módulo de Usuários cujo perfil de acesso é **"Supervisor"** e que estejam **ativos**, ordenados por nome.
- **Armazenamento:** o cliente guarda o **ID do usuário** (vínculo por chave/FK para `usuarios`) — o nome é apenas exibição, resolvido pelo ID.
- **Opcional:** o cliente pode ficar sem supervisor ("— Nenhum —").

---

## Filtros e Listagem <a name="listagem"></a>

### Regra definida
> A listagem é filtrável por busca textual, canal e status, exibindo por padrão só clientes ativos, ordenados por Razão Social.

- **Busca:** casa parcialmente com Razão Social, CNPJ, E-mail ou Código do Cliente.
- **Filtro de Status:** default `ativo` — a tela abre mostrando apenas clientes ativos; o usuário pode escolher `inativo` ou "Todos".
- **Ordenação:** alfabética por Razão Social.
- **Impacto em Legados:** não se aplica (filtro é leitura).

---

## Exportação Excel <a name="export"></a>

### Regra definida
> Exporta a listagem atual para `.xlsx` com colunas de identificação, canal, descontos, contato e endereço.

- **Formato:** arquivo `.xlsx` nomeado `clientes_AAAA-MM-DD.xlsx`.
- **Colunas:** Código, Razão Social, CNPJ, CPF, Canal de Venda, Desconto Cliente %, Limite de Crédito, E-mail, Telefone 1, Telefone 2, Supervisor, CEP, Endereço, Número, Complemento, Bairro, Cidade, Estado, País, Status.
- **Estado vazio:** sem registros na listagem, a exportação é abortada com aviso "Nenhum registro para exportar".

---

## Importação Excel <a name="import"></a>

### Regra definida
> Importa clientes de uma planilha `.xlsx/.xls` por drag-and-drop, fazendo upsert e apresentando prévia antes de gravar.

- **Leitura:** primeira aba da planilha; **ignora as 5 primeiras linhas** (metadados + cabeçalho); os dados começam na **linha 6**.
- **Mapeamento por índice de coluna:** Código (A), Razão Social (C), CNPJ (E), Endereço (H), Número (I), Complemento (J), Bairro (K), Cidade (L), Estado (N), País (O), CEP (P), Telefone 1 (Q), E-mail (Y), Supervisor (AC), Status (BW).
- **E-mail:** extrai o **primeiro e-mail válido** do conteúdo da célula (aceita listas separadas por espaço/;/,//).
- **Linha sem Razão Social:** ignorada e contabilizada como "ignorada".
- **Upsert:** localiza duplicata primeiro por **Código do Cliente**, depois por **CNPJ**; se existir, **atualiza**; senão, **insere**.
- **Campos atualizados no upsert:** apenas os mapeados (identificação, endereço, telefone1, e-mail, supervisor, status). Descontos, canal, bônus, idioma e moeda **não** são tocados na atualização.
- **Novos registros:** recebem canal vazio, descontos `0`, Idioma `pt`, Moeda `BRL`.
- **Conflito de e-mail:** e-mail repetido no mesmo lote, ou já pertencente a outro cliente no banco, é **mantido**. Como o E-mail é opcional (ver [Campos](#campos)), o registro é inserido/atualizado normalmente **sem e-mail**, sem abortar a importação.
- **Prévia:** exibe até **200 linhas** na tela; se houver mais, avisa que todas serão importadas mesmo assim.
- **Relatório final:** total de inseridos / atualizados / linhas sem Razão Social ignoradas / e-mails duplicados mantidos.

---

## Critérios de Aceite <a name="criterios"></a>

- [ ] Criado um novo cadastro e com flag criar usuário, mandar ume-mail para cliente cadastrar a senha.
- [ ] Dado um cliente sem E-mail, quando o usuário salva com Razão Social preenchida, então o cliente é criado com E-mail vazio.
- [ ] Dado um E-mail já usado por outro cliente, quando o usuário tenta salvar, então o sistema permite o cadastro.
- [ ] Dada a listagem aberta sem filtros, quando a tela carrega, então só aparecem clientes com status `ativo`, ordenados por Razão Social.
#PAREI AQUI#- [ ] Dada uma planilha com 3 linhas de dados a partir da linha 6, sendo 1 sem Razão Social, quando importada, então o relatório informa 1 ignorada e processa as outras 2 por upsert (Código → CNPJ).
- [ ] Dada uma planilha com dois clientes novos usando o mesmo e-mail, quando importada, então o segundo tem o e-mail descartado e é contabilizado como conflito.
- [ ] Dado o select de Supervisor, quando o formulário abre, então lista apenas usuários com perfil "Supervisor" ativos, ordenados por nome, mais a opção "— Nenhum —", e ao salvar grava o **ID** do usuário selecionado.

---

## Dependências e Impactos Cruzados <a name="dependencias"></a>

- **Exclusão** anula `cliente_id` em `pedidos` e `contas_receber` (`ON DELETE SET NULL`) — sem bloqueio por histórico.
- **Desconto do Canal** depende do cadastro de **Canal de Venda** (teto de desconto); mudar o teto lá afeta a validação aqui (ver [Descontos](#descontos)).
- **Idioma** e **Moeda** governam o portal do cliente — ver `../11-multimoeda-e-i18n.md`.
- **Supervisor** vincula o cliente a **Usuários** por **ID** (FK para `usuarios`); mudança nesse módulo afeta a resolução do nome exibido. Regras internas de Usuários estão fora deste spec por escopo.
- **E-mail único** é a chave de login do portal — ver `../10-regras-de-negocio.md`.

---

## Índice de Decisões já tomadas <a name="indice"></a>

- **Identidade:** Razão Social obrigatória; E-mail opcional e único quando informado — *Alvo/Usuário* → [Ir para Regra](#campos)
- **Defaults aplicados:** País = `Brasil`, Desconto Cliente = `0`, Limite de Crédito = `0`, Idioma = `pt`, Moeda = `BRL`, Status = `ativo`.
- **Excluir:** Confirmação obrigatória e executar o soft delete [Ir para Regra](#excluir)
- **Descontos:** Desconto do Canal capado ao teto do canal; Bônus Desempenho ≤ 4%; Material de Apoio ≤ 5% inteiro — *Dev+Contexto* → [Ir para Regra](#descontos)
- **i18n:** Idioma pt/en/es (default pt); Moeda BRL/USD/EUR (default BRL); País default Brasil — *Dev+Contexto* → [Ir para Regra](#i18n)
- **Supervisor:** Select de usuários com perfil "Supervisor" ativos; grava o **ID** do usuário — *Alvo/Usuário* → [Ir para Regra](#supervisor)
- **Listagem:** Busca por razão/CNPJ/e-mail/código; filtro canal e status; default só ativos; ordena por razão — *Dev+Contexto* → [Ir para Regra](#listagem)
- **Exportação:** `.xlsx` com 21 colunas; largura auto máx 45 chars — *Dev+Contexto* → [Ir para Regra](#export)
- **Importação:** Ignora 5 linhas; mapeamento por índice; upsert Código→CNPJ; preview 200; senha aleatória 8 dígitos; relatório final — *Dev+Contexto* → [Ir para Regra](#import)


---

## Pendências para decidir <a name="pendencias"></a>

Nenhuma pendência aberta — todas as decisões deste CRUD foram fechadas na v2.

---

## Changelog

| Versão | Data | O que mudou |
|---|---|---|
| 1 | 2026-07-07 | Criação inicial — spec do CRUD de Cliente (escopo sem acessos/campos de usuário), derivado do código e do resumo `clientes.md`. |
| 2 | 2026-07-07 | Resolvidas as 7 pendências. **E-mail passa a ser opcional** (único quando informado). **Supervisor passa a ser vinculado por ID do usuário** (antes: nome/texto). Confirmadas como definitivas: sem hash de senha, exclusão sem bloqueio por histórico, teto de canal sem reprocessamento, sem troca de senha forçada no 1º acesso. Status → 🟢 Estável. |

