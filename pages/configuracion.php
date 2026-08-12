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

function tabla_existe(PDO $pdo, string $tabla): bool
{
    $consulta = $pdo->prepare(
        "SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = :tabla"
    );

    $consulta->execute([
        'tabla' => $tabla
    ]);

    return (int) $consulta->fetchColumn() > 0;
}

function columna_existe(PDO $pdo, string $tabla, string $columna): bool
{
    $consulta = $pdo->prepare(
        "SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = :tabla
         AND COLUMN_NAME = :columna"
    );

    $consulta->execute([
        'tabla' => $tabla,
        'columna' => $columna
    ]);

    return (int) $consulta->fetchColumn() > 0;
}

function asegurar_tablas_configuracion(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS configuracion (
            id_configuracion INT AUTO_INCREMENT PRIMARY KEY,
            clave VARCHAR(100) NOT NULL UNIQUE,
            valor TEXT NULL,
            descripcion VARCHAR(255) NULL,
            fecha_actualizacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS estado_dispositivo (
            id_estado INT AUTO_INCREMENT PRIMARY KEY,
            estado_biometrico VARCHAR(50) NOT NULL DEFAULT 'Estado Apagado',
            estado_sensor VARCHAR(50) NOT NULL DEFAULT 'Apagado',
            estado_wifi VARCHAR(50) NOT NULL DEFAULT 'Desconectado',
            mensaje VARCHAR(255) NULL,
            fecha_actualizacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function obtener_configuracion(PDO $pdo, string $clave, string $valorDefecto = ''): string
{
    $consulta = $pdo->prepare(
        "SELECT valor
         FROM configuracion
         WHERE clave = :clave
         LIMIT 1"
    );

    $consulta->execute([
        'clave' => $clave
    ]);

    $fila = $consulta->fetch();

    if (!$fila) {
        return $valorDefecto;
    }

    return (string) $fila['valor'];
}

function guardar_configuracion(PDO $pdo, string $clave, string $valor, string $descripcion = ''): void
{
    $consulta = $pdo->prepare(
        "INSERT INTO configuracion (clave, valor, descripcion)
         VALUES (:clave, :valor, :descripcion)
         ON DUPLICATE KEY UPDATE
            valor = VALUES(valor),
            descripcion = VALUES(descripcion),
            fecha_actualizacion = NOW()"
    );

    $consulta->execute([
        'clave' => $clave,
        'valor' => $valor,
        'descripcion' => $descripcion
    ]);
}

function obtener_estado_dispositivo_configuracion(PDO $pdo): array
{
    $consulta = $pdo->query(
        "SELECT id_estado,
                estado_biometrico,
                estado_sensor,
                estado_wifi,
                mensaje,
                fecha_actualizacion
         FROM estado_dispositivo
         ORDER BY id_estado DESC
         LIMIT 1"
    );

    $estado = $consulta->fetch();

    if ($estado) {
        return $estado;
    }

    $pdo->exec(
        "INSERT INTO estado_dispositivo
         (estado_biometrico, estado_sensor, estado_wifi, mensaje)
         VALUES
         ('Estado Apagado', 'Apagado', 'Desconectado', 'Dispositivo biométrico sin actividad')"
    );

    $consulta = $pdo->query(
        "SELECT id_estado,
                estado_biometrico,
                estado_sensor,
                estado_wifi,
                mensaje,
                fecha_actualizacion
         FROM estado_dispositivo
         ORDER BY id_estado DESC
         LIMIT 1"
    );

    return $consulta->fetch();
}

function hora_configuracion_valida(string $hora): bool
{
    return preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $hora) === 1;
}

function numero_entero_rango(string $valor, int $minimo, int $maximo): bool
{
    if (!preg_match('/^[0-9]+$/', $valor)) {
        return false;
    }

    $numero = (int) $valor;

    return $numero >= $minimo && $numero <= $maximo;
}

function texto_seguro_configuracion(string $valor): string
{
    return trim(strip_tags($valor));
}

$tokenCsrf = generar_token_csrf();

try {
    asegurar_tablas_configuracion($pdo);

    guardar_configuracion($pdo, 'hora_inicio_sistema', obtener_configuracion($pdo, 'hora_inicio_sistema', '13:00'), 'Hora desde la cual el sistema biométrico permite marcaciones.');
    guardar_configuracion($pdo, 'hora_entrada_oficial', obtener_configuracion($pdo, 'hora_entrada_oficial', '14:00'), 'Hora oficial de entrada para calcular puntualidad o tardanza.');
    guardar_configuracion($pdo, 'hora_salida_oficial', obtener_configuracion($pdo, 'hora_salida_oficial', '19:00'), 'Hora oficial de salida de la jornada académica.');
    guardar_configuracion($pdo, 'tolerancia_minutos', obtener_configuracion($pdo, 'tolerancia_minutos', '15'), 'Minutos de tolerancia antes de considerar tardanza.');
    guardar_configuracion($pdo, 'intervalo_minimo_marcaciones', obtener_configuracion($pdo, 'intervalo_minimo_marcaciones', '30'), 'Minutos mínimos que deben pasar entre la primera y segunda marcación.');
    guardar_configuracion($pdo, 'tolerancia_salida_minutos', obtener_configuracion($pdo, 'tolerancia_salida_minutos', '15'), 'Minutos adicionales permitidos para registrar la salida.');
    guardar_configuracion($pdo, 'porcentaje_alerta', obtener_configuracion($pdo, 'porcentaje_alerta', '30'), 'Porcentaje de inasistencias para activar alerta crítica.');
    guardar_configuracion($pdo, 'nombre_institucion', obtener_configuracion($pdo, 'nombre_institucion', 'IESTP Ciro Alegría Bazán'), 'Nombre de la institución que se muestra en el sistema.');
    guardar_configuracion($pdo, 'programa_estudios_default', obtener_configuracion($pdo, 'programa_estudios_default', 'Computación e Informática'), 'Programa de estudios usado como valor predeterminado.');
    guardar_configuracion($pdo, 'ciclo_default', obtener_configuracion($pdo, 'ciclo_default', 'V'), 'Ciclo académico usado como valor predeterminado.');
    guardar_configuracion($pdo, 'modo_notificacion', obtener_configuracion($pdo, 'modo_notificacion', 'API'), 'Modo de envío de alertas mediante API real.');
} catch (Throwable $e) {
    $mensajeError = 'No se pudo preparar la configuración inicial del sistema.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requerir_token_csrf($_POST['csrf_token'] ?? null);

    $accion = $_POST['accion'] ?? '';

    try {
        if ($accion === 'guardar_reglas') {
            $horaInicio = texto_seguro_configuracion($_POST['hora_inicio_sistema'] ?? '');
            $horaEntrada = texto_seguro_configuracion($_POST['hora_entrada_oficial'] ?? '');
            $horaSalida = texto_seguro_configuracion($_POST['hora_salida_oficial'] ?? '');
            $tolerancia = texto_seguro_configuracion($_POST['tolerancia_minutos'] ?? '15');
            $intervaloMinimoMarcaciones = texto_seguro_configuracion($_POST['intervalo_minimo_marcaciones'] ?? '30');
            $toleranciaSalida = texto_seguro_configuracion($_POST['tolerancia_salida_minutos'] ?? '15');
            $porcentajeAlerta = texto_seguro_configuracion($_POST['porcentaje_alerta'] ?? '30');

            if (!hora_configuracion_valida($horaInicio)) {
                $mensajeError = 'La hora de activación no es válida.';
            } elseif (!hora_configuracion_valida($horaEntrada)) {
                $mensajeError = 'La hora oficial de entrada no es válida.';
            } elseif (!hora_configuracion_valida($horaSalida)) {
                $mensajeError = 'La hora oficial de salida no es válida.';
            } elseif ($horaInicio >= $horaEntrada) {
                $mensajeError = 'La hora de activación debe ser menor que la hora oficial de entrada.';
            } elseif ($horaEntrada >= $horaSalida) {
                $mensajeError = 'La hora de entrada debe ser menor que la hora de salida.';
            } elseif (!numero_entero_rango($tolerancia, 0, 60)) {
                $mensajeError = 'La tolerancia de entrada debe estar entre 0 y 60 minutos.';
            } elseif (!numero_entero_rango($intervaloMinimoMarcaciones, 1, 180)) {
    $mensajeError = 'El intervalo mínimo entre marcaciones debe estar entre 1 y 180 minutos.';
                } elseif (!numero_entero_rango($toleranciaSalida, 0, 60)) {
                $mensajeError = 'La tolerancia de salida debe estar entre 0 y 60 minutos.';
            } elseif (!numero_entero_rango($porcentajeAlerta, 1, 100)) {
                $mensajeError = 'El porcentaje de alerta debe estar entre 1 y 100.';
            } else {
                guardar_configuracion($pdo, 'hora_inicio_sistema', $horaInicio, 'Hora desde la cual el sistema biométrico permite marcaciones.');
                guardar_configuracion($pdo, 'hora_entrada_oficial', $horaEntrada, 'Hora oficial de entrada para calcular puntualidad o tardanza.');
                guardar_configuracion($pdo, 'hora_salida_oficial', $horaSalida, 'Hora oficial de salida de la jornada académica.');
                guardar_configuracion($pdo, 'tolerancia_minutos', $tolerancia, 'Minutos de tolerancia antes de considerar tardanza.');
                guardar_configuracion($pdo, 'intervalo_minimo_marcaciones', $intervaloMinimoMarcaciones, 'Minutos mínimos que deben pasar entre la primera y segunda marcación.');
                guardar_configuracion($pdo, 'tolerancia_salida_minutos', $toleranciaSalida, 'Minutos adicionales permitidos para registrar la salida.');
                guardar_configuracion($pdo, 'porcentaje_alerta', $porcentajeAlerta, 'Porcentaje de inasistencias para activar alerta crítica.');

                $mensajeExito = 'Reglas de asistencia actualizadas correctamente.';
            }
        }

        if ($accion === 'guardar_institucion') {
            $nombreInstitucion = texto_seguro_configuracion($_POST['nombre_institucion'] ?? '');
            $programaDefault = texto_seguro_configuracion($_POST['programa_estudios_default'] ?? '');
            $cicloDefault = texto_seguro_configuracion($_POST['ciclo_default'] ?? '');

            if ($nombreInstitucion === '') {
                $mensajeError = 'El nombre de la institución es obligatorio.';
            } elseif ($programaDefault === '') {
                $mensajeError = 'El programa de estudios es obligatorio.';
            } elseif ($cicloDefault === '') {
                $mensajeError = 'El ciclo académico es obligatorio.';
            } else {
                guardar_configuracion($pdo, 'nombre_institucion', $nombreInstitucion, 'Nombre de la institución que se muestra en el sistema.');
                guardar_configuracion($pdo, 'programa_estudios_default', $programaDefault, 'Programa de estudios usado como valor predeterminado.');
                guardar_configuracion($pdo, 'ciclo_default', $cicloDefault, 'Ciclo académico usado como valor predeterminado.');

                $mensajeExito = 'Datos institucionales actualizados correctamente.';
            }
        }

        if ($accion === 'guardar_dispositivo') {
            $estadoBiometrico = texto_seguro_configuracion($_POST['estado_biometrico'] ?? '');
            $estadoSensor = texto_seguro_configuracion($_POST['estado_sensor'] ?? '');
            $estadoWifi = texto_seguro_configuracion($_POST['estado_wifi'] ?? '');
            $mensaje = texto_seguro_configuracion($_POST['mensaje'] ?? '');

            $estadosBiometricoPermitidos = ['Sistema Activo', 'Estado Apagado', 'Sin Conexión', 'Mantenimiento'];
            $estadosSensorPermitidos = ['Activo', 'Apagado', 'Sin Lectura', 'Error'];
            $estadosWifiPermitidos = ['Conectado', 'Desconectado', 'Inestable'];

            if (!in_array($estadoBiometrico, $estadosBiometricoPermitidos, true)) {
                $mensajeError = 'El estado del biométrico no es válido.';
            } elseif (!in_array($estadoSensor, $estadosSensorPermitidos, true)) {
                $mensajeError = 'El estado del sensor no es válido.';
            } elseif (!in_array($estadoWifi, $estadosWifiPermitidos, true)) {
                $mensajeError = 'El estado WiFi no es válido.';
            } else {
                $estadoActual = obtener_estado_dispositivo_configuracion($pdo);

                $consulta = $pdo->prepare(
                    "UPDATE estado_dispositivo
                     SET estado_biometrico = :estado_biometrico,
                         estado_sensor = :estado_sensor,
                         estado_wifi = :estado_wifi,
                         mensaje = :mensaje,
                         fecha_actualizacion = NOW()
                     WHERE id_estado = :id_estado"
                );

                $consulta->execute([
                    'estado_biometrico' => $estadoBiometrico,
                    'estado_sensor' => $estadoSensor,
                    'estado_wifi' => $estadoWifi,
                    'mensaje' => $mensaje !== '' ? $mensaje : null,
                    'id_estado' => (int) $estadoActual['id_estado']
                ]);

                $estadoDispositivo = obtener_estado_dispositivo($pdo);
                $claseEstadoDispositivo = clase_estado_dispositivo($estadoDispositivo);
                $mensajeExito = 'Estado del dispositivo actualizado correctamente.';
            }
        }

        if ($accion === 'guardar_notificaciones') {
            $modoNotificacion = texto_seguro_configuracion($_POST['modo_notificacion'] ?? 'API');
            $correoSoporte = texto_seguro_configuracion($_POST['correo_soporte'] ?? '');
            $telefonoSoporte = texto_seguro_configuracion($_POST['telefono_soporte'] ?? '');

            if (!in_array($modoNotificacion, ['API'], true)) {
                $mensajeError = 'El modo de notificación debe estar en API real.';
            } elseif ($correoSoporte !== '' && !filter_var($correoSoporte, FILTER_VALIDATE_EMAIL)) {
                $mensajeError = 'El correo de soporte no es válido.';
            } else {
                guardar_configuracion($pdo, 'modo_notificacion', $modoNotificacion, 'Modo de envío de alertas mediante API real.');
                guardar_configuracion($pdo, 'correo_soporte', $correoSoporte, 'Correo usado para soporte y envío real de alertas.');
                guardar_configuracion($pdo, 'telefono_soporte', $telefonoSoporte, 'Teléfono o WhatsApp usado para soporte y envío real de alertas.');

                $mensajeExito = 'Configuración de notificaciones actualizada correctamente.';
            }
        }
    } catch (Throwable $e) {
        $mensajeError = $e->getMessage() !== '' ? $e->getMessage() : 'No se pudo guardar la configuración.';
    }
}

$horaInicioSistema = obtener_configuracion($pdo, 'hora_inicio_sistema', '13:00');
$horaEntradaOficial = obtener_configuracion($pdo, 'hora_entrada_oficial', '14:00');
$horaSalidaOficial = obtener_configuracion($pdo, 'hora_salida_oficial', '19:00');
$toleranciaMinutos = obtener_configuracion($pdo, 'tolerancia_minutos', '15');
$intervaloMinimoMarcaciones = obtener_configuracion($pdo, 'intervalo_minimo_marcaciones', '30');
$toleranciaSalidaMinutos = obtener_configuracion($pdo, 'tolerancia_salida_minutos', '15');
$porcentajeAlerta = obtener_configuracion($pdo, 'porcentaje_alerta', '30');
$nombreInstitucion = obtener_configuracion($pdo, 'nombre_institucion', 'IESTP Ciro Alegría Bazán');
$programaDefault = obtener_configuracion($pdo, 'programa_estudios_default', 'Computación e Informática');
$cicloDefault = obtener_configuracion($pdo, 'ciclo_default', 'V');
$modoNotificacion = obtener_configuracion($pdo, 'modo_notificacion', 'API');
if ($modoNotificacion === 'API') {
    $modoNotificacion = 'API';
    guardar_configuracion($pdo, 'modo_notificacion', 'API', 'Modo de envío de alertas mediante API real.');
}
$correoSoporte = obtener_configuracion($pdo, 'correo_soporte', '');
$telefonoSoporte = obtener_configuracion($pdo, 'telefono_soporte', '');
$estadoActualDispositivo = obtener_estado_dispositivo_configuracion($pdo);

$totalEstudiantes = (int) $pdo->query("SELECT COUNT(*) FROM estudiantes")->fetchColumn();
$totalUsuarios = (int) $pdo->query("SELECT COUNT(*) FROM usuarios")->fetchColumn();
$totalCursos = (int) $pdo->query("SELECT COUNT(*) FROM cursos")->fetchColumn();
$totalHuellas = (int) $pdo->query("SELECT COUNT(*) FROM huellas WHERE estado = 'Activa'")->fetchColumn();

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
    <title>BioAsistencia - Configuración</title>
    <link rel="icon" type="image/png" href="<?php echo app_url('assets/img/logo.png?v=1'); ?>">
    <link rel="shortcut icon" type="image/png" href="<?php echo app_url('assets/img/logo.png?v=1'); ?>">
    <link rel="stylesheet" href="<?php echo app_url('assets/css/styles.css?v=9999'); ?>">
</head>
<body class="pagina-interna pagina-configuracion">

   <?php
   $menuActual = 'configuracion';
   require __DIR__ . '/../includes/sidebar.php';
   ?>

    <div class="contenido-principal premium-main">
        <header class="barra-superior premium-topbar">
            <div>
                <span class="breadcrumb-premium">BioAsistencia / Administración</span>
                <h1 class="titulo-pagina">Configuración</h1>
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

        <main class="area-contenido premium-content">
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

            <section class="hero-modulo hero-configuracion">
                <div class="hero-modulo-contenido">
                    <span class="hero-chip">Centro de control</span>
                    <h2>Configuración general del sistema</h2>
                    <p>
                        Administra reglas de asistencia, datos institucionales, estado del dispositivo y parámetros de notificación.
                    </p>
                </div>

                <div class="hero-modulo-panel">
                    <span class="hero-panel-label">Sistema actual</span>
                    <strong>Operativo</strong>
                    <small>Preparado para Arduino y sensor biométrico</small>
                </div>
            </section>

            <section class="dashboard-stat-grid configuracion-stat-grid">
                <article class="stat-card">
                    <div class="stat-head">
                        <div class="stat-icon">🎓</div>
                        <span class="stat-chip">Estudiantes</span>
                    </div>
                    <div class="stat-label">Registrados</div>
                    <div class="stat-number"><?php echo $totalEstudiantes; ?></div>
                    <div class="stat-bar"><span style="width:100%"></span></div>
                </article>

                <article class="stat-card stat-green">
                    <div class="stat-head">
                        <div class="stat-icon">🧬</div>
                        <span class="stat-chip">Huellas</span>
                    </div>
                    <div class="stat-label">Activas</div>
                    <div class="stat-number"><?php echo $totalHuellas; ?></div>
                    <div class="stat-bar"><span style="width:<?php echo $totalEstudiantes > 0 ? min(100, round(($totalHuellas / $totalEstudiantes) * 100)) : 0; ?>%"></span></div>
                </article>

                <article class="stat-card stat-orange">
                    <div class="stat-head">
                        <div class="stat-icon">📚</div>
                        <span class="stat-chip">Cursos</span>
                    </div>
                    <div class="stat-label">Configurados</div>
                    <div class="stat-number"><?php echo $totalCursos; ?></div>
                    <div class="stat-bar"><span style="width:85%"></span></div>
                </article>

                <article class="stat-card stat-red">
                    <div class="stat-head">
                        <div class="stat-icon">👤</div>
                        <span class="stat-chip">Usuarios</span>
                    </div>
                    <div class="stat-label">Cuentas del sistema</div>
                    <div class="stat-number"><?php echo $totalUsuarios; ?></div>
                    <div class="stat-bar"><span style="width:75%"></span></div>
                </article>
            </section>

            <section class="configuracion-grid">
                <article class="tarjeta-panel configuracion-card">
                    <div class="panel-title-row">
                        <div>
                            <span class="panel-kicker">Reglas académicas</span>
                            <h2 class="titulo-tarjeta-panel">Asistencia y alertas</h2>
                        </div>
                        <span class="badge-premium badge-blue">Horario</span>
                    </div>

                    <form method="POST" action="configuracion.php" class="formulario-panel formulario-configuracion">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($tokenCsrf, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="accion" value="guardar_reglas">

                        <div class="grupo-campo">
                            <label for="horaInicioSistema">Hora de activación del sistema</label>
                            <input type="time" id="horaInicioSistema" name="hora_inicio_sistema" value="<?php echo htmlspecialchars($horaInicioSistema, ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>

                        <div class="grupo-campo">
                            <label for="horaEntradaOficial">Hora oficial de entrada</label>
                            <input type="time" id="horaEntradaOficial" name="hora_entrada_oficial" value="<?php echo htmlspecialchars($horaEntradaOficial, ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>

                        <div class="grupo-campo">
                            <label for="horaSalidaOficial">Hora oficial de salida</label>
                            <input type="time" id="horaSalidaOficial" name="hora_salida_oficial" value="<?php echo htmlspecialchars($horaSalidaOficial, ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>

                        <div class="grupo-campo">
                            <label for="toleranciaMinutos">Tolerancia de entrada en minutos</label>
                            <input type="number" id="toleranciaMinutos" name="tolerancia_minutos" min="0" max="60" value="<?php echo htmlspecialchars($toleranciaMinutos, ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>

                        <div class="grupo-campo">
                            <label for="intervaloMinimoMarcaciones">Tiempo mínimo entre marcaciones</label>
                            <input type="number" id="intervaloMinimoMarcaciones" name="intervalo_minimo_marcaciones" min="1" max="180" value="<?php echo htmlspecialchars($intervaloMinimoMarcaciones, ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>

                        <div class="grupo-campo">
                            <label for="toleranciaSalidaMinutos">Tolerancia de salida en minutos</label>
                            <input type="number" id="toleranciaSalidaMinutos" name="tolerancia_salida_minutos" min="0" max="60" value="<?php echo htmlspecialchars($toleranciaSalidaMinutos, ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>

                        <div class="grupo-campo">
                            <label for="porcentajeAlerta">Porcentaje para alerta crítica</label>
                            <input type="number" id="porcentajeAlerta" name="porcentaje_alerta" min="1" max="100" value="<?php echo htmlspecialchars($porcentajeAlerta, ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>

                        <div class="acciones-panel acciones-configuracion">
                            <button type="submit" class="boton-primario">Guardar reglas</button>
                        </div>
                    </form>
                </article>

                <article class="tarjeta-panel configuracion-card">
                    <div class="panel-title-row">
                        <div>
                            <span class="panel-kicker">Institución</span>
                            <h2 class="titulo-tarjeta-panel">Datos predeterminados</h2>
                        </div>
                        <span class="badge-premium badge-green">Académico</span>
                    </div>

                    <form method="POST" action="configuracion.php" class="formulario-panel formulario-configuracion">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($tokenCsrf, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="accion" value="guardar_institucion">

                        <div class="grupo-campo full-line">
                            <label for="nombreInstitucion">Nombre de institución</label>
                            <input type="text" id="nombreInstitucion" name="nombre_institucion" value="<?php echo htmlspecialchars($nombreInstitucion, ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>

                        <div class="grupo-campo">
                            <label for="programaDefault">Programa por defecto</label>
                            <input type="text" id="programaDefault" name="programa_estudios_default" value="<?php echo htmlspecialchars($programaDefault, ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>

                        <div class="grupo-campo">
                            <label for="cicloDefault">Ciclo por defecto</label>
                            <input type="text" id="cicloDefault" name="ciclo_default" value="<?php echo htmlspecialchars($cicloDefault, ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>

                        <div class="acciones-panel acciones-configuracion">
                            <button type="submit" class="boton-primario">Guardar institución</button>
                        </div>
                    </form>
                </article>

                <article class="tarjeta-panel configuracion-card">
                    <div class="panel-title-row">
                        <div>
                            <span class="panel-kicker">Hardware</span>
                            <h2 class="titulo-tarjeta-panel">Estado del dispositivo</h2>
                        </div>
                        <span class="badge-premium badge-orange">Biométrico</span>
                    </div>

                    <form method="POST" action="configuracion.php" class="formulario-panel formulario-configuracion">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($tokenCsrf, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="accion" value="guardar_dispositivo">

                        <div class="grupo-campo">
                            <label for="estadoBiometrico">Estado biométrico</label>
                            <select id="estadoBiometrico" name="estado_biometrico">
                                <?php foreach (['Sistema Activo', 'Estado Apagado', 'Sin Conexión', 'Mantenimiento'] as $opcion): ?>
                                    <option value="<?php echo htmlspecialchars($opcion, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $estadoActualDispositivo['estado_biometrico'] === $opcion ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($opcion, ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="grupo-campo">
                            <label for="estadoSensor">Estado del sensor</label>
                            <select id="estadoSensor" name="estado_sensor">
                                <?php foreach (['Activo', 'Apagado', 'Sin Lectura', 'Error'] as $opcion): ?>
                                    <option value="<?php echo htmlspecialchars($opcion, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $estadoActualDispositivo['estado_sensor'] === $opcion ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($opcion, ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="grupo-campo">
                            <label for="estadoWifi">Estado WiFi</label>
                            <select id="estadoWifi" name="estado_wifi">
                                <?php foreach (['Conectado', 'Desconectado', 'Inestable'] as $opcion): ?>
                                    <option value="<?php echo htmlspecialchars($opcion, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $estadoActualDispositivo['estado_wifi'] === $opcion ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($opcion, ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="grupo-campo full-line">
                            <label for="mensajeDispositivo">Mensaje del dispositivo</label>
                            <textarea id="mensajeDispositivo" name="mensaje" rows="3"><?php echo htmlspecialchars($estadoActualDispositivo['mensaje'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>

                        <div class="acciones-panel acciones-configuracion">
                            <button type="submit" class="boton-primario">Guardar dispositivo</button>
                        </div>
                    </form>
                </article>

                <article class="tarjeta-panel configuracion-card">
                    <div class="panel-title-row">
                        <div>
                            <span class="panel-kicker">Alertas</span>
                            <h2 class="titulo-tarjeta-panel">Notificaciones</h2>
                        </div>
                        <span class="badge-premium badge-red">Comunicación</span>
                    </div>

                    <form method="POST" action="configuracion.php" class="formulario-panel formulario-configuracion">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($tokenCsrf, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="accion" value="guardar_notificaciones">

                        <div class="grupo-campo">
                            <label for="modoNotificacion">Modo de notificación</label>
                            <select id="modoNotificacion" name="modo_notificacion">
                                <option value="API" selected>API real</option>
                            </select>
                        </div>

                        <div class="grupo-campo">
                            <label for="correoSoporte">Correo de soporte</label>
                            <input type="email" id="correoSoporte" name="correo_soporte" value="<?php echo htmlspecialchars($correoSoporte, ENT_QUOTES, 'UTF-8'); ?>">
                        </div>

                        <div class="grupo-campo full-line">
                            <label for="telefonoSoporte">WhatsApp o teléfono de soporte</label>
                            <input type="text" id="telefonoSoporte" name="telefono_soporte" value="<?php echo htmlspecialchars($telefonoSoporte, ENT_QUOTES, 'UTF-8'); ?>">
                        </div>

                        <div class="acciones-panel acciones-configuracion">
                            <button type="submit" class="boton-primario">Guardar notificaciones</button>
                        </div>
                    </form>
                </article>
            </section>
        </main>
    </div>

    <script src="<?php echo app_url('assets/js/main.js?v=9999'); ?>"></script>
</body>
</html>