# Template — Service (Laravel 8 + PHP 7.4)

```php
<?php

namespace Modules\{Modulo}\Services;

use Modules\{Modulo}\Models\{Entidade};
use Modules\{Modulo}\Repositories\{Entidade}Repository;

// PHP 7.4: sem constructor promotion
// Declarar propriedade tipada + atribuir no __construct
class {Entidade}Service
{
    private {Entidade}Repository $repository;

    public function __construct({Entidade}Repository $repository)
    {
        $this->repository = $repository;
    }

    public function criar(array $dados): {Entidade}
    {
        // 1. Regras de negócio antes de persistir
        $this->validarRegrasNegocio($dados);

        // 2. Transformações se necessário (ex: calcular campo derivado)

        // 3. Persistir
        $entidade = $this->repository->criar($dados);

        // 4. Jobs assíncronos (driver sync em dev — muda só o .env em prod)
        // EnviarEmailJob::dispatch($entidade);

        return $entidade;
    }

    public function atualizar({Entidade} $entidade, array $dados): {Entidade}
    {
        $this->validarRegrasNegocio($dados, $entidade);

        return $this->repository->atualizar($entidade, $dados);
    }

    public function deletar({Entidade} $entidade): void
    {
        // Verificar dependências antes de deletar
        if ($entidade->{relacionamento}()->exists()) {
            throw new \DomainException(
                'Não é possível excluir: existem {dependencias} vinculados.'
            );
        }

        $this->repository->deletar($entidade);
    }

    // ── Regras de negócio privadas ────────────────────────────────────────────

    // PHP 7.4: ?{Entidade} funciona (nullable type existe desde PHP 7.1)
    private function validarRegrasNegocio(array $dados, ?{Entidade} $entidade = null): void
    {
        // Exemplo de regra:
        // if (($dados['valor'] ?? 0) > 5000 && empty($dados['aprovador_id'])) {
        //     throw new \DomainException('Valores acima de R$5.000 precisam de aprovação.');
        // }
    }
}
```

## Regras de Service

- **Toda** regra de negócio fica aqui — nunca no Controller, Model ou Repository
- Lançar `\DomainException` para erros de negócio (não de validação de input)
- Jobs sempre disparados no Service, nunca no Controller
- Não acessar banco diretamente — sempre via Repository
- **PHP 7.4**: sem constructor promotion — declarar `private Tipo $prop` separado e atribuir no `__construct`
- `?Tipo` (nullable) é válido desde PHP 7.1 — pode usar em parâmetros
- Nomes de métodos descritivos em português: `criar`, `atualizar`, `deletar`, `aprovar`
