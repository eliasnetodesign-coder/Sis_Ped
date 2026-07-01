# Especificação Funcional — Sis_Ped

## 1. Visão Geral

Sistema web de gestão de pedidos B2B para indústria de cosméticos (Itallian Hairtech). Dois portais distintos: **Admin** (equipe interna) e **Cliente** (comprador externo). Backend PHP 7.4+ com MySQL/PDO, frontend Bootstrap 5, JavaScript vanilla, SheetJS para Excel.

**Recursos transversais:**
- **Multimoeda** — pedidos e preços operam em BRL, USD ou EUR conforme a moeda do cliente; todos os somatórios/relatórios convertem USD/EUR para BRL pela cotação do pedido (ver §5.10).
- **Internacionalização (i18n)** — a área do cliente é traduzida para PT/EN/ES conforme `clientes.idioma` (ver §5.11).
- **2FA por WhatsApp** — usuários internos do tipo "Externo" exigem verificação por código quando logam fora do IP autorizado (ver §2.1.2).
- **Tema claro/escuro** — o portal admin tem alternância de tema persistida em `localStorage` (`sisped-theme`).

---

## 2. Autenticação e Controle de Acesso

### 2.1 Login (`login.php`)
- Formulário único para todos os tipos de usuário; autenticação por **e-mail + senha**.
- Verifica primeiro a tabela `clientes` (status ativo), depois `usuarios` (status ativo).
- Botão de mostrar/ocultar senha.
- Redirecionamento automático:
  - Cliente **sem** grupo de empresas (ou grupo com 1 membro) → `cliente/dashboard.php`
  - Cliente **em** grupo de empresas com mais de um membro → `cliente/selecionar-cnpj.php` (escolha da empresa)
  - Admin → `admin/dashboard.php`
- Caso já logado, redireciona para `index.php` (que roteia por perfil).
- Mensagens de erro via flash session.

### 2.1.1 Senha Temporária (`trocar-senha.php`)
- Usuários/clientes com `senha_temporaria = 1` recebem `must_change` na sessão e são direcionados a trocar a senha.
- Validações: nova senha obrigatória, confirmação igual, mínimo 4 caracteres e diferente da senha padrão (`123`).
- Atualiza `senha` e zera `senha_temporaria` na tabela correspondente (`clientes` ou `usuarios`).

### 2.1.2 Verificação de Acesso — 2FA por WhatsApp (`verificar-acesso.php`)
- Usuários internos cujo **Tipo de Usuário** (`usuarios.tipo_usuario`, texto livre) seja **"Externo"** e que logam a partir de um IP **diferente** de `IP_LIBERADO` (constante em `config.php`) precisam confirmar um **código de 6 dígitos enviado por WhatsApp** para o celular cadastrado.
- Fluxo: `login.php` gera o código, envia via `enviarWhatsappCodigo()` e guarda em `$_SESSION['login_2fa']` (hash do código, expiração, telefone mascarado, contador de tentativas); o login **só é efetivado** após a verificação bem-sucedida em `verificar-acesso.php`.
- Regras: validade de `WHATSAPP_CODIGO_VALIDADE` segundos (padrão 600 = 10 min); máximo de **5 tentativas**; opção de **reenviar** o código; código expirado ou tentativas excedidas voltam ao login.
- Sem celular cadastrado, o acesso externo é bloqueado com aviso.
- **Integração WhatsApp:** hoje o envio é registrado em `whatsapp_logs` e `logs/whatsapp.log` (modo simulação); o ponto único `enviarWhatsappCodigo()` está pronto para plugar um provedor real (WhatsApp Cloud API/Twilio). Remetente em `WHATSAPP_REMETENTE`.

### 2.2 Perfis de Usuário

| Perfil (`tipo_acesso`) | Portal | Permissões |
|--------|--------|-----------|
| `comercial` | Admin | Todos os pedidos e relatórios; aprova e cancela pedidos na etapa Comercial; gerencia cadastros comerciais |
| `financeiro` | Admin | Módulo financeiro e cadastros financeiros; aprova, cancela e retorna pedidos na etapa Financeiro; vê colunas fiscais/crédito nos pedidos |
| `supervisor` | Admin | Visão filtrada somente aos próprios clientes e pedidos; pode criar pedidos e **aprovar na etapa Comercial** |
| `tecnologia da informacao` | Admin | **Acesso total** — atua simultaneamente como Comercial **e** Financeiro (sem o escopo restrito do supervisor); vê todos os módulos, colunas sensíveis e todas as ações |
| `cliente` | Cliente | Acesso apenas ao próprio portal: pedidos, financeiro, perfil, troca de CNPJ |

> **TI = acesso total:** o gating fino inclui `tecnologia da informacao` nas duas listas de papel (`$isComercial` e `$isFinanceiro`). Ao criar nova checagem de papel, incluir TI em ambos os grupos.

> O enum de `tipo_acesso` em `usuarios` também aceita `recursos humanos`, `marketing`, `diretoria`, `centro tecnico`, `contabilidade`, `recepcao`, `expedicao` (11 valores no total); esses entram como acesso admin genérico via `requireAdmin()`, sem menus/rotas dedicados.

### 2.3 Proteção de Rotas
- `requireAdmin()` — permite `comercial`, `financeiro`, `supervisor`, `tecnologia da informacao`
- `requireComercial()` — permite `comercial`, `supervisor`, `tecnologia da informacao`
- `requireCliente()` — permite somente `cliente`
- `requireLogin()` — qualquer usuário autenticado (ex.: `trocar-senha.php`)
- Rotas do financeiro admin aceitam `comercial` ou `financeiro` diretamente

---

## 3. Portal Administrativo

### 3.1 Dashboard (`admin/dashboard.php`)
- Cards de resumo: Aguardando Comercial, No Financeiro, No Faturamento, Faturados, Cancelados, Total de Pedidos, Valor Total Faturado.
- Tabela dos 10 últimos pedidos (nº, cliente, produto, data, valor, status + link para detalhes).
- Perfil `supervisor`: vê apenas seus pedidos e clientes.
- Alerta de ação rápida (botão) exibido quando há pedidos aguardando Comercial (somente para perfis `comercial` e `financeiro`).

---

### 3.2 Módulo Comercial — Cadastros

#### 3.2.1 Cadastro de Clientes (`admin/cadastros/clientes.php`)
**Campos:** Código, CNPJ, CPF, Razão Social*, Status, CEP, Endereço, Número, Complemento, Bairro, Cidade, UF (2 chars), País (default: Brasil), Telefone 1, Telefone 2, E-mail (login, único)*, supervisor (dropdown de usuários tipo supervisor ativos), Canal de Venda (dropdown), Desconto do Cliente %, Desconto do Canal %, Bônus Desempenho % (máx. 4%), Bônus Material de Apoio % (máx. 5%), Limite de Crédito, Idioma (PT/EN/ES), Moeda (BRL/USD/EUR), Senha.

**Operações:**
- **Criar** — senha obrigatória; Desconto do Canal limitado ao teto do canal selecionado (exibir somente para perfil `tecnologia da informação`).
- **Editar** — senha opcional (campo vazio mantém a atual).
- **Excluir** — com confirmação JS e se não houver histórico de pedidos.
- **Filtros:** busca texto (codigo / razão social / CNPJ / e-mail), Canal de Venda, Status; padrão exibe somente ativos.
- **Desconto do Canal:** ao selecionar o canal, preenchido automaticamente com o teto do canal; campo limitado pelo `max` do input via JS; informação do máximo exibida abaixo do campo.

**Exportar Excel:**
- Gera `.xlsx` com colunas: Código, Razão Social, CNPJ, CPF, Canal de Venda, Desconto Cliente %, Desconto Canal %, Limite de Crédito, E-mail, Telefone 1, Telefone 2, supervisor, CEP, Endereço, Número, Complemento, Bairro, Cidade, Estado, País, Status.
- Largura de coluna ajustada automaticamente (máx. 45 chars).

