# Sis_Ped — Sistema de Pedidos B2B

Sistema web de gestão de pedidos para indústria de cosméticos, com dois portais distintos: um para a equipe interna (admin) e outro para os clientes compradores.

## Tecnologias

- **Backend:** PHP 7.4+ puro (sem framework), PDO/MySQL
- **Frontend:** Bootstrap 5, Bootstrap Icons, JavaScript vanilla
- **Excel:** SheetJS (importação e exportação de planilhas `.xlsx/.xls`)
- **Banco:** MySQL — schema em `sis_ped.sql`

## Estrutura de Pastas

```
sis_ped/
├── admin/               # Portal administrativo
│   ├── cadastros/       # Clientes, Produtos, NCM, Canal, Campanhas, Metas, Bônus, Usuários
│   ├── financeiro/      # Contas a receber/pagar, fornecedores, ordens
│   ├── relatorios/      # Faturamento diário/mensal/anual/por cliente/canal/vendedor/estado/região
│   ├── dashboard.php
│   ├── pedidos.php      # Lista de pedidos com filtros
│   ├── novo-pedido.php  # Criação de pedido (carrinho)
│   ├── pedido.php       # Detalhe, aprovação e log
│   └── pedido-pdf.php   # Geração de PDF
├── cliente/             # Portal do cliente
│   ├── dashboard.php
│   ├── novo-pedido.php
│   ├── meus-pedidos.php
│   ├── financeiro.php
│   └── perfil.php
├── layout/              # Header e footer compartilhados
├── assets/              # CSS e JS próprios
├── config.php           # Configuração de banco, funções globais e migrações
├── login.php / logout.php
├── install.php          # Instalação inicial
└── sis_ped.sql          # Schema e dados de exemplo
```

## Perfis de Acesso

| Perfil | Portal | Resumo |
|--------|--------|--------|
| `comercial` | Admin | Acesso completo: cadastros, pedidos, relatórios, aprovação etapa Comercial |
| `financeiro` | Admin | Módulo financeiro, aprovação/reprovação etapa Financeiro |
| `vendedor` | Admin | Visão filtrada aos próprios clientes e pedidos |
| `cliente` | Cliente | Pedidos próprios, títulos financeiros, perfil |

## Funcionalidades Principais

### Portal Admin
- **Dashboard** com indicadores de pedidos e valor faturado
- **Novo Pedido** em 2 etapas: seleção de cliente + produtos por linha, carrinho offcanvas com resumo
- **Gestão de Pedidos** com filtro por status e período; ações de aprovação inline
- **Fluxo de Aprovação:** Comercial → Financeiro → Faturado (ou Reprovado)
- **Cadastros completos:** clientes, produtos, tabela de preços, canais de venda, campanhas, NCM, metas, usuários, fornecedores
- **Import/Export Excel** em clientes e produtos (com preview e upsert inteligente)
- **Bônus de Desempenho** trimestral e **Bônus de Material de Apoio** mensal por cliente
- **Concessão de Créditos** para clientes
- **8 relatórios de faturamento** filtráveis por período

### Portal Cliente
- Dashboard com resumo de pedidos e boletos
- Fazer pedido com desconto automático (cliente + canal + campanha)
- Histórico de pedidos e acesso ao PDF
- Visualização de títulos financeiros
- Edição de perfil e senha

## Regras de Desconto

Os preços aplicados em cada pedido combinam até 3 camadas:
1. **Desconto do Cliente** — percentual fixo por cliente
2. **Desconto do Canal de Venda** — limitado ao teto do canal cadastrado
3. **Desconto de Campanha** — ativado por quantidade mínima (por produto, linha, grupo ou subgrupo)

## Instalação

1. Importar `sis_ped.sql` no MySQL para criar o banco `sis_ped`
2. Ajustar `config.php`: `DB_HOST`, `DB_USER`, `DB_PASS` e `BASE_URL`
3. Apontar Apache para a raiz do projeto
4. Acessar `/login.php`

**Credenciais de exemplo:**
- Comercial: `comercial@teste.com` / `123`
- Financeiro: `financeiro@teste.com` / `123`
- Cliente: código `CLI001` / `123` (clientes autenticam com `codigo_cliente`, não e-mail)
