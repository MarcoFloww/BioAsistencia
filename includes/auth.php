<?php

date_default_timezone_set('America/Lima');

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');

    $seguro = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $seguro,
        'httponly' => true,
        'samesite' => 'Strict'
    ]);

    session_start();
}

require_once __DIR__ . '/../config/conexion.php';

function iniciar_sesion(PDO $pdo, string $usuario, string $contrasena, bool $recordar = false): bool
{
    $usuario = trim($usuario);

    if ($usuario === '' || $contrasena === '') {
        return false;
    }

    $consulta = $pdo->prepare(
        "SELECT u.id_usuario,
                u.id_rol,
                u.usuario,
                u.contrasena,
                u.nombres,
                u.apellidos,
                u.correo,
                u.estado AS estado_usuario,
                r.nombre_rol,
                r.estado AS estado_rol
         FROM usuarios u
         INNER JOIN roles r ON u.id_rol = r.id_rol
         WHERE u.usuario = :usuario
         LIMIT 1"
    );

    $consulta->execute([
        'usuario' => $usuario
    ]);

    $fila = $consulta->fetch();

    if (!$fila) {
        return false;
    }

    if ($fila['estado_usuario'] !== 'Activo' || $fila['estado_rol'] !== 'Activo') {
        return false;
    }

    if (!password_verify($contrasena, $fila['contrasena'])) {
        return false;
    }

    session_regenerate_id(true);

    $_SESSION['id_usuario'] = (int) $fila['id_usuario'];
    $_SESSION['id_rol'] = (int) $fila['id_rol'];
    $_SESSION['usuario'] = $fila['usuario'];
    $_SESSION['nombres'] = $fila['nombres'];
    $_SESSION['apellidos'] = $fila['apellidos'];
    $_SESSION['correo'] = $fila['correo'];
    $_SESSION['rol'] = $fila['nombre_rol'];
    $_SESSION['inicio_sesion'] = time();

    $actualizar = $pdo->prepare(
        "UPDATE usuarios
         SET ultimo_acceso = NOW()
         WHERE id_usuario = :id_usuario"
    );

    $actualizar->execute([
        'id_usuario' => (int) $fila['id_usuario']
    ]);

    $seguro = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    if ($recordar) {
        setcookie('bioasistencia_usuario', $fila['usuario'], [
            'expires' => time() + 2592000,
            'path' => '/',
            'domain' => '',
            'secure' => $seguro,
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
    } else {
        setcookie('bioasistencia_usuario', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'domain' => '',
            'secure' => $seguro,
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
    }

    return true;
}

function cerrar_sesion(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $parametros = session_get_cookie_params();

        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $parametros['path'],
            'domain' => $parametros['domain'],
            'secure' => $parametros['secure'],
            'httponly' => $parametros['httponly'],
            'samesite' => $parametros['samesite'] ?? 'Strict'
        ]);
    }

    $seguro = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    setcookie('bioasistencia_usuario', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'domain' => '',
        'secure' => $seguro,
        'httponly' => true,
        'samesite' => 'Strict'
    ]);

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function usuario_autenticado(): bool
{
    return isset($_SESSION['id_usuario']) && (int) $_SESSION['id_usuario'] > 0;
}

function validar_sesion_actual(PDO $pdo): bool
{
    if (!usuario_autenticado()) {
        return false;
    }

    $idUsuario = (int) $_SESSION['id_usuario'];

    $consulta = $pdo->prepare(
        "SELECT u.id_usuario,
                u.id_rol,
                u.usuario,
                u.nombres,
                u.apellidos,
                u.correo,
                u.estado AS estado_usuario,
                r.nombre_rol,
                r.estado AS estado_rol
         FROM usuarios u
         INNER JOIN roles r ON u.id_rol = r.id_rol
         WHERE u.id_usuario = :id_usuario
         LIMIT 1"
    );

    $consulta->execute([
        'id_usuario' => $idUsuario
    ]);

    $fila = $consulta->fetch();

    if (
        !$fila
        || $fila['estado_usuario'] !== 'Activo'
        || $fila['estado_rol'] !== 'Activo'
    ) {
        cerrar_sesion();
        return false;
    }

    $_SESSION['id_usuario'] = (int) $fila['id_usuario'];
    $_SESSION['id_rol'] = (int) $fila['id_rol'];
    $_SESSION['usuario'] = $fila['usuario'];
    $_SESSION['nombres'] = $fila['nombres'];
    $_SESSION['apellidos'] = $fila['apellidos'];
    $_SESSION['correo'] = $fila['correo'];
    $_SESSION['rol'] = $fila['nombre_rol'];

    return true;
}

function requerir_sesion(): void
{
    global $pdo;

    if (!isset($pdo) || !($pdo instanceof PDO) || !validar_sesion_actual($pdo)) {
        header('Location: ' . app_url('login.php'));
        exit;
    }
}

function requerir_rol(array $rolesPermitidos): void
{
    requerir_sesion();

    $rolUsuario = obtener_rol_usuario();

    if (!in_array($rolUsuario, $rolesPermitidos, true)) {
        http_response_code(403);
        echo 'Acceso denegado. No tienes permisos para ingresar a esta sección.';
        exit;
    }
}

function usuario_actual(): ?array
{
    if (!usuario_autenticado()) {
        return null;
    }

    return [
        'id_usuario' => $_SESSION['id_usuario'] ?? null,
        'id_rol' => $_SESSION['id_rol'] ?? null,
        'usuario' => $_SESSION['usuario'] ?? '',
        'nombres' => $_SESSION['nombres'] ?? '',
        'apellidos' => $_SESSION['apellidos'] ?? '',
        'correo' => $_SESSION['correo'] ?? '',
        'rol' => $_SESSION['rol'] ?? ''
    ];
}

function obtener_rol_usuario(): string
{
    return $_SESSION['rol'] ?? '';
}

function obtener_nombre_usuario(): string
{
    $nombres = $_SESSION['nombres'] ?? '';
    $apellidos = $_SESSION['apellidos'] ?? '';

    $nombreCompleto = trim($nombres . ' ' . $apellidos);

    if ($nombreCompleto !== '') {
        return $nombreCompleto;
    }

    return $_SESSION['usuario'] ?? 'Usuario';
}

function generar_token_csrf(): string
{
    if (
        !isset($_SESSION['csrf_token'])
        || !is_string($_SESSION['csrf_token'])
        || strlen($_SESSION['csrf_token']) < 64
    ) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function validar_token_csrf(?string $token): bool
{
    if (
        !isset($_SESSION['csrf_token'])
        || !is_string($_SESSION['csrf_token'])
        || $token === null
        || $token === ''
    ) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}

function requerir_token_csrf(?string $token): void
{
    if (!validar_token_csrf($token)) {
        http_response_code(403);
        exit('Solicitud no válida. Recarga la página e inténtalo nuevamente.');
    }
}