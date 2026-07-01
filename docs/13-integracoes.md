# 13 — Integrações e Utilitários

## Bibliotecas de frontend

| Recurso | Versão / Fonte | Uso |
|---------|----------------|-----|
| **SheetJS (xlsx.js)** | 0.18.5 / cdnjs | Leitura e geração de `.xlsx/.xls` no browser |
| **Bootstrap** | 5.3.2 / jsdelivr | CSS/JS; modais, offcanvas, toasts, badges |
| **Bootstrap Icons** | 1.11.3 / jsdelivr | Ícones em toda a interface |
| **PDF de Pedido** | Geração server-side PHP | Layout formatado com dados do pedido |

## Webhook Pipefy (`api/webhook-pipefy.php`)

- Endpoint `POST /Sis_Ped/api/webhook-pipefy.php`, autenticado por token no header `X-Webhook-Token` (`WEBHOOK_SECRET`).
- `FIELD_MAP` mapeia campos do card do Pipefy para colunas da tabela `clientes` (identificação, endereço, contato, supervisor).
- Faz **upsert** de cliente e grava cada evento (sucesso/erro) em `webhook_logs`.

## WhatsApp — 2FA (`enviarWhatsappCodigo()` em `config.php`)

- Ponto **único** de integração com a API de WhatsApp para a verificação de acesso (ver [Autenticação e Acesso](02-autenticacao-e-acesso.md)).
- Hoje registra o envio em `whatsapp_logs` e `logs/whatsapp.log` (**modo simulação**), para funcionar em ambiente sem provedor.
- Pronto para plugar um provedor real (WhatsApp Cloud API da Meta, Twilio, etc.). Remetente em `WHATSAPP_REMETENTE`; validade do código em `WHATSAPP_CODIGO_VALIDADE`.

## Cotação de câmbio (AwesomeAPI)

- `buscarCotacaoAPI()` consulta USD-BRL e EUR-BRL via cURL.
- `cotacaoDia($moeda)` cacheia a cotação do dia em `configuracoes` (`cotacao_usd`, `cotacao_eur`, `cotacao_data`) — **1 chamada por dia**, com fallback ao último valor.
- Botão "Buscar cotação" em `admin/cadastros/tabela-precos.php` (`?cotacao=1`) atualiza o cache manualmente. Ver [Multimoeda e i18n](11-multimoeda-e-i18n.md).

## Logs em arquivo

Além das tabelas de log no banco, a pasta `logs/` guarda arquivos como `whatsapp.log` (envios 2FA em modo simulação).

Relacionado: [Banco de Dados](12-banco-de-dados.md), [Autenticação e Acesso](02-autenticacao-e-acesso.md), [Multimoeda e i18n](11-multimoeda-e-i18n.md).
