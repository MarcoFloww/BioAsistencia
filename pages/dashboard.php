<?php

date_default_timezone_set('America/Lima');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/funciones.php';

requerir_rol(['Administrador', 'Docente']);

$rol = function_exists('obtener_rol_usuario') ? obtener_rol_usuario() : ($_SESSION['rol'] ?? 'Usuario');
$nombreUsuario = function_exists('obtener_nombre_usuario') ? obtener_nombre_usuario() : ($_SESSION['nombre_completo'] ?? 'Usuario');
$estadoDispositivo = function_exists('obtener_estado_dispositivo') ? obtener_estado_dispositivo($pdo) : 'Estado Apagado';
$claseEstadoDispositivo = function_exists('clase_estado_dispositivo') ? clase_estado_dispositivo($estadoDispositivo) : 'apagado';

$fechaHoy = date('Y-m-d');
$horaActual = date('H:i:s');
$inicioSemana = date('Y-m-d', strtotime('monday this week'));
$finSemana = date('Y-m-d', strtotime('friday this week'));

function valor_dashboard($valor): int
{
    return (int) ($valor ?? 0);
}

function ejecutar_valor_dashboard(PDO $pdo, string $sql, array $parametros = []): int
{
    try {
        $consulta = $pdo->prepare($sql);
        $consulta->execute($parametros);

        return (int) $consulta->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function ejecutar_fila_dashboard(PDO $pdo, string $sql, array $parametros = []): array
{
    try {
        $consulta = $pdo->prepare($sql);
        $consulta->execute($parametros);
        $fila = $consulta->fetch();

        return $fila ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function ejecutar_lista_dashboard(PDO $pdo, string $sql, array $parametros = []): array
{
    try {
        $consulta = $pdo->prepare($sql);
        $consulta->execute($parametros);

        return $consulta->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function estado_visual_dashboard(?string $estado): string
{
    $estadoNormalizado = strtolower(trim((string) $estado));

    if (str_contains($estadoNormalizado, 'puntual') || str_contains($estadoNormalizado, 'registrada') || str_contains($estadoNormalizado, 'completada')) {
        return 'success';
    }

    if (str_contains($estadoNormalizado, 'tardanza') || str_contains($estadoNormalizado, 'anticipada') || str_contains($estadoNormalizado, 'pendiente')) {
        return 'warning';
    }

    if (str_contains($estadoNormalizado, 'falto') || str_contains($estadoNormalizado, 'falta') || str_contains($estadoNormalizado, 'error') || str_contains($estadoNormalizado, 'no aplica')) {
        return 'danger';
    }

    return 'neutral';
}

function inicial_usuario_dashboard(string $nombreUsuario): string
{
    $nombreUsuario = trim($nombreUsuario);

    if ($nombreUsuario === '') {
        return 'U';
    }

    if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
        return mb_strtoupper(mb_substr($nombreUsuario, 0, 1, 'UTF-8'), 'UTF-8');
    }

    return strtoupper(substr($nombreUsuario, 0, 1));
}

function hora_corta_dashboard(?string $hora): string
{
    if ($hora === null || $hora === '') {
        return '-';
    }

    return substr($hora, 0, 5);
}

function texto_seguro_dashboard($valor, string $alternativo = '-'): string
{
    $valor = trim((string) ($valor ?? ''));

    if ($valor === '') {
        return $alternativo;
    }

    return $valor;
}


function obtener_configuracion_dashboard(PDO $pdo, string $clave, string $valorDefecto): string
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

        return $valor !== false && $valor !== null && $valor !== ''
            ? (string) $valor
            : $valorDefecto;
    } catch (Throwable $e) {
        return $valorDefecto;
    }
}

function segundos_hora_dashboard(string $hora): int
{
    $partes = array_map('intval', explode(':', $hora));

    return (($partes[0] ?? 0) * 3600)
        + (($partes[1] ?? 0) * 60)
        + ($partes[2] ?? 0);
}

function obtener_periodo_actual_dashboard(PDO $pdo, string $fecha): ?array
{
    if (function_exists('obtener_periodo_academico_por_fecha')) {
        return obtener_periodo_academico_por_fecha($pdo, $fecha);
    }

    try {
        $consulta = $pdo->prepare(
            "SELECT id_periodo,
                    nombre_periodo,
                    fecha_inicio,
                    fecha_fin,
                    estado
             FROM periodos_academicos
             WHERE :fecha BETWEEN fecha_inicio AND fecha_fin
             ORDER BY fecha_inicio DESC
             LIMIT 1"
        );

        $consulta->execute([
            'fecha' => $fecha
        ]);

        $periodo = $consulta->fetch();

        return $periodo ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function obtener_dias_clase_dashboard(PDO $pdo): array
{
    try {
        $consulta = $pdo->query(
            "SELECT DISTINCT dia_semana
             FROM horarios
             WHERE tipo_actividad = 'Clase'
             AND estado = 'Activo'"
        );

        $dias = [];

        while ($fila = $consulta->fetch()) {
            $dia = trim((string) ($fila['dia_semana'] ?? ''));

            if ($dia !== '') {
                $dias[$dia] = true;
            }
        }

        return $dias;
    } catch (Throwable $e) {
        return [];
    }
}

function contar_dias_clase_dashboard(string $inicio, string $fin, array $diasClase): int
{
    if ($inicio === '' || $fin === '' || $inicio > $fin || count($diasClase) === 0) {
        return 0;
    }

    try {
        $fechaInicio = new DateTimeImmutable($inicio);
        $fechaFin = new DateTimeImmutable($fin);
    } catch (Throwable $e) {
        return 0;
    }

    $nombresDias = [
        1 => 'Lunes',
        2 => 'Martes',
        3 => 'Miércoles',
        4 => 'Jueves',
        5 => 'Viernes',
        6 => 'Sábado',
        7 => 'Domingo'
    ];

    $total = 0;
    $periodo = new DatePeriod(
        $fechaInicio,
        new DateInterval('P1D'),
        $fechaFin->modify('+1 day')
    );

    foreach ($periodo as $fecha) {
        $nombreDia = $nombresDias[(int) $fecha->format('N')] ?? '';

        if ($nombreDia !== '' && isset($diasClase[$nombreDia])) {
            $total++;
        }
    }

    return $total;
}

$periodoActualDashboard = obtener_periodo_actual_dashboard($pdo, $fechaHoy);
$idPeriodoActualDashboard = (int) ($periodoActualDashboard['id_periodo'] ?? 0);
$nombrePeriodoDashboard = $periodoActualDashboard['nombre_periodo'] ?? 'Sin periodo académico';
$diasClaseDashboard = obtener_dias_clase_dashboard($pdo);

$nombresDiasDashboard = [
    1 => 'Lunes',
    2 => 'Martes',
    3 => 'Miércoles',
    4 => 'Jueves',
    5 => 'Viernes',
    6 => 'Sábado',
    7 => 'Domingo'
];

$nombreDiaHoyDashboard = $nombresDiasDashboard[(int) date('N')] ?? '';
$hayClasesHoyDashboard = $periodoActualDashboard !== null
    && $nombreDiaHoyDashboard !== ''
    && isset($diasClaseDashboard[$nombreDiaHoyDashboard]);

$porcentajeAlertaCriticaDashboard = (int) obtener_configuracion_dashboard(
    $pdo,
    'porcentaje_alerta',
    '30'
);
$porcentajeAlertaCriticaDashboard = max(1, min(100, $porcentajeAlertaCriticaDashboard));

$horaSalidaOficialDashboard = obtener_configuracion_dashboard(
    $pdo,
    'hora_salida_oficial',
    '19:00:00'
);
$toleranciaSalidaDashboard = (int) obtener_configuracion_dashboard(
    $pdo,
    'tolerancia_salida_minutos',
    '15'
);

$segundosCierreDashboard = segundos_hora_dashboard($horaSalidaOficialDashboard)
    + (max(0, $toleranciaSalidaDashboard) * 60);
$segundosActualesDashboard = segundos_hora_dashboard($horaActual);

$inicioPeriodoDashboard = (string) ($periodoActualDashboard['fecha_inicio'] ?? $fechaHoy);
$finPeriodoDashboard = (string) ($periodoActualDashboard['fecha_fin'] ?? $fechaHoy);
$finEvaluadoDashboard = date('Y-m-d', strtotime($inicioPeriodoDashboard . ' -1 day'));

if ($periodoActualDashboard) {
    if ($fechaHoy > $finPeriodoDashboard) {
        $finEvaluadoDashboard = $finPeriodoDashboard;
    } elseif ($fechaHoy >= $inicioPeriodoDashboard) {
        if (!$hayClasesHoyDashboard || $segundosActualesDashboard > $segundosCierreDashboard) {
            $finEvaluadoDashboard = min($fechaHoy, $finPeriodoDashboard);
        } else {
            $finEvaluadoDashboard = date('Y-m-d', strtotime($fechaHoy . ' -1 day'));
        }
    }
}

$diasEvaluadosDashboard = $periodoActualDashboard
    ? contar_dias_clase_dashboard(
        $inicioPeriodoDashboard,
        $finEvaluadoDashboard,
        $diasClaseDashboard
    )
    : 0;

$totalEstudiantes = ejecutar_valor_dashboard(
    $pdo,
    "SELECT COUNT(*) FROM estudiantes WHERE estado = 'Activo'"
);

$asistenciasHoy = ejecutar_valor_dashboard(
    $pdo,
    "SELECT COUNT(*)
     FROM asistencias
     WHERE fecha = :fecha
     AND estado_entrada IN ('Puntual', 'Tardanza')",
    ['fecha' => $fechaHoy]
);

$puntualesHoy = ejecutar_valor_dashboard(
    $pdo,
    "SELECT COUNT(*)
     FROM asistencias
     WHERE fecha = :fecha
     AND estado_entrada = 'Puntual'",
    ['fecha' => $fechaHoy]
);

$tardanzasHoy = ejecutar_valor_dashboard(
    $pdo,
    "SELECT COUNT(*)
     FROM asistencias
     WHERE fecha = :fecha
     AND estado_entrada = 'Tardanza'",
    ['fecha' => $fechaHoy]
);

$faltasHoy = ejecutar_valor_dashboard(
    $pdo,
    "SELECT COUNT(*)
     FROM asistencias
     WHERE fecha = :fecha
     AND estado_entrada IN ('Falto', 'Falta')",
    ['fecha' => $fechaHoy]
);

$salidasPendientes = ejecutar_valor_dashboard(
    $pdo,
    "SELECT COUNT(*)
     FROM asistencias
     WHERE fecha = :fecha
     AND estado_entrada IN ('Puntual', 'Tardanza')
     AND (hora_salida IS NULL OR estado_salida LIKE '%Pendiente%')",
    ['fecha' => $fechaHoy]
);

$alertasCriticas = 0;

if ($idPeriodoActualDashboard > 0 && $diasEvaluadosDashboard > 0) {
    $resumenAlertasDashboard = ejecutar_lista_dashboard(
        $pdo,
        "SELECT e.id_estudiante,
                COUNT(DISTINCT a.fecha) AS dias_con_registro,
                COALESCE(SUM(CASE WHEN a.estado_entrada IN ('Falto', 'Falta') THEN 1 ELSE 0 END), 0) AS faltas_registradas
         FROM estudiantes e
         LEFT JOIN asistencias a
             ON a.id_estudiante = e.id_estudiante
             AND a.fecha BETWEEN :inicio AND :fin
         WHERE e.estado = 'Activo'
         AND e.id_periodo = :id_periodo
         GROUP BY e.id_estudiante",
        [
            'inicio' => $inicioPeriodoDashboard,
            'fin' => $finEvaluadoDashboard,
            'id_periodo' => $idPeriodoActualDashboard
        ]
    );

    foreach ($resumenAlertasDashboard as $filaAlertaDashboard) {
        $diasConRegistro = (int) ($filaAlertaDashboard['dias_con_registro'] ?? 0);
        $faltasRegistradas = (int) ($filaAlertaDashboard['faltas_registradas'] ?? 0);
        $faltasSinRegistro = max(0, $diasEvaluadosDashboard - $diasConRegistro);
        $faltasCalculadas = $faltasRegistradas + $faltasSinRegistro;
        $porcentajeInasistencia = ($faltasCalculadas / $diasEvaluadosDashboard) * 100;

        if ($porcentajeInasistencia >= $porcentajeAlertaCriticaDashboard) {
            $alertasCriticas++;
        }
    }
}

$ultimaMarcacion = ejecutar_fila_dashboard(
    $pdo,
    "SELECT a.id_asistencia,
            a.fecha,
            a.hora_entrada,
            a.estado_entrada,
            a.hora_salida,
            a.estado_salida,
            a.metodo_registro,
            e.codigo_estudiante,
            e.nombres,
            e.apellidos,
            h.id_sensor AS id_huella
     FROM asistencias a
     INNER JOIN estudiantes e ON a.id_estudiante = e.id_estudiante
     LEFT JOIN huellas h ON e.id_estudiante = h.id_estudiante
     ORDER BY a.fecha DESC, COALESCE(a.hora_salida, a.hora_entrada, '00:00:00') DESC
     LIMIT 1"
);

$ultimosRegistros = ejecutar_lista_dashboard(
    $pdo,
    "SELECT a.id_asistencia,
            a.fecha,
            a.hora_entrada,
            a.estado_entrada,
            a.hora_salida,
            a.estado_salida,
            a.metodo_registro,
            e.codigo_estudiante,
            e.nombres,
            e.apellidos,
            h.id_sensor AS id_huella
     FROM asistencias a
     INNER JOIN estudiantes e ON a.id_estudiante = e.id_estudiante
     LEFT JOIN huellas h ON e.id_estudiante = h.id_estudiante
     ORDER BY a.fecha DESC, COALESCE(a.hora_salida, a.hora_entrada, '00:00:00') DESC
     LIMIT 8"
);

$diasSemana = [
    ['dia' => 'Lun', 'fecha' => date('Y-m-d', strtotime('monday this week'))],
    ['dia' => 'Mar', 'fecha' => date('Y-m-d', strtotime('tuesday this week'))],
    ['dia' => 'Mié', 'fecha' => date('Y-m-d', strtotime('wednesday this week'))],
    ['dia' => 'Jue', 'fecha' => date('Y-m-d', strtotime('thursday this week'))],
    ['dia' => 'Vie', 'fecha' => date('Y-m-d', strtotime('friday this week'))]
];

$datosSemana = [];

foreach ($diasSemana as $dia) {
    $filaDia = ejecutar_fila_dashboard(
        $pdo,
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN estado_entrada = 'Puntual' THEN 1 ELSE 0 END) AS puntuales,
            SUM(CASE WHEN estado_entrada = 'Tardanza' THEN 1 ELSE 0 END) AS tardanzas,
            SUM(CASE WHEN estado_entrada IN ('Falto', 'Falta') THEN 1 ELSE 0 END) AS faltas
         FROM asistencias
         WHERE fecha = :fecha",
        ['fecha' => $dia['fecha']]
    );

    $datosSemana[] = [
        'dia' => $dia['dia'],
        'fecha' => $dia['fecha'],
        'total' => valor_dashboard($filaDia['total'] ?? 0),
        'puntuales' => valor_dashboard($filaDia['puntuales'] ?? 0),
        'tardanzas' => valor_dashboard($filaDia['tardanzas'] ?? 0),
        'faltas' => valor_dashboard($filaDia['faltas'] ?? 0)
    ];
}

$resumenSemana = ejecutar_fila_dashboard(
    $pdo,
    "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN estado_entrada = 'Puntual' THEN 1 ELSE 0 END) AS puntuales,
        SUM(CASE WHEN estado_entrada = 'Tardanza' THEN 1 ELSE 0 END) AS tardanzas,
        SUM(CASE WHEN estado_entrada IN ('Falto', 'Falta') THEN 1 ELSE 0 END) AS faltas
     FROM asistencias
     WHERE fecha BETWEEN :inicio AND :fin",
    [
        'inicio' => $inicioSemana,
        'fin' => $finSemana
    ]
);

$totalSemana = max(1, valor_dashboard($resumenSemana['total'] ?? 0));
$puntualesSemana = valor_dashboard($resumenSemana['puntuales'] ?? 0);
$tardanzasSemana = valor_dashboard($resumenSemana['tardanzas'] ?? 0);
$faltasSemana = valor_dashboard($resumenSemana['faltas'] ?? 0);

$porcentajePuntuales = round(($puntualesSemana / $totalSemana) * 100);
$porcentajeTardanzas = round(($tardanzasSemana / $totalSemana) * 100);
$porcentajeFaltas = round(($faltasSemana / $totalSemana) * 100);

$porcentajeAsistenciaHoy = ($hayClasesHoyDashboard && $totalEstudiantes > 0) ? min(100, round(($asistenciasHoy / $totalEstudiantes) * 100)) : 0;
$porcentajePuntualidadHoy = $asistenciasHoy > 0 ? min(100, round(($puntualesHoy / $asistenciasHoy) * 100)) : 0;
$porcentajeTardanzaHoy = $asistenciasHoy > 0 ? min(100, round(($tardanzasHoy / $asistenciasHoy) * 100)) : 0;
$porcentajeFaltasHoy = ($hayClasesHoyDashboard && $totalEstudiantes > 0) ? min(100, round(($faltasHoy / $totalEstudiantes) * 100)) : 0;
$porcentajeSalidasPendientes = $asistenciasHoy > 0 ? min(100, round(($salidasPendientes / $asistenciasHoy) * 100)) : 0;
$porcentajeAlertas = $totalEstudiantes > 0 ? min(100, round(($alertasCriticas / $totalEstudiantes) * 100)) : 0;

$menuAdministrador = [
    ['titulo' => 'Panel General', 'url' => 'dashboard.php', 'key' => 'dashboard', 'icono' => 'M4 6h16M4 12h16M4 18h16'],
    ['titulo' => 'Estudiantes', 'url' => 'estudiantes.php', 'key' => 'estudiantes', 'icono' => 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM4 21a8 8 0 0 1 16 0'],
    ['titulo' => 'Docentes', 'url' => 'docentes.php', 'key' => 'docentes', 'icono' => 'M4 19.5A2.5 2.5 0 0 1 6.5 17H20M8 7h8M8 11h8M8 15h5'],
    ['titulo' => 'Cursos y Horarios', 'url' => 'cursos_horarios.php', 'key' => 'cursos', 'icono' => 'M5 4h14v16H5zM8 8h8M8 12h8M8 16h5'],
    ['titulo' => 'Asistencia', 'url' => 'asistencia.php', 'key' => 'asistencia', 'icono' => 'M9 11l3 3L22 4M5 5h11M5 19h14'],
    ['titulo' => 'Reportes', 'url' => 'reportes.php', 'key' => 'reportes', 'icono' => 'M4 19V5M4 19h16M8 15l3-4 4 3 5-8'],
    ['titulo' => 'Alertas', 'url' => 'alertas.php', 'key' => 'alertas', 'icono' => 'M12 3 2 20h20L12 3ZM12 9v5M12 17h.01'],
    ['titulo' => 'Usuarios', 'url' => 'usuarios.php', 'key' => 'usuarios', 'icono' => 'M16 21v-2a4 4 0 0 0-8 0v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M22 21v-2a4 4 0 0 0-3-3.87'],
    ['titulo' => 'Configuración', 'url' => 'configuracion.php', 'key' => 'configuracion', 'icono' => 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM19.4 15a1.65 1.65 0 0 0 .33 1.82M4.27 7.12A1.65 1.65 0 0 0 4.6 9']
];

$menuDocente = [
    ['titulo' => 'Panel Docente', 'url' => 'dashboard.php', 'key' => 'dashboard', 'icono' => 'M4 6h16M4 12h16M4 18h16'],
    ['titulo' => 'Estudiantes', 'url' => 'estudiantes.php', 'key' => 'estudiantes', 'icono' => 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM4 21a8 8 0 0 1 16 0'],
    ['titulo' => 'Cursos y Horarios', 'url' => 'cursos_horarios.php', 'key' => 'cursos', 'icono' => 'M5 4h14v16H5zM8 8h8M8 12h8M8 16h5'],
    ['titulo' => 'Asistencia', 'url' => 'asistencia.php', 'key' => 'asistencia', 'icono' => 'M9 11l3 3L22 4M5 5h11M5 19h14'],
    ['titulo' => 'Reportes', 'url' => 'reportes.php', 'key' => 'reportes', 'icono' => 'M4 19V5M4 19h16M8 15l3-4 4 3 5-8'],
    ['titulo' => 'Alertas', 'url' => 'alertas.php', 'key' => 'alertas', 'icono' => 'M12 3 2 20h20L12 3ZM12 9v5M12 17h.01'],
    ['titulo' => 'Perfil', 'url' => 'perfil.php', 'key' => 'perfil', 'icono' => 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM4 21a8 8 0 0 1 16 0']
];

$menuActivo = $rol === 'Administrador' ? $menuAdministrador : $menuDocente;

$estadisticas = [
    [
        'clase' => 'stat-blue',
        'icono' => '👥',
        'chip' => 'Base activa',
        'titulo' => 'Estudiantes registrados',
        'valor' => $totalEstudiantes,
        'progreso' => $totalEstudiantes > 0 ? 100 : 0
    ],
    [
        'clase' => 'stat-cyan',
        'icono' => '✅',
        'chip' => $hayClasesHoyDashboard ? 'Hoy' : 'Sin clases',
        'titulo' => 'Asistencias de hoy',
        'valor' => $asistenciasHoy,
        'progreso' => $porcentajeAsistenciaHoy
    ],
    [
        'clase' => 'stat-green',
        'icono' => '⏱',
        'chip' => $porcentajePuntualidadHoy . '%',
        'titulo' => 'Puntuales hoy',
        'valor' => $puntualesHoy,
        'progreso' => $porcentajePuntualidadHoy
    ],
    [
        'clase' => 'stat-orange',
        'icono' => '⚠',
        'chip' => 'Control',
        'titulo' => 'Tardanzas hoy',
        'valor' => $tardanzasHoy,
        'progreso' => $porcentajeTardanzaHoy
    ],
    [
        'clase' => 'stat-red',
        'icono' => '✕',
        'chip' => $hayClasesHoyDashboard ? 'Ausencias' : 'Sin clases',
        'titulo' => 'Faltas hoy',
        'valor' => $faltasHoy,
        'progreso' => $porcentajeFaltasHoy
    ],
    [
        'clase' => 'stat-cyan',
        'icono' => '↗',
        'chip' => $hayClasesHoyDashboard ? 'Salida' : 'Sin clases',
        'titulo' => 'Salidas pendientes',
        'valor' => $salidasPendientes,
        'progreso' => $porcentajeSalidasPendientes
    ],
    [
        'clase' => 'stat-red',
        'icono' => '🔔',
        'chip' => $porcentajeAlertaCriticaDashboard . '%',
        'titulo' => 'Alertas por inasistencias',
        'valor' => $alertasCriticas,
        'progreso' => $porcentajeAlertas
    ],
    [
        'clase' => 'stat-blue',
        'icono' => '%',
        'chip' => 'Resumen',
        'titulo' => 'Asistencia general hoy',
        'valor' => $hayClasesHoyDashboard ? $porcentajeAsistenciaHoy . '%' : 'Sin clases',
        'progreso' => $porcentajeAsistenciaHoy
    ]
];

$datosDashboard = [
    'periodo' => $nombrePeriodoDashboard,
    'hay_clases_hoy' => $hayClasesHoyDashboard,
    'dias_evaluados_periodo' => $diasEvaluadosDashboard,
    'semana' => $datosSemana,
    'general' => [
        'puntuales' => $porcentajePuntuales,
        'tardanzas' => $porcentajeTardanzas,
        'faltas' => $porcentajeFaltas
    ]
];

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BioAsistencia - Panel General</title>
    <link rel="icon" type="image/png" href="<?php echo app_url('assets/img/logo.png?v=1'); ?>">
    <link rel="shortcut icon" type="image/png" href="<?php echo app_url('assets/img/logo.png?v=1'); ?>">
    <link rel="stylesheet" href="<?php echo app_url('assets/css/styles.css?v=9999'); ?>">
</head>
<body class="pagina-interna pagina-dashboard dashboard-premium">

    <div class="dashboard-fondo" aria-hidden="true">
        <span class="dashboard-orbita dashboard-orbita-uno"></span>
        <span class="dashboard-orbita dashboard-orbita-dos"></span>
        <span class="dashboard-grid-bg"></span>
    </div>

   <?php
   $menuActual = 'dashboard';
   require __DIR__ . '/../includes/sidebar.php';
   ?>

    <div class="premium-main">
        <header class="premium-topbar reveal-card">
            <div class="premium-page-title">
                <div class="premium-breadcrumb">BioAsistencia <span>/</span> Dashboard</div>
                <h1>Panel General</h1>
                <p>Resumen real del control biométrico y asistencia estudiantil.</p>
            </div>

            <div class="premium-topbar-actions">
                <div class="premium-system-pill indicador-<?php echo htmlspecialchars($claseEstadoDispositivo, ENT_QUOTES, 'UTF-8'); ?>">
                    <span class="punto-indicador"></span>
                    <?php echo htmlspecialchars($estadoDispositivo, ENT_QUOTES, 'UTF-8'); ?>
                </div>

                <div class="premium-clock-pill">
                    <strong id="dashboardClock"><?php echo htmlspecialchars($horaActual, ENT_QUOTES, 'UTF-8'); ?></strong>
                    <span><?php echo htmlspecialchars(date('d/m/Y'), ENT_QUOTES, 'UTF-8'); ?></span>
                </div>

                <div class="premium-top-user">
                    <div class="premium-avatar premium-avatar-small">
                        <?php echo htmlspecialchars(inicial_usuario_dashboard($nombreUsuario), ENT_QUOTES, 'UTF-8'); ?>
                    </div>

                    <div>
                        <strong><?php echo htmlspecialchars($nombreUsuario, ENT_QUOTES, 'UTF-8'); ?></strong>
                        <span><?php echo htmlspecialchars($rol, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                </div>

                <a href="<?php echo app_url('logout.php'); ?>" class="premium-logout">Cerrar Sesión</a>
            </div>
        </header>

        <main class="premium-content">
            <section class="premium-hero reveal-card">
                <div class="premium-hero-texto">
                    <span class="premium-eyebrow"><i></i> Centro de monitoreo biométrico</span>
                    <h2>Control biométrico conectado para asistencia académica.</h2>
                    <p>Supervisa estudiantes, marcaciones reales, estados y reportes con una experiencia moderna, segura y visual.</p>
                </div>

                <div class="premium-hero-biometrico">
                    <div class="premium-bioid">
                        <span></span>
                        <span></span>
                        <span></span>
                        <strong>BioID</strong>
                    </div>

                    <div class="premium-hero-datos">
                        <div>
                            <span>Fecha</span>
                            <strong><?php echo htmlspecialchars(date('d/m/Y'), ENT_QUOTES, 'UTF-8'); ?></strong>
                        </div>

                        <div>
                            <span>Periodo</span>
                            <strong><?php echo htmlspecialchars($nombrePeriodoDashboard, ENT_QUOTES, 'UTF-8'); ?></strong>
                        </div>

                        <div>
                            <span>Estado</span>
                            <strong><?php echo htmlspecialchars($estadoDispositivo, ENT_QUOTES, 'UTF-8'); ?></strong>
                        </div>
                    </div>
                </div>
            </section>

            <section class="premium-stats-grid">
                <?php foreach ($estadisticas as $estadistica): ?>
                    <article class="premium-stat-card <?php echo htmlspecialchars($estadistica['clase'], ENT_QUOTES, 'UTF-8'); ?> reveal-card" style="--progress: <?php echo (int) $estadistica['progreso']; ?>%;">
                        <div class="premium-stat-icon">
                            <?php echo htmlspecialchars($estadistica['icono'], ENT_QUOTES, 'UTF-8'); ?>
                        </div>

                        <div class="premium-stat-chip">
                            <?php echo htmlspecialchars($estadistica['chip'], ENT_QUOTES, 'UTF-8'); ?>
                        </div>

                        <span><?php echo htmlspecialchars($estadistica['titulo'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <strong><?php echo htmlspecialchars((string) $estadistica['valor'], ENT_QUOTES, 'UTF-8'); ?></strong>

                        <div class="premium-stat-progress">
                            <i></i>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>

            <section class="premium-dashboard-grid">
                <article class="premium-panel premium-last-mark reveal-card">
                    <div class="premium-panel-head">
                        <div>
                            <span class="premium-eyebrow"><i></i> Última validación</span>
                            <h3>Última marcación registrada</h3>
                        </div>

                        <a href="asistencia.php" class="premium-panel-link">Ver asistencia →</a>
                    </div>

                    <?php if (empty($ultimaMarcacion)): ?>
                        <div class="premium-empty-state">
                            <div>
                                <div class="premium-empty-icon">⌁</div>
                                <strong>No hay registros disponibles</strong>
                                <p>Cuando exista una nueva marcación de asistencia, aparecerá aquí.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="premium-last-mark-content">
                            <div class="premium-last-avatar">
                                <?php echo htmlspecialchars(inicial_usuario_dashboard($ultimaMarcacion['nombres'] ?? 'E'), ENT_QUOTES, 'UTF-8'); ?>
                            </div>

                            <div class="premium-last-info">
                                <strong>
                                    <?php echo htmlspecialchars(($ultimaMarcacion['nombres'] ?? '') . ' ' . ($ultimaMarcacion['apellidos'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                </strong>
                                <span><?php echo htmlspecialchars($ultimaMarcacion['codigo_estudiante'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></span>
                                <p>
                                    Entrada: <?php echo htmlspecialchars(hora_corta_dashboard($ultimaMarcacion['hora_entrada'] ?? null), ENT_QUOTES, 'UTF-8'); ?>
                                    · Salida: <?php echo htmlspecialchars(hora_corta_dashboard($ultimaMarcacion['hora_salida'] ?? null), ENT_QUOTES, 'UTF-8'); ?>
                                </p>
                                <span class="premium-badge <?php echo htmlspecialchars(estado_visual_dashboard($ultimaMarcacion['estado_entrada'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars($ultimaMarcacion['estado_entrada'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </div>
                        </div>
                    <?php endif; ?>
                </article>

                <article class="premium-panel premium-actions-panel reveal-card">
                    <div class="premium-panel-head">
                        <div>
                            <span class="premium-eyebrow"><i></i> Accesos rápidos</span>
                            <h3>Operaciones</h3>
                        </div>
                    </div>

                    <div class="premium-operaciones">
                        <?php if ($rol === 'Administrador'): ?>
                            <a href="estudiantes.php">+ Nuevo estudiante</a>
                        <?php endif; ?>
                        <a href="asistencia.php">✓ Ver asistencia</a>
                        <a href="../exports/exportar_reporte_excel.php">Exportar reporte</a>
                        <a href="alertas.php">Ver alertas</a>
                    </div>
                </article>
            </section>

            <section class="premium-charts-grid">
                <article class="premium-panel premium-chart-panel reveal-card">
                    <div class="premium-panel-head compact-head">
                        <div>
                            <span class="premium-eyebrow"><i></i> Lunes a viernes</span>
                            <h3>Asistencia semanal</h3>
                        </div>
                    </div>

                    <canvas id="weeklyAttendanceChart" width="900" height="260"></canvas>
                </article>

                <article class="premium-panel premium-donut-panel reveal-card">
                    <div class="premium-panel-head compact-head">
                        <div>
                            <span class="premium-eyebrow"><i></i> Indicador general</span>
                            <h3>Puntualidad y faltas</h3>
                        </div>
                    </div>

                    <canvas id="generalAttendanceChart" width="160" height="160"></canvas>

                    <div class="premium-donut-legend">
                        <div><i class="legend-green"></i> Puntuales <strong><?php echo $porcentajePuntuales; ?>%</strong></div>
                        <div><i class="legend-orange"></i> Tardanzas <strong><?php echo $porcentajeTardanzas; ?>%</strong></div>
                        <div><i class="legend-red"></i> Faltas <strong><?php echo $porcentajeFaltas; ?>%</strong></div>
                    </div>
                </article>
            </section>

            <section class="premium-panel premium-records-panel reveal-card">
                <div class="premium-panel-head">
                    <div>
                        <span class="premium-eyebrow"><i></i> Actividad reciente</span>
                        <h3>Últimos registros de asistencia</h3>
                    </div>

                    <a href="asistencia.php" class="premium-panel-link">Ver todos →</a>
                </div>

                <div class="premium-table-wrap">
                    <table class="premium-record-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Código</th>
                                <th>Estudiante</th>
                                <th>ID Huella</th>
                                <th>Fecha</th>
                                <th>Entrada</th>
                                <th>Estado</th>
                                <th>Método</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (count($ultimosRegistros) === 0): ?>
                                <tr>
                                    <td colspan="8" class="premium-empty-row">No hay registros biométricos disponibles</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($ultimosRegistros as $indice => $registro): ?>
                                    <tr>
                                        <td><?php echo $indice + 1; ?></td>
                                        <td><?php echo htmlspecialchars($registro['codigo_estudiante'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td>
                                            <strong>
                                                <?php echo htmlspecialchars(($registro['nombres'] ?? '') . ' ' . ($registro['apellidos'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                            </strong>
                                        </td>
                                        <td><?php echo htmlspecialchars(texto_seguro_dashboard($registro['id_huella'] ?? '', 'Sin asignar'), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars($registro['fecha'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars(hora_corta_dashboard($registro['hora_entrada'] ?? null), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td>
                                            <span class="premium-badge <?php echo htmlspecialchars(estado_visual_dashboard($registro['estado_entrada'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                                <?php echo htmlspecialchars($registro['estado_entrada'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="premium-method-badge">
                                                <?php echo htmlspecialchars($registro['metodo_registro'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>

    <script>
        window.bioDashboardData = <?php echo json_encode($datosDashboard, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    </script>
    <script src="<?php echo app_url('assets/js/dashboard.js?v=501'); ?>"></script>
    <script src="<?php echo app_url('assets/js/main.js?v=9999'); ?>"></script>
</body>
</html>