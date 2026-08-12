<?php

date_default_timezone_set('America/Lima');

require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../includes/funciones.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

function responder_estado_api(array $datos, int $codigoHttp = 200): void
{
    http_response_code($codigoHttp);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function validar_clave_estado(): void
{
    $claveSecretaArduino = (string) clave_api_arduino();

    if ($claveSecretaArduino === '') {
        responder_estado_api([
            'estado' => 'error',
            'tipo' => 'configuracion_api_invalida',
            'mensaje' => 'La autenticación del dispositivo no está configurada'
        ], 500);
    }

    $claveCabecera = $_SERVER['HTTP_X_BIOASISTENCIA_KEY'] ?? '';

    $claveRecibida = $claveCabecera !== ''
        ? $claveCabecera
        : ($_POST['clave'] ?? $_GET['clave'] ?? '');

    if (!hash_equals($claveSecretaArduino, (string) $claveRecibida)) {
        responder_estado_api([
            'estado' => 'error',
            'tipo' => 'acceso_no_autorizado',
            'mensaje' => 'Acceso no autorizado'
        ], 401);
    }
}

function asegurar_tabla_estado(PDO $pdo): void
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

function obtener_configuracion_estado(PDO $pdo, string $clave, string $predeterminado): string
{
    $consulta = $pdo->prepare(
        "SELECT valor
         FROM configuracion
         WHERE clave = :clave
         LIMIT 1"
    );

    $consulta->execute([
        'clave' => $clave
    ]);

    $valor = $consulta->fetchColumn();

    return $valor !== false && $valor !== null && $valor !== ''
        ? (string) $valor
        : $predeterminado;
}

function segundos_hora_estado(string $hora): int
{
    $partes = array_map('intval', explode(':', $hora));

    return (($partes[0] ?? 0) * 3600)
        + (($partes[1] ?? 0) * 60)
        + ($partes[2] ?? 0);
}

function texto_hora_estado(int $segundos): string
{
    $segundos = max(0, min(86399, $segundos));
    $hora = intdiv($segundos, 3600);
    $minuto = intdiv($segundos % 3600, 60);

    return str_pad((string) $hora, 2, '0', STR_PAD_LEFT)
        . ':'
        . str_pad((string) $minuto, 2, '0', STR_PAD_LEFT);
}

function generar_faltas_automaticas_estado(PDO $pdo, string $fecha, int $idPeriodo): array
{
    try {
        $consulta = $pdo->prepare(
            "INSERT IGNORE INTO asistencias
             (id_estudiante, fecha, hora_entrada, estado_entrada, hora_salida, estado_salida, metodo_registro, observacion)
             SELECT e.id_estudiante,
                    :fecha,
                    NULL,
                    'Falto',
                    NULL,
                    'No aplica',
                    'Sistema',
                    'Falta automática generada al cierre del día'
             FROM estudiantes e
             WHERE e.estado = 'Activo'
             AND e.estado_academico = 'Regular'
             AND e.id_periodo = :id_periodo
             AND NOT EXISTS (
                 SELECT 1
                 FROM asistencias a
                 WHERE a.id_estudiante = e.id_estudiante
                 AND a.fecha = :fecha_validar
             )"
        );

        $consulta->execute([
            'fecha' => $fecha,
            'id_periodo' => $idPeriodo,
            'fecha_validar' => $fecha
        ]);

        return [
            'estado' => 'procesadas',
            'cantidad' => $consulta->rowCount()
        ];
    } catch (Throwable $e) {
        error_log('Error generando faltas automáticas: ' . $e->getMessage());

        return [
            'estado' => 'error',
            'cantidad' => 0
        ];
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET, POST');

    responder_estado_api([
        'estado' => 'error',
        'tipo' => 'metodo_no_permitido',
        'mensaje' => 'Método no permitido'
    ], 405);
}

validar_clave_estado();

try {
    sincronizar_periodo_academico($pdo);
} catch (Throwable $e) {
    error_log('BioAsistencia estado_dispositivo - sincronización de periodo: ' . $e->getMessage());
}

$fechaActual = date('Y-m-d');
$numeroDia = (int) date('N');

$diasSemana = [
    1 => 'Lunes',
    2 => 'Martes',
    3 => 'Miércoles',
    4 => 'Jueves',
    5 => 'Viernes'
];

$esDiaLaborable = isset($diasSemana[$numeroDia]);
$diaHoy = $esDiaLaborable ? $diasSemana[$numeroDia] : '';

try {
    $consultaPeriodo = $pdo->prepare(
        "SELECT id_periodo,
                nombre_periodo,
                fecha_inicio,
                fecha_fin
         FROM periodos_academicos
         WHERE :fecha BETWEEN fecha_inicio AND fecha_fin
         ORDER BY fecha_inicio DESC
         LIMIT 1"
    );

    $consultaPeriodo->execute([
        'fecha' => $fechaActual
    ]);

    $periodoActual = $consultaPeriodo->fetch();
    $hayPeriodoActual = (bool) $periodoActual;

    $hayClaseHoy = 0;

    if ($esDiaLaborable && $hayPeriodoActual) {
        $consultaHorario = $pdo->prepare(
            "SELECT COUNT(*)
             FROM horarios
             WHERE dia_semana = :dia
             AND tipo_actividad = 'Clase'
             AND estado = 'Activo'"
        );

        $consultaHorario->execute([
            'dia' => $diaHoy
        ]);

        $hayClaseHoy = (int) $consultaHorario->fetchColumn();
    }

    $horaInicioSistema = obtener_configuracion_estado(
        $pdo,
        'hora_inicio_sistema',
        '13:00:00'
    );

    $horaEntradaOficial = obtener_configuracion_estado(
        $pdo,
        'hora_entrada_oficial',
        '14:00:00'
    );

    $toleranciaEntrada = (int) obtener_configuracion_estado(
        $pdo,
        'tolerancia_minutos',
        '15'
    );

    $intervaloMinimoMarcaciones = (int) obtener_configuracion_estado(
        $pdo,
        'intervalo_minimo_marcaciones',
        '30'
    );

    $horaSalidaOficial = obtener_configuracion_estado(
        $pdo,
        'hora_salida_oficial',
        '19:00:00'
    );

    $toleranciaSalida = (int) obtener_configuracion_estado(
        $pdo,
        'tolerancia_salida_minutos',
        '15'
    );

    $segundosActuales = segundos_hora_estado(date('H:i:s'));
    $segundosInicio = segundos_hora_estado($horaInicioSistema);
    $segundosSalida = segundos_hora_estado($horaSalidaOficial);
    $segundosCierre = $segundosSalida + ($toleranciaSalida * 60);

    $textoInicio = texto_hora_estado($segundosInicio);
    $textoCierre = texto_hora_estado($segundosCierre);

    if (!$esDiaLaborable || !$hayPeriodoActual || $hayClaseHoy <= 0) {
        $tipoDia = 'dia_no_laborable';
        $estadoBiometricoHorario = 'Estado Apagado';
        $lcdLinea1 = 'Sistema inactivo';
        $lcdLinea2 = 'No hay clases';
    } elseif ($segundosActuales < $segundosInicio) {
        $tipoDia = 'dia_laborable';
        $estadoBiometricoHorario = 'Estado Apagado';
        $lcdLinea1 = 'Sistema inactivo';
        $lcdLinea2 = 'Activa ' . $textoInicio;
    } elseif ($segundosActuales <= $segundosCierre) {
        $tipoDia = 'dia_laborable';
        $estadoBiometricoHorario = 'Sistema Activo';
        $lcdLinea1 = 'Sistema Activo';
        $lcdLinea2 = 'Coloque dedo';
    } else {
        $tipoDia = 'dia_laborable';
        $estadoBiometricoHorario = 'Estado Apagado';
        $lcdLinea1 = 'Sistema cerrado';
        $lcdLinea2 = 'Hasta ' . $textoCierre;
    }

    $resultadoFaltas = [
        'estado' => 'no_corresponde',
        'cantidad' => 0
    ];

    if (
        $esDiaLaborable
        && $hayPeriodoActual
        && $hayClaseHoy > 0
        && $segundosActuales > $segundosCierre
    ) {
        $resultadoFaltas = generar_faltas_automaticas_estado(
            $pdo,
            $fechaActual,
            (int) $periodoActual['id_periodo']
        );
    }

    asegurar_tabla_estado($pdo);

    $estadoSensor = $_SERVER['HTTP_X_BIOASISTENCIA_SENSOR']
        ?? $_POST['sensor']
        ?? $_GET['sensor']
        ?? 'activo';

    $estadoSensor = strtolower(trim((string) $estadoSensor));

    if ($estadoSensor === 'activo') {
        $estadoBiometrico = $estadoBiometricoHorario;
        $estadoSensorTexto = 'Activo';
        $estadoWifiTexto = 'Conectado';
        $mensaje = 'Señal de vida recibida desde Arduino';
    } else {
        $estadoBiometrico = 'Estado Apagado';
        $estadoSensorTexto = 'Apagado';
        $estadoWifiTexto = 'Desconectado';
        $mensaje = 'Sensor biométrico apagado o sin respuesta';
    }

    $consulta = $pdo->query(
        "SELECT id_estado
         FROM estado_dispositivo
         ORDER BY id_estado DESC
         LIMIT 1"
    );

    $idEstado = $consulta->fetchColumn();

    if ($idEstado) {
        $actualizar = $pdo->prepare(
            "UPDATE estado_dispositivo
             SET estado_biometrico = :estado_biometrico,
                 estado_sensor = :estado_sensor,
                 estado_wifi = :estado_wifi,
                 mensaje = :mensaje,
                 fecha_actualizacion = NOW()
             WHERE id_estado = :id_estado"
        );

        $actualizar->execute([
            'estado_biometrico' => $estadoBiometrico,
            'estado_sensor' => $estadoSensorTexto,
            'estado_wifi' => $estadoWifiTexto,
            'mensaje' => $mensaje,
            'id_estado' => (int) $idEstado
        ]);
    } else {
        $insertar = $pdo->prepare(
            "INSERT INTO estado_dispositivo
             (estado_biometrico, estado_sensor, estado_wifi, mensaje)
             VALUES
             (:estado_biometrico, :estado_sensor, :estado_wifi, :mensaje)"
        );

        $insertar->execute([
            'estado_biometrico' => $estadoBiometrico,
            'estado_sensor' => $estadoSensorTexto,
            'estado_wifi' => $estadoWifiTexto,
            'mensaje' => $mensaje
        ]);
    }

    responder_estado_api([
        'estado' => 'ok',
        'estado_biometrico' => $estadoBiometrico,
        'estado_sensor' => $estadoSensorTexto,
        'estado_wifi' => $estadoWifiTexto,
        'mensaje' => $mensaje,
        'hay_clases_hoy' => $hayClaseHoy > 0,
        'tipo_dia' => $tipoDia,
        'periodo_actual' => $periodoActual['nombre_periodo'] ?? null,
        'lcd_linea_1' => $lcdLinea1,
        'lcd_linea_2' => $lcdLinea2,

        'hora_inicio_sistema' => $horaInicioSistema,
        'hora_entrada_oficial' => $horaEntradaOficial,
        'tolerancia_entrada_minutos' => $toleranciaEntrada,
        'intervalo_minimo_marcaciones' => $intervaloMinimoMarcaciones,
        'hora_salida_oficial' => $horaSalidaOficial,
        'tolerancia_salida_minutos' => $toleranciaSalida,

        'faltas_automaticas' => $resultadoFaltas['estado'],
        'faltas_generadas' => $resultadoFaltas['cantidad'],

        'fecha_actualizacion' => date('Y-m-d H:i:s')
    ]);
} catch (Throwable $e) {
    error_log('BioAsistencia estado_dispositivo: ' . $e->getMessage());

    responder_estado_api([
        'estado' => 'error',
        'tipo' => 'error_servidor',
        'mensaje' => 'No se pudo actualizar el estado del dispositivo'
    ], 500);
}