# Template — Migration (Laravel 8 + PHP 7.4)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// OBRIGATÓRIO: classe nomeada — não usar "return new class"
// Laravel 8 + PHP 7.4 usam classes nomeadas em migrations
class Create{Prefixo}{Tabela}Table extends Migration
{
    public function up()
    {
        Schema::create('{prefixo_modulo}_{tabela}', function (Blueprint $table) {
            $table->id();

            // FKs — referenciar tabela com prefixo do módulo dono
            $table->unsignedBigInteger('{entidade_pai}_id');
            $table->foreign('{entidade_pai}_id')
                  ->references('id')
                  ->on('{prefixo_pai}_{tabela_pai}')
                  ->restrictOnDelete(); // ou cascadeOnDelete() conforme regra

            // Campos obrigatórios
            $table->string('{campo}', {tamanho});

            // Campos opcionais
            $table->string('{campo}')->nullable();

            // Valores monetários — sempre decimal, nunca float
            $table->decimal('{campo}_valor', 15, 2)->default(0);

            // Status como string (evitar enum() do MySQL — difícil de alterar)
            $table->string('status', 30)->default('{valor_inicial}');

            // Boolean com default
            $table->boolean('ativo')->default(true);

            // Soft delete obrigatório em entidades de negócio
            $table->softDeletes();
            $table->timestamps();

            // Índices — sempre indexar campos de busca, status e FKs
            $table->index('{campo_busca}');
            $table->index(['{fk}_id', 'status']); // índice composto
        });
    }

    public function down()
    {
        Schema::dropIfExists('{prefixo_modulo}_{tabela}');
    }
}
```

## Regras de Migration

- **Classe nomeada obrigatória** — `return new class` é padrão do Laravel 9+, não do 8
- `up()` e `down()` sem tipo de retorno `void` — PHP 7.4 aceita, mas o gerador do Laravel 8 omite
- Prefixo da tabela = nome do módulo em snake_case: `cadastro_`, `financeiro_`, `vendas_`
- **Valores monetários**: sempre `decimal(15, 2)` — nunca `float` ou `double`
- **Enum**: evitar `->enum()` — usar `->string()` com validação no FormRequest (enum é difícil de alterar em produção)
- **FKs**: usar `unsignedBigInteger` + `foreign()` em vez de `foreignId()->constrained()` quando a tabela referenciada tem prefixo de módulo (evita ambiguidade)
- **Índices**: criar em todo campo usado em `WHERE`, `ORDER BY` ou FK
- **`softDeletes()`**: obrigatório em toda entidade de negócio
- **Migrations são imutáveis** — nunca editar migration existente, sempre criar nova
- **MariaDB**: sem `ILIKE`, sem `RETURNING`, sem arrays nativos — ver ADR-002
