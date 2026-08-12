<?php

date_default_timezone_set('America/Lima');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/funciones.php';

requerir_rol(['Administrador', 'Docente']);

$rol = obtener_rol_usuario();
$nombreUsuario = obtener_nombre_usuario();
$estadoDispositivo = obtener_estado_dispositivo($pdo);
$claseEstadoDispositivo = clase_estado_dispositivo($estadoDispositivo);
$mensajeExito = '';
$mensajeError = '';

function fecha_valida_alerta(string $fecha): bool
{
    $partes = explode('-', $fecha);

    if (count($partes) !== 3) {
        return false;
    }

    return checkdate((int) $partes[1], (int) $partes[2], (int) $partes[0]);
}

function obtener_configuracion_alerta(PDO $pdo, string $clave, string $predeterminado): string
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
            : $predeterminado;
    } catch (Throwable $e) {
        return $predeterminado;
    }
}

function segundos_hora_alerta(string $hora): int
{
    $partes = array_map('intval', explode(':', $hora));

    return (($partes[0] ?? 0) * 3600)
        + (($partes[1] ?? 0) * 60)
        + ($partes[2] ?? 0);
}

function obtener_dias_clase_alerta(PDO $pdo): array
{
    try {
        $consulta = $pdo->query(
            "SELECT DISTINCT dia_semana
             FROM horarios
             WHERE tipo_actividad = 'Clase'
             AND estado = 'Activo'"
        );

        $dias = [];

        foreach ($consulta->fetchAll(PDO::FETCH_COLUMN) as $dia) {
            $dias[(string) $dia] = true;
        }

        return $dias;
    } catch (Throwable $e) {
        return [
            'Lunes' => true,
            'Martes' => true,
            'Miércoles' => true,
            'Jueves' => true,
            'Viernes' => true
        ];
    }
}

