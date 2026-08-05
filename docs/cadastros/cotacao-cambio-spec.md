# Cotação de Câmbio (AwesomeAPI) — Especificação

> Documento de especificação funcional. Define **como deve se comportar**,
> não como foi implementado. Atualizar sempre que uma decisão for tomada.
> Relacionado: `tabela-precos-spec.md` (Câmbio de Segurança, seção que
> **permanece** naquele documento), `../11-multimoeda-e-i18n.md`,
> `../13-integracoes.md`, `../06-pedidos.md`.
> **Escopo:** consulta, cache e uso da cotação comercial diária USD/EUR em
> BRL (AwesomeAPI) — `buscarCotacaoAPI()`, `cotacaoDia()` e
> `cotacaoExibicaoPedido()`. **Não cobre** o Câmbio de Segurança
> (`dolar_seguranca`/`euro_seguranca`, colunas `preco_dolar`/`preco_euro`
> de `tabela_precos`), que é uma regra independente documentada em
> `tabela-precos-spec.md#cambio`.

**Versão:** 1 · **Última atualização:** 2026-08-04 · **Status:** 🟢 Estável

---

## Fonte e Consulta à API <a name="fonte"></a>

### Regra definida
> `buscarCotacaoAPI()` consulta USD-BRL e EUR-BRL na AwesomeAPI via cURL,
> com fallback e timeout curtos; falha parcial é tratada como falha total.

- **Endpoint:** `GET https://economia.awesomeapi.com.br/json/last/USD-BRL,EUR-BRL` — usa o campo `bid` (cotação comercial de compra) de `USDBRL` e `EURBRL`.
- **Transporte:** cURL quando disponível (`CURLOPT_RETURNTRANSFER=true`, `CURLOPT_TIMEOUT=10` segundos, `CURLOPT_SSL_VERIFYPEER=false`); se `curl_init` não existir, cai para `file_get_contents($url)` com `@` (silencia warning).
- **Falha de transporte:** se a resposta bruta vier `false`/vazia, ou o JSON não decodificar, `buscarCotacaoAPI()` retorna `null`.
- **Falha parcial = falha total:** se `USDBRL.bid` **ou** `EURBRL.bid` vier vazio, a função retorna `null` para os dois — não há atualização parcial (só dólar ou só euro).
- **Retorno em sucesso:** `['usd' => float, 'eur' => float, 'data' => create_date da API ou null]`.
- **Impacto em Legados:** não aplicável — sem histórico de chamadas anteriores.

---

## Cache Diário <a name="cache"></a>

### Regra definida
> A cotação é cacheada em `configuracoes` e reaproveitada durante o mesmo
> dia; no máximo 1 chamada externa à API por dia pela via automática.

- **Chaves em `configuracoes`:** `cotacao_usd`, `cotacao_eur` (últimos valores válidos), `cotacao_data` (data `Y-m-d` do cache) e `cotacao_atualizado` (timestamp/`create_date` retornado pela API).
- **`cotacaoDia($moeda)`:** usa cache estático em memória (1x por request); se `cotacao_data` do cache já for **hoje**, reaproveita `cotacao_usd`/`cotacao_eur` sem chamar a API. Só chama `buscarCotacaoAPI()` quando a data salva não é hoje **ou** os valores em cache são `≤ 0`.
- **Moeda BRL ou desconhecida:** `cotacaoDia()` retorna `null` imediatamente, sem tocar no cache nem chamar a API.
- **Impacto em Legados:** não aplicável — cache substituído a cada consulta bem-sucedida.

---

## Atualização Manual <a name="manual"></a>

### Regra definida
> O botão "Buscar cotação" da tela de Tabela de Preços força uma nova
> consulta à API, ignorando a data do cache.

- **Endpoint:** `GET tabela-precos.php?cotacao=1` (AJAX, header `X-Requested-With: XMLHttpRequest`), chama `buscarCotacaoAPI()` diretamente — **não** passa por `cotacaoDia()`, então ignora o cache do dia.
- **Sucesso:** grava `cotacao_usd`, `cotacao_eur`, `cotacao_data` (hoje) e `cotacao_atualizado` (`create_date` da API, ou timestamp atual se ausente); retorna JSON `{ok:true, usd, eur, data}`.
- **Feedback visual:** botão mostra spinner + "Buscando..." durante a chamada (desabilitado); em sucesso preenche os campos de cotação (4 casas decimais, vírgula) e exibe "Cotação comercial (compra) — atualizada em {data}. Fonte: AwesomeAPI."
- **Impacto em Legados:** não aplicável.

---

## Falha e Fallback <a name="falha"></a>

### Regra definida
> Qualquer falha da API preserva o último valor cacheado; nunca há erro
> fatal nem `null` propagado para o cálculo de pedidos quando existe cache
> anterior válido.

