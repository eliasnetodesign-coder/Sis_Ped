# 01 — Visão Geral

## Propósito

Sistema web de gestão de pedidos **B2B** para a indústria de cosméticos **Itallian Hairtech**. Cobre o ciclo completo do pedido: catálogo e preços, criação do pedido pelo cliente ou pela equipe interna, aprovação em duas etapas (Comercial → Financeiro), faturamento, relatórios e módulo financeiro.

## Portais

| Portal | Público | Acesso |
|--------|---------|--------|
| **Admin** | Equipe interna (comercial, financeiro, supervisor, TI) | `admin/*` |
| **Cliente** | Comprador externo (B2B) | `cliente/*` |

## Stack

- **Backend:** PHP 7.4+ com **MySQL/PDO**.
- **Frontend:** **Bootstrap 5.3**, JavaScript vanilla, **SheetJS** para importação/exportação Excel no browser.
- **PDF:** geração server-side em PHP.
- **Sem framework** — arquitetura de scripts PHP diretos, com um `config.php` central de funções utilitárias.

## Recursos transversais

- **Multimoeda** — pedidos e preços em BRL, USD ou EUR conforme a moeda do cliente; agregações convertem para BRL. Ver [Multimoeda e i18n](11-multimoeda-e-i18n.md).
- **Internacionalização (i18n)** — a área do cliente é traduzida para PT/EN/ES conforme `clientes.idioma`.
- **2FA por WhatsApp** — usuários internos do tipo "Externo" exigem verificação por código fora do IP autorizado. Ver [Autenticação e Acesso](02-autenticacao-e-acesso.md).
- **Tema claro/escuro** — o portal admin alterna tema (persistido em `localStorage`, chave `sisped-theme`).

## Estrutura de diretórios

```
Sis_Ped/
├── config.php              # Constantes, DB, funções utilitárias (fonte de verdade das regras)
├── lang.php                # Dicionário de tradução EN/ES
├── login.php / logout.php  # Autenticação unificada
├── verificar-acesso.php    # 2FA por WhatsApp
├── trocar-senha.php        # Troca de senha temporária
├── layout/                 # header.php (sidebar) + footer.php
├── cliente/                # Portal do cliente
├── admin/                  # Portal admin
│   ├── cadastros/          # Cadastros comerciais
│   ├── relatorios/         # Relatórios de faturamento
│   └── financeiro/         # Módulo financeiro
├── api/                    # Webhook Pipefy
├── assets/                 # CSS, imagens
└── sis_ped.sql             # Schema inicial + dados de exemplo
```

## Requisitos técnicos

- **PHP:** 7.4+ (extensões `pdo_mysql` e `curl` para a cotação de câmbio).
- **Banco:** MySQL 5.7+ ou MariaDB.
- **Servidor:** Apache (XAMPP recomendado para desenvolvimento).
- **Schema inicial:** `sis_ped.sql` (inclui dados de exemplo).
- **Migrações:** automáticas via `db()` no `config.php` a cada conexão (ver [Banco de Dados](12-banco-de-dados.md)).
- **Senhas:** armazenadas em texto plano no schema atual (sem hash). Os códigos 2FA usam `password_hash()` na sessão.

### Constantes de configuração (`config.php`)

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

## Identidade visual

Paleta verde institucional: `#004733`, `#2b6a4d`, `#568d66`. Layout Bootstrap 5.3 com sidebar (flyouts no desktop, offcanvas no mobile).
