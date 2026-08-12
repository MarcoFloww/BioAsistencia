<?php

date_default_timezone_set('America/Lima');

require_once __DIR__ . '/includes/auth.php';

if (usuario_autenticado()) {
    if (validar_sesion_actual($pdo)) {
        header('Location: ' . app_url('pages/dashboard.php'));
        exit;
    }
}

if (
    !isset($_SESSION['csrf_login'])
    || !is_string($_SESSION['csrf_login'])
    || strlen($_SESSION['csrf_login']) < 64
) {
    $_SESSION['csrf_login'] = bin2hex(random_bytes(32));
}

$mensajeError = '';
$usuarioIngresado = $_COOKIE['bioasistencia_usuario'] ?? '';
$recordarMarcado = $usuarioIngresado !== '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuarioIngresado = trim($_POST['usuario'] ?? '');
    $contrasenaIngresada = (string) ($_POST['contrasena'] ?? '');
    $recordarMarcado = isset($_POST['recordar']);
    $tokenRecibido = (string) ($_POST['csrf_token'] ?? '');

    if (
        $tokenRecibido === ''
        || !hash_equals($_SESSION['csrf_login'], $tokenRecibido)
    ) {
        $mensajeError = 'La sesión del formulario expiró. Recarga la página e inténtalo nuevamente.';
        $_SESSION['csrf_login'] = bin2hex(random_bytes(32));
    } elseif ($usuarioIngresado === '' || $contrasenaIngresada === '') {
        $mensajeError = 'Ingresa tu usuario y contraseña para continuar.';
    } else {
        try {
            if (iniciar_sesion($pdo, $usuarioIngresado, $contrasenaIngresada, $recordarMarcado)) {
                $_SESSION['csrf_login'] = bin2hex(random_bytes(32));

                header('Location: ' . app_url('pages/dashboard.php'));
                exit;
            }

            $mensajeError = 'Usuario o contraseña incorrectos.';
        } catch (Throwable $e) {
            $mensajeError = 'No se pudo iniciar sesión. Inténtalo nuevamente.';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BioAsistencia - Iniciar Sesión</title>
    <link rel="icon" type="image/png" href="<?php echo app_url('assets/img/logo.png?v=1'); ?>">
    <link rel="shortcut icon" type="image/png" href="<?php echo app_url('assets/img/logo.png?v=1'); ?>">
    <link rel="stylesheet" href="<?php echo app_url('assets/css/styles.css?v=9999'); ?>">
</head>
<body class="pagina-login login-premium">

    <div class="login-fondo-tecnologico" aria-hidden="true">
        <span class="login-luz login-luz-uno"></span>
        <span class="login-luz login-luz-dos"></span>
        <span class="login-luz login-luz-tres"></span>
        <span class="login-grid"></span>
        <span class="login-linea login-linea-uno"></span>
        <span class="login-linea login-linea-dos"></span>
        <span class="login-linea login-linea-tres"></span>
        <span class="login-circulo login-circulo-uno"></span>
        <span class="login-circulo login-circulo-dos"></span>
        <span class="login-circulo login-circulo-tres"></span>
    </div>

    <main class="login-layout">

        <section class="login-hero">
            <div class="login-hero-contenido">

                <div class="login-marca-principal">
                    <div class="login-logo-marco">
                        <img src="<?php echo app_url('assets/img/logo.png'); ?>" alt="BioAsistencia" class="login-logo">
                    </div>

                    <div class="login-marca-texto">
                        <h1>BioAsistencia</h1>
                    </div>
                </div>

                <p>
                    Plataforma diseñada para registrar, consultar y supervisar la asistencia de estudiantes
                    con una experiencia moderna, segura y conectada.
                </p>

                <div class="login-metricas login-metricas-horizontal">
                    <div class="login-metrica login-metrica-celeste">
                        <strong>BioID</strong>
                        <span>Identificación biométrica</span>
                    </div>
                </div>

            </div>
        </section>

        <section class="login-panel">
            <div class="login-tarjeta-premium">

                <div class="login-acceso-superior">
                    <div class="login-logo-secundario">
                        <img src="<?php echo app_url('assets/img/logo.png'); ?>" alt="BioAsistencia" class="login-logo-mini">
                    </div>

                    <span class="login-chip-seguro">
                        <span></span>
                        Acceso seguro
                    </span>

                    <h2>Iniciar Sesión</h2>

                    <p>
                        Ingresa tus credenciales para continuar en BioAsistencia.
                    </p>
                </div>

                <?php if ($mensajeError !== ''): ?>
                    <div class="login-alerta-error" role="alert">
                        <svg viewBox="0 0 24 24" width="13" height="13" aria-hidden="true" style="width:13px;height:13px;max-width:13px;max-height:13px;fill:currentColor;flex:0 0 13px;display:inline-block;">
                            <path d="M12 2 1 21h22L12 2Zm1 15h-2v-2h2v2Zm0-4h-2V8h2v5Z"/>
                        </svg>
                        <span><?php echo htmlspecialchars($mensajeError, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" action="<?php echo app_url('login.php'); ?>" class="login-formulario" autocomplete="off">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?php echo htmlspecialchars($_SESSION['csrf_login'], ENT_QUOTES, 'UTF-8'); ?>"
                    >

                    <div class="login-campo">
                        <label for="usuario">Usuario</label>

                        <div class="login-control">
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M12 12a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9Zm0 2c-4.42 0-8 2.24-8 5v1a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-1c0-2.76-3.58-5-8-5Z"/>
                            </svg>

                            <input
                                type="text"
                                id="usuario"
                                name="usuario"
                                value="<?php echo htmlspecialchars($usuarioIngresado, ENT_QUOTES, 'UTF-8'); ?>"
                                placeholder="Ingresa tu usuario"
                                autocomplete="username"
                                maxlength="60"
                                required
                                autofocus
                            >
                        </div>
                    </div>

                    <div class="login-campo">
                        <label for="contrasena">Contraseña</label>

                        <div class="login-control login-control-password">
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M17 9h-1V7a4 4 0 0 0-8 0v2H7a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2ZM10 7a2 2 0 0 1 4 0v2h-4V7Zm3 8.73V18h-2v-2.27a2 2 0 1 1 2 0Z"/>
                            </svg>

                            <input
                                type="password"
                                id="contrasena"
                                name="contrasena"
                                placeholder="Ingresa tu contraseña"
                                autocomplete="current-password"
                                required
                            >

                            <button type="button" class="login-boton-ver" data-toggle-password="contrasena">
                                Ver
                            </button>
                        </div>
                    </div>

                    <div class="login-opciones">
                        <label class="login-check">
                            <input type="checkbox" name="recordar" <?php echo $recordarMarcado ? 'checked' : ''; ?>>
                            <span></span>
                            Recordar usuario
                        </label>

                        <span class="login-enlace" aria-disabled="true">
                            Recuperación administrada por el sistema
                        </span>
                    </div>

                    <button type="submit" class="login-boton-principal">
                        <span>Iniciar Sesión</span>

                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M13.17 12 8.22 7.05l1.41-1.41L16 12l-6.37 6.36-1.41-1.41L13.17 12Z"/>
                        </svg>
                    </button>

                </form>

                <div class="login-acceso-pie">
                    <span id="loginFecha">BioAsistencia</span>
                    <span id="loginHora">Sistema activo</span>
                </div>
            </div>
        </section>

    </main>

    <script>
        (function () {
            const botonesPassword = document.querySelectorAll('[data-toggle-password]');

            botonesPassword.forEach(function (boton) {
                boton.addEventListener('click', function () {
                    const idCampo = boton.getAttribute('data-toggle-password');
                    const campo = document.getElementById(idCampo);

                    if (!campo) {
                        return;
                    }

                    const visible = campo.type === 'text';

                    campo.type = visible ? 'password' : 'text';
                    boton.textContent = visible ? 'Ver' : 'Ocultar';
                });
            });

            const fecha = document.getElementById('loginFecha');
            const hora = document.getElementById('loginHora');

            function actualizarTiempoLogin() {
                const ahora = new Date();

                if (fecha) {
                    fecha.textContent = ahora.toLocaleDateString('es-PE', {
                        weekday: 'long',
                        day: '2-digit',
                        month: 'long',
                        year: 'numeric'
                    });
                }

                if (hora) {
                    hora.textContent = ahora.toLocaleTimeString('es-PE', {
                        hour: '2-digit',
                        minute: '2-digit',
                        second: '2-digit'
                    });
                }
            }

            actualizarTiempoLogin();
            setInterval(actualizarTiempoLogin, 1000);
        })();
    </script>

</body>
</html>