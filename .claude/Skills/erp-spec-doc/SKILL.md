---
name: erp-spec-doc
description: >
  Gera e atualiza documentos de especificação funcional (spec.md) para módulos
  do ERP Laravel, no padrão usado em autenticacao.md / controle-acesso.md.
  Use esta skill sempre que o usuário pedir para "documentar as regras de",
  "criar a especificação de", "documentar as decisões sobre", "gerar o spec
  do módulo X", ou quando estiver no meio de uma decisão de produto/arquitetura
  e quiser registrar o que já foi decidido e o que ainda está em aberto.
  Também usar para revisar/melhorar um spec.md existente, aplicando o padrão
  de qualidade desta skill (decisões centralizadas, pendências rastreáveis,
  critérios de aceite testáveis).
---

# ERP Spec Doc — Skill

## O que é um documento de Spec

Um spec.md é um documento **vivo** de especificação funcional. Define
**como o sistema deve se comportar**, não como foi implementado. Diferente
de uma TASK (que é uma instrução de execução pontual), o spec.md é a
**fonte da verdade** de regras de negócio de um módulo — sobrevive a várias
tasks e é consultado antes de cada nova decisão.

Dois tipos de conteúdo coexistem no mesmo documento, e a skill nunca deve
misturá-los dentro do texto corrido:

- **Decisão tomada** — regra definida, com justificativa, pronta pra implementar
- **Pendência** — pergunta em aberto, sem decisão ainda

---

## Onde salvar


```

Modules/{NomeModulo}/docs/{nome-do-spec}.md

```

Exemplos:

```

Modules/Core/docs/autenticacao.md
Modules/Core/docs/controle-acesso.md
Modules/Financeiro/docs/contas-pagar.md

```

Specs relacionados devem se referenciar entre si no cabeçalho (ver Passo 2).

---

## Passo 1 — Modo de geração

Perguntar ao usuário (ou inferir do contexto da conversa) qual modo usar:

### Modo A — Esqueleto
Gera o documento com a estrutura completa (Passo 2), seções de regras
**vazias com `[ ]` indicando o que falta decidir**, e nenhuma decisão
inventada. Útil quando o usuário ainda está planejando.

### Modo B — Interativo
Faz perguntas objetivas e já preenche a seção "Decisões já tomadas" com as respostas, deixando em "Pendências" só o que o usuário explicitamente disser "não sei ainda" ou pular.
* **⚠️ Regra de Foco Único:** A skill está estritamente proibida de listar múltiplas perguntas no mesmo turno. Faça **apenas uma pergunta por vez**, espere a resposta do usuário e só então passe para o próximo ponto.

**Nunca decidir por conta própria.** Se o usuário não respondeu, a regra vai para Pendências — nunca para Decisões.

---

## Passo 2 — Estrutura obrigatória

```markdown
# {Nome do Módulo/Tema} — Especificação

> Documento de especificação funcional. Define **como deve se comportar**,
> não como foi implementado. Atualizar sempre que uma decisão for tomada.
> Relacionado: `{outro-spec.md}`

**Versão:** {N} · **Última atualização:** {data} · **Status:** 🟡 Em definição / 🟢 Estável

---

## {Seção temática 1}
## {Seção temática 2}
## {Seção temática N}

{Cada seção temática segue o formato detalhado abaixo}

---

## Critérios de Aceite

{Lista testável, formato Given/When/Then ou checklist direto, derivada
das decisões já tomadas. Serve de ponte para tasks e testes Pest.}

- [ ] Dado {contexto}, quando {ação}, então {resultado esperado}

---

## Dependências e Impactos Cruzados

{Quais outros módulos/specs são afetados por mudanças aqui, e vice-versa}

- Alterar `{regra X}` impacta `{outro-spec.md}` porque {motivo}

---

## Índice de Decisões já tomadas

{Resumo executivo cronológico/plano de TODAS as decisões do documento. Para evitar dessincronização, use uma linha direta com link/âncora para a seção temática onde a regra está detalhada.}

- **{Tema}:** {Breve resumo da decisão} — *Aprovado por: {Alvo/Responsável ou 'Dev+Contexto'}* -> [Ir para Regra](#ancora-da-secao)

---

## Pendências para decidir

{Lista plana de TODAS as perguntas em aberto do documento — inclusive
as que aparecem como `[ ]` dentro das seções temáticas.}

- [ ] {pergunta objetiva, respondível com sim/não/valor}

---

## Changelog

| Versão | Data | O que mudou |
|---|---|---|
| 1 | {data} | Criação inicial |

```

### Formato de cada seção temática (Passo 2 detalhado)

