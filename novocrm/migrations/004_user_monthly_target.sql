-- Meta mensal de faturamento por pessoa (editável por admin/gestor). NULL = sem meta.
ALTER TABLE users ADD COLUMN monthly_target DECIMAL(15,2) NULL;
