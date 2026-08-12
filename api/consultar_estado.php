<?php

date_default_timezone_set('America/Lima');

require_once __DIR__ . '/../config/conexion.php';

header('Content-Type: application/json; charset=utf-8');

function responder_estado_dispositivo(array $datos, int $codigoHttp = 200): void
{
    http_response_code($codigoHttp);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function asegurar_tabla_estado_dispositivo(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS estado_dispositivo (
            id_estado INT AUTO_INCREMENT PRIMARY KEY,
            estado_biometrico VARCHAR(50) NOT NULL DEFAULT 'Estado Apagado',
            estado_sensor VARCHAR(50) NOT NULL DEFAULT 'Apagado',
            estado_wifi VARCHAR(50) NOT NULL DEFAULT 'Desconectado',
            mensaje VARCHAR(255) NULL,
            fecha_actualizacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

try {
    asegurar_tabla_estado_dispositivo($pdo);

    $consulta = $pdo->query(
        "SELECT estado_biometrico,
                estado_sensor,
                estado_wifi,
                mensaje,
                fecha_actualizacion,
                TIMESTAMPDIFF(SECOND, fecha_actualizacion, NOW()) AS segundos_sin_senal
         FROM estado_dispositivo
         ORDER BY id_estado DESC
         LIMIT 1"
    );

    $estado = $consulta->fetch();

    if (!$estado) {
        responder_estado_dispositivo([
            'estado' => 'Estado Apagado',
            'estado_biometrico' => 'Estado Apagado',
            'clase' => 'estado-apagado',
            'mensaje' => 'Dispositivo biométrico sin actividad',
            'segundos_sin_senal' => null
        ]);
    }

    $segundosSinSenal = (int) ($estado['segundos_sin_senal'] ?? 9999);
    $estaActivo = $estado['estado_biometrico'] === 'Sistema Activo' && $segundosSinSenal <= 300;

    if ($estaActivo) {
        responder_estado_dispositivo([
            'estado' => 'Sistema Activo',
            'estado_biometrico' => 'Sistema Activo',
            'estado_sensor' => 'Activo',
            'estado_wifi' => 'Conectado',
            'clase' => 'sistema-activo',
            'mensaje' => $estado['mensaje'] ?? 'Dispositivo biométrico activo',
            'fecha_actualizacion' => $estado['fecha_actualizacion'],
            'segundos_sin_senal' => $segundosSinSenal
        ]);
    }

    responder_estado_dispositivo([
        'estado' => 'Estado Apagado',
        'estado_biometrico' => 'Estado Apagado',
        'estado_sensor' => 'Apagado',
        'estado_wifi' => 'Desconectado',
        'clase' => 'estado-apagado',
        'mensaje' => 'No hay señal reciente del sensor biométrico',
        'fecha_actualizacion' => $estado['fecha_actualizacion'],
        'segundos_sin_senal' => $segundosSinSenal
    ]);
} catch (Throwable $e) {
    responder_estado_dispositivo([
        'estado' => 'Estado Apagado',
        'estado_biometrico' => 'Estado Apagado',
        'estado_sensor' => 'Apagado',
        'estado_wifi' => 'Desconectado',
        'clase' => 'estado-apagado',
        'mensaje' => 'No se pudo leer el estado del dispositivo'
    ], 200);
}