- **Botão manual:** em falha, endpoint retorna `{ok:false}`; front exibe `alert("Não foi possível buscar a cotação agora. Tente novamente em instantes.")`; o cache anterior em `configuracoes` permanece intacto (não é sobrescrito).
- **Via automática (`cotacaoDia`):** se a API falhar (retorno `null`) e os valores em cache também estiverem `≤ 0`, a função tenta reaproveitar o último `cotacao_usd`/`cotacao_eur` salvo em `configuracoes`, **mesmo que de um dia anterior**, antes de desistir.
- **Sem valor disponível:** se não houver cache anterior e a API falhar, `cotacaoDia()` retorna `null` para aquela moeda naquele request.
- **Impacto em Legados:** não aplicável.

---

## Uso na Criação de Pedidos <a name="uso-pedidos"></a>

### Regra definida
> `pedidos.cotacao` é congelado no momento da criação do pedido a partir
> de `cotacaoDia()`; pedidos de bonificação nunca gravam cotação.

- **Chamada:** `admin/novo-pedido.php` e `cliente/novo-pedido.php` chamam `cotacaoDia($cli['moeda'] ?? 'BRL')` na criação, exceto quando `tipoVenda === 'bonificacao'` — nesse caso `cotacaoPedido` é forçado a `null` sem chamar a função.
- **Congelamento:** o valor retornado é gravado em `pedidos.cotacao` no `INSERT`; alterações posteriores na cotação (automática ou manual) **não** afetam pedidos já criados.
- **BRL:** `cotacaoDia('BRL')` sempre retorna `null` (moeda não é USD/EUR); `pedidos.cotacao` fica `null`/vazio para pedidos em BRL.
- **Impacto em Legados:** pedidos criados em dias com falha total da API (sem cache anterior) ficam com `cotacao` vazia — tratado na exibição, ver regra de Exibição/Conversão.

---

## Exibição e Conversão em Telas <a name="exibicao"></a>

### Regra definida
> A exibição de valores convertidos usa a cotação gravada no pedido; só
> recorre à cotação atual como fallback visual quando o pedido não tem
> cotação própria.

- **`cotacaoExibicaoPedido($moeda, $cotacaoPedido)`:** se `$cotacaoPedido > 0`, retorna `['taxa' => $cotacaoPedido, 'fallback' => false]` (usa o valor congelado do pedido). Se `$cotacaoPedido` estiver vazio/zerado, cai para `cotacaoDia($moeda)` atual e marca `fallback => true`. Para moeda `BRL`, retorna `null` sempre.
- **Sem taxa disponível:** se nem o pedido nem `cotacaoDia()` tiverem valor (`fallback` também `≤ 0`), retorna `null` e a tela não exibe conversão.
- **Telas que consomem:** `admin/dashboard.php`, `admin/pedidos.php` (listagem), `admin/pedido.php` (detalhe — topo e rodapé), `admin/relatorios/status-pedidos.php`.
- **Impacto em Legados:** pedidos antigos sem `cotacao` gravada (criados antes desta coluna existir, ou em dia de falha total da API) exibem a conversão com `fallback => true`, usando a cotação **atual** em vez da cotação do dia da venda — a tela pode sinalizar esse caso como aproximado.

---

## Distinção do Câmbio de Segurança <a name="distincao"></a>

### Regra definida
> Cotação do dia e Câmbio de Segurança são dois valores independentes que
> o operador pode manter propositalmente diferentes.

- **Cotação do dia** (este documento): converte `valor_total` de pedidos em USD/EUR para exibição/relatórios, no momento da venda.
- **Câmbio de segurança** (`dolar_seguranca`/`euro_seguranca`, calcula `preco_dolar`/`preco_euro` em `tabela_precos`): regra própria, **detalhada e mantida em** `tabela-precos-spec.md#cambio` — não duplicada aqui.
- **Por que separados:** o câmbio de segurança costuma ser mais conservador que a cotação real do dia, funcionando como margem de proteção nos preços praticados.
- **Impacto em Legados:** não aplicável — ver `tabela-precos-spec.md#cambio` para o histórico dessa regra.

---

## Critérios de Aceite

