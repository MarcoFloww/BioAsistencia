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

function fecha_valida_reporte(string $fecha): bool
{
    $partes = explode('-', $fecha);

    if (count($partes) !== 3) {
        return false;
    }

    return checkdate((int) $partes[1], (int) $partes[2], (int) $partes[0]);
}

function clase_estado_reporte(?string $estado): string
{
    $estado = strtolower((string) $estado);

    if (str_contains($estado, 'puntual') || str_contains($estado, 'registrada') || str_contains($estado, 'completada')) {
        return 'estado-puntual';
    }

    if (str_contains($estado, 'tardanza') || str_contains($estado, 'anticipada') || str_contains($estado, 'pendiente')) {
        return 'estado-tardanza';
    }

    if (str_contains($estado, 'falto') || str_contains($estado, 'falta') || str_contains($estado, 'no aplica')) {
        return 'estado-falto';
    }

    return 'estado-pendiente';
}

function texto_seguro(?string $texto): string
{
    return htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
}

function porcentaje_reporte(int $valor, int $total): int
{
    if ($total <= 0) {
        return 0;
    }

    return (int) round(($valor / $total) * 100);
}

$inicioSemana = date('Y-m-d', strtotime('monday this week'));
$finSemana = date('Y-m-d', strtotime('friday this week'));

if (isset($_GET['semana_inicio'], $_GET['semana_fin'])) {
    $inicioTemporal = limpiar_texto($_GET['semana_inicio']);
    $finTemporal = limpiar_texto($_GET['semana_fin']);

    if (!fecha_valida_reporte($inicioTemporal) || !fecha_valida_reporte($finTemporal)) {
        $mensajeError = 'Las fechas ingresadas no son válidas.';
    } elseif ($inicioTemporal > $finTemporal) {
        $mensajeError = 'La fecha inicial no puede ser mayor que la fecha final.';
    } else {
        $inicioSemana = $inicioTemporal;
        $finSemana = $finTemporal;
    }
}

$consultaReporte = $pdo->prepare(
    "SELECT a.id_asistencia,
            a.fecha,
            a.hora_entrada,
            a.estado_entrada,
            a.hora_salida,
            a.estado_salida,
            a.metodo_registro,
            a.observacion,
            e.codigo_estudiante,
            e.nombres,
            e.apellidos,
            e.programa_estudios,
            COALESCE(NULLIF(SUBSTRING_INDEX(p.nombre_periodo, ' ', 1), ''), e.ciclo) AS ciclo_reporte,
            COALESCE(p.nombre_periodo, 'Sin periodo académico') AS periodo_academico,
            h.id_sensor AS id_huella
     FROM asistencias a
     INNER JOIN estudiantes e ON a.id_estudiante = e.id_estudiante
     LEFT JOIN periodos_academicos p
        ON a.fecha BETWEEN p.fecha_inicio AND p.fecha_fin
     LEFT JOIN (
         SELECT id_estudiante, MIN(id_sensor) AS id_sensor
         FROM huellas
         WHERE estado = 'Activa'
         GROUP BY id_estudiante
     ) h ON e.id_estudiante = h.id_estudiante
     WHERE a.fecha BETWEEN :inicio_semana AND :fin_semana
     ORDER BY a.fecha ASC, e.codigo_estudiante ASC"
);

$consultaReporte->execute([
    'inicio_semana' => $inicioSemana,
    'fin_semana' => $finSemana
]);

$listaReporte = $consultaReporte->fetchAll();

$periodosDelReporte = [];
foreach ($listaReporte as $registroPeriodo) {
    $nombrePeriodoFila = trim((string) ($registroPeriodo['periodo_academico'] ?? ''));
    if ($nombrePeriodoFila !== '' && $nombrePeriodoFila !== 'Sin periodo académico') {
        $periodosDelReporte[$nombrePeriodoFila] = true;
    }
}

