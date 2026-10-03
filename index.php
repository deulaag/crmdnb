<?php
require_once __DIR__ . '/includes/auth.php';
redirect(current_user() ? 'painel.php' : 'login.php');
