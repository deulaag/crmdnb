<?php
declare(strict_types=1);

require_once __DIR__ . '/_app.php';

nc_boot();
redirect(nc_url('painel.php'));
