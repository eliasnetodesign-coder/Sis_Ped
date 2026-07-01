# Cadastro de Produtos

`admin/cadastros/produtos.php` — acesso `comercial`, `supervisor` ou `tecnologia da informacao`.

## Campos

Código (único), Linha, Grupo, Subgrupo, Código de Barras, Descrição PT/EN/ES, **Descrição Área do Cliente** PT/EN/ES, Nuance, Múltiplo de venda, Preço Padrão (gerenciado em `tabela_precos`), Vendas Distribuidor/Varejo/Exportação, NCM (FK), CEST, Status.

## Interface

- Modal em **abas**: **Dados do Produto**, **Descrição Área do Cliente** (PT/EN/ES) e **KIT** (só para grupo Kit).
- Toggle inline de Vendas (Distribuidor/Varejo/Exportação) direto na listagem (AJAX).

## Excel

- **Importar:** upsert por código; lookup de NCM pelo código (cria NCM mínimo se não existir).
- **Exportar:** dados completos.

## Aba KIT (composição)

- Exibida apenas para produtos cujo grupo normaliza para "KIT" (ex.: `-KIT`).
- Permite **adicionar produtos componentes** (autocomplete por código/nome + quantidade); cada item pode ser removido.
- Persistida em `kit_composicao` (`kit_codigo`, `produto_codigo`, `nome`, `qtd`), **substituída por completo** ao salvar o kit; removida ao excluir o produto.
- O nome exibido usa a descrição atual do produto componente (fallback ao nome gravado).

> A tabela `kit_composicao` **não** é criada em `config.php`: é criada e semeada sob demanda (a partir de `admin/cadastros/kit_composicao.php`, gerado de `COMPOSICAO_KIT.xlsx`) na primeira abertura da tela.

## Tradução de produtos

Os produtos têm tradução **própria** nos cadastros (`desc_cliente_pt/_en/_es`) — não usam o mecanismo `t()` da área do cliente. Ver [Multimoeda e i18n](../11-multimoeda-e-i18n.md#internacionalização-i18n--área-do-cliente).

---

Voltar ao [índice de Cadastros](../04-cadastros-comerciais.md) · Ver também [Tabela de Preços](tabela-precos.md).