**Importar Excel:**
- Aceita `.xlsx` e `.xls`; drag-and-drop ou clique.
- Ignora as **5 primeiras linhas** (metadados + cabeçalho do relatório); dados começam na linha 6.
- Mapeamento de colunas por índice (A=0, C=2, E=4, H=7, I=8, J=9, K=10, L=11, N=13, O=14, P=15, Q=16, Y=24, AC=28, BW=74).
- E-mail: extrai o primeiro endereço válido de células com múltiplos e-mails separados por `;`, `,`, `/` ou espaço.
- Upsert: identifica duplicata por `codigo_cliente` (primeiro) ou `cnpj` (segundo).
- Conflito de e-mail: se o e-mail já pertence a outro cliente no banco ou aparece duplicado no lote, o campo deve ser gravado (não rejeita a linha).
- Novos registros recebem senha aleatória de 4 dígitos numéricos sendo eles 1234, e solicita alteração do cadastro no primeiro acesso.
- Preview de até 200 linhas antes da confirmação.
- Relatório final: inseridos, atualizados, ignorados (sem razão social), e-mails conflitantes.

#### 3.2.1.1 Grupo de Empresas (`admin/cadastros/grupo-empresas.php`)
**Objetivo:** agrupar CNPJs (clientes) para operações conjuntas e para a troca de empresa no portal do cliente.
**Campos do grupo:** Nome*, Descrição (opcional).
- **Operações:** Criar / Editar / Excluir grupo; **adicionar** e **remover** empresas (clientes) do grupo.
- Cada grupo é um **item de accordion** (cabeçalho com nome, descrição e contagem de empresas) que **expande/recolhe** a lista de clientes — mesmo padrão visual do cadastro de NCM.
- Adição de empresa por **autocomplete** (nome ou código), filtrando clientes já presentes no grupo.
- Após adicionar/remover, o grupo correspondente é reaberto automaticamente (âncora `#grupo-ID`).
- Tabelas: `grupo_empresas` e `grupo_empresas_clientes` (UNIQUE por grupo+cliente).

#### 3.2.2 Cadastro de Produtos (`admin/cadastros/produtos.php`)
**Campos:** Código (único), Linha, Grupo, Subgrupo, Código de Barras, Descrição PT / EN / ES, Nuance, Múltiplo de venda (int), Preço Padrão (gerenciado em `tabela_precos`), Vendas Distribuidor (R$), Vendas Varejo (R$), Vendas Exportação (R$), NCM (FK lookup), CEST, Status (ativo/inativo).

**Operações:** Criar / Editar / Excluir; Preço Padrão criado/atualizado junto em `tabela_precos`.
- Modal em abas: **Dados do Produto**, **Descrição Área do Cliente** (PT/EN/ES) e **KIT** (somente para grupo Kit).
- Toggle inline de Vendas (Distribuidor/Varejo/Exportação) direto na listagem (AJAX).
- **Importar Excel:** upsert por código; lookup de NCM pelo código (cria NCM mínimo se não existir); campos espelhados.
- **Exportar Excel:** dados completos.

**Aba KIT (composição):**
- Exibida apenas para produtos cujo grupo normaliza para "KIT" (ex.: `-KIT`).
- Permite **adicionar produtos** que compõem o kit: busca por código/nome (autocomplete), quantidade e botão Adicionar; cada item pode ser removido.
- Persistida na tabela `kit_composicao` (`kit_codigo`, `produto_codigo`, `nome`, `qtd`), substituída por completo ao salvar o produto-kit; removida ao excluir o produto.
- A tabela é **criada e semeada uma única vez** (a partir de `admin/cadastros/kit_composicao.php`, gerado da planilha `COMPOSICAO_KIT.xlsx`) na primeira abertura da tela.
- O nome exibido usa a descrição atual do produto componente (fallback ao nome gravado).

#### 3.2.3 Tabela de Preços (`admin/cadastros/tabela-precos.php`)
- Associa produto a **três faixas de preço**: Preço Padrão*, Preço Network*, Preço Auxiliar (opcional).
- Busca por código ou descrição.
- **Importar Excel:** colunas A=Código do Produto, B=Preço Padrão, C=Preço Network, D=Preço Auxiliar; auto-detecção de cabeçalho; parseamento de formato BRL (`1.234,56`); upsert por produto.
- Preview de até 200 linhas; aviso quando arquivo excede 200.
- Não é possível criar duas entradas para o mesmo produto (faz upsert).

**Câmbio de segurança e preços em moeda estrangeira (multimoeda):**
- No topo da tela há a edição do **câmbio de segurança**: `dolar_seguranca` e `euro_seguranca` (action `cambio`), armazenados na tabela `configuracoes` (chave/valor). Helpers `getConfig()`/`setConfig()` em `config.php`.
- Colunas **calculadas** em `tabela_precos`: `preco_dolar = preco_auxiliar / dolar_seguranca` e `preco_euro = preco_auxiliar / euro_seguranca` (NULL se o auxiliar estiver vazio ou o câmbio ≤ 0). Recalculadas ao salvar/importar preço e ao salvar o câmbio.
- **Cotação do dia (botão "Buscar cotação", `?cotacao=1`):** `buscarCotacaoAPI()` consulta a AwesomeAPI (USD-BRL / EUR-BRL via cURL) e `cotacaoDia()` cacheia o valor do dia em `configuracoes` (`cotacao_usd`, `cotacao_eur`, `cotacao_data`) — 1 chamada por dia, com fallback ao último valor. Essa cotação é gravada no pedido (`pedidos.cotacao`) na criação e usada para converter USD/EUR → BRL nas agregações.

#### 3.2.4 Canal de Venda (`admin/cadastros/canal-venda.php`)
**Campos:** Canal*, Faixa de Faturamento (texto, ex: "Acima de R$ 50.000"), Desconto Máximo %, **Margem de Negociação %**.
- **Desconto Máximo %** (`canal_venda.desconto`) serve de teto para `desconto_canal` nos clientes.
- **Margem de Negociação %** (`canal_venda.margem_negociacao`) serve de teto para o **Desconto Comercial** aplicável por item no pedido (ver §3.3.3 e §5.1.2).
- O canal cujo nome contém **"export"** é tratado como canal de **Exportação** e concede o bônus de exportação (ver §5.12).

#### 3.2.5 Campanhas (`admin/cadastros/campanhas.php`)

O módulo de campanhas foi **reestruturado** (jun/2026). Convivem dois modelos, decididos por: **se a campanha tem linhas em `campanha_condicoes` ⇒ modelo novo; senão ⇒ legado**. As migrações são apenas aditivas; **o PHP (`config.php`) é a fonte de verdade** da avaliação. A descrição abaixo é do **modelo novo**.

**Cabeçalho da campanha:** Código da Campanha*, Canal de Venda (opcional — "Todos" ou canal específico), **Ativa/Inativa** (`campanhas.ativo`; campanhas inativas são ignoradas na avaliação), **Tipo de Campanha** (Desconto **ou** Bonificação). No modelo novo o cabeçalho grava produto/linha/grupo/subgrupo = NULL e `quantidade = 0` (o gatilho fica nas condições).

**Condições (gatilho) — tabela `campanha_condicoes`:**
- Cada condição é um **filtro composto**: **Linha + Grupo + Subgrupo + Produto** (cada um opcional, "— qualquer —"), combinados em **E** — ex.: "Linha Itallian Color · Grupo Coloração".
- Cada condição tem um **Modo** (`criterio_modo`): **Quantidade** ou **Valor**, e um **Mínimo** (`quantidade` ou `valor_min`) somado entre os itens do pedido que satisfazem o filtro.
- **Todas as condições são combinadas em E** — ex.: "Grupo Coloração ≥ 10 un." **E** "Grupo Oxidante ≥ 5 un.". Só dispara quando todas são atingidas.
- Colunas: `cond_linha`, `cond_grupo`, `cond_subgrupo`, `cond_produto_id`, `criterio_modo`, `quantidade`, `valor_min` (colunas `criterio_tipo`/`criterio_valor` são fallback legado de filtro único).