```markdown
## {Tema, ex: Rate Limiting — Tentativas de Login} <a name="ancora-da-secao"></a>

### Regra definida
> {regra em destaque, uma frase}

- **{aspecto 1}:** {valor exato} — {justificativa curta, o "porquê"}
- **{aspecto 2}:** {valor exato} — {justificativa curta}
- **Impacto em Legados:** {Como essa regra afeta os registros antigos/banco de dados atual}
- [ ] {pergunta em aberto relacionada a este tema, se houver}

```

---

## Passo 3 — Regras de qualidade (aplicar sempre, nos dois modos)

### 1. Toda decisão tem justificativa

❌ "Bloqueio por email + IP"
✅ "Bloqueio por email + IP — evita impactar outros usuários na mesma rede (só IP) e evita que um atacante troque de IP livremente (só email)"

### 2. Valores exatos, nunca vagos

❌ "expira rápido" / "algumas tentativas"
✅ "expira em 5 minutos" / "3 tentativas"

### 3. Nenhuma pendência solta fora da seção "Pendências para decidir"

Toda vez que um `[ ]` aparecer dentro de uma seção temática, ele também precisa estar replicado na seção centralizada no fim do documento.

### 4. Nenhuma decisão solta fora do "Índice de Decisões"

Se uma regra foi definida em uma seção temática, ela deve ser catalogada no Índice no final do documento para auditoria rápida.

### 5. Critérios de Aceite são testáveis, não descritivos

❌ "o sistema deve bloquear logins inválidos"
✅ "Dado 3 tentativas erradas de email+senha na mesma combinação email+IP em 1 minuto, quando a 4ª tentativa ocorre, então o sistema retorna mensagem de bloqueio e nega acesso por 30 minutos"

### 6. Dependências cruzadas são explicitas

Se a decisão de um spec afeta outro documento, isso vai na seção "Dependências e Impactos Cruzados".

### 7. Versionamento obrigatório

Todo documento tem versão, data e changelog. Alterações geram nova linha no changelog, nunca edições silenciosas.

### 8. Estados de erro/loading fazem parte da spec

Cobrir caminhos de falha, feedbacks visuais de carregamento e timeouts, não apenas o fluxo de sucesso ("happy path").

### 9. Impacto em Dados Legados (Retrocompatibilidade)

Dado que é um ERP, toda alteração de regra deve explicitar o comportamento com dados antigos (ex: *"Aplica-se apenas para novas parcelas; parcelas retroativas mantêm o cálculo da v1"*).

### 10. Foco Único no Modo Interativo

No Modo B, a skill está estritamente proibida de listar múltiplas perguntas no mesmo turno. Faça uma pergunta objetiva, espere o usuário responder, registre e passe para a próxima.

---

## Passo 4 — Checklist antes de entregar o spec

* [ ] Cabeçalho com versão, data e status (🟡 Em definição / 🟢 Estável)
* [ ] Toda decisão tem justificativa (o "porquê")
* [ ] Todo valor é exato (números, tempos, formatos)
* [ ] Toda pendência das seções temáticas está replicada em "Pendências para decidir"
* [ ] Toda decisão está catalogada no Índice de Decisões
* [ ] Impacto em dados legados/retrocompatibilidade preenchido em cada tema
* [ ] Critérios de Aceite testáveis (Given/When/Then)
* [ ] Seção de Dependências e Impactos Cruzados preenchida
* [ ] Changelog atualizado com a versão corrente
* [ ] Caminho de destino informado: `Modules/{Modulo}/docs/{nome}.md`
* [ ] Se Modo Interativo: respeitou o limite de uma pergunta por vez, sem inventar respostas.

---

## Passo 5 — Revisando um spec.md existente

Quando o usuário pedir para melhorar um documento já escrito:

1. Ler o documento completo.
2. Mapear decisões e pendências "soltas" e propor mover para as seções centralizadas.
3. Verificar falta de justificativas ou impactos em legados e perguntar ao usuário.
4. Verificar se existem critérios de aceite testáveis; se não, propor.
5. Adicionar cabeçalho de versionamento/changelog se não existir.
6. Apresentar o resumo/diff das mudanças antes de aplicar.

---

## Exemplo de saída esperada

Pedido: "documenta as regras de cadastro de Cliente no módulo Cadastro, ainda não decidi tudo"

A skill deve:

1. Identificar Modo A (esqueleto) ou iniciar o Modo B (uma pergunta isolada).
2. Gerar `Modules/Cadastro/docs/cadastro-cliente.md` estruturado.
3. Informar o caminho final ao usuário e sugerir os comandos Git apropriados:
```bash
git add Modules/Cadastro/docs/cadastro-cliente.md
git commit -m "docs(cadastro): spec inicial de cliente (em definição)"

```
