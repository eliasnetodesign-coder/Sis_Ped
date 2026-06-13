# Especificação Funcional — Sis_Ped

## 1. Visão Geral

Sistema web de gestão de pedidos B2B para indústria de cosméticos. Dois portais distintos: **Admin** (equipe interna) e **Cliente** (comprador externo). Backend PHP 7.4+ com MySQL/PDO, frontend Bootstrap 5, JavaScript vanilla, SheetJS para Excel.

---

## 2. Autenticação e Controle de Acesso

### 2.1 Login (`login.php`)
- Formulário único para todos os tipos de usuário.
- **Clientes** autenticam com **e-mail de cliente** (`e-mail_cliente`) + senha.
- **Usuários admin** autenticam com **e-mail** + senha.
- Botão de mostrar/ocultar senha.
- Redirecionamento automático:
  - Cliente → `cliente/dashboard.php`
  - Admin → `admin/dashboard.php`
- Caso já logado, redireciona para `index.php`.
- Mensagens de erro via flash session.

### 2.2 Perfis de Usuário

| Perfil | Portal | Permissões |
|--------|--------|-----------|
| `comercial` | Admin | Acesso total: todos os pedidos, relatórios; aprova e cancela pedidos na etapa Comercial | Acesso limitado aos cadastros, permitindo edição de descontos, limite de crédito, idioma e bonus
| `financeiro` | Admin | Acesso ao módulo financeiro e cadastros financeiros; aprova, cancela e retorna pedidos na etapa Financeiro |
| `supervisor` | Admin | Visão filtrada somente aos próprios clientes e pedidos; pode criar pedidos |
| `tecnologia da informação` | Acesso total a todos os módulos |
| `cliente` | Cliente | Acesso apenas ao próprio portal: pedidos, financeiro, perfil |

### 2.3 Proteção de Rotas
- `requireAdmin()` — permite `comercial`, `financeiro`, `supervisor`
- `requireComercial()` — permite `comercial`, `supervisor`
- `requireCliente()` — permite somente `cliente`
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

#### 3.2.2 Cadastro de Produtos (`admin/cadastros/produtos.php`)
**Campos:** Código (único), Linha, Grupo, Subgrupo, Código de Barras, Descrição PT / EN / ES, Nuance, Múltiplo de venda (int), Preço Padrão (gerenciado em `tabela_precos`), Vendas Distribuidor (R$), Vendas Varejo (R$), Vendas Exportação (R$), NCM (FK lookup), CEST, Status (ativo/inativo).

**Operações:** Editar; Preço Padrão criado/atualizado junto.
- **Importar Excel:** upsert por código; lookup de NCM pelo código; campos espelhados.
- **Exportar Excel:** dados completos.

#### 3.2.3 Tabela de Preços (`admin/cadastros/tabela-precos.php`)
- Associa produto a **três faixas de preço**: Preço Padrão*, Preço Network*, Preço Auxiliar (opcional).
- Busca por código ou descrição.
- **Importar Excel:** colunas A=Código do Produto, B=Preço Padrão, C=Preço Network, D=Preço Auxiliar; auto-detecção de cabeçalho; parseamento de formato BRL (`1.234,56`); upsert por produto.
- Preview de até 200 linhas; aviso quando arquivo excede 200.
- Não é possível criar duas entradas para o mesmo produto (faz upsert).

#### 3.2.4 Canal de Venda (`admin/cadastros/canal-venda.php`)
**Campos:** Canal*, Faixa de Faturamento (texto, ex: "Acima de R$ 50.000"), Desconto Máximo %.
- O valor de Desconto serve de teto para `desconto_canal` nos clientes.

#### 3.2.5 Campanhas (`admin/cadastros/campanhas.php`)
**Campos:** Código da Campanha*, Canal de Venda (opcional — "Todos" ou canal específico), critério exclusivo (apenas um de: Produto, Linha, Grupo, Subgrupo), Quantidade Mínima*, Desconto %.
(Se selecionar Produto, pode adicionar mais de um Produto e bloqueia Linha, Grupo, Subgrupo, Se selecionar Linha, Grupo ou Subgrupo, pode selcionar mais uma categoria, e bloqueia Produto)
- Regra de exclusividade: ao selecionar um critério, os demais são zerados e desabilitados via JS.
- Campanha sem canal afeta todos os clientes; com canal, afeta somente clientes desse canal.
- Listagem mostra: código, produto, linha/grupo, canal, qtd mínima, desconto%.

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
- Colunas: Nº Pedido, Cliente, Data (+ hora), Tipo (Venda/Bonificação), Valor, Status, Observações (truncado 2 linhas + tooltip), Ações.
- **Ações inline por perfil/status:**
  - `comercial` + status `comercial`: Aprovar → Financeiro, Cancelar.
  - `financeiro` + status `financeiro`: Aprovar → Faturado, Retornar ao Comercial, Cancelar.
- Contagem de resultados no rodapé.

#### 3.3.3 Detalhe do Pedido (`admin/pedido.php`)
- Exibe todos os itens do lote.
- Log de ações (`pedido_logs`): usuário, tipo, ação, status antes/depois, data/hora.
- Ações (conforme perfil e status atual):
  - `comercial`: Aprovar (→ financeiro) ou Cancelar.
  - `financeiro`: Aprovar (→ faturamento), Retornar ao Comercial ou Cancelar.
- Recalcula descontos de campanha ao aprovar/alterar (`recalcularDescontosCampanha`).
- Forma de Pagamento registrável.
- Crédito utilizado registrado no campo `credito_utilizado` do pedido.
- Botão para gerar PDF do pedido.
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

