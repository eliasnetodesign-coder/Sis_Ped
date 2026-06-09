Requisitos
    PHP 7.4
    XAMP 7.4

-Páginas
Página Area de Pedidos
    Login (por e-mail)
    Senha (123)
        Módulos de Cliente
        1-Dashboard
        2-Novo Pedido
        3-Meus Pedidos
        4-Financeiro

Página Administrativa
    Login (por e-mail)
    Senha (123)
    1-Comercial
        1.1-(Permite Criar e Consultar dados dos bancos de todos sub-modulos)
            -Cadastro de Produtos
            -Cadastro de Clientes
            -Cadastro de Tabela de Preços
            -Cadastro de Campanhas
            -Cadastro de Canal de Venda
            -Cadastro de NCM
            -Cadastro de Metas
            -Bonus de Desempenho
            -Bonus de Material de Apoio
            -Concessão de Créditos

        1.2-Relatórios
            Status dos Pedidos (Mostrar o que está no Comercial/Financeiro/Faturamento)
            -Faturamento Diario (Faturados)
            -Faturamento Mensal (Faturados)
            -Faturamento Anual (Faturados)
            -Faturamento por Cliente (Faturados)
            -Faturamento por Canal de Vendas (Faturados)
            -Faturamento por Vendedor (Faturados)
            -Faturamento por Estado (Faturados)
            -Faturamento por Região (Centro/Norte/Nordeste/Sul/Sudeste) (Faturados)

    2-Financeiro
        2.1-Cadastros (Permite Criar e Consultar dados dos bancos de todos sub-modulos)
            -Cadastro de Clientes
            -Cadastros de Fornecedores
            -Contas a Receber
            -Contas a Pagar
            -Ordens de Pagamento
            -Ordens de Investimento

    3-Administração
        1.1-Cadastros
            -Cadastro de Usuários

-Banco de Dados
Pedidos
    Numero do Pedido
    Tipo de Venda (Venda/Bonificação)
    Data do Pedido
    Vendedor do Pedido
    Codigo do Produto
    Codigo de Barra
    Descrição do Produto
    Quantidade Total
    Valor Total
    Status (Aguardando Comercial/Aguardando Financeiro/Aguardando Faturamento/Faturado/Cancelado)

Cadastro de Produtos
    Codigo do Produto - A
    Linha - Q
    Grupo - O
    Subgrupo - P
    Codigo de Barra - E
    Descrição Portugues - B
    Descrição Inglês - 
    Descrição Espanhol - 
    Nuance - 
    Multiplo - BF
    Vendas para Distribuidor - (No Cadastro mostrar lista com Sim ou  Não para selecionar)
    Vendas para Varejo - (No Cadastro mostrar lista com Sim ou  Não para selecionar)
    Vendas para Exportação - (No Cadastro mostrar lista com Sim ou  Não para selecionar)
    NCM - AO
    CEST - CF
    Status - C

Tabela de Preços
    Codigo do Produto
    Preço Padrão

Campanha de Produtos
    Código da Campanha
    Codigo do Produto
    Linha
    Grupo
    Subgrupo
    Quantidade
    Desconto

Canal de Venda
    Canal
    Faixa de Faturamento
    Desconto

Cadastro de Clientes (Acessam a Página Area de Pedidos com e-mail e senha)
    Codigo de Cliente - A
    CNPJ - E
    CPF - 
    Razão Social - C
    CEP - P
    Endereço - H
    Numero - I
    Complemento - J
    Bairro - K
    Cidade - L
    Estado - N
    País - O
    Telefone 1 - Q
    Telefone 2
    E-mail do Cliente - Y
    Vendedor - AC
    Canal de Venda
    Material de Apoio
    Bonus Desempenho
    Desconto do Cliente
    Limite de Crédito
    Idioma
    Moeda
    Pedido Accademia
    Senha do Cliente
    Status - BW

Cadastro de Usuários (Acessam a Página Administrativa com e-mail e senha)
    Codigo do Usuário
    Nome do Usuário
    Senha do Usuário
    Departamento
    Divisão de Vendas
    E-mail do Usuário
    Tipo de Acesso
        (Criar Lista com opções)
        Comercial
        Financeiro
    Tipo de Usuário
    Telefone
    Ramal
    Status

Cadastro de A Receber
    Numero do Documento
    Codigo do Cliente
    Valor A Receber
    Descontos
    Data da Emissão
    Data do Vencimento
    Data do Pagamento
    Situação do Titulo

Cadastro de A Pagar
    Numero do Documento
    Codigo do Fornecedor
    Valor A Receber
    Descontos
    Juros
    Data da Emissão
    Data do Vencimento
    Data do Pagamento
    Situação do Titulo

Cadastro de NCM
    Nome da Categoria
    NCM
    CEST
    IPI
    
Cadastro de Metas
    Codigo de Cliente
    Trimestre
    Ano
    Meta do Cliente

-Fluxo de Aprovações
    -Se "Tipo de Acesso" de "Cadastro de Usuário = Comercial
        -Permite Aprovar e Reprovar Pedidos
    -Se "Tipo de Acesso" de "Cadastro de Usuário = Financeiro
        -Permite Aprovar, Reprovar Pedidos e Retornar para o Comercial os pedidos