function contar_dias_clase_alerta(string $inicio, string $fin, array $diasClase): int
{
    if (!fecha_valida_alerta($inicio) || !fecha_valida_alerta($fin)) {
        return 0;
    }

    $fechaInicio = new DateTimeImmutable($inicio);
    $fechaFin = new DateTimeImmutable($fin);

    if ($fechaInicio > $fechaFin) {
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

    $periodo = new DatePeriod(
        $fechaInicio,
        new DateInterval('P1D'),
        $fechaFin->modify('+1 day')
    );

    $total = 0;

    foreach ($periodo as $fecha) {
        $numeroDia = (int) $fecha->format('N');
        $nombreDia = $nombresDias[$numeroDia] ?? '';

        if ($nombreDia !== '' && isset($diasClase[$nombreDia])) {
            $total++;
        }
    }

    return $total;
}

function porcentaje_alerta(int $cantidad, int $total): float
{
    if ($total <= 0) {
        return 0;
    }

    return round(($cantidad / $total) * 100, 2);
}

function nivel_riesgo_alerta(float $porcentaje, int $diasEvaluados, int $umbralCritico): string
{
    if ($diasEvaluados <= 0) {
        return 'Sin datos';
    }

    $umbralCritico = max(1, min(100, $umbralCritico));
    $umbralRiesgo = max(1, $umbralCritico - 5);
    $umbralAtencion = max(1, $umbralCritico - 10);

    if ($porcentaje >= $umbralCritico) {
        return 'Alerta crítica';
    }

    if ($porcentaje >= $umbralRiesgo) {
        return 'Riesgo';
    }

    if ($porcentaje >= $umbralAtencion) {
        return 'Atención';
    }

    return 'Normal';
}

function clase_riesgo_alerta(string $nivel): string
{
    return match ($nivel) {
        'Alerta crítica' => 'estado-falto alerta-critica',
        'Riesgo' => 'estado-falto alerta-riesgo',
        'Atención' => 'estado-tardanza alerta-atencion',
        'Normal' => 'estado-puntual alerta-normal',
        default => 'estado-pendiente alerta-sin-datos'
    };
}

function recomendacion_alerta(string $nivel, int $umbralCritico): string
{
    $umbralRiesgo = max(1, $umbralCritico - 5);
    $umbralAtencion = max(1, $umbralCritico - 10);

    return match ($nivel) {
        'Alerta crítica' => 'El estudiante alcanzó el ' . $umbralCritico . '% o más de inasistencias en los días de clase ya evaluados. Requiere revisión inmediata.',
        'Riesgo' => 'Realizar seguimiento inmediato. Su inasistencia ya alcanzó al menos el ' . $umbralRiesgo . '% y está cerca del nivel crítico.',
        'Atención' => 'Realizar seguimiento preventivo. Su inasistencia ya alcanzó al menos el ' . $umbralAtencion . '%.',
        'Normal' => 'El estudiante mantiene un nivel de asistencia aceptable en los días de clase ya evaluados.',
        default => 'Todavía no existen días de clase cerrados suficientes para calcular el riesgo.'
    };
}

function normalizar_whatsapp_alerta(?string $whatsapp): string
{
    $numero = preg_replace('/\D+/', '', (string) $whatsapp);

    if ($numero === '') {
        return '';
    }

    if (strlen($numero) === 9) {
        return '51' . $numero;
    }

    return $numero;
}

function construir_mensaje_alerta(array $estudiante, string $inicio, string $fin): string
{
    return 'Hola, se informa que el estudiante ' . $estudiante['estudiante'] .
        ' presenta ' . $estudiante['porcentaje_inasistencia'] .
        '% de inasistencias acumuladas en los días de clase evaluados del periodo ' .
        $inicio . ' al ' . $fin . '. Nivel: ' . $estudiante['nivel_riesgo'] .
        '. Se recomienda revisar su asistencia en BioAsistencia.';
}

function sincronizar_alerta_inasistencia(
    PDO $pdo,
    int $idEstudiante,
    int $idPeriodo,
    string $nombrePeriodo,
    float $porcentaje,
    string $nivelRiesgo,
    string $descripcion
): void {
    if ($idEstudiante <= 0 || $idPeriodo <= 0) {
        return;
    }

    try {
        $buscar = $pdo->prepare(
            "SELECT id_alerta, estado
             FROM alertas
             WHERE id_estudiante = :id_estudiante
             AND id_periodo = :id_periodo
             AND tipo_alerta = 'Falta'
             ORDER BY id_alerta DESC
             LIMIT 1"
        );

        $buscar->execute([
            'id_estudiante' => $idEstudiante,
            'id_periodo' => $idPeriodo
        ]);

        $existente = $buscar->fetch();
        $esRiesgoActivo = in_array($nivelRiesgo, ['Atención', 'Riesgo', 'Alerta crítica'], true);

        if (!$esRiesgoActivo) {
            if ($existente) {
                $actualizar = $pdo->prepare(
                    "UPDATE alertas
                     SET porcentaje_inasistencia = :porcentaje,
                         nivel_riesgo = :nivel_riesgo,
                         descripcion = :descripcion,
                         estado = CASE
                             WHEN estado IN ('Activa', 'Pendiente') THEN 'Resuelta'
                             ELSE estado
                         END
                     WHERE id_alerta = :id_alerta"
                );

                $actualizar->execute([
                    'porcentaje' => $porcentaje,
                    'nivel_riesgo' => $nivelRiesgo,
                    'descripcion' => $descripcion,
                    'id_alerta' => (int) $existente['id_alerta']
                ]);
            }

            return;
        }

        $nivelAlerta = match ($nivelRiesgo) {
            'Alerta crítica' => 'Crítico',
            'Riesgo' => 'Seguimiento',
            default => 'Informativo'
        };

        $titulo = 'Riesgo de inasistencia - ' . $nombrePeriodo;

        if ($existente) {
            $actualizar = $pdo->prepare(
                "UPDATE alertas
                 SET nivel_alerta = :nivel_alerta,
                     porcentaje_inasistencia = :porcentaje,
                     nivel_riesgo = :nivel_riesgo,
                     titulo = :titulo,
                     descripcion = :descripcion,
                     estado = CASE
                         WHEN estado IN ('Atendida', 'Archivada') THEN estado
                         ELSE 'Activa'
                     END
                 WHERE id_alerta = :id_alerta"
            );

            $actualizar->execute([
                'nivel_alerta' => $nivelAlerta,
                'porcentaje' => $porcentaje,
                'nivel_riesgo' => $nivelRiesgo,
                'titulo' => $titulo,
                'descripcion' => $descripcion,
                'id_alerta' => (int) $existente['id_alerta']
            ]);

            return;
        }

        $insertar = $pdo->prepare(
            "INSERT INTO alertas
             (id_estudiante, id_periodo, tipo_alerta, nivel_alerta, porcentaje_inasistencia, nivel_riesgo, titulo, descripcion, estado)
             VALUES
             (:id_estudiante, :id_periodo, 'Falta', :nivel_alerta, :porcentaje, :nivel_riesgo, :titulo, :descripcion, 'Activa')"
        );

        $insertar->execute([
            'id_estudiante' => $idEstudiante,
            'id_periodo' => $idPeriodo,
            'nivel_alerta' => $nivelAlerta,
            'porcentaje' => $porcentaje,
            'nivel_riesgo' => $nivelRiesgo,
            'titulo' => $titulo,
            'descripcion' => $descripcion
        ]);
    } catch (Throwable $e) {
        return;
    }
}

$fechaHoy = date('Y-m-d');
$horaActual = date('H:i:s');
$periodosAcademicos = [];
$diasClase = obtener_dias_clase_alerta($pdo);
$porcentajeAlertaCritica = (int) obtener_configuracion_alerta($pdo, 'porcentaje_alerta', '30');
$porcentajeAlertaCritica = max(1, min(100, $porcentajeAlertaCritica));
$horaSalidaOficial = obtener_configuracion_alerta($pdo, 'hora_salida_oficial', '19:00:00');
$toleranciaSalida = (int) obtener_configuracion_alerta($pdo, 'tolerancia_salida_minutos', '15');
$segundosCierre = segundos_hora_alerta($horaSalidaOficial) + (max(0, $toleranciaSalida) * 60);
$segundosActuales = segundos_hora_alerta($horaActual);

try {
    $consultaPeriodos = $pdo->query(
        "SELECT id_periodo,
                nombre_periodo,
                fecha_inicio,
                fecha_fin,
                estado
         FROM periodos_academicos
         ORDER BY fecha_inicio ASC"
    );

    $periodosAcademicos = $consultaPeriodos->fetchAll();
} catch (Throwable $e) {
    $periodosAcademicos = [];
    $mensajeError = 'No se encontró la tabla de periodos académicos.';
}

$idPeriodoSeleccionado = (int) ($_GET['periodo'] ?? 0);
$periodoAcademico = null;

if ($idPeriodoSeleccionado > 0) {
    foreach ($periodosAcademicos as $periodo) {
        if ((int) $periodo['id_periodo'] === $idPeriodoSeleccionado) {
            $periodoAcademico = $periodo;
            break;
        }
    }
}

if (!$periodoAcademico) {
    foreach ($periodosAcademicos as $periodo) {
        if ($fechaHoy >= $periodo['fecha_inicio'] && $fechaHoy <= $periodo['fecha_fin']) {
            $periodoAcademico = $periodo;
            break;
        }
    }
}

if (!$periodoAcademico && count($periodosAcademicos) > 0) {
    foreach (array_reverse($periodosAcademicos) as $periodo) {
        if ($periodo['fecha_inicio'] <= $fechaHoy) {
            $periodoAcademico = $periodo;
            break;
        }
    }

    if (!$periodoAcademico) {
        $periodoAcademico = $periodosAcademicos[0];
    }
}

if ($periodoAcademico) {
    $idPeriodoSeleccionado = (int) $periodoAcademico['id_periodo'];
    $nombrePeriodo = (string) $periodoAcademico['nombre_periodo'];
    $inicioPeriodo = (string) $periodoAcademico['fecha_inicio'];
    $finPeriodo = (string) $periodoAcademico['fecha_fin'];
} else {
    $idPeriodoSeleccionado = 0;
    $nombrePeriodo = 'Sin periodo académico';
    $inicioPeriodo = $fechaHoy;
    $finPeriodo = $fechaHoy;
}

$finRegistros = date('Y-m-d', strtotime($inicioPeriodo . ' -1 day'));

if ($periodoAcademico) {
    if ($fechaHoy > $finPeriodo) {
        $finRegistros = $finPeriodo;
    } elseif ($fechaHoy >= $inicioPeriodo) {
        $numeroDiaHoy = (int) date('N');
        $nombresDias = [
            1 => 'Lunes',
            2 => 'Martes',
            3 => 'Miércoles',
            4 => 'Jueves',
            5 => 'Viernes',
            6 => 'Sábado',
            7 => 'Domingo'
        ];
        $nombreDiaHoy = $nombresDias[$numeroDiaHoy] ?? '';
        $hoyTieneClase = $nombreDiaHoy !== '' && isset($diasClase[$nombreDiaHoy]);

        if (!$hoyTieneClase || $segundosActuales > $segundosCierre) {
            $finRegistros = min($fechaHoy, $finPeriodo);
        } else {
            $finRegistros = date('Y-m-d', strtotime($fechaHoy . ' -1 day'));
        }
    }
}

$totalDiasCiclo = $periodoAcademico
    ? contar_dias_clase_alerta($inicioPeriodo, $finPeriodo, $diasClase)
    : 0;

$diasTranscurridos = $periodoAcademico
    ? contar_dias_clase_alerta($inicioPeriodo, $finRegistros, $diasClase)
    : 0;

$busqueda = limpiar_texto($_GET['buscar'] ?? '');
$filtroRiesgo = limpiar_texto($_GET['riesgo'] ?? '');

$parametros = [
    'inicio' => $inicioPeriodo,
    'fin' => $finRegistros,
    'id_periodo_actual' => $idPeriodoSeleccionado,
    'inicio_historial' => $inicioPeriodo,
    'fin_historial' => $finPeriodo,
    'id_periodo_alerta' => $idPeriodoSeleccionado
];

$sqlBusqueda = "WHERE (
    (e.id_periodo = :id_periodo_actual AND e.estado = 'Activo')
    OR EXISTS (
        SELECT 1
        FROM asistencias ah
        WHERE ah.id_estudiante = e.id_estudiante
        AND ah.fecha BETWEEN :inicio_historial AND :fin_historial
    )
    OR EXISTS (
        SELECT 1
        FROM alertas alh
        WHERE alh.id_estudiante = e.id_estudiante
        AND alh.id_periodo = :id_periodo_alerta
    )
)";