**Tipo Desconto:**
- Campo **Desconto %** + **alvos opcionais** (tabela `campanha_desconto_alvo`) que definem **onde** o desconto incide (por linha/grupo/subgrupo/produto). Se nenhum alvo for definido, o desconto recai sobre os itens que satisfazem as condições.
- Aplicado de forma **multiplicativa** sobre o preço já líquido dos demais descontos (ver §5.1).

**Tipo Bonificação:**
- **Multiplicador:** a quantidade bonificada multiplica conforme o total comprado — `mult = floor(qtd_alvo / mínimo)` (menor múltiplo entre as condições).
- **Modo fixo (lista):** produtos + quantidades fixos como brinde (tabela `campanha_bonificacao`).
- **Modo selecionável:** o cliente escolhe o brinde até um **limite** (`bonif_limite_tipo`/`bonif_limite_valor`, por quantidade ou valor). A origem dos produtos é (`bonif_selec_modo`): **lista** de produtos (`campanha_bonificacao`) **ou** **pool por categoria** (`campanha_bonif_pool`, filtro por linha/grupo/subgrupo/produto).
- Ao finalizar uma **venda nova** que aciona a campanha, o sistema cria um **pedido bonificado separado** (`tipo_venda=bonificacao`, lote próprio, status comercial, `cotacao=NULL`) com valor pelo preço **Network** (`criarPedidoBonificado`/`gerarBonificacaoCampanha`). Não ocorre em edição de pedido. Bonificação **selecionável** redireciona para `cliente/bonificacao-selecionavel.php` (usado por cliente **e** admin) antes de concluir.

**Formulário (modal `modal-xl`, exibição progressiva):** Código → Canal → Ativa/Inativa → Tipo. As **condições** são uma tabela com selects de Linha/Grupo/Subgrupo/Produto + Modo + Mínimo (linhas adicionáveis). Desconto: percentual + alvos. Bonificação: fixo (lista) ou selecionável (lista/pool) + limite.

**Modelo de dados:** uma campanha é um conjunto de linhas em `campanhas` (compartilham `codigo_campanha`) + condições em `campanha_condicoes` + alvos/pool. Salvar **substitui** todas as linhas do código; excluir remove todas elas.

- Campanha sem canal afeta todos os clientes; com canal, afeta somente clientes desse canal.
- Listagem **agrupada por código**: mostra condições, canal, tipo, desconto/bonificação e status ativo.

**Helpers centrais (`config.php`):** `campanhasAgrupadas()` (filtra inativas, carrega condições), `ctxCampanha()` (monta o contexto do pedido — qtd/valor por categoria e lista de itens normalizada), `avaliarCampanhaTrigger()` (avalia o gatilho E entre condições), `avaliarCampanhasDescontoAvancadas()` (resolve alvos), `detectarBonificacaoSelecionavel()` (monta o pool selecionável).

#### 3.2.6 NCM (`admin/cadastros/ncm.php`)
**Campos:** Nome da Categoria, NCM* (código), CEST, IPI (%, 4 casas decimais).
- Tabela auxiliar fiscal; vinculada ao produto.

#### 3.2.7 Metas (`admin/cadastros/metas.php`)
**Campos:** Cliente (busca autocomplete por nome ou código), Trimestre (1º–4º TRI), Ano, Meta (R$).
- Exibição ordenada por ano DESC e trimestre.
- Usada no módulo de Bônus de Desempenho para comparar realizado vs. meta.

#### 3.2.8 Bônus de Desempenho (`admin/cadastros/bonus-desempenho.php`)
- Avaliação **trimestral** por cliente vs. meta cadastrada.
- Filtros: Trimestre (1–4) e Ano; padrão = trimestre atual, busca por nome/codigo do Cliente.
- Tabela: código, cliente, supervisor, canal, faturamento no trimestre (pedidos faturados), meta, atingimento %, último log.
- Ações: **Aprovar** ou **Cancelar** por cliente/trimestre/ano — registradas em `bonus_desempenho_logs`.
- Requer uma aprovação inicial do Comercial, e depois do Financeiro.
- Log exibe: status (aprovado/cancelado), nome do usuário gestor, data/hora.
- Percentual do bônus configurado no cadastro do cliente (campo `bonus_desempenho`, máx. 4%).
- Depois de aprovado pelos Comercial e Financeiro, grava as informações do Valor %BD,  Valor BD e Méd. Atrasos.

#### 3.2.9 Bônus de Material de Apoio (`admin/cadastros/bonus-ma.php`)
- Avaliação **mensal** por cliente.
- Padrão: mês **anterior** ao mês atual (pois o bônus é retroativo).
- Navegação por Mês/Ano com botões "Mês Anterior" e "Próximo".
- Filtros: busca por nome/código do cliente
- Somente exibe clientes com `material_apoio > 0` e status ativo.
- Colunas: código, cliente, supervisor, canal, desconto do canal, faturamento do mês, % MA, valor do bônus MA, **média de atraso** (dias médios de atraso nos pagamentos — semáforo: verde ≤3d, amarelo ≤5, vermelho >6d), ação, log.
- Cards de resumo: período, clientes elegíveis, total faturado, total bônus MA.
- Ações: **Aprovar** ou **Cancelar** — registradas em `bonus_ma_logs`.
- Última ação exibida por cliente/mês (join com subquery MAX id).
- Total de rodapé mostra faturamento total e total de bônus do período.
- Depois de aprovado grava as informações do supervisor, Canal, Desconto Canal, %MA, Valor MA, Méd.Atr.

#### 3.2.10 Concessão de Créditos (`admin/cadastros/concessao-creditos.php`)
**Campos do crédito:** Cliente (busca autocomplete), Crédito Referente (descrição, ex: "Bônus de desempenho Q2/2026"), Data*, Valor (R$)*, Observação Interna (textarea).
- Filtros: busca por nome/código do cliente, data inicial e final (padrão: mês atual).
- **Colunas da listagem:** Data, Código, Cliente, Crédito Referente, Observação Interna, Média de Atraso (semáforo igual ao Bônus MA), Valor do Crédito, Solicitante (usuário que criou), Ações, Log.
- **Workflow de aprovação:** Aprovar ou Cancelar — registrados em `creditos_logs`; último log exibido.
- **Regra de exclusão:** crédito com `valor_utilizado > 0` não pode ser excluído (botão desabilitado com tooltip).
- Total do período exibido no cabeçalho dos filtros.

#### 3.2.11 Contas a Receber — Comercial (`admin/cadastros/contas-receber.php`)
**Operações:** Criar / Editar / Excluir. Acesso: `requireAdmin()`.
**Campos:** Nº Documento, Cliente, Valor a Receber, Descontos, Data Emissão, Data Vencimento, Data Pagamento, Situação (aberto/pago/vencido/cancelado).
- Filtro por situação (botões).
- Coluna Líquido = Valor − Descontos.
- Linha destacada em vermelho quando situação = vencido.

#### 3.2.12 Contas a Pagar — Comercial (`admin/cadastros/contas-pagar.php`)
**Operações:** Criar / Editar / Excluir. Acesso: `requireAdmin()`.
**Campos:** Nº Documento, Fornecedor (dropdown ativos), Valor a Pagar, Descontos, Juros, Data Emissão, Data Vencimento, Data Pagamento, Situação.
- Filtro por situação (botões).
- Linha destacada em vermelho quando vencido.

