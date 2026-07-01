# 09 — Portal do Cliente

Portal `cliente/*`. Traduzido para PT/EN/ES conforme `clientes.idioma` (ver [Multimoeda e i18n](11-multimoeda-e-i18n.md)).

## Dashboard (`dashboard.php`)

- **Cards:** Total de Pedidos, Aguardando Comercial/Financeiro/Faturamento, Faturados, Cancelados, Valor Total Faturado.
- **Cards financeiros:** Títulos Abertos, Títulos Vencidos, Saldo Devedor (abertos + vencidos).
- Últimos 5 pedidos do cliente.
- **Popup de Bônus MA:** exibido na **primeira visita por sessão** (`ma_popup_shown_{id}`) se o bônus do mês anterior foi aprovado e `material_apoio > 0`. Estima `faturamento_mes_anterior × material_apoio / 100`.

## Novo Pedido (`novo-pedido.php`)

- Mesmo fluxo do admin, mas o "cliente" é o próprio usuário logado (sem seleção de cliente).
- Desconto do cliente + canal aplicado automaticamente.
- **Moeda:** preços e totais na moeda do cliente (BRL/USD/EUR).
- Campanhas ativas em chips (desconto% ou 🎁 brindes); bonificação selecionável passa por `bonificacao-selecionavel.php`.
- Abas por linha, carrinho offcanvas, resumo e observação.
- **Modal de Forma de Pagamento:** Pix (**5% de desconto**, destacado), Boleto 30 / 30-60 / 30-60-90 dias; opção de **usar crédito** (limitado à diferença `valor − detalhamento fiscal`).
- **Bônus de Exportação:** clientes do canal Exportação recebem bônus selecionável de **5% do valor da venda** (ver [Multimoeda e i18n](11-multimoeda-e-i18n.md#bônus-de-exportação)).

## Meus Pedidos (`meus-pedidos.php`)

- **Todos** os pedidos do cliente logado, agrupados por `lote_id`.
- Filtro por status (Todos, Ag. Comercial, Ag. Financeiro, Ag. Faturamento, Cancelado).
- Colunas: Nº (+ badge "N itens"), Data, Tipo (Venda/Bonificação), Valor, Status, Detalhes.

## Detalhe do Pedido (`pedido.php`)

- Visualização dos itens do lote e acesso ao PDF.

## Financeiro (`financeiro.php`)

- Cards: Total, Em Aberto, Vencido, Pago (líquidos).
- Filtro por situação; linha em vermelho quando vencido.
- Somente os títulos do cliente logado. **Somente leitura** — cliente não cria/edita títulos.

## Perfil (`perfil.php`)

- **Editáveis:** E-mail (unicidade), Telefone 1, Telefone 2.
- **Somente leitura (sidebar):** Código, Razão Social, CNPJ, Supervisor, Canal, Status, endereço completo. Aviso: "Para alterar dados cadastrais, entre em contato com o suporte."
- **Alteração de senha** (formulário separado): Nova + Confirmar (ambas obrigatórias, iguais, mínimo 4 caracteres).

## Grupo de Empresas

Permite operar com múltiplos CNPJs do mesmo grupo:

- **Seleção no login (`selecionar-cnpj.php`):** quando o cliente pertence a um grupo com mais de uma empresa ativa, escolhe com qual CNPJ entrar.
- **Troca durante a sessão (`trocar-cnpj.php`):** alterna para outra empresa do mesmo grupo sem novo login, retornando à página de origem. As opções vêm de `grupo_opcoes`/`grupo_selecao` na sessão e são validadas no servidor.

Relacionado: [Pedidos](06-pedidos.md), [Regras de Negócio](10-regras-de-negocio.md), [Autenticação e Acesso](02-autenticacao-e-acesso.md).
