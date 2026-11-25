<?php
declare(strict_types=1);

/**
 * NOVOCRM — instalador do banco (atalho para o executor de migrações).
 *
 * Abra /novocrm/install.php logado como admin e clique em "Aplicar pendentes".
 * Cria/altera apenas o que falta (nc_migrations, order_items, position, portfolio_status, monthly_target).
 * É idempotente: pode abrir e aplicar quantas vezes quiser.
 */
require __DIR__ . '/migrations/run.php';
