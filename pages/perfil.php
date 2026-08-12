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
$tokenCsrf = generar_token_csrf();

function obtener_id_usuario_perfil(): int
{
    if (function_exists('usuario_actual')) {
        $usuarioActual = usuario_actual();

        if (is_array($usuarioActual)) {
            $posiblesClaves = [
                'id_usuario',
                'usuario_id',
                'id'
            ];

            foreach ($posiblesClaves as $clave) {
                if (isset($usuarioActual[$clave]) && (int) $usuarioActual[$clave] > 0) {
                    return (int) $usuarioActual[$clave];
                }
            }
        }
    }

    $posiblesSesiones = [
        $_SESSION['id_usuario'] ?? null,
        $_SESSION['usuario_id'] ?? null,
        $_SESSION['id'] ?? null,
        $_SESSION['usuario']['id_usuario'] ?? null,
        $_SESSION['usuario']['usuario_id'] ?? null,
        $_SESSION['usuario']['id'] ?? null,
        $_SESSION['usuario_actual']['id_usuario'] ?? null,
        $_SESSION['usuario_actual']['usuario_id'] ?? null,
        $_SESSION['usuario_actual']['id'] ?? null
    ];

    foreach ($posiblesSesiones as $valor) {
        if ((int) $valor > 0) {
            return (int) $valor;
        }
    }

    return 0;
}

function iniciales_perfil(string $nombres, string $apellidos): string
{
    $primeraInicial = mb_substr(trim($nombres), 0, 1, 'UTF-8');
    $segundaInicial = mb_substr(trim($apellidos), 0, 1, 'UTF-8');

    $iniciales = mb_strtoupper($primeraInicial . $segundaInicial, 'UTF-8');

    return $iniciales !== '' ? $iniciales : 'BA';
}

function clase_estado_usuario_perfil(string $estado): string
{
    return $estado === 'Activo' ? 'estado-puntual' : 'estado-falto';
}

$idUsuarioPerfil = obtener_id_usuario_perfil();

if ($idUsuarioPerfil <= 0) {
    cerrar_sesion();
    header('Location: ' . app_url('login.php'));
    exit;
}

$consultaPerfil = $pdo->prepare(
    "SELECT u.id_usuario,
            u.id_rol,
            u.usuario,
            u.contrasena,
            u.nombres,
            u.apellidos,
            u.correo,
            u.estado,
            u.fecha_creacion,
            u.ultimo_acceso,
            r.nombre_rol,
            d.id_docente,
            d.titulo_cargo,
            d.correo_institucional,
            d.whatsapp
     FROM usuarios u
     INNER JOIN roles r ON u.id_rol = r.id_rol
     LEFT JOIN docentes d ON u.id_usuario = d.id_usuario
     WHERE u.id_usuario = :id_usuario
     LIMIT 1"
);

$consultaPerfil->execute([
    'id_usuario' => $idUsuarioPerfil
]);

$perfil = $consultaPerfil->fetch();

