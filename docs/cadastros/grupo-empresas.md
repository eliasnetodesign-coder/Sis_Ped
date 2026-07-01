# Grupo de Empresas

`admin/cadastros/grupo-empresas.php` — acesso `comercial`, `supervisor` ou `tecnologia da informacao`.

Agrupa CNPJs (clientes) para operações conjuntas e para a **troca de empresa** no portal do cliente.

## Campos do grupo

- Nome*
- Descrição

## Operações

- Criar / Editar / Excluir grupo.
- **Adicionar / remover** empresas (clientes) do grupo.
- UI em **accordion** (mesmo padrão visual do NCM): cada grupo é um item que expande/recolhe a lista de clientes.
- Adição de empresa por **autocomplete** (nome ou código), filtrando clientes já presentes no grupo.
- Após adicionar/remover, o grupo é reaberto automaticamente (âncora `#grupo-ID`).

## Modelo de dados

- `grupo_empresas` — id, nome, descricao, created_at.
- `grupo_empresas_clientes` — id, grupo_id, cliente_id (**UNIQUE** por grupo + cliente).

## Uso no portal do cliente

Clientes de um grupo com mais de uma empresa ativa escolhem o CNPJ no login (`selecionar-cnpj.php`) e podem alternar durante a sessão (`trocar-cnpj.php`). Ver [Portal do Cliente](../09-portal-cliente.md#grupo-de-empresas).

---

Voltar ao [índice de Cadastros](../04-cadastros-comerciais.md) · Ver também [Clientes](clientes.md).
