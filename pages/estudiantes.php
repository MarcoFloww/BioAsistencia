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

function generar_codigo_estudiante(PDO $pdo): string
{
    $consulta = $pdo->query(
        "SELECT codigo_estudiante
         FROM estudiantes
         WHERE codigo_estudiante LIKE 'EST%'
         ORDER BY CAST(SUBSTRING(codigo_estudiante, 4) AS UNSIGNED) DESC
         LIMIT 1"
    );

    $fila = $consulta->fetch();

    if (!$fila) {
        return 'EST001';
    }

    $numero = (int) substr($fila['codigo_estudiante'], 3);
    $numero++;

    return 'EST' . str_pad((string) $numero, 3, '0', STR_PAD_LEFT);
}

function texto_seguro($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function icono_menu_estudiantes(string $clave): string
{
    $iconos = [
        'panel' => '⌂',
        'estudiantes' => '◉',
        'docentes' => '◇',
        'cursos' => '▣',
        'asistencia' => '◆',
        'reportes' => '▤',
        'alertas' => '△',
        'usuarios' => '◌',
        'configuracion' => '⚙',
        'perfil' => '◎'
    ];

    return $iconos[$clave] ?? '•';
}

function obtener_periodo_actual_estudiantes(PDO $pdo): ?array
{
    $consulta = $pdo->prepare(
        "SELECT id_periodo,
                nombre_periodo,
                fecha_inicio,
                fecha_fin
         FROM periodos_academicos
         WHERE :fecha BETWEEN fecha_inicio AND fecha_fin
         ORDER BY fecha_inicio DESC
         LIMIT 1"
    );

    $consulta->execute([
        'fecha' => date('Y-m-d')
    ]);

    $periodo = $consulta->fetch();

    return $periodo ?: null;
}

function obtener_ciclo_periodo_estudiantes(?array $periodo): string
{
    if (!$periodo) {
        return '';
    }

    $nombrePeriodo = trim((string) ($periodo['nombre_periodo'] ?? ''));

    if (preg_match('/^([IVXLCDM]+)\b/u', $nombrePeriodo, $coincidencia) === 1) {
        return strtoupper($coincidencia[1]);
    }

    return '';
}

$periodoActualEstudiantes = obtener_periodo_actual_estudiantes($pdo);
$cicloPeriodoActual = obtener_ciclo_periodo_estudiantes($periodoActualEstudiantes);
$tokenCsrf = generar_token_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $rol === 'Administrador') {
    requerir_token_csrf($_POST['csrf_token'] ?? null);

    $accion = $_POST['accion'] ?? '';

    try {
        if ($accion === 'guardar') {
            $idEstudiante = (int) ($_POST['id_estudiante'] ?? 0);
            $codigo = limpiar_texto($_POST['codigo_estudiante'] ?? '');
            $nombres = limpiar_texto($_POST['nombres'] ?? '');
            $apellidos = limpiar_texto($_POST['apellidos'] ?? '');
            $programa = limpiar_texto($_POST['programa_estudios'] ?? 'Computación e Informática');
            $ciclo = limpiar_texto($_POST['ciclo'] ?? 'V');
            $whatsapp = limpiar_texto($_POST['whatsapp'] ?? '');
            $correo = limpiar_texto($_POST['correo_institucional'] ?? '');
            $idSensor = limpiar_texto($_POST['id_sensor'] ?? '');
            $estado = limpiar_texto($_POST['estado'] ?? 'Activo');

            if ($codigo === '') {
                $codigo = generar_codigo_estudiante($pdo);
            }

            if ($nombres === '' || $apellidos === '') {
                $mensajeError = 'Los nombres y apellidos son obligatorios.';
            } elseif ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                $mensajeError = 'El correo institucional no es válido.';
            } elseif (!in_array($estado, ['Activo', 'Inactivo'], true)) {
                $mensajeError = 'El estado no es válido.';
            } elseif ($idEstudiante <= 0 && !$periodoActualEstudiantes) {
                $mensajeError = 'No hay un periodo académico vigente. No se puede registrar un estudiante nuevo fuera de un ciclo académico.';
            } elseif ($idEstudiante <= 0 && $cicloPeriodoActual === '') {
                $mensajeError = 'No se pudo determinar el ciclo del periodo académico vigente.';
            } else {
                if ($idEstudiante <= 0) {
                    $ciclo = $cicloPeriodoActual;
                }

                $pdo->beginTransaction();

                if ($idEstudiante > 0) {
                    $consulta = $pdo->prepare(
                        "UPDATE estudiantes
                         SET codigo_estudiante = :codigo_estudiante,
                             nombres = :nombres,
                             apellidos = :apellidos,
                             programa_estudios = :programa_estudios,
                             ciclo = :ciclo,
                             whatsapp = :whatsapp,
                             correo_institucional = :correo_institucional,
                             estado = :estado
                         WHERE id_estudiante = :id_estudiante"
                    );

                    $consulta->execute([
                        'codigo_estudiante' => $codigo,
                        'nombres' => $nombres,
                        'apellidos' => $apellidos,
                        'programa_estudios' => $programa,
                        'ciclo' => $ciclo,
                        'whatsapp' => $whatsapp !== '' ? $whatsapp : null,
                        'correo_institucional' => $correo !== '' ? $correo : null,
                        'estado' => $estado,
                        'id_estudiante' => $idEstudiante
                    ]);

                    $idEstudianteFinal = $idEstudiante;
                    $mensajeExito = 'Estudiante actualizado correctamente.';
                } else {
                    $consulta = $pdo->prepare(
                        "INSERT INTO estudiantes
                         (codigo_estudiante, nombres, apellidos, programa_estudios, ciclo, id_periodo, whatsapp, correo_institucional, estado)
                         VALUES
                         (:codigo_estudiante, :nombres, :apellidos, :programa_estudios, :ciclo, :id_periodo, :whatsapp, :correo_institucional, :estado)"
                    );

                    $consulta->execute([
                        'codigo_estudiante' => $codigo,
                        'nombres' => $nombres,
                        'apellidos' => $apellidos,
                        'programa_estudios' => $programa,
                        'ciclo' => $ciclo,
                        'id_periodo' => (int) $periodoActualEstudiantes['id_periodo'],
                        'whatsapp' => $whatsapp !== '' ? $whatsapp : null,
                        'correo_institucional' => $correo !== '' ? $correo : null,
                        'estado' => $estado
                    ]);

                    $idEstudianteFinal = (int) $pdo->lastInsertId();
                    $mensajeExito = 'Estudiante registrado correctamente.';
                }

                if ($idSensor !== '') {
                    $idSensorEntero = (int) $idSensor;

                    if ($idSensorEntero <= 0) {
                        throw new Exception('El ID de huella no es válido.');
                    }

                    $validarHuella = $pdo->prepare(
                        "SELECT id_estudiante
                         FROM huellas
                         WHERE id_sensor = :id_sensor
                         AND id_estudiante <> :id_estudiante
                         LIMIT 1"
                    );

                    $validarHuella->execute([
                        'id_sensor' => $idSensorEntero,
                        'id_estudiante' => $idEstudianteFinal
                    ]);

                    if ($validarHuella->fetch()) {
                        throw new Exception('Ese ID de huella ya está asignado a otro estudiante.');
                    }

                    $existeHuella = $pdo->prepare(
                        "SELECT id_huella
                         FROM huellas
                         WHERE id_estudiante = :id_estudiante
                         LIMIT 1"
                    );

                    $existeHuella->execute([
                        'id_estudiante' => $idEstudianteFinal
                    ]);

                    if ($existeHuella->fetch()) {
                        $actualizarHuella = $pdo->prepare(
                            "UPDATE huellas
                             SET id_sensor = :id_sensor,
                                 estado = 'Activa'
                             WHERE id_estudiante = :id_estudiante"
                        );

                        $actualizarHuella->execute([
                            'id_sensor' => $idSensorEntero,
                            'id_estudiante' => $idEstudianteFinal
                        ]);
                    } else {
                        $registrarHuella = $pdo->prepare(
                            "INSERT INTO huellas (id_sensor, id_estudiante, estado)
                             VALUES (:id_sensor, :id_estudiante, 'Activa')"
                        );

                        $registrarHuella->execute([
                            'id_sensor' => $idSensorEntero,
                            'id_estudiante' => $idEstudianteFinal
                        ]);
                    }
                } else {
                    $eliminarHuella = $pdo->prepare(
                        "DELETE FROM huellas
                         WHERE id_estudiante = :id_estudiante"
                    );

                    $eliminarHuella->execute([
                        'id_estudiante' => $idEstudianteFinal
                    ]);
                }

                $pdo->commit();
            }
        }

if ($accion === 'eliminar') {
    $idEstudiante = (int) ($_POST['id_estudiante'] ?? 0);

    if ($idEstudiante > 0) {
        $pdo->beginTransaction();

        $consulta = $pdo->prepare(
            "UPDATE estudiantes
             SET estado = 'Inactivo'
             WHERE id_estudiante = :id_estudiante"
        );

        $consulta->execute([
            'id_estudiante' => $idEstudiante
        ]);

        $consultaHuella = $pdo->prepare(
            "UPDATE huellas
             SET estado = 'Inactiva'
             WHERE id_estudiante = :id_estudiante"
        );

        $consultaHuella->execute([
            'id_estudiante' => $idEstudiante
        ]);

        $pdo->commit();

        $mensajeExito = 'Estudiante desactivado correctamente.';
    }
}
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $mensajeError = $e->getMessage() !== '' ? $e->getMessage() : 'No se pudo completar la operación.';
    }
}

