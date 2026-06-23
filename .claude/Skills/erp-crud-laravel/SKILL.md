---
name: erp-crud-laravel
description: >
  Gera CRUDs completos para o ERP Laravel 8 com nwidart/laravel-modules:
  estrutura Modules/NomeModulo/, Livewire 2 para listagem e formulário,
  Blade + Alpine.js + Tailwind CSS, Policy nativa do Laravel, FormRequest,
  Repository, Service, soft delete e PHPUnit.
  Use esta skill sempre que o usuário pedir para criar um CRUD, cadastro, tela
  de listagem, formulário, módulo novo ou qualquer funcionalidade que envolva
  criar, listar, editar ou excluir registros. Também usar quando mencionar
  entidades como Cliente, Fornecedor, Produto, Pedido, Conta, etc.
---

# ERP CRUD Laravel 8 + Modules — Skill

## Contexto do Projeto

Stack obrigatória — ler CLAUDE.md para detalhes completos:
- **Backend**: Laravel 8, PHP 7.4, MariaDB 10.11
- **Módulos**: nwidart/laravel-modules v8 — todo CRUD fica em `Modules/NomeModulo/`
- **Frontend**: Blade + Livewire 2 + Alpine.js 3 + Tailwind CSS 3
- **Build**: Laravel Mix 6 (não Vite)
- **Acesso**: Policy nativa do Laravel — Spatie Permission NÃO instalado
- **Testes**: PHPUnit 9 — não usar Pest
- **Sintaxe**: PHP 7.4 — ver tabela de restrições no CLAUDE.md

## ADR-001 obrigatório

Antes de gerar, verificar:
- Entidade existe de forma independente? → vai para `Modules/Cadastro/`
- Entidade só existe dentro de um módulo? → vai para o módulo dono
- Exemplos: Cliente/Produto/Fornecedor → `Cadastro`; ItemPedido/Parcela → módulo dono

---

## Passo 1 — Entender o que gerar

Extrair da conversa ou perguntar ao usuário:

1. **Nome do módulo** — ex: `Cadastro`, `Financeiro`, `Vendas`
2. **Nome da entidade** — ex: `Cliente`, `ContaPagar`, `Pedido`
3. **Campos da entidade** — nome, tipo MariaDB, obrigatório, único, FK
4. **Regras de negócio** — limites, dependências, o que não pode
5. **Ações disponíveis** — visualizar / criar / editar / excluir / extras
6. **Soft delete** — padrão sim, confirmar se não
7. **Relacionamentos** — pertence a qual entidade? (checar ADR-001)
8. **Filtros na listagem** — quais campos são pesquisáveis?

---

## Passo 2 — Estrutura de arquivos a gerar

Para `NomeEntidade` no `NomeModulo`:

```
src/
└── Modules/NomeModulo/
    ├── Database/
    │   ├── Migrations/
    │   │   └── xxxx_create_nome_entidades_table.php
    │   └── Factories/
    │       └── NomeEntidadeFactory.php
    ├── Models/
    │   └── NomeEntidade.php
    ├── Policies/
    │   └── NomeEntidadePolicy.php
    ├── Repositories/
    │   └── NomeEntidadeRepository.php
    ├── Services/
    │   └── NomeEntidadeService.php
    ├── Http/
    │   ├── Controllers/
    │   │   └── NomeEntidadeController.php
    │   ├── Livewire/
    │   │   └── NomeEntidade/
    │   │       ├── NomeEntidadeIndex.php
    │   │       └── NomeEntidadeForm.php
    │   └── Requests/
    │       ├── StoreNomeEntidadeRequest.php
    │       └── UpdateNomeEntidadeRequest.php
    ├── Resources/
    │   └── views/
    │       └── livewire/
    │           └── nome-entidade/
    │               ├── index.blade.php
    │               └── form.blade.php
    ├── Routes/
    │   └── web.php                       ← adicionar rotas do CRUD
    └── Providers/
        └── NomeModuloServiceProvider.php ← registrar Policy aqui
```

---

## Passo 3 — Regras obrigatórias de segurança

### Backend
1. Middleware `auth` em todas as rotas do módulo
2. Policy com `before()` para admin + métodos por ação
3. Policy registrada no ServiceProvider do módulo
4. `$this->authorize()` no controller **antes** de toda operação
5. FormRequest com validação completa — nunca validar no controller
6. Soft delete por padrão (`SoftDeletes` + `$table->softDeletes()`)
7. Nunca usar `env()` — sempre `config()`
8. `$fillable` explícito no model — nunca `$guarded = []`
9. Route model binding com type hint no controller

### Frontend (Blade + Livewire 2)
10. `@can` no Blade apenas para UI — o backend nega via authorize()
11. Confirmação antes de delete via Alpine.js
12. `wire:loading` no botão de submit
13. Flash messages via `session('success')` / `session('error')` no layout

