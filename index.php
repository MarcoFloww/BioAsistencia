<?php

require_once __DIR__ . '/includes/auth.php';

if (usuario_autenticado()) {
    header('Location: ' . app_url('pages/dashboard.php'));
    exit;
}

header('Location: ' . app_url('login.php'));
exit;