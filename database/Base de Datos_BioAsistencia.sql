CREATE DATABASE bioasistencia
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE bioasistencia;

SET NAMES utf8mb4;
SET time_zone = '-05:00';

CREATE TABLE roles (
    id_rol INT AUTO_INCREMENT PRIMARY KEY,
    nombre_rol VARCHAR(50) NOT NULL UNIQUE,
    descripcion VARCHAR(200) NULL,
    estado ENUM('Activo','Inactivo') NOT NULL DEFAULT 'Activo',
    fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE usuarios (
    id_usuario INT AUTO_INCREMENT PRIMARY KEY,
    id_rol INT NOT NULL,
    nombres VARCHAR(120) NOT NULL,
    apellidos VARCHAR(120) NOT NULL,
    usuario VARCHAR(60) NOT NULL UNIQUE,
    correo VARCHAR(150) NOT NULL UNIQUE,
    contrasena VARCHAR(255) NOT NULL,
    estado ENUM('Activo','Inactivo') NOT NULL DEFAULT 'Activo',
    ultimo_acceso DATETIME NULL,
    fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_usuarios_roles
        FOREIGN KEY (id_rol)
        REFERENCES roles(id_rol)
        ON DELETE NO ACTION
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE docentes (
    id_docente INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario INT NULL,
    codigo_docente VARCHAR(20) NOT NULL UNIQUE,
    nombres VARCHAR(120) NOT NULL,
    apellidos VARCHAR(120) NOT NULL,
    dni VARCHAR(15) NOT NULL UNIQUE,
    correo VARCHAR(150) NOT NULL UNIQUE,
    telefono VARCHAR(20) NULL,
    especialidad VARCHAR(150) NULL,
    titulo_cargo VARCHAR(150) NOT NULL DEFAULT 'Docente',
    correo_institucional VARCHAR(150) NULL,
    whatsapp VARCHAR(20) NULL,
    estado ENUM('Activo','Inactivo') NOT NULL DEFAULT 'Activo',
    fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_docentes_usuario UNIQUE (id_usuario),
    CONSTRAINT fk_docentes_usuarios
        FOREIGN KEY (id_usuario)
        REFERENCES usuarios(id_usuario)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cursos (
    id_curso INT AUTO_INCREMENT PRIMARY KEY,
    codigo_curso VARCHAR(20) NULL UNIQUE,
    nombre_curso VARCHAR(160) NOT NULL,
    descripcion VARCHAR(300) NULL,
    ciclo VARCHAR(20) NOT NULL DEFAULT 'V',
    programa_estudios VARCHAR(160) NOT NULL DEFAULT 'Computación e Informática',
    horas_semanales INT NOT NULL DEFAULT 0,
    estado ENUM('Activo','Inactivo') NOT NULL DEFAULT 'Activo',
    fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE docente_curso (
    id_docente_curso INT AUTO_INCREMENT PRIMARY KEY,
    id_docente INT NOT NULL,
    id_curso INT NOT NULL,
    estado ENUM('Activo','Inactivo') NOT NULL DEFAULT 'Activo',
    fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_docente_curso UNIQUE (id_docente, id_curso),
    CONSTRAINT fk_docente_curso_docentes
        FOREIGN KEY (id_docente)
        REFERENCES docentes(id_docente)
        ON DELETE NO ACTION
        ON UPDATE CASCADE,
    CONSTRAINT fk_docente_curso_cursos
        FOREIGN KEY (id_curso)
        REFERENCES cursos(id_curso)
        ON DELETE NO ACTION
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE periodos_academicos (
    id_periodo INT AUTO_INCREMENT PRIMARY KEY,
    nombre_periodo VARCHAR(100) NOT NULL UNIQUE,
    fecha_inicio DATE NOT NULL,
    fecha_fin DATE NOT NULL,
    estado ENUM('Activo','Inactivo') NOT NULL DEFAULT 'Activo',
    fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_periodo_fechas CHECK (fecha_fin >= fecha_inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE estudiantes (
    id_estudiante INT AUTO_INCREMENT PRIMARY KEY,
    id_periodo INT NOT NULL DEFAULT 1,
    codigo_estudiante VARCHAR(20) NOT NULL UNIQUE,
    nombres VARCHAR(120) NOT NULL,
    apellidos VARCHAR(120) NOT NULL,
    dni VARCHAR(15) NULL UNIQUE,
    correo VARCHAR(150) NULL UNIQUE,
    correo_institucional VARCHAR(150) NULL UNIQUE,
    telefono VARCHAR(20) NULL,
    whatsapp VARCHAR(20) NULL,
    direccion VARCHAR(250) NULL,
    programa_estudios VARCHAR(160) NOT NULL DEFAULT 'Computación e Informática',
    ciclo VARCHAR(20) NOT NULL DEFAULT 'V',
    turno ENUM('Mañana','Tarde','Noche') NOT NULL DEFAULT 'Tarde',
    estado_academico ENUM('Regular','Retirado','Egresado') NOT NULL DEFAULT 'Regular',
    estado ENUM('Activo','Inactivo') NOT NULL DEFAULT 'Activo',
    fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_estudiantes_periodos
        FOREIGN KEY (id_periodo)
        REFERENCES periodos_academicos(id_periodo)
        ON DELETE NO ACTION
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE huellas (
    id_huella INT AUTO_INCREMENT PRIMARY KEY,
    id_estudiante INT NOT NULL,
    id_sensor INT NOT NULL UNIQUE,
    dedo_registrado VARCHAR(50) NOT NULL DEFAULT 'Índice derecho',
    plantilla_huella TEXT NULL,
    estado ENUM('Activa','Inactiva','Pendiente') NOT NULL DEFAULT 'Activa',
    fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_huella_estudiante UNIQUE (id_estudiante),
    CONSTRAINT fk_huellas_estudiantes
        FOREIGN KEY (id_estudiante)
        REFERENCES estudiantes(id_estudiante)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT chk_huella_sensor CHECK (id_sensor > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE horarios (
    id_horario INT AUTO_INCREMENT PRIMARY KEY,
    id_curso INT NULL,
    id_docente INT NULL,
    dia_semana ENUM('Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo') NOT NULL,
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    tipo_actividad ENUM('Clase','Receso') NOT NULL DEFAULT 'Clase',
    aula VARCHAR(60) NULL,
    estado ENUM('Activo','Inactivo') NOT NULL DEFAULT 'Activo',
    fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_horarios_cursos
        FOREIGN KEY (id_curso)
        REFERENCES cursos(id_curso)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
    CONSTRAINT fk_horarios_docentes
        FOREIGN KEY (id_docente)
        REFERENCES docentes(id_docente)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
    CONSTRAINT chk_horarios_horas CHECK (hora_fin > hora_inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE asistencias (
    id_asistencia INT AUTO_INCREMENT PRIMARY KEY,
    id_estudiante INT NOT NULL,
    fecha DATE NOT NULL,
    hora_entrada TIME NULL,
    hora_salida TIME NULL,
    estado_entrada ENUM('Puntual','Tardanza','Falto','Falta') NOT NULL,
    estado_salida ENUM('Sin registro de salida','Salida Registrada','Salida Anticipada','No aplica') NOT NULL DEFAULT 'Sin registro de salida',
    metodo_registro ENUM('Huella','Manual','Sistema') NOT NULL DEFAULT 'Huella',
    observacion VARCHAR(300) NULL,
    registrado_por INT NULL,
    fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_asistencia_estudiante_fecha UNIQUE (id_estudiante, fecha),
    CONSTRAINT fk_asistencias_estudiantes
        FOREIGN KEY (id_estudiante)
        REFERENCES estudiantes(id_estudiante)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_asistencias_usuarios
        FOREIGN KEY (registrado_por)
        REFERENCES usuarios(id_usuario)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
    CONSTRAINT chk_asistencia_horas CHECK (
        hora_salida IS NULL OR hora_entrada IS NULL OR hora_salida >= hora_entrada
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE alertas (
    id_alerta INT AUTO_INCREMENT PRIMARY KEY,
    id_estudiante INT NOT NULL,
    tipo_alerta ENUM('Tardanza','Falta','Salida Anticipada','Huella no registrada','Sistema') NULL,
    nivel_alerta ENUM('Informativo','Seguimiento','Crítico') NULL DEFAULT 'Informativo',
    porcentaje_inasistencia DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    nivel_riesgo ENUM('Normal','Atención','Riesgo','Alerta crítica','Sin datos') NOT NULL DEFAULT 'Normal',
    titulo VARCHAR(160) NULL,
    descripcion TEXT NULL,
    estado ENUM('Activa','Resuelta','Pendiente','Atendida','Archivada') NOT NULL DEFAULT 'Activa',
    fecha_alerta DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_atencion DATETIME NULL,
    atendido_por INT NULL,
    CONSTRAINT fk_alertas_estudiantes
        FOREIGN KEY (id_estudiante)
        REFERENCES estudiantes(id_estudiante)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_alertas_usuarios
        FOREIGN KEY (atendido_por)
        REFERENCES usuarios(id_usuario)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE configuracion (
    id_configuracion INT AUTO_INCREMENT PRIMARY KEY,
    clave VARCHAR(100) NOT NULL UNIQUE,
    valor TEXT NULL,
    descripcion VARCHAR(255) NULL,
    nombre_configuracion VARCHAR(120) NULL,
    valor_configuracion TEXT NULL,
    fecha_actualizacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE estado_dispositivo (
    id_estado INT AUTO_INCREMENT PRIMARY KEY,
    nombre_dispositivo VARCHAR(120) NULL,
    estado ENUM('Activo','Apagado','Desconectado','Error') NULL DEFAULT 'Desconectado',
    ultimo_evento VARCHAR(250) NULL,
    ultima_conexion DATETIME NULL,
    estado_biometrico VARCHAR(50) NOT NULL DEFAULT 'Estado Apagado',
    estado_sensor VARCHAR(50) NOT NULL DEFAULT 'Apagado',
    estado_wifi VARCHAR(50) NOT NULL DEFAULT 'Desconectado',
    mensaje VARCHAR(255) NULL,
    fecha_actualizacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reportes (
    id_reporte INT AUTO_INCREMENT PRIMARY KEY,
    nombre_reporte VARCHAR(160) NULL,
    tipo_reporte ENUM('Diario','Semanal','Mensual','Personalizado') NOT NULL DEFAULT 'Semanal',
    fecha_inicio DATE NULL,
    fecha_fin DATE NULL,
    semana_inicio DATE NULL,
    semana_fin DATE NULL,
    generado_por INT NULL,
    ruta_archivo VARCHAR(250) NULL,
    fecha_generado DATETIME NULL,
    fecha_generacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_reportes_usuarios
        FOREIGN KEY (generado_por)
        REFERENCES usuarios(id_usuario)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
    CONSTRAINT chk_reportes_fechas CHECK (fecha_fin IS NULL OR fecha_inicio IS NULL OR fecha_fin >= fecha_inicio),
    CONSTRAINT chk_reportes_semana CHECK (semana_fin IS NULL OR semana_inicio IS NULL OR semana_fin >= semana_inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_usuarios_rol ON usuarios(id_rol);
CREATE INDEX idx_docentes_estado ON docentes(estado);
CREATE INDEX idx_cursos_estado ON cursos(estado);
CREATE INDEX idx_estudiantes_periodo ON estudiantes(id_periodo);
CREATE INDEX idx_estudiantes_estado ON estudiantes(estado);
CREATE INDEX idx_huellas_sensor ON huellas(id_sensor);
CREATE INDEX idx_horarios_dia ON horarios(dia_semana);
CREATE INDEX idx_asistencias_fecha ON asistencias(fecha);
CREATE INDEX idx_asistencias_estado ON asistencias(estado_entrada);
CREATE INDEX idx_asistencias_salida ON asistencias(estado_salida);
CREATE INDEX idx_alertas_estado ON alertas(estado);
CREATE INDEX idx_alertas_nivel ON alertas(nivel_alerta);
CREATE INDEX idx_alertas_riesgo ON alertas(nivel_riesgo);
CREATE INDEX idx_reportes_fechas ON reportes(fecha_inicio, fecha_fin);
CREATE INDEX idx_reportes_semana ON reportes(semana_inicio, semana_fin);

INSERT INTO roles (id_rol, nombre_rol, descripcion, estado) VALUES
(1, 'Administrador', 'Acceso completo al sistema biométrico de asistencia.', 'Activo'),
(2, 'Docente', 'Acceso a módulos académicos, asistencia, reportes y perfil.', 'Activo');

INSERT INTO usuarios (id_usuario, id_rol, nombres, apellidos, usuario, correo, contrasena, estado) VALUES
(1, 1, 'Administrador', 'General', 'admin', 'admin@bioasistencia.local', '$2y$12$0267sGYwFUwugvRIXBShA.TW265.2CuPl9XUtyPz7mSfdghz1Je7W', 'Activo'),
(2, 2, 'Franshesca', 'Lamadrid Bringas', 'f.lamadrid', 'franshesca.lamadrid@bioasistencia.local', '$2y$12$0267sGYwFUwugvRIXBShA.TW265.2CuPl9XUtyPz7mSfdghz1Je7W', 'Activo'),
(3, 2, 'Diana Carolina', 'Mil Osorio', 'diana.mil', 'diana.mil@bioasistencia.local', '$2y$12$0267sGYwFUwugvRIXBShA.TW265.2CuPl9XUtyPz7mSfdghz1Je7W', 'Activo'),
(4, 2, 'Luis Antonio', 'Pairazamán Polo', 'luis.pairazaman', 'luis.pairazaman@bioasistencia.local', '$2y$12$0267sGYwFUwugvRIXBShA.TW265.2CuPl9XUtyPz7mSfdghz1Je7W', 'Activo'),
(5, 2, 'Arturo', 'Licham Abanto', 'arturo.licham', 'arturo.licham@bioasistencia.local', '$2y$12$0267sGYwFUwugvRIXBShA.TW265.2CuPl9XUtyPz7mSfdghz1Je7W', 'Activo');

INSERT INTO docentes
(id_docente, id_usuario, codigo_docente, nombres, apellidos, dni, correo, telefono, especialidad, titulo_cargo, correo_institucional, whatsapp, estado)
VALUES
(1, 2, 'DOC001', 'Franshesca', 'Lamadrid Bringas', '70000001', 'franshesca.lamadrid@bioasistencia.local', '900000001', 'Análisis de Problemas y ADS', 'Docente de Computación e Informática', 'franshesca.lamadrid@bioasistencia.local', '900000001', 'Activo'),
(2, 3, 'DOC002', 'Diana Carolina', 'Mil Osorio', '70000002', 'diana.mil@bioasistencia.local', '900000002', 'Diseño Web y Base de Datos', 'Docente de Computación e Informática', 'diana.mil@bioasistencia.local', '900000002', 'Activo'),
(3, 4, 'DOC003', 'Luis Antonio', 'Pairazamán Polo', '70000003', 'luis.pairazaman@bioasistencia.local', '900000003', 'Innovación Tecnológica y SQL', 'Docente de Computación e Informática', 'luis.pairazaman@bioasistencia.local', '900000003', 'Activo'),
(4, 5, 'DOC004', 'Arturo', 'Licham Abanto', '70000004', 'arturo.licham@bioasistencia.local', '900000004', 'Programación Orientada a Objetos', 'Docente de Computación e Informática', 'arturo.licham@bioasistencia.local', '900000004', 'Activo');

INSERT INTO cursos (id_curso, codigo_curso, nombre_curso, descripcion, ciclo, programa_estudios, horas_semanales, estado) VALUES
(1, 'CUR001', 'Identificación de Análisis de Problema', 'Curso orientado a identificar, analizar y plantear problemas tecnológicos.', 'V', 'Computación e Informática', 3, 'Activo'),
(2, 'CUR002', 'Diseño Web', 'Curso de diseño y construcción de interfaces web.', 'V', 'Computación e Informática', 5, 'Activo'),
(3, 'CUR003', 'Fundamentos de Innovación Tecnológica', 'Curso de fundamentos para desarrollar soluciones innovadoras.', 'V', 'Computación e Informática', 3, 'Activo'),
(4, 'CUR004', 'Programación Orientada a Objetos', 'Curso de programación con clases, objetos y métodos.', 'V', 'Computación e Informática', 4, 'Activo'),
(5, 'CUR005', 'Análisis y Diseño de Sistemas', 'Curso de modelado, análisis y diseño de soluciones de software.', 'V', 'Computación e Informática', 4, 'Activo'),
(6, 'CUR006', 'Diseño de Base de Datos', 'Curso de modelado, normalización y diseño de bases de datos.', 'V', 'Computación e Informática', 3, 'Activo'),
(7, 'CUR007', 'Gestión de Lenguaje de Consultas', 'Curso de SQL, consultas, procedimientos y transacciones.', 'V', 'Computación e Informática', 4, 'Activo');

INSERT INTO docente_curso (id_docente_curso, id_docente, id_curso, estado) VALUES
(1, 1, 1, 'Activo'),
(2, 2, 2, 'Activo'),
(3, 3, 3, 'Activo'),
(4, 4, 4, 'Activo'),
(5, 1, 5, 'Activo'),
(6, 2, 6, 'Activo'),
(7, 3, 7, 'Activo');

INSERT INTO periodos_academicos (id_periodo, nombre_periodo, fecha_inicio, fecha_fin, estado) VALUES
(1, 'V Ciclo 2026', '2026-04-13', '2026-08-14', 'Activo'),
(2, 'VI Ciclo 2026', '2026-09-07', '2026-12-18', 'Inactivo');

INSERT INTO estudiantes
(id_estudiante, id_periodo, codigo_estudiante, nombres, apellidos, dni, correo, correo_institucional, telefono, whatsapp, direccion, programa_estudios, ciclo, turno, estado_academico, estado)
VALUES
(1, 1, 'EST001', 'Felipe Giampiere', 'Abanto Alvarado', '76000001', 'est001@bioasistencia.local', 'est001@iestpchepen.edu.pe', '910000001', '910000001', 'Chepén', 'Computación e Informática', 'V', 'Tarde', 'Regular', 'Activo'),
(2, 1, 'EST002', 'Maxi Elizeth', 'Aguilar Llamóctanta', '76000002', 'est002@bioasistencia.local', 'est002@iestpchepen.edu.pe', '910000002', '910000002', 'Chepén', 'Computación e Informática', 'V', 'Tarde', 'Regular', 'Activo'),
(3, 1, 'EST003', 'Treicy Mariana', 'Apari Rojas', '76000003', 'est003@bioasistencia.local', 'est003@iestpchepen.edu.pe', '910000003', '910000003', 'Chepén', 'Computación e Informática', 'V', 'Tarde', 'Regular', 'Activo'),
(4, 1, 'EST004', 'Kris Belen', 'Barboza Pardo', '76000004', 'est004@bioasistencia.local', 'est004@iestpchepen.edu.pe', '910000004', '910000004', 'Chepén', 'Computación e Informática', 'V', 'Tarde', 'Regular', 'Activo'),
(5, 1, 'EST005', 'Diana Lili', 'Briones Pilco', '76000005', 'est005@bioasistencia.local', 'est005@iestpchepen.edu.pe', '910000005', '910000005', 'Chepén', 'Computación e Informática', 'V', 'Tarde', 'Regular', 'Activo'),
(6, 1, 'EST006', 'Adriana Zharit', 'Cabanillas Zelada', '76000006', 'est006@bioasistencia.local', 'est006@iestpchepen.edu.pe', '910000006', '910000006', 'Chepén', 'Computación e Informática', 'V', 'Tarde', 'Regular', 'Activo'),
(7, 1, 'EST007', 'Darwin', 'Castañeda Becerra', '76000007', 'est007@bioasistencia.local', 'est007@iestpchepen.edu.pe', '910000007', '910000007', 'Chepén', 'Computación e Informática', 'V', 'Tarde', 'Regular', 'Activo'),
(8, 1, 'EST008', 'Jhonatan Ernesto', 'Castañeda Cubas', '76000008', 'est008@bioasistencia.local', 'est008@iestpchepen.edu.pe', '910000008', '910000008', 'Chepén', 'Computación e Informática', 'V', 'Tarde', 'Regular', 'Activo'),
(9, 1, 'EST009', 'Alberto Aldair', 'Correa Tejada', '76000009', 'est009@bioasistencia.local', 'est009@iestpchepen.edu.pe', '910000009', '910000009', 'Chepén', 'Computación e Informática', 'V', 'Tarde', 'Regular', 'Activo'),
(10, 1, 'EST010', 'Victor Orlando', 'Davila Rodas', '76000010', 'est010@bioasistencia.local', 'est010@iestpchepen.edu.pe', '910000010', '910000010', 'Chepén', 'Computación e Informática', 'V', 'Tarde', 'Regular', 'Activo'),
(11, 1, 'EST011', 'Maria Brigitte', 'Flores Quispe', '76000011', 'est011@bioasistencia.local', 'est011@iestpchepen.edu.pe', '910000011', '910000011', 'Chepén', 'Computación e Informática', 'V', 'Tarde', 'Regular', 'Activo'),
(12, 1, 'EST012', 'Marco Antonio', 'Gutiérrez Cerna', '76000012', 'est012@bioasistencia.local', 'est012@iestpchepen.edu.pe', '910000012', '910000012', 'Chepén', 'Computación e Informática', 'V', 'Tarde', 'Regular', 'Activo'),
(13, 1, 'EST013', 'Briana Milenka', 'Henandez Boza', '76000013', 'est013@bioasistencia.local', 'est013@iestpchepen.edu.pe', '910000013', '910000013', 'Chepén', 'Computación e Informática', 'V', 'Tarde', 'Regular', 'Activo'),
(14, 1, 'EST014', 'Jeanlucas', 'Hernández Díaz', '76000014', 'est014@bioasistencia.local', 'est014@iestpchepen.edu.pe', '910000014', '910000014', 'Chepén', 'Computación e Informática', 'V', 'Tarde', 'Regular', 'Activo'),
(15, 1, 'EST015', 'Duilio Jefferson', 'Huangal Quiroz', '76000015', 'est015@bioasistencia.local', 'est015@iestpchepen.edu.pe', '910000015', '910000015', 'Chepén', 'Computación e Informática', 'V', 'Tarde', 'Regular', 'Activo'),
(16, 1, 'EST016', 'Harrison Bryan', 'Machuca León', '76000016', 'est016@bioasistencia.local', 'est016@iestpchepen.edu.pe', '910000016', '910000016', 'Chepén', 'Computación e Informática', 'V', 'Tarde', 'Regular', 'Activo'),
(17, 1, 'EST017', 'Fernando Alexis', 'Moncada Espinoza', '76000017', 'est017@bioasistencia.local', 'est017@iestpchepen.edu.pe', '910000017', '910000017', 'Chepén', 'Computación e Informática', 'V', 'Tarde', 'Regular', 'Activo'),
(18, 1, 'EST018', 'Jeferson Jair', 'Monja Sandoval', '76000018', 'est018@bioasistencia.local', 'est018@iestpchepen.edu.pe', '910000018', '910000018', 'Chepén', 'Computación e Informática', 'V', 'Tarde', 'Regular', 'Activo'),
(19, 1, 'EST019', 'Helena Graciela', 'Pacheco Sanchez', '76000019', 'est019@bioasistencia.local', 'est019@iestpchepen.edu.pe', '910000019', '910000019', 'Chepén', 'Computación e Informática', 'V', 'Tarde', 'Regular', 'Activo'),
(20, 1, 'EST020', 'Karla Xiomara', 'Palomino Pineda', '76000020', 'est020@bioasistencia.local', 'est020@iestpchepen.edu.pe', '910000020', '910000020', 'Chepén', 'Computación e Informática', 'V', 'Tarde', 'Regular', 'Activo'),
(21, 1, 'EST021', 'José Gerardo', 'Pinedo Lezama', '76000021', 'est021@bioasistencia.local', 'est021@iestpchepen.edu.pe', '910000021', '910000021', 'Chepén', 'Computación e Informática', 'V', 'Tarde', 'Regular', 'Activo'),
(22, 1, 'EST022', 'Carlos Arturo', 'Quispe Cruzado', '76000022', 'est022@bioasistencia.local', 'est022@iestpchepen.edu.pe', '910000022', '910000022', 'Chepén', 'Computación e Informática', 'V', 'Tarde', 'Regular', 'Activo'),
(23, 1, 'EST023', 'Jose Hilton', 'Rivaspata Vasquez', '76000023', 'est023@bioasistencia.local', 'est023@iestpchepen.edu.pe', '910000023', '910000023', 'Chepén', 'Computación e Informática', 'V', 'Tarde', 'Regular', 'Activo'),
(24, 1, 'EST024', 'Fernando Juhior', 'Rojas Valera', '76000024', 'est024@bioasistencia.local', 'est024@iestpchepen.edu.pe', '910000024', '910000024', 'Chepén', 'Computación e Informática', 'V', 'Tarde', 'Regular', 'Activo'),
(25, 1, 'EST025', 'Yan Carlos', 'Sanchez Tarrillo', '76000025', 'est025@bioasistencia.local', 'est025@iestpchepen.edu.pe', '910000025', '910000025', 'Chepén', 'Computación e Informática', 'V', 'Tarde', 'Regular', 'Activo'),
(26, 1, 'EST026', 'Willians Estuart', 'Soberon Mujica', '76000026', 'est026@bioasistencia.local', 'est026@iestpchepen.edu.pe', '910000026', '910000026', 'Chepén', 'Computación e Informática', 'V', 'Tarde', 'Regular', 'Activo'),
(27, 1, 'EST027', 'Jhonatan Jois', 'Suarez Tejada', '76000027', 'est027@bioasistencia.local', 'est027@iestpchepen.edu.pe', '910000027', '910000027', 'Chepén', 'Computación e Informática', 'V', 'Tarde', 'Regular', 'Activo');

INSERT INTO huellas (id_estudiante, id_sensor, dedo_registrado, plantilla_huella, estado) VALUES
(1, 1, 'Índice derecho', 'ID de sensor asignado según número de lista del aula', 'Activa'),
(2, 2, 'Índice derecho', 'ID de sensor asignado según número de lista del aula', 'Activa'),
(3, 3, 'Índice derecho', 'ID de sensor asignado según número de lista del aula', 'Activa'),
(4, 4, 'Índice derecho', 'ID de sensor asignado según número de lista del aula', 'Activa'),
(5, 5, 'Índice derecho', 'ID de sensor asignado según número de lista del aula', 'Activa'),
(6, 6, 'Índice derecho', 'ID de sensor asignado según número de lista del aula', 'Activa'),
(7, 7, 'Índice derecho', 'ID de sensor asignado según número de lista del aula', 'Activa'),
(8, 8, 'Índice derecho', 'ID de sensor asignado según número de lista del aula', 'Activa'),
(9, 9, 'Índice derecho', 'ID de sensor asignado según número de lista del aula', 'Activa'),
(10, 10, 'Índice derecho', 'ID de sensor asignado según número de lista del aula', 'Activa'),
(11, 11, 'Índice derecho', 'ID de sensor asignado según número de lista del aula', 'Activa'),
(12, 12, 'Índice derecho', 'ID de sensor asignado según número de lista del aula', 'Activa'),
(13, 13, 'Índice derecho', 'ID de sensor asignado según número de lista del aula', 'Activa'),
(14, 14, 'Índice derecho', 'ID de sensor asignado según número de lista del aula', 'Activa'),
(15, 15, 'Índice derecho', 'ID de sensor asignado según número de lista del aula', 'Activa'),
(16, 16, 'Índice derecho', 'ID de sensor asignado según número de lista del aula', 'Activa'),
(17, 17, 'Índice derecho', 'ID de sensor asignado según número de lista del aula', 'Activa'),
(18, 18, 'Índice derecho', 'ID de sensor asignado según número de lista del aula', 'Activa'),
(19, 19, 'Índice derecho', 'ID de sensor asignado según número de lista del aula', 'Activa'),
(20, 20, 'Índice derecho', 'ID de sensor asignado según número de lista del aula', 'Activa'),
(21, 21, 'Índice derecho', 'ID de sensor asignado según número de lista del aula', 'Activa'),
(22, 22, 'Índice derecho', 'ID de sensor asignado según número de lista del aula', 'Activa'),
(23, 23, 'Índice derecho', 'ID de sensor asignado según número de lista del aula', 'Activa'),
(24, 24, 'Índice derecho', 'ID de sensor asignado según número de lista del aula', 'Activa'),
(25, 25, 'Índice derecho', 'ID de sensor asignado según número de lista del aula', 'Activa'),
(26, 26, 'Índice derecho', 'ID de sensor asignado según número de lista del aula', 'Activa'),
(27, 27, 'Índice derecho', 'ID de sensor asignado según número de lista del aula', 'Activa');

INSERT INTO horarios (id_curso, id_docente, dia_semana, hora_inicio, hora_fin, tipo_actividad, aula, estado) VALUES
(1, 1, 'Lunes', '14:00:00', '16:15:00', 'Clase', 'Aula 01', 'Activo'),
(NULL, NULL, 'Lunes', '16:15:00', '16:45:00', 'Receso', 'Patio', 'Activo'),
(2, 2, 'Lunes', '16:45:00', '19:00:00', 'Clase', 'Laboratorio 01', 'Activo'),
(3, 3, 'Martes', '14:00:00', '16:15:00', 'Clase', 'Aula 01', 'Activo'),
(NULL, NULL, 'Martes', '16:15:00', '16:45:00', 'Receso', 'Patio', 'Activo'),
(4, 4, 'Martes', '16:45:00', '19:00:00', 'Clase', 'Laboratorio 01', 'Activo'),
(2, 2, 'Miércoles', '14:00:00', '16:15:00', 'Clase', 'Laboratorio 01', 'Activo'),
(NULL, NULL, 'Miércoles', '16:15:00', '16:45:00', 'Receso', 'Patio', 'Activo'),
(5, 1, 'Miércoles', '16:45:00', '19:00:00', 'Clase', 'Aula 01', 'Activo'),
(4, 4, 'Jueves', '14:00:00', '15:30:00', 'Clase', 'Laboratorio 01', 'Activo'),
(6, 2, 'Jueves', '15:30:00', '16:15:00', 'Clase', 'Laboratorio 01', 'Activo'),
(NULL, NULL, 'Jueves', '16:15:00', '16:45:00', 'Receso', 'Patio', 'Activo'),
(6, 2, 'Jueves', '16:45:00', '19:00:00', 'Clase', 'Laboratorio 01', 'Activo'),
(7, 3, 'Viernes', '14:00:00', '16:15:00', 'Clase', 'Laboratorio 01', 'Activo'),
(NULL, NULL, 'Viernes', '16:15:00', '16:45:00', 'Receso', 'Patio', 'Activo'),
(7, 3, 'Viernes', '16:45:00', '17:30:00', 'Clase', 'Laboratorio 01', 'Activo'),
(5, 1, 'Viernes', '17:30:00', '19:00:00', 'Clase', 'Aula 01', 'Activo');

INSERT INTO configuracion
(clave, valor, nombre_configuracion, valor_configuracion, descripcion)
VALUES
('hora_inicio_sistema', '13:00:00', 'Hora de activación del sistema', '13:00:00', 'Hora desde la cual el sistema biométrico permite marcaciones.'),
('hora_entrada_oficial', '14:00:00', 'Hora oficial de entrada', '14:00:00', 'Hora base para determinar asistencia puntual o tardanza.'),
('hora_salida_oficial', '19:00:00', 'Hora oficial de salida', '19:00:00', 'Hora base para determinar salida registrada o salida anticipada.'),
('tolerancia_minutos', '15', 'Tolerancia de entrada', '15', 'Tolerancia aplicada después de la hora oficial de entrada.'),
('tolerancia_salida_minutos', '15', 'Tolerancia de salida', '15', 'Minutos adicionales permitidos para registrar la salida.'),
('minutos_tolerancia', '15', 'Minutos de tolerancia', '15', 'Clave auxiliar conservada por compatibilidad.'),
('nombre_institucion', 'Instituto Público de Chepén', 'Nombre de la institución', 'Instituto Público de Chepén', 'Nombre institucional mostrado en reportes y sistema.'),
('nombre_sistema', 'BioAsistencia', 'Nombre del sistema', 'BioAsistencia', 'Nombre comercial o interno del sistema biométrico.'),
('modo_registro', 'Huella', 'Modo de registro principal', 'Huella', 'Modo principal usado para registrar asistencia.'),
('estado_wifi', 'Pendiente', 'Estado del módulo WiFi', 'Pendiente', 'Estado informativo del ESP8266.'),
('puerto_serial', '9600', 'Velocidad serial', '9600', 'Velocidad de comunicación con Arduino Mega.');

INSERT INTO estado_dispositivo
(nombre_dispositivo, estado, ultimo_evento, ultima_conexion, estado_biometrico, estado_sensor, estado_wifi, mensaje)
VALUES
('Sistema Biométrico BioAsistencia', 'Desconectado', 'Pendiente de conexión con Arduino.', NULL, 'Estado Apagado', 'Apagado', 'Desconectado', 'Sistema pendiente de conexión.');

CREATE VIEW vw_estudiantes_huella AS
SELECT
    e.id_estudiante,
    e.codigo_estudiante,
    CONCAT(e.nombres, ' ', e.apellidos) AS estudiante,
    e.dni,
    e.correo,
    e.correo_institucional,
    e.telefono,
    e.whatsapp,
    e.programa_estudios,
    e.ciclo,
    e.turno,
    e.estado_academico,
    e.estado AS estado_estudiante,
    h.id_huella,
    h.id_sensor,
    h.dedo_registrado,
    h.estado AS estado_huella,
    p.nombre_periodo
FROM estudiantes e
INNER JOIN periodos_academicos p ON p.id_periodo = e.id_periodo
LEFT JOIN huellas h ON h.id_estudiante = e.id_estudiante;

CREATE VIEW vw_horario_semanal AS
SELECT
    h.id_horario,
    CASE h.dia_semana
        WHEN 'Lunes' THEN 1
        WHEN 'Martes' THEN 2
        WHEN 'Miércoles' THEN 3
        WHEN 'Jueves' THEN 4
        WHEN 'Viernes' THEN 5
        WHEN 'Sábado' THEN 6
        WHEN 'Domingo' THEN 7
    END AS orden_dia,
    h.dia_semana,
    h.hora_inicio,
    h.hora_fin,
    h.tipo_actividad,
    h.aula,
    c.codigo_curso,
    c.nombre_curso,
    CONCAT(d.nombres, ' ', d.apellidos) AS docente,
    d.titulo_cargo,
    h.estado
FROM horarios h
LEFT JOIN cursos c ON c.id_curso = h.id_curso
LEFT JOIN docentes d ON d.id_docente = h.id_docente;

CREATE VIEW vw_asistencia_detallada AS
SELECT
    a.id_asistencia,
    a.fecha,
    a.hora_entrada,
    a.hora_salida,
    a.estado_entrada,
    a.estado_salida,
    a.metodo_registro,
    a.observacion,
    e.id_estudiante,
    e.codigo_estudiante,
    CONCAT(e.nombres, ' ', e.apellidos) AS estudiante,
    e.programa_estudios,
    e.ciclo,
    e.turno,
    h.id_sensor,
    u.usuario AS registrado_por_usuario,
    a.fecha_registro
FROM asistencias a
INNER JOIN estudiantes e ON e.id_estudiante = a.id_estudiante
LEFT JOIN huellas h ON h.id_estudiante = e.id_estudiante
LEFT JOIN usuarios u ON u.id_usuario = a.registrado_por;

CREATE VIEW vw_resumen_asistencia_diaria AS
SELECT
    fecha,
    COUNT(*) AS total_registros,
    SUM(CASE WHEN estado_entrada = 'Puntual' THEN 1 ELSE 0 END) AS total_puntuales,
    SUM(CASE WHEN estado_entrada = 'Tardanza' THEN 1 ELSE 0 END) AS total_tardanzas,
    SUM(CASE WHEN estado_entrada IN ('Falto','Falta') THEN 1 ELSE 0 END) AS total_faltas,
    SUM(CASE WHEN estado_salida = 'Sin registro de salida' THEN 1 ELSE 0 END) AS total_salidas_pendientes,
    SUM(CASE WHEN estado_salida = 'Salida Anticipada' THEN 1 ELSE 0 END) AS total_salidas_anticipadas,
    SUM(CASE WHEN estado_salida = 'Salida Registrada' THEN 1 ELSE 0 END) AS total_salidas_registradas
FROM asistencias
GROUP BY fecha;

CREATE VIEW vw_alertas_detalladas AS
SELECT
    al.id_alerta,
    al.tipo_alerta,
    al.nivel_alerta,
    al.titulo,
    al.descripcion,
    al.porcentaje_inasistencia,
    al.nivel_riesgo,
    al.estado,
    al.fecha_alerta,
    al.fecha_atencion,
    e.codigo_estudiante,
    CONCAT(e.nombres, ' ', e.apellidos) AS estudiante,
    u.usuario AS atendido_por_usuario
FROM alertas al
INNER JOIN estudiantes e ON e.id_estudiante = al.id_estudiante
LEFT JOIN usuarios u ON u.id_usuario = al.atendido_por;

SELECT 'Base de datos bioasistencia creada correctamente' AS resultado;
SELECT COUNT(*) AS total_roles FROM roles;
SELECT COUNT(*) AS total_usuarios FROM usuarios;
SELECT COUNT(*) AS total_docentes FROM docentes;
SELECT COUNT(*) AS total_cursos FROM cursos;
SELECT COUNT(*) AS total_estudiantes FROM estudiantes;
SELECT COUNT(*) AS total_huellas FROM huellas;
SELECT COUNT(*) AS total_horarios FROM horarios;
SELECT COUNT(*) AS total_asistencias FROM asistencias;
SELECT COUNT(*) AS total_alertas FROM alertas;
SELECT COUNT(*) AS total_configuraciones FROM configuracion;
SELECT COUNT(*) AS total_dispositivos FROM estado_dispositivo;
SELECT COUNT(*) AS total_reportes FROM reportes;

SELECT
    e.id_estudiante,
    e.codigo_estudiante,
    e.apellidos,
    e.nombres,
    h.id_sensor
FROM estudiantes e
INNER JOIN huellas h ON h.id_estudiante = e.id_estudiante
ORDER BY e.id_estudiante;