---

## Passo 4 — Templates de cada arquivo

### Migration (PHP 7.4 — classe nomeada, não `return new class`)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateNomeEntidadesTable extends Migration
{
    public function up()
    {
        Schema::create('nome_modulo_nome_entidades', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 100);
            $table->softDeletes();
            $table->timestamps();

            $table->index('nome');
        });
    }

    public function down()
    {
        Schema::dropIfExists('nome_modulo_nome_entidades');
    }
}
```

> Prefixo da tabela: `nome_modulo_` (ex: `cadastro_clientes`, `vendas_pedidos`).

---

### Model

```php
<?php

namespace Modules\NomeModulo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class NomeEntidade extends Model
{
    use SoftDeletes;

    protected $table = 'nome_modulo_nome_entidades';

    protected $fillable = [
        'nome',
    ];

    protected $casts = [
        'ativo' => 'boolean',
    ];
}
```

---

### Policy

```php
<?php

namespace Modules\NomeModulo\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Modules\NomeModulo\Models\NomeEntidade;

class NomeEntidadePolicy
{
    use HandlesAuthorization;

    public function before(User $user, $ability)
    {
        if ($user->is_admin) {
            return true;
        }
    }

    public function viewAny(User $user): bool { return true; }
    public function view(User $user, NomeEntidade $item): bool { return true; }
    public function create(User $user): bool { return $user->can_create ?? false; }
    public function update(User $user, NomeEntidade $item): bool { return $user->can_edit ?? false; }
    public function delete(User $user, NomeEntidade $item): bool { return $user->can_delete ?? false; }
}
```

Registrar no `NomeModuloServiceProvider`:
```php
use Illuminate\Support\Facades\Gate;

public function boot()
{
    Gate::policy(NomeEntidade::class, NomeEntidadePolicy::class);
}
```

---

### Repository

```php
<?php

namespace Modules\NomeModulo\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\NomeModulo\Models\NomeEntidade;

class NomeEntidadeRepository
{
    public function listarPorFiltros(array $filtros): LengthAwarePaginator
    {
        return NomeEntidade::query()
            ->when($filtros['search'] ?? null, function ($q, $search) {
                $q->where('nome', 'like', '%' . $search . '%');
            })
            ->orderBy($filtros['sort'] ?? 'nome', $filtros['direction'] ?? 'asc')
            ->paginate(15);
    }

    public function criar(array $dados): NomeEntidade
    {
        return NomeEntidade::create($dados);
    }

    public function atualizar(NomeEntidade $item, array $dados): NomeEntidade
    {
        $item->update($dados);
        return $item;
    }
}
```

---

### Service

```php
<?php

namespace Modules\NomeModulo\Services;

use Modules\NomeModulo\Models\NomeEntidade;
use Modules\NomeModulo\Repositories\NomeEntidadeRepository;

// PHP 7.4: sem constructor promotion — declarar propriedades explicitamente
class NomeEntidadeService
{
    private NomeEntidadeRepository $repository;

    public function __construct(NomeEntidadeRepository $repository)
    {
        $this->repository = $repository;
    }

    public function criar(array $dados): NomeEntidade
    {
        // regras de negócio aqui
        return $this->repository->criar($dados);
    }

    public function atualizar(NomeEntidade $item, array $dados): NomeEntidade
    {
        // regras de negócio aqui
        return $this->repository->atualizar($item, $dados);
    }
}
```

---

### FormRequests

```php
// StoreNomeEntidadeRequest.php
namespace Modules\NomeModulo\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\NomeModulo\Models\NomeEntidade;

class StoreNomeEntidadeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', NomeEntidade::class);
    }

    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:100',
                       'unique:nome_modulo_nome_entidades,nome'],
        ];
    }
}

// UpdateNomeEntidadeRequest.php
class UpdateNomeEntidadeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->nome_entidade);
    }

    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:100',
                       'unique:nome_modulo_nome_entidades,nome,' . $this->nome_entidade->id],
        ];
    }
}
```

---

### Controller (thin)

```php
<?php

namespace Modules\NomeModulo\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\NomeModulo\Http\Requests\StoreNomeEntidadeRequest;
use Modules\NomeModulo\Http\Requests\UpdateNomeEntidadeRequest;
use Modules\NomeModulo\Models\NomeEntidade;
use Modules\NomeModulo\Services\NomeEntidadeService;

class NomeEntidadeController extends Controller
{
    private NomeEntidadeService $service;

    public function __construct(NomeEntidadeService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $this->authorize('viewAny', NomeEntidade::class);
        return view('nomemodulo::livewire.nome-entidade.wrapper-index');
    }

    public function create()
    {
        $this->authorize('create', NomeEntidade::class);
        return view('nomemodulo::livewire.nome-entidade.wrapper-form');
    }

