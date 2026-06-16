CREATE DATABASE IF NOT EXISTS sis_ped CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sis_ped;

CREATE TABLE IF NOT EXISTS ncm (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome_categoria VARCHAR(100),
    ncm VARCHAR(20),
    cest VARCHAR(20),
    ipi DECIMAL(10,4) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS canal_venda (
    id INT AUTO_INCREMENT PRIMARY KEY,
    canal VARCHAR(100) NOT NULL,
    faixa_faturamento VARCHAR(100),
    desconto DECIMAL(10,4) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS produtos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo_produto VARCHAR(50) UNIQUE,
    linha VARCHAR(50),
    grupo VARCHAR(50),
    subgrupo VARCHAR(50),
    codigo_barra VARCHAR(50),
    descricao_pt VARCHAR(200),
    descricao_en VARCHAR(200),
    descricao_es VARCHAR(200),
    desc_cliente_pt TEXT,
    desc_cliente_en TEXT,
    desc_cliente_es TEXT,
    nuance VARCHAR(50),
    multiplo INT DEFAULT 1,
    vendas_distribuidor DECIMAL(10,2) DEFAULT 0,
    vendas_varejo DECIMAL(10,2) DEFAULT 0,
    vendas_exportacao DECIMAL(10,2) DEFAULT 0,
    ncm_id INT,
    cest VARCHAR(20),
    status ENUM('ativo','inativo') DEFAULT 'ativo',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ncm_id) REFERENCES ncm(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS tabela_precos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    produto_id INT NOT NULL,
    preco_padrao DECIMAL(10,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS clientes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo_cliente VARCHAR(50),
    cnpj VARCHAR(20),
    cpf VARCHAR(15),
    razao_social VARCHAR(200) NOT NULL,
    cep VARCHAR(10),
    endereco VARCHAR(200),
    numero VARCHAR(10),
    complemento VARCHAR(100),
    bairro VARCHAR(100),
    cidade VARCHAR(100),
    estado VARCHAR(2),
    pais VARCHAR(50) DEFAULT 'Brasil',
    telefone1 VARCHAR(20),
    telefone2 VARCHAR(20),
    email VARCHAR(100) NOT NULL UNIQUE,
    supervisor VARCHAR(100),
    canal_venda_id INT,
    material_apoio TINYINT DEFAULT 0,
    bonus_desempenho DECIMAL(10,2) DEFAULT 0,
    desconto_cliente DECIMAL(10,4) DEFAULT 0,
    limite_credito DECIMAL(12,2) DEFAULT 0,
    idioma VARCHAR(20) DEFAULT 'pt',
    moeda VARCHAR(10) DEFAULT 'BRL',
    pedido_accademia TINYINT DEFAULT 0,
    senha VARCHAR(255) NOT NULL,
    status ENUM('ativo','inativo') DEFAULT 'ativo',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (canal_venda_id) REFERENCES canal_venda(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(20),
    nome VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    senha VARCHAR(255) NOT NULL,
    departamento VARCHAR(50),
    divisao_vendas VARCHAR(50),
    tipo_acesso ENUM('comercial','financeiro','supervisor','tecnologia da informacao','recursos humanos','marketing','diretoria','centro tecnico','contabilidade','recepcao','expedicao') NOT NULL DEFAULT 'comercial',
    tipo_usuario VARCHAR(50),
    celular VARCHAR(20),
    ramal VARCHAR(10),
    status ENUM('ativo','inativo') DEFAULT 'ativo',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS campanhas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo_campanha VARCHAR(50),
    produto_id INT,
    linha VARCHAR(50),
    grupo VARCHAR(50),
    subgrupo VARCHAR(50),
    quantidade INT DEFAULT 0,
    desconto DECIMAL(10,4) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS metas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT,
    trimestre TINYINT,
    ano YEAR,
    meta_cliente DECIMAL(12,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS pedidos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    numero_pedido VARCHAR(50) UNIQUE,
    tipo_venda ENUM('venda','bonificacao') DEFAULT 'venda',
    data_pedido DATE,
    cliente_id INT,
    produto_id INT,
    supervisor VARCHAR(100),
    codigo_barra VARCHAR(50),
    descricao_produto VARCHAR(200),
    quantidade_total INT DEFAULT 0,
    valor_total DECIMAL(12,2) DEFAULT 0,
    status ENUM('comercial','financeiro','faturamento','faturado','cancelado','reprovado') DEFAULT 'comercial',
    observacoes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE SET NULL,
    FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS contas_receber (
    id INT AUTO_INCREMENT PRIMARY KEY,
    numero_documento VARCHAR(50),
    cliente_id INT,
    valor_receber DECIMAL(12,2) DEFAULT 0,
    descontos DECIMAL(12,2) DEFAULT 0,
    data_emissao DATE,
    data_vencimento DATE,
    data_pagamento DATE,
    situacao ENUM('aberto','pago','vencido','cancelado') DEFAULT 'aberto',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS fornecedores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(50),
    razao_social VARCHAR(200) NOT NULL,
    cnpj VARCHAR(20),
    email VARCHAR(100),
    telefone VARCHAR(20),
    cidade VARCHAR(100),
    estado VARCHAR(2),
    status ENUM('ativo','inativo') DEFAULT 'ativo',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS contas_pagar (
    id INT AUTO_INCREMENT PRIMARY KEY,
    numero_documento VARCHAR(50),
    fornecedor_id INT,
    valor_pagar DECIMAL(12,2) DEFAULT 0,
    descontos DECIMAL(12,2) DEFAULT 0,
    juros DECIMAL(12,2) DEFAULT 0,
    data_emissao DATE,
    data_vencimento DATE,
    data_pagamento DATE,
    situacao ENUM('aberto','pago','vencido','cancelado') DEFAULT 'aberto',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (fornecedor_id) REFERENCES fornecedores(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS ordens_pagamento (
    id INT AUTO_INCREMENT PRIMARY KEY,
    numero_ordem VARCHAR(50),
    descricao VARCHAR(200),
    valor DECIMAL(12,2) DEFAULT 0,
    data_ordem DATE,
    status ENUM('pendente','aprovado','cancelado') DEFAULT 'pendente',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ordens_investimento (
    id INT AUTO_INCREMENT PRIMARY KEY,
    numero_ordem VARCHAR(50),
    descricao VARCHAR(200),
    valor DECIMAL(12,2) DEFAULT 0,
    data_ordem DATE,
    retorno_esperado DECIMAL(12,2) DEFAULT 0,
    status ENUM('pendente','aprovado','cancelado') DEFAULT 'pendente',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Dados de exemplo
INSERT IGNORE INTO ncm (nome_categoria, ncm, cest, ipi) VALUES
('Cosméticos', '3305.10.00', '20.029.00', 0),
('Tintas para Cabelo', '3305.90.00', '20.029.01', 5),
('Condicionadores', '3305.20.00', '20.029.02', 0);

INSERT IGNORE INTO canal_venda (canal, faixa_faturamento, desconto) VALUES
('Distribuidor', 'Acima de R$ 50.000', 15.00),
('Varejo', 'Até R$ 50.000', 8.00),
('Exportação', 'Internacional', 10.00);

INSERT IGNORE INTO produtos (codigo_produto, linha, grupo, subgrupo, codigo_barra, descricao_pt, descricao_en, descricao_es, nuance, multiplo, vendas_distribuidor, vendas_varejo, vendas_exportacao, ncm_id, status) VALUES
('PRD001', 'Coloração', 'Tintas', 'Permanente', '7891234560001', 'Tinta Profissional 7.0', 'Professional Color 7.0', 'Tinta Profesional 7.0', 'Louro Médio', 1, 45.00, 65.00, 50.00, 2, 'ativo'),
('PRD002', 'Tratamento', 'Máscaras', 'Hidratação', '7891234560002', 'Máscara Hidratante 500g', 'Hydrating Mask 500g', 'Mascarilla Hidratante 500g', NULL, 1, 35.00, 52.00, 40.00, 3, 'ativo'),
('PRD003', 'Finalização', 'Géis', 'Modelador', '7891234560003', 'Gel Modelador 200ml', 'Modeling Gel 200ml', 'Gel Modelador 200ml', NULL, 6, 18.00, 28.00, 22.00, 1, 'ativo');

INSERT IGNORE INTO tabela_precos (produto_id, preco_padrao) VALUES
(1, 45.00),
(2, 35.00),
(3, 18.00);

INSERT IGNORE INTO clientes (codigo_cliente, cnpj, razao_social, cep, endereco, numero, cidade, estado, telefone1, email, supervisor, canal_venda_id, desconto_cliente, limite_credito, idioma, moeda, senha, status) VALUES
('CLI001', '12.345.678/0001-90', 'Distribuidora Beleza Ltda', '01310-100', 'Av. Paulista', '1000', 'São Paulo', 'SP', '(11) 3000-0001', 'cliente@teste.com', 'João Supervisor', 1, 5.00, 50000.00, 'pt', 'BRL', '123', 'ativo'),
('CLI002', '98.765.432/0001-10', 'Salão Top Style', '20040-020', 'Rua da Quitanda', '50', 'Rio de Janeiro', 'RJ', '(21) 3000-0002', 'salao@teste.com', 'Maria Supervisora', 2, 3.00, 20000.00, 'pt', 'BRL', '123', 'ativo');

INSERT IGNORE INTO usuarios (codigo, nome, email, senha, departamento, divisao_vendas, tipo_acesso, tipo_usuario, celular, status) VALUES
('USR001', 'Comercial Teste', 'comercial@teste.com', '123', 'Comercial', 'Nacional', 'comercial', 'Interno', '(11) 9000-0001', 'ativo'),
('USR002', 'Financeiro Teste', 'financeiro@teste.com', '123', 'Financeiro', NULL, 'financeiro', 'Interno', '(11) 9000-0002', 'ativo');

INSERT IGNORE INTO pedidos (numero_pedido, tipo_venda, data_pedido, cliente_id, produto_id, supervisor, codigo_barra, descricao_produto, quantidade_total, valor_total, status) VALUES
('PED-2024-001', 'venda', '2024-01-15', 1, 1, 'João Supervisor', '7891234560001', 'Tinta Profissional 7.0', 10, 450.00, 'comercial'),
('PED-2024-002', 'venda', '2024-01-16', 2, 2, 'Maria Supervisora', '7891234560002', 'Máscara Hidratante 500g', 20, 700.00, 'financeiro'),
('PED-2024-003', 'bonificacao', '2024-01-17', 1, 3, 'João Supervisor', '7891234560003', 'Gel Modelador 200ml', 6, 0.00, 'faturado'),
('PED-2024-004', 'venda', '2024-02-01', 2, 1, 'Maria Supervisora', '7891234560001', 'Tinta Profissional 7.0', 5, 325.00, 'comercial');

INSERT IGNORE INTO contas_receber (numero_documento, cliente_id, valor_receber, data_emissao, data_vencimento, situacao) VALUES
('NF-001', 1, 450.00, '2024-01-15', '2024-02-15', 'pago'),
('NF-002', 2, 700.00, '2024-01-16', '2024-02-16', 'aberto'),
('NF-003', 1, 325.00, '2024-02-01', '2024-03-01', 'aberto');

INSERT IGNORE INTO fornecedores (codigo, razao_social, cnpj, email, telefone, cidade, estado, status) VALUES
('FOR001', 'Distribuidora de Insumos SA', '11.222.333/0001-44', 'contato@insumos.com', '(11) 4000-0001', 'São Paulo', 'SP', 'ativo'),
('FOR002', 'Embalagens Brasil Ltda', '55.666.777/0001-88', 'contato@embalagens.com', '(11) 4000-0002', 'Campinas', 'SP', 'ativo');

INSERT IGNORE INTO contas_pagar (numero_documento, fornecedor_id, valor_pagar, data_emissao, data_vencimento, situacao) VALUES
('CP-001', 1, 15000.00, '2024-01-10', '2024-02-10', 'pago'),
('CP-002', 2, 8000.00, '2024-01-20', '2024-02-20', 'aberto');

INSERT IGNORE INTO campanhas (codigo_campanha, produto_id, linha, grupo, subgrupo, quantidade, desconto) VALUES
('CAMP-2024-01', 1, 'Coloração', 'Tintas', 'Permanente', 10, 10.00),
('CAMP-2024-02', 2, 'Tratamento', 'Máscaras', 'Hidratação', 5, 15.00);

INSERT IGNORE INTO metas (cliente_id, trimestre, ano, meta_cliente) VALUES
(1, 1, 2024, 30000.00),
(1, 2, 2024, 35000.00),
(2, 1, 2024, 15000.00),
(2, 2, 2024, 18000.00);

INSERT IGNORE INTO ordens_pagamento (numero_ordem, descricao, valor, data_ordem, status) VALUES
('OP-001', 'Pagamento Fornecedor Insumos', 15000.00, '2024-02-10', 'aprovado'),
('OP-002', 'Pagamento Salários Janeiro', 45000.00, '2024-01-31', 'aprovado');

INSERT IGNORE INTO ordens_investimento (numero_ordem, descricao, valor, data_ordem, retorno_esperado, status) VALUES
('OI-001', 'Compra de Equipamentos', 80000.00, '2024-01-05', 120000.00, 'aprovado'),
('OI-002', 'Marketing Digital Q1', 25000.00, '2024-01-10', 40000.00, 'pendente');
