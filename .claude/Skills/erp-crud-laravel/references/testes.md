# Template — Testes PHPUnit (Laravel 8 + PHP 7.4)

> **Não usar Pest** — este projeto usa PHPUnit 9 (padrão do Laravel 8).
> Sem `it()`, `beforeEach()`, `expect()` do Pest. Usar `$this->assert*()`.

---

## Teste Feature — Controller

```php
<?php

// Modules/{Modulo}/Tests/Feature/{Entidade}Test.php
// OU: tests/Feature/{Modulo}/{Entidade}Test.php

namespace Modules\{Modulo}\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\{Modulo}\Models\{Entidade};
use Tests\TestCase;

class {Entidade}Test extends TestCase
{
    use RefreshDatabase;

    // ── Setup ─────────────────────────────────────────────────────────────────

    private function criarAdmin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function criarUsuarioComum(): User
    {
        return User::factory()->create([
            'is_admin'   => false,
            'can_create' => false,
            'can_edit'   => false,
            'can_delete' => false,
        ]);
    }

    // ── Acesso sem autenticação ───────────────────────────────────────────────

    public function test_listagem_redireciona_login_sem_autenticacao()
    {
        $this->get(route('{modulo}.{entidade}s.index'))
             ->assertRedirect(route('login'));
    }

    public function test_criacao_redireciona_login_sem_autenticacao()
    {
        $this->post(route('{modulo}.{entidade}s.store'), [])
             ->assertRedirect(route('login'));
    }

    // ── Acesso autorizado ─────────────────────────────────────────────────────

    public function test_admin_acessa_listagem()
    {
        $this->actingAs($this->criarAdmin())
             ->get(route('{modulo}.{entidade}s.index'))
             ->assertOk();
    }

    public function test_admin_acessa_formulario_de_criacao()
    {
        $this->actingAs($this->criarAdmin())
             ->get(route('{modulo}.{entidade}s.create'))
             ->assertOk();
    }

    // ── Criação ───────────────────────────────────────────────────────────────

    public function test_admin_cria_registro_com_dados_validos()
    {
        $admin = $this->criarAdmin();

        $this->actingAs($admin)
             ->post(route('{modulo}.{entidade}s.store'), [
                 '{campo1}' => '{valor_valido}',
                 '{campo2}' => '{valor_valido}',
             ])
             ->assertRedirect(route('{modulo}.{entidade}s.index'))
             ->assertSessionHas('success');

        $this->assertDatabaseHas('{prefixo}_{tabela}', [
            '{campo1}' => '{valor_valido}',
        ]);
    }

    // ── Validação ─────────────────────────────────────────────────────────────

    public function test_campo_obrigatorio_ausente_retorna_erro_validacao()
    {
        $this->actingAs($this->criarAdmin())
             ->post(route('{modulo}.{entidade}s.store'), [
                 '{campo_obrigatorio}' => '',
             ])
             ->assertSessionHasErrors('{campo_obrigatorio}');
    }

    public function test_campo_unico_duplicado_retorna_erro_validacao()
    {
        {Entidade}::factory()->create(['{campo_unico}' => '{valor_existente}']);

        $this->actingAs($this->criarAdmin())
             ->post(route('{modulo}.{entidade}s.store'), [
                 '{campo_unico}' => '{valor_existente}',
             ])
             ->assertSessionHasErrors('{campo_unico}');
    }

    // ── Edição ────────────────────────────────────────────────────────────────

    public function test_admin_atualiza_registro_com_dados_validos()
    {
        $entidade = {Entidade}::factory()->create();
        $admin    = $this->criarAdmin();

        $this->actingAs($admin)
             ->put(route('{modulo}.{entidade}s.update', $entidade), [
                 '{campo1}' => 'Novo Valor',
             ])
             ->assertRedirect(route('{modulo}.{entidade}s.index'));

        $this->assertDatabaseHas('{prefixo}_{tabela}', [
            'id'       => $entidade->id,
            '{campo1}' => 'Novo Valor',
        ]);
    }

    // ── Exclusão (soft delete) ────────────────────────────────────────────────

    public function test_admin_exclui_registro_com_soft_delete()
    {
        $entidade = {Entidade}::factory()->create();
        $admin    = $this->criarAdmin();

        $this->actingAs($admin)
             ->delete(route('{modulo}.{entidade}s.destroy', $entidade))
             ->assertRedirect(route('{modulo}.{entidade}s.index'));

        // Soft delete: deleted_at preenchido, registro ainda existe no banco
        $this->assertSoftDeleted('{prefixo}_{tabela}', ['id' => $entidade->id]);
    }

    // ── Autorização ───────────────────────────────────────────────────────────

    public function test_usuario_sem_permissao_nao_pode_criar()
    {
        $this->actingAs($this->criarUsuarioComum())
             ->post(route('{modulo}.{entidade}s.store'), ['{campo1}' => 'Teste'])
             ->assertForbidden();
    }

    public function test_usuario_sem_permissao_nao_pode_excluir()
    {
        $entidade = {Entidade}::factory()->create();

        $this->actingAs($this->criarUsuarioComum())
             ->delete(route('{modulo}.{entidade}s.destroy', $entidade))
             ->assertForbidden();
    }
}
```

