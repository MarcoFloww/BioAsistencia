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

function obtener_valores_enum(PDO $pdo, string $tabla, string $columna): array
{
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
}

function fecha_valida(string $fecha): bool
{
    $partes = explode('-', $fecha);

    if (count($partes) !== 3) {
        return false;
    }

    return checkdate((int) $partes[1], (int) $partes[2], (int) $partes[0]);
}

function hora_valida_o_vacia(string $hora): bool
{
    if ($hora === '') {
        return true;
    }

    return preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $hora) === 1;
}

function clase_estado_asistencia(?string $estado): string
{
    $estado = strtolower((string) $estado);

    if (str_contains($estado, 'puntual') || str_contains($estado, 'registrada')) {
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

function icono_estado_asistencia(?string $estado): string
{
    $estado = strtolower((string) $estado);

    if (str_contains($estado, 'puntual') || str_contains($estado, 'registrada')) {
        return '●';
    }

    if (str_contains($estado, 'tardanza') || str_contains($estado, 'anticipada') || str_contains($estado, 'pendiente')) {
        return '▲';
    }

    if (str_contains($estado, 'falto') || str_contains($estado, 'falta') || str_contains($estado, 'no aplica')) {
        return '■';
    }

    return '◆';
}

$opcionesEstadoEntrada = obtener_valores_enum($pdo, 'asistencias', 'estado_entrada');
$opcionesEstadoSalida = obtener_valores_enum($pdo, 'asistencias', 'estado_salida');
$opcionesMetodoRegistro = obtener_valores_enum($pdo, 'asistencias', 'metodo_registro');

if (count($opcionesEstadoEntrada) === 0) {
    $opcionesEstadoEntrada = ['Puntual', 'Tardanza', 'Falto'];
}

if (count($opcionesEstadoSalida) === 0) {
    $opcionesEstadoSalida = ['Pendiente', 'Salida Registrada', 'Salida Anticipada', 'No aplica'];
}

if (count($opcionesMetodoRegistro) === 0) {
    $opcionesMetodoRegistro = ['Manual', 'Huella'];
}

$fechaHoy = date('Y-m-d');
$fechaSeleccionada = limpiar_texto($_GET['fecha'] ?? $fechaHoy);

if (!fecha_valida($fechaSeleccionada)) {
    $fechaSeleccionada = $fechaHoy;
}

$busqueda = limpiar_texto($_GET['buscar'] ?? '');
$estadoFiltro = limpiar_texto($_GET['estado'] ?? '');
$parametros = [
    'fecha' => $fechaSeleccionada
];

$sqlCondiciones = "WHERE a.fecha = :fecha";

if ($busqueda !== '') {
    $sqlCondiciones .= " AND (
        e.codigo_estudiante LIKE :buscar
        OR e.nombres LIKE :buscar
        OR e.apellidos LIKE :buscar
    )";

    $parametros['buscar'] = '%' . $busqueda . '%';
}

$estadosPermitidosFiltro = array_unique(array_merge($opcionesEstadoEntrada, $opcionesEstadoSalida));

if ($estadoFiltro !== '' && in_array($estadoFiltro, $estadosPermitidosFiltro, true)) {
    $sqlCondiciones .= " AND (a.estado_entrada = :estado_entrada_filtro OR a.estado_salida = :estado_salida_filtro)";
    $parametros['estado_entrada_filtro'] = $estadoFiltro;
    $parametros['estado_salida_filtro'] = $estadoFiltro;
}

$consultaAsistencias = $pdo->prepare(
    "SELECT a.id_asistencia,
            a.id_estudiante,
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
            h.id_sensor AS id_huella
     FROM asistencias a
     INNER JOIN estudiantes e ON a.id_estudiante = e.id_estudiante
     LEFT JOIN (
         SELECT id_estudiante, MIN(id_sensor) AS id_sensor
         FROM huellas
         WHERE estado = 'Activa'
         GROUP BY id_estudiante
     ) h ON e.id_estudiante = h.id_estudiante
     $sqlCondiciones
     ORDER BY e.codigo_estudiante ASC"
);

$consultaAsistencias->execute($parametros);
$listaAsistencias = $consultaAsistencias->fetchAll();

$consultaEstudiantes = $pdo->query(
    "SELECT id_estudiante, codigo_estudiante, nombres, apellidos
     FROM estudiantes
     WHERE estado = 'Activo'
     ORDER BY codigo_estudiante ASC"
);

$listaEstudiantes = $consultaEstudiantes->fetchAll();

$consultaResumen = $pdo->prepare(
    "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN estado_entrada = 'Puntual' THEN 1 ELSE 0 END) AS puntuales,
        SUM(CASE WHEN estado_entrada = 'Tardanza' THEN 1 ELSE 0 END) AS tardanzas,
        SUM(CASE WHEN estado_entrada = 'Falto' THEN 1 ELSE 0 END) AS faltas
     FROM asistencias
     WHERE fecha = :fecha"
);

$consultaResumen->execute([
    'fecha' => $fechaSeleccionada
]);

$resumen = $consultaResumen->fetch();

$totalRegistros = (int) ($resumen['total'] ?? 0);
$totalPuntuales = (int) ($resumen['puntuales'] ?? 0);
$totalTardanzas = (int) ($resumen['tardanzas'] ?? 0);
$totalFaltas = (int) ($resumen['faltas'] ?? 0);
$totalEstudiantesActivos = count($listaEstudiantes);
$porcentajeCobertura = $totalEstudiantesActivos > 0 ? round(($totalRegistros / $totalEstudiantesActivos) * 100) : 0;

$consultaUltimaMarcacion = $pdo->prepare(
    "SELECT a.id_asistencia,
            a.id_estudiante,
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
            h.id_sensor AS id_huella
     FROM asistencias a
     INNER JOIN estudiantes e ON a.id_estudiante = e.id_estudiante
     LEFT JOIN (
         SELECT id_estudiante, MIN(id_sensor) AS id_sensor
         FROM huellas
         WHERE estado = 'Activa'
         GROUP BY id_estudiante
     ) h ON e.id_estudiante = h.id_estudiante
     WHERE a.fecha = :fecha
     AND (
         a.hora_entrada IS NOT NULL
         OR a.hora_salida IS NOT NULL
     )
     ORDER BY COALESCE(a.hora_salida, a.hora_entrada) DESC,
              a.id_asistencia DESC
     LIMIT 1"
);

$consultaUltimaMarcacion->execute([
    'fecha' => $fechaSeleccionada
]);

$ultimaMarcacion = $consultaUltimaMarcacion->fetch() ?: null;

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
    <title>BioAsistencia - Asistencia</title>
    <link rel="icon" type="image/png" href="<?php echo app_url('assets/img/logo.png?v=1'); ?>">
    <link rel="shortcut icon" type="image/png" href="<?php echo app_url('assets/img/logo.png?v=1'); ?>">
    <link rel="stylesheet" href="<?php echo app_url('assets/css/styles.css?v=9999'); ?>">
</head>
<body class="pagina-interna pagina-asistencia premium-app">

    <div class="fondo-app" aria-hidden="true">
        <span class="fondo-luz fondo-luz-uno"></span>
        <span class="fondo-luz fondo-luz-dos"></span>
        <span class="fondo-grid"></span>
    </div>

   <?php
   $menuActual = 'asistencia';
   require __DIR__ . '/../includes/sidebar.php';
   ?>

    <div class="contenido-principal main-premium">
        <header class="barra-superior topbar-premium">
            <div class="topbar-title">
                <span class="breadcrumb-premium">BioAsistencia / Control diario</span>
                <h1 class="titulo-pagina">Asistencia</h1>
            </div>

            <div class="acciones-superiores topbar-actions">
<div class="indicador-dispositivo indicador-<?php echo htmlspecialchars($claseEstadoDispositivo, ENT_QUOTES, 'UTF-8'); ?>">
    <span class="punto-indicador"></span>
    <?php echo htmlspecialchars($estadoDispositivo, ENT_QUOTES, 'UTF-8'); ?>
</div>

                <div class="menu-usuario user-pill">
                    <span class="nombre-usuario-superior"><?php echo htmlspecialchars($nombreUsuario, ENT_QUOTES, 'UTF-8'); ?></span>
                    <a href="<?php echo app_url('logout.php'); ?>" class="boton-cerrar-sesion">Cerrar Sesión</a>
                </div>
            </div>
        </header>

        <main class="area-contenido content-premium">
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

            <section class="hero-modulo hero-asistencia">
                <div class="hero-modulo-contenido">
                    <span class="hero-chip">Módulo de asistencia</span>
                    <h2>Control diario de marcaciones biométricas reales.</h2>
                    <p>
                        Consulta las marcaciones reales recibidas desde el Arduino y el sensor de huella.
                        No se generan faltas ni registros manuales desde esta pantalla.
                    </p>
                </div>

                <div class="hero-modulo-panel">
                    <span>Fecha seleccionada</span>
                    <strong><?php echo htmlspecialchars($fechaSeleccionada, ENT_QUOTES, 'UTF-8'); ?></strong>
                    <small><?php echo $totalRegistros; ?> registros encontrados</small>
                </div>
            </section>

            <section class="stat-grid-premium resumen-asistencia">
                <article class="stat-card stat-blue">
                    <div class="stat-head">
                        <div class="stat-icon">▦</div>
                        <span class="stat-chip">Día</span>
                    </div>
                    <span class="stat-label">Registros</span>
                    <strong class="stat-number"><?php echo $totalRegistros; ?></strong>
                    <div class="stat-bar">
                        <span style="width: <?php echo min($porcentajeCobertura, 100); ?>%;"></span>
                    </div>
                </article>

                <article class="stat-card stat-green">
                    <div class="stat-head">
                        <div class="stat-icon">●</div>
                        <span class="stat-chip">Puntual</span>
                    </div>
                    <span class="stat-label">Puntuales</span>
                    <strong class="stat-number"><?php echo $totalPuntuales; ?></strong>
                    <div class="stat-bar">
                        <span style="width: <?php echo $totalRegistros > 0 ? round(($totalPuntuales / $totalRegistros) * 100) : 0; ?>%;"></span>
                    </div>
                </article>

                <article class="stat-card stat-orange">
                    <div class="stat-head">
                        <div class="stat-icon">▲</div>
                        <span class="stat-chip">Control</span>
                    </div>
                    <span class="stat-label">Tardanzas</span>
                    <strong class="stat-number"><?php echo $totalTardanzas; ?></strong>
                    <div class="stat-bar">
                        <span style="width: <?php echo $totalRegistros > 0 ? round(($totalTardanzas / $totalRegistros) * 100) : 0; ?>%;"></span>
                    </div>
                </article>

                <article class="stat-card stat-red">
                    <div class="stat-head">
                        <div class="stat-icon">■</div>
                        <span class="stat-chip">Alerta</span>
                    </div>
                    <span class="stat-label">Faltas</span>
                    <strong class="stat-number"><?php echo $totalFaltas; ?></strong>
                    <div class="stat-bar">
                        <span style="width: <?php echo $totalRegistros > 0 ? round(($totalFaltas / $totalRegistros) * 100) : 0; ?>%;"></span>
                    </div>
                </article>
            </section>

            <section class="grid-dos-columnas grid-asistencia-paneles">
                <article class="tarjeta-panel panel-premium panel-biometrico">
                    <div class="panel-header-premium">
                        <div>
                            <span class="panel-kicker">Última actividad</span>
                            <h2 class="titulo-tarjeta-panel">Marcación detectada</h2>
                        </div>
                        <span class="panel-badge">BioID</span>
                    </div>

                    <?php if ($ultimaMarcacion): ?>
                        <div class="marcacion-destacada">
                            <div class="marcacion-orbita">
                                <span></span>
                                <strong><?php echo htmlspecialchars($ultimaMarcacion['id_huella'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>

                            <div class="marcacion-info">
                                <h3>
                                    <?php echo htmlspecialchars($ultimaMarcacion['nombres'] . ' ' . $ultimaMarcacion['apellidos'], ENT_QUOTES, 'UTF-8'); ?>
                                </h3>
                                <p>
                                    <?php echo htmlspecialchars($ultimaMarcacion['codigo_estudiante'], ENT_QUOTES, 'UTF-8'); ?>
                                    · <?php echo htmlspecialchars($ultimaMarcacion['metodo_registro'], ENT_QUOTES, 'UTF-8'); ?>
                                </p>
                                <span class="etiqueta-estado <?php echo clase_estado_asistencia($ultimaMarcacion['estado_entrada']); ?>">
                                    <?php echo htmlspecialchars($ultimaMarcacion['estado_entrada'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="estado-vacio-premium">
                            <strong>Sin marcaciones</strong>
                            <span>No hay registros para la fecha seleccionada.</span>
                        </div>
                    <?php endif; ?>
                </article>

            </section>

            <section class="tarjeta-panel panel-premium tabla-modulo">
                <div class="panel-header-premium panel-header-con-acciones">
                    <div>
                        <span class="panel-kicker">Registros filtrados</span>
                        <h2 class="titulo-tarjeta-panel">Marcaciones de asistencia</h2>
                    </div>

                </div>

                <form method="GET" action="asistencia.php" class="formulario-busqueda filtros-premium">
                    <div class="grupo-filtro">
                        <label>Fecha</label>
                        <input type="date" name="fecha" value="<?php echo htmlspecialchars($fechaSeleccionada, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>

                    <div class="grupo-filtro filtro-grow">
                        <label>Buscar estudiante</label>
                        <input type="text" name="buscar" placeholder="Código, nombres o apellidos" value="<?php echo htmlspecialchars($busqueda, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>

                    <div class="grupo-filtro">
                        <label>Estado</label>
                        <select name="estado">
                            <option value="">Todos los estados</option>
                            <?php foreach ($estadosPermitidosFiltro as $estado): ?>
                                <option value="<?php echo htmlspecialchars($estado, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $estadoFiltro === $estado ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($estado, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="grupo-filtro acciones-filtro">
                        <button type="submit" class="boton-secundario">Buscar</button>
                        <a href="asistencia.php" class="boton-secundario">Limpiar</a>
                    </div>
                </form>

                <div class="tabla-responsive">
                    <table class="tabla-datos tabla-premium">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Estudiante</th>
                                <th>ID Huella</th>
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
                            <?php if (count($listaAsistencias) === 0): ?>
                                <tr>
                                    <td colspan="10" class="texto-sin-datos">
                                        No hay registros de asistencia para esta fecha
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($listaAsistencias as $asistencia): ?>
                                    <?php
                                    $datosAsistencia = htmlspecialchars(
                                        json_encode($asistencia, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    );
                                    ?>
                                    <tr>
                                        <td>
                                            <span class="codigo-chip">
                                                <?php echo htmlspecialchars($asistencia['codigo_estudiante'], ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </td>

                                        <td>
                                            <div class="celda-usuario">
                                                <div class="avatar-tabla">
                                                    <?php echo htmlspecialchars(strtoupper(substr($asistencia['nombres'], 0, 1)), ENT_QUOTES, 'UTF-8'); ?>
                                                </div>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($asistencia['nombres'] . ' ' . $asistencia['apellidos'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                                    <span>Estudiante activo</span>
                                                </div>
                                            </div>
                                        </td>

                                        <td>
                                            <span class="huella-chip <?php echo $asistencia['id_huella'] ? 'huella-activa' : 'huella-pendiente'; ?>">
                                                <?php echo $asistencia['id_huella'] ? htmlspecialchars($asistencia['id_huella'], ENT_QUOTES, 'UTF-8') : 'Sin asignar'; ?>
                                            </span>
                                        </td>

                                        <td><?php echo htmlspecialchars($asistencia['fecha'], ENT_QUOTES, 'UTF-8'); ?></td>

                                        <td>
                                            <?php echo $asistencia['hora_entrada'] ? htmlspecialchars(substr($asistencia['hora_entrada'], 0, 5), ENT_QUOTES, 'UTF-8') : '-'; ?>
                                        </td>

                                        <td>
                                            <span class="etiqueta-estado <?php echo clase_estado_asistencia($asistencia['estado_entrada']); ?>">
                                                <?php echo icono_estado_asistencia($asistencia['estado_entrada']); ?>
                                                <?php echo htmlspecialchars($asistencia['estado_entrada'], ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?php echo $asistencia['hora_salida'] ? htmlspecialchars(substr($asistencia['hora_salida'], 0, 5), ENT_QUOTES, 'UTF-8') : '-'; ?>
                                        </td>

                                        <td>
                                            <span class="etiqueta-estado <?php echo clase_estado_asistencia($asistencia['estado_salida']); ?>">
                                                <?php echo icono_estado_asistencia($asistencia['estado_salida']); ?>
                                                <?php echo htmlspecialchars($asistencia['estado_salida'], ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </td>

                                        <td>
                                            <span class="metodo-chip">
                                                <?php echo htmlspecialchars($asistencia['metodo_registro'], ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </td>

                                        <td><?php echo htmlspecialchars($asistencia['observacion'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>

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