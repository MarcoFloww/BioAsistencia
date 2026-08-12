<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/funciones.php';

requerir_rol(['Administrador']);

$rol = obtener_rol_usuario();
$nombreUsuario = obtener_nombre_usuario();
$estadoDispositivo = obtener_estado_dispositivo($pdo);
$claseEstadoDispositivo = clase_estado_dispositivo($estadoDispositivo);
$mensajeExito = '';
$mensajeError = '';

function obtener_id_rol_docente(PDO $pdo): int
{
    $consulta = $pdo->prepare(
        "SELECT id_rol
         FROM roles
         WHERE nombre_rol = 'Docente'
         LIMIT 1"
    );

    $consulta->execute();
    $rolDocente = $consulta->fetch();

    if (!$rolDocente) {
        throw new Exception('No existe el rol Docente en la base de datos.');
    }

    return (int) $rolDocente['id_rol'];
}

function generar_codigo_docente(PDO $pdo): string
{
    $consulta = $pdo->query(
        "SELECT codigo_docente
         FROM docentes
         WHERE codigo_docente LIKE 'DOC%'
         ORDER BY CAST(SUBSTRING(codigo_docente, 4) AS UNSIGNED) DESC
         LIMIT 1"
    );

    $fila = $consulta->fetch();

    if (!$fila) {
        return 'DOC001';
    }

    $numero = (int) substr((string) $fila['codigo_docente'], 3);

    return 'DOC' . str_pad((string) ($numero + 1), 3, '0', STR_PAD_LEFT);
}

function inicial_docente(string $texto): string
{
    $texto = trim($texto);

    if ($texto === '') {
        return '';
    }

    if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
        return mb_strtoupper(mb_substr($texto, 0, 1, 'UTF-8'), 'UTF-8');
    }

    return strtoupper(substr($texto, 0, 1));
}

function iniciales_docente(string $nombres, string $apellidos): string
{
    $primerNombre = explode(' ', trim($nombres))[0] ?? '';
    $primerApellido = explode(' ', trim($apellidos))[0] ?? '';

    $iniciales = inicial_docente($primerNombre) . inicial_docente($primerApellido);

    return $iniciales !== '' ? $iniciales : 'DC';
}

function porcentaje_docentes(int $valor, int $total): int
{
    if ($total <= 0) {
        return 0;
    }

    return (int) round(($valor / $total) * 100);
}

