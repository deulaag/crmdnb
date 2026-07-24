-- ============================================================
-- DNB · CRM Comercial — schema MySQL
-- Compatível com MySQL 5.7+/8.0 e MariaDB 10.2+ (tipo JSON)
-- Importe este arquivo inteiro no phpMyAdmin do cPanel
-- (Bancos de Dados MySQL > phpMyAdmin > aba "Importar")
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------- usuários (login do sistema) ----------
CREATE TABLE IF NOT EXISTS usuarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(120) NOT NULL,
  email VARCHAR(160) NOT NULL UNIQUE,
  senha_hash VARCHAR(255) NOT NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- vendedores / suporte (pessoas e políticas de comissão) ----------
CREATE TABLE IF NOT EXISTS vendedores (
  id VARCHAR(20) PRIMARY KEY,
  nome VARCHAR(120) NOT NULL,
  email VARCHAR(160) DEFAULT '',
  fone VARCHAR(30) DEFAULT '',
  funcao ENUM('vendedor','suporte') NOT NULL DEFAULT 'vendedor',
  vinculo_id VARCHAR(20) DEFAULT NULL,
  orientador_id VARCHAR(20) DEFAULT NULL,
  politica VARCHAR(40) NOT NULL DEFAULT 'sem_politica',
  pol JSON NOT NULL,
  data_contratacao DATE DEFAULT NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- linhas de produto ----------
CREATE TABLE IF NOT EXISTS linhas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(120) NOT NULL UNIQUE,
  ordem INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- orçamentos / oportunidades (funil de vendas) ----------
CREATE TABLE IF NOT EXISTS orcamentos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  numero VARCHAR(40) DEFAULT '',
  data DATE NOT NULL,
  cliente VARCHAR(200) NOT NULL,
  vendedor_id VARCHAR(20) DEFAULT NULL,
  suporte_id VARCHAR(20) DEFAULT NULL,
  linha VARCHAR(120) DEFAULT '',
  tipo VARCHAR(40) DEFAULT '',
  funil VARCHAR(20) DEFAULT '',
  etapa VARCHAR(80) DEFAULT '',
  valor DECIMAL(14,2) NOT NULL DEFAULT 0,
  comissao_dnb_estimada DECIMAL(14,2) NOT NULL DEFAULT 0,
  status ENUM('Aberta','Ganha','Perdida') NOT NULL DEFAULT 'Aberta',
  origem VARCHAR(30) NOT NULL DEFAULT 'manual',
  sf_id VARCHAR(80) DEFAULT NULL,
  obs TEXT,
  cad_pausada TINYINT(1) NOT NULL DEFAULT 0,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_orc_vendedor (vendedor_id),
  KEY idx_orc_data (data),
  KEY idx_orc_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- histórico de toques de cadência por oportunidade (equivalente a cadLog[])
CREATE TABLE IF NOT EXISTS orcamento_cadencia_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  orcamento_id INT NOT NULL,
  data DATE NOT NULL,
  etapa VARCHAR(150) DEFAULT '',
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (orcamento_id) REFERENCES orcamentos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- pedidos de venda ----------
CREATE TABLE IF NOT EXISTS pedidos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  numero VARCHAR(40) DEFAULT '',
  data DATE NOT NULL,
  cliente VARCHAR(200) NOT NULL,
  vendedor_id VARCHAR(20) DEFAULT NULL,
  suporte_id VARCHAR(20) DEFAULT NULL,
  linha VARCHAR(120) DEFAULT '',
  valor DECIMAL(14,2) NOT NULL DEFAULT 0,
  margem DECIMAL(14,2) DEFAULT NULL,
  modalidade ENUM('revenda','agenciada') NOT NULL DEFAULT 'revenda',
  comissao_dnb DECIMAL(14,2) NOT NULL DEFAULT 0,
  faturado TINYINT(1) NOT NULL DEFAULT 0,
  data_fat DATE DEFAULT NULL,
  orcamento_id INT DEFAULT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ped_vendedor (vendedor_id),
  KEY idx_ped_datafat (data_fat)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- visitas / prospecções ----------
CREATE TABLE IF NOT EXISTS visitas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  os VARCHAR(40) DEFAULT '',
  data DATE NOT NULL,
  cliente VARCHAR(200) NOT NULL,
  vendedor_id VARCHAR(20) DEFAULT NULL,
  tipo ENUM('visita','prospeccao') NOT NULL DEFAULT 'visita',
  contato VARCHAR(160) DEFAULT '',
  setor VARCHAR(120) DEFAULT '',
  resumo TEXT,
  oportunidade TEXT,
  lead_status VARCHAR(20) DEFAULT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_vis_vendedor (vendedor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- apuração mensal (ocorrências operacionais lançadas pela diretoria) ----------
CREATE TABLE IF NOT EXISTS apuracao (
  id INT AUTO_INCREMENT PRIMARY KEY,
  mes CHAR(7) NOT NULL,
  vendedor_id VARCHAR(20) NOT NULL,
  ocorrencias INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_mes_vendedor (mes, vendedor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- SEED — linhas de produto
-- ============================================================
INSERT INTO linhas (nome, ordem) VALUES
 ('Compressores IR (agenciamento)', 1),
 ('Serviço Manutenção IR', 2),
 ('Locação', 3),
 ('Peças IR', 4),
 ('Bombas ARO', 5),
 ('Peças ARO', 6),
 ('Parker Automação', 7),
 ('Parker Filtração de Processos', 8),
 ('Parker Filtração GSF', 9),
 ('Parker Conexões', 10)
ON DUPLICATE KEY UPDATE ordem = VALUES(ordem);

-- ============================================================
-- SEED — vendedores/suporte com as políticas vigentes (jul/2026)
-- Os parâmetros de "pol" replicam os presets originais de cada política;
-- edite pela tela Configurações depois de instalado.
-- ============================================================
INSERT INTO vendedores (id, nome, email, fone, funcao, vinculo_id, orientador_id, politica, pol) VALUES
('v1','Hiago Azevedo','hiago@dnbe.com.br','75 99270-7879','vendedor',NULL,NULL,'consultor_senior',
 JSON_OBJECT('label','Consultor Comercial Sênior (v2 — anual)','regime','anual','metaPropriasAno',6300000,'metaOrientadoAno',3150000,'metaAgenciadasAno',2500000,'pctAgenciada',6,'tetoAgMes',8000,'tetoAgAno',60000,'tetoPropriasAno',60000,'tetoOrientAno',20000,'tetoGlobalAno',120000,'metaPropostasMes',3119792,'visitasMes',40,'prospecMes',0)),
('v2','Rogerio Viana','rogerio@dnbe.com.br','71 98151-1860','vendedor',NULL,NULL,'vendedor_ir',
 JSON_OBJECT('label','Vendedor IR (v3)','regime','mensal','metaRevenda',500000,'metaAgenciadas',300000,'pctPecasServ',0.5,'pctAgenciada',1,'visitasMes',60,'prospecMes',0,'convIR',40,'convEquip',20,'convDemais',15)),
('v3','Rafael Cerqueira','rafael.cerqueira@dnbe.com.br','71 3503-0263','vendedor',NULL,NULL,'vendedor_externo',
 JSON_OBJECT('label','Vendedor Externo (rev02 — jul/2026)','regime','mensal','metaRevenda',500000,'metaAgenciadas',300000,'pctPecasServ',0.5,'pctAgenciada',1,'bonifKpiPct',100,'visitasMes',40,'prospecMes',0,'convIR',40,'convEquip',20,'convDemais',15)),
('v4','João Fernando','fernando.barbosa@dnbe.com.br','71 98151-2058','vendedor',NULL,NULL,'vendedor_externo',
 JSON_OBJECT('label','Vendedor Externo (rev02 — jul/2026)','regime','mensal','metaRevenda',500000,'metaAgenciadas',300000,'pctPecasServ',0.5,'pctAgenciada',1,'bonifKpiPct',100,'visitasMes',40,'prospecMes',0,'convIR',40,'convEquip',20,'convDemais',15)),
('v5','Juliana Santos','juliana.santos@dnbe.com.br','71 3503-0263','suporte','v3',NULL,'suporte_ir',
 JSON_OBJECT('label','Suporte em Vendas IR (rev04 — jul/2026)','regime','mensal','metaRevenda',500000,'metaAgenciadas',300000,'pctPecasServ',0.25,'pctAgenciada',0.5,'contatosMin',100,'oportunidadesMin',20,'contasNovasMin',5,'visitasMes',0,'prospecMes',0,'convIR',40,'convEquip',20,'convDemais',15)),
('v6','Marilia Abreu','marilia.abreu@dnbe.com.br','71 98151-2128','suporte','v4',NULL,'suporte_ir',
 JSON_OBJECT('label','Suporte em Vendas IR (rev04 — jul/2026)','regime','mensal','metaRevenda',500000,'metaAgenciadas',300000,'pctPecasServ',0.25,'pctAgenciada',0.5,'contatosMin',100,'oportunidadesMin',20,'contasNovasMin',5,'visitasMes',0,'prospecMes',0,'convIR',40,'convEquip',20,'convDemais',15)),
('v7','Carlos Camerino','ceacamerino@gmail.com','71 99962-2079','vendedor',NULL,'v1','representante',
 JSON_OBJECT('label','Representante Comercial Autônomo (PCV-REP-001)','regime','mensal','metaRevenda',300000,'metaAgenciadas',0,'metaCompressores',200000,'metaAcessorios',100000,'pctComDNB',30,'pctMargem',5,'bonifKpiPct',20,'pipelineMin',1000000,'cotacoesMin',48,'convMin',25,'visitasMes',40,'prospecMes',0,'convIR',40,'convEquip',20,'convDemais',15)),
('v9','Denis Carvalho','','','vendedor',NULL,NULL,'interno',
 JSON_OBJECT('label','Vendedor Interno Sênior (PCV-INT-001)','regime','mensal','metaRevenda',500000,'metaAgenciadas',0,'pctPecasServ',0.5,'pctAgenciada',1,'tetoBonusMes',9600,'visitasMes',0,'prospecMes',40,'convIR',0,'convEquip',0,'convDemais',15,'pipelineMin',1700000,'cotacoesMin',80,'oportunidadesMin',60,'convMin',25)),
('v10','Felipe Maciel','vendas@dnbe.com.br','71 3503-0263','suporte',NULL,NULL,'suporte_ir',
 JSON_OBJECT('label','Suporte em Vendas IR (rev04 — jul/2026)','regime','mensal','metaRevenda',500000,'metaAgenciadas',300000,'pctPecasServ',0.25,'pctAgenciada',0.5,'contatosMin',100,'oportunidadesMin',20,'contasNovasMin',5,'visitasMes',0,'prospecMes',0,'convIR',40,'convEquip',20,'convDemais',15)),
('v11','Raquel','','','suporte',NULL,NULL,'suporte_ir',
 JSON_OBJECT('label','Suporte em Vendas IR (rev04 — jul/2026)','regime','mensal','metaRevenda',500000,'metaAgenciadas',300000,'pctPecasServ',0.25,'pctAgenciada',0.5,'contatosMin',100,'oportunidadesMin',20,'contasNovasMin',5,'visitasMes',0,'prospecMes',0,'convIR',40,'convEquip',20,'convDemais',15))
ON DUPLICATE KEY UPDATE nome = VALUES(nome);

-- Nenhum usuário de login é criado aqui por segurança.
-- Após subir o site, acesse /setup.php uma única vez para criar o primeiro administrador.