if ($busqueda !== '') {
    $sqlBusqueda .= " AND (
        e.codigo_estudiante LIKE :buscar
        OR e.nombres LIKE :buscar
        OR e.apellidos LIKE :buscar
        OR e.correo_institucional LIKE :buscar
    )";

    $parametros['buscar'] = '%' . $busqueda . '%';
}

$consultaAlertas = $pdo->prepare(
    "SELECT e.id_estudiante,
            e.codigo_estudiante,
            e.nombres,
            e.apellidos,
            e.whatsapp,
            e.correo_institucional,
            e.fecha_registro AS fecha_alta_sistema,
            h.id_sensor AS id_huella,
            COUNT(DISTINCT a.fecha) AS dias_con_registro,
            COALESCE(SUM(CASE WHEN a.estado_entrada = 'Puntual' THEN 1 ELSE 0 END), 0) AS puntuales,
            COALESCE(SUM(CASE WHEN a.estado_entrada = 'Tardanza' THEN 1 ELSE 0 END), 0) AS tardanzas,
            COALESCE(SUM(CASE WHEN a.estado_entrada IN ('Falto', 'Falta') THEN 1 ELSE 0 END), 0) AS faltas_registradas
     FROM estudiantes e
     LEFT JOIN asistencias a ON e.id_estudiante = a.id_estudiante
        AND a.fecha BETWEEN :inicio AND :fin
     LEFT JOIN (
         SELECT id_estudiante, MIN(id_sensor) AS id_sensor
         FROM huellas
         WHERE estado = 'Activa'
         GROUP BY id_estudiante
     ) h ON e.id_estudiante = h.id_estudiante
     $sqlBusqueda
     GROUP BY e.id_estudiante,
              e.codigo_estudiante,
              e.nombres,
              e.apellidos,
              e.whatsapp,
              e.correo_institucional,
              e.fecha_registro,
              h.id_sensor
     ORDER BY e.codigo_estudiante ASC"
);