#### 3.2.13 Cadastro de Usuários (`admin/cadastros/usuarios.php`)
**Campos:** Código, Nome*, E-mail*, Senha (obrigatória na criação; em branco = manter), Departamento, Divisão de Vendas, Tipo de Acesso* (`comercial` / `financeiro` / `supervisor` / `tecnologia da informacao` / `recursos humanos` / `marketing` / `diretoria` / `centro tecnico` / `contabilidade` / `recepcao` / `expedicao`), Tipo de Usuário (texto livre, ex: Gerente, Analista), Telefone, Ramal, Status.
- **Restrição:** não é possível excluir o próprio usuário logado.
- Acesso restrito: `requireAdmin()` (inclui `supervisor`, que pode ver mas normalmente não gerencia usuários).

---

### 3.3 Módulo Comercial — Pedidos

#### 3.3.1 Novo Pedido Admin (`admin/novo-pedido.php`)
- Acesso: `requireComercial()` (`comercial` + `supervisor`).
- **Etapa 1 — Seleção de Cliente e Produtos:**
  - Busca de cliente com autocomplete (dropdown filtrado por nome ou código); exibe código como badge.
  - Após selecionar cliente, exibe alerta verde com os descontos aplicados (Cliente % | Canal %).
  - Campanhas ativas exibidas em alerta informativo no topo com código, produto/linha/grupo e desconto%.
  - Produtos organizados em **abas por Linha** (nav-tabs com scroll horizontal).
  - Campo de filtro rápido por nome/código do produto (filtra todas as abas em tempo real e exibe na aba que encontrar).
  - Tabela por linha: Código, Código de Barras, Produto, Preço Unit. (já com descontos; badge verde quando há desconto de campanha), Múltiplo (badge se > 1), Campo Qtd. visual, Quantidade Total (visual × múltiplo), Total R$.
  - Badge na aba mostra quantidade de itens adicionados naquela linha.
  - Badge no botão Carrinho mostra total de itens.
  - Linhas com quantidade > 0 ficam destacadas (tabela-primary).
- **Carrinho (offcanvas lateral):**
  - Lista itens com nome, qtd (visual × múltiplo = total), preço, subtotal; badge de campanha.
  - Total geral; botão Avançar.
  - Validação: cliente selecionado e ao menos 1 produto antes de avançar.
- **Etapa 2 — Resumo:**
  - Tabela agrupada por linha com subtotais por linha e total geral.
  - Campo de Observação (textarea, opcional).
  - Botão Finalizar (desabilitado com spinner durante o submit).
- **Lógica de desconto no servidor:**
  - `valor = qtd × preco × (1 − (dCliente + dCanal)/100) × (1 − campDesc/100)`
  - Campanha validada server-side; valor JS enviado como `camp_desc` e usado como fallback se servidor não detectar.
- Pedidos com múltiplos produtos recebem mesmo `lote_id` (UUID); pedido único tem `lote_id = null`.
- Status inicial: `comercial`.
- Número: `PED-YYYY-NNNN` (com rand 1000–9999).

#### 3.3.2 Lista de Pedidos (`admin/pedidos.php`)
- Cards clicáveis por status com qtd e valor (Total Geral, Ag. Comercial, Ag. Financeiro, Ag. Faturamento, Cancelados); card ativo tem borda colorida.
- Filtro combinado: status (botões) + período (data inicial/data final); padrão = mês atual.
- Pedidos agrupados por `lote_id` (exibe valor total do lote; badge "N itens" quando > 1).
- Colunas: Nº Pedido, Código, Cliente, Supervisor, Data (+ hora), Tipo (Venda/Bonificação), Valor, Status, Observações (truncado 2 linhas + tooltip), Ações.
- **Colunas extras do perfil `financeiro`** (após Valor): **Crédito Aplicado** (`credito_utilizado` do lote), **Detalhamento Fiscal** (Total da NF = produtos pelo preço Network + IPI do NCM), **Accademia** (= Valor do pedido − Detalhamento Fiscal; negativo em vermelho; pedidos de bonificação exibem "—"), **% Desconto** (5% quando pagamento Pix, senão "—") e **Valor Desconto** (valor do desconto Pix do lote).
- **Filtro por Cliente** (nome, código ou CNPJ), aplicado à lista e aos cards de totais. No perfil **`financeiro`**, se o cliente filtrado pertencer a um **grupo de empresas**, o resultado inclui automaticamente os pedidos de **todos os clientes do mesmo grupo**.
- **Ações inline por perfil/status:**
  - `comercial` ou `supervisor` + status `comercial`: Aprovar → Financeiro (o `supervisor` aprova; cancelar permanece com o `comercial`).
  - `financeiro` + status `financeiro`: Aprovar → Faturado, Retornar ao Comercial, Cancelar.
- Contagem de resultados no rodapé.

#### 3.3.3 Detalhe do Pedido (`admin/pedido.php`)
- Exibe todos os itens do lote.
- Log de ações (`pedido_logs`): usuário, tipo, ação, status antes/depois, data/hora.
- Ações (conforme perfil e status atual):
  - `comercial`: Aprovar (→ financeiro) ou Cancelar.
  - `supervisor`: Aprovar (→ financeiro) ou Cancelar pedidos na etapa Comercial.
  - `financeiro`: Aprovar (→ faturamento), Retornar ao Comercial ou Cancelar.
- **Descontos e Campanhas (card):** mostra os percentuais usados — Desconto Cliente, Desconto Canal e Comercial (Cliente+Canal) — e a lista de **campanhas atingidas** pelo pedido (código, alvo, quantidade atingida × mínimo, e o desconto% ou os produtos bonificados ×multiplicador). Pedidos de bonificação indicam "sem desconto comercial".
- **Descontos extras por item (etapa Comercial):** além de cliente/canal, cada item admite **Desconto Comercial** (`pedidos.desconto_comercial`, limitado pelo teto `canal_venda.margem_negociacao` — clamp no servidor na action `set_desconto` + `max` no input) e **Desconto Diretoria** (`pedidos.desconto_diretoria`, **sem limite**). Editável só na etapa `comercial` por quem edita itens (comercial/TI). Colunas da tabela de itens: Preço Unit.(bruto) | Desc. Comercial | Desc. Diretoria | Valor Unit. c/ Desc. | Desconto (campanha) | Total.
- Recalcula descontos de campanha ao aprovar/alterar (`recalcularDescontosCampanha`) e o valor do item (`recalcularValorItem`/`melhorCampanhaItem`).
- Forma de Pagamento registrável.
- Crédito utilizado registrado no campo `credito_utilizado` do pedido.
- Botão para gerar PDF do pedido.
- **Detalhamento Fiscal (modal):** tabela por item com Código, Descrição, UN, Quantidade, **Valor Unitário (sempre o preço Network original — `tabela_precos.preco_network`, independente dos descontos do pedido; produtos sem Network ficam zerados)**, Valor Total Item (qtd × unit), Alíq./Valor de **ICMS**, Alíq./Valor de **IPI**, **PIS** (rateado + %) e **COFINS** (rateado + %), com linha de totais.
  - IPI/PIS/COFINS vêm do cadastro de **NCM** do produto (`ncm.ipi/pis/cofins`).
  - ICMS vem de `ncm_estados` pela UF do cliente: usa `icms_local` quando a UF do cliente = `EMPRESA_UF` (constante em `config.php`, padrão `SP`), senão `icms_interestadual`. Sem NCM/estado cadastrado, alíquota 0.
  - **Total da Nota Fiscal** = Total dos Produtos **+ IPI** (ICMS, PIS e COFINS estão embutidos no preço e não somam à NF).
- Log de cada mudança de status registrado em `pedido_logs` com: pedido_id, numero_pedido, usuario_nome, usuario_tipo, acao, status_antes, status_depois, detalhes, created_at.

#### 3.3.4 Fluxo de Status dos Pedidos