if (count($periodosDelReporte) > 0) {
    $textoPeriodosReporte = implode(' / ', array_keys($periodosDelReporte));
} else {
    $consultaPeriodoRango = $pdo->prepare(
        "SELECT nombre_periodo
         FROM periodos_academicos
         WHERE fecha_inicio <= :fin
         AND fecha_fin >= :inicio
         ORDER BY fecha_inicio ASC"
    );
    $consultaPeriodoRango->execute([
        'inicio' => $inicioSemana,
        'fin' => $finSemana
    ]);
    $periodosRango = $consultaPeriodoRango->fetchAll(PDO::FETCH_COLUMN);
    $textoPeriodosReporte = count($periodosRango) > 0
        ? implode(' / ', $periodosRango)
        : 'Sin periodo académico asociado';
}

$consultaHistorial = $pdo->query(
    "SELECT id_reporte,
            nombre_reporte,
            tipo_reporte,
            COALESCE(fecha_inicio, semana_inicio) AS inicio_reporte,
            COALESCE(fecha_fin, semana_fin) AS fin_reporte,
            COALESCE(fecha_generado, fecha_generacion) AS fecha_generado
     FROM reportes
     ORDER BY COALESCE(fecha_generado, fecha_generacion) DESC, id_reporte DESC"
);

$historial = $consultaHistorial->fetchAll();

$consultaResumen = $pdo->prepare(
    "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN estado_entrada = 'Puntual' THEN 1 ELSE 0 END) AS puntuales,
        SUM(CASE WHEN estado_entrada = 'Tardanza' THEN 1 ELSE 0 END) AS tardanzas,
        SUM(CASE WHEN estado_entrada IN ('Falto', 'Falta') THEN 1 ELSE 0 END) AS faltas
     FROM asistencias
     WHERE fecha BETWEEN :inicio_semana AND :fin_semana"
);

$consultaResumen->execute([
    'inicio_semana' => $inicioSemana,
    'fin_semana' => $finSemana
]);

$resumen = $consultaResumen->fetch();

$totalRegistros = (int) ($resumen['total'] ?? 0);
$totalPuntuales = (int) ($resumen['puntuales'] ?? 0);
$totalTardanzas = (int) ($resumen['tardanzas'] ?? 0);
$totalFaltas = (int) ($resumen['faltas'] ?? 0);
$totalHistorial = count($historial);
$porcentajePuntuales = porcentaje_reporte($totalPuntuales, $totalRegistros);
$porcentajeTardanzas = porcentaje_reporte($totalTardanzas, $totalRegistros);
$porcentajeFaltas = porcentaje_reporte($totalFaltas, $totalRegistros);

