# NCM — Especificação

> Documento de especificação funcional. Define **como deve se comportar**,
> não como foi implementado. Atualizar sempre que uma decisão for tomada.
> Relacionado: `produtos.md` (usa ncm_id), `../06-pedidos.md` (Detalhamento Fiscal),
> `../12-banco-de-dados.md`.
> **Escopo:** CRUD de NCM e sua tabela filha `ncm_estados` (ICMS por estado).
> O cálculo fiscal nos pedidos está em `../06-pedidos.md#detalhamento-fiscal`.

**Versão:** 2 · **Última atualização:** 2026-08-04 · **Status:** 🟢 Estável

---

## Campos do NCM <a name="campos"></a>

### Regra definida
> O NCM é identificado pelo **código NCM** (obrigatório); os demais campos
> fiscais são opcionais e armazenados com até 4 casas decimais.

- **Código NCM:** texto, **obrigatório** e **único** — chave de identificação fiscal do produto; o sistema rejeita criação/edição com código já existente entre os NCMs ativos. Exibido como badge na listagem.
- **Produto / Categoria (`nome_categoria`):** texto, **opcional** — rótulo descritivo para facilitar a busca; é o título do accordion na listagem. Quando vazio, o accordion exibe o próprio código NCM como fallback.
- **CEST:** texto, opcional — código de substituição tributária; sem validação de formato no sistema.
- **II (Imposto de Importação) %:** numérico, até 4 casas decimais, nullable (`null` = não informado = 0 no cálculo fiscal) — armazenado mas **não utilizado** no Detalhamento Fiscal atual; mantido para referência futura.
- **IPI %:** numérico, até 4 casas decimais, nullable — **utilizado** no Detalhamento Fiscal: soma ao Total dos Produtos para compor o Total da Nota Fiscal.
- **PIS %:** numérico, até 4 casas decimais, nullable — **utilizado** no Detalhamento Fiscal (embutido no preço, não soma à NF).
- **COFINS %:** numérico, até 4 casas decimais, nullable — **utilizado** no Detalhamento Fiscal (embutido no preço, não soma à NF).
- **Status:** `ativo` | `inativo` — default `ativo`; controla visibilidade nos selects de produto e nos filtros padrão.

---

## Estados — ICMS por UF <a name="estados"></a>

### Regra definida
> Cada NCM pode ter alíquotas de ICMS definidas para cada um dos 27 estados;
> a tabela `ncm_estados` é substituída por completo a cada edição do NCM.

- **Lista de estados:** fixa com as 27 UFs brasileiras (incluindo DF), armazenadas como **sigla de 2 caracteres** (ex: `"SP"`, `"RJ"`) — padroniza com `clientes.estado` e elimina ambiguidade de grafia do nome completo. O formulário exibe o nome por extensão mas grava a sigla.
- **Campos por estado:**

  | Campo | Descrição | Uso no sistema |
  |---|---|---|
  | `icms_local` | ICMS quando UF do cliente = UF da empresa | **Utilizado** no Detalhamento Fiscal |
  | `icms_interestadual` | ICMS quando UF do cliente ≠ UF da empresa | **Utilizado** no Detalhamento Fiscal |
  | `indice_lp` | Índice LP | Armazenado; **não utilizado** no cálculo atual |
  | `red_base_lp` | Redução de base LP | Armazenado; **não utilizado** no cálculo atual |
  | `indice_sp_ls` | Índice SP/LS | Armazenado; **não utilizado** no cálculo atual |
  | `red_base_sp` | Redução de base SP | Armazenado; **não utilizado** no cálculo atual |
  | `fecep` | FECEP | Armazenado; **não utilizado** no cálculo atual |

