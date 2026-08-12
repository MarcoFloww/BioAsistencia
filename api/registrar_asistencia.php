<?php

date_default_timezone_set('America/Lima');

require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../includes/funciones.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

function responder_api(array $datos, int $codigoHttp = 200): void
{
    http_response_code($codigoHttp);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function validar_clave_arduino(): void
{
    $claveSecretaArduino = (string) clave_api_arduino();

    if ($claveSecretaArduino === '') {
        responder_api([
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
        responder_api([
            'estado' => 'error',
            'tipo' => 'acceso_no_autorizado',
            'mensaje' => 'Acceso no autorizado',
            'lcd_linea_1' => 'Acceso denegado',
            'lcd_linea_2' => 'Clave invalida',
            'led' => 'rojo',
            'pitidos' => 2
        ], 401);
    }
}

function obtener_parametro_sensor(): int
{
    $valor = $_POST['id_sensor']
        ?? $_GET['id_sensor']
        ?? $_POST['id_huella']
        ?? $_GET['id_huella']
        ?? '';

    $raw = file_get_contents('php://input');

    if ($valor === '' && is_string($raw) && $raw !== '') {
        if (preg_match('/ASISTENCIA\s*:\s*([0-9]+)/i', $raw, $coincidencia)) {
            $valor = $coincidencia[1];
        } elseif (preg_match('/id_sensor\s*=\s*([0-9]+)/i', $raw, $coincidencia)) {
            $valor = $coincidencia[1];
        } elseif (preg_match('/id_huella\s*=\s*([0-9]+)/i', $raw, $coincidencia)) {
            $valor = $coincidencia[1];
        } elseif (preg_match('/^[0-9]+$/', trim($raw))) {
            $valor = trim($raw);
        }
    }

    if (!preg_match('/^[0-9]+$/', (string) $valor)) {
        return 0;
    }

    return (int) $valor;
}

function obtener_configuracion_api(PDO $pdo, string $clave, string $valorDefecto): string
{
    try {
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

        return $valor !== false && $valor !== null && $valor !== '' ? (string) $valor : $valorDefecto;
    } catch (Throwable $e) {
        return $valorDefecto;
    }
}

function obtener_valores_enum_api(PDO $pdo, string $tabla, string $columna): array
{
    try {
        $consulta = $pdo->prepare(
            "SELECT COLUMN_TYPE
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = :tabla
             AND COLUMN_NAME = :columna
             LIMIT 1"
        );

        $consulta->execute([
            'tabla' => $tabla,
            'columna' => $columna
        ]);

        $fila = $consulta->fetch();

        if (!$fila || !str_starts_with($fila['COLUMN_TYPE'], 'enum')) {
            return [];
        }

        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $fila['COLUMN_TYPE'], $coincidencias);

        return array_map(function ($valor) {
            return str_replace("\\'", "'", $valor);
        }, $coincidencias[1]);
    } catch (Throwable $e) {
        return [];
    }
}

function elegir_valor_api(array $permitidos, string $principal, string $alternativo): string
{
    if (count($permitidos) === 0) {
        return $principal;
    }

    if (in_array($principal, $permitidos, true)) {
        return $principal;
    }

    if (in_array($alternativo, $permitidos, true)) {
        return $alternativo;
    }

    return $permitidos[0];
}

function segundos_hora_api(string $hora): int
{
    $partes = explode(':', $hora);
    $horas = (int) ($partes[0] ?? 0);
    $minutos = (int) ($partes[1] ?? 0);
    $segundos = (int) ($partes[2] ?? 0);

    return ($horas * 3600) + ($minutos * 60) + $segundos;
}

function diferencia_segundos_hora_api(string $horaMayor, string $horaMenor): int
{
    return segundos_hora_api($horaMayor) - segundos_hora_api($horaMenor);
}

function normalizar_hora_api(string $hora, string $defecto): string
{
    $hora = trim($hora);

    if (preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $hora) === 1) {
        return $hora . ':00';
    }

    if (preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/', $hora) === 1) {
        return $hora;
    }

    return normalizar_hora_api($defecto, '00:00:00');
}

function hora_desde_minutos_api(int $minutos): string
{
    $minutos = max(0, min(1439, $minutos));
    $hora = intdiv($minutos, 60);
    $minuto = $minutos % 60;

    return str_pad((string) $hora, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string) $minuto, 2, '0', STR_PAD_LEFT) . ':00';
}