$menuAdministrador = [
    ['Panel General', 'dashboard.php', 'panel'],
    ['Estudiantes', 'estudiantes.php', 'estudiantes'],
    ['Docentes', 'docentes.php', 'docentes'],
    ['Cursos y Horarios', 'cursos_horarios.php', 'cursos'],
    ['Asistencia', 'asistencia.php', 'asistencia'],
    ['Reportes', 'reportes.php', 'reportes'],
    ['Alertas', 'alertas.php', 'alertas'],
    ['Usuarios', 'usuarios.php', 'usuarios'],
    ['Configuración', 'configuracion.php', 'configuracion']
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
    <title>BioAsistencia - Reportes</title>
    <link rel="icon" type="image/png" href="<?php echo app_url('assets/img/logo.png?v=1'); ?>">
    <link rel="shortcut icon" type="image/png" href="<?php echo app_url('assets/img/logo.png?v=1'); ?>">
    <link rel="stylesheet" href="<?php echo app_url('assets/css/styles.css?v=9999'); ?>">
</head>
<body class="pagina-interna pagina-premium pagina-reportes">

    <div class="fondo-panel-premium" aria-hidden="true">
        <span class="fondo-orbe fondo-orbe-uno"></span>
        <span class="fondo-orbe fondo-orbe-dos"></span>
        <span class="fondo-linea fondo-linea-uno"></span>
        <span class="fondo-linea fondo-linea-dos"></span>
        <span class="fondo-grid"></span>
    </div>

   <?php
   $menuActual = 'reportes';
   require __DIR__ . '/../includes/sidebar.php';
   ?>

    <div class="contenido-principal contenido-premium">
        <header class="barra-superior topbar-premium">
            <div class="topbar-titulos">
                <span class="breadcrumb-premium">BioAsistencia / Reportes</span>
                <h1 class="titulo-pagina">Reportes</h1>
            </div>

            <div class="acciones-superiores">
<div class="indicador-dispositivo indicador-<?php echo htmlspecialchars($claseEstadoDispositivo, ENT_QUOTES, 'UTF-8'); ?>">
    <span class="punto-indicador"></span>
    <?php echo htmlspecialchars($estadoDispositivo, ENT_QUOTES, 'UTF-8'); ?>
</div>

                <div class="usuario-premium">
                    <div class="avatar-usuario-premium">
                        <?php echo texto_seguro(mb_strtoupper(mb_substr($nombreUsuario, 0, 1, 'UTF-8'), 'UTF-8')); ?>
                    </div>

                    <div class="usuario-premium-texto">
                        <span><?php echo texto_seguro($nombreUsuario); ?></span>
                        <small><?php echo texto_seguro($rol); ?></small>
                    </div>

                    <a href="<?php echo app_url('logout.php'); ?>" class="boton-cerrar-sesion">Cerrar Sesión</a>
                </div>
            </div>
        </header>

        <main class="area-contenido area-premium">
            <?php if ($mensajeExito !== ''): ?>
                <div class="mensaje-alerta mensaje-exito">
                    <?php echo texto_seguro($mensajeExito); ?>
                </div>
            <?php endif; ?>

            <?php if ($mensajeError !== ''): ?>
                <div class="mensaje-alerta mensaje-error">
                    <?php echo texto_seguro($mensajeError); ?>
                </div>
            <?php endif; ?>

            <section class="hero-modulo hero-reportes">
                <div class="hero-modulo-contenido">
                    <span class="chip-modulo">Centro de reportes</span>

                    <h2>Resumen semanal de asistencia académica</h2>

                    <p>
                        Consulta, filtra, cierra y exporta los registros de asistencia de BioAsistencia
                        con un control semanal claro y profesional.
                    </p>

                    <div class="hero-modulo-detalles">
                        <span>Rango: <?php echo texto_seguro($inicioSemana); ?> al <?php echo texto_seguro($finSemana); ?></span>
                        <span>Periodo: <?php echo texto_seguro($textoPeriodosReporte); ?></span>
                        <span>Registros: <?php echo $totalRegistros; ?></span>
                        <span>Historial: <?php echo $totalHistorial; ?> reportes</span>
                    </div>
                </div>

                <div class="hero-modulo-visual">
                    <div class="scanner-reporte">
                        <span></span>
                        <strong><?php echo $totalRegistros; ?></strong>
                        <small>registros</small>
                    </div>
                </div>
            </section>

            <section class="dashboard-stat-grid resumen-premium">
                <article class="stat-card stat-blue">
                    <div class="stat-head">
                        <div class="stat-icon">📄</div>
                        <span class="stat-chip">Semana</span>
                    </div>

                    <div class="stat-label">Registros encontrados</div>
                    <div class="stat-number"><?php echo $totalRegistros; ?></div>

                    <div class="stat-bar">
                        <span style="width: <?php echo $totalRegistros > 0 ? '100' : '0'; ?>%;"></span>
                    </div>
                </article>

                <article class="stat-card stat-green">
                    <div class="stat-head">
                        <div class="stat-icon">✅</div>
                        <span class="stat-chip">Puntualidad</span>
                    </div>

                    <div class="stat-label">Puntuales</div>
                    <div class="stat-number"><?php echo $totalPuntuales; ?></div>

                    <div class="stat-bar">
                        <span style="width: <?php echo $porcentajePuntuales; ?>%;"></span>
                    </div>
                </article>

                <article class="stat-card stat-orange">
                    <div class="stat-head">
                        <div class="stat-icon">⏱</div>
                        <span class="stat-chip">Control</span>
                    </div>

                    <div class="stat-label">Tardanzas</div>
                    <div class="stat-number"><?php echo $totalTardanzas; ?></div>

                    <div class="stat-bar">
                        <span style="width: <?php echo $porcentajeTardanzas; ?>%;"></span>
                    </div>
                </article>

                <article class="stat-card stat-red">
                    <div class="stat-head">
                        <div class="stat-icon">🚫</div>
                        <span class="stat-chip">Riesgo</span>
                    </div>

                    <div class="stat-label">Faltas</div>
                    <div class="stat-number"><?php echo $totalFaltas; ?></div>

                    <div class="stat-bar">
                        <span style="width: <?php echo $porcentajeFaltas; ?>%;"></span>
                    </div>
                </article>
            </section>

            <section class="tarjeta-panel tarjeta-premium panel-reporte-control">
                <div class="panel-cabecera-premium">
                    <div>
                        <span class="subtitulo-panel">Filtro semanal</span>
                        <h2 class="titulo-tarjeta-panel">Rango de reporte</h2>
                    </div>

                    <div class="acciones-panel-premium">
                        <a href="../exports/exportar_reporte_excel.php?inicio=<?php echo urlencode($inicioSemana); ?>&fin=<?php echo urlencode($finSemana); ?>" class="boton-primario">
                            Descargar reporte
                        </a>
                    </div>
                </div>

                <form method="GET" action="reportes.php" class="formulario-busqueda formulario-premium-grid">
                    <div class="grupo-campo compacto">
                        <label for="semana_inicio">Fecha inicial</label>
                        <input type="date" id="semana_inicio" name="semana_inicio" value="<?php echo texto_seguro($inicioSemana); ?>">
                    </div>

                    <div class="grupo-campo compacto">
                        <label for="semana_fin">Fecha final</label>
                        <input type="date" id="semana_fin" name="semana_fin" value="<?php echo texto_seguro($finSemana); ?>">
                    </div>

                    <div class="acciones-filtro-premium">
                        <button type="submit" class="boton-secundario">Buscar</button>
                        <a href="reportes.php" class="boton-secundario">Semana actual</a>
                    </div>
                </form>
            </section>

            <section class="tarjeta-panel tarjeta-premium">
                <div class="panel-cabecera-premium">
                    <div>
                        <span class="subtitulo-panel">Detalle académico</span>
                        <h2 class="titulo-tarjeta-panel">
                            Reporte del <?php echo texto_seguro($inicioSemana); ?> al <?php echo texto_seguro($finSemana); ?>
                        </h2>
                    </div>
                </div>

                <div class="tabla-responsive-premium">
                    <table class="tabla-datos tabla-premium">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Estudiante</th>
                                <th>ID Huella</th>
                                <th>Periodo</th>
                                <th>Fecha</th>
                                <th>Entrada</th>
                                <th>Estado entrada</th>
                                <th>Salida</th>
                                <th>Estado salida</th>
                                <th>Método</th>
                                <th>Observación</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (count($listaReporte) === 0): ?>
                                <tr>
                                    <td colspan="11" class="texto-sin-datos">
                                        No hay registros disponibles para esta semana
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($listaReporte as $registro): ?>
                                    <tr>
                                        <td>
                                            <span class="codigo-chip"><?php echo texto_seguro($registro['codigo_estudiante']); ?></span>
                                        </td>

                                        <td>
                                            <div class="celda-identidad">
                                                <span class="avatar-tabla">
                                                    <?php echo texto_seguro(mb_strtoupper(mb_substr($registro['nombres'], 0, 1, 'UTF-8'), 'UTF-8')); ?>
                                                </span>

                                                <div>
                                                    <strong><?php echo texto_seguro($registro['nombres'] . ' ' . $registro['apellidos']); ?></strong>
                                                    <small><?php echo texto_seguro($registro['programa_estudios'] . ' - ' . $registro['ciclo_reporte']); ?></small>
                                                </div>
                                            </div>
                                        </td>

                                        <td>
                                            <?php if ($registro['id_huella']): ?>
                                                <span class="huella-chip">ID <?php echo texto_seguro($registro['id_huella']); ?></span>
                                            <?php else: ?>
                                                <span class="huella-chip huella-pendiente">Sin asignar</span>
                                            <?php endif; ?>
                                        </td>

                                        <td><?php echo texto_seguro($registro['periodo_academico']); ?></td>

                                        <td><?php echo texto_seguro($registro['fecha']); ?></td>

                                        <td>
                                            <?php echo $registro['hora_entrada'] ? texto_seguro(substr($registro['hora_entrada'], 0, 5)) : '-'; ?>
                                        </td>

                                        <td>
                                            <span class="etiqueta-estado <?php echo clase_estado_reporte($registro['estado_entrada']); ?>">
                                                <?php echo texto_seguro($registro['estado_entrada']); ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?php echo $registro['hora_salida'] ? texto_seguro(substr($registro['hora_salida'], 0, 5)) : '-'; ?>
                                        </td>

                                        <td>
                                            <span class="etiqueta-estado <?php echo clase_estado_reporte($registro['estado_salida']); ?>">
                                                <?php echo texto_seguro($registro['estado_salida']); ?>
                                            </span>
                                        </td>

                                        <td>
                                            <span class="metodo-chip"><?php echo texto_seguro($registro['metodo_registro']); ?></span>
                                        </td>

                                        <td><?php echo texto_seguro($registro['observacion'] ?? '-'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="tarjeta-panel tarjeta-premium">
                <div class="panel-cabecera-premium">
                    <div>
                        <span class="subtitulo-panel">Historial</span>
                        <h2 class="titulo-tarjeta-panel">Reportes guardados</h2>
                    </div>
                </div>

                <div class="tabla-responsive-premium">
                    <table class="tabla-datos tabla-premium">
                        <thead>
                            <tr>
                                <th>Reporte</th>
                                <th>Tipo</th>
                                <th>Rango</th>
                                <th>Generado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (count($historial) === 0): ?>
                                <tr>
                                    <td colspan="5" class="texto-sin-datos">
                                        No hay reportes guardados
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($historial as $reporte): ?>
                                    <tr>
                                        <td><?php echo texto_seguro($reporte['nombre_reporte'] ?: 'Reporte de asistencias'); ?></td>

                                        <td>
                                            <span class="codigo-chip"><?php echo texto_seguro($reporte['tipo_reporte'] ?: 'Semanal'); ?></span>
                                        </td>

                                        <td>
                                            <?php echo texto_seguro(($reporte['inicio_reporte'] ?? '-') . ' al ' . ($reporte['fin_reporte'] ?? '-')); ?>
                                        </td>

                                        <td><?php echo texto_seguro($reporte['fecha_generado'] ?? '-'); ?></td>

                                        <td>
                                            <div class="acciones-tabla">
                                                <a href="reportes.php?semana_inicio=<?php echo urlencode((string) $reporte['inicio_reporte']); ?>&semana_fin=<?php echo urlencode((string) $reporte['fin_reporte']); ?>" class="boton-secundario boton-mini">
                                                    Ver
                                                </a>

                                                <a href="../exports/exportar_reporte_excel.php?inicio=<?php echo urlencode((string) $reporte['inicio_reporte']); ?>&fin=<?php echo urlencode((string) $reporte['fin_reporte']); ?>" class="boton-secundario boton-mini">
                                                    Descargar
                                                </a>
                                            </div>
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

    <script src="<?php echo app_url('assets/js/main.js?v=9999'); ?>"></script>
</body>
</html>