$busqueda = limpiar_texto($_GET['buscar'] ?? '');
$parametros = [];
$sqlBusqueda = '';

if ($busqueda !== '') {
    $sqlBusqueda = "WHERE e.codigo_estudiante LIKE :buscar
                    OR e.nombres LIKE :buscar
                    OR e.apellidos LIKE :buscar
                    OR e.correo_institucional LIKE :buscar";

    $parametros['buscar'] = '%' . $busqueda . '%';
}

$consultaEstudiantes = $pdo->prepare(
    "SELECT e.id_estudiante,
            e.codigo_estudiante,
            e.nombres,
            e.apellidos,
            e.programa_estudios,
            e.ciclo,
            e.id_periodo,
            p.nombre_periodo,
            e.whatsapp,
            e.correo_institucional,
            e.estado,
            h.id_sensor AS id_huella
     FROM estudiantes e
     LEFT JOIN periodos_academicos p ON e.id_periodo = p.id_periodo
     LEFT JOIN huellas h ON e.id_estudiante = h.id_estudiante
     $sqlBusqueda
     ORDER BY e.codigo_estudiante ASC"
);

$consultaEstudiantes->execute($parametros);
$listaEstudiantes = $consultaEstudiantes->fetchAll();
$codigoSugerido = generar_codigo_estudiante($pdo);

