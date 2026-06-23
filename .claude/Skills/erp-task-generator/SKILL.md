---
name: erp-task-generator
description: >
  Gera arquivos TASK-NNNNN-nome.md para o ERP Laravel 8 seguindo o padrão do
  projeto com nwidart/laravel-modules. Use esta skill sempre que o usuário
  pedir para criar uma task, plano de execução, instrução para o Claude Code,
  ou quando mencionar "gera a task de", "cria a task para", "preciso da task
  do", "task para o Claude Code". Também usar quando pedir para documentar o
  que vai ser feito antes de executar.
---

# ERP Task Generator — Laravel 8 + Modules

## O que é uma Task

Uma Task é um arquivo Markdown autocontido que descreve **o que fazer e como
fazer** para o Claude Code executar sem precisar perguntar nada.

Cada task é também um **registro histórico** — após executada, permanece como
documentação do que foi construído e por quê.

---

## Nomenclatura obrigatória

```
TASK-NNNNN-descricao-kebab-case.md
```

- `NNNNN` = número sequencial com 5 dígitos, zero-padded: `00001`, `00002`...
- Exemplos:
  - `TASK-00001-crud-cliente.md`
  - `TASK-00002-livewire-relatorio-contas.md`
  - `TASK-00003-job-envio-email.md`

---

## Onde salvar

### Tasks de módulo específico
```
src/Modules/{NomeModulo}/docs/tasks/TASK-NNNNN-descricao.md
```
A sequência numérica é **por módulo** — cada módulo começa do 00001.

```
Modules/Cadastro/docs/tasks/
├── TASK-00001-crud-cliente.md
├── TASK-00002-crud-fornecedor.md

Modules/Financeiro/docs/tasks/
├── TASK-00001-crud-conta-pagar.md
```

### Tasks globais (infraestrutura, Core, arquitetura)
```
src/docs/tasks/TASK-NNNNN-descricao.md
```
Sequência global independente dos módulos.

---

## Passo 1 — Entender o que gerar

Antes de escrever a task, extrair da conversa:

1. **Qual módulo** — ex: Cadastro, Financeiro, Core, global
2. **O que fazer** — CRUD, Livewire component, job, migration, etc.
3. **Entidade ou funcionalidade** — ex: Cliente, ContaPagar, EnvioEmail
4. **O que já existe** — ler `Modules/NomeModulo/Models/` para não recriar
5. **Dependências** — o que precisa existir antes de executar
6. **Próximo número** — verificar `Modules/NomeModulo/docs/tasks/` ou perguntar
7. **ADR-001** — entidade compartilhada? → módulo Cadastro

---

## Passo 2 — Estrutura obrigatória da Task

```markdown
# Task: {Título descritivo}

**Arquivo:** `TASK-NNNNN-descricao.md`
**Módulo:** {NomeModulo} ou Global
**Criado em:** {data}
**Status:** 🔴 Pendente

> Status: 🔴 Pendente · 🟡 Em execução · 🟢 Concluída · ⚫ Cancelada

---

## Contexto

{O que motivou essa task. Qual problema resolve. Referência ao ADR se aplicável.}

## O que já existe — NÃO recriar

- `Modules/NomeModulo/Models/NomeModel.php` ✅
- `Modules/NomeModulo/Database/Migrations/xxxx_create_...php` ✅

## Dependências

- [ ] Módulo NomeModulo criado via `php artisan module:make NomeModulo`
- [ ] TASK-NNNNN-outra.md concluída
- [ ] Migration X rodada

## Arquivos a criar / modificar

src/Modules/NomeModulo/
├── Database/Migrations/xxxx_create_nome_table.php     ← criar
├── Models/NomeModel.php                               ← criar
├── Policies/NomeModelPolicy.php                       ← criar
├── Repositories/NomeModelRepository.php               ← criar
├── Services/NomeModelService.php                      ← criar
├── Http/Controllers/NomeModelController.php           ← criar
├── Http/Livewire/NomeModel/NomeModelIndex.php         ← criar
├── Http/Requests/StoreNomeModelRequest.php            ← criar
├── Http/Requests/UpdateNomeModelRequest.php           ← criar
├── Resources/views/livewire/nome-model/index.blade.php ← criar
├── Routes/web.php                                     ← modificar
└── Providers/NomeModuloServiceProvider.php            ← modificar

## Especificação detalhada

{Conteúdo completo de cada arquivo. O Claude Code executa sem perguntar nada.}

### 1. Migration

{código completo — classe nomeada, prefixo de módulo na tabela}

### 2. Model

{código completo}

### N. ...

## Ordem de execução

1. Verificar se o módulo existe: `php artisan module:list`
2. Criar migration + rodar: `php artisan module:migrate NomeModulo`
3. Criar Model + Factory
4. Criar Policy + registrar no ServiceProvider
5. Criar Repository
6. Criar Service
7. Criar FormRequests (Store + Update)
8. Criar Controller
9. Criar Livewire components + registrar no ServiceProvider
10. Criar views Blade
11. Adicionar rotas em `Modules/NomeModulo/Routes/web.php`
12. Criar testes PHPUnit
13. Rodar: `docker compose exec app php artisan test --filter=NomeTest`

## Verificação final

- [ ] `docker compose exec app php artisan module:migrate NomeModulo` sem erros
- [ ] `docker compose exec app php artisan test --filter=NomeEntidadeTest` passando
- [ ] GET /nome-modulo/nome-entidades retorna 200 (autenticado)
- [ ] GET /nome-modulo/nome-entidades redireciona para /login (não autenticado)
- [ ] POST com dados válidos persiste na tabela `nome_modulo_nome_entidades` (ex: `cadastro_clientes`)
- [ ] POST com campo obrigatório ausente retorna erro de validação
- [ ] DELETE registra `deleted_at` (soft delete — não apaga o registro)
```