    public function store(StoreNomeEntidadeRequest $request)
    {
        $this->service->criar($request->validated());
        return redirect()->route('nomemodulo.nome-entidades.index')
                         ->with('success', 'Registro criado com sucesso.');
    }

    public function edit(NomeEntidade $nomeEntidade)
    {
        $this->authorize('update', $nomeEntidade);
        return view('nomemodulo::livewire.nome-entidade.wrapper-form',
                    compact('nomeEntidade'));
    }

    public function update(UpdateNomeEntidadeRequest $request, NomeEntidade $nomeEntidade)
    {
        $this->service->atualizar($nomeEntidade, $request->validated());
        return redirect()->route('nomemodulo.nome-entidades.index')
                         ->with('success', 'Registro atualizado com sucesso.');
    }

    public function destroy(NomeEntidade $nomeEntidade)
    {
        $this->authorize('delete', $nomeEntidade);
        $nomeEntidade->delete();
        return redirect()->route('nomemodulo.nome-entidades.index')
                         ->with('success', 'Registro excluído com sucesso.');
    }
}
```

---

### Livewire — Componente de Listagem

```php
<?php

namespace Modules\NomeModulo\Http\Livewire\NomeEntidade;

use Livewire\Component;
use Livewire\WithPagination;
use Modules\NomeModulo\Repositories\NomeEntidadeRepository;

class NomeEntidadeIndex extends Component
{
    use WithPagination;

    public string $search = '';
    public string $sortField = 'nome';
    public string $sortDirection = 'asc';

    protected $queryString = ['search', 'sortField', 'sortDirection'];

    private NomeEntidadeRepository $repository;

    public function boot(NomeEntidadeRepository $repository)
    {
        $this->repository = $repository;
    }

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function sortBy(string $field)
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }
    }

    public function delete(int $id)
    {
        $item = \Modules\NomeModulo\Models\NomeEntidade::findOrFail($id);
        $this->authorize('delete', $item);
        $item->delete();
        session()->flash('success', 'Registro excluído com sucesso.');
    }

    public function render()
    {
        $items = $this->repository->listarPorFiltros([
            'search'    => $this->search,
            'sort'      => $this->sortField,
            'direction' => $this->sortDirection,
        ]);

        return view('nomemodulo::livewire.nome-entidade.index', compact('items'));
    }
}
```

---

### Livewire — View de Listagem

```blade
{{-- Modules/NomeModulo/Resources/views/livewire/nome-entidade/index.blade.php --}}
<div>
    <div class="flex items-center justify-between mb-4">
        <input wire:model.debounce.300ms="search" type="text"
               placeholder="Buscar..."
               class="border rounded px-3 py-1.5 text-sm w-64 focus:outline-none focus:ring-2 focus:ring-blue-400">
        @can('create', Modules\NomeModulo\Models\NomeEntidade::class)
            <a href="{{ route('nomemodulo.nome-entidades.create') }}"
               class="bg-blue-600 text-white px-4 py-2 rounded text-sm hover:bg-blue-700">
                + Novo
            </a>
        @endcan
    </div>

    @if(session('success'))
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-2 rounded mb-4 text-sm">
            {{ session('success') }}
        </div>
    @endif

    <table class="w-full text-sm border-collapse">
        <thead class="bg-gray-100 text-left">
            <tr>
                <th wire:click="sortBy('nome')" class="px-4 py-2 cursor-pointer select-none">
                    Nome
                    @if($sortField === 'nome') {{ $sortDirection === 'asc' ? '▲' : '▼' }} @endif
                </th>
                <th class="px-4 py-2 w-28">Ações</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $item)
                <tr class="border-t hover:bg-gray-50">
                    <td class="px-4 py-2">{{ $item->nome }}</td>
                    <td class="px-4 py-2 space-x-2">
                        @can('update', $item)
                            <a href="{{ route('nomemodulo.nome-entidades.edit', $item) }}"
                               class="text-blue-600 hover:underline text-xs">Editar</a>
                        @endcan
                        @can('delete', $item)
                            <button
                                wire:click="delete({{ $item->id }})"
                                x-on:click="confirm('Confirma exclusão?') || $event.stopImmediatePropagation()"
                                class="text-red-600 hover:underline text-xs">
                                Excluir
                            </button>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="2" class="px-4 py-6 text-center text-gray-400">
                        Nenhum registro encontrado.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="mt-4">{{ $items->links() }}</div>
</div>
```

---

### Rotas do Módulo

```php
<?php
// Modules/NomeModulo/Routes/web.php

use Illuminate\Support\Facades\Route;
use Modules\NomeModulo\Http\Controllers\NomeEntidadeController;

Route::middleware(['auth'])->prefix('nome-modulo')->name('nomemodulo.')->group(function () {
    Route::resource('nome-entidades', NomeEntidadeController::class);
});
```

Registrar Livewire component no ServiceProvider:
```php
use Livewire\Livewire;