if (!$perfil) {
    cerrar_sesion();
    header('Location: ' . app_url('login.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requerir_token_csrf($_POST['csrf_token'] ?? null);

    $accion = $_POST['accion'] ?? '';

    try {
        if ($accion === 'actualizar_perfil') {
            $nombres = limpiar_texto($_POST['nombres'] ?? '');
            $apellidos = limpiar_texto($_POST['apellidos'] ?? '');
            $correo = limpiar_texto($_POST['correo'] ?? '');
            $whatsapp = limpiar_texto($_POST['whatsapp'] ?? '');
            $tituloCargo = limpiar_texto($_POST['titulo_cargo'] ?? '');

            if ($nombres === '' || $apellidos === '' || $correo === '') {
                $mensajeError = 'Completa nombres, apellidos y correo.';
            } elseif (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                $mensajeError = 'El correo ingresado no es válido.';
            } else {
                $pdo->beginTransaction();

                $actualizarUsuario = $pdo->prepare(
                    "UPDATE usuarios
                     SET nombres = :nombres,
                         apellidos = :apellidos,
                         correo = :correo
                     WHERE id_usuario = :id_usuario"
                );

                $actualizarUsuario->execute([
                    'nombres' => $nombres,
                    'apellidos' => $apellidos,
                    'correo' => $correo,
                    'id_usuario' => $idUsuarioPerfil
                ]);

                if ($perfil['nombre_rol'] === 'Docente') {
                    if ((int) ($perfil['id_docente'] ?? 0) > 0) {
                        $actualizarDocente = $pdo->prepare(
                            "UPDATE docentes
                             SET nombres = :nombres,
                                 apellidos = :apellidos,
                                 correo = :correo,
                                 telefono = :telefono,
                                 titulo_cargo = :titulo_cargo,
                                 correo_institucional = :correo_institucional,
                                 whatsapp = :whatsapp
                             WHERE id_docente = :id_docente"
                        );

                        $actualizarDocente->execute([
                            'nombres' => $nombres,
                            'apellidos' => $apellidos,
                            'correo' => $correo,
                            'telefono' => $whatsapp !== '' ? $whatsapp : null,
                            'titulo_cargo' => $tituloCargo !== '' ? $tituloCargo : 'Docente',
                            'correo_institucional' => $correo,
                            'whatsapp' => $whatsapp !== '' ? $whatsapp : null,
                            'id_docente' => (int) $perfil['id_docente']
                        ]);
                    } else {
                        throw new Exception('La cuenta no tiene una ficha docente completa. El administrador debe registrarla desde el módulo Docentes.');
                    }
                }

                $pdo->commit();

                $_SESSION['nombres'] = $nombres;
                $_SESSION['apellidos'] = $apellidos;
                $_SESSION['correo'] = $correo;

                if (isset($_SESSION['usuario']) && is_array($_SESSION['usuario'])) {
                    $_SESSION['usuario']['nombres'] = $nombres;
                    $_SESSION['usuario']['apellidos'] = $apellidos;
                    $_SESSION['usuario']['correo'] = $correo;
                }

                $mensajeExito = 'Perfil actualizado correctamente.';

                $consultaPerfil->execute([
                    'id_usuario' => $idUsuarioPerfil
                ]);

                $perfil = $consultaPerfil->fetch();
                $nombreUsuario = trim($perfil['nombres'] . ' ' . $perfil['apellidos']);
            }
        }

        if ($accion === 'cambiar_contrasena') {
            $contrasenaActual = $_POST['contrasena_actual'] ?? '';
            $contrasenaNueva = $_POST['contrasena_nueva'] ?? '';
            $contrasenaConfirmar = $_POST['contrasena_confirmar'] ?? '';

            if ($contrasenaActual === '' || $contrasenaNueva === '' || $contrasenaConfirmar === '') {
                $mensajeError = 'Completa todos los campos de contraseña.';
            } elseif (!password_verify($contrasenaActual, $perfil['contrasena'])) {
                $mensajeError = 'La contraseña actual no es correcta.';
            } elseif (strlen($contrasenaNueva) < 8) {
                $mensajeError = 'La nueva contraseña debe tener como mínimo 8 caracteres.';
            } elseif ($contrasenaNueva !== $contrasenaConfirmar) {
                $mensajeError = 'La confirmación no coincide con la nueva contraseña.';
            } else {
                $hashNuevo = password_hash($contrasenaNueva, PASSWORD_BCRYPT);

                $actualizarContrasena = $pdo->prepare(
                    "UPDATE usuarios
                     SET contrasena = :contrasena
                     WHERE id_usuario = :id_usuario"
                );

                $actualizarContrasena->execute([
                    'contrasena' => $hashNuevo,
                    'id_usuario' => $idUsuarioPerfil
                ]);

                session_regenerate_id(true);

                $mensajeExito = 'Contraseña actualizada correctamente.';

                $consultaPerfil->execute([
                    'id_usuario' => $idUsuarioPerfil
                ]);

                $perfil = $consultaPerfil->fetch();
            }
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $mensajeError = $e->getMessage() !== '' ? $e->getMessage() : 'No se pudo completar la operación.';
    }
}

$consultaCursosDocente = $pdo->prepare(
    "SELECT c.nombre_curso
     FROM docente_curso dc
     INNER JOIN cursos c ON dc.id_curso = c.id_curso
     WHERE dc.id_docente = :id_docente
     ORDER BY c.nombre_curso ASC"
);

$cursosDocente = [];

if ((int) ($perfil['id_docente'] ?? 0) > 0) {
    $consultaCursosDocente->execute([
        'id_docente' => (int) $perfil['id_docente']
    ]);

    $cursosDocente = $consultaCursosDocente->fetchAll();
}

$consultaResumenPersonal = $pdo->prepare(
    "SELECT
        COUNT(*) AS total_registros,
        SUM(CASE WHEN a.estado_entrada = 'Puntual' THEN 1 ELSE 0 END) AS puntuales,
        SUM(CASE WHEN a.estado_entrada = 'Tardanza' THEN 1 ELSE 0 END) AS tardanzas,
        SUM(CASE WHEN a.estado_entrada = 'Falto' THEN 1 ELSE 0 END) AS faltas
     FROM asistencias a
     WHERE a.fecha BETWEEN DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND CURDATE()"
);

$consultaResumenPersonal->execute();
$resumenSistema = $consultaResumenPersonal->fetch();

$totalCursosAsignados = count($cursosDocente);
$inicialesUsuario = iniciales_perfil($perfil['nombres'], $perfil['apellidos']);
$correoPrincipal = $perfil['correo_institucional'] ?: $perfil['correo'];

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
    <title>BioAsistencia - Perfil</title>
    <link rel="icon" type="image/png" href="<?php echo app_url('assets/img/logo.png?v=1'); ?>">
    <link rel="shortcut icon" type="image/png" href="<?php echo app_url('assets/img/logo.png?v=1'); ?>">
    <link rel="stylesheet" href="<?php echo app_url('assets/css/styles.css?v=9999'); ?>">
</head>
<body class="pagina-interna">

   <?php
   $menuActual = 'perfil';
   require __DIR__ . '/../includes/sidebar.php';
   ?>

    <div class="contenido-principal">
        <header class="barra-superior">
            <div>
                <span class="breadcrumb-modulo">BioAsistencia / Cuenta</span>
                <h1 class="titulo-pagina">Mi perfil</h1>
            </div>

            <div class="acciones-superiores">
                <div class="indicador-dispositivo indicador-<?php echo htmlspecialchars($claseEstadoDispositivo, ENT_QUOTES, 'UTF-8'); ?>">
                    <span class="punto-indicador"></span>
                    <?php echo htmlspecialchars($estadoDispositivo, ENT_QUOTES, 'UTF-8'); ?>
                </div>

                <div class="menu-usuario">
                    <span class="nombre-usuario-superior">
                        <?php echo htmlspecialchars($nombreUsuario, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
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

            <section class="modulo-hero perfil-hero">
                <div class="modulo-hero-contenido">
                    <span class="modulo-kicker">Cuenta de usuario</span>
                    <h2><?php echo htmlspecialchars($perfil['nombres'] . ' ' . $perfil['apellidos'], ENT_QUOTES, 'UTF-8'); ?></h2>
                    <p>
                        Administra tus datos personales, revisa tu rol dentro del sistema y actualiza tu contraseña
                        de acceso de forma segura.
                    </p>

                    <div class="hero-mini-indicadores">
                        <span><?php echo htmlspecialchars($perfil['nombre_rol'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <span><?php echo htmlspecialchars($perfil['estado'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <span><?php echo htmlspecialchars($perfil['usuario'], ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                </div>

                <div class="perfil-identidad-card">
                    <div class="perfil-avatar-gigante">
                        <?php echo htmlspecialchars($inicialesUsuario, ENT_QUOTES, 'UTF-8'); ?>
                    </div>

                    <div>
                        <span class="perfil-chip-rol">
                            <?php echo htmlspecialchars($perfil['nombre_rol'], ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <h3><?php echo htmlspecialchars($perfil['usuario'], ENT_QUOTES, 'UTF-8'); ?></h3>
                        <p><?php echo htmlspecialchars($correoPrincipal, ENT_QUOTES, 'UTF-8'); ?></p>
                    </div>
                </div>
            </section>

            <section class="resumen-premium">
                <article class="tarjeta-estadistica stat-card">
                    <span class="etiqueta-estadistica">Rol de acceso</span>
                    <strong><?php echo htmlspecialchars($perfil['nombre_rol'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    <p>Permisos asignados</p>
                </article>

                <article class="tarjeta-estadistica stat-card stat-green">
                    <span class="etiqueta-estadistica">Estado</span>
                    <strong><?php echo htmlspecialchars($perfil['estado'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    <p>Cuenta del sistema</p>
                </article>

                <article class="tarjeta-estadistica stat-card">
                    <span class="etiqueta-estadistica">Cursos asignados</span>
                    <strong><?php echo (int) $totalCursosAsignados; ?></strong>
                    <p>Solo para docentes</p>
                </article>

                <article class="tarjeta-estadistica stat-card stat-orange">
                    <span class="etiqueta-estadistica">Último acceso</span>
                    <strong>
                        <?php echo $perfil['ultimo_acceso'] ? htmlspecialchars(date('d/m/Y', strtotime($perfil['ultimo_acceso'])), ENT_QUOTES, 'UTF-8') : 'Sin dato'; ?>
                    </strong>
                    <p>
                        <?php echo $perfil['ultimo_acceso'] ? htmlspecialchars(date('H:i', strtotime($perfil['ultimo_acceso'])), ENT_QUOTES, 'UTF-8') : 'Pendiente'; ?>
                    </p>
                </article>
            </section>

            <section class="layout-dos-columnas">
                <article class="tarjeta-panel">
                    <div class="panel-cabecera-premium">
                        <div>
                            <span class="modulo-kicker">Datos personales</span>
                            <h2 class="titulo-tarjeta-panel">Información del perfil</h2>
                        </div>

                        <span class="etiqueta-estado <?php echo clase_estado_usuario_perfil($perfil['estado']); ?>">
                            <?php echo htmlspecialchars($perfil['estado'], ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>

                    <form method="POST" action="perfil.php" class="formulario-panel">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($tokenCsrf, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="accion" value="actualizar_perfil">

                        <div class="grupo-campo">
                            <label for="campoUsuario">Usuario</label>
                            <input
                                type="text"
                                id="campoUsuario"
                                value="<?php echo htmlspecialchars($perfil['usuario'], ENT_QUOTES, 'UTF-8'); ?>"
                                disabled
                            >
                        </div>

                        <div class="grupo-campo">
                            <label for="campoRol">Rol</label>
                            <input
                                type="text"
                                id="campoRol"
                                value="<?php echo htmlspecialchars($perfil['nombre_rol'], ENT_QUOTES, 'UTF-8'); ?>"
                                disabled
                            >
                        </div>

                        <div class="grupo-campo">
                            <label for="campoNombres">Nombres</label>
                            <input
                                type="text"
                                name="nombres"
                                id="campoNombres"
                                value="<?php echo htmlspecialchars($perfil['nombres'], ENT_QUOTES, 'UTF-8'); ?>"
                                required
                            >
                        </div>

                        <div class="grupo-campo">
                            <label for="campoApellidos">Apellidos</label>
                            <input
                                type="text"
                                name="apellidos"
                                id="campoApellidos"
                                value="<?php echo htmlspecialchars($perfil['apellidos'], ENT_QUOTES, 'UTF-8'); ?>"
                                required
                            >
                        </div>

                        <div class="grupo-campo">
                            <label for="campoCorreo">Correo principal</label>
                            <input
                                type="email"
                                name="correo"
                                id="campoCorreo"
                                value="<?php echo htmlspecialchars($perfil['correo'], ENT_QUOTES, 'UTF-8'); ?>"
                                required
                            >
                        </div>

                        <?php if ($perfil['nombre_rol'] === 'Docente'): ?>
                            <div class="grupo-campo">
                                <label for="campoWhatsapp">WhatsApp</label>
                                <input
                                    type="text"
                                    name="whatsapp"
                                    id="campoWhatsapp"
                                    value="<?php echo htmlspecialchars($perfil['whatsapp'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                    placeholder="Ejemplo: 999999999"
                                >
                            </div>

                            <div class="grupo-campo">
                                <label for="campoTituloCargo">Título o cargo</label>
                                <input
                                    type="text"
                                    name="titulo_cargo"
                                    id="campoTituloCargo"
                                    value="<?php echo htmlspecialchars($perfil['titulo_cargo'] ?? 'Docente', ENT_QUOTES, 'UTF-8'); ?>"
                                >
                            </div>
                        <?php endif; ?>

                        <div class="acciones-panel">
                            <button type="submit" class="boton-primario">
                                Guardar cambios
                            </button>
                        </div>
                    </form>
                </article>

                <article class="tarjeta-panel">
                    <div class="panel-cabecera-premium">
                        <div>
                            <span class="modulo-kicker">Seguridad</span>
                            <h2 class="titulo-tarjeta-panel">Cambiar contraseña</h2>
                        </div>
                    </div>

                    <form method="POST" action="perfil.php" class="formulario-panel">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($tokenCsrf, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="accion" value="cambiar_contrasena">

                        <div class="grupo-campo full-line">
                            <label for="campoContrasenaActual">Contraseña actual</label>
                            <input
                                type="password"
                                name="contrasena_actual"
                                id="campoContrasenaActual"
                                required
                            >
                        </div>

                        <div class="grupo-campo">
                            <label for="campoContrasenaNueva">Nueva contraseña</label>
                            <input
                                type="password"
                                name="contrasena_nueva"
                                id="campoContrasenaNueva"
                                minlength="8"
                                required
                            >
                        </div>

                        <div class="grupo-campo">
                            <label for="campoContrasenaConfirmar">Confirmar contraseña</label>
                            <input
                                type="password"
                                name="contrasena_confirmar"
                                id="campoContrasenaConfirmar"
                                minlength="8"
                                required
                            >
                        </div>

                        <div class="perfil-seguridad-nota">
                            <strong>Recomendación:</strong>
                            usa una contraseña con mayúsculas, minúsculas, números y símbolos.
                        </div>

                        <div class="acciones-panel">
                            <button type="submit" class="boton-primario">
                                Actualizar contraseña
                            </button>
                        </div>
                    </form>
                </article>
            </section>

            <section class="layout-dos-columnas">
                <article class="tarjeta-panel">
                    <div class="panel-cabecera-premium">
                        <div>
                            <span class="modulo-kicker">Actividad</span>
                            <h2 class="titulo-tarjeta-panel">Resumen del sistema</h2>
                        </div>
                    </div>

                    <div class="perfil-lista-datos">
                        <div>
                            <span>Registros últimos 30 días</span>
                            <strong><?php echo (int) ($resumenSistema['total_registros'] ?? 0); ?></strong>
                        </div>

                        <div>
                            <span>Puntuales</span>
                            <strong><?php echo (int) ($resumenSistema['puntuales'] ?? 0); ?></strong>
                        </div>

                        <div>
                            <span>Tardanzas</span>
                            <strong><?php echo (int) ($resumenSistema['tardanzas'] ?? 0); ?></strong>
                        </div>

                        <div>
                            <span>Faltas</span>
                            <strong><?php echo (int) ($resumenSistema['faltas'] ?? 0); ?></strong>
                        </div>
                    </div>
                </article>

                <article class="tarjeta-panel">
                    <div class="panel-cabecera-premium">
                        <div>
                            <span class="modulo-kicker">Docencia</span>
                            <h2 class="titulo-tarjeta-panel">Cursos asignados</h2>
                        </div>
                    </div>

                    <?php if ($perfil['nombre_rol'] !== 'Docente'): ?>
                        <div class="texto-sin-datos">
                            Esta sección aplica principalmente para usuarios docentes.
                        </div>
                    <?php elseif (count($cursosDocente) === 0): ?>
                        <div class="texto-sin-datos">
                            No tienes cursos asignados por el momento.
                        </div>
                    <?php else: ?>
                        <div class="perfil-cursos-grid">
                            <?php foreach ($cursosDocente as $curso): ?>
                                <div class="perfil-curso-chip">
                                    <?php echo htmlspecialchars($curso['nombre_curso'], ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>
            </section>

        </main>
    </div>

    <script src="<?php echo app_url('assets/js/main.js?v=9999'); ?>"></script>
</body>
</html>