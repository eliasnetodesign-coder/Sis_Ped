# 12 — Banco de Dados

Banco `sis_ped` (MySQL/MariaDB). Schema inicial em `sis_ped.sql`; evolução via **migrações automáticas** em `config.php`.

## Tabelas

| Tabela | Descrição | Campos-chave |
|--------|-----------|-------------|
| `clientes` | Compradores do portal cliente | id, codigo_cliente, cnpj, cpf, razao_social, email (UNIQUE), senha, canal_venda_id (FK), desconto_cliente, desconto_canal, bonus_desempenho, material_apoio, limite_credito, idioma, moeda, status |
| `usuarios` | Usuários internos | id, nome, email (UNIQUE), senha, tipo_acesso (11 valores), tipo_usuario ("Externo" ⇒ 2FA), departamento, divisao_vendas, celular, status |
| `produtos` | Catálogo de produtos | id, codigo_produto (UNIQUE), linha, grupo, subgrupo, descricao_pt/en/es, desc_cliente_pt/en/es, multiplo, ncm_id (FK), status |
| `tabela_precos` | Preços por produto | id, produto_id (FK), preco_padrao, preco_network, preco_auxiliar, preco_dolar (calc.), preco_euro (calc.) |
| `ncm` | Classificação fiscal | id, nome_categoria, ncm, cest, ipi, pis, cofins |
| `ncm_estados` | ICMS por UF | ncm_id (FK), uf, icms_local, icms_interestadual |
| `canal_venda` | Canais de venda | id, canal, faixa_faturamento, desconto (teto p/ desconto_canal), margem_negociacao (teto p/ desconto_comercial) |
| `configuracoes` | Config. chave/valor | chave (PK), valor, updated_at — `dolar_seguranca`, `euro_seguranca`, `cotacao_usd/eur/data` |
| `campanhas` | Cabeçalho da campanha (agrupada por `codigo_campanha`) | id, codigo_campanha, produto_id (opt), linha, grupo, subgrupo, canal_venda_id (opt), quantidade, desconto, tipo, ativo, bonif_modo, bonif_selec_modo, bonif_limite_tipo, bonif_limite_valor, valor_alvo (legado) |
| `campanha_condicoes` | Condições (gatilho) — filtro composto E | id, codigo_campanha, cond_linha, cond_grupo, cond_subgrupo, cond_produto_id, criterio_modo (quantidade/valor), quantidade, valor_min |
| `campanha_desconto_alvo` | Onde o desconto incide | id, codigo_campanha, alvo_tipo, alvo_valor |
| `campanha_bonif_pool` | Pool selecionável por categoria | id, codigo_campanha, alvo_tipo, alvo_valor |
| `campanha_bonificacao` | Produtos bonificados (lista fixa) | id, codigo_campanha, produto_id, quantidade |
| `kit_composicao` | Composição de produtos do grupo Kit | id, kit_codigo, produto_codigo, nome, qtd |
| `grupo_empresas` | Grupos de empresas (CNPJs) | id, nome, descricao, created_at |
| `grupo_empresas_clientes` | Vínculo grupo ↔ cliente | id, grupo_id, cliente_id (UNIQUE grupo+cliente) |
| `webhook_logs` | Log de webhooks (Pipefy) | id, origem, evento, status, detalhe, cliente_id, created_at |
| `whatsapp_logs` | Log de códigos 2FA enviados | id, usuario_id, destino, remetente, mensagem, ip_origem, status, created_at |
| `metas` | Metas trimestrais por cliente | id, cliente_id (FK), trimestre, ano, meta_cliente |
| `pedidos` | Pedidos (1 reg. por item) | id, numero_pedido (UNIQUE), tipo_venda, data_pedido, cliente_id (FK), produto_id (FK), supervisor, lote_id, quantidade_total, valor_total, desconto_campanha, desconto_comercial, desconto_diretoria, moeda, cotacao, forma_pagamento, credito_utilizado, desconto_pagamento, status, observacoes |
| `pedido_logs` | Histórico de ações | id, pedido_id, numero_pedido, usuario_nome, usuario_tipo, acao, status_antes, status_depois, detalhes, created_at |
| `contas_receber` | Títulos a receber | id, numero_documento, cliente_id (FK), valor_receber, descontos, data_emissao, data_vencimento, data_pagamento, situacao |
| `contas_pagar` | Títulos a pagar | id, numero_documento, fornecedor_id (FK), valor_pagar, descontos, juros, data_emissao, data_vencimento, data_pagamento, situacao |
| `fornecedores` | Fornecedores | id, codigo, razao_social, cnpj, email, telefone, cidade, estado, status |
| `ordens_pagamento` | Ordens de pagamento | id, numero_ordem, descricao, valor, data_ordem, status |
| `ordens_investimento` | Ordens de investimento | id, numero_ordem, descricao, valor, retorno_esperado, data_ordem, status |
| `creditos` | Créditos concedidos a clientes | id, cliente_id (FK), descricao, observacao_interna, valor, valor_utilizado, data, usuario_id (FK) |
| `creditos_logs` | Log de aprovações de crédito | id, credito_id (FK), acao, usuario_nome, created_at |
| `bonus_desempenho_logs` | Log de bônus trimestral | id, cliente_id, trimestre, ano, acao, usuario_nome, created_at |
| `bonus_ma_logs` | Log de bônus MA mensal | id, cliente_id, mes, ano, acao, valor_utilizado, usuario_nome, created_at |

## Migrações automáticas

Executadas via `try/ALTER TABLE` e `CREATE TABLE IF NOT EXISTS` na função `db()` do `config.php` a **cada conexão** (idempotentes; erros silenciados). Isso permite evoluir o schema sem scripts manuais.

- **Colunas em `pedidos`:** `lote_id`, `desconto_campanha`, `forma_pagamento`, `credito_utilizado`, `desconto_pagamento`, `supervisor`, `moeda`, `cotacao`, `desconto_comercial`, `desconto_diretoria`; ajuste de enum em `status`.
- **Colunas em `clientes`:** `email`, `senha`, `desconto_canal`, `supervisor`.
- **Colunas em `tabela_precos`:** `preco_network`, `preco_auxiliar`, `preco_dolar`, `preco_euro`.
- **Colunas em `campanhas`:** `canal_venda_id`, `tipo`, `valor_alvo`, `bonif_modo`, `bonif_limite_tipo`, `bonif_limite_valor`, `ativo`, `bonif_selec_modo`.
- **Colunas em `campanha_condicoes`:** `criterio_modo`, `valor_min`, `cond_linha`, `cond_grupo`, `cond_subgrupo`, `cond_produto_id`.
- **Diversas:** `margem_negociacao` em `canal_venda`; `valor_utilizado` em `bonus_ma_logs` e `creditos`; `celular` em `usuarios` (renomeado de `telefone`); enum de `usuarios.tipo_acesso` (11 valores).
- **Tabelas criadas:** `pedido_logs`, `grupo_empresas`, `grupo_empresas_clientes`, `webhook_logs`, `whatsapp_logs` (renomeada de `sms_logs`), `campanha_bonificacao`, `configuracoes`, `campanha_condicoes`, `campanha_desconto_alvo`, `campanha_bonif_pool`.

> `kit_composicao` **não** é criada no `config.php`: é criada e semeada sob demanda em `admin/cadastros/produtos.php` na primeira abertura da tela.

Relacionado: [Campanhas](05-campanhas.md), [Pedidos](06-pedidos.md), [Multimoeda e i18n](11-multimoeda-e-i18n.md).
