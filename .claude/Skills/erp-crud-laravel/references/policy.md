# Template — Policy (Laravel 8 + PHP 7.4)

```php
<?php

namespace Modules\{Modulo}\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Modules\{Modulo}\Models\{Entidade};

class {Entidade}Policy
{
    use HandlesAuthorization;

    /**
     * before() é avaliado antes de qualquer método.
     * Retornar true concede tudo ao admin.
     * Retornar null passa para o método específico.
     * NÃO declarar tipo de retorno — null implícito = pass-through no Laravel 8.
     */
    public function before(User $user, $ability)
    {
        if ($user->is_admin) {
            return true;
        }
    }

    public function viewAny(User $user): bool
    {
        return true; // todo usuário autenticado pode listar
    }

    public function view(User $user, {Entidade} $entidade): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return (bool) ($user->can_create ?? false);
    }

    public function update(User $user, {Entidade} $entidade): bool
    {
        return (bool) ($user->can_edit ?? false);
    }

    public function delete(User $user, {Entidade} $entidade): bool
    {
        return (bool) ($user->can_delete ?? false);
    }
}
```

## Registro no ServiceProvider do módulo

```php
<?php
// Modules/{Modulo}/Providers/{Modulo}ServiceProvider.php

namespace Modules\{Modulo}\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\{Modulo}\Models\{Entidade};
use Modules\{Modulo}\Policies\{Entidade}Policy;

class {Modulo}ServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Registrar Policies do módulo via Gate::policy()
        // NÃO usar $policies array — isso só funciona no AuthServiceProvider
        Gate::policy({Entidade}::class, {Entidade}Policy::class);
    }
}
```

> **Por que `Gate::policy()` e não `$policies` array?**
> O array `$policies` só é processado pelo `AuthServiceProvider` do Laravel.
> Em um ServiceProvider de módulo nwidart (que estende `Illuminate\Support\ServiceProvider`),
> o array é ignorado. Use sempre `Gate::policy()` no método `boot()`.

## Uso no Controller

```php
// index / create / show — sem FormRequest
$this->authorize('viewAny', {Entidade}::class);
$this->authorize('create', {Entidade}::class);

// edit / update / destroy — com instância
$this->authorize('update', $entidade);
$this->authorize('delete', $entidade);
```

## Uso no Blade

```blade
@can('create', Modules\{Modulo}\Models\{Entidade}::class)
    <a href="{{ route('{modulo}.{entidade}s.create') }}">+ Novo</a>
@endcan

@can('update', $item)
    <a href="{{ route('{modulo}.{entidade}s.edit', $item) }}">Editar</a>
@endcan

@can('delete', $item)
    <button wire:click="delete({{ $item->id }})">Excluir</button>
@endcan
```

## Regras de Policy

- `before()` **sem tipo de retorno** — `null` implícito não nega, passa para o método
- Usar `HandlesAuthorization` trait (convencional no Laravel 8)
- Cada método retorna `bool` — nunca lançar exceção aqui
- Lógica simples: campo no User (`is_admin`, `can_create`, etc.) ou relação com Role futura
- Registrar via `Gate::policy()` no `boot()` do ServiceProvider do módulo
- **Spatie Permission não está instalado** — não usar `$user->can('string.permissao')`
