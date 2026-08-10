-- DNB CRM v3.3.0 — Múltiplos cargos, pessoas e comissão por cargo
-- Execute uma única vez no phpMyAdmin do cPanel, com backup prévio do banco.
-- Pré-requisitos: 20260809_crm_ia_rbac.sql, 20260809_whatsapp_rbac.sql e 20260809_followup_engine.sql.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS cargos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(120) NOT NULL,
  has_commission TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cargos_title (title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO cargos (title,has_commission) VALUES
 ('ADMINISTRADOR',0),
 ('SUPERVISOR',1),
 ('Representante Comercial Autônomo',1),
 ('Vendedor Interno Sênior',1),
 ('Vendedor Externo',1),
 ('Vendedor JR',1),
 ('Consultor Comercial Sênior',1),
 ('Suporte em Vendas JR',1)
ON DUPLICATE KEY UPDATE has_commission=VALUES(has_commission);

CREATE TABLE IF NOT EXISTS user_cargos (
  user_id INT NOT NULL,
  cargo_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id,cargo_id),
  KEY idx_user_cargos_cargo (cargo_id,user_id),
  CONSTRAINT fk_user_cargos_user FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_cargos_cargo FOREIGN KEY (cargo_id) REFERENCES cargos(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP PROCEDURE IF EXISTS dnb_cargos_add_column;
DELIMITER $$
CREATE PROCEDURE dnb_cargos_add_column(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_definition TEXT)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=p_table AND COLUMN_NAME=p_column
  ) THEN
    SET @ddl = CONCAT('ALTER TABLE `',p_table,'` ADD COLUMN `',p_column,'` ',p_definition);
    PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
  END IF;
END$$
DELIMITER ;

CALL dnb_cargos_add_column('usuarios','pessoa_id','VARCHAR(20) DEFAULT NULL AFTER vendedor_id');
CALL dnb_cargos_add_column('orcamentos','cargo_id','INT DEFAULT NULL AFTER assigned_to_user_id');
CALL dnb_cargos_add_column('pedidos','cargo_id','INT DEFAULT NULL AFTER assigned_to_user_id');
DROP PROCEDURE dnb_cargos_add_column;

-- Vincula usuários já existentes às pessoas legadas sem substituir credenciais.
UPDATE usuarios u
JOIN vendedores v ON v.id=u.vendedor_id
SET u.pessoa_id=v.id
WHERE u.pessoa_id IS NULL;

UPDATE usuarios u
JOIN vendedores v ON LOWER(TRIM(v.email))=LOWER(TRIM(u.email)) AND TRIM(v.email)<>''
LEFT JOIN usuarios u2 ON u2.pessoa_id=v.id AND u2.id<>u.id
SET u.pessoa_id=v.id,u.vendedor_id=COALESCE(u.vendedor_id,v.id)
WHERE u.pessoa_id IS NULL AND u2.id IS NULL;

-- Pessoas sem login tornam-se usuários inativos; o Administrador poderá definir
-- senha e ativá-los depois. O hash sentinela nunca autentica com password_verify.
INSERT INTO usuarios (nome,email,senha_hash,ativo,criado_em,role,supervisor_id,vendedor_id,pessoa_id,status,atualizado_em)
SELECT v.nome,
       CASE WHEN TRIM(v.email)<>'' AND NOT EXISTS (SELECT 1 FROM usuarios ue WHERE LOWER(ue.email)=LOWER(v.email))
            THEN LOWER(TRIM(v.email))
            ELSE CONCAT('pessoa+',LOWER(SUBSTRING(SHA2(v.id,256),1,16)),'@dnb.local.invalid') END,
       CONCAT('!migrated-',SHA2(CONCAT(v.id,':dnb-cargos-v3.3'),256)),
       0,NOW(),
       CASE WHEN v.funcao='suporte' THEN 'SUPORTE' ELSE 'VENDEDOR' END,
       NULL,v.id,v.id,'inactive',NOW()
FROM vendedores v
WHERE NOT EXISTS (
  SELECT 1 FROM usuarios u WHERE u.pessoa_id=v.id OR u.vendedor_id=v.id
);

UPDATE usuarios SET vendedor_id=COALESCE(vendedor_id,pessoa_id) WHERE pessoa_id IS NOT NULL;

-- Migra o papel único para cargos. A política comercial escolhe o cargo mais
-- próximo, preservando a hierarquia e os registros existentes.
INSERT IGNORE INTO user_cargos (user_id,cargo_id)
SELECT u.id,c.id FROM usuarios u JOIN cargos c ON c.title='ADMINISTRADOR' WHERE u.role='ADMINISTRADOR';

INSERT IGNORE INTO user_cargos (user_id,cargo_id)
SELECT u.id,c.id FROM usuarios u JOIN cargos c ON c.title='SUPERVISOR' WHERE u.role='SUPERVISOR';

INSERT IGNORE INTO user_cargos (user_id,cargo_id)
SELECT u.id,c.id
FROM usuarios u LEFT JOIN vendedores v ON v.id=COALESCE(u.pessoa_id,u.vendedor_id)
JOIN cargos c ON c.title='Suporte em Vendas JR'
WHERE u.role='SUPORTE' OR v.funcao='suporte';

INSERT IGNORE INTO user_cargos (user_id,cargo_id)
SELECT u.id,c.id
FROM usuarios u LEFT JOIN vendedores v ON v.id=COALESCE(u.pessoa_id,u.vendedor_id)
JOIN cargos c ON c.title=CASE
  WHEN v.politica='representante' THEN 'Representante Comercial Autônomo'
  WHEN v.politica='interno' THEN 'Vendedor Interno Sênior'
  WHEN v.politica='vendedor_externo' THEN 'Vendedor Externo'
  WHEN v.politica='consultor_senior' THEN 'Consultor Comercial Sênior'
  ELSE 'Vendedor JR' END
WHERE u.role='VENDEDOR' AND COALESCE(v.funcao,'vendedor')<>'suporte';

INSERT IGNORE INTO user_cargos (user_id,cargo_id)
SELECT u.id,c.id FROM usuarios u
JOIN cargos c ON c.title=CASE u.role
  WHEN 'ADMINISTRADOR' THEN 'ADMINISTRADOR'
  WHEN 'SUPERVISOR' THEN 'SUPERVISOR'
  WHEN 'SUPORTE' THEN 'Suporte em Vendas JR'
  ELSE 'Vendedor JR' END
WHERE NOT EXISTS (SELECT 1 FROM user_cargos uc WHERE uc.user_id=u.id);

-- Atualiza atribuições históricas e grava o cargo efetivo. Administradores
-- permanecem sempre não comissionáveis, mesmo se acumularem outro cargo.
UPDATE orcamentos o JOIN usuarios u ON COALESCE(u.pessoa_id,u.vendedor_id)=o.vendedor_id
SET o.assigned_to_user_id=u.id WHERE o.assigned_to_user_id IS NULL;

UPDATE pedidos p JOIN usuarios u ON COALESCE(u.pessoa_id,u.vendedor_id)=p.vendedor_id
SET p.assigned_to_user_id=u.id WHERE p.assigned_to_user_id IS NULL;

UPDATE orcamentos o JOIN usuarios u ON u.id=o.assigned_to_user_id
SET o.cargo_id=COALESCE(
  (SELECT MIN(ca.id) FROM user_cargos uca JOIN cargos ca ON ca.id=uca.cargo_id WHERE uca.user_id=u.id AND ca.title='ADMINISTRADOR'),
  (SELECT MIN(cc.id) FROM user_cargos ucc JOIN cargos cc ON cc.id=ucc.cargo_id WHERE ucc.user_id=u.id AND cc.has_commission=1)
)
WHERE o.cargo_id IS NULL;

UPDATE pedidos p LEFT JOIN orcamentos o ON o.id=p.orcamento_id
SET p.cargo_id=o.cargo_id
WHERE p.cargo_id IS NULL AND o.cargo_id IS NOT NULL;

UPDATE pedidos p JOIN usuarios u ON u.id=p.assigned_to_user_id
SET p.cargo_id=COALESCE(
  (SELECT MIN(ca.id) FROM user_cargos uca JOIN cargos ca ON ca.id=uca.cargo_id WHERE uca.user_id=u.id AND ca.title='ADMINISTRADOR'),
  (SELECT MIN(cc.id) FROM user_cargos ucc JOIN cargos cc ON cc.id=ucc.cargo_id WHERE ucc.user_id=u.id AND cc.has_commission=1)
)
WHERE p.cargo_id IS NULL;

DROP PROCEDURE IF EXISTS dnb_cargos_add_index;
DELIMITER $$
CREATE PROCEDURE dnb_cargos_add_index(IN p_table VARCHAR(64), IN p_index VARCHAR(64), IN p_columns VARCHAR(255), IN p_unique TINYINT)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=p_table AND INDEX_NAME=p_index
  ) THEN
    SET @ddl=CONCAT('ALTER TABLE `',p_table,'` ADD ',IF(p_unique=1,'UNIQUE ',''),'INDEX `',p_index,'` (',p_columns,')');
    PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
  END IF;
