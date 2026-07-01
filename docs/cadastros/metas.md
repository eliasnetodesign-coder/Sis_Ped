# Metas

`admin/cadastros/metas.php` — acesso `comercial`, `supervisor` ou `tecnologia da informacao`.

## Campos

- Cliente (busca autocomplete por nome ou código)
- Trimestre (1º–4º TRI)
- Ano
- Meta (R$)

## Comportamento

- Exibição ordenada por ano DESC e trimestre.
- Usada no módulo de **Bônus de Desempenho** para comparar o realizado (faturamento) vs. a meta cadastrada.

## Modelo de dados

`metas` — id, cliente_id (FK), trimestre, ano, meta_cliente.

---

Voltar ao [índice de Cadastros](../04-cadastros-comerciais.md) · Ver também [Bônus de Desempenho](bonus-desempenho.md).
