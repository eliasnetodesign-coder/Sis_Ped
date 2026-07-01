# 02 — Autenticação e Controle de Acesso

## Login (`login.php`)

- Formulário **único** para todos os tipos de usuário; autenticação por **e-mail + senha**.
- Verifica primeiro a tabela `clientes` (status ativo), depois `usuarios` (status ativo).
- Botão de mostrar/ocultar senha.
- **Redirecionamento automático:**
  - Cliente **sem** grupo de empresas (ou grupo com 1 membro) → `cliente/dashboard.php`.
  - Cliente **em** grupo de empresas com mais de um membro → `cliente/selecionar-cnpj.php` (escolha da empresa).
  - Admin → `admin/dashboard.php`.
- Se já logado, redireciona para `index.php` (que roteia por perfil).
- Mensagens de erro via flash session.

## Senha temporária (`trocar-senha.php`)

- Usuários/clientes com `senha_temporaria = 1` recebem `must_change` na sessão e são direcionados a trocar a senha.
- **Validações:** nova senha obrigatória, confirmação igual, mínimo 4 caracteres e diferente da senha padrão (`123`).
- Atualiza `senha` e zera `senha_temporaria` na tabela correspondente (`clientes` ou `usuarios`).

## Verificação de Acesso — 2FA por WhatsApp (`verificar-acesso.php`)

Camada extra de segurança para usuários internos **externos ao escritório**.

- **Gatilho:** usuário cujo **Tipo de Usuário** (`usuarios.tipo_usuario`, texto livre) seja **"Externo"** e que logue a partir de um IP **diferente** de `IP_LIBERADO`.
- **Fluxo:**
  1. `login.php` valida a senha, gera um **código de 6 dígitos**, envia via `enviarWhatsappCodigo()` e guarda em `$_SESSION['login_2fa']` (hash do código com `password_hash`, expiração, telefone mascarado, contador de tentativas).
  2. O login **só é efetivado** após a verificação bem-sucedida em `verificar-acesso.php`.
- **Regras:**
  - Validade `WHATSAPP_CODIGO_VALIDADE` segundos (padrão 600 = 10 min).
  - Máximo de **5 tentativas**; excedido, volta ao login.
  - Opção de **reenviar** o código.
  - Sem celular cadastrado, o acesso externo é bloqueado com aviso.
- **Integração:** hoje o envio é registrado em `whatsapp_logs` e `logs/whatsapp.log` (modo simulação). O ponto único `enviarWhatsappCodigo()` está pronto para um provedor real (WhatsApp Cloud API / Twilio). Ver [Integrações](13-integracoes.md).

## Perfis de usuário

| Perfil (`tipo_acesso`) | Portal | Permissões |
|--------|--------|-----------|
| `comercial` | Admin | Todos os pedidos e relatórios; aprova e cancela pedidos na etapa Comercial; gerencia cadastros comerciais |
| `financeiro` | Admin | Módulo financeiro e cadastros financeiros; aprova, cancela e retorna pedidos na etapa Financeiro; vê colunas fiscais/crédito nos pedidos |
| `supervisor` | Admin | Visão filtrada **somente** aos próprios clientes e pedidos; pode criar pedidos e aprovar na etapa Comercial |
| `tecnologia da informacao` | Admin | **Acesso total** — atua como Comercial **e** Financeiro (sem o escopo restrito do supervisor) |
| `cliente` | Cliente | Acesso apenas ao próprio portal: pedidos, financeiro, perfil, troca de CNPJ |

### TI = acesso total

O tipo `tecnologia da informacao` (gravado sem acento) vê todos os módulos e executa todas as ações. O gating fino inclui TI nas **duas** listas de papel:

```php
$isComercial  = in_array($u['tipo'], ['comercial','tecnologia da informacao']);
$isFinanceiro = in_array($u['tipo'], ['financeiro','tecnologia da informacao']);
```

> Ao criar uma nova checagem de papel, **incluir TI em ambos os grupos**.

### Tipos de acesso disponíveis (11)

O enum de `usuarios.tipo_acesso` aceita: `comercial`, `financeiro`, `supervisor`, `tecnologia da informacao`, `recursos humanos`, `marketing`, `diretoria`, `centro tecnico`, `contabilidade`, `recepcao`, `expedicao`. Apenas os quatro primeiros têm comportamento de permissão definido; os demais entram como acesso admin genérico (`requireAdmin()`), sem menus/rotas dedicados.

## Proteção de rotas

| Função (`config.php`) | Perfis permitidos |
|-----------------------|-------------------|
| `requireAdmin()` | `comercial`, `financeiro`, `supervisor`, `tecnologia da informacao` |
| `requireComercial()` | `comercial`, `supervisor`, `tecnologia da informacao` |
| `requireCliente()` | somente `cliente` |
| `requireLogin()` | qualquer usuário autenticado (ex.: `trocar-senha.php`) |

- As rotas do financeiro admin aceitam `comercial`, `financeiro` ou `tecnologia da informacao`.

Relacionado: [Portal Administrativo](03-portal-admin.md), [Portal do Cliente](09-portal-cliente.md).
