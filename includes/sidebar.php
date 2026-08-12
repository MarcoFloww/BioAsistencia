<?php
if (!function_exists('bio_sidebar_texto')) {
    function bio_sidebar_texto($valor): string {
        return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('bio_sidebar_inicial')) {
    function bio_sidebar_inicial(string $nombre): string {
        $nombre = trim($nombre);
        if ($nombre === '') return 'U';
        if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
            return mb_strtoupper(mb_substr($nombre, 0, 1, 'UTF-8'), 'UTF-8');
        }
        return strtoupper(substr($nombre, 0, 1));
    }
}

$rolSidebar = $rol ?? ($_SESSION['rol'] ?? 'Usuario');
$nombreSidebar = $nombreUsuario ?? ($_SESSION['nombre_completo'] ?? $rolSidebar);

$archivoActualSidebar = basename($_SERVER['SCRIPT_NAME'] ?? '');

$mapaActivoSidebar = [
    'dashboard.php' => 'dashboard',
    'estudiantes.php' => 'estudiantes',
    'docentes.php' => 'docentes',
    'cursos_horarios.php' => 'cursos',
    'asistencia.php' => 'asistencia',
    'reportes.php' => 'reportes',
    'alertas.php' => 'alertas',
    'usuarios.php' => 'usuarios',
    'configuracion.php' => 'configuracion',
    'perfil.php' => 'perfil'
];

$menuActual = $menuActual ?? ($mapaActivoSidebar[$archivoActualSidebar] ?? 'dashboard');

$menuAdministradorSidebar = [
    ['titulo' => 'Panel General', 'url' => 'dashboard.php', 'key' => 'dashboard', 'icono' => 'M4 6h16M4 12h16M4 18h16'],
    ['titulo' => 'Estudiantes', 'url' => 'estudiantes.php', 'key' => 'estudiantes', 'icono' => 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM4 21a8 8 0 0 1 16 0'],
    ['titulo' => 'Docentes', 'url' => 'docentes.php', 'key' => 'docentes', 'icono' => 'M4 19.5A2.5 2.5 0 0 1 6.5 17H20M8 7h8M8 11h8M8 15h5'],
    ['titulo' => 'Cursos y Horarios', 'url' => 'cursos_horarios.php', 'key' => 'cursos', 'icono' => 'M5 4h14v16H5zM8 8h8M8 12h5'],
    ['titulo' => 'Asistencia', 'url' => 'asistencia.php', 'key' => 'asistencia', 'icono' => 'M9 11l3 3L22 4M5 5h11M5 19h14'],
    ['titulo' => 'Reportes', 'url' => 'reportes.php', 'key' => 'reportes', 'icono' => 'M4 19V5M4 19h16M8 15l3-4 4 3 5-8'],
    ['titulo' => 'Alertas', 'url' => 'alertas.php', 'key' => 'alertas', 'icono' => 'M12 3 2 20h20L12 3ZM12 9v5M12 17h.01'],
    ['titulo' => 'Usuarios', 'url' => 'usuarios.php', 'key' => 'usuarios', 'icono' => 'M16 21v-2a4 4 0 0 0-8 0v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M22 21v-2a4 4 0 0 0-3-3.87'],
    ['titulo' => 'Configuración', 'url' => 'configuracion.php', 'key' => 'configuracion', 'icono' => 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM19.4 15a1.65 1.65 0 0 0 .33 1.82M4.27 7.12A1.65 1.65 0 0 0 4.6 9']
];

$menuDocenteSidebar = [
    ['titulo' => 'Panel Docente', 'url' => 'dashboard.php', 'key' => 'dashboard', 'icono' => 'M4 6h16M4 12h16M4 18h16'],
    ['titulo' => 'Estudiantes', 'url' => 'estudiantes.php', 'key' => 'estudiantes', 'icono' => 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM4 21a8 8 0 0 1 16 0'],
    ['titulo' => 'Cursos y Horarios', 'url' => 'cursos_horarios.php', 'key' => 'cursos', 'icono' => 'M5 4h14v16H5zM8 8h8M8 12h5'],
    ['titulo' => 'Asistencia', 'url' => 'asistencia.php', 'key' => 'asistencia', 'icono' => 'M9 11l3 3L22 4M5 5h11M5 19h14'],
    ['titulo' => 'Reportes', 'url' => 'reportes.php', 'key' => 'reportes', 'icono' => 'M4 19V5M4 19h16M8 15l3-4 4 3 5-8'],
    ['titulo' => 'Alertas', 'url' => 'alertas.php', 'key' => 'alertas', 'icono' => 'M12 3 2 20h20L12 3ZM12 9v5M12 17h.01'],
    ['titulo' => 'Perfil', 'url' => 'perfil.php', 'key' => 'perfil', 'icono' => 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM4 21a8 8 0 0 1 16 0']
];

$menuSidebar = $rolSidebar === 'Administrador' ? $menuAdministradorSidebar : $menuDocenteSidebar;
?>

<aside class="premium-sidebar">
    <div class="premium-sidebar-marca">
        <div class="premium-sidebar-logo">
            <img src="<?php echo bio_sidebar_texto(app_url('assets/img/logo.png')); ?>" alt="BioAsistencia" class="logo-lateral">
        </div>

        <div class="premium-sidebar-nombre">
            <strong>BioAsistencia</strong>
            <span>Sistema Biométrico</span>
        </div>
    </div>

    <div class="premium-sidebar-usuario">
        <div class="premium-avatar">
            <?php echo bio_sidebar_texto(bio_sidebar_inicial($nombreSidebar)); ?>
        </div>

        <div>
            <strong><?php echo bio_sidebar_texto($rolSidebar); ?></strong>
            <span><i></i> En línea</span>
        </div>
    </div>

    <nav class="premium-menu">
        <span class="premium-menu-titulo">Navegación</span>

        <?php foreach ($menuSidebar as $item): ?>
            <a href="<?php echo bio_sidebar_texto(app_url('pages/' . $item['url'])); ?>" class="premium-menu-link <?php echo $item['key'] === $menuActual ? 'activo' : ''; ?>">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="<?php echo bio_sidebar_texto($item['icono']); ?>" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <span><?php echo bio_sidebar_texto($item['titulo']); ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
</aside>