$consultaAlertas->execute($parametros);
$registrosAlertas = $consultaAlertas->fetchAll();

$listaAlertas = [];
$totalEstudiantes = 0;
$totalCriticos = 0;
$totalRiesgo = 0;
$totalAtencion = 0;
$totalNormales = 0;
$totalSinHuella = 0;
$totalSinContacto = 0;

foreach ($registrosAlertas as $registro) {
    $totalEstudiantes++;

    $fechaAltaSistema = substr((string) ($registro['fecha_alta_sistema'] ?? ''), 0, 10);
    $inicioEvaluacion = $inicioPeriodo;

    if (fecha_valida_alerta($fechaAltaSistema) && $fechaAltaSistema > $inicioEvaluacion) {
        $inicioEvaluacion = $fechaAltaSistema;
    }

    $diasEvaluadosEstudiante = contar_dias_clase_alerta(
        $inicioEvaluacion,
        $finRegistros,
        $diasClase
    );

    $diasTotalesEstudiante = contar_dias_clase_alerta(
        $inicioEvaluacion,
        $finPeriodo,
        $diasClase
    );

    $diasConRegistro = (int) $registro['dias_con_registro'];
    $faltasRegistradas = (int) $registro['faltas_registradas'];
    $faltasSinRegistro = max(0, $diasEvaluadosEstudiante - $diasConRegistro);
    $faltasCalculadas = $faltasRegistradas + $faltasSinRegistro;
    $tardanzas = (int) $registro['tardanzas'];
    $puntuales = (int) $registro['puntuales'];
    $porcentajeInasistencia = porcentaje_alerta($faltasCalculadas, $diasEvaluadosEstudiante);
    $porcentajeTardanza = porcentaje_alerta($tardanzas, $diasEvaluadosEstudiante);
    $nivelRiesgo = nivel_riesgo_alerta(
        $porcentajeInasistencia,
        $diasEvaluadosEstudiante,
        $porcentajeAlertaCritica
    );
    $recomendacion = recomendacion_alerta($nivelRiesgo, $porcentajeAlertaCritica);
    $whatsappNormalizado = normalizar_whatsapp_alerta($registro['whatsapp'] ?? null);
    $correo = trim((string) ($registro['correo_institucional'] ?? ''));
    $sinHuella = empty($registro['id_huella']);
    $sinContacto = $whatsappNormalizado === '' && $correo === '';

    if ($periodoAcademico && $diasEvaluadosEstudiante > 0) {
        sincronizar_alerta_inasistencia(
            $pdo,
            (int) $registro['id_estudiante'],
            $idPeriodoSeleccionado,
            $nombrePeriodo,
            $porcentajeInasistencia,
            $nivelRiesgo,
            $recomendacion
        );
    }

    if ($nivelRiesgo === 'Alerta crítica') {
        $totalCriticos++;
    } elseif ($nivelRiesgo === 'Riesgo') {
        $totalRiesgo++;
    } elseif ($nivelRiesgo === 'Atención') {
        $totalAtencion++;
    } elseif ($nivelRiesgo === 'Normal') {
        $totalNormales++;
    }

    if ($sinHuella) {
        $totalSinHuella++;
    }

    if ($sinContacto) {
        $totalSinContacto++;
    }

    $estudiante = [
        'id_estudiante' => (int) $registro['id_estudiante'],
        'codigo_estudiante' => $registro['codigo_estudiante'],
        'estudiante' => trim($registro['nombres'] . ' ' . $registro['apellidos']),
        'nombres' => $registro['nombres'],
        'apellidos' => $registro['apellidos'],
        'whatsapp' => $registro['whatsapp'],
        'whatsapp_normalizado' => $whatsappNormalizado,
        'correo_institucional' => $correo,
        'id_huella' => $registro['id_huella'],
        'periodo' => $nombrePeriodo,
        'fecha_inicio_periodo' => $inicioPeriodo,
        'fecha_fin_periodo' => $finPeriodo,
        'dias_considerados' => $diasTotalesEstudiante,
        'dias_transcurridos' => $diasEvaluadosEstudiante,
        'dias_con_registro' => $diasConRegistro,
        'puntuales' => $puntuales,
        'tardanzas' => $tardanzas,
        'faltas_registradas' => $faltasRegistradas,
        'faltas_sin_registro' => $faltasSinRegistro,
        'faltas_calculadas' => $faltasCalculadas,
        'porcentaje_inasistencia' => $porcentajeInasistencia,
        'porcentaje_tardanza' => $porcentajeTardanza,
        'nivel_riesgo' => $nivelRiesgo,
        'recomendacion' => $recomendacion,
        'sin_huella' => $sinHuella,
        'sin_contacto' => $sinContacto
    ];

    if ($filtroRiesgo === '' || $filtroRiesgo === $nivelRiesgo) {
        $listaAlertas[] = $estudiante;
    }
}

