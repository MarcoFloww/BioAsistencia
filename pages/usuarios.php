<?php

date_default_timezone_set('America/Lima');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/funciones.php';

requerir_rol(['Administrador']);

$rol = obtener_rol_usuario();
$nombreUsuario = obtener_nombre_usuario();
$estadoDispositivo = obtener_estado_dispositivo($pdo);
$claseEstadoDispositivo = clase_estado_dispositivo($estadoDispositivo);
$mensajeExito = '';
$mensajeError = '';
$usuarioActual = usuario_actual();
$idUsuarioActual = (int) ($usuarioActual['id_usuario'] ?? $_SESSION['id_usuario'] ?? 0);

function obtener_iniciales_usuario(string $nombres, string $apellidos): string
{
    $primeraLetraNombre = mb_substr(trim($nombres), 0, 1, 'UTF-8');
    $primeraLetraApellido = mb_substr(trim($apellidos), 0, 1, 'UTF-8');

    return mb_strtoupper($primeraLetraNombre . $primeraLetraApellido, 'UTF-8');
}

function clase_estado_usuario(string $estado): string
{
    return $estado === 'Activo' ? 'estado-puntual' : 'estado-falto';
}

function clase_rol_usuario(string $rolUsuario): string
{
    $rolUsuario = strtolower($rolUsuario);

    if (str_contains($rolUsuario, 'admin')) {
        return 'chip-admin';
    }

    if (str_contains($rolUsuario, 'docente')) {
        return 'chip-docente';
    }

    return 'chip-neutro';
}

function normalizar_usuario(string $usuario): string
{
    $usuario = trim($usuario);
    $usuario = preg_replace('/\s+/', '', $usuario);

    return strtolower($usuario);
}