END$$
DELIMITER ;
CALL dnb_cargos_add_index('usuarios','uq_usuarios_pessoa','`pessoa_id`',1);
CALL dnb_cargos_add_index('orcamentos','idx_orc_cargo','`cargo_id`',0);
CALL dnb_cargos_add_index('pedidos','idx_ped_cargo','`cargo_id`',0);
DROP PROCEDURE dnb_cargos_add_index;

DROP PROCEDURE IF EXISTS dnb_cargos_add_fk;
DELIMITER $$
CREATE PROCEDURE dnb_cargos_add_fk(IN p_table VARCHAR(64), IN p_constraint VARCHAR(64), IN p_definition TEXT)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=p_table AND CONSTRAINT_NAME=p_constraint
  ) THEN
    SET @ddl=CONCAT('ALTER TABLE `',p_table,'` ADD CONSTRAINT `',p_constraint,'` ',p_definition);
    PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
  END IF;
END$$
DELIMITER ;
CALL dnb_cargos_add_fk('usuarios','fk_usuarios_pessoa','FOREIGN KEY (`pessoa_id`) REFERENCES `vendedores`(`id`) ON DELETE SET NULL');
CALL dnb_cargos_add_fk('orcamentos','fk_orcamentos_cargo','FOREIGN KEY (`cargo_id`) REFERENCES `cargos`(`id`) ON DELETE SET NULL');
CALL dnb_cargos_add_fk('pedidos','fk_pedidos_cargo','FOREIGN KEY (`cargo_id`) REFERENCES `cargos`(`id`) ON DELETE SET NULL');
DROP PROCEDURE dnb_cargos_add_fk;

SET FOREIGN_KEY_CHECKS = 1;
