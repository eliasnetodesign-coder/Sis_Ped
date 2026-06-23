# Template — Model (Laravel 8 + PHP 7.4)

```php
<?php

namespace Modules\{Modulo}\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class {Entidade} extends Model
{
    use SoftDeletes;

    // Sempre declarar $table explicitamente — prefixo do módulo obrigatório
    protected $table = '{prefixo_modulo}_{tabela}';

    // Sempre $fillable explícito — nunca $guarded = []
    protected $fillable = [
        '{campo1}',
        '{campo2}',
        '{fk}_id',
    ];

    protected $casts = [
        '{campo_bool}'    => 'boolean',
        '{campo_decimal}' => 'decimal:2',
        '{campo_date}'    => 'date',
        '{campo_json}'    => 'array',
    ];

    // ── Relacionamentos ───────────────────────────────────────────────────────

    public function {entidadePai}(): BelongsTo
    {
        return $this->belongsTo({EntidadePai}::class);
    }

    public function {entidadesFilho}(): HasMany
    {
        return $this->hasMany({EntidadeFilho}::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeAtivo(Builder $query): Builder
    {
        return $query->where('ativo', true);
    }

    public function scopePorStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }
}
```

## Regras de Model

- Sempre declarar `$table` com prefixo do módulo: `cadastro_`, `vendas_`, etc.
- `$fillable` obrigatório — nunca `$guarded = []`
- Campos sensíveis (tokens, senhas) em `$hidden`
- `SoftDeletes` obrigatório em toda entidade de negócio
- Regras de negócio nunca ficam no Model — ficam no Service
- Accessors/Mutators apenas para transformações de formato, não de negócio
- **Spatie Activitylog não instalado** — não usar `LogsActivity` nem `LogOptions`
- **PHP 7.4**: sem constructor promotion, sem union types em assinatura
