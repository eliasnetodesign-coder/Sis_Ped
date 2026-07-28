# Canal de Venda — Especificação

> Documento de especificação funcional. Define **como deve se comportar**,
> não como foi implementado. Atualizar sempre que uma decisão for tomada.
> Relacionado: `canal-venda.md` (resumo), `clientes-spec.md`, `../06-pedidos.md`,
> `../10-regras-de-negocio.md`, `../11-multimoeda-e-i18n.md`.
> **Escopo:** apenas o CRUD do Canal de Venda. O uso do Desconto Máximo % e
> da Margem de Negociação % dentro do fluxo de Pedidos está detalhado em
> `../06-pedidos.md` e `../10-regras-de-negocio.md`.

**Versão:** 1 · **Última atualização:** 2026-07-28 · **Status:** 🟢 Estável

---

## Campos do Cadastro <a name="campos"></a>

### Regra definida
> O Canal é identificado por um nome único; possui três percentuais de
> governança independentes: Desconto Máximo, Margem de Negociação e Bônus
> (Pedido Bonificado).

- **Canal:** obrigatório e **único** (comparação case-insensitive) — evita ambiguidade entre canais e vínculos incorretos no cadastro do cliente.
- **Faixa de Faturamento:** texto livre, opcional — apenas informativo (ex.: "Acima de R$ 50.000"), sem impacto em regra de negócio.
- **Desconto Máximo %:** numérico, faixa **0 a 100%**, default `0` — teto do `desconto_canal` aplicado no cadastro do cliente (ver [Governança dos Tetos](#governanca)).
- **Margem de Negociação %:** numérico, faixa **0 a 100%**, default `0` — teto do Desconto Comercial aplicável por item no pedido (ver [Governança dos Tetos](#governanca)).
- **Bônus (Pedido Bonificado) %:** numérico, faixa **0 a 100%**, default `0` — percentual do bônus concedido automaticamente ao cliente vinculado (ver [Bônus de Exportação](#bonus-exportacao)).
- **Impacto em Legados:** hoje não existe unicidade de nome nem faixa de validação nos três percentuais — nomes de canal duplicados na base precisam ser resolvidos manualmente antes de aplicar a constraint `UNIQUE`. Canais que hoje possuem "export" no nome precisam ser migrados manualmente com Bônus % = `5` (valor herdado da constante `BONUS_EXPORTACAO_PCT`) para não perder o benefício (ver [Bônus de Exportação](#bonus-exportacao)).

---

## Governança dos Tetos (Desconto Máximo vs. Margem de Negociação) <a name="governanca"></a>

### Regra definida
> Desconto Máximo % e Margem de Negociação % são tetos independentes porque
> pertencem a camadas de decisão diferentes.

- **Desconto Máximo %:** teto do `desconto_canal` — decisão comercial estável, definida uma única vez no cadastro do cliente.
- **Margem de Negociação %:** teto do Desconto Comercial — decisão operacional e pontual, aplicada item a item pelo vendedor dentro do pedido (ver [Pedidos](../06-pedidos.md#detalhe-do-pedido-adminpedidophp) e [Regras de Negócio](../10-regras-de-negocio.md#descontos-no-pedido)).
- **Justificativa:** separar os dois evita que uma negociação pontual dentro de um pedido corroa permanentemente o desconto padrão do cliente — cada teto tem seu próprio nível de aprovação/governança.
- **Impacto em Legados:** não se aplica — regra já vigente no código atual; esta seção apenas documenta uma decisão pré-existente.

---

## Validação dos Percentuais <a name="validacao"></a>

### Regra definida
> Desconto Máximo %, Margem de Negociação % e Bônus (Pedido Bonificado) % são
> limitados a 0–100% no servidor.

- **Faixa:** `0` a `100` — valores fora da faixa são rejeitados no servidor.
- **Estado atual:** o servidor hoje (`admin/cadastros/canal-venda.php:8`) apenas faz cast para `float`, sem validar faixa mínima/máxima.
- **Impacto em Legados:** registros existentes fora da faixa 0–100% (se houver) precisam ser corrigidos manualmente; a validação passa a valer apenas para novos salvamentos (criação/edição), não reprocessa retroativamente.

---

## Bônus de Exportação (Pedido Bonificado) <a name="bonus-exportacao"></a>

### Regra definida
> O bônus de exportação passa a ser controlado por um campo percentual no
> cadastro do canal (`bonus_pedido_bonificado`), não mais pela detecção do
> texto "export" no nome do canal.

- **Campo:** Bônus (Pedido Bonificado) % — numérico, 0–100%, default `0`.
- **Concessão automática:** qualquer canal com esse campo `> 0` concede ao cliente vinculado um bônus selecionável de **X% do valor da venda** (`X` = valor do campo) ao finalizar uma **venda nova na área do cliente** — substitui a constante fixa `BONUS_EXPORTACAO_PCT = 5.0`.
- **Substituição da regra antiga:** a função `canalEhExportacao()` (`config.php:1041`), que hoje detecta o canal de exportação pelo nome conter "export", deixa de ser usada; a checagem passa a ser `canal_venda.bonus_pedido_bonificado > 0`.
- **Escopo mantido:** aplica-se apenas a vendas novas do cliente no portal (não em edição, não em bonificação/MA) — regra herdada de [Multimoeda e i18n](../11-multimoeda-e-i18n.md#bônus-de-exportação).
- **Impacto em Legados:** canais que hoje concedem o bônus por terem "export" no nome (identificados via `canalEhExportacao()`) precisam ser migrados manualmente com Bônus % = `5` para não perder o benefício na troca de regra. Canais sem "export" no nome mantêm `0%` (comportamento inalterado — nunca concederam bônus).

---

## Exclusão (Soft Delete) <a name="excluir"></a>

### Regra definida
> A exclusão de um canal é um soft delete (`status = inativo`), com aviso de
> confirmação sempre exibido, independente de haver clientes vinculados.

- **Comportamento:** em vez de `DELETE FROM canal_venda` (`admin/cadastros/canal-venda.php:16-18`), a ação marca o registro como `status = 'inativo'`, seguindo o mesmo padrão de `status` usado em `clientes`, `usuarios`, `produtos` e `fornecedores` — preserva o vínculo histórico dos clientes/pedidos já associados a esse canal.
- **Aviso:** toda ação de exclusão exibe uma confirmação/aviso ao usuário antes de efetivar, **sempre** — não apenas quando há clientes vinculados.
- **Listagem:** canais com `status = 'inativo'` deixam de aparecer na listagem/selects padrão do cadastro; comportamento de exibição segue o mesmo padrão já usado no filtro de Status de `clientes` (ver [Clientes — Filtros e Listagem](clientes-spec.md#listagem)).
- **Impacto em Legados:** requer migration adicionando a coluna `status` (enum `ativo`/`inativo`, default `ativo`) em `canal_venda` — hoje a tabela só suporta hard delete e não tem essa coluna. Clientes/pedidos que já referenciam um `canal_venda_id` cujo canal foi soft-deletado continuam funcionando normalmente, pois o registro não é mais fisicamente removido.

---

## Unicidade do Nome do Canal <a name="unicidade"></a>

### Regra definida
> O nome do Canal é único entre os canais ativos (comparação case-insensitive).

- **Validação:** o servidor rejeita criação/edição com nome de canal já existente entre os canais com `status = 'ativo'`.
- **Impacto em Legados:** se já existirem canais duplicados na base atual, precisam ser resolvidos manualmente (renomear ou consolidar) antes de aplicar a constraint `UNIQUE`.

---

## Critérios de Aceite

- [ ] Dado um canal com clientes vinculados, quando o usuário exclui o canal, então o registro recebe `status = 'inativo'` (não é removido fisicamente) e uma mensagem de aviso é exibida antes da confirmação.
- [ ] Dado um canal sem nenhum cliente vinculado, quando o usuário exclui o canal, então o mesmo aviso de confirmação é exibido antes do soft delete — a regra vale sempre, não só quando há vínculo.
- [ ] Dado o campo Desconto Máximo % preenchido com `-5` ou `150`, quando o usuário salva o canal, então o sistema rejeita o valor por estar fora da faixa 0–100%.
- [ ] Dado um canal já cadastrado com nome "Varejo", quando o usuário tenta criar outro canal chamado "varejo" (qualquer caixa), então o sistema rejeita por nome duplicado.
- [ ] Dado um canal com Bônus (Pedido Bonificado) % = `5`, quando um cliente vinculado a esse canal finaliza uma venda nova no portal, então o sistema oferece o bônus selecionável de 5% do valor da venda.
- [ ] Dado um canal com Bônus (Pedido Bonificado) % = `0` (default) e nome contendo "export", quando um cliente vinculado a esse canal finaliza uma venda nova, então **nenhum** bônus é oferecido — o nome do canal deixa de ter efeito.

---

## Dependências e Impactos Cruzados

- Substituir `canalEhExportacao()` (`config.php:1041`) por `canal_venda.bonus_pedido_bonificado > 0` impacta `cliente/novo-pedido.php` e `admin/pedidos.php`, que hoje chamam essa função diretamente.
- Soft delete em `canal_venda` (campo `status`) afeta a resolução de nome/teto em `clientes-spec.md#descontos`, já que "Desconto do Canal depende do cadastro de Canal de Venda" — clientes vinculados a um canal inativo continuam resolvendo normalmente.
- Alterar o Desconto Máximo % de um canal já existente afeta a validação de `desconto_canal` documentada em `clientes-spec.md#descontos`.
- Migração de dados obrigatória: canais com "export" no nome precisam ter `bonus_pedido_bonificado` preenchido manualmente com `5` para preservar o benefício atual (ver [Bônus de Exportação](#bonus-exportacao)).

---

## Índice de Decisões já tomadas

- **Campos e unicidade:** Canal com nome único (case-insensitive); Faixa de Faturamento livre; três percentuais (Desconto Máximo, Margem de Negociação, Bônus) numéricos 0–100%, default `0` — *Alvo/Usuário* → [Ir para Regra](#campos)
- **Governança dos tetos:** Desconto Máximo % = decisão comercial estável (cadastro do cliente); Margem de Negociação % = decisão operacional pontual (item do pedido) — *Alvo/Usuário* → [Ir para Regra](#governanca)
- **Validação:** todos os percentuais limitados a 0–100% no servidor — *Alvo/Usuário* → [Ir para Regra](#validacao)
- **Bônus de Exportação:** substituído o match por nome "export" pelo campo percentual `bonus_pedido_bonificado`; canal concede bônus automaticamente quando `> 0` — *Alvo/Usuário* → [Ir para Regra](#bonus-exportacao)
- **Exclusão:** soft delete (`status = inativo`), com aviso sempre exibido antes de confirmar — *Alvo/Usuário* → [Ir para Regra](#excluir)
- **Unicidade:** nome do canal único, case-insensitive, entre canais ativos — *Alvo/Usuário* → [Ir para Regra](#unicidade)

---

## Pendências para decidir

Nenhuma pendência aberta — todas as decisões deste documento foram fechadas na v1.

---

## Changelog

| Versão | Data | O que mudou |
|---|---|---|
| 1 | 2026-07-28 | Criação inicial — spec do cadastro de Canal de Venda, derivado do código atual (`admin/cadastros/canal-venda.php`, `config.php`) e do resumo `canal-venda.md`. Decisões novas: soft delete com aviso sempre exibido, validação 0–100% dos três percentuais, nome único, e substituição da detecção de canal de exportação por nome pelo campo percentual `bonus_pedido_bonificado`. |