$tokenCsrf = generar_token_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requerir_token_csrf($_POST['csrf_token'] ?? null);

    $accion = $_POST['accion'] ?? '';

    try {
        if ($accion === 'guardar') {
            $idUsuario = (int) ($_POST['id_usuario'] ?? 0);
            $idRol = (int) ($_POST['id_rol'] ?? 0);
            $usuario = normalizar_usuario($_POST['usuario'] ?? '');
            $nombres = limpiar_texto($_POST['nombres'] ?? '');
            $apellidos = limpiar_texto($_POST['apellidos'] ?? '');
            $correo = limpiar_texto($_POST['correo'] ?? '');
            $contrasena = $_POST['contrasena'] ?? '';
            $estado = limpiar_texto($_POST['estado'] ?? 'Activo');

            if ($idRol <= 0 || $usuario === '' || $nombres === '' || $apellidos === '' || $correo === '') {
                $mensajeError = 'Completa todos los campos obligatorios.';
            } elseif (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                $mensajeError = 'El correo no es válido.';
            } elseif (!in_array($estado, ['Activo', 'Inactivo'], true)) {
                $mensajeError = 'El estado no es válido.';
            } elseif ($idUsuario === 0 && $contrasena === '') {
                $mensajeError = 'La contraseña es obligatoria para registrar un usuario nuevo.';
            } elseif ($contrasena !== '' && strlen($contrasena) < 8) {
                $mensajeError = 'La contraseña debe tener como mínimo 8 caracteres.';
            } elseif (strlen($usuario) < 4) {
                $mensajeError = 'El usuario debe tener como mínimo 4 caracteres.';
            } else {
$validarRol = $pdo->prepare(
    "SELECT id_rol, nombre_rol
     FROM roles
     WHERE id_rol = :id_rol
     LIMIT 1"
);

$validarRol->execute([
    'id_rol' => $idRol
]);

$rolSeleccionado = $validarRol->fetch();

if (!$rolSeleccionado) {
    throw new Exception('El rol seleccionado no existe.');
}

$nombreRolSeleccionado = $rolSeleccionado['nombre_rol'];

if ($nombreRolSeleccionado === 'Docente') {
    $consultaFichaDocente = $pdo->prepare(
        "SELECT id_docente
         FROM docentes
         WHERE id_usuario = :id_usuario
         LIMIT 1"
    );

    $consultaFichaDocente->execute([
        'id_usuario' => $idUsuario
    ]);

    if ($idUsuario === 0 || !$consultaFichaDocente->fetch()) {
        throw new Exception('Los usuarios docentes deben registrarse desde el módulo Docentes para guardar también su código y DNI.');
    }
}

if ($idUsuario === $idUsuarioActual && $nombreRolSeleccionado !== 'Administrador') {
    throw new Exception('No puedes quitarte tu propio rol de Administrador.');
}
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

                $validarCorreo = $pdo->prepare(
                    "SELECT id_usuario
                     FROM usuarios
                     WHERE correo = :correo
                     AND id_usuario <> :id_usuario
                     LIMIT 1"
                );

                $validarCorreo->execute([
                    'correo' => $correo,
                    'id_usuario' => $idUsuario
                ]);

                if ($validarCorreo->fetch()) {
                    throw new Exception('Ese correo ya está registrado en otro usuario.');
                }

                if ($idUsuario > 0) {
                    if ($idUsuario === $idUsuarioActual && $estado === 'Inactivo') {
                        throw new Exception('No puedes desactivar tu propio usuario mientras estás usando el sistema.');
                    }

                    if ($contrasena !== '') {
                        $hash = password_hash($contrasena, PASSWORD_BCRYPT);

                        $consulta = $pdo->prepare(
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

                        $consulta->execute([
                            'id_rol' => $idRol,
                            'usuario' => $usuario,
                            'contrasena' => $hash,
                            'nombres' => $nombres,
                            'apellidos' => $apellidos,
                            'correo' => $correo,
                            'estado' => $estado,
                            'id_usuario' => $idUsuario
                        ]);
                    } else {
                        $consulta = $pdo->prepare(
                            "UPDATE usuarios
                             SET id_rol = :id_rol,
                                 usuario = :usuario,
                                 nombres = :nombres,
                                 apellidos = :apellidos,
                                 correo = :correo,
                                 estado = :estado
                             WHERE id_usuario = :id_usuario"
                        );

                        $consulta->execute([
                            'id_rol' => $idRol,
                            'usuario' => $usuario,
                            'nombres' => $nombres,
                            'apellidos' => $apellidos,
                            'correo' => $correo,
                            'estado' => $estado,
                            'id_usuario' => $idUsuario
                        ]);
                    }

                    $mensajeExito = 'Usuario actualizado correctamente.';
                } else {
                    $hash = password_hash($contrasena, PASSWORD_BCRYPT);

                    $consulta = $pdo->prepare(
                        "INSERT INTO usuarios
                         (id_rol, usuario, contrasena, nombres, apellidos, correo, estado)
                         VALUES
                         (:id_rol, :usuario, :contrasena, :nombres, :apellidos, :correo, :estado)"
                    );

                    $consulta->execute([
                        'id_rol' => $idRol,
                        'usuario' => $usuario,
                        'contrasena' => $hash,
                        'nombres' => $nombres,
                        'apellidos' => $apellidos,
                        'correo' => $correo,
                        'estado' => $estado
                    ]);

                    $mensajeExito = 'Usuario registrado correctamente.';
                }
            }
        }

        if ($accion === 'cambiar_estado') {
            $idUsuario = (int) ($_POST['id_usuario'] ?? 0);
            $nuevoEstado = limpiar_texto($_POST['nuevo_estado'] ?? '');

            if ($idUsuario <= 0 || !in_array($nuevoEstado, ['Activo', 'Inactivo'], true)) {
                $mensajeError = 'No se pudo cambiar el estado del usuario.';
            } elseif ($idUsuario === $idUsuarioActual && $nuevoEstado === 'Inactivo') {
                $mensajeError = 'No puedes desactivar tu propio usuario.';
            } else {
                $consulta = $pdo->prepare(
                    "UPDATE usuarios
                     SET estado = :estado
                     WHERE id_usuario = :id_usuario"
                );

                $consulta->execute([
                    'estado' => $nuevoEstado,
                    'id_usuario' => $idUsuario
                ]);

                $sincronizarDocente = $pdo->prepare(
                    "UPDATE docentes
                     SET estado = :estado
                     WHERE id_usuario = :id_usuario"
                );

                $sincronizarDocente->execute([
                    'estado' => $nuevoEstado,
                    'id_usuario' => $idUsuario
                ]);

                $mensajeExito = $nuevoEstado === 'Activo'
                    ? 'Usuario activado correctamente.'
                    : 'Usuario desactivado correctamente.';
            }
        }
    } catch (Throwable $e) {
        $mensajeError = $e->getMessage() !== '' ? $e->getMessage() : 'No se pudo completar la operación.';
    }
}

$busqueda = limpiar_texto($_GET['buscar'] ?? '');
$filtroRol = (int) ($_GET['rol'] ?? 0);
$filtroEstado = limpiar_texto($_GET['estado'] ?? '');
$parametros = [];
$sqlCondiciones = 'WHERE 1 = 1';

