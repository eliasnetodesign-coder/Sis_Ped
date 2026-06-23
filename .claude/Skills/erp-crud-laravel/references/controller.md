# Template — Controller (Laravel 8 + PHP 7.4 + Blade/Livewire)

```php
<?php

namespace Modules\{Modulo}\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\{Modulo}\Http\Requests\Store{Entidade}Request;
use Modules\{Modulo}\Http\Requests\Update{Entidade}Request;
use Modules\{Modulo}\Models\{Entidade};
use Modules\{Modulo}\Services\{Entidade}Service;

// PHP 7.4: sem constructor promotion
class {Entidade}Controller extends Controller
{
    private {Entidade}Service $service;

    public function __construct({Entidade}Service $service)
    {
        $this->service = $service;
    }

    // Retorna view que embute o componente Livewire de listagem
    public function index()
    {
        $this->authorize('viewAny', {Entidade}::class);

        return view('{modulo}::livewire.{entidade}.wrapper-index');
    }

    public function create()
    {
        $this->authorize('create', {Entidade}::class);

        return view('{modulo}::livewire.{entidade}.wrapper-form');
    }

    // authorize() já feito no Store{Entidade}Request::authorize()
    public function store(Store{Entidade}Request $request)
    {
        try {
            $this->service->criar($request->validated());
        } catch (\DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('{modulo}.{entidade}s.index')
                         ->with('success', '{Entidade} criado com sucesso.');
    }

    public function edit({Entidade} ${entidade})
    {
        $this->authorize('update', ${entidade});

        return view('{modulo}::livewire.{entidade}.wrapper-form', [
            '{entidade}' => ${entidade},
        ]);
    }

    // authorize() já feito no Update{Entidade}Request::authorize()
    public function update(Update{Entidade}Request $request, {Entidade} ${entidade})
    {
        try {
            $this->service->atualizar(${entidade}, $request->validated());
        } catch (\DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('{modulo}.{entidade}s.index')
                         ->with('success', '{Entidade} atualizado com sucesso.');
    }

    public function destroy({Entidade} ${entidade})
    {
        $this->authorize('delete', ${entidade});

        try {
            $this->service->deletar(${entidade});
        } catch (\DomainException $e) {
            return redirect()->route('{modulo}.{entidade}s.index')
                             ->with('error', $e->getMessage());
        }

        return redirect()->route('{modulo}.{entidade}s.index')
                         ->with('success', '{Entidade} excluído com sucesso.');
    }
}
```

## Template — Rotas do Módulo

```php
<?php
// Modules/{Modulo}/Routes/web.php

use Illuminate\Support\Facades\Route;
use Modules\{Modulo}\Http\Controllers\{Entidade}Controller;

Route::middleware(['auth'])
     ->prefix('{modulo}')
     ->name('{modulo}.')
     ->group(function () {
         Route::resource('{entidade}s', {Entidade}Controller::class);
     });
```

## Registro do Livewire no ServiceProvider

```php
// Modules/{Modulo}/Providers/{Modulo}ServiceProvider.php
use Livewire\Livewire;

public function boot(): void
{
    Livewire::component('{modulo}.{entidade}-index',
        \Modules\{Modulo}\Http\Livewire\{Entidade}\{Entidade}Index::class);

    Livewire::component('{modulo}.{entidade}-form',
        \Modules\{Modulo}\Http\Livewire\{Entidade}\{Entidade}Form::class);
}
```

## Regras de Controller

- **Thin** — só recebe, delega ao Service, responde
- Nunca colocar regra de negócio no controller
- `$this->authorize()` em métodos sem FormRequest (`index`, `edit`, `destroy`)
- Store e Update: authorize() fica no FormRequest — não duplicar no controller
- Capturar `\DomainException` do Service e converter em flash de erro
- Sempre usar `$request->validated()` — nunca `$request->all()`
- Retornar Blade views — **não usar `Inertia::render()`**
- **PHP 7.4**: sem constructor promotion — declarar `private` separado
- **Sem middleware `permission:`** — Spatie Permission não está instalado