$inicioSemana = $inicioPeriodo;
$finSemana = $finRegistros >= $inicioPeriodo ? $finRegistros : $inicioPeriodo;
$diasConsiderados = $diasTranscurridos;
$opcionesRiesgo = ['Normal', 'Atención', 'Riesgo', 'Alerta crítica', 'Sin datos'];

$menuAdministrador = [
    ['Panel General', 'dashboard.php', 'panel'],
    ['Estudiantes', 'estudiantes.php', 'estudiantes'],
    ['Docentes', 'docentes.php', 'docentes'],
    ['Cursos y Horarios', 'cursos_horarios.php', 'cursos'],
    ['Asistencia', 'asistencia.php', 'asistencia'],
    ['Reportes', 'reportes.php', 'reportes'],
    ['Alertas', 'alertas.php', 'alertas'],
    ['Usuarios', 'usuarios.php', 'usuarios'],
    ['Configuración', 'configuracion.php', 'configuracion'],
    ['Perfil', 'perfil.php', 'perfil']
];

$menuDocente = [
    ['Panel Docente', 'dashboard.php', 'panel'],
    ['Estudiantes', 'estudiantes.php', 'estudiantes'],
    ['Cursos y Horarios', 'cursos_horarios.php', 'cursos'],
    ['Asistencia', 'asistencia.php', 'asistencia'],
    ['Reportes', 'reportes.php', 'reportes'],
    ['Alertas', 'alertas.php', 'alertas'],
    ['Perfil', 'perfil.php', 'perfil']
];