if ($busqueda !== '') {
    $sqlCondiciones .= " AND (
        u.usuario LIKE :buscar
        OR u.nombres LIKE :buscar
        OR u.apellidos LIKE :buscar
        OR u.correo LIKE :buscar
        OR r.nombre_rol LIKE :buscar
    )";

    $parametros['buscar'] = '%' . $busqueda . '%';
}

if ($filtroRol > 0) {
    $sqlCondiciones .= ' AND u.id_rol = :id_rol';
    $parametros['id_rol'] = $filtroRol;
}

if ($filtroEstado !== '' && in_array($filtroEstado, ['Activo', 'Inactivo'], true)) {
    $sqlCondiciones .= ' AND u.estado = :estado';
    $parametros['estado'] = $filtroEstado;
}

$consultaRoles = $pdo->query(
    "SELECT id_rol, nombre_rol
     FROM roles
     ORDER BY nombre_rol ASC"
);

$listaRoles = $consultaRoles->fetchAll();

$consultaUsuarios = $pdo->prepare(
    "SELECT u.id_usuario,
            u.id_rol,
            u.usuario,
            u.nombres,
            u.apellidos,
            u.correo,
            u.estado,
            u.fecha_creacion,
            u.ultimo_acceso,
            r.nombre_rol
     FROM usuarios u
     INNER JOIN roles r ON u.id_rol = r.id_rol
     $sqlCondiciones
     ORDER BY u.estado ASC, r.nombre_rol ASC, u.apellidos ASC, u.nombres ASC"
);

$consultaUsuarios->execute($parametros);
$listaUsuarios = $consultaUsuarios->fetchAll();

$totalUsuarios = count($listaUsuarios);
$usuariosActivos = 0;
$usuariosInactivos = 0;
$usuariosAdministradores = 0;
$usuariosDocentes = 0;