- [ ] Dado `USDBRL.bid` e `EURBRL.bid` presentes na resposta da API, quando `buscarCotacaoAPI()` é chamada, então retorna `usd`, `eur` e `data` preenchidos.
- [ ] Dado `EURBRL.bid` vazio na resposta da API (mesmo com `USDBRL.bid` presente), quando `buscarCotacaoAPI()` é chamada, então retorna `null` (falha total, nenhum valor parcial é usado).
- [ ] Dado `cotacao_data = hoje` em `configuracoes` com `cotacao_usd`/`cotacao_eur > 0`, quando `cotacaoDia('USD')` é chamada, então retorna o valor cacheado sem chamar a API.
- [ ] Dado `cotacao_data` diferente de hoje, quando `cotacaoDia('USD')` é chamada, então a API é consultada novamente e o cache é atualizado.
- [ ] Dado o botão "Buscar cotação" acionado, quando a API responde com sucesso, então `configuracoes.cotacao_data` é atualizada para hoje independente do valor anterior.
- [ ] Dado o botão "Buscar cotação" acionado, quando a API falha, então o JSON retorna `{ok:false}`, o front exibe alerta de erro e `configuracoes` não é alterada.
- [ ] Dado um pedido do tipo bonificação sendo criado, quando gravado, então `pedidos.cotacao` é `null`, independente do resultado de `cotacaoDia()`.
- [ ] Dado um cliente com moeda USD criando um pedido normal (não bonificação), quando o pedido é gravado, então `pedidos.cotacao` recebe o valor de `cotacaoDia('USD')` no momento da criação.
- [ ] Dado um pedido já criado com `cotacao` gravada, quando o câmbio do dia muda posteriormente, então a exibição desse pedido continua usando a `cotacao` congelada (`fallback => false`).
- [ ] Dado um pedido antigo sem `cotacao` gravada, quando exibido em `admin/pedido.php`, então `cotacaoExibicaoPedido()` usa a cotação atual como fallback (`fallback => true`).
- [ ] Dado nenhum cache anterior em `configuracoes` e falha total da API, quando `cotacaoDia('EUR')` é chamada, então retorna `null`.

---

## Dependências e Impactos Cruzados

- **Tabela de Preços** (`tabela-precos-spec.md`): hospeda a UI do botão "Buscar cotação" (`admin/cadastros/tabela-precos.php?cotacao=1`); Câmbio de Segurança é regra separada e permanece naquele documento.
- **Pedidos** (`../06-pedidos.md`): `admin/novo-pedido.php` e `cliente/novo-pedido.php` chamam `cotacaoDia()` na criação; `pedidos.cotacao` é a coluna congelada consumida por este spec.
- **Multimoeda e i18n** (`../11-multimoeda-e-i18n.md`): conversão de totais em BRL (`valor_total * cotacao`) usada em dashboards/relatórios depende de `pedidos.cotacao`, documentado aqui.
- **Configurações** (`configuracoes`): `cotacao_usd`, `cotacao_eur`, `cotacao_data`, `cotacao_atualizado` são chaves globais compartilhadas via `getConfig()`/`setConfig()` — as mesmas chaves usadas pelo Câmbio de Segurança são **distintas** (`dolar_seguranca`/`euro_seguranca`), sem sobreposição.
- **Integrações** (`../13-integracoes.md`): referência geral à AwesomeAPI como integração externa do sistema.

---

## Índice de Decisões já tomadas

- **Fonte e Consulta:** AwesomeAPI, `bid` comercial, timeout 10s, fallback `file_get_contents`, falha parcial = falha total — → [Ir para Regra](#fonte)
- **Cache Diário:** chaves em `configuracoes`, no máximo 1 chamada automática por dia, BRL sempre `null` — → [Ir para Regra](#cache)
- **Atualização Manual:** botão força nova consulta ignorando cache, feedback visual com spinner — → [Ir para Regra](#manual)
- **Falha e Fallback:** cache anterior preservado em qualquer falha, `cotacaoDia()` reaproveita último valor mesmo de dia anterior — → [Ir para Regra](#falha)
- **Uso em Pedidos:** `cotacaoDia()` na criação, congelado em `pedidos.cotacao`, bonificação sempre `null` — → [Ir para Regra](#uso-pedidos)
- **Exibição e Conversão:** prioriza cotação congelada do pedido; fallback à cotação atual quando ausente, com flag `fallback` — → [Ir para Regra](#exibicao)
- **Distinção do Câmbio de Segurança:** valores independentes por design; câmbio de segurança documentado em `tabela-precos-spec.md#cambio` — → [Ir para Regra](#distincao)

---

## Pendências para decidir

Nenhuma pendência aberta — documento extraído de comportamento já implementado e decidido (ver Changelog).

---

## Changelog

| Versão | Data | O que mudou |
|---|---|---|
| 1 | 2026-08-04 | Criação inicial — spec extraído da seção "Cotação do Dia" de `tabela-precos-spec.md` (v2), detalhado a partir do código de `config.php` (`buscarCotacaoAPI()`, `cotacaoDia()`, `cotacaoExibicaoPedido()`) e `admin/cadastros/tabela-precos.php`. Câmbio de Segurança permanece em `tabela-precos-spec.md#cambio`. |
