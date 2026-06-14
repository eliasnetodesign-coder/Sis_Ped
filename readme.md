# Sis_Ped — Sistema de Pedidos B2B

Sistema web de gestão de pedidos para indústria de cosméticos (Itallian Hairtech), com dois portais distintos: um para a equipe interna (admin) e outro para os clientes compradores.

## Tecnologias

- **Backend:** PHP 7.4+ puro (sem framework), PDO/MySQL
- **Frontend:** Bootstrap 5, Bootstrap Icons, JavaScript vanilla
- **Excel:** SheetJS (importação e exportação de planilhas `.xlsx/.xls`)
- **Integração:** Webhook Pipefy → cadastro/atualização de clientes (`api/webhook-pipefy.php`)
- **Banco:** MySQL — schema em `sis_ped.sql`; migrações incrementais automáticas no `config.php`

## Estrutura de Pastas

```
Sis_Ped/
├── admin/                  # Portal administrativo
│   ├── cadastros/          # Produtos, Clientes, Grupo de Empresas, Tabela de Preços,
│   │                       # Campanhas, Canal de Venda, NCM, Metas, Bônus, Créditos, Usuários
│   │   └── kit_composicao.php  # Semente da composição de KITs (gerada da planilha)
│   ├── financeiro/         # Contas a receber/pagar, fornecedores, ordens de pagamento/investimento
│   ├── relatorios/         # Faturamento diário/mensal/anual/cliente/canal/vendedor/estado/região + status
│   ├── dashboard.php
│   ├── pedidos.php         # Lista de pedidos com filtros
│   ├── novo-pedido.php     # Criação de pedido (carrinho)
│   ├── pedido.php          # Detalhe, aprovação, edição de itens e log
│   └── pedido-pdf.php      # Geração de PDF
├── cliente/                # Portal do cliente
│   ├── dashboard.php
│   ├── novo-pedido.php
│   ├── meus-pedidos.php
│   ├── pedido.php / pedido-pdf.php
│   ├── financeiro.php
│   ├── perfil.php
│   ├── selecionar-cnpj.php # Escolha de empresa no login (grupo de empresas)
│   └── trocar-cnpj.php     # Troca de empresa durante a sessão
├── api/
│   └── webhook-pipefy.php  # Recebe dados do Pipefy e faz upsert de clientes
├── layout/                 # Header (menu lateral) e footer compartilhados
├── assets/                 # CSS e JS próprios
├── config.php              # Configuração de banco, funções globais e migrações automáticas
├── login.php / logout.php
├── trocar-senha.php        # Troca de senha (primeiro acesso / senha temporária)
├── index.php               # Roteamento por perfil
├── install.php             # Instalação inicial
└── sis_ped.sql             # Schema e dados de exemplo
```

## Perfis de Acesso

| Perfil | Portal | Resumo |
|--------|--------|--------|
| `comercial` | Admin | Acesso completo: cadastros, pedidos, relatórios, aprovação na etapa Comercial |
| `financeiro` | Admin | Módulo financeiro; aprova/retorna/cancela na etapa Financeiro |
| `supervisor` | Admin | Visão filtrada aos próprios clientes/pedidos; cria pedidos |
| `vendedor` | Admin | Equivalente operacional ao comercial para pedidos/cadastros |
| `tecnologia da informacao` | Admin | Acesso amplo (inclui campos sensíveis em cadastros) |
| `cliente` | Cliente | Pedidos próprios, títulos financeiros, perfil, troca de CNPJ |

> Outros tipos de acesso de `usuarios` (recursos humanos, marketing, diretoria, etc.) existem no enum mas não recebem permissões de módulo específicas.

## Funcionalidades Principais

### Portal Admin
- **Dashboard** com indicadores de pedidos e valor faturado
- **Novo Pedido** em 2 etapas: seleção de cliente + produtos por linha, carrinho offcanvas com resumo
- **Gestão de Pedidos** com filtro por status e período; aprovação inline e edição de itens
- **Fluxo de Aprovação:** Comercial → Financeiro → Faturamento → Faturado (ou Cancelado; Financeiro pode retornar ao Comercial)
- **Cadastros completos:** produtos, clientes, grupo de empresas, tabela de preços (3 faixas), campanhas, canais de venda, NCM, metas, usuários, fornecedores
- **Produtos com aba KIT:** para produtos do grupo "Kit", composição editável (busca de produtos por código/nome, quantidade, adicionar/remover)
- **Grupo de Empresas:** agrupa CNPJs (clientes) em accordion expansível; usado para troca de empresa no login
- **Campanhas avançadas:** por **vários produtos** ou por **combinação** de Linha/Grupo/Subgrupo, com canal opcional e quantidade mínima
- **Import/Export Excel** em clientes, produtos e tabela de preços (preview + upsert)
- **Bônus de Desempenho** trimestral e **Bônus de Material de Apoio** mensal por cliente
- **Concessão de Créditos** para clientes
- **9 relatórios** (status + 8 de faturamento) filtráveis por período/ano

### Portal Cliente
- Dashboard com resumo de pedidos e boletos; popup de Bônus MA no primeiro acesso do mês
- Fazer pedido com desconto automático (cliente + canal + campanha)
- Histórico de pedidos e acesso ao PDF
- Visualização de títulos financeiros (somente leitura)
- Edição de perfil e senha
- **Grupo de Empresas:** clientes que compartilham grupo escolhem a empresa no login e podem trocar de CNPJ durante a sessão

## Regras de Desconto

Os preços aplicados em cada pedido combinam até 3 camadas:
1. **Desconto do Cliente** — percentual fixo por cliente
2. **Desconto do Canal de Venda** — limitado ao teto do canal cadastrado
3. **Desconto de Campanha** — ativado por quantidade mínima:
   - **Por produto:** uma campanha pode conter vários produtos; o mínimo considera a **soma** das quantidades de todos os produtos da campanha no pedido e, atingido, o desconto vale para **todos** eles.
   - **Por categoria:** Linha, Grupo e/ou Subgrupo podem ser combinados; o mínimo é avaliado pelo total da categoria.
   - Aplica-se o **maior** desconto elegível; bonificações sempre têm valor zero.

Pedidos de bonificação usam a tabela de preços **Network** e têm `valor_total = 0`.

## Instalação

1. Importar `sis_ped.sql` no MySQL para criar o banco `sis_ped`
2. Ajustar `config.php`: `DB_HOST`, `DB_USER`, `DB_PASS` e `BASE_URL`
3. Apontar Apache para a raiz do projeto (XAMPP recomendado)
4. Acessar `/login.php` — as migrações incrementais rodam automaticamente na primeira conexão

**Credenciais de exemplo (login por e-mail):**
- Comercial: `comercial@teste.com` / `123`
- Financeiro: `financeiro@teste.com` / `123`
- Cliente: `cliente@teste.com` / `123`

> Tanto clientes quanto usuários internos autenticam por **e-mail + senha**. Senhas são armazenadas em texto plano no schema atual.