foreach ($listaUsuarios as $usuarioFila) {
    if ($usuarioFila['estado'] === 'Activo') {
        $usuariosActivos++;
    } else {
        $usuariosInactivos++;
    }

    if ($usuarioFila['nombre_rol'] === 'Administrador') {
        $usuariosAdministradores++;
    }

    if ($usuarioFila['nombre_rol'] === 'Docente') {
        $usuariosDocentes++;
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

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BioAsistencia - Usuarios</title>
    <link rel="icon" type="image/png" href="<?php echo app_url('assets/img/logo.png?v=1'); ?>">
    <link rel="shortcut icon" type="image/png" href="<?php echo app_url('assets/img/logo.png?v=1'); ?>">
    <link rel="stylesheet" href="<?php echo app_url('assets/css/styles.css?v=9999'); ?>">
</head>
<body class="pagina-interna pagina-usuarios">

   <?php
   $menuActual = 'usuarios';
   require __DIR__ . '/../includes/sidebar.php';
   ?>

    <div class="contenido-principal">
        <header class="barra-superior">
            <div>
                <span class="subtitulo-pagina">Seguridad y accesos</span>
                <h1 class="titulo-pagina">Usuarios del sistema</h1>
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

            <section class="modulo-hero modulo-hero-usuarios">
                <div class="modulo-hero-contenido">
                    <span class="modulo-etiqueta">Gestión de seguridad</span>
                    <h2>Control de usuarios, roles y accesos</h2>
                    <p>
                        Administra las cuentas que pueden ingresar a BioAsistencia. Desde este módulo puedes crear usuarios,
                        cambiar contraseñas, actualizar roles y activar o desactivar accesos.
                    </p>
                </div>

                <div class="modulo-hero-panel">
                    <span class="hero-panel-label">Usuarios activos</span>
                    <strong><?php echo (int) $usuariosActivos; ?></strong>
                    <small><?php echo (int) $totalUsuarios; ?> cuentas encontradas</small>
                </div>
            </section>

            <section class="resumen-premium">
                <article class="tarjeta-estadistica tarjeta-azul">
                    <span class="etiqueta-estadistica">Total de usuarios</span>
                    <strong><?php echo (int) $totalUsuarios; ?></strong>
                    <small>Cuentas registradas</small>
                </article>

                <article class="tarjeta-estadistica tarjeta-verde">
                    <span class="etiqueta-estadistica">Activos</span>
                    <strong><?php echo (int) $usuariosActivos; ?></strong>
                    <small>Acceso permitido</small>
                </article>

                <article class="tarjeta-estadistica tarjeta-naranja">
                    <span class="etiqueta-estadistica">Administradores</span>
                    <strong><?php echo (int) $usuariosAdministradores; ?></strong>
                    <small>Acceso completo</small>
                </article>

                <article class="tarjeta-estadistica tarjeta-celeste">
                    <span class="etiqueta-estadistica">Docentes</span>
                    <strong><?php echo (int) $usuariosDocentes; ?></strong>
                    <small>Acceso académico</small>
                </article>
            </section>

            <section class="tarjeta-panel tarjeta-panel-premium">
                <div class="barra-filtros barra-filtros-premium">
                    <form method="GET" action="usuarios.php" class="formulario-busqueda formulario-busqueda-premium">
                        <input
                            type="text"
                            name="buscar"
                            placeholder="Buscar por usuario, nombre, correo o rol"
                            value="<?php echo htmlspecialchars($busqueda, ENT_QUOTES, 'UTF-8'); ?>"
                        >

                        <select name="rol">
                            <option value="0">Todos los roles</option>
                            <?php foreach ($listaRoles as $rolFila): ?>
                                <option value="<?php echo (int) $rolFila['id_rol']; ?>" <?php echo $filtroRol === (int) $rolFila['id_rol'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($rolFila['nombre_rol'], ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <select name="estado">
                            <option value="">Todos los estados</option>
                            <option value="Activo" <?php echo $filtroEstado === 'Activo' ? 'selected' : ''; ?>>Activo</option>
                            <option value="Inactivo" <?php echo $filtroEstado === 'Inactivo' ? 'selected' : ''; ?>>Inactivo</option>
                        </select>

                        <button type="submit" class="boton-secundario">Buscar</button>

                        <?php if ($busqueda !== '' || $filtroRol > 0 || $filtroEstado !== ''): ?>
                            <a href="usuarios.php" class="boton-secundario">Limpiar</a>
                        <?php endif; ?>
                    </form>

                    <button type="button" class="boton-primario" onclick="abrirModalUsuario()">
                        Registrar nuevo usuario
                    </button>
                </div>

                <div class="tabla-contenedor-premium">
                    <table class="tabla-datos tabla-premium" id="tablaUsuarios">
                        <thead>
                            <tr>
                                <th>Usuario</th>
                                <th>Nombre completo</th>
                                <th>Rol</th>
                                <th>Correo</th>
                                <th>Último acceso</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (count($listaUsuarios) === 0): ?>
                                <tr>
                                    <td colspan="7" class="texto-sin-datos">
                                        No hay usuarios registrados con los filtros aplicados
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($listaUsuarios as $usuarioFila): ?>
                                    <?php
                                    $datosUsuario = htmlspecialchars(
                                        json_encode($usuarioFila, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    );
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="celda-usuario-premium">
                                                <div class="avatar-tabla avatar-usuario">
                                                    <?php echo htmlspecialchars(obtener_iniciales_usuario($usuarioFila['nombres'], $usuarioFila['apellidos']), ENT_QUOTES, 'UTF-8'); ?>
                                                </div>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($usuarioFila['usuario'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                                    <span>ID <?php echo (int) $usuarioFila['id_usuario']; ?></span>
                                                </div>
                                            </div>
                                        </td>

                                        <td>
                                            <?php echo htmlspecialchars($usuarioFila['nombres'] . ' ' . $usuarioFila['apellidos'], ENT_QUOTES, 'UTF-8'); ?>
                                        </td>

                                        <td>
                                            <span class="badge-premium <?php echo clase_rol_usuario($usuarioFila['nombre_rol']); ?>">
                                                <?php echo htmlspecialchars($usuarioFila['nombre_rol'], ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?php echo htmlspecialchars($usuarioFila['correo'], ENT_QUOTES, 'UTF-8'); ?>
                                        </td>

                                        <td>
                                            <?php echo $usuarioFila['ultimo_acceso'] ? htmlspecialchars($usuarioFila['ultimo_acceso'], ENT_QUOTES, 'UTF-8') : 'Sin acceso registrado'; ?>
                                        </td>

                                        <td>
                                            <span class="etiqueta-estado <?php echo clase_estado_usuario($usuarioFila['estado']); ?>">
                                                <?php echo htmlspecialchars($usuarioFila['estado'], ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </td>

                                        <td>
                                            <div class="acciones-tabla">
                                                <button type="button" class="boton-secundario" onclick="abrirModalUsuario(<?php echo $datosUsuario; ?>)">
                                                    Editar
                                                </button>

                                                <form method="POST" action="usuarios.php" style="display:inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($tokenCsrf, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="accion" value="cambiar_estado">
                                                    <input type="hidden" name="id_usuario" value="<?php echo (int) $usuarioFila['id_usuario']; ?>">
                                                    <input type="hidden" name="nuevo_estado" value="<?php echo $usuarioFila['estado'] === 'Activo' ? 'Inactivo' : 'Activo'; ?>">

                                                    <button
                                                        type="submit"
                                                        class="<?php echo $usuarioFila['estado'] === 'Activo' ? 'boton-peligro' : 'boton-secundario'; ?>"
                                                        data-confirmar="¿Deseas <?php echo $usuarioFila['estado'] === 'Activo' ? 'desactivar' : 'activar'; ?> este usuario?"
                                                    >
                                                        <?php echo $usuarioFila['estado'] === 'Activo' ? 'Desactivar' : 'Activar'; ?>
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

    <div class="fondo-modal" id="fondoModalUsuario" style="display:none;">
        <div class="tarjeta-panel modal-formulario modal-formulario-premium">
            <div class="modal-cabecera-premium">
                <span class="modulo-etiqueta">Control de acceso</span>
                <h2 class="titulo-tarjeta-panel" id="tituloModalUsuario">Registrar nuevo usuario</h2>
                <p>Completa los datos del usuario y define el rol que tendrá dentro del sistema.</p>
            </div>

            <form method="POST" action="usuarios.php" class="formulario-panel formulario-panel-premium">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($tokenCsrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="accion" value="guardar">
                <input type="hidden" name="id_usuario" id="campoIdUsuario" value="">

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
                    <label for="campoCorreo">Correo</label>
                    <input type="email" name="correo" id="campoCorreo" required>
                </div>

                <div class="grupo-campo">
                    <label for="campoRol">Rol</label>
                    <select name="id_rol" id="campoRol" required>
                        <option value="">Seleccionar rol</option>
                        <?php foreach ($listaRoles as $rolFila): ?>
                            <option value="<?php echo (int) $rolFila['id_rol']; ?>">
                                <?php echo htmlspecialchars($rolFila['nombre_rol'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="grupo-campo">
                    <label for="campoEstado">Estado</label>
                    <select name="estado" id="campoEstado">
                        <option value="Activo">Activo</option>
                        <option value="Inactivo">Inactivo</option>
                    </select>
                </div>

                <div class="grupo-campo full-line">
                    <label for="campoContrasena">Contraseña</label>
                    <input type="password" name="contrasena" id="campoContrasena">
                    <small class="texto-ayuda-formulario" id="ayudaContrasena">
                        Para usuarios nuevos la contraseña es obligatoria.
                    </small>
                </div>

                <div class="acciones-panel acciones-panel-premium">
                    <button type="button" class="boton-secundario" onclick="cerrarModalUsuario()">
                        Cancelar
                    </button>
                    <button type="submit" class="boton-primario">
                        Guardar usuario
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="<?php echo app_url('assets/js/main.js?v=9999'); ?>"></script>
    <script>
        function abrirModalUsuario(datos) {
            const titulo = document.getElementById('tituloModalUsuario');
            const ayudaContrasena = document.getElementById('ayudaContrasena');
            const campoContrasena = document.getElementById('campoContrasena');

            titulo.textContent = datos ? 'Editar usuario' : 'Registrar nuevo usuario';
            ayudaContrasena.textContent = datos
                ? 'Deja este campo vacío si no deseas cambiar la contraseña.'
                : 'Para usuarios nuevos la contraseña es obligatoria.';

            document.getElementById('campoIdUsuario').value = datos ? datos.id_usuario : '';
            document.getElementById('campoNombres').value = datos ? datos.nombres : '';
            document.getElementById('campoApellidos').value = datos ? datos.apellidos : '';
            document.getElementById('campoUsuario').value = datos ? datos.usuario : '';
            document.getElementById('campoCorreo').value = datos ? datos.correo : '';
            document.getElementById('campoRol').value = datos ? datos.id_rol : '';
            document.getElementById('campoEstado').value = datos ? datos.estado : 'Activo';
            campoContrasena.value = '';
            campoContrasena.required = !datos;

            document.getElementById('fondoModalUsuario').style.display = 'flex';
        }

        function cerrarModalUsuario() {
            document.getElementById('fondoModalUsuario').style.display = 'none';
        }
    </script>
</body>
</html>