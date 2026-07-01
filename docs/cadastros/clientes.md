# Cadastro de Clientes

`admin/cadastros/clientes.php` — acesso `comercial`, `supervisor` ou `tecnologia da informacao`.

## Campos

Código, CNPJ, CPF, Razão Social*, Status, CEP, Endereço, Número, Complemento, Bairro, Cidade, UF, País (default Brasil), Telefone 1/2, E-mail (login, único)*, Supervisor (dropdown de usuários tipo supervisor), Canal de Venda, Desconto do Cliente %, Desconto do Canal %, Bônus Desempenho % (máx. 4%), Bônus Material de Apoio % (máx. 5%), Limite de Crédito, **Idioma** (PT/EN/ES), **Moeda** (BRL/USD/EUR), Senha.

## Operações

- **Criar** — senha obrigatória; Desconto do Canal limitado ao teto do canal (visível para TI).
- **Editar** — senha opcional (vazio mantém a atual).
- **Excluir** — com confirmação e se não houver histórico de pedidos.
- **Filtros:** busca (código / razão social / CNPJ / e-mail), Canal, Status; padrão só ativos.

## Excel

- **Exportar** `.xlsx` com colunas de identificação, canal, descontos, contato e endereço (largura auto, máx. 45 chars).
- **Importar** `.xlsx/.xls` (drag-and-drop):
  - Ignora as **5 primeiras linhas** (dados a partir da linha 6).
  - Mapeamento por índice de coluna; extração do primeiro e-mail válido.
  - Upsert por `codigo_cliente` (ou `cnpj`).
  - Preview até 200 linhas; relatório final (inseridos / atualizados / ignorados / e-mails conflitantes).
  - Novos registros recebem senha padrão e exigem troca no 1º acesso.

## Regras relacionadas

- **E-mail** é chave única de login do portal cliente (ver [Regras de Negócio](../10-regras-de-negocio.md#e-mail-de-cliente-chave-única-de-login)).
- **Idioma** e **Moeda** governam a área do cliente (ver [Multimoeda e i18n](../11-multimoeda-e-i18n.md)).

---

Voltar ao [índice de Cadastros](../04-cadastros-comerciais.md) · Ver também [Grupo de Empresas](grupo-empresas.md).
