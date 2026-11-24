-- Ordem manual dos cards dentro da coluna do kanban. NULL = ordenar por updated_at DESC.
ALTER TABLE opportunities ADD COLUMN position INT NULL;