```
[Criado] → comercial → financeiro → faturamento → faturado
                    ↘ cancelado ↙
         financeiro pode retornar → comercial
```

#### 3.3.5 PDF do Pedido (`admin/pedido-pdf.php`)
- Geração server-side de PDF com layout do pedido, dados do cliente e itens.

---

### 3.4 Módulo Comercial — Relatórios

Todos filtram somente pedidos com status `faturado`. Todos exibem percentual de participação com barra de progresso.

#### 3.4.1 Status dos Pedidos (`admin/relatorios/status-pedidos.php`)
- Sem filtro de período (mostra todos os pedidos do banco).
- Cards por status: comercial, financeiro, faturamento, faturado, cancelado (qtd + valor total).
- Tabela dos **últimos 20 pedidos** de todos os status: nº pedido, cliente, produto (truncado), supervisor, data, valor, status.

#### 3.4.2 Faturamento Diário (`admin/relatorios/faturamento-diario.php`)
- Filtro: data inicial e data final (padrão: 1º ao dia atual do mês corrente).
- Agrupado por dia (ORDER BY dia DESC).
- Colunas: Data, Pedidos Faturados, Clientes distintos, Valor; total no rodapé.

#### 3.4.3 Faturamento Mensal (`admin/relatorios/faturamento-mensal.php`)
- Filtro: Ano (dropdown de 5 anos retroativos).
- Agrupado por mês; total anual exibido no cabeçalho.
- Colunas: Mês/Ano, Pedidos, Clientes distintos, Valor Faturado, % do Total.

#### 3.4.4 Faturamento Anual (`admin/relatorios/faturamento-anual.php`)
- Sem filtro (exibe todos os anos com dados).
- Colunas: Ano, Pedidos, Clientes distintos, Valor Faturado, Participação % (barra de progresso).