$tokenCsrf = generar_token_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requerir_token_csrf($_POST['csrf_token'] ?? null);

    $accion = $_POST['accion'] ?? '';

    try {
        if ($accion === 'guardar') {
            $idDocente = (int) ($_POST['id_docente'] ?? 0);
            $idUsuario = (int) ($_POST['id_usuario'] ?? 0);
            $codigoDocente = limpiar_texto($_POST['codigo_docente'] ?? '');
            $dni = limpiar_texto($_POST['dni'] ?? '');
            $nombres = limpiar_texto($_POST['nombres'] ?? '');
            $apellidos = limpiar_texto($_POST['apellidos'] ?? '');
            $usuario = limpiar_texto($_POST['usuario'] ?? '');
            $correo = limpiar_texto($_POST['correo_institucional'] ?? '');
            $whatsapp = limpiar_texto($_POST['whatsapp'] ?? '');
            $tituloCargo = limpiar_texto($_POST['titulo_cargo'] ?? '');
            $contrasena = $_POST['contrasena'] ?? '';
            $estado = limpiar_texto($_POST['estado'] ?? 'Activo');

            if ($codigoDocente === '') {
                $codigoDocente = generar_codigo_docente($pdo);
            }

            if ($dni === '' || $nombres === '' || $apellidos === '' || $usuario === '' || $correo === '' || $tituloCargo === '') {
                $mensajeError = 'Completa los campos obligatorios.';
            } elseif (!preg_match('/^[0-9]{8}$/', $dni)) {
                $mensajeError = 'El DNI debe contener 8 números.';
            } elseif (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                $mensajeError = 'El correo institucional no es válido.';
            } elseif (!in_array($estado, ['Activo', 'Inactivo'], true)) {
                $mensajeError = 'El estado no es válido.';
            } elseif ($idDocente === 0 && $contrasena === '') {
                $mensajeError = 'La contraseña es obligatoria para registrar un nuevo docente.';
            } else {
                $pdo->beginTransaction();

                $validarUsuario = $pdo->prepare(
                    "SELECT id_usuario
                     FROM usuarios
                     WHERE usuario = :usuario
                     AND id_usuario <> :id_usuario
                     LIMIT 1"
                );

                $validarUsuario->execute([
                    'usuario' => $usuario,
                    'id_usuario' => $idUsuario
                ]);

                if ($validarUsuario->fetch()) {
                    throw new Exception('Ese nombre de usuario ya está registrado.');
                }

                $idRolDocente = obtener_id_rol_docente($pdo);

                if ($idDocente > 0 && $idUsuario > 0) {
                    if ($contrasena !== '') {
                        $hash = password_hash($contrasena, PASSWORD_BCRYPT);

                        $actualizarUsuario = $pdo->prepare(
                            "UPDATE usuarios
                             SET id_rol = :id_rol,
                                 usuario = :usuario,
                                 contrasena = :contrasena,
                                 nombres = :nombres,
                                 apellidos = :apellidos,
                                 correo = :correo,
                                 estado = :estado
                             WHERE id_usuario = :id_usuario"
                        );

                        $actualizarUsuario->execute([
                            'id_rol' => $idRolDocente,
                            'usuario' => $usuario,
                            'contrasena' => $hash,
                            'nombres' => $nombres,
                            'apellidos' => $apellidos,
                            'correo' => $correo,
                            'estado' => $estado,
                            'id_usuario' => $idUsuario
                        ]);
                    } else {
                        $actualizarUsuario = $pdo->prepare(
                            "UPDATE usuarios
                             SET id_rol = :id_rol,
                                 usuario = :usuario,
                                 nombres = :nombres,
                                 apellidos = :apellidos,
                                 correo = :correo,
                                 estado = :estado
                             WHERE id_usuario = :id_usuario"
                        );

                        $actualizarUsuario->execute([
                            'id_rol' => $idRolDocente,
                            'usuario' => $usuario,
                            'nombres' => $nombres,
                            'apellidos' => $apellidos,
                            'correo' => $correo,
                            'estado' => $estado,
                            'id_usuario' => $idUsuario
                        ]);
                    }

                    $actualizarDocente = $pdo->prepare(
                        "UPDATE docentes
                         SET codigo_docente = :codigo_docente,
                             nombres = :nombres,
                             apellidos = :apellidos,
                             dni = :dni,
                             correo = :correo,
                             telefono = :telefono,
                             titulo_cargo = :titulo_cargo,
                             correo_institucional = :correo_institucional,
                             whatsapp = :whatsapp,
                             estado = :estado
                         WHERE id_docente = :id_docente"
                    );

                    $actualizarDocente->execute([
                        'codigo_docente' => $codigoDocente,
                        'nombres' => $nombres,
                        'apellidos' => $apellidos,
                        'dni' => $dni,
                        'correo' => $correo,
                        'telefono' => $whatsapp !== '' ? $whatsapp : null,
                        'titulo_cargo' => $tituloCargo,
                        'correo_institucional' => $correo,
                        'whatsapp' => $whatsapp !== '' ? $whatsapp : null,
                        'estado' => $estado,
                        'id_docente' => $idDocente
                    ]);

                    $mensajeExito = 'Docente actualizado correctamente.';
                } else {
                    $hash = password_hash($contrasena, PASSWORD_BCRYPT);

                    $registrarUsuario = $pdo->prepare(
                        "INSERT INTO usuarios
                         (id_rol, usuario, contrasena, nombres, apellidos, correo, estado)
                         VALUES
                         (:id_rol, :usuario, :contrasena, :nombres, :apellidos, :correo, :estado)"
                    );

                    $registrarUsuario->execute([
                        'id_rol' => $idRolDocente,
                        'usuario' => $usuario,
                        'contrasena' => $hash,
                        'nombres' => $nombres,
                        'apellidos' => $apellidos,
                        'correo' => $correo,
                        'estado' => $estado
                    ]);

                    $idUsuarioNuevo = (int) $pdo->lastInsertId();

                    $registrarDocente = $pdo->prepare(
                        "INSERT INTO docentes
                         (id_usuario, codigo_docente, nombres, apellidos, dni, correo, telefono, titulo_cargo, correo_institucional, whatsapp, estado)
                         VALUES
                         (:id_usuario, :codigo_docente, :nombres, :apellidos, :dni, :correo, :telefono, :titulo_cargo, :correo_institucional, :whatsapp, :estado)"
                    );

                    $registrarDocente->execute([
                        'id_usuario' => $idUsuarioNuevo,
                        'codigo_docente' => $codigoDocente,
                        'nombres' => $nombres,
                        'apellidos' => $apellidos,
                        'dni' => $dni,
                        'correo' => $correo,
                        'telefono' => $whatsapp !== '' ? $whatsapp : null,
                        'titulo_cargo' => $tituloCargo,
                        'correo_institucional' => $correo,
                        'whatsapp' => $whatsapp !== '' ? $whatsapp : null,
                        'estado' => $estado
                    ]);

                    $mensajeExito = 'Docente registrado correctamente.';
                }

                $pdo->commit();
            }
        }

if ($accion === 'desactivar') {
    $idUsuario = (int) ($_POST['id_usuario'] ?? 0);

    if ($idUsuario > 0) {
        $pdo->beginTransaction();

        $consulta = $pdo->prepare(
            "UPDATE usuarios
             SET estado = 'Inactivo'
             WHERE id_usuario = :id_usuario"
        );

        $consulta->execute([
            'id_usuario' => $idUsuario
        ]);

        $consultaDocente = $pdo->prepare(
            "UPDATE docentes
             SET estado = 'Inactivo'
             WHERE id_usuario = :id_usuario"
        );

        $consultaDocente->execute([
            'id_usuario' => $idUsuario
        ]);

        $pdo->commit();

        $mensajeExito = 'Docente desactivado correctamente.';
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
    $sqlBusqueda = "WHERE u.nombres LIKE :buscar
                    OR u.apellidos LIKE :buscar
                    OR u.usuario LIKE :buscar
                    OR d.correo_institucional LIKE :buscar
                    OR d.titulo_cargo LIKE :buscar";

    $parametros['buscar'] = '%' . $busqueda . '%';
}

$consultaDocentes = $pdo->prepare(
    "SELECT d.id_docente,
            d.id_usuario,
            d.codigo_docente,
            d.dni,
            d.titulo_cargo,
            d.correo_institucional,
            d.whatsapp,
            u.usuario,
            u.nombres,
            u.apellidos,
            u.estado,
            COALESCE(GROUP_CONCAT(c.nombre_curso ORDER BY c.nombre_curso ASC SEPARATOR ', '), 'Sin cursos asignados') AS cursos_asignados,
            COUNT(dc.id_docente_curso) AS total_cursos
     FROM docentes d
     INNER JOIN usuarios u ON d.id_usuario = u.id_usuario
     LEFT JOIN docente_curso dc ON d.id_docente = dc.id_docente
     LEFT JOIN cursos c ON dc.id_curso = c.id_curso
     $sqlBusqueda
     GROUP BY d.id_docente,
              d.id_usuario,
              d.codigo_docente,
              d.dni,
              d.titulo_cargo,
              d.correo_institucional,
              d.whatsapp,
              u.usuario,
              u.nombres,
              u.apellidos,
              u.estado
     ORDER BY u.apellidos ASC, u.nombres ASC"
);

$consultaDocentes->execute($parametros);
$listaDocentes = $consultaDocentes->fetchAll();

$totalDocentes = count($listaDocentes);
$totalActivos = 0;
$totalInactivos = 0;
$totalCursosAsignados = 0;

foreach ($listaDocentes as $docenteResumen) {
    if ($docenteResumen['estado'] === 'Activo') {
        $totalActivos++;
    } else {
        $totalInactivos++;
    }

    $totalCursosAsignados += (int) $docenteResumen['total_cursos'];
}

$porcentajeActivos = porcentaje_docentes($totalActivos, $totalDocentes);
$porcentajeCursos = $totalDocentes > 0 ? min(100, (int) round(($totalCursosAsignados / max(1, $totalDocentes * 3)) * 100)) : 0;

$menuAdministrador = [
    ['Panel General', 'dashboard.php', 'panel', '⌂'],
    ['Estudiantes', 'estudiantes.php', 'estudiantes', '◉'],
    ['Docentes', 'docentes.php', 'docentes', '♟'],
    ['Cursos y Horarios', 'cursos_horarios.php', 'cursos', '▣'],
    ['Asistencia', 'asistencia.php', 'asistencia', '◆'],
    ['Reportes', 'reportes.php', 'reportes', '▤'],
    ['Alertas', 'alertas.php', 'alertas', '▲'],
    ['Usuarios', 'usuarios.php', 'usuarios', '◈'],
    ['Configuración', 'configuracion.php', 'configuracion', '⚙']
];

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BioAsistencia - Docentes</title>
    <link rel="icon" type="image/png" href="<?php echo app_url('assets/img/logo.png?v=1'); ?>">
    <link rel="shortcut icon" type="image/png" href="<?php echo app_url('assets/img/logo.png?v=1'); ?>">
    <link rel="stylesheet" href="<?php echo app_url('assets/css/styles.css?v=9999'); ?>">
</head>
<body class="pagina-interna pagina-premium modulo-docentes">

    <div class="fondo-premium" aria-hidden="true">
        <span class="fondo-luz fondo-luz-uno"></span>
        <span class="fondo-luz fondo-luz-dos"></span>
        <span class="fondo-grid"></span>
    </div>

    <?php
   $menuActual = 'docentes';
   require __DIR__ . '/../includes/sidebar.php';
   ?>

    <div class="contenido-principal contenido-premium">
        <header class="barra-superior header-premium">
            <div class="header-titulo">
                <span class="breadcrumb-premium">BioAsistencia / Gestión Académica</span>
                <h1 class="titulo-pagina">Docentes</h1>
                <p>Administra docentes, usuarios de acceso y cursos asignados al sistema.</p>
            </div>

            <div class="acciones-superiores header-acciones">
<div class="indicador-dispositivo indicador-<?php echo htmlspecialchars($claseEstadoDispositivo, ENT_QUOTES, 'UTF-8'); ?>">
    <span class="punto-indicador"></span>
    <?php echo htmlspecialchars($estadoDispositivo, ENT_QUOTES, 'UTF-8'); ?>
</div>

                <div class="usuario-premium">
                    <span class="avatar-usuario"><?php echo htmlspecialchars(iniciales_docente($nombreUsuario, ''), ENT_QUOTES, 'UTF-8'); ?></span>
                    <div>
                        <strong><?php echo htmlspecialchars($nombreUsuario, ENT_QUOTES, 'UTF-8'); ?></strong>
                        <small><?php echo htmlspecialchars($rol, ENT_QUOTES, 'UTF-8'); ?></small>
                    </div>
                </div>

                <a href="<?php echo app_url('logout.php'); ?>" class="boton-cerrar-sesion boton-salir-premium">Cerrar Sesión</a>
            </div>
        </header>

        <main class="area-contenido area-premium">
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

            <section class="hero-modulo hero-docentes">
                <div class="hero-modulo-info">
                    <span class="modulo-chip">Gestión de docentes</span>
                    <h2>Equipo docente autorizado</h2>
                    <p>
                        Controla los docentes con acceso al sistema, su usuario de ingreso y los cursos que tienen asignados.
                    </p>
                </div>

                <div class="hero-modulo-panel">
                    <span>Docentes activos</span>
                    <strong><?php echo (int) $totalActivos; ?></strong>
                    <small><?php echo (int) $porcentajeActivos; ?>% del equipo registrado</small>
                </div>
            </section>

            <section class="resumen-premium">
                <article class="stat-card stat-blue">
                    <div class="stat-head">
                        <div class="stat-icon">♟</div>
                        <span class="stat-chip">Equipo</span>
                    </div>
                    <div class="stat-label">Docentes registrados</div>
                    <div class="stat-number"><?php echo (int) $totalDocentes; ?></div>
                    <div class="stat-bar"><span style="width:100%"></span></div>
                </article>

                <article class="stat-card stat-green">
                    <div class="stat-head">
                        <div class="stat-icon">✅</div>
                        <span class="stat-chip">Activos</span>
                    </div>
                    <div class="stat-label">Docentes activos</div>
                    <div class="stat-number"><?php echo (int) $totalActivos; ?></div>
                    <div class="stat-bar"><span style="width:<?php echo (int) $porcentajeActivos; ?>%"></span></div>
                </article>

                <article class="stat-card stat-orange">
                    <div class="stat-head">
                        <div class="stat-icon">▣</div>
                        <span class="stat-chip">Cursos</span>
                    </div>
                    <div class="stat-label">Cursos asignados</div>
                    <div class="stat-number"><?php echo (int) $totalCursosAsignados; ?></div>
                    <div class="stat-bar"><span style="width:<?php echo (int) $porcentajeCursos; ?>%"></span></div>
                </article>

                <article class="stat-card stat-red">
                    <div class="stat-head">
                        <div class="stat-icon">○</div>
                        <span class="stat-chip">Inactivos</span>
                    </div>
                    <div class="stat-label">Docentes inactivos</div>
                    <div class="stat-number"><?php echo (int) $totalInactivos; ?></div>
                    <div class="stat-bar"><span style="width:<?php echo (int) porcentaje_docentes($totalInactivos, $totalDocentes); ?>%"></span></div>
                </article>
            </section>

            <section class="tarjeta-panel tarjeta-premium tabla-modulo-panel">
                <div class="panel-encabezado">
                    <div>
                        <span class="panel-kicker">Directorio académico</span>
                        <h2 class="titulo-tarjeta-panel">Listado de docentes</h2>
                        <p>Consulta, registra y actualiza los docentes autorizados en BioAsistencia.</p>
                    </div>

                    <button type="button" class="boton-primario boton-premium" onclick="abrirModalDocente()">
                        Registrar nuevo docente
                    </button>
                </div>

                <div class="barra-filtros filtros-premium">
                    <form method="GET" action="docentes.php" class="formulario-busqueda">
                        <input
                            type="text"
                            name="buscar"
                            placeholder="Buscar por nombre, usuario, correo o cargo"
                            value="<?php echo htmlspecialchars($busqueda, ENT_QUOTES, 'UTF-8'); ?>"
                        >

                        <button type="submit" class="boton-secundario">Buscar</button>

                        <?php if ($busqueda !== ''): ?>
                            <a href="docentes.php" class="boton-secundario">Limpiar</a>
                        <?php endif; ?>
                    </form>
                </div>

                <div class="tabla-contenedor-premium">
                    <table class="tabla-datos tabla-premium">
                        <thead>
                            <tr>
                                <th>Docente</th>
                                <th>Usuario</th>
                                <th>Cursos asignados</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (count($listaDocentes) === 0): ?>
                                <tr>
                                    <td colspan="5" class="texto-sin-datos">No hay docentes registrados</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($listaDocentes as $docente): ?>
                                    <?php
                                    $datosDocente = htmlspecialchars(
                                        json_encode($docente, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    );
                                    ?>

                                    <tr>
                                        <td>
                                            <div class="celda-persona">
                                                <span class="avatar-tabla">
                                                    <?php echo htmlspecialchars(iniciales_docente($docente['nombres'], $docente['apellidos']), ENT_QUOTES, 'UTF-8'); ?>
                                                </span>
                                                <div>
                                                    <strong>
                                                        <?php echo htmlspecialchars($docente['nombres'] . ' ' . $docente['apellidos'], ENT_QUOTES, 'UTF-8'); ?>
                                                    </strong>
                                                    <small><?php echo htmlspecialchars($docente['titulo_cargo'], ENT_QUOTES, 'UTF-8'); ?></small>
                                                </div>
                                            </div>
                                        </td>

                                        <td>
                                            <span class="badge-tecnico">
                                                <?php echo htmlspecialchars($docente['usuario'], ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </td>

                                        <td>
                                            <span class="texto-tabla-largo">
                                                <?php echo htmlspecialchars($docente['cursos_asignados'], ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </td>

                                        <td>
                                            <span class="etiqueta-estado <?php echo $docente['estado'] === 'Activo' ? 'estado-puntual' : 'estado-falto'; ?>">
                                                <?php echo htmlspecialchars($docente['estado'], ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </td>

                                        <td>
                                            <div class="acciones-tabla">
                                                <button
                                                    type="button"
                                                    class="boton-secundario boton-mini"
                                                    onclick='abrirModalDocente(<?php echo $datosDocente; ?>)'
                                                >
                                                    Editar
                                                </button>

                                                <form method="POST" action="docentes.php">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($tokenCsrf, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="accion" value="desactivar">
                                                    <input type="hidden" name="id_usuario" value="<?php echo (int) $docente['id_usuario']; ?>">

                                                    <button
                                                        type="submit"
                                                        class="boton-peligro boton-mini"
                                                        data-confirmar="¿Seguro que deseas desactivar a este docente?"
                                                    >
                                                        Desactivar
                                                    </button>
                                                </form>
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

    <div class="fondo-modal" id="fondoModalDocente" style="display:none;">
        <div class="tarjeta-panel modal-formulario modal-formulario-premium">
            <div class="modal-cabecera-premium">
                <div>
                    <span class="panel-kicker">Formulario docente</span>
                    <h2 class="titulo-tarjeta-panel" id="tituloModalDocente">Registrar nuevo docente</h2>
                </div>

                <button type="button" class="modal-cerrar" onclick="cerrarModalDocente()">×</button>
            </div>

            <form method="POST" action="docentes.php" class="formulario-panel formulario-premium">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($tokenCsrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="accion" value="guardar">
                <input type="hidden" name="id_docente" id="campoIdDocente" value="">
                <input type="hidden" name="id_usuario" id="campoIdUsuario" value="">

                <div class="grupo-campo">
                    <label for="campoCodigoDocente">Código docente</label>
                    <input type="text" name="codigo_docente" id="campoCodigoDocente" placeholder="Se genera automáticamente">
                </div>

                <div class="grupo-campo">
                    <label for="campoDni">DNI</label>
                    <input type="text" name="dni" id="campoDni" maxlength="8" inputmode="numeric" required>
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
                    <label for="campoUsuario">Usuario</label>
                    <input type="text" name="usuario" id="campoUsuario" required>
                </div>

                <div class="grupo-campo">
                    <label for="campoCorreo">Correo institucional</label>
                    <input type="email" name="correo_institucional" id="campoCorreo" required>
                </div>

                <div class="grupo-campo">
                    <label for="campoWhatsapp">WhatsApp</label>
                    <input type="text" name="whatsapp" id="campoWhatsapp">
                </div>

                <div class="grupo-campo">
                    <label for="campoTituloCargo">Título o cargo</label>
                    <input type="text" name="titulo_cargo" id="campoTituloCargo" placeholder="Ejemplo: Ing." required>
                </div>

                <div class="grupo-campo">
                    <label for="campoContrasena">Contraseña</label>
                    <input type="password" name="contrasena" id="campoContrasena">
                </div>

                <div class="grupo-campo">
                    <label for="campoEstado">Estado</label>
                    <select name="estado" id="campoEstado">
                        <option value="Activo">Activo</option>
                        <option value="Inactivo">Inactivo</option>
                    </select>
                </div>

                <div class="acciones-panel acciones-modal-premium">
                    <button type="button" class="boton-secundario" onclick="cerrarModalDocente()">Cancelar</button>
                    <button type="submit" class="boton-primario">Guardar</button>
                </div>
            </form>
        </div>
    </div>

    <script src="<?php echo app_url('assets/js/main.js?v=9999'); ?>"></script>
    <script>
        function abrirModalDocente(datos) {
            document.getElementById('tituloModalDocente').textContent = datos ? 'Editar docente' : 'Registrar nuevo docente';
            document.getElementById('campoIdDocente').value = datos ? datos.id_docente : '';
            document.getElementById('campoIdUsuario').value = datos ? datos.id_usuario : '';
            document.getElementById('campoCodigoDocente').value = datos ? datos.codigo_docente : '';
            document.getElementById('campoDni').value = datos ? datos.dni : '';
            document.getElementById('campoNombres').value = datos ? datos.nombres : '';
            document.getElementById('campoApellidos').value = datos ? datos.apellidos : '';
            document.getElementById('campoUsuario').value = datos ? datos.usuario : '';
            document.getElementById('campoCorreo').value = datos ? datos.correo_institucional : '';
            document.getElementById('campoWhatsapp').value = datos && datos.whatsapp ? datos.whatsapp : '';
            document.getElementById('campoTituloCargo').value = datos ? datos.titulo_cargo : '';
            document.getElementById('campoEstado').value = datos ? datos.estado : 'Activo';
            document.getElementById('campoContrasena').value = '';
            document.getElementById('campoContrasena').placeholder = datos ? 'Dejar vacío si no deseas cambiarla' : 'Contraseña del docente';
            document.getElementById('fondoModalDocente').style.display = 'flex';
        }

        function cerrarModalDocente() {
            document.getElementById('fondoModalDocente').style.display = 'none';
        }
    </script>

</body>
</html> 