- **Todos os campos de estado:** numéricos, até 4 casas decimais, nullable — campo vazio = `null` = não informado.
- **Substituição completa:** ao editar um NCM, todos os registros de `ncm_estados` daquele NCM são apagados e reinseridos — simplifica a lógica de edição; estados deixados em branco não geram registro.
- **Sem registro:** estado sem cadastro → alíquota `0` no Detalhamento Fiscal (regra do protótipo; não exibe erro).
- **Campos extras de estado** (`indice_lp`, `red_base_lp`, `indice_sp_ls`, `red_base_sp`, `fecep`): mantidos no ERP por paridade com o protótipo; armazenados mas sem uso no cálculo fiscal atual.
- **Impacto em Legados:** o protótipo grava nome completo (ex: `"São Paulo"`) em `ncm_estados.estado` — migration necessária para converter para sigla UF antes de usar a nova constraint de lookup.

---

## Listagem e Interface <a name="interface"></a>

### Regra definida
> NCMs são exibidos em um accordion, um item por NCM, ordenado por
> `nome_categoria`; os estados são exibidos em tabela dentro do accordion.

- **Accordion:** cada NCM é um item colapsável com título = `nome_categoria` + badges de código NCM e CEST + resumo dos percentuais (II, IPI, PIS, COFINS) visível sem expandir.
- **Tabela de estados:** exibida dentro do accordion expandido; mostra todos os estados com alíquota cadastrada; estado sem registro não aparece na visualização.
- **Estado vazio:** exibe mensagem "Sem estados cadastrados." na tabela.
- **Formulário:** modal com campos do NCM + tabela inline scrollável com os 27 estados fixos; os valores já cadastrados são carregados no modal ao editar.

---

## Exclusão <a name="excluir"></a>

### Regra definida
> A exclusão de um NCM é um soft delete (`status = inativo`); bloqueada se
> existir produto ativo vinculado.

- **Comportamento:** a ação marca o registro como `status = 'inativo'` — preserva o vínculo histórico com produtos e pedidos; os dados fiscais ficam disponíveis para leitura em pedidos já emitidos.
- **Bloqueio:** se existir ao menos um produto com `status = 'ativo'` vinculado ao NCM (`produtos.ncm_id`), a exclusão é **bloqueada** com mensagem de erro ("Existem produtos ativos vinculados a este NCM"). Produtos inativos não impedem a exclusão.
- **Aviso:** quando não houver bloqueio, exibe confirmação antes de efetivar o soft delete.
- **Listagem:** NCMs com `status = 'inativo'` deixam de aparecer na listagem padrão e nos selects de produto.
- **`ncm_estados`:** não são apagados no soft delete — os dados de estado permanecem, pois o NCM pode ser reativado.
- **Impacto em Legados:** requer migration adicionando coluna `status` (enum `ativo`/`inativo`, default `ativo`) em `ncm`.

---

## Uso no Detalhamento Fiscal (Pedidos) <a name="uso-fiscal"></a>

### Regra definida
> O NCM do produto alimenta o Detalhamento Fiscal do pedido; campos não
> cadastrados resultam em alíquota zero, sem erro.

- **Fonte:** o produto referencia `ncm_id`; o Detalhamento Fiscal lê `ncm.ipi`, `ncm.pis`, `ncm.cofins` e `ncm_estados.icms_local/icms_interestadual` pela UF do cliente.
- **IPI:** somado ao total dos produtos para compor o Total da Nota Fiscal.
- **PIS / COFINS / ICMS:** exibidos na tabela fiscal (embutidos no preço; não somam à NF).
- **Produto sem NCM:** alíquotas exibidas como zero no Detalhamento Fiscal — sem erro.
- **Estado sem registro em `ncm_estados`:** ICMS exibido como zero — sem erro.
- **ICMS local vs. interestadual:** determinado comparando a UF do cliente com a UF da empresa (configuração `EMPRESA_UF`); se iguais, usa `icms_local`; caso contrário, `icms_interestadual`.

---

## Critérios de Aceite

