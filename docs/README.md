# Documentação — Sis_Ped

Documentação modular do **Sis_Ped**, sistema web de gestão de pedidos B2B da **Itallian Hairtech**. Cada arquivo detalha um módulo do sistema. A fonte-mãe é a [especificação funcional](../especificacao.md) na raiz do projeto.

## Índice

| # | Documento | Conteúdo |
|---|-----------|----------|
| 01 | [Visão Geral](01-visao-geral.md) | Propósito, arquitetura, stack e requisitos técnicos |
| 02 | [Autenticação e Acesso](02-autenticacao-e-acesso.md) | Login, 2FA por WhatsApp, perfis e proteção de rotas |
| 03 | [Portal Administrativo](03-portal-admin.md) | Dashboard admin, navegação e tema |
| 04 | [Cadastros Comerciais](04-cadastros-comerciais.md) | Índice dos cadastros (detalhados em [`cadastros/`](cadastros/)): clientes, produtos, preços, canal, NCM, metas, bônus, créditos, usuários |
| 05 | [Campanhas](05-campanhas.md) | Modelo avançado de campanhas (condições, desconto, bonificação) |
| 06 | [Pedidos](06-pedidos.md) | Novo pedido, lista, detalhe, fluxo de status, descontos e PDF |
| 07 | [Relatórios](07-relatorios.md) | 9 relatórios de faturamento |
| 08 | [Módulo Financeiro](08-modulo-financeiro.md) | Contas, fornecedores, ordens de pagamento/investimento |
| 09 | [Portal do Cliente](09-portal-cliente.md) | Dashboard, pedidos, financeiro, perfil, grupo de empresas |
| 10 | [Regras de Negócio](10-regras-de-negocio.md) | Descontos, pagamento, lote, bônus e créditos |
| 11 | [Multimoeda e i18n](11-multimoeda-e-i18n.md) | Preços BRL/USD/EUR, cotação, tradução PT/EN/ES, bônus exportação |
| 12 | [Banco de Dados](12-banco-de-dados.md) | Tabelas, colunas-chave e migrações automáticas |
| 13 | [Integrações](13-integracoes.md) | Webhook Pipefy, WhatsApp, cotação de câmbio, bibliotecas |

## Convenções

- **Portais:** o sistema tem dois portais — **Admin** (equipe interna) e **Cliente** (comprador externo).
- **Perfis de acesso:** `comercial`, `financeiro`, `supervisor`, `tecnologia da informacao` (acesso total) e `cliente`. Ver [Autenticação e Acesso](02-autenticacao-e-acesso.md).
- **Fonte de verdade:** a lógica de negócio (descontos, campanhas, moeda) é avaliada no **PHP** (`config.php`); o JavaScript apenas espelha para preview.
