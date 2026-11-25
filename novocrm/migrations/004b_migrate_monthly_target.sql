-- Copia users.monthly_target (migração 004_user_monthly_target) para sales_targets, no mês corrente.
-- A coluna users.monthly_target NÃO é removida. Depois disso o NOVOCRM lê metas só de sales_targets.
-- Idempotente: só insere se ainda não houver meta do vendedor para o mês corrente.
INSERT INTO sales_targets (user_id, year, month, amount, updated_by)
SELECT u.id, YEAR(CURDATE()), MONTH(CURDATE()), u.monthly_target, NULL
FROM users u
WHERE u.monthly_target IS NOT NULL
AND NOT EXISTS (SELECT 1 FROM sales_targets t WHERE t.user_id = u.id AND t.year = YEAR(CURDATE()) AND t.month = MONTH(CURDATE()));
