<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/funciones.php';

requerir_rol(['Administrador', 'Docente']);

$rol = obtener_rol_usuario();
$nombreUsuario = obtener_nombre_usuario();
$estadoDispositivo = obtener_estado_dispositivo($pdo);
$claseEstadoDispositivo = clase_estado_dispositivo($estadoDispositivo);
$mensajeExito = '';
$mensajeError = '';

function escapar_cursos($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function hora_valida_cursos(string $hora): bool
{
    return preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $hora) === 1;
}

function limpiar_hora_cursos(?string $hora): string
{
    if ($hora === null || $hora === '') {
        return '-';
    }

    return substr($hora, 0, 5);
}

function preparar_json_cursos(array $datos): string
{
    return htmlspecialchars(
        json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT),
        ENT_QUOTES,
        'UTF-8'
    );
}

$diasPermitidos = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes'];
$tiposPermitidos = ['Clase', 'Receso'];
$tokenCsrf = generar_token_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $rol === 'Administrador') {
    requerir_token_csrf($_POST['csrf_token'] ?? null);

    $accion = $_POST['accion'] ?? '';

    try {
        if ($accion === 'guardar_curso') {
            $idCurso = (int) ($_POST['id_curso'] ?? 0);
            $nombreCurso = limpiar_texto($_POST['nombre_curso'] ?? '');
            $idDocente = (int) ($_POST['id_docente'] ?? 0);

            if ($nombreCurso === '') {
                $mensajeError = 'El nombre del curso es obligatorio.';
            } else {
                $pdo->beginTransaction();

                if ($idCurso > 0) {
                    $consulta = $pdo->prepare(
                        "UPDATE cursos
                         SET nombre_curso = :nombre_curso
                         WHERE id_curso = :id_curso"
                    );

                    $consulta->execute([
                        'nombre_curso' => $nombreCurso,
                        'id_curso' => $idCurso
                    ]);

                    $idCursoFinal = $idCurso;
                    $mensajeExito = 'Curso actualizado correctamente.';
                } else {
                    $consulta = $pdo->prepare(
                        "INSERT INTO cursos (nombre_curso)
                         VALUES (:nombre_curso)"
                    );

                    $consulta->execute([
                        'nombre_curso' => $nombreCurso
                    ]);

                    $idCursoFinal = (int) $pdo->lastInsertId();
                    $mensajeExito = 'Curso registrado correctamente.';
                }

                $eliminarAsignacion = $pdo->prepare(
                    "DELETE FROM docente_curso
                     WHERE id_curso = :id_curso"
                );

                $eliminarAsignacion->execute([
                    'id_curso' => $idCursoFinal
                ]);

                if ($idDocente > 0) {
                    $asignar = $pdo->prepare(
                        "INSERT INTO docente_curso (id_docente, id_curso)
                         VALUES (:id_docente, :id_curso)"
                    );

                    $asignar->execute([
                        'id_docente' => $idDocente,
                        'id_curso' => $idCursoFinal
                    ]);
                }

                $pdo->commit();
            }
        }

if ($accion === 'eliminar_curso') {
    $idCurso = (int) ($_POST['id_curso'] ?? 0);

    if ($idCurso > 0) {
        $pdo->beginTransaction();

        $consulta = $pdo->prepare(
            "UPDATE cursos
             SET estado = 'Inactivo'
             WHERE id_curso = :id_curso"
        );

        $consulta->execute([
            'id_curso' => $idCurso
        ]);

        $consultaHorario = $pdo->prepare(
            "DELETE FROM horarios
             WHERE id_curso = :id_curso"
        );

        $consultaHorario->execute([
            'id_curso' => $idCurso
        ]);

        $consultaAsignacion = $pdo->prepare(
            "DELETE FROM docente_curso
             WHERE id_curso = :id_curso"
        );

        $consultaAsignacion->execute([
            'id_curso' => $idCurso
        ]);

        $pdo->commit();

        $mensajeExito = 'Curso desactivado correctamente.';
    }
}

        if ($accion === 'guardar_horario') {
            $idHorario = (int) ($_POST['id_horario'] ?? 0);
            $diaSemana = limpiar_texto($_POST['dia_semana'] ?? '');
            $horaInicio = limpiar_texto($_POST['hora_inicio'] ?? '');
            $horaFin = limpiar_texto($_POST['hora_fin'] ?? '');
            $idCurso = (int) ($_POST['id_curso'] ?? 0);
            $idDocente = (int) ($_POST['id_docente'] ?? 0);
            $tipoActividad = limpiar_texto($_POST['tipo_actividad'] ?? 'Clase');

            if (!in_array($diaSemana, $diasPermitidos, true)) {
                $mensajeError = 'El día seleccionado no es válido.';
            } elseif (!hora_valida_cursos($horaInicio) || !hora_valida_cursos($horaFin)) {
                $mensajeError = 'La hora de inicio y la hora de fin son obligatorias.';
            } elseif ($horaInicio >= $horaFin) {
                $mensajeError = 'La hora de inicio debe ser menor que la hora de fin.';
            } elseif (!in_array($tipoActividad, $tiposPermitidos, true)) {
                $mensajeError = 'El tipo de actividad no es válido.';
            } else {
                $idCursoGuardar = $tipoActividad === 'Receso' ? null : ($idCurso > 0 ? $idCurso : null);
                $idDocenteGuardar = $tipoActividad === 'Receso' ? null : ($idDocente > 0 ? $idDocente : null);

                if ($idHorario > 0) {
                    $consulta = $pdo->prepare(
                        "UPDATE horarios
                         SET dia_semana = :dia_semana,
                             hora_inicio = :hora_inicio,
                             hora_fin = :hora_fin,
                             id_curso = :id_curso,
                             id_docente = :id_docente,
                             tipo_actividad = :tipo_actividad
                         WHERE id_horario = :id_horario"
                    );

                    $consulta->execute([
                        'dia_semana' => $diaSemana,
                        'hora_inicio' => $horaInicio,
                        'hora_fin' => $horaFin,
                        'id_curso' => $idCursoGuardar,
                        'id_docente' => $idDocenteGuardar,
                        'tipo_actividad' => $tipoActividad,
                        'id_horario' => $idHorario
                    ]);

                    $mensajeExito = 'Horario actualizado correctamente.';
                } else {
                    $consulta = $pdo->prepare(
                        "INSERT INTO horarios
                         (dia_semana, hora_inicio, hora_fin, id_curso, id_docente, tipo_actividad)
                         VALUES
                         (:dia_semana, :hora_inicio, :hora_fin, :id_curso, :id_docente, :tipo_actividad)"
                    );

                    $consulta->execute([
                        'dia_semana' => $diaSemana,
                        'hora_inicio' => $horaInicio,
                        'hora_fin' => $horaFin,
                        'id_curso' => $idCursoGuardar,
                        'id_docente' => $idDocenteGuardar,
                        'tipo_actividad' => $tipoActividad
                    ]);

                    $mensajeExito = 'Horario registrado correctamente.';
                }
            }
        }

        if ($accion === 'eliminar_horario') {
            $idHorario = (int) ($_POST['id_horario'] ?? 0);

            if ($idHorario > 0) {
                $consulta = $pdo->prepare(
                    "DELETE FROM horarios
                     WHERE id_horario = :id_horario"
                );

                $consulta->execute([
                    'id_horario' => $idHorario
                ]);

                $mensajeExito = 'Horario eliminado correctamente.';
            }
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $mensajeError = $e->getMessage() !== '' ? $e->getMessage() : 'No se pudo completar la operación.';
    }
}

$consultaDocentes = $pdo->query(
    "SELECT d.id_docente,
            u.id_usuario,
            u.nombres,
            u.apellidos,
            CONCAT(u.nombres, ' ', u.apellidos) AS docente_nombre
     FROM docentes d
     INNER JOIN usuarios u ON d.id_usuario = u.id_usuario
     WHERE u.estado = 'Activo'
     ORDER BY u.apellidos ASC, u.nombres ASC"
);

$listaDocentes = $consultaDocentes->fetchAll();

$consultaCursos = $pdo->query(
    "SELECT c.id_curso,
            c.nombre_curso,
            c.estado,
            MIN(d.id_docente) AS id_docente_asignado,
            GROUP_CONCAT(CONCAT(u.nombres, ' ', u.apellidos) ORDER BY u.apellidos ASC SEPARATOR ', ') AS docentes_asignados
     FROM cursos c
     LEFT JOIN docente_curso dc ON c.id_curso = dc.id_curso
     LEFT JOIN docentes d ON dc.id_docente = d.id_docente
     LEFT JOIN usuarios u ON d.id_usuario = u.id_usuario
     GROUP BY c.id_curso, c.nombre_curso, c.estado
     ORDER BY c.nombre_curso ASC"
);

$listaCursos = $consultaCursos->fetchAll();

$consultaHorarios = $pdo->query(
    "SELECT h.id_horario,
            h.dia_semana,
            h.hora_inicio,
            h.hora_fin,
            h.id_curso,
            h.id_docente,
            h.tipo_actividad,
            c.nombre_curso,
            CONCAT(u.nombres, ' ', u.apellidos) AS docente_nombre
     FROM horarios h
     LEFT JOIN cursos c ON h.id_curso = c.id_curso
     LEFT JOIN docentes d ON h.id_docente = d.id_docente
     LEFT JOIN usuarios u ON d.id_usuario = u.id_usuario
     ORDER BY FIELD(h.dia_semana, 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes'), h.hora_inicio ASC"
);

$listaHorarios = $consultaHorarios->fetchAll();

$totalCursos = count($listaCursos);
$totalDocentes = count($listaDocentes);
$totalBloquesHorario = count($listaHorarios);
$totalClases = 0;
$totalRecesos = 0;
$horariosPorDia = [];

foreach ($diasPermitidos as $dia) {
    $horariosPorDia[$dia] = [];
}

foreach ($listaHorarios as $horario) {
    if ($horario['tipo_actividad'] === 'Receso') {
        $totalRecesos++;
    } else {
        $totalClases++;
    }

    if (isset($horariosPorDia[$horario['dia_semana']])) {
        $horariosPorDia[$horario['dia_semana']][] = $horario;
    }
}

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
    <title>BioAsistencia - Cursos y Horarios</title>
    <link rel="icon" type="image/png" href="<?php echo app_url('assets/img/logo.png?v=1'); ?>">
    <link rel="shortcut icon" type="image/png" href="<?php echo app_url('assets/img/logo.png?v=1'); ?>">
    <link rel="stylesheet" href="<?php echo app_url('assets/css/styles.css?v=9999'); ?>">
</head>
<body class="pagina-interna pagina-cursos">

    <div class="fondo-modulo" aria-hidden="true">
        <span class="fondo-luz fondo-luz-uno"></span>
        <span class="fondo-luz fondo-luz-dos"></span>
        <span class="fondo-malla"></span>
    </div>

   <?php
   $menuActual = 'cursos';
   require __DIR__ . '/../includes/sidebar.php';
   ?>

    <div class="contenido-principal contenido-premium">
        <header class="barra-superior topbar-premium">
            <div class="topbar-titulos">
                <span class="breadcrumb-modulo">BioAsistencia / Gestión académica</span>
                <h1 class="titulo-pagina">Cursos y Horarios</h1>
            </div>

            <div class="acciones-superiores">
<div class="indicador-dispositivo indicador-<?php echo htmlspecialchars($claseEstadoDispositivo, ENT_QUOTES, 'UTF-8'); ?>">
    <span class="punto-indicador"></span>
    <?php echo htmlspecialchars($estadoDispositivo, ENT_QUOTES, 'UTF-8'); ?>
</div>

                <div class="menu-usuario usuario-premium">
                    <div class="usuario-avatar">
                        <?php echo escapar_cursos(mb_strtoupper(mb_substr($nombreUsuario, 0, 1, 'UTF-8'), 'UTF-8')); ?>
                    </div>
                    <div class="usuario-datos">
                        <span class="nombre-usuario-superior"><?php echo escapar_cursos($nombreUsuario); ?></span>
                        <small><?php echo escapar_cursos($rol); ?></small>
                    </div>
                    <a href="<?php echo app_url('logout.php'); ?>" class="boton-cerrar-sesion">Cerrar Sesión</a>
                </div>
            </div>
        </header>

        <main class="area-contenido area-premium">
            <?php if ($mensajeExito !== ''): ?>
                <div class="mensaje-alerta mensaje-exito">
                    <?php echo escapar_cursos($mensajeExito); ?>
                </div>
            <?php endif; ?>

            <?php if ($mensajeError !== ''): ?>
                <div class="mensaje-alerta mensaje-error">
                    <?php echo escapar_cursos($mensajeError); ?>
                </div>
            <?php endif; ?>

            <section class="modulo-hero hero-cursos">
                <div class="hero-contenido">
                    <span class="hero-kicker">Malla académica y control de bloques</span>
                    <h2>Organiza cursos, docentes y horarios semanales.</h2>
                    <p>
                        Administra la programación académica del V Ciclo y mantén cada curso conectado
                        con su docente responsable y sus bloques de clase.
                    </p>
                </div>

                <div class="hero-panel-mini">
                    <span>Horario activo</span>
                    <strong>Lunes a Viernes</strong>
                    <small>14:00 - 19:00</small>
                </div>
            </section>

            <section class="resumen-premium dashboard-stat-grid">
                <article class="stat-card stat-blue">
                    <div class="stat-head">
                        <div class="stat-icon">▣</div>
                        <span class="stat-chip">Cursos</span>
                    </div>
                    <div class="stat-label">Cursos registrados</div>
                    <div class="stat-number"><?php echo (int) $totalCursos; ?></div>
                    <div class="stat-bar"><span style="width:100%"></span></div>
                </article>

                <article class="stat-card stat-green">
                    <div class="stat-head">
                        <div class="stat-icon">👨‍🏫</div>
                        <span class="stat-chip">Docentes</span>
                    </div>
                    <div class="stat-label">Docentes activos</div>
                    <div class="stat-number"><?php echo (int) $totalDocentes; ?></div>
                    <div class="stat-bar"><span style="width:80%"></span></div>
                </article>

                <article class="stat-card stat-orange">
                    <div class="stat-head">
                        <div class="stat-icon">⏱</div>
                        <span class="stat-chip">Bloques</span>
                    </div>
                    <div class="stat-label">Bloques de horario</div>
                    <div class="stat-number"><?php echo (int) $totalBloquesHorario; ?></div>
                    <div class="stat-bar"><span style="width:75%"></span></div>
                </article>

                <article class="stat-card stat-red">
                    <div class="stat-head">
                        <div class="stat-icon">☕</div>
                        <span class="stat-chip">Recesos</span>
                    </div>
                    <div class="stat-label">Bloques de receso</div>
                    <div class="stat-number"><?php echo (int) $totalRecesos; ?></div>
                    <div class="stat-bar"><span style="width:35%"></span></div>
                </article>
            </section>

            <section class="tarjeta-panel panel-premium">
                <div class="panel-cabecera-premium">
                    <div>
                        <span class="panel-kicker">Catálogo académico</span>
                        <h2 class="titulo-tarjeta-panel">Cursos registrados</h2>
                    </div>

                    <?php if ($rol === 'Administrador'): ?>
                        <button type="button" class="boton-primario" onclick="abrirModalCurso()">
                            Registrar nuevo curso
                        </button>
                    <?php endif; ?>
                </div>

                <div class="tabla-contenedor-premium">
                    <table class="tabla-datos tabla-premium">
                        <thead>
                            <tr>
                                <th>Curso</th>
                                <th>Docente asignado</th>
                                <th>Estado</th>
                                <?php if ($rol === 'Administrador'): ?>
                                    <th>Acciones</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($listaCursos) === 0): ?>
                                <tr>
                                    <td colspan="<?php echo $rol === 'Administrador' ? '4' : '3'; ?>" class="texto-sin-datos">
                                        No hay cursos registrados.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($listaCursos as $curso): ?>
                                    <tr>
                                        <td>
                                            <div class="celda-principal">
                                                <span class="avatar-tabla avatar-curso">▣</span>
                                                <div>
                                                    <strong><?php echo escapar_cursos($curso['nombre_curso']); ?></strong>
                                                    <small>Curso académico</small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php echo $curso['docentes_asignados'] ? escapar_cursos($curso['docentes_asignados']) : 'Sin asignar'; ?>
                                        </td>
                                        <td>
                                            <span class="etiqueta-estado <?php echo $curso['estado'] === 'Activo' ? 'estado-puntual' : 'estado-falto'; ?>">
                                                <?php echo escapar_cursos($curso['estado']); ?>
                                            </span>
                                        </td>

                                        <?php if ($rol === 'Administrador'): ?>
                                            <td>
                                                <div class="acciones-tabla">
                                                    <button
                                                        type="button"
                                                        class="boton-secundario"
                                                        onclick='abrirModalCurso(<?php echo preparar_json_cursos($curso); ?>)'
                                                    >
                                                        Editar
                                                    </button>

                                                    <form method="POST" action="cursos_horarios.php" class="formulario-inline">
                                                        <input type="hidden" name="csrf_token" value="<?php echo escapar_cursos($tokenCsrf); ?>">
                                                        <input type="hidden" name="accion" value="eliminar_curso">
                                                        <input type="hidden" name="id_curso" value="<?php echo (int) $curso['id_curso']; ?>">
                                                        <button
                                                            type="submit"
                                                            class="boton-peligro"
                                                            data-confirmar="¿Seguro que deseas eliminar este curso?"
                                                        >
                                                            Eliminar
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="tarjeta-panel panel-premium">
                <div class="panel-cabecera-premium">
                    <div>
                        <span class="panel-kicker">Programación semanal</span>
                        <h2 class="titulo-tarjeta-panel">Horario semanal</h2>
                    </div>

                    <?php if ($rol === 'Administrador'): ?>
                        <button type="button" class="boton-primario" onclick="abrirModalHorario()">
                            Registrar nuevo horario
                        </button>
                    <?php endif; ?>
                </div>

                <div class="horario-grid-premium">
                    <?php foreach ($horariosPorDia as $dia => $horariosDia): ?>
                        <article class="horario-dia-card">
                            <div class="horario-dia-cabecera">
                                <span><?php echo escapar_cursos($dia); ?></span>
                                <small><?php echo count($horariosDia); ?> bloques</small>
                            </div>

                            <?php if (count($horariosDia) === 0): ?>
                                <div class="horario-vacio">Sin horario registrado</div>
                            <?php else: ?>
                                <div class="horario-linea">
                                    <?php foreach ($horariosDia as $horario): ?>
                                        <div class="horario-bloque <?php echo $horario['tipo_actividad'] === 'Receso' ? 'horario-receso' : 'horario-clase'; ?>">
                                            <div class="horario-hora">
                                                <?php echo escapar_cursos(limpiar_hora_cursos($horario['hora_inicio'])); ?>
                                                -
                                                <?php echo escapar_cursos(limpiar_hora_cursos($horario['hora_fin'])); ?>
                                            </div>

                                            <strong>
                                                <?php echo $horario['tipo_actividad'] === 'Receso' ? 'Receso' : escapar_cursos($horario['nombre_curso'] ?? 'Sin curso'); ?>
                                            </strong>

                                            <small>
                                                <?php echo $horario['tipo_actividad'] === 'Receso' ? 'Pausa académica' : escapar_cursos($horario['docente_nombre'] ?? 'Sin docente'); ?>
                                            </small>

                                            <?php if ($rol === 'Administrador'): ?>
                                                <div class="acciones-horario">
                                                    <button
                                                        type="button"
                                                        class="mini-accion"
                                                        onclick='abrirModalHorario(<?php echo preparar_json_cursos($horario); ?>)'
                                                    >
                                                        Editar
                                                    </button>

                                                    <form method="POST" action="cursos_horarios.php">
                                                        <input type="hidden" name="csrf_token" value="<?php echo escapar_cursos($tokenCsrf); ?>">
                                                        <input type="hidden" name="accion" value="eliminar_horario">
                                                        <input type="hidden" name="id_horario" value="<?php echo (int) $horario['id_horario']; ?>">
                                                        <button
                                                            type="submit"
                                                            class="mini-accion mini-peligro"
                                                            data-confirmar="¿Seguro que deseas eliminar este horario?"
                                                        >
                                                            Eliminar
                                                        </button>
                                                    </form>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>

                <div class="tabla-contenedor-premium tabla-horario-detalle">
                    <table class="tabla-datos tabla-premium">
                        <thead>
                            <tr>
                                <th>Día</th>
                                <th>Hora inicio</th>
                                <th>Hora fin</th>
                                <th>Curso</th>
                                <th>Docente</th>
                                <th>Tipo</th>
                                <?php if ($rol === 'Administrador'): ?>
                                    <th>Acciones</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($listaHorarios) === 0): ?>
                                <tr>
                                    <td colspan="<?php echo $rol === 'Administrador' ? '7' : '6'; ?>" class="texto-sin-datos">
                                        No hay horarios registrados.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($listaHorarios as $horario): ?>
                                    <tr>
                                        <td><?php echo escapar_cursos($horario['dia_semana']); ?></td>
                                        <td><?php echo escapar_cursos(limpiar_hora_cursos($horario['hora_inicio'])); ?></td>
                                        <td><?php echo escapar_cursos(limpiar_hora_cursos($horario['hora_fin'])); ?></td>
                                        <td><?php echo $horario['nombre_curso'] ? escapar_cursos($horario['nombre_curso']) : '-'; ?></td>
                                        <td><?php echo $horario['docente_nombre'] ? escapar_cursos($horario['docente_nombre']) : '-'; ?></td>
                                        <td>
                                            <span class="etiqueta-estado <?php echo $horario['tipo_actividad'] === 'Receso' ? 'estado-tardanza' : 'estado-puntual'; ?>">
                                                <?php echo escapar_cursos($horario['tipo_actividad']); ?>
                                            </span>
                                        </td>

                                        <?php if ($rol === 'Administrador'): ?>
                                            <td>
                                                <div class="acciones-tabla">
                                                    <button
                                                        type="button"
                                                        class="boton-secundario"
                                                        onclick='abrirModalHorario(<?php echo preparar_json_cursos($horario); ?>)'
                                                    >
                                                        Editar
                                                    </button>

                                                    <form method="POST" action="cursos_horarios.php" class="formulario-inline">
                                                        <input type="hidden" name="csrf_token" value="<?php echo escapar_cursos($tokenCsrf); ?>">
                                                        <input type="hidden" name="accion" value="eliminar_horario">
                                                        <input type="hidden" name="id_horario" value="<?php echo (int) $horario['id_horario']; ?>">
                                                        <button
                                                            type="submit"
                                                            class="boton-peligro"
                                                            data-confirmar="¿Seguro que deseas eliminar este horario?"
                                                        >
                                                            Eliminar
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>

    <?php if ($rol === 'Administrador'): ?>
        <div class="fondo-modal" id="fondoModalCurso" style="display:none;">
            <div class="tarjeta-panel modal-formulario modal-formulario-premium">
                <div class="modal-cabecera-premium">
                    <span class="panel-kicker">Gestión de curso</span>
                    <h2 class="titulo-tarjeta-panel" id="tituloModalCurso">Registrar nuevo curso</h2>
                </div>

                <form method="POST" action="cursos_horarios.php" class="formulario-panel">
                    <input type="hidden" name="csrf_token" value="<?php echo escapar_cursos($tokenCsrf); ?>">
                    <input type="hidden" name="accion" value="guardar_curso">
                    <input type="hidden" name="id_curso" id="campoCursoId" value="">

                    <div class="grupo-campo full-line">
                        <label for="campoCursoNombre">Nombre del curso</label>
                        <input type="text" name="nombre_curso" id="campoCursoNombre" required>
                    </div>

                    <div class="grupo-campo full-line">
                        <label for="campoDocenteCurso">Docente asignado</label>
                        <select name="id_docente" id="campoDocenteCurso">
                            <option value="0">Sin asignar</option>
                            <?php foreach ($listaDocentes as $docente): ?>
                                <option value="<?php echo (int) $docente['id_docente']; ?>">
                                    <?php echo escapar_cursos($docente['docente_nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="acciones-panel">
                        <button type="button" class="boton-secundario" onclick="cerrarModalCurso()">
                            Cancelar
                        </button>
                        <button type="submit" class="boton-primario">
                            Guardar
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="fondo-modal" id="fondoModalHorario" style="display:none;">
            <div class="tarjeta-panel modal-formulario modal-formulario-premium">
                <div class="modal-cabecera-premium">
                    <span class="panel-kicker">Programación semanal</span>
                    <h2 class="titulo-tarjeta-panel" id="tituloModalHorario">Registrar nuevo horario</h2>
                </div>

                <form method="POST" action="cursos_horarios.php" class="formulario-panel">
                    <input type="hidden" name="csrf_token" value="<?php echo escapar_cursos($tokenCsrf); ?>">
                    <input type="hidden" name="accion" value="guardar_horario">
                    <input type="hidden" name="id_horario" id="campoHorarioId" value="">

                    <div class="grupo-campo">
                        <label for="campoDiaSemana">Día</label>
                        <select name="dia_semana" id="campoDiaSemana" required>
                            <?php foreach ($diasPermitidos as $dia): ?>
                                <option value="<?php echo escapar_cursos($dia); ?>">
                                    <?php echo escapar_cursos($dia); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="grupo-campo">
                        <label for="campoHoraInicio">Hora de inicio</label>
                        <input type="time" name="hora_inicio" id="campoHoraInicio" required>
                    </div>

                    <div class="grupo-campo">
                        <label for="campoHoraFin">Hora de fin</label>
                        <input type="time" name="hora_fin" id="campoHoraFin" required>
                    </div>

                    <div class="grupo-campo">
                        <label for="campoTipoActividad">Tipo de actividad</label>
                        <select name="tipo_actividad" id="campoTipoActividad">
                            <?php foreach ($tiposPermitidos as $tipo): ?>
                                <option value="<?php echo escapar_cursos($tipo); ?>">
                                    <?php echo escapar_cursos($tipo); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="grupo-campo">
                        <label for="campoCursoSeleccionado">Curso</label>
                        <select name="id_curso" id="campoCursoSeleccionado">
                            <option value="0">Ninguno</option>
                            <?php foreach ($listaCursos as $curso): ?>
                                <option value="<?php echo (int) $curso['id_curso']; ?>">
                                    <?php echo escapar_cursos($curso['nombre_curso']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="grupo-campo">
                        <label for="campoDocenteHorario">Docente</label>
                        <select name="id_docente" id="campoDocenteHorario">
                            <option value="0">Sin asignar</option>
                            <?php foreach ($listaDocentes as $docente): ?>
                                <option value="<?php echo (int) $docente['id_docente']; ?>">
                                    <?php echo escapar_cursos($docente['docente_nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="acciones-panel">
                        <button type="button" class="boton-secundario" onclick="cerrarModalHorario()">
                            Cancelar
                        </button>
                        <button type="submit" class="boton-primario">
                            Guardar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <script src="<?php echo app_url('assets/js/main.js?v=9999'); ?>"></script>
    <script>
        function normalizarHora(valor) {
            if (!valor) {
                return '';
            }

            return valor.substring(0, 5);
        }

        function abrirModalCurso(datos) {
            document.getElementById('tituloModalCurso').textContent = datos ? 'Editar curso' : 'Registrar nuevo curso';
            document.getElementById('campoCursoId').value = datos ? datos.id_curso : '';
            document.getElementById('campoCursoNombre').value = datos ? datos.nombre_curso : '';
            document.getElementById('campoDocenteCurso').value = datos && datos.id_docente_asignado ? datos.id_docente_asignado : '0';
            document.getElementById('fondoModalCurso').style.display = 'flex';
        }

        function cerrarModalCurso() {
            document.getElementById('fondoModalCurso').style.display = 'none';
        }

        function abrirModalHorario(datos) {
            document.getElementById('tituloModalHorario').textContent = datos ? 'Editar horario' : 'Registrar nuevo horario';
            document.getElementById('campoHorarioId').value = datos ? datos.id_horario : '';
            document.getElementById('campoDiaSemana').value = datos ? datos.dia_semana : 'Lunes';
            document.getElementById('campoHoraInicio').value = datos ? normalizarHora(datos.hora_inicio) : '';
            document.getElementById('campoHoraFin').value = datos ? normalizarHora(datos.hora_fin) : '';
            document.getElementById('campoCursoSeleccionado').value = datos && datos.id_curso ? datos.id_curso : '0';
            document.getElementById('campoDocenteHorario').value = datos && datos.id_docente ? datos.id_docente : '0';
            document.getElementById('campoTipoActividad').value = datos ? datos.tipo_actividad : 'Clase';
            document.getElementById('fondoModalHorario').style.display = 'flex';
        }

        function cerrarModalHorario() {
            document.getElementById('fondoModalHorario').style.display = 'none';
        }
    </script>

</body>
</html>