function obtener_hora_marcacion_api(string $horaActual, bool $usarHoraCaptura = false): string
{
    if (!$usarHoraCaptura) {
        return normalizar_hora_api($horaActual, '00:00:00');
    }
    $minutosCaptura = $_POST['minutos_captura'] ?? $_GET['minutos_captura'] ?? null;

    if ($minutosCaptura !== null && preg_match('/^[0-9]{1,4}$/', (string) $minutosCaptura) === 1) {
        $minutos = (int) $minutosCaptura;

        if ($minutos >= 0 && $minutos <= 1439) {
            return hora_desde_minutos_api($minutos);
        }
    }

    $horaCaptura = $_POST['hora_captura'] ?? $_GET['hora_captura'] ?? '';

    if (is_string($horaCaptura) && trim($horaCaptura) !== '') {
        return normalizar_hora_api($horaCaptura, $horaActual);
    }

    return normalizar_hora_api($horaActual, '00:00:00');
}

function obtener_tipo_marcacion_api(int $segundosMarcacion, int $segundosInicioSistema, int $segundosFinEntrada, int $segundosSalidaOficial, int $segundosFinSalida): string
{
    $tipo = strtolower(trim((string) ($_POST['tipo_marcacion'] ?? $_GET['tipo_marcacion'] ?? '')));
    $tipo = str_replace('-', '_', $tipo);

    $tiposPermitidos = ['entrada', 'mixto', 'salida_anticipada', 'salida'];

    if (in_array($tipo, $tiposPermitidos, true)) {
        return $tipo;
    }

    if ($segundosMarcacion >= $segundosInicioSistema && $segundosMarcacion <= $segundosFinEntrada) {
        return 'entrada';
    }

    if ($segundosMarcacion > $segundosFinEntrada && $segundosMarcacion < $segundosSalidaOficial) {
        return 'mixto';
    }

    if ($segundosMarcacion >= $segundosSalidaOficial && $segundosMarcacion <= $segundosFinSalida) {
        return 'salida';
    }

    return 'ninguno';
}

function actualizar_estado_dispositivo_api(PDO $pdo, string $mensaje): void
{
    try {
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
                 SET estado_biometrico = 'Sistema Activo',
                     estado_sensor = 'Activo',
                     estado_wifi = 'Conectado',
                     mensaje = :mensaje,
                     fecha_actualizacion = NOW()
                 WHERE id_estado = :id_estado"
            );

            $actualizar->execute([
                'mensaje' => $mensaje,
                'id_estado' => (int) $idEstado
            ]);
        } else {
            $insertar = $pdo->prepare(
                "INSERT INTO estado_dispositivo
                 (estado_biometrico, estado_sensor, estado_wifi, mensaje)
                 VALUES
                 ('Sistema Activo', 'Activo', 'Conectado', :mensaje)"
            );

            $insertar->execute([
                'mensaje' => $mensaje
            ]);
        }
    } catch (Throwable $e) {
        return;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET, POST');

    responder_api([
        'estado' => 'error',
        'tipo' => 'metodo_no_permitido',
        'mensaje' => 'Método no permitido'
    ], 405);
}

validar_clave_arduino();

$esPendiente = (string) ($_POST['pendiente'] ?? $_GET['pendiente'] ?? '0') === '1';

$fechaServidorActual = date('Y-m-d');
$fechaMarcacion = $fechaServidorActual;