$totalEstudiantes = count($listaEstudiantes);
$totalActivos = 0;
$totalInactivos = 0;
$totalConHuella = 0;
$totalSinHuella = 0;

foreach ($listaEstudiantes as $estudianteResumen) {
    if (($estudianteResumen['estado'] ?? '') === 'Activo') {
        $totalActivos++;
    } else {
        $totalInactivos++;
    }

    if (!empty($estudianteResumen['id_huella'])) {
        $totalConHuella++;
    } else {
        $totalSinHuella++;
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
    <title>BioAsistencia - Estudiantes</title>
    <link rel="icon" type="image/png" href="<?php echo app_url('assets/img/logo.png?v=1'); ?>">
    <link rel="shortcut icon" type="image/png" href="<?php echo app_url('assets/img/logo.png?v=1'); ?>">
    <link rel="stylesheet" href="<?php echo app_url('assets/css/styles.css?v=9999'); ?>">
</head>
<body class="pagina-interna pagina-premium pagina-estudiantes">

    <div class="fondo-app-premium" aria-hidden="true">
        <span class="fondo-luz fondo-luz-uno"></span>
        <span class="fondo-luz fondo-luz-dos"></span>
        <span class="fondo-luz fondo-luz-tres"></span>
        <span class="fondo-grid"></span>
    </div>

    <div class="app-shell">

   <?php
   $menuActual = 'estudiantes';
   require __DIR__ . '/../includes/sidebar.php';
   ?>

        <div class="contenido-principal contenido-premium">
            <header class="barra-superior topbar-premium">
                <div class="topbar-identidad">
                    <span class="breadcrumb-premium">Gestión académica / Estudiantes</span>
                    <h1 class="titulo-pagina">Estudiantes</h1>
                </div>

                <div class="acciones-superiores acciones-premium">
<div class="indicador-dispositivo indicador-<?php echo htmlspecialchars($claseEstadoDispositivo, ENT_QUOTES, 'UTF-8'); ?>">
    <span class="punto-indicador"></span>
    <?php echo htmlspecialchars($estadoDispositivo, ENT_QUOTES, 'UTF-8'); ?>
</div>

                    <div class="usuario-topbar">
                        <div class="usuario-topbar-avatar">
                            <?php echo texto_seguro(mb_substr($nombreUsuario, 0, 1, 'UTF-8')); ?>
                        </div>

                        <div class="usuario-topbar-info">
                            <span><?php echo texto_seguro($nombreUsuario); ?></span>
                            <small><?php echo texto_seguro($rol); ?></small>
                        </div>
                    </div>

                    <a href="<?php echo app_url('logout.php'); ?>" class="boton-cerrar-sesion boton-salida-premium">Cerrar Sesión</a>
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

                <section class="modulo-hero modulo-hero-estudiantes">
                    <div class="modulo-hero-contenido">
                        <span class="modulo-chip">Registro académico</span>
                        <h2>Gestión inteligente de estudiantes</h2>
                        <p>
                            Administra la lista oficial, datos de contacto, estado académico e identificación biométrica de cada estudiante.
                        </p>
                    </div>

                    <div class="modulo-hero-accion">
                        <?php if ($rol === 'Administrador'): ?>
                            <button type="button" class="boton-primario boton-hero" onclick="abrirModalEstudiante()">
                                Registrar estudiante
                            </button>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="resumen-premium resumen-estudiantes">
                    <article class="tarjeta-estadistica tarjeta-azul">
                        <span class="etiqueta-estadistica">Total estudiantes</span>
                        <strong><?php echo (int) $totalEstudiantes; ?></strong>
                        <small>Registros visibles</small>
                    </article>

                    <article class="tarjeta-estadistica tarjeta-verde">
                        <span class="etiqueta-estadistica">Activos</span>
                        <strong><?php echo (int) $totalActivos; ?></strong>
                        <small>Habilitados para asistencia</small>
                    </article>

                    <article class="tarjeta-estadistica tarjeta-celeste">
                        <span class="etiqueta-estadistica">Con huella</span>
                        <strong><?php echo (int) $totalConHuella; ?></strong>
                        <small>Identificación vinculada</small>
                    </article>

                    <article class="tarjeta-estadistica tarjeta-roja">
                        <span class="etiqueta-estadistica">Sin huella</span>
                        <strong><?php echo (int) $totalSinHuella; ?></strong>
                        <small>Pendientes de enrolar</small>
                    </article>
                </section>

                <section class="tarjeta-panel panel-premium">
                    <div class="panel-cabecera">
                        <div>
                            <span class="panel-suptitulo">Directorio estudiantil</span>
                            <h2 class="titulo-tarjeta-panel">Lista de estudiantes</h2>
                        </div>

                        <div class="panel-contador">
                            <?php echo (int) $totalEstudiantes; ?> registros
                        </div>
                    </div>

                    <div class="barra-filtros filtros-premium">
                        <form method="GET" action="estudiantes.php" class="formulario-busqueda formulario-busqueda-premium">
                            <div class="campo-busqueda-premium">
                                <span>⌕</span>
                                <input
                                    type="text"
                                    name="buscar"
                                    placeholder="Buscar por código, nombre o correo"
                                    value="<?php echo texto_seguro($busqueda); ?>"
                                >
                            </div>

                            <button type="submit" class="boton-secundario">Buscar</button>

                            <?php if ($busqueda !== ''): ?>
                                <a href="estudiantes.php" class="boton-secundario">Limpiar</a>
                            <?php endif; ?>
                        </form>

                        <?php if ($rol === 'Administrador'): ?>
                            <button type="button" class="boton-primario" onclick="abrirModalEstudiante()">
                                Nuevo estudiante
                            </button>
                        <?php endif; ?>
                    </div>

                    <div class="tabla-contenedor-premium">
                        <table class="tabla-datos tabla-premium" id="tablaEstudiantes">
                            <thead>
                                <tr>
                                    <th>Código</th>
                                    <th>Estudiante</th>
                                    <th>Programa</th>
                                    <th>Ciclo</th>
                                    <th>WhatsApp</th>
                                    <th>Correo</th>
                                    <th>ID Huella</th>
                                    <th>Estado</th>
                                    <?php if ($rol === 'Administrador'): ?>
                                        <th>Acciones</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($listaEstudiantes) === 0): ?>
                                    <tr>
                                        <td colspan="<?php echo $rol === 'Administrador' ? '9' : '8'; ?>" class="texto-sin-datos">
                                            No hay registros disponibles
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($listaEstudiantes as $estudiante): ?>
                                        <?php
                                        $datosEstudiante = htmlspecialchars(
                                            json_encode($estudiante, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        );

                                        $nombreCompleto = trim($estudiante['nombres'] . ' ' . $estudiante['apellidos']);
                                        $iniciales = mb_substr($estudiante['nombres'], 0, 1, 'UTF-8') . mb_substr($estudiante['apellidos'], 0, 1, 'UTF-8');
                                        ?>
                                        <tr>
                                            <td>
                                                <span class="codigo-estudiante-premium">
                                                    <?php echo texto_seguro($estudiante['codigo_estudiante']); ?>
                                                </span>
                                            </td>

                                            <td>
                                                <div class="celda-persona">
                                                    <span class="avatar-tabla">
                                                        <?php echo texto_seguro(mb_strtoupper($iniciales, 'UTF-8')); ?>
                                                    </span>
                                                    <div>
                                                        <strong><?php echo texto_seguro($nombreCompleto); ?></strong>
                                                        <small><?php echo texto_seguro($estudiante['apellidos']); ?></small>
                                                    </div>
                                                </div>
                                            </td>

                                            <td><?php echo texto_seguro($estudiante['programa_estudios']); ?></td>
                                            <td><?php echo texto_seguro($estudiante['ciclo']); ?></td>
                                            <td><?php echo texto_seguro($estudiante['whatsapp'] ?? '-'); ?></td>
                                            <td><?php echo texto_seguro($estudiante['correo_institucional'] ?? '-'); ?></td>

                                            <td>
                                                <?php if (!empty($estudiante['id_huella'])): ?>
                                                    <span class="badge-huella activo">ID <?php echo texto_seguro($estudiante['id_huella']); ?></span>
                                                <?php else: ?>
                                                    <span class="badge-huella pendiente">Sin asignar</span>
                                                <?php endif; ?>
                                            </td>

                                            <td>
                                                <span class="etiqueta-estado <?php echo $estudiante['estado'] === 'Activo' ? 'estado-puntual' : 'estado-falto'; ?>">
                                                    <?php echo texto_seguro($estudiante['estado']); ?>
                                                </span>
                                            </td>

                                            <?php if ($rol === 'Administrador'): ?>
                                                <td>
                                                    <div class="acciones-tabla">
                                                        <button
                                                            type="button"
                                                            class="boton-secundario boton-mini"
                                                            onclick='abrirModalEstudiante(<?php echo $datosEstudiante; ?>)'
                                                        >
                                                            Editar
                                                        </button>

                                                        <form method="POST" action="estudiantes.php" class="formulario-inline">
                                                            <input type="hidden" name="csrf_token" value="<?php echo texto_seguro($tokenCsrf); ?>">
                                                            <input type="hidden" name="accion" value="eliminar">
                                                            <input type="hidden" name="id_estudiante" value="<?php echo (int) $estudiante['id_estudiante']; ?>">

                                                            <button
                                                                type="submit"
                                                                class="boton-secundario boton-mini boton-riesgo"
                                                                data-confirmar="¿Seguro que deseas desactivar a este estudiante?"
                                                            >
                                                                Desactivar
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
    </div>

    <?php if ($rol === 'Administrador'): ?>
        <div class="fondo-modal" id="fondoModalEstudiante" style="display:none;">
            <div class="tarjeta-panel modal-formulario modal-formulario-premium">
                <div class="modal-cabecera-premium">
                    <div>
                        <span class="panel-suptitulo">Registro estudiantil</span>
                        <h2 class="titulo-tarjeta-panel" id="tituloModalEstudiante">Registrar nuevo estudiante</h2>
                    </div>

                    <button type="button" class="modal-cerrar" onclick="cerrarModalEstudiante()">×</button>
                </div>

                <form method="POST" action="estudiantes.php" class="formulario-panel formulario-premium">
                    <input type="hidden" name="csrf_token" value="<?php echo texto_seguro($tokenCsrf); ?>">
                    <input type="hidden" name="accion" value="guardar">
                    <input type="hidden" name="id_estudiante" id="campoIdEstudiante" value="">

                    <div class="grupo-campo">
                        <label for="campoCodigo">Código de estudiante</label>
                        <input
                            type="text"
                            name="codigo_estudiante"
                            id="campoCodigo"
                            placeholder="<?php echo texto_seguro($codigoSugerido); ?>"
                        >
                    </div>

                    <div class="grupo-campo">
                        <label for="campoNombres">Nombres</label>
                        <input type="text" name="nombres" id="campoNombres" required>
                    </div>

                    <div class="grupo-campo">
                        <label for="campoApellidos">Apellidos</label>
                        <input type="text" name="apellidos" id="campoApellidos" required>
                    </div>

                    <div class="grupo-campo">
                        <label for="campoPrograma">Programa de estudios</label>
                        <select name="programa_estudios" id="campoPrograma">
                            <option value="Computación e Informática">Computación e Informática</option>
                        </select>
                    </div>

                    <div class="grupo-campo">
                        <label for="campoCicloVisual">Ciclo</label>
                        <input type="hidden" name="ciclo" id="campoCiclo" value="">
                        <input type="text" id="campoCicloVisual" value="" readonly>
                    </div>

                    <div class="grupo-campo">
                        <label for="campoWhatsapp">WhatsApp</label>
                        <input type="text" name="whatsapp" id="campoWhatsapp" placeholder="Pendiente">
                    </div>

                    <div class="grupo-campo">
                        <label for="campoCorreo">Correo institucional</label>
                        <input type="email" name="correo_institucional" id="campoCorreo" placeholder="Pendiente">
                    </div>

                    <div class="grupo-campo">
                        <label for="campoIdHuella">ID de huella</label>
                        <input type="number" name="id_sensor" id="campoIdHuella" min="1" placeholder="Asignado en pruebas reales">
                    </div>

                    <div class="grupo-campo">
                        <label for="campoEstado">Estado</label>
                        <select name="estado" id="campoEstado">
                            <option value="Activo">Activo</option>
                            <option value="Inactivo">Inactivo</option>
                        </select>
                    </div>

                    <div class="acciones-panel acciones-modal-premium">
                        <button type="button" class="boton-secundario" onclick="cerrarModalEstudiante()">
                            Cancelar
                        </button>

                        <button type="submit" class="boton-primario">
                            Guardar estudiante
                        </button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <script src="<?php echo app_url('assets/js/main.js?v=9999'); ?>"></script>
    <script>
        const cicloPeriodoActual = <?php echo json_encode($cicloPeriodoActual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

        function abrirModalEstudiante(datos) {
            document.getElementById('tituloModalEstudiante').textContent = datos ? 'Editar estudiante' : 'Registrar nuevo estudiante';
            document.getElementById('campoIdEstudiante').value = datos ? datos.id_estudiante : '';
            document.getElementById('campoCodigo').value = datos ? datos.codigo_estudiante : '';
            document.getElementById('campoNombres').value = datos ? datos.nombres : '';
            document.getElementById('campoApellidos').value = datos ? datos.apellidos : '';
            document.getElementById('campoPrograma').value = datos ? datos.programa_estudios : 'Computación e Informática';

            const cicloSeleccionado = datos ? datos.ciclo : cicloPeriodoActual;
            document.getElementById('campoCiclo').value = cicloSeleccionado || '';
            document.getElementById('campoCicloVisual').value = cicloSeleccionado ? cicloSeleccionado + ' Ciclo' : 'Sin periodo vigente';

            document.getElementById('campoWhatsapp').value = datos && datos.whatsapp ? datos.whatsapp : '';
            document.getElementById('campoCorreo').value = datos && datos.correo_institucional ? datos.correo_institucional : '';
            document.getElementById('campoIdHuella').value = datos && datos.id_huella ? datos.id_huella : '';
            document.getElementById('campoEstado').value = datos ? datos.estado : 'Activo';
            document.getElementById('fondoModalEstudiante').style.display = 'flex';
            document.body.classList.add('modal-abierto');
        }

        function cerrarModalEstudiante() {
            document.getElementById('fondoModalEstudiante').style.display = 'none';
            document.body.classList.remove('modal-abierto');
        }
    </script>
</body>
</html>