public function boot()
{
    Livewire::component('nomemodulo.nome-entidade-index',
        \Modules\NomeModulo\Http\Livewire\NomeEntidade\NomeEntidadeIndex::class);
}
```

---

### PHPUnit — Feature Test

```php
<?php

namespace Modules\NomeModulo\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\NomeModulo\Models\NomeEntidade;
use Tests\TestCase;

class NomeEntidadeTest extends TestCase
{
    use RefreshDatabase;

    public function test_listagem_exige_autenticacao()
    {
        $this->get(route('nomemodulo.nome-entidades.index'))
             ->assertRedirect(route('login'));
    }

    public function test_usuario_autenticado_ve_listagem()
    {
        $this->actingAs(User::factory()->create())
             ->get(route('nomemodulo.nome-entidades.index'))
             ->assertOk();
    }

    public function test_admin_cria_registro_com_dados_validos()
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
             ->post(route('nomemodulo.nome-entidades.store'), ['nome' => 'Teste'])
             ->assertRedirect(route('nomemodulo.nome-entidades.index'));

        $this->assertDatabaseHas('nome_modulo_nome_entidades', ['nome' => 'Teste']);
    }

    public function test_nome_duplicado_retorna_erro()
    {
        NomeEntidade::factory()->create(['nome' => 'Existente']);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
             ->post(route('nomemodulo.nome-entidades.store'), ['nome' => 'Existente'])
             ->assertSessionHasErrors('nome');
    }

    public function test_soft_delete()
    {
        $item  = NomeEntidade::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
             ->delete(route('nomemodulo.nome-entidades.destroy', $item));

        $this->assertSoftDeleted('nome_modulo_nome_entidades', ['id' => $item->id]);
    }
}
```

---

## Passo 5 — Nomenclatura obrigatória

| Elemento | Padrão | Exemplo |
|---|---|---|
| Tabela | `modulo_entidade` plural | `cadastro_clientes`, `vendas_pedidos` |
| Namespace | `Modules\NomeModulo\...` | `Modules\Cadastro\Models\Cliente` |
| Model | PascalCase singular | `Cliente` |
| Controller | PascalCase + Controller | `ClienteController` |
| Livewire Index | PascalCase + Index | `ClienteIndex` |
| Livewire Form | PascalCase + Form | `ClienteForm` |
| Service | PascalCase + Service | `ClienteService` |
| Repository | PascalCase + Repository | `ClienteRepository` |
| Policy | PascalCase + Policy | `ClientePolicy` |
| Rota name | `modulo.entidades.acao` | `cadastro.clientes.index` |
| View namespace | `nomemodulo::livewire.entidade.view` | `cadastro::livewire.cliente.index` |

---

## Passo 6 — Compatibilidade PHP 7.4

Nunca usar:
- Constructor promotion (`private Repo $r` no parâmetro do construtor)
- Named arguments
- `match` sem `default`
- `str_contains()`, `str_starts_with()`, `str_ends_with()`
- Enums (`enum`)
- Union types em assinatura (usar PHPDoc)

---

## Passo 7 — Checklist de verificação final

- [ ] Migration com **classe nomeada** (não `return new class`) + prefixo de módulo na tabela
- [ ] Model com `$table`, `$fillable`, `$casts`, `SoftDeletes`
- [ ] Factory para uso nos testes
- [ ] Policy com `before()` + métodos por ação
- [ ] Policy registrada no ServiceProvider do módulo via `Gate::policy()`
- [ ] Repository com `listarPorFiltros()` paginado
- [ ] Service sem lógica no controller — PHP 7.4 sem constructor promotion
- [ ] FormRequests separados Store/Update com `authorize()` + `rules()`
- [ ] Controller thin com `$this->authorize()` antes de toda operação
- [ ] Livewire Index com busca, paginação, sort e delete com `x-on:click="confirm(...)"`
- [ ] `wire:loading` no botão de submit do form
- [ ] Flash messages com `session('success')` na view
- [ ] Rotas com middleware `auth` + nome prefixado pelo módulo
- [ ] Livewire component registrado no ServiceProvider
- [ ] PHPUnit cobrindo: acesso sem auth, listagem, criação, validação, soft delete
- [ ] Nenhuma sintaxe PHP 8+ usada

---

## Formato de saída

Gerar sempre um arquivo `TASK-NNNNN-crud-[nome-entidade].md` salvo em:
- `src/Modules/NomeModulo/docs/tasks/` — se for task de módulo específico
- `src/docs/tasks/` — se for task global/infraestrutura

O arquivo deve ser autocontido — o Claude Code executa sem precisar perguntar nada.
Ordem: migration → model → factory → policy → repository → service → requests → controller → livewire → views → rotas → testes
