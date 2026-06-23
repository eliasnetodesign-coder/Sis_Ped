# Template — Repository (Laravel 8 + MariaDB)

```php
<?php

namespace Modules\{Modulo}\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\{Modulo}\Models\{Entidade};

class {Entidade}Repository
{
    // Listagem paginada com filtros
    public function listarPorFiltros(array $filtros = []): LengthAwarePaginator
    {
        $query = {Entidade}::query();

        // Busca por texto — usar LIKE (MariaDB com collation utf8mb4_unicode_ci é case-insensitive)
        // NÃO usar ILIKE — isso é PostgreSQL
        if (! empty($filtros['busca'])) {
            $busca = $filtros['busca'];
            $query->where(function ($q) use ($busca) {
                $q->where('{campo_busca}', 'like', '%' . $busca . '%')
                  ->orWhere('{outro_campo}', 'like', '%' . $busca . '%');
            });
        }

        // Filtro por status
        if (! empty($filtros['status'])) {
            $query->where('status', $filtros['status']);
        }

        // Filtro por boolean
        if (isset($filtros['ativo'])) {
            $query->where('ativo', (bool) $filtros['ativo']);
        }

        // Ordenação — validar campo para evitar injeção SQL
        $camposPermitidos = ['{campo1}', '{campo2}', 'created_at'];
        $campo   = in_array($filtros['sort'] ?? '', $camposPermitidos)
                   ? $filtros['sort']
                   : '{campo_default}';
        $direcao = ($filtros['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        $query->orderBy($campo, $direcao);

        return $query->paginate($filtros['por_pagina'] ?? 15);
    }

    public function criar(array $dados): {Entidade}
    {
        return {Entidade}::create($dados);
    }

    public function atualizar({Entidade} $entidade, array $dados): {Entidade}
    {
        $entidade->update($dados);
        return $entidade->fresh();
    }

    public function deletar({Entidade} $entidade): void
    {
        $entidade->delete(); // soft delete automático com a trait
    }

    // Busca por ID — lança 404 se não encontrado
    public function findOrFail(int $id): {Entidade}
    {
        return {Entidade}::findOrFail($id);
    }

    // Listagem sem paginação — apenas para selects/dropdowns
    public function listarAtivos(): Collection
    {
        return {Entidade}::where('ativo', true)
                          ->orderBy('{campo_default}')
                          ->get();
    }
}
```

## Regras de Repository

- Responsabilidade única: acesso a dados — sem regra de negócio
- **MariaDB usa `LIKE`**, não `ILIKE` (PostgreSQL) — com `utf8mb4_unicode_ci` já é case-insensitive
- Sempre retornar paginado no `listarPorFiltros()` — nunca `get()` sem limite em listagens
- Validar `$campo` de ordenação contra lista branca — evitar injeção via query string
- `fresh()` após `update()` para retornar dados atualizados do banco
- Soft delete: `delete()` já usa soft delete se o model tem a trait `SoftDeletes`
- `listarAtivos()` sem paginação apenas para selects — nunca para listagens grandes