- [ ] Dado um NCM sem `nome_categoria`, quando salvo, então aparece na listagem usando o código NCM como fallback no título do accordion — não bloqueia.
- [ ] Dado um código NCM já existente entre os NCMs ativos, quando o usuário tenta criar outro com o mesmo código, então o sistema rejeita com mensagem de código duplicado.
- [ ] Dado um valor `ipi = 1.5000`, quando exibido na listagem accordion, então aparece formatado como `1,5%` (sem zeros à direita desnecessários).
- [ ] Dado um NCM com UF `SP` com `icms_local = 12` e `icms_interestadual = 7`, quando o Detalhamento Fiscal de um pedido de cliente com `estado = SP` é aberto, então exibe ICMS = 12%.
- [ ] Dado um NCM com UF `SP` com `icms_local = 12` e `icms_interestadual = 7`, quando o Detalhamento Fiscal de um pedido de cliente com `estado = RJ` é aberto, então exibe ICMS = 7%.
- [ ] Dado um NCM sem nenhum estado cadastrado, quando o Detalhamento Fiscal é aberto, então ICMS = 0% — sem mensagem de erro.
- [ ] Dado um NCM com estados cadastrados, quando editado e salvo, então os estados anteriores são substituídos pelos novos (estados deixados em branco no formulário não persistem).
- [ ] Dado um NCM vinculado a um produto ativo, quando o usuário tenta excluir, então o sistema bloqueia com mensagem de erro — o soft delete não é efetuado.
- [ ] Dado um NCM vinculado apenas a produtos inativos (ou sem vínculos), quando o usuário confirma a exclusão, então o NCM recebe `status = inativo` e some da listagem; `ncm_estados` permanece intacto.

---

## Dependências e Impactos Cruzados

- **Produtos** (`produtos.md`): `ncm_id` é FK de `produtos` → exclusão de NCM referenciado pode deixar produtos sem NCM (ver [Pendências](#pendencias)).
- **Detalhamento Fiscal** (`../06-pedidos.md`): lê `ncm.ipi/pis/cofins` e `ncm_estados.icms_local/icms_interestadual` — mudança de alíquota afeta apenas novos cálculos; pedidos já gravados usam os valores registrados no momento.
- **Importação de Produtos**: ao importar produtos via Excel, se o NCM informado não existir, é criado um registro mínimo (só com o código NCM); esses registros mínimos precisarão ter os demais campos preenchidos manualmente.

---

## Índice de Decisões já tomadas

- **Campos NCM:** código NCM obrigatório e único; nome_categoria opcional (fallback = código); CEST, II, IPI, PIS, COFINS opcionais; percentuais com até 4 casas decimais, nullable; campo `status` ativo/inativo — → [Ir para Regra](#campos)
- **Estados:** lista fixa de 27 UFs (sigla 2 chars); campos extras mantidos; substituição completa a cada edição; estado vazio não gera registro; sem cadastro = alíquota 0 — → [Ir para Regra](#estados)
- **Interface:** accordion por NCM; tabela de estados inline; modal XL para criar/editar — → [Ir para Regra](#interface)
- **Exclusão:** soft delete (`status = inativo`); bloqueado se existir produto ativo vinculado; `ncm_estados` preservados — → [Ir para Regra](#excluir)
- **Uso fiscal:** IPI soma à NF; PIS/COFINS/ICMS embutidos; ICMS local vs. interestadual por UF do cliente vs. EMPRESA_UF — → [Ir para Regra](#uso-fiscal)

---

## Pendências para decidir <a name="pendencias"></a>

Nenhuma pendência aberta — todas as decisões deste documento foram fechadas na v2.

---

## Changelog

| Versão | Data | O que mudou |
|---|---|---|
| 1 | 2026-08-04 | Criação inicial — spec do CRUD de NCM e `ncm_estados`, derivado do código do protótipo (`admin/cadastros/ncm.php`) e dos docs `../06-pedidos.md`, `../12-banco-de-dados.md`. 5 pendências identificadas. |
| 2 | 2026-08-04 | Pendências fechadas: código NCM único entre ativos; nome_categoria opcional (fallback = código); estado como sigla UF 2 chars; campos extras mantidos; exclusão alterada para soft delete bloqueado por produto ativo. Status → 🟢 Estável. |
