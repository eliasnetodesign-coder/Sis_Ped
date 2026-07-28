# Cadastro de Usuários

`admin/cadastros/usuarios.php` — acesso `requireAdmin()`.

## Campos

- Código
- Nome*
- E-mail*
- Senha (obrigatória na criação; em branco = manter)
- Departamento
- Divisão de Vendas
- **Tipo de Acesso*** — 11 opções (ver abaixo)
- **Tipo de Usuário** (texto livre, ex.: Gerente, Analista; o valor **"Externo"** ativa o 2FA por WhatsApp)
- Telefone / Celular
- Ramal
- Status

## Tipo de Acesso (11 valores)

`comercial`, `financeiro`, `supervisor`, `tecnologia da informacao`, `recursos humanos`, `marketing`, `diretoria`, `centro tecnico`, `contabilidade`, `recepcao`, `expedicao`, `admin`.

- O valor gravado em `usuarios.tipo_acesso` (ENUM) é minúsculo/sem acento; o rótulo exibido é acentuado (array `$TIPOS_ACESSO`).
- Apenas `comercial`, `financeiro`, `supervisor` e `tecnologia da informacao` têm comportamento de permissão definido; os demais entram como acesso admin genérico. Ver [Autenticação e Acesso](../02-autenticacao-e-acesso.md#perfis-de-usuário).

## Restrições

- Não é possível **excluir o próprio usuário logado**.
- Usuários com Tipo de Usuário = **"Externo"** exigem verificação por WhatsApp fora do IP autorizado. Ver [Autenticação e Acesso → 2FA](../02-autenticacao-e-acesso.md#verificação-de-acesso--2fa-por-whatsapp-verificar-acessophp).

---

Voltar ao [índice de Cadastros](../04-cadastros-comerciais.md).