---

## Teste Unitário — Service

```php
<?php

// Modules/{Modulo}/Tests/Unit/{Entidade}ServiceTest.php

namespace Modules\{Modulo}\Tests\Unit;

use Mockery;
use Mockery\MockInterface;
use Modules\{Modulo}\Models\{Entidade};
use Modules\{Modulo}\Repositories\{Entidade}Repository;
use Modules\{Modulo}\Services\{Entidade}Service;
use Tests\TestCase;

class {Entidade}ServiceTest extends TestCase
{
    public function test_cria_entidade_com_dados_validos()
    {
        $dados        = ['{campo1}' => '{valor}'];
        $entidadeMock = new {Entidade}($dados);

        /** @var {Entidade}Repository|MockInterface $repository */
        $repository = Mockery::mock({Entidade}Repository::class);
        $repository->shouldReceive('criar')
                   ->once()
                   ->with($dados)
                   ->andReturn($entidadeMock);

        $service   = new {Entidade}Service($repository);
        $resultado = $service->criar($dados);

        $this->assertInstanceOf({Entidade}::class, $resultado);
    }

    public function test_lanca_excecao_ao_deletar_entidade_com_dependencias()
    {
        // Criar entidade com relacionamento mockado
        $entidade = Mockery::mock({Entidade}::class)->makePartial();
        $entidade->shouldReceive('{relacionamento}->exists')
                 ->andReturn(true);

        $service = new {Entidade}Service(
            Mockery::mock({Entidade}Repository::class)
        );

        $this->expectException(\DomainException::class);
        $service->deletar($entidade);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
```

---

## Regras de Testes

- **PHPUnit 9** — sem Pest. Classes extends `TestCase`, métodos `public function test_*()`
- `use RefreshDatabase` — banco real (MariaDB de teste ou SQLite :memory:)
- Helpers privados `criarAdmin()`, `criarUsuarioComum()` em vez de `beforeEach()`
- Testes feature: banco real, HTTP completo, sem mock do controller
- Testes unit: mock com Mockery (já incluso no Laravel), `tearDown` com `Mockery::close()`
- Testar: acesso sem auth, acesso autorizado, criação válida, validação, soft delete, autorização negada
- `assertSoftDeleted()` para soft delete — verifica `deleted_at` sem apagar o registro
- `assertSessionHas('success')` e `assertSessionHasErrors()` para flash/validation
- **Sem Spatie**: `criarAdmin()` usa `['is_admin' => true]` — não `assignRole()`
- **Sem assertInertia**: usar `assertOk()`, `assertSee()`, `assertViewIs()` para Blade
