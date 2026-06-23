# Template — FormRequests (Laravel 8 + PHP 7.4)

## Store{Entidade}Request

```php
<?php

namespace Modules\{Modulo}\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\{Modulo}\Models\{Entidade};

class Store{Entidade}Request extends FormRequest
{
    public function authorize(): bool
    {
        // Verificar Policy diretamente no FormRequest
        // O controller NÃO precisa chamar authorize() novamente para store/update
        return $this->user()->can('create', {Entidade}::class);
    }

    public function rules(): array
    {
        return [
            '{campo_texto}'    => ['required', 'string', 'max:255'],
            '{campo_opcional}' => ['nullable', 'string', 'max:100'],
            '{campo_decimal}'  => ['required', 'numeric', 'min:0'],
            '{campo_date}'     => ['required', 'date'],
            '{campo_fk}'       => ['required', 'integer', 'exists:{tabela_referencia},id'],
            '{campo_enum}'     => ['required', 'string', 'in:{valor1},{valor2},{valor3}'],
            '{campo_email}'    => ['nullable', 'email', 'max:150'],
            '{campo_cpf_cnpj}' => ['required', 'string', 'max:18',
                                   'unique:{prefixo}_{tabela},{campo_cpf_cnpj}'],
        ];
    }

    public function messages(): array
    {
        return [
            '{campo}.required' => 'O campo {Label} é obrigatório.',
            '{campo}.max'      => 'O campo {Label} deve ter no máximo {n} caracteres.',
            '{campo}.exists'   => '{Label} não encontrado.',
            '{campo}.in'       => '{Label} inválido.',
            '{campo}.unique'   => '{Label} já cadastrado.',
        ];
    }

    public function attributes(): array
    {
        return [
            '{campo}' => '{label amigável}',
        ];
    }
}
```

## Update{Entidade}Request

```php
<?php

namespace Modules\{Modulo}\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\{Modulo}\Models\{Entidade};

class Update{Entidade}Request extends FormRequest
{
    public function authorize(): bool
    {
        // Route model binding: $this->{entidade} é resolvido automaticamente
        return $this->user()->can('update', $this->{entidade});
    }

    public function rules(): array
    {
        return [
            // unique com ignore no registro atual — evita conflito ao salvar o próprio registro
            '{campo_unico}' => [
                'required', 'string', 'max:255',
                Rule::unique('{prefixo}_{tabela}', '{campo_unico}')
                    ->ignore($this->route('{entidade}')),
            ],
            '{campo_texto}' => ['required', 'string', 'max:255'],
            // ... demais campos iguais ao Store
        ];
    }
}
```

## Regras de FormRequest

- `authorize()` verifica a Policy — não deixar como `return true`
- Store: `$this->user()->can('create', Entidade::class)`
- Update: `$this->user()->can('update', $this->{entidade})` — usa route model binding
- Campos `unique` no Update: usar `Rule::unique()->ignore()` para não conflitar com o próprio registro
- **Valores monetários**: validar como `numeric` — cast para decimal no Model
- **Datas**: `after_or_equal:today` para datas futuras obrigatórias
- **FKs**: sempre `exists:{tabela},id` para garantir integridade
- **Enum/Status**: validar com `in:` no Request — não usar enum do MySQL (ADR-002)
- Nunca colocar regra de negócio no FormRequest — só formato e integridade de dados
- **PHP 7.4**: `authorize()` e `rules()` retornam `bool` e `array` — válido