if ($esPendiente) {
    $fechaCaptura = trim((string) ($_POST['fecha_captura'] ?? $_GET['fecha_captura'] ?? ''));

    $fechaObjeto = DateTimeImmutable::createFromFormat('!Y-m-d', $fechaCaptura);

    if (
        !$fechaObjeto
        || $fechaObjeto->format('Y-m-d') !== $fechaCaptura
        || $fechaCaptura > $fechaServidorActual
    ) {
        responder_api([
            'estado' => 'error',
            'tipo' => 'fecha_captura_no_valida',
            'mensaje' => 'La fecha original de la marcación no es válida',
            'lcd_linea_1' => 'Fecha invalida',
            'lcd_linea_2' => 'No registra',
            'led' => 'rojo',
            'pitidos' => 2
        ], 400);
    }

    $fechaMarcacion = $fechaCaptura;
}

$fechaObjetoMarcacion = new DateTimeImmutable($fechaMarcacion);
$numeroDia = (int) $fechaObjetoMarcacion->format('N');

$diasSemana = [
    1 => 'Lunes',
    2 => 'Martes',
    3 => 'Miércoles',
    4 => 'Jueves',
    5 => 'Viernes'
];

if (!isset($diasSemana[$numeroDia])) {
    responder_api([
        'estado' => 'error',
        'tipo' => 'dia_no_laborable',
        'mensaje' => 'Hoy no es un día de clases',
        'fecha' => $fechaMarcacion,
        'lcd_linea_1' => 'Dia no laborable',
        'lcd_linea_2' => 'No se registra',
        'led' => 'rojo',
        'pitidos' => 2
    ]);
}

$diaHoy = $diasSemana[$numeroDia];

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
    'fecha' => $fechaMarcacion
]);

$periodoActual = $consultaPeriodo->fetch();

if (!$periodoActual) {
    responder_api([
        'estado' => 'error',
        'tipo' => 'fuera_periodo_academico',
        'mensaje' => 'Actualmente no hay clases por periodo académico',
        'fecha' => $fechaMarcacion,
        'lcd_linea_1' => 'Sistema inactivo',
        'lcd_linea_2' => 'Fuera de ciclo',
        'led' => 'rojo',
        'pitidos' => 2
    ]);
}

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

if ($hayClaseHoy <= 0) {
    responder_api([
        'estado' => 'error',
        'tipo' => 'dia_no_laborable',
        'mensaje' => 'Hoy no hay clases programadas',
        'fecha' => $fechaMarcacion,
        'lcd_linea_1' => 'Dia no laborable',
        'lcd_linea_2' => 'No se registra',
        'led' => 'rojo',
        'pitidos' => 2
    ]);
}

$idSensor = obtener_parametro_sensor();

if ($idSensor <= 0) {
    responder_api([
        'estado' => 'error',
        'tipo' => 'id_sensor_no_valido',
        'mensaje' => 'ID de sensor no válido',
        'lcd_linea_1' => 'ID sensor',
        'lcd_linea_2' => 'no valido',
        'led' => 'rojo',
        'pitidos' => 2
    ], 400);
}

