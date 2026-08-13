# Metas — Especificação

> Documento de especificação funcional. Define **como deve se comportar**,
> não como foi implementado. Atualizar sempre que uma decisão for tomada.
> Relacionado: `metas.md` (resumo), `bonus-desempenho.md`, `clientes-spec.md`,
> `../10-regras-de-negocio.md`, `../12-banco-de-dados.md`.
> **Escopo:** apenas o CRUD de Metas trimestrais por cliente. O cálculo e o
> workflow de aprovação do Bônus de Desempenho estão detalhados em
> `bonus-desempenho.md` (spec própria ainda não criada); aqui documentamos
> apenas como a Meta é lida e exibida por aquela tela.

**Versão:** 2 · **Última atualização:** 2026-08-13 · **Status:** 🟢 Aprovado

---

## Campos do Cadastro <a name="campos"></a>

### Regra definida
> Uma Meta é identificada pela combinação cliente + trimestre + ano; o valor
> é sempre armazenado em reais (BRL), independente da moeda do cliente.

- **Cliente:** obrigatório — selecionado via busca autocomplete (nome ou código) sobre a lista de clientes com `status = 'ativo'`. FK `cliente_id`.
- **Trimestre:** obrigatório — select fixo com 4 opções (1º a 4º TRI); ao abrir "Nova Meta" o formulário sempre inicia em **1º TRI**, independente do trimestre vigente.
- **Ano:** obrigatório, numérico — default = ano corrente do servidor; sem faixa mínima/máxima validada (aceita qualquer inteiro).
- **Meta (R$):** numérico, default `0` — sempre em BRL, mesmo para clientes que compram em USD/EUR, pois é comparada ao faturamento já convertido para BRL na tela de Bônus de Desempenho (ver [Uso no Bônus de Desempenho](#uso-bonus)). Valor negativo é rejeitado no servidor (`meta_cliente >= 0`); o `<input type="number" min="0">` no formulário reforça isso no client, mas a validação que vale é a do backend.
- **Impacto em Legados:** nenhum campo novo. Implementado em `admin/cadastros/metas.php`: as ações `criar`/`editar` lançam exceção ("Meta não pode ser negativa.") antes de qualquer `INSERT`/`UPDATE` quando `meta_cliente < 0`. Registros negativos que já existiam na base **não** são corrigidos retroativamente por essa validação (ela só atua no salvamento).

---

## Unicidade / Duplicidade <a name="duplicidade"></a>

### Regra definida
> Não pode existir mais de uma Meta para o mesmo cliente no mesmo
> trimestre/ano; salvar uma combinação já existente atualiza o registro
> (upsert) em vez de criar um duplicado.

- **Constraint implementada:** `UNIQUE KEY cliente_trimestre_ano_unique (cliente_id, trimestre, ano)` na tabela `metas`, aplicada via migração automática idempotente em `config.php` (mesmo mecanismo — `try/ALTER TABLE` a cada conexão, erro silenciado — descrito em `../12-banco-de-dados.md`), e upsert (`INSERT ... ON DUPLICATE KEY UPDATE meta_cliente=VALUES(meta_cliente)`) na ação `criar` de `admin/cadastros/metas.php` — mesmo padrão já adotado em `tabela_precos.produto_id` (ver `tabela-precos-spec.md`).
- **Motivo da mudança:** `bonus-desempenho.php` faz `LEFT JOIN metas m ON m.cliente_id=c.id AND m.trimestre=? AND m.ano=?` sem agregação — duas metas para o mesmo cliente/trimestre duplicam a linha desse cliente na tela de Bônus de Desempenho, inflando a contagem de clientes e os totais do rodapé.
- **Impacto em Legados:** a `ALTER TABLE ... ADD UNIQUE KEY` roda automaticamente a cada conexão, mas falha silenciosamente (padrão do mecanismo de migração) se já existirem metas duplicadas na base — nesse caso a constraint **não** é criada até que os duplicados existentes sejam consolidados manualmente (manter a de maior `id`, por exemplo). Ou seja: bases limpas ganham a proteção automaticamente; bases com duplicidade legada continuam desprotegidas até limpeza manual.

---

## Listagem e Interface <a name="interface"></a>

### Regra definida
> Metas são exibidas em uma tabela simples (sem accordion), ordenada por ano
> decrescente e trimestre crescente; não há filtro de período na tela.

- **Tabela:** Código do cliente, Razão Social, Trimestre (badge "Nº TRI"), Ano, Meta (formatada em R$), Ações (editar/excluir).
- **Ordenação:** `ORDER BY ano DESC, trimestre` — dentro do mesmo ano, os trimestres aparecem em ordem crescente (1º antes do 4º).
- **Sem filtro:** todas as metas de todos os anos e clientes aparecem na mesma listagem, sem paginação.
- **Clientes inativos:** a listagem faz `JOIN clientes` sem filtrar `status` — metas de clientes inativados continuam aparecendo, mesmo que o dropdown de criação só ofereça clientes ativos.
- **Formulário:** modal único para criar/editar; o campo Cliente é um autocomplete client-side — a lista completa de clientes ativos é renderizada no HTML da página (um `<div>` por cliente, com `data-search`) e filtrada via JavaScript conforme o usuário digita, sem paginação/lazy-load.
- **Impacto em Legados:** nenhuma migration necessária; ajustes são apenas de UX/escala (relevante se a base de clientes ativos crescer muito).

---

## Exclusão <a name="excluir"></a>

### Regra definida
> A exclusão de uma Meta é definitiva (hard delete) e sem bloqueio, pois
> nenhuma outra tabela referencia `metas.id`.

- **Comportamento:** `DELETE FROM metas WHERE id=?`, precedido de confirmação via `confirm()` no navegador.
- **Sem impacto retroativo no Bônus:** o cálculo do Valor BD (`bdCalcValor()`) usa apenas `clientes.bonus_desempenho` × faturamento faturado no trimestre — **não** lê a tabela `metas`. Excluir uma meta depois que o bônus daquele trimestre já foi aprovado não altera nenhum crédito já gerado.
- **Impacto em Legados:** nenhum — mantém o comportamento já vigente no código.

---

## Uso no Bônus de Desempenho <a name="uso-bonus"></a>

### Regra definida
> A Meta é exibida lado a lado com o faturamento apenas como referência
> visual para quem aprova; ela não entra na fórmula do valor do bônus nem
> bloqueia a aprovação.

- **Onde aparece:** coluna "Meta" em `admin/cadastros/bonus-desempenho.php`, ao lado das colunas de faturamento mensal e do Total Faturado do trimestre.
- **Fórmula do Valor BD:** `faturamento faturado no trimestre (convertido para BRL) × clientes.bonus_desempenho%` — **não** utiliza `metas.meta_cliente` em nenhum ponto do cálculo.
- **Sem meta cadastrada:** a coluna Meta exibe "—"; a aprovação e o cálculo do bônus seguem normalmente, sem bloqueio.
- **Decisão:** a Meta permanece **apenas informativa** na tela de Bônus de Desempenho — não entra e não entrará na fórmula do Valor BD nem bloqueia aprovação. Confirmado como comportamento definitivo (não é mais uma pendência em aberto).
- **Divergência com `bonus-desempenho.md`:** o resumo atual desse módulo descreve a tela como comparando "faturamento vs. meta" e cita uma coluna de "atingimento %", mas o código não calcula nem exibe esse percentual — a comparação é puramente visual, duas colunas lado a lado. Isso é uma imprecisão de redação em `bonus-desempenho.md` a corrigir quando esse módulo ganhar sua própria spec (ver [Dependências e Impactos Cruzados](#dependencias-e-impactos-cruzados)), não uma decisão de produto pendente.
- **Impacto em Legados:** nenhum — esta seção documenta o comportamento já vigente no código, não uma mudança.

---

## Critérios de Aceite

- [ ] Dado um cliente sem meta cadastrada para o trimestre/ano selecionado, quando a tela de Bônus de Desempenho é aberta, então a coluna Meta exibe "—" e o Valor BD é calculado normalmente.
- [ ] Dado um conjunto de metas de anos diferentes, quando a listagem de Metas é aberta, então elas aparecem ordenadas por ano decrescente e, dentro do mesmo ano, por trimestre crescente.
- [ ] Dado um cliente com `status = 'inativo'` que possui meta cadastrada, quando a listagem de Metas é aberta, então a meta continua aparecendo — inativar o cliente não remove nem oculta a meta.
- [ ] Dado um cliente que já possui meta cadastrada para um trimestre/ano, quando o usuário tenta cadastrar outra meta para o mesmo cliente/trimestre/ano, então o sistema atualiza o registro existente (upsert) em vez de criar um duplicado.
- [ ] Dado um trimestre com bônus já aprovado pelo Financeiro (crédito gerado), quando o usuário exclui a meta daquele cliente/trimestre, então o crédito de bônus já gerado permanece intacto.
- [ ] Dado o botão "Nova Meta" clicado em qualquer trimestre do ano, quando o modal abre, então o Trimestre vem pré-selecionado como 1º TRI (não o trimestre vigente) e o Ano vem preenchido com o ano corrente.
- [ ] Dado um valor negativo digitado no campo Meta (R$), quando o usuário tenta salvar (criar ou editar), então o sistema rejeita a operação, exibe a mensagem de erro e não persiste o registro.

---

## Dependências e Impactos Cruzados <a name="dependencias-e-impactos-cruzados"></a>

- **Bônus de Desempenho** (`bonus-desempenho.md`): lê `metas.meta_cliente` via `LEFT JOIN` cliente+trimestre+ano apenas para exibição, e a meta **não** entra na fórmula do Valor BD (decisão confirmada, ver [Uso no Bônus de Desempenho](#uso-bonus)); duplicidade em `metas` duplicava linhas na tela — mitigado pela constraint UNIQUE (ver [Duplicidade](#duplicidade)), exceto em bases com duplicidade legada ainda não consolidada.
- **Clientes** (`clientes-spec.md`): `metas.cliente_id` é FK sem regra de exclusão em cascata documentada; inativar um cliente não remove nem oculta suas metas.
- **`bonus-desempenho.md`:** o resumo do módulo cita "atingimento %" e "compara realizado vs. meta" de forma que sugere uso no cálculo do bônus — precisa ser corrigido ou detalhado quando esse módulo ganhar sua própria spec (`bonus-desempenho-spec.md`, ainda não criada).

---

## Índice de Decisões já tomadas

- **Campos:** cliente obrigatório (autocomplete, só ativos); trimestre fixo 1º–4º (abre em 1º TRI); ano numérico (default = ano corrente); meta em R$, sempre BRL, default `0`, valor negativo rejeitado no servidor — → [Ir para Regra](#campos)
- **Duplicidade:** `UNIQUE (cliente_id, trimestre, ano)` com upsert, implementado — → [Ir para Regra](#duplicidade)
- **Interface:** tabela ordenada por ano DESC / trimestre ASC; sem filtro de período; listagem não filtra status do cliente; autocomplete de cliente client-side — → [Ir para Regra](#interface)
- **Exclusão:** hard delete sem bloqueio — mantido por não haver referência de outra tabela a `metas.id` — → [Ir para Regra](#excluir)
- **Uso no Bônus:** Meta é apenas informativa/comparativa na tela de Bônus de Desempenho; não entra e não entrará na fórmula do Valor BD — decisão definitiva, confirmada em 2026-08-13 — → [Ir para Regra](#uso-bonus)
- **Validação de valor negativo:** rejeitada no servidor (`meta_cliente >= 0`) desde 2026-08-13 — → [Ir para Regra](#campos)

---

## Pendências para decidir <a name="pendencias"></a>

Nenhuma pendência aberta no momento. As duas questões anteriores foram decididas em 2026-08-13:

- ~~Validação de valor negativo em Meta (R$)~~ — decidido: rejeitar no servidor (faixa ≥ 0). Implementado em `admin/cadastros/metas.php` (ver [Campos do Cadastro](#campos)).
- ~~Vínculo entre Meta e cálculo do Bônus de Desempenho~~ — decidido: a Meta permanece puramente informativa; o Valor BD nunca dependerá do atingimento da meta (ver [Uso no Bônus de Desempenho](#uso-bonus)). A divergência de redação em `bonus-desempenho.md` (que sugere um cálculo de "atingimento %" inexistente) segue como item editorial em [Dependências e Impactos Cruzados](#dependencias-e-impactos-cruzados), a corrigir quando `bonus-desempenho-spec.md` for criada — mas não é mais uma decisão de produto em aberto.

---

## Changelog

| Versão | Data | O que mudou |
|---|---|---|
| 2 | 2026-08-13 | Resolvidas as 2 pendências da v1. (1) Validação de valor negativo: decidido rejeitar no servidor — implementado em `admin/cadastros/metas.php` (exceção antes do `INSERT`/`UPDATE` quando `meta_cliente < 0`) e reforçado com `min="0"` no formulário. (2) Vínculo Meta × Bônus: confirmado que a Meta permanece puramente informativa, não entra na fórmula do Valor BD. Também implementada a constraint `UNIQUE KEY cliente_trimestre_ano_unique (cliente_id, trimestre, ano)` (migração automática em `config.php`) com upsert (`ON DUPLICATE KEY UPDATE`) na ação `criar`, fechando a lacuna entre a regra já definida na v1 e o código. |
| 1 | 2026-08-07 | Criação inicial — spec do CRUD de Metas, derivada do código atual (`admin/cadastros/metas.php`, `admin/cadastros/bonus-desempenho.php`, `config.php`) e do resumo `metas.md`. Decisão nova: constraint `UNIQUE (cliente_id, trimestre, ano)` com upsert. 2 pendências identificadas (validação de valor negativo; vínculo real entre meta e cálculo do bônus). |

---

Voltar ao [índice de Cadastros](../04-cadastros-comerciais.md) · Ver também [Metas (resumo)](metas.md) · [Bônus de Desempenho](bonus-desempenho.md).