$menuActivo = $rol === 'Administrador' ? $menuAdministrador : $menuDocente;

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BioAsistencia - Alertas</title>
    <link rel="icon" type="image/png" href="<?php echo app_url('assets/img/logo.png?v=1'); ?>">
    <link rel="shortcut icon" type="image/png" href="<?php echo app_url('assets/img/logo.png?v=1'); ?>">
    <link rel="stylesheet" href="<?php echo app_url('assets/css/styles.css?v=9999'); ?>">
</head>
<body class="pagina-interna pagina-alertas">

   <?php
   $menuActual = 'alertas';
   require __DIR__ . '/../includes/sidebar.php';
   ?>

    <div class="contenido-principal">
        <header class="barra-superior">
            <div>
                <span class="breadcrumb-premium">BioAsistencia / Supervisión</span>
                <h1 class="titulo-pagina">Alertas</h1>
            </div>

            <div class="acciones-superiores">
<div class="indicador-dispositivo indicador-<?php echo htmlspecialchars($claseEstadoDispositivo, ENT_QUOTES, 'UTF-8'); ?>">
    <span class="punto-indicador"></span>
    <?php echo htmlspecialchars($estadoDispositivo, ENT_QUOTES, 'UTF-8'); ?>
</div>

                <div class="menu-usuario">
                    <span class="nombre-usuario-superior"><?php echo htmlspecialchars($nombreUsuario, ENT_QUOTES, 'UTF-8'); ?></span>
                    <a href="<?php echo app_url('logout.php'); ?>" class="boton-cerrar-sesion">Cerrar Sesión</a>
                </div>
            </div>
        </header>

        <main class="area-contenido">
            <?php if ($mensajeExito !== ''): ?>
                <div class="mensaje-alerta mensaje-exito">
                    <?php echo htmlspecialchars($mensajeExito, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <?php if ($mensajeError !== ''): ?>
                <div class="mensaje-alerta mensaje-error">
                    <?php echo htmlspecialchars($mensajeError, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <section class="modulo-hero modulo-hero-alertas">
                <div class="modulo-hero-contenido">
                    <span class="modulo-kicker">Monitor de riesgo académico</span>
                    <h2>Alertas calculadas por inasistencias</h2>
                    <p>
                        Consulta las faltas registradas y los días de clase cerrados sin marcación para detectar quiénes necesitan seguimiento preventivo.
                    </p>

                    <div class="modulo-hero-detalles">
                        <span>Periodo: <?php echo htmlspecialchars($nombrePeriodo, ENT_QUOTES, 'UTF-8'); ?></span>
                        <span>Rango: <?php echo htmlspecialchars($inicioSemana, ENT_QUOTES, 'UTF-8'); ?> al <?php echo htmlspecialchars($finSemana, ENT_QUOTES, 'UTF-8'); ?></span>
                        <span>Días del ciclo: <?php echo (int) $totalDiasCiclo; ?></span>
                        <span>Evaluados hasta hoy: <?php echo (int) $diasTranscurridos; ?></span>
                        <span>Regla crítica: <?php echo (int) $porcentajeAlertaCritica; ?>% o más</span>
                    </div>
                </div>

                <div class="modulo-hero-panel">
                    <span class="panel-mini-titulo">Alertas críticas</span>
                    <strong><?php echo (int) $totalCriticos; ?></strong>
                    <p>Estudiantes con 30% o más de inasistencias.</p>
                </div>
            </section>

            <section class="dashboard-stat-grid resumen-premium">
                <article class="stat-card">
                    <div class="stat-head">
                        <div class="stat-icon">👥</div>
                        <span class="stat-chip">Evaluados</span>
                    </div>
                    <div class="stat-label">Estudiantes evaluados</div>
                    <div class="stat-number"><?php echo (int) $totalEstudiantes; ?></div>
                    <div class="stat-bar"><span style="width:100%"></span></div>
                </article>

                <article class="stat-card stat-red">
                    <div class="stat-head">
                        <div class="stat-icon">🚨</div>
                        <span class="stat-chip">Crítico</span>
                    </div>
                    <div class="stat-label">Alerta crítica</div>
                    <div class="stat-number"><?php echo (int) $totalCriticos; ?></div>
                    <div class="stat-bar">
                        <span style="width:<?php echo $totalEstudiantes > 0 ? round(($totalCriticos / $totalEstudiantes) * 100) : 0; ?>%"></span>
                    </div>
                </article>

                <article class="stat-card stat-orange">
                    <div class="stat-head">
                        <div class="stat-icon">⚠️</div>
                        <span class="stat-chip">Seguimiento</span>
                    </div>
                    <div class="stat-label">Riesgo / Atención</div>
                    <div class="stat-number"><?php echo (int) ($totalRiesgo + $totalAtencion); ?></div>
                    <div class="stat-bar">
                        <span style="width:<?php echo $totalEstudiantes > 0 ? round((($totalRiesgo + $totalAtencion) / $totalEstudiantes) * 100) : 0; ?>%"></span>
                    </div>
                </article>

                <article class="stat-card stat-blue">
                    <div class="stat-head">
                        <div class="stat-icon">🧬</div>
                        <span class="stat-chip">Biometría</span>
                    </div>
                    <div class="stat-label">Sin huella asignada</div>
                    <div class="stat-number"><?php echo (int) $totalSinHuella; ?></div>
                    <div class="stat-bar">
                        <span style="width:<?php echo $totalEstudiantes > 0 ? round(($totalSinHuella / $totalEstudiantes) * 100) : 0; ?>%"></span>
                    </div>
                </article>
            </section>

            <section class="tarjeta-panel panel-premium">
                <div class="barra-filtros barra-filtros-premium">
                    <form method="GET" action="alertas.php" class="formulario-busqueda formulario-busqueda-premium">
                        <select name="periodo">
                            <?php foreach ($periodosAcademicos as $periodo): ?>
                                <option value="<?php echo (int) $periodo['id_periodo']; ?>" <?php echo (int) $periodo['id_periodo'] === $idPeriodoSeleccionado ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($periodo['nombre_periodo'] . ' | ' . $periodo['fecha_inicio'] . ' al ' . $periodo['fecha_fin'], ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>

                            <?php if (count($periodosAcademicos) === 0): ?>
                                <option value="">Sin periodos académicos registrados</option>
                            <?php endif; ?>
                        </select>

                        <input type="text" name="buscar" placeholder="Buscar por código, nombre o correo" value="<?php echo htmlspecialchars($busqueda, ENT_QUOTES, 'UTF-8'); ?>">

                        <select name="riesgo">
                            <option value="">Todos los niveles</option>
                            <?php foreach ($opcionesRiesgo as $opcion): ?>
                                <option value="<?php echo htmlspecialchars($opcion, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $filtroRiesgo === $opcion ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($opcion, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <button type="submit" class="boton-secundario">Buscar</button>
                        <a href="alertas.php" class="boton-secundario">Periodo actual</a>
                    </form>
                </div>

                <div class="tabla-responsive">
                    <table class="tabla-datos tabla-premium">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Estudiante</th>
                                <th>ID Huella</th>
                                <th>Días ciclo</th>
                                <th>Faltas</th>
                                <th>Tardanzas</th>
                                <th>% Inasistencia</th>
                                <th>Nivel</th>
                                <th>Contacto</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (count($listaAlertas) === 0): ?>
                                <tr>
                                    <td colspan="10" class="texto-sin-datos">No hay estudiantes que coincidan con los filtros seleccionados</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($listaAlertas as $estudiante): ?>
                                    <?php
                                    $mensajeContacto = construir_mensaje_alerta($estudiante, $inicioSemana, $finSemana);
                                    $urlWhatsapp = $estudiante['whatsapp_normalizado'] !== ''
                                        ? 'https://wa.me/' . $estudiante['whatsapp_normalizado'] . '?text=' . urlencode($mensajeContacto)
                                        : '';
                                    $urlCorreo = $estudiante['correo_institucional'] !== ''
                                        ? 'mailto:' . rawurlencode($estudiante['correo_institucional']) . '?subject=' . rawurlencode('Alerta de asistencia - BioAsistencia') . '&body=' . rawurlencode($mensajeContacto)
                                        : '';

                                    $datosDetalle = htmlspecialchars(
                                        json_encode($estudiante, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    );
                                    ?>
                                    <tr>
                                        <td>
                                            <span class="codigo-chip">
                                                <?php echo htmlspecialchars($estudiante['codigo_estudiante'], ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="celda-usuario">
                                                <span class="avatar-tabla">
                                                    <?php echo htmlspecialchars(mb_substr($estudiante['nombres'], 0, 1) . mb_substr($estudiante['apellidos'], 0, 1), ENT_QUOTES, 'UTF-8'); ?>
                                                </span>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($estudiante['estudiante'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                                    <small><?php echo htmlspecialchars($estudiante['correo_institucional'] !== '' ? $estudiante['correo_institucional'] : 'Correo pendiente', ENT_QUOTES, 'UTF-8'); ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="etiqueta-estado <?php echo $estudiante['id_huella'] ? 'estado-puntual' : 'estado-pendiente'; ?>">
                                                <?php echo $estudiante['id_huella'] ? htmlspecialchars((string) $estudiante['id_huella'], ENT_QUOTES, 'UTF-8') : 'Sin huella'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo (int) $estudiante['dias_transcurridos']; ?> / <?php echo (int) $estudiante['dias_considerados']; ?>
                                        </td>
                                        <td>
                                            <?php echo (int) $estudiante['faltas_calculadas']; ?>
                                            <?php if ($estudiante['faltas_sin_registro'] > 0): ?>
                                                <small class="texto-secundario">(+<?php echo (int) $estudiante['faltas_sin_registro']; ?> sin registro)</small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo (int) $estudiante['tardanzas']; ?></td>
                                        <td>
                                            <div class="progreso-riesgo">
                                                <strong><?php echo htmlspecialchars((string) $estudiante['porcentaje_inasistencia'], ENT_QUOTES, 'UTF-8'); ?>%</strong>
                                                <span><i style="width:<?php echo min(100, (float) $estudiante['porcentaje_inasistencia']); ?>%"></i></span>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="etiqueta-estado <?php echo clase_riesgo_alerta($estudiante['nivel_riesgo']); ?>">
                                                <?php echo htmlspecialchars($estudiante['nivel_riesgo'], ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="acciones-contacto">
                                                <?php if ($urlWhatsapp !== ''): ?>
                                                    <a href="<?php echo htmlspecialchars($urlWhatsapp, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="boton-mini boton-whatsapp">WhatsApp</a>
                                                <?php endif; ?>

                                                <?php if ($urlCorreo !== ''): ?>
                                                    <a href="<?php echo htmlspecialchars($urlCorreo, ENT_QUOTES, 'UTF-8'); ?>" class="boton-mini boton-correo">Correo</a>
                                                <?php endif; ?>

                                                <?php if ($urlWhatsapp === '' && $urlCorreo === ''): ?>
                                                    <span class="etiqueta-estado estado-pendiente">Pendiente</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <button type="button" class="boton-secundario" onclick='abrirModalAlerta(<?php echo $datosDetalle; ?>)'>Detalle</button>
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

    <div class="fondo-modal" id="fondoModalAlerta" style="display:none;">
        <div class="tarjeta-panel modal-formulario modal-alerta-detalle">
            <h2 class="titulo-tarjeta-panel" id="modalAlertaTitulo">Detalle de alerta</h2>

            <div class="detalle-alerta-grid">
                <div class="detalle-alerta-item">
                    <span>Código</span>
                    <strong id="detalleCodigo">-</strong>
                </div>

                <div class="detalle-alerta-item">
                    <span>Estudiante</span>
                    <strong id="detalleEstudiante">-</strong>
                </div>

                <div class="detalle-alerta-item">
                    <span>Inasistencia</span>
                    <strong id="detallePorcentaje">-</strong>
                </div>

                <div class="detalle-alerta-item">
                    <span>Nivel</span>
                    <strong id="detalleNivel">-</strong>
                </div>

                <div class="detalle-alerta-item">
                    <span>Faltas calculadas</span>
                    <strong id="detalleFaltas">-</strong>
                </div>

                <div class="detalle-alerta-item">
                    <span>Tardanzas</span>
                    <strong id="detalleTardanzas">-</strong>
                </div>
            </div>

            <div class="detalle-alerta-recomendacion">
                <span>Recomendación</span>
                <p id="detalleRecomendacion">-</p>
            </div>

            <div class="acciones-panel">
                <button type="button" class="boton-secundario" onclick="cerrarModalAlerta()">Cerrar</button>
            </div>
        </div>
    </div>

    <script src="<?php echo app_url('assets/js/main.js?v=9999'); ?>"></script>
    <script>
        function abrirModalAlerta(datos) {
            document.getElementById('modalAlertaTitulo').textContent = 'Detalle de alerta';
            document.getElementById('detalleCodigo').textContent = datos.codigo_estudiante || '-';
            document.getElementById('detalleEstudiante').textContent = datos.estudiante || '-';
            document.getElementById('detallePorcentaje').textContent = datos.porcentaje_inasistencia + '%';
            document.getElementById('detalleNivel').textContent = datos.nivel_riesgo || '-';
            document.getElementById('detalleFaltas').textContent = datos.faltas_calculadas || '0';
            document.getElementById('detalleTardanzas').textContent = datos.tardanzas || '0';
            document.getElementById('detalleRecomendacion').textContent = datos.recomendacion || '-';
            document.getElementById('fondoModalAlerta').style.display = 'flex';
        }

        function cerrarModalAlerta() {
            document.getElementById('fondoModalAlerta').style.display = 'none';
        }
    </script>
</body>
</html>