try {
    $consultaEstudiante = $pdo->prepare(
        "SELECT e.id_estudiante,
                e.codigo_estudiante,
                e.nombres,
                e.apellidos,
                h.id_sensor
         FROM huellas h
         INNER JOIN estudiantes e ON h.id_estudiante = e.id_estudiante
         WHERE h.id_sensor = :id_sensor
         AND h.estado = 'Activa'
         AND e.estado = 'Activo'
         LIMIT 1"
    );

    $consultaEstudiante->execute([
        'id_sensor' => $idSensor
    ]);

    $estudiante = $consultaEstudiante->fetch();

    if (!$estudiante) {
        actualizar_estado_dispositivo_api($pdo, 'Huella no registrada en la base de datos. ID sensor: ' . $idSensor);

        responder_api([
            'estado' => 'error',
            'tipo' => 'huella_no_registrada',
            'mensaje' => 'Huella no registrada',
            'id_sensor' => $idSensor,
            'lcd_linea_1' => 'Huella no',
            'lcd_linea_2' => 'registrada',
            'led' => 'rojo',
            'pitidos' => 2
        ]);
    }

    $fechaHoy = $fechaMarcacion;
    $horaServidorActual = date('H:i:s');
    $horaMarcacion = obtener_hora_marcacion_api($horaServidorActual, $esPendiente);

    $horaInicioSistema = normalizar_hora_api(obtener_configuracion_api($pdo, 'hora_inicio_sistema', '13:00:00'), '13:00:00');
    $horaEntradaOficial = normalizar_hora_api(obtener_configuracion_api($pdo, 'hora_entrada_oficial', '14:00:00'), '14:00:00');
    $horaSalidaOficial = normalizar_hora_api(obtener_configuracion_api($pdo, 'hora_salida_oficial', '19:00:00'), '19:00:00');
    $toleranciaMinutos = (int) obtener_configuracion_api($pdo, 'tolerancia_minutos', '15');
    $toleranciaSalidaMinutos = (int) obtener_configuracion_api($pdo, 'tolerancia_salida_minutos', '15');
    $intervaloMinimoMarcaciones = (int) obtener_configuracion_api($pdo, 'intervalo_minimo_marcaciones', '30');
    $segundosIntervaloMinimo = max(1, $intervaloMinimoMarcaciones) * 60;

    $segundosInicioSistema = segundos_hora_api($horaInicioSistema);
    $segundosEntrada = segundos_hora_api($horaEntradaOficial);
    $segundosFinEntrada = $segundosEntrada + ($toleranciaMinutos * 60);
    $segundosSalida = segundos_hora_api($horaSalidaOficial);
    $segundosFinSalida = $segundosSalida + ($toleranciaSalidaMinutos * 60);
    $segundosMarcacion = segundos_hora_api($horaMarcacion);

    if ($segundosMarcacion < $segundosInicioSistema) {
        responder_api([
            'estado' => 'error',
            'tipo' => 'modo_suspendido',
            'mensaje' => 'El sistema todavía está suspendido',
            'fecha' => $fechaHoy,
            'hora' => $horaMarcacion,
            'lcd_linea_1' => 'Modo suspendido',
            'lcd_linea_2' => 'Activa 1:00 PM',
            'led' => 'rojo',
            'pitidos' => 2
        ]);
    }

    if ($segundosMarcacion > $segundosFinSalida) {
        responder_api([
            'estado' => 'error',
            'tipo' => 'sistema_cerrado',
            'mensaje' => 'El sistema ya cerró el registro del día',
            'fecha' => $fechaHoy,
            'hora' => $horaMarcacion,
            'lcd_linea_1' => 'Sistema cerrado',
            'lcd_linea_2' => 'Hasta 7:15 PM',
            'led' => 'rojo',
            'pitidos' => 2
        ]);
    }

    $tipoMarcacion = obtener_tipo_marcacion_api($segundosMarcacion, $segundosInicioSistema, $segundosFinEntrada, $segundosSalida, $segundosFinSalida);

    if ($tipoMarcacion === 'ninguno') {
        responder_api([
            'estado' => 'error',
            'tipo' => 'horario_no_permitido',
            'mensaje' => 'Horario no permitido para registrar asistencia',
            'fecha' => $fechaHoy,
            'hora' => $horaMarcacion,
            'lcd_linea_1' => 'Horario cerrado',
            'lcd_linea_2' => 'No registra',
            'led' => 'rojo',
            'pitidos' => 2
        ]);
    }

    $opcionesEstadoEntrada = obtener_valores_enum_api($pdo, 'asistencias', 'estado_entrada');
    $opcionesEstadoSalida = obtener_valores_enum_api($pdo, 'asistencias', 'estado_salida');
    $opcionesMetodo = obtener_valores_enum_api($pdo, 'asistencias', 'metodo_registro');
    $metodoRegistro = elegir_valor_api($opcionesMetodo, 'Huella', 'Manual');

    $consultaAsistencia = $pdo->prepare(
        "SELECT id_asistencia,
                hora_entrada,
                estado_entrada,
                hora_salida,
                estado_salida,
                metodo_registro
         FROM asistencias
         WHERE id_estudiante = :id_estudiante
         AND fecha = :fecha
         LIMIT 1"
    );

    $consultaAsistencia->execute([
        'id_estudiante' => (int) $estudiante['id_estudiante'],
        'fecha' => $fechaHoy
    ]);

    $asistencia = $consultaAsistencia->fetch();

    if (!$asistencia) {
        $tipoMarcacion = 'entrada';
    } elseif (
        $asistencia
        && ($asistencia['hora_salida'] === null || $asistencia['hora_salida'] === '')
    ) {
        $tipoMarcacion = $segundosMarcacion < $segundosSalida
            ? 'salida_anticipada'
            : 'salida';
    }

    $estadoEntradaBase = $segundosMarcacion < $segundosFinEntrada ? 'Puntual' : 'Tardanza';
    $estadoEntrada = elegir_valor_api($opcionesEstadoEntrada, $estadoEntradaBase, 'Puntual');
    $estadoSalidaBase = $tipoMarcacion === 'salida_anticipada' ? 'Salida Anticipada' : 'Salida Registrada';
    $estadoSalida = elegir_valor_api($opcionesEstadoSalida, $estadoSalidaBase, 'Salida Registrada');
    $estadoSalidaPendiente = elegir_valor_api($opcionesEstadoSalida, 'Sin registro de salida', 'Pendiente');

    $esFaltaAutomatica = $asistencia
        && ($asistencia['hora_entrada'] === null || $asistencia['hora_entrada'] === '')
        && in_array((string) $asistencia['estado_entrada'], ['Falto', 'Falta'], true)
        && (string) ($asistencia['metodo_registro'] ?? '') === 'Sistema';

    if ($esPendiente && $esFaltaAutomatica) {
        $recuperarEntrada = $pdo->prepare(
            "UPDATE asistencias
             SET hora_entrada = :hora_entrada,
                 estado_entrada = :estado_entrada,
                 hora_salida = NULL,
                 estado_salida = :estado_salida,
                 metodo_registro = :metodo_registro,
                 observacion = :observacion
             WHERE id_asistencia = :id_asistencia"
        );

        $recuperarEntrada->execute([
            'hora_entrada' => $horaMarcacion,
            'estado_entrada' => $estadoEntrada,
            'estado_salida' => $estadoSalidaPendiente,
            'metodo_registro' => $metodoRegistro,
            'observacion' => 'Entrada recuperada desde marcación pendiente del sensor biométrico',
            'id_asistencia' => (int) $asistencia['id_asistencia']
        ]);

        actualizar_estado_dispositivo_api(
            $pdo,
            'Entrada pendiente recuperada: ' . $estudiante['nombres'] . ' ' . $estudiante['apellidos'] . ' - ID sensor ' . $idSensor
        );

        responder_api([
            'estado' => 'ok',
            'tipo' => 'asistencia',
            'mensaje' => 'Asistencia Registrada',
            'id_sensor' => $idSensor,
            'id_estudiante' => (int) $estudiante['id_estudiante'],
            'codigo_estudiante' => $estudiante['codigo_estudiante'],
            'estudiante' => trim($estudiante['nombres'] . ' ' . $estudiante['apellidos']),
            'fecha' => $fechaHoy,
            'hora' => $horaMarcacion,
            'estado_entrada' => $estadoEntrada,
            'recuperada_desde_pendiente' => true,
            'lcd_linea_1' => 'Entrada',
            'lcd_linea_2' => $estadoEntrada,
            'led' => 'verde',
            'pitidos' => 1
        ]);
    }

    if (!$asistencia && $tipoMarcacion !== 'entrada') {
        actualizar_estado_dispositivo_api($pdo, 'Intento de salida sin entrada: ' . $estudiante['nombres'] . ' ' . $estudiante['apellidos'] . ' - ID sensor ' . $idSensor);

        responder_api([
            'estado' => 'error',
            'tipo' => 'sin_entrada',
            'mensaje' => 'No existe entrada registrada para hoy',
            'id_sensor' => $idSensor,
            'id_estudiante' => (int) $estudiante['id_estudiante'],
            'codigo_estudiante' => $estudiante['codigo_estudiante'],
            'estudiante' => trim($estudiante['nombres'] . ' ' . $estudiante['apellidos']),
            'fecha' => $fechaHoy,
            'hora' => $horaMarcacion,
            'lcd_linea_1' => 'Sin entrada',
            'lcd_linea_2' => 'No registra',
            'led' => 'rojo',
            'pitidos' => 2
        ]);
    }

    if (!$asistencia) {
        $registrarEntrada = $pdo->prepare(
            "INSERT INTO asistencias
             (id_estudiante, fecha, hora_entrada, estado_entrada, hora_salida, estado_salida, metodo_registro, observacion)
             VALUES
             (:id_estudiante, :fecha, :hora_entrada, :estado_entrada, NULL, :estado_salida, :metodo_registro, :observacion)"
        );

        $registrarEntrada->execute([
            'id_estudiante' => (int) $estudiante['id_estudiante'],
            'fecha' => $fechaHoy,
            'hora_entrada' => $horaMarcacion,
            'estado_entrada' => $estadoEntrada,
            'estado_salida' => $estadoSalidaPendiente,
            'metodo_registro' => $metodoRegistro,
            'observacion' => 'Entrada registrada por sensor biométrico'
        ]);

        actualizar_estado_dispositivo_api($pdo, 'Entrada registrada: ' . $estudiante['nombres'] . ' ' . $estudiante['apellidos'] . ' - ID sensor ' . $idSensor);

        responder_api([
            'estado' => 'ok',
            'tipo' => 'asistencia',
            'mensaje' => 'Asistencia Registrada',
            'id_sensor' => $idSensor,
            'id_estudiante' => (int) $estudiante['id_estudiante'],
            'codigo_estudiante' => $estudiante['codigo_estudiante'],
            'estudiante' => trim($estudiante['nombres'] . ' ' . $estudiante['apellidos']),
            'fecha' => $fechaHoy,
            'hora' => $horaMarcacion,
            'estado_entrada' => $estadoEntrada,
            'lcd_linea_1' => 'Entrada',
            'lcd_linea_2' => $estadoEntrada,
            'led' => 'verde',
            'pitidos' => 1
        ]);
    }

    if ($tipoMarcacion === 'entrada') {
        actualizar_estado_dispositivo_api($pdo, 'Entrada repetida: ' . $estudiante['nombres'] . ' ' . $estudiante['apellidos'] . ' - ID sensor ' . $idSensor);

        responder_api([
            'estado' => 'ok',
            'tipo' => 'huella_repetida',
            'mensaje' => 'Entrada ya registrada. Retire su dedo.',
            'id_sensor' => $idSensor,
            'id_estudiante' => (int) $estudiante['id_estudiante'],
            'codigo_estudiante' => $estudiante['codigo_estudiante'],
            'estudiante' => trim($estudiante['nombres'] . ' ' . $estudiante['apellidos']),
            'fecha' => $fechaHoy,
            'hora_entrada' => $asistencia['hora_entrada'],
            'lcd_linea_1' => 'Entrada ya reg.',
            'lcd_linea_2' => 'Retire dedo',
            'led' => 'rojo',
            'pitidos' => 2
        ]);
    }

    if ($asistencia['hora_salida'] === null || $asistencia['hora_salida'] === '') {
        $horaEntradaRegistrada = (string) ($asistencia['hora_entrada'] ?? '');

        if ($horaEntradaRegistrada !== '' && diferencia_segundos_hora_api($horaMarcacion, $horaEntradaRegistrada) >= 0 && diferencia_segundos_hora_api($horaMarcacion, $horaEntradaRegistrada) < $segundosIntervaloMinimo) {
            actualizar_estado_dispositivo_api($pdo, 'Huella repetida: ' . $estudiante['nombres'] . ' ' . $estudiante['apellidos'] . ' - ID sensor ' . $idSensor);

            responder_api([
                'estado' => 'ok',
                'tipo' => 'huella_repetida',
                'mensaje' => 'Huella ya registrada. Retire su dedo.',
                'id_sensor' => $idSensor,
                'id_estudiante' => (int) $estudiante['id_estudiante'],
                'codigo_estudiante' => $estudiante['codigo_estudiante'],
                'estudiante' => trim($estudiante['nombres'] . ' ' . $estudiante['apellidos']),
                'fecha' => $fechaHoy,
                'hora_entrada' => $asistencia['hora_entrada'],
                'lcd_linea_1' => 'Huella ya reg.',
                'lcd_linea_2' => 'Retire dedo',
                'led' => 'rojo',
                'pitidos' => 2
            ]);
        }

        $registrarSalida = $pdo->prepare(
            "UPDATE asistencias
             SET hora_salida = :hora_salida,
                 estado_salida = :estado_salida,
                 metodo_registro = :metodo_registro,
                 observacion = :observacion
             WHERE id_asistencia = :id_asistencia"
        );

        $observacionSalida = $estadoSalida === 'Salida Anticipada'
            ? 'Salida anticipada registrada por sensor biométrico'
            : 'Salida registrada por sensor biométrico';

        $registrarSalida->execute([
            'hora_salida' => $horaMarcacion,
            'estado_salida' => $estadoSalida,
            'metodo_registro' => $metodoRegistro,
            'observacion' => $observacionSalida,
            'id_asistencia' => (int) $asistencia['id_asistencia']
        ]);

        actualizar_estado_dispositivo_api($pdo, $estadoSalida . ': ' . $estudiante['nombres'] . ' ' . $estudiante['apellidos'] . ' - ID sensor ' . $idSensor);

        $tipoSalidaRespuesta = $estadoSalida === 'Salida Anticipada' ? 'salida_anticipada' : 'salida';
        $mensajeSalidaRespuesta = $estadoSalida === 'Salida Anticipada' ? 'Salida Anticipada' : 'Salida Registrada';

        responder_api([
            'estado' => 'ok',
            'tipo' => $tipoSalidaRespuesta,
            'mensaje' => $mensajeSalidaRespuesta,
            'id_sensor' => $idSensor,
            'id_estudiante' => (int) $estudiante['id_estudiante'],
            'codigo_estudiante' => $estudiante['codigo_estudiante'],
            'estudiante' => trim($estudiante['nombres'] . ' ' . $estudiante['apellidos']),
            'fecha' => $fechaHoy,
            'hora' => $horaMarcacion,
            'estado_salida' => $estadoSalida,
            'lcd_linea_1' => $mensajeSalidaRespuesta,
            'lcd_linea_2' => substr(trim($estudiante['nombres'] . ' ' . $estudiante['apellidos']), 0, 16),
            'led' => 'verde',
            'pitidos' => 1
        ]);
    }

    actualizar_estado_dispositivo_api($pdo, 'Marcación repetida: ' . $estudiante['nombres'] . ' ' . $estudiante['apellidos'] . ' - ID sensor ' . $idSensor);

    responder_api([
        'estado' => 'ok',
        'tipo' => 'asistencia_completa',
        'mensaje' => 'Asistencia completa. Retire su dedo.',
        'id_sensor' => $idSensor,
        'id_estudiante' => (int) $estudiante['id_estudiante'],
        'codigo_estudiante' => $estudiante['codigo_estudiante'],
        'estudiante' => trim($estudiante['nombres'] . ' ' . $estudiante['apellidos']),
        'fecha' => $fechaHoy,
        'hora_entrada' => $asistencia['hora_entrada'],
        'hora_salida' => $asistencia['hora_salida'],
        'lcd_linea_1' => 'Asistencia',
        'lcd_linea_2' => 'completa',
        'led' => 'rojo',
        'pitidos' => 2
    ]);
} catch (Throwable $e) {
    error_log('BioAsistencia registrar_asistencia: ' . $e->getMessage());

    responder_api([
        'estado' => 'error',
        'tipo' => 'error_servidor',
        'mensaje' => 'No se pudo registrar la asistencia',
        'lcd_linea_1' => 'Error sistema',
        'lcd_linea_2' => 'Revise conexion',
        'led' => 'rojo',
        'pitidos' => 2
    ], 500);
}