---

## Passo 3 — Regras de qualidade da Task

### Autocontida
O Claude Code não deve precisar perguntar nada para executar.

### Valores exatos
❌ "criar os campos necessários"
✅ "campos: `nome` (string 100, obrigatório), `cpf_cnpj` (string 18, único na tabela)"

### Restrições de stack obrigatórias em todo código da task
- PHP 7.4: sem constructor promotion, sem named args, sem `str_contains`, sem enums
- Migration: classe nomeada (não `return new class`)
- Frontend: Blade + Livewire 2 + Alpine.js — sem Vue, Inertia, Vite
- Testes: PHPUnit com `RefreshDatabase` — não Pest
- Policy: Gate nativo — não Spatie Permission

### Verificação objetiva
❌ "verificar se funciona"
✅ "GET /cadastro/clientes retorna 200 com lista paginada"
✅ "POST com CPF duplicado retorna 422 com erro no campo cpf_cnpj"

---

## Passo 4 — Templates por tipo de task

### CRUD completo
Usar a skill `erp-crud-laravel` para gerar o conteúdo detalhado.
Salvar em `Modules/NomeModulo/docs/tasks/TASK-NNNNN-crud-nome-entidade.md`.

### Novo módulo (module:make)
Incluir: comando de criação, configuração do ServiceProvider, registro em `config/modules.php`, primeira migration.

### Livewire Component isolado (sem CRUD completo)
Estrutura: classe Livewire, propriedades públicas, métodos, view blade.

### Job
Estrutura: classe Job, quando dispara, timeout, o que faz, como testar.
Driver atual: `sync` em dev. Não assumir Redis/Horizon instalados.

### Migration nova (tabela existente)
Estrutura: campos a adicionar, índices, rollback. Lembrar: imutável após rodar.

---

## Passo 5 — Checklist antes de entregar

- [ ] Título claro e descritivo
- [ ] Número sequencial correto para o módulo/escopo
- [ ] Status 🔴 Pendente
- [ ] Módulo identificado + caminho de destino informado
- [ ] Seção "O que já existe" preenchida
- [ ] Dependências listadas (incluindo `module:make` se módulo novo)
- [ ] Árvore de arquivos completa
- [ ] Especificação detalhada para executar sem perguntas
- [ ] PHP 7.4 em todos os exemplos de código
- [ ] Ordem de execução correta
- [ ] Verificação com itens objetivos e testáveis

---

## Exemplo de saída

Quando o usuário pedir "cria a task para o CRUD de Cliente no módulo Cadastro":

1. Verificar se existe `Modules/Cadastro/docs/tasks/` e qual o próximo número
2. Confirmar campos da entidade (ou perguntar se não informados)
3. Gerar arquivo completo
4. Informar: salvar em `src/Modules/Cadastro/docs/tasks/TASK-00001-crud-cliente.md`
5. Sugerir:
   ```bash
   git add src/Modules/Cadastro/docs/tasks/
   git commit -m "docs(cadastro): task CRUD cliente"
   ```
