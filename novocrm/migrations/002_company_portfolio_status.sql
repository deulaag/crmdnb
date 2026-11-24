-- Status manual da carteira (ativo | risco | inativo). NULL = calculado pelo último pedido.
ALTER TABLE companies ADD COLUMN portfolio_status VARCHAR(20) NULL;