#### 3.4.5 Faturamento por Cliente (`admin/relatorios/faturamento-cliente.php`)
- Filtro: Ano.
- Rankeia clientes por valor DESC; badge dourado (#1, #2, #3) para os 3 primeiros.
- Colunas: Rank, Cliente, Cidade/UF, supervisor, Pedidos, Valor, Participação %.

#### 3.4.6 Faturamento por Canal de Venda (`admin/relatorios/faturamento-canal.php`)
- Filtro: Ano.
- Colunas: Canal, Clientes, Pedidos, Valor, Participação %.

#### 3.4.7 Faturamento por Supervisor (`admin/relatorios/faturamento-supervisor.php`)
- Filtro: Ano. Título da página: "Faturamento por Supervisor".
- Agrupa por `COALESCE(supervisor, vendedor)`; rankeia por valor DESC; badge dourado (#1, #2, #3).
- Colunas: Rank, Supervisor, Clientes, Pedidos, Valor, Participação %.

#### 3.4.8 Faturamento por Estado (`admin/relatorios/faturamento-estado.php`)
- Filtro: Ano.
- Colunas: UF (badge azul), Clientes, Pedidos, Valor, Participação %.

#### 3.4.9 Faturamento por Região (`admin/relatorios/faturamento-regiao.php`)
- Filtro: Ano.
- Mapeamento fixo UF → Região: Centro-Oeste (DF,GO,MT,MS), Norte (AC,AP,AM,PA,RO,RR,TO), Nordeste (AL,BA,CE,MA,PB,PE,PI,RN,SE), Sul (PR,RS,SC), Sudeste (ES,MG,RJ,SP). UFs não mapeadas → "Outros".
- Ordenado por valor DESC.
- Colunas: Região, Pedidos, Valor, Participação % (barra de progresso mais larga).

---

### 3.5 Módulo Financeiro Admin

Acesso a todas as telas: `financeiro` **ou** `tecnologia da informacao` (o menu Financeiro aparece para esses perfis; `comercial` também acessa as rotas diretamente).

#### 3.5.0 Clientes — Financeiro (`admin/financeiro/clientes.php`)
- Visão dos clientes sob a ótica financeira (títulos, saldo devedor, limite de crédito).

#### 3.5.1 Contas a Receber — Financeiro (`admin/financeiro/contas-receber.php`)
- Cards de resumo: **Em Aberto**, **Vencido**, **Pago** (valores líquidos totais — sem filtro de período).
- Filtro por situação (botões: Todos / Aberto / Vencido / Pago / Cancelado).
- Ordenado por `data_vencimento ASC` (vencimento mais próximo primeiro).
- Linha em vermelho (`table-danger`) quando situação = vencido.
- Colunas: Documento, Cliente, Valor, Desconto, Líquido, Emissão, Vencimento, Pagamento, Situação, Ações.
- Operações: Criar / Editar / Excluir.

#### 3.5.2 Contas a Pagar (`admin/financeiro/contas-pagar.php`)
- Filtro por situação (botões).
- Linha em vermelho quando vencido.
- Colunas: Documento, Fornecedor, Valor, Desconto, Juros, Emissão, Vencimento, Pagamento, Situação, Ações.
- Operações: Criar / Editar / Excluir.

#### 3.5.3 Fornecedores (`admin/financeiro/fornecedores.php`)
**Campos:** Código, Razão Social*, CNPJ, E-mail, Telefone, Cidade, UF, Status.
- Busca por razão social ou CNPJ.
- Operações: Criar / Editar / Excluir.

#### 3.5.4 Ordens de Pagamento (`admin/financeiro/ordens-pagamento.php`)
**Campos:** Número da Ordem, Descrição, Valor, Data, Status (pendente/aprovado/cancelado).
- Filtro por status (botões).
- Operações: Criar / Editar / Excluir.

#### 3.5.5 Ordens de Investimento (`admin/financeiro/ordens-investimento.php`)
**Campos:** Número da Ordem, Descrição, Valor do Investimento, Retorno Esperado, Data, Status (pendente/aprovado/cancelado).
- Cards de resumo (apenas status `aprovado`): **Total Investido**, **Retorno Esperado**, **ROI Esperado** (calculado: `(retorno/investimento − 1) × 100%`).
- Filtro por status.
- Operações: Criar / Editar / Excluir.

---

## 4. Portal do Cliente

### 4.1 Dashboard do Cliente (`cliente/dashboard.php`)
- Cards: Total de Pedidos, Aguardando Comercial, Aguardando Financeiro, Aguardando Faturamento, Faturados, Cancelados, Valor Total Faturado.
- Cards financeiros: Títulos Abertos, Títulos Vencidos, Saldo Devedor (abertos + vencidos).
- Últimos 5 pedidos do cliente (com produto, data, valor, status).
- **Popup de Bônus MA:** exibido automaticamente na **primeira visita por sessão** (key `ma_popup_shown_{id}`), se o bônus do mês anterior foi aprovado e `material_apoio > 0`. Calcula e exibe o valor estimado do bônus: `faturamento_mes_anterior × material_apoio / 100`.

### 4.2 Novo Pedido (Cliente) (`cliente/novo-pedido.php`)
- Mesmo fluxo do admin, porém o "cliente" é o próprio usuário logado.
- Sem seleção de cliente (fixado no `cliente_id` da sessão).
- Desconto do cliente + canal aplicado automaticamente.
- Campanhas ativas exibidas em chips (desconto% ou 🎁 com os produtos bonificados) e aplicadas; campanhas de bonificação geram pedido bonificado separado ao finalizar. Bonificação selecionável passa por `cliente/bonificacao-selecionavel.php`.
- **Moeda:** preços e totais exibidos na moeda do cliente (BRL/USD/EUR) via `colPrecoMoeda()`; símbolo por `simboloMoedaJS()`.
- Abas por linha, carrinho offcanvas, etapa de resumo e observação.
- **Modal de Forma de Pagamento:** Pix (com **5% de desconto**, destacado), Boleto 30 / 30-60 / 30-60-90 dias; opção de **usar crédito** (limitado à diferença `valor do pedido − detalhamento fiscal`, com confirmação quando o crédito disponível excede a diferença). Cartão selecionado em verde claro.
- **Bônus de Exportação:** clientes do canal Exportação recebem, ao finalizar a venda, um bônus selecionável de **5% do valor da venda** na moeda do cliente, para escolher entre todos os produtos ativos (ver §5.12).

### 4.3 Meus Pedidos (`cliente/meus-pedidos.php`)
- Lista **todos** os pedidos do cliente logado, agrupados por `lote_id`.
- Filtro por status (botões: Todos, Aguardando Comercial, Aguardando Financeiro, Aguardando Faturamento, cancelado).
- Colunas: Nº Pedido (+ badge "N itens" se lote), Data (+ hora), Tipo (Venda/Bonificação), Valor, Status, link Detalhes.
- Contagem de resultados no rodapé.

### 4.4 Detalhe do Pedido — Cliente (`cliente/pedido.php`)
- Visualização dos itens do lote.
- Acesso ao PDF do pedido.

### 4.5 Financeiro do Cliente (`cliente/financeiro.php`)
- Cards de resumo: Total, Em Aberto, Vencido, Pago (valores líquidos = valor − descontos).
- Filtro por situação (botões: Todos / Em Aberto / Vencido / Pago / Cancelado).
- Tabela somente com os títulos do cliente logado.
- Colunas: Documento, Valor, Desconto, Líquido, Emissão, Vencimento, Pagamento, Situação.
- Linha em vermelho quando vencido.
- **Somente leitura** — cliente não pode criar ou editar títulos.

### 4.6 Perfil do Cliente (`cliente/perfil.php`)
**Campos editáveis:** E-mail (com verificação de unicidade), Telefone 1, Telefone 2.
**Campos somente leitura (sidebar):** Código do Cliente, Razão Social, CNPJ, supervisor, Canal de Venda, Status, CEP, Endereço, Número, Complemento, Bairro, Cidade, UF, País. Mensagem: "Para alterar dados cadastrais, entre em contato com o suporte."
**Alteração de senha (formulário separado):** Nova Senha + Confirmar Senha; validações: ambas obrigatórias, devem ser iguais, mínimo 4 caracteres.

### 4.7 Grupo de Empresas — Troca de CNPJ
- **Seleção no login (`cliente/selecionar-cnpj.php`):** quando o cliente pertence a um grupo com mais de uma empresa ativa, escolhe com qual CNPJ entrar; a sessão passa a operar como o cliente selecionado.
- **Troca durante a sessão (`cliente/trocar-cnpj.php`):** permite alternar para outra empresa do mesmo grupo sem novo login, retornando à página de origem. As opções vêm de `grupo_opcoes`/`grupo_selecao` na sessão e são validadas no servidor.

---

## 5. Regras de Negócio

### 5.1 Descontos no Pedido (camadas somadas + campanha multiplicativa)
1. **Desconto do Cliente** — `desconto_cliente` fixo no cadastro do cliente.
2. **Desconto do Canal** — `desconto_canal` limitado ao teto do `canal_venda.desconto`.
3. **Desconto Comercial** (por item) — `pedidos.desconto_comercial`, limitado ao teto `canal_venda.margem_negociacao`. Editável na etapa Comercial.
4. **Desconto Diretoria** (por item) — `pedidos.desconto_diretoria`, **sem limite**. Editável na etapa Comercial.
5. **Desconto de Campanha** — acionado quando o gatilho da campanha é atingido (ver §3.2.5).
   - **Modelo novo (condições):** cada condição é um filtro composto (linha E grupo E subgrupo E produto) com mínimo por **quantidade OU valor**; todas as condições combinam em **E**. O desconto incide sobre os alvos (`campanha_desconto_alvo`) ou, na ausência de alvo, sobre os itens das condições.
   - **Modelo legado (por produto/categoria):** soma das quantidades dos produtos/critérios da campanha; Linha/Grupo/Subgrupo coexistem (OR). Loops legados **barram** `quantidade <= 0` para não aplicar o cabeçalho do modelo novo a todos os itens.
   - Restrição de canal: campanha com `canal_venda_id` afeta somente clientes do mesmo canal.
   - Aplica o **maior** desconto de campanha elegível por item.
   - **Campanhas tipo Bonificação:** não alteram o preço; geram um **pedido bonificado separado** com os brindes × multiplicador (`floor(qtd_alvo / mínimo)`). Geradas no fechamento de vendas novas (`gerarBonificacaoCampanha`/`criarPedidoBonificado`), em `cliente/novo-pedido.php` e `admin/novo-pedido.php`.

**Fórmula do valor por item:**
`valor = qtd × preço × (1 − (dCliente + dCanal + dComercial + dDiretoria)/100) × (1 − campDesc/100)`

Ou seja, cliente + canal + comercial + diretoria **somam** (cap 100%) e a campanha é **multiplicativa**. O `preço` é a coluna da moeda do cliente (ver §5.10).

Pedidos de **bonificação** usam a tabela de preços **Network** (`valor_total = qtd × preço Network`, sem desconto de cliente/canal/campanha e `cotacao=NULL`).

### 5.1.1 Pagamento, Crédito e Desconto Pix
- **Forma de pagamento:** escolhida em modal (Pix, Boleto 30, 30/60, 30/60/90 dias) ao finalizar pedidos de venda.
- **Crédito do cliente:** aplicado primeiro, porém **limitado à diferença `valor do pedido − detalhamento fiscal (NF)`** (ver 5.7). O excedente fica para outro pedido.
- **Desconto Pix (5%):** se a forma for **Pix**, incide sobre o valor já líquido de crédito (`Pix = 5% × (Total − Crédito)`); somente vendas. Gravado em `pedidos.desconto_pagamento` (1º item do lote), sem alterar o `valor_total` dos itens (é dedução no resumo, como o crédito).
- **Ordem de cálculo / Total a Pagar** = `Total − Crédito − Pix`. No resumo do pedido (cliente/admin e PDF) são exibidas as linhas "Crédito aplicado" e, abaixo, "Desconto Pix (5%)".

### 5.2 Múltiplo de Venda
- Quantidade visual (informada pelo usuário) × múltiplo do produto = quantidade real registrada.
- Exibido como badge na tabela; cálculo em tempo real no front.

### 5.3 Lote de Pedidos
- Pedido com múltiplos produtos: todos compartilham o mesmo `lote_id` (UUID `uniqid('L', true)`).
- Pedido de produto único: `lote_id = null`.
- Listagens agrupam por `COALESCE(lote_id, CAST(id AS CHAR))` para exibir como um único registro.
- Aprovações e reprovações afetam todo o lote.
- Recálculo de desconto de campanha (`recalcularDescontosCampanha`) considera quantidades totais de todos os itens do lote — por linha/grupo/subgrupo **e** pela soma dos produtos de cada campanha de produtos.

### 5.4 Log de Pedidos (`pedido_logs`)
- Toda mudança de status é registrada com: pedido_id, numero_pedido, usuario_nome, usuario_tipo, acao, status_antes, status_depois, detalhes, created_at.

### 5.5 Bônus de Desempenho
- Trimestral; aprovado ou cancelado manualmente por gestor.
- Compara faturamento de pedidos faturados no trimestre vs. meta cadastrada.
- Percentual do bônus configurado no cliente (campo `bonus_desempenho`, máx. 4%).
- Log em `bonus_desempenho_logs`.

### 5.6 Bônus de Material de Apoio
- Mensal; aprovado ou cancelado manualmente.
- Somente para clientes com `material_apoio > 0`.
- Valor do bônus = faturamento do mês × percentual MA.
- Média de atraso nos pagamentos exibida como indicador de risco (join com `contas_receber`).
- Log em `bonus_ma_logs` com campo `valor_utilizado`.
- Notificação ao cliente: popup no primeiro acesso ao dashboard do mês seguinte ao aprovado.

### 5.7 Créditos a Clientes
- Concedidos manualmente por gestor com: cliente, descrição, data, valor, observação interna.
- Workflow de aprovação/reprovação registrado em `creditos_logs`.
- Campo `valor_utilizado` no registro do crédito rastreia quanto foi consumido em pedidos.
- Campo `credito_utilizado` no pedido registra o valor aplicado.
- Crédito com `valor_utilizado > 0` não pode ser excluído.
- **Limite de uso por pedido (diferença fiscal):** o crédito só pode ser usado sobre a **diferença = valor do pedido − detalhamento fiscal (NF)** (a mesma "Accademia": `NF = Σ qtd × preço Network × (1 + IPI/100)`). Se o crédito disponível do cliente exceder essa diferença, o sistema **pergunta** se ele quer usar apenas a diferença e **manter o restante para outro pedido**. Validado no cliente (modal de pagamento) e no servidor (`cliente/novo-pedido.php`). Se a diferença for ≤ 0 (NF ≥ valor do pedido), o crédito não pode ser aplicado.

### 5.8 E-mail de Cliente (Chave Única de Login)
- E-mail é chave de login do portal cliente; deve ser único em `clientes`.
- Na importação, conflitos são resolvidos zerando o campo (linha não é rejeitada).
- No cadastro manual, unicidade é garantida pelo banco (UNIQUE no schema).

### 5.9 Migrações de Schema
- Executadas automaticamente via `try/ALTER TABLE` e `CREATE TABLE IF NOT EXISTS` na função `db()` do `config.php` a cada conexão.
- **Colunas em `pedidos`:** `lote_id`, `desconto_campanha`, `forma_pagamento`, `credito_utilizado`, `desconto_pagamento`, `supervisor`, `moeda`, `cotacao`, `desconto_comercial`, `desconto_diretoria`; ajuste de enum em `pedidos.status`.
- **Colunas em `clientes`:** `email`, `senha`, `desconto_canal`, `supervisor`.
- **Colunas em `tabela_precos`:** `preco_network`, `preco_auxiliar`, `preco_dolar`, `preco_euro`.
- **Colunas em `campanhas`:** `canal_venda_id`, `tipo`, `valor_alvo`, `bonif_modo`, `bonif_limite_tipo`, `bonif_limite_valor`, `ativo`, `bonif_selec_modo`.
- **Colunas em `campanha_condicoes`:** `criterio_modo`, `valor_min`, `cond_linha`, `cond_grupo`, `cond_subgrupo`, `cond_produto_id`.
- **Colunas diversas:** `margem_negociacao` em `canal_venda`; `valor_utilizado` em `bonus_ma_logs` e `creditos`; `celular` em `usuarios` (renomeado de `telefone`); ajuste de enum em `usuarios.tipo_acesso` (11 valores).
- **Tabelas criadas:** `pedido_logs`, `grupo_empresas`, `grupo_empresas_clientes`, `webhook_logs`, `whatsapp_logs` (renomeada de `sms_logs`), `campanha_bonificacao`, `configuracoes`, `campanha_condicoes`, `campanha_desconto_alvo`, `campanha_bonif_pool`.
- A tabela `kit_composicao` **não** é criada no `config.php`: é criada e semeada sob demanda em `admin/cadastros/produtos.php` na primeira abertura da tela.

### 5.10 Multimoeda (BRL / USD / EUR)
- A **moeda do cliente** (`clientes.moeda`) determina em qual moeda o pedido é feito. `colPrecoMoeda($moeda, $bonificacao)` mapeia a moeda → coluna de preço: BRL → `preco_padrao`, USD → `preco_dolar`, EUR → `preco_euro`; **bonificação sempre `preco_network`**.
- `pedidos.moeda` é gravada na criação (todos os INSERTs, inclusive bonificação). `simboloMoeda()` → R$ / US$ / €; `moedaBR()`/`moedaCorrente()` formatam por moeda.
- **Cotação:** na criação grava-se `pedidos.cotacao` (cotação da moeda; NULL para BRL e bonificação). Áreas admin exibem a conversão `valor × cotacao` em R$.
- **Totais em BRL:** toda agregação de `pedidos.valor_total` converte USD/EUR → BRL via `valor_total * (CASE WHEN moeda <> 'BRL' AND cotacao > 0 THEN cotacao ELSE 1 END)` — aplicada em cards de dashboard/pedidos, relatórios de faturamento, Bônus MA e Bônus Desempenho. Bonificação (`cotacao=NULL`) fica fora da conversão.
- **Permanecem em R$ (base BR):** seção fiscal/NF (ICMS/IPI/PIS/COFINS, preço Network), saldos de crédito e o financeiro (contas a receber/pagar têm tabelas próprias).

### 5.11 Internacionalização — Área do Cliente (PT/EN/ES)
- A área do cliente é traduzida conforme `clientes.idioma` (pt|en|es); admin e telas pré-login ficam sempre em PT.
- `idiomaAtual()` resolve o idioma do cliente logado (query cacheada); `t($pt, ...$args)` traduz usando **a própria frase PT como chave** (dicionário em `lang.php`, seções `en`/`es`, fallback para PT); `et()` = `e(t())`; `htmlLang()` define `<html lang>`.
- Placeholders `%s` (sprintf) no PHP; em JS usa `%1..%4` + helper `_tfmt()`/objeto `T`. `statusBadge()` traduz os rótulos de status.
- **Produtos têm tradução própria** nos cadastros (`produtos.desc_cliente_pt/_en/_es`) — não usam `t()`.

### 5.12 Bônus de Exportação
- Clientes do canal **Exportação** (`canal_venda` cujo nome contém "export", via `canalEhExportacao()`), ao finalizar uma **venda nova na área do cliente**, recebem um **bônus selecionável por valor = 5% do valor da venda** (`BONUS_EXPORTACAO_PCT`, `bonusExportacaoSelecionavel()`), para escolher entre **todos os produtos ativos**.
- **Respeita a moeda do cliente:** limite e preços na moeda do cliente (`colPrecoMoeda`), sem conversão ≈R$ na área do cliente.
- Reutiliza `cliente/bonificacao-selecionavel.php`; ao confirmar, `criarPedidoBonificado()` grava o pedido bonificado (status comercial) com o mesmo preço exibido na seleção (fallback `preco_network` quando não há preço na moeda); `cotacao=NULL`.
- Escopo: apenas vendas novas do cliente (não em edição, não em pedido de bonificação/MA).

---

## 6. Integrações e Utilitários

| Recurso | Versão/Fonte | Uso |
|---------|-------------|-----|
| SheetJS (xlsx.js) | 0.18.5 / cdnjs | Leitura e geração de `.xlsx/.xls` no browser |
| Bootstrap | 5.3.2 / jsdelivr | Framework CSS/JS; modais, offcanvas, toasts, badges |
| Bootstrap Icons | 1.11.3 / jsdelivr | Ícones em toda a interface |
| PDF de Pedido | Geração server-side PHP | Layout formatado com dados do pedido |
| Webhook Pipefy | `api/webhook-pipefy.php` | Recebe POST do Pipefy (header `X-Webhook-Token`), faz upsert de clientes via `FIELD_MAP` e registra em `webhook_logs` |
| WhatsApp (2FA) | `enviarWhatsappCodigo()` em `config.php` | Envia código de verificação de acesso; registra em `whatsapp_logs`/`logs/whatsapp.log` (modo simulação; pronto para provedor real) |
| Cotação de câmbio | AwesomeAPI (USD-BRL / EUR-BRL) | `buscarCotacaoAPI()`/`cotacaoDia()`; cacheada 1×/dia em `configuracoes` |

### 6.1 Webhook Pipefy (`api/webhook-pipefy.php`)
- Endpoint `POST /Sis_Ped/api/webhook-pipefy.php`, autenticado por token no header `X-Webhook-Token` (`WEBHOOK_SECRET`).
- `FIELD_MAP` mapeia campos do card do Pipefy para colunas da tabela `clientes` (identificação, endereço, contato, supervisor).
- Faz upsert de cliente e grava cada evento (sucesso/erro) em `webhook_logs`.

### 6.2 Cotação de Câmbio (AwesomeAPI)
- `buscarCotacaoAPI()` consulta USD-BRL e EUR-BRL via cURL; `cotacaoDia($moeda)` cacheia a cotação do dia em `configuracoes` (`cotacao_usd`, `cotacao_eur`, `cotacao_data`) — 1 chamada por dia, com fallback ao último valor.
- Botão "Buscar cotação" em `tabela-precos.php` (`?cotacao=1`) atualiza o cache manualmente.

---

## 7. Banco de Dados — Tabelas

| Tabela | Descrição | Campos-chave |
|--------|-----------|-------------|
| `clientes` | Compradores do portal cliente | id, codigo_cliente, cnpj, cpf, razao_social, email (UNIQUE), senha, canal_venda_id (FK), desconto_cliente, desconto_canal, bonus_desempenho, material_apoio, limite_credito, idioma, moeda, status |
| `usuarios` | Usuários internos | id, nome, email (UNIQUE), senha, tipo_acesso (11 valores), tipo_usuario (texto; "Externo" ⇒ 2FA), departamento, divisao_vendas, celular, status |
| `produtos` | Catálogo de produtos | id, codigo_produto (UNIQUE), linha, grupo, subgrupo, descricao_pt/en/es, desc_cliente_pt/en/es, multiplo, ncm_id (FK), status |
| `tabela_precos` | Preços por produto | id, produto_id (FK), preco_padrao, preco_network, preco_auxiliar, preco_dolar (calc.), preco_euro (calc.) |
| `ncm` | Classificação fiscal | id, nome_categoria, ncm, cest, ipi, pis, cofins |
| `ncm_estados` | ICMS por UF | ncm_id (FK), uf, icms_local, icms_interestadual |
| `canal_venda` | Canais de venda | id, canal, faixa_faturamento, desconto (teto p/ desconto_canal), margem_negociacao (teto p/ desconto_comercial) |
| `configuracoes` | Config. chave/valor | chave (PK), valor, updated_at — `dolar_seguranca`, `euro_seguranca`, `cotacao_usd/eur/data` |
| `campanhas` | Cabeçalho da campanha (agrupada por `codigo_campanha`) | id, codigo_campanha, produto_id (opt), linha, grupo, subgrupo, canal_venda_id (opt), quantidade, desconto, tipo (desconto/bonificacao), ativo, bonif_modo, bonif_selec_modo, bonif_limite_tipo, bonif_limite_valor, valor_alvo (legado) |
| `campanha_condicoes` | Condições (gatilho) — filtro composto E | id, codigo_campanha, cond_linha, cond_grupo, cond_subgrupo, cond_produto_id, criterio_modo (quantidade/valor), quantidade, valor_min |
| `campanha_desconto_alvo` | Onde o desconto da campanha incide | id, codigo_campanha, alvo_tipo, alvo_valor |
| `campanha_bonif_pool` | Pool selecionável por categoria (bonificação) | id, codigo_campanha, alvo_tipo, alvo_valor |
| `campanha_bonificacao` | Produtos bonificados (lista fixa) | id, codigo_campanha, produto_id, quantidade |
| `kit_composicao` | Composição de produtos do grupo Kit | id, kit_codigo, produto_codigo, nome, qtd |
| `grupo_empresas` | Grupos de empresas (CNPJs) | id, nome, descricao, created_at |
| `grupo_empresas_clientes` | Vínculo grupo ↔ cliente | id, grupo_id, cliente_id (UNIQUE grupo+cliente) |
| `webhook_logs` | Log de webhooks recebidos (Pipefy) | id, origem, evento, status, detalhe, cliente_id, created_at |
| `whatsapp_logs` | Log de códigos de verificação 2FA enviados | id, usuario_id, destino, remetente, mensagem, ip_origem, status, created_at |
| `metas` | Metas trimestrais por cliente | id, cliente_id (FK), trimestre, ano, meta_cliente |
| `pedidos` | Pedidos (1 reg. por item) | id, numero_pedido (UNIQUE), tipo_venda, data_pedido, cliente_id (FK), produto_id (FK), supervisor, lote_id, quantidade_total, valor_total, desconto_campanha, desconto_comercial, desconto_diretoria, moeda, cotacao, forma_pagamento, credito_utilizado, desconto_pagamento, status, observacoes |
| `pedido_logs` | Histórico de ações | id, pedido_id, numero_pedido, usuario_nome, usuario_tipo, acao, status_antes, status_depois, detalhes, created_at |
| `contas_receber` | Títulos a receber | id, numero_documento, cliente_id (FK), valor_receber, descontos, data_emissao, data_vencimento, data_pagamento, situacao |
| `contas_pagar` | Títulos a pagar | id, numero_documento, fornecedor_id (FK), valor_pagar, descontos, juros, data_emissao, data_vencimento, data_pagamento, situacao |
| `fornecedores` | Fornecedores | id, codigo, razao_social, cnpj, email, telefone, cidade, estado, status |
| `ordens_pagamento` | Ordens de pagamento | id, numero_ordem, descricao, valor, data_ordem, status (pendente/aprovado/cancelado) |
| `ordens_investimento` | Ordens de investimento | id, numero_ordem, descricao, valor, retorno_esperado, data_ordem, status |
| `creditos` | Créditos concedidos a clientes | id, cliente_id (FK), descricao, observacao_interna, valor, valor_utilizado, data, usuario_id (FK) |
| `creditos_logs` | Log de aprovações de crédito | id, credito_id (FK), acao, usuario_nome, created_at |
| `bonus_desempenho_logs` | Log de aprovações de bônus trimestral | id, cliente_id, trimestre, ano, acao, usuario_nome, created_at |
| `bonus_ma_logs` | Log de aprovações de bônus MA mensal | id, cliente_id, mes, ano, acao, valor_utilizado, usuario_nome, created_at |

---

## 8. Requisitos Técnicos

- **PHP:** 7.4+ (extensões `pdo_mysql` e `curl` para a cotação de câmbio)
- **Banco:** MySQL 5.7+ ou MariaDB
- **Servidor:** Apache (XAMPP recomendado para desenvolvimento)
- **Schema inicial:** `sis_ped.sql` (inclui dados de exemplo)
- **Migrações:** automáticas via `db()` no `config.php` a cada conexão
- **Senhas:** armazenadas em texto plano no schema atual (sem hash); os códigos 2FA são guardados com `password_hash()` na sessão.

**Constantes de configuração (`config.php`):**

| Constante | Uso |
|-----------|-----|
| `DB_HOST` / `DB_NAME` / `DB_USER` / `DB_PASS` | Conexão MySQL/PDO |
| `BASE_URL` | Prefixo de URL da aplicação (ex.: `/Sis_Ped`) |
| `ASSETS_URL` / `LAYOUT_PATH` | Caminhos de assets e layout |
| `EMPRESA_UF` | UF da empresa — decide ICMS local × interestadual no detalhamento fiscal (padrão `SP`) |
| `IP_LIBERADO` | IP que dispensa 2FA para usuários "Externo" |
| `WHATSAPP_REMETENTE` | Número remetente da verificação 2FA |
| `WHATSAPP_CODIGO_VALIDADE` | Validade do código 2FA, em segundos (padrão 600) |
| `WEBHOOK_SECRET` | Token do webhook Pipefy (header `X-Webhook-Token`) |