#### 3.4.7 Faturamento por supervisor (`admin/relatorios/faturamento-supervisor.php`)
- Filtro: Ano.
- Rankeia supervisores por valor DESC; badge dourado (#1, #2, #3).
- Colunas: Rank, supervisor, Clientes, Pedidos, Valor, Participação %.

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

Acesso a todas as telas: `comercial` **ou** `financeiro`.

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
- Campanhas ativas exibidas e aplicadas.
- Abas por linha, carrinho offcanvas, etapa de resumo e observação.

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

---

## 5. Regras de Negócio

### 5.1 Descontos no Pedido (3 camadas)
1. **Desconto do Cliente** — `desconto_cliente` fixo no cadastro do cliente.
2. **Desconto do Canal** — `desconto_canal` limitado ao teto do `canal_venda.desconto`; aplicado além do desconto do cliente.
3. **Desconto de Campanha** — acionado quando quantidade total do critério ≥ mínimo da campanha.
   - Hierarquia do critério: produto específico > linha > grupo > subgrupo.
   - Restrição de canal: campanha com `canal_venda_id` afeta somente clientes do mesmo canal.
   - Aplica o maior desconto de campanha elegível.

**Fórmula:** `valor = qtd × preco × (1 − dCliente/100 − dCanal/100) × (1 − campDesc/100)`

Pedidos de **bonificação** sempre têm `valor_total = 0`.

### 5.2 Múltiplo de Venda
- Quantidade visual (informada pelo usuário) × múltiplo do produto = quantidade real registrada.
- Exibido como badge na tabela; cálculo em tempo real no front.

### 5.3 Lote de Pedidos
- Pedido com múltiplos produtos: todos compartilham o mesmo `lote_id` (UUID `uniqid('L', true)`).
- Pedido de produto único: `lote_id = null`.
- Listagens agrupam por `COALESCE(lote_id, CAST(id AS CHAR))` para exibir como um único registro.
- Aprovações e reprovações afetam todo o lote.
- Recálculo de desconto de campanha (`recalcularDescontosCampanha`) considera quantidades totais de todos os itens do lote.

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

### 5.8 E-mail de Cliente (Chave Única de Login)
- E-mail é chave de login do portal cliente; deve ser único em `clientes`.
- Na importação, conflitos são resolvidos zerando o campo (linha não é rejeitada).
- No cadastro manual, unicidade é garantida pelo banco (UNIQUE no schema).

### 5.9 Migrações de Schema
- Executadas automaticamente via `try/ALTER TABLE` na função `db()` do `config.php` a cada requisição.
- Colunas adicionadas desta forma: `lote_id`, `desconto_campanha`, `forma_pagamento`, `credito_utilizado` em pedidos; `preco_network`, `preco_auxiliar` em tabela_precos; `valor_utilizado` em bonus_ma_logs e creditos; tabela `pedido_logs`.

---

## 6. Integrações e Utilitários

| Recurso | Versão/Fonte | Uso |
|---------|-------------|-----|
| SheetJS (xlsx.js) | 0.18.5 / cdnjs | Leitura e geração de `.xlsx/.xls` no browser |
| Bootstrap | 5.3.2 / jsdelivr | Framework CSS/JS; modais, offcanvas, toasts, badges |
| Bootstrap Icons | 1.11.3 / jsdelivr | Ícones em toda a interface |
| PDF de Pedido | Geração server-side PHP | Layout formatado com dados do pedido |

---

## 7. Banco de Dados — Tabelas

| Tabela | Descrição | Campos-chave |
|--------|-----------|-------------|
| `clientes` | Compradores do portal cliente | id, codigo_cliente, cnpj, cpf, razao_social, email (UNIQUE), senha, canal_venda_id (FK), desconto_cliente, desconto_canal, bonus_desempenho, material_apoio, limite_credito, idioma, moeda, status |
| `usuarios` | Usuários internos | id, nome, email (UNIQUE), senha, tipo_acesso (comercial/financeiro), tipo_usuario, departamento, divisao_vendas, status |
| `produtos` | Catálogo de produtos | id, codigo_produto (UNIQUE), linha, grupo, subgrupo, descricao_pt/en/es, multiplo, ncm_id (FK), status |
| `tabela_precos` | Preços por produto | id, produto_id (FK), preco_padrao, preco_network, preco_auxiliar |
| `ncm` | Classificação fiscal | id, nome_categoria, ncm, cest, ipi |
| `canal_venda` | Canais de venda | id, canal, faixa_faturamento, desconto (teto para clientes) |
| `campanhas` | Campanhas de desconto | id, codigo_campanha, produto_id (FK opt), linha, grupo, subgrupo, canal_venda_id (FK opt), quantidade, desconto |
| `metas` | Metas trimestrais por cliente | id, cliente_id (FK), trimestre, ano, meta_cliente |
| `pedidos` | Pedidos (1 reg. por item) | id, numero_pedido (UNIQUE), tipo_venda, data_pedido, cliente_id (FK), produto_id (FK), supervisor, lote_id, quantidade_total, valor_total, desconto_campanha, forma_pagamento, credito_utilizado, status, observacoes |
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

- **PHP:** 7.4+
- **Banco:** MySQL 5.7+ ou MariaDB
- **Servidor:** Apache (XAMPP recomendado para desenvolvimento)
- **Base URL:** configurada em `config.php` → constante `BASE_URL` (ex: `/Sis_Ped`)
- **Schema inicial:** `sis_ped.sql` (inclui dados de exemplo)
- **Migrações:** automáticas via `db()` no `config.php` a cada conexão
- **Senhas:** armazenadas em texto plano no schema atual (sem hash)
