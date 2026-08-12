<?php
date_default_timezone_set('America/Lima');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/funciones.php';

requerir_rol(['Administrador', 'Docente']);

function fecha_valida_excel(string $fecha): bool
{
    $partes = explode('-', $fecha);

    if (count($partes) !== 3) {
        return false;
    }

    return checkdate((int) $partes[1], (int) $partes[2], (int) $partes[0]);
}

function escapar_xml_excel(?string $texto): string
{
    $texto = (string) $texto;
    $texto = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $texto);
    $texto = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $texto);

    return htmlspecialchars($texto, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
}

function limpiar_fecha_excel(string $fecha, string $fechaDefecto): string
{
    $fecha = limpiar_texto($fecha);

    return fecha_valida_excel($fecha) ? $fecha : $fechaDefecto;
}

function nombre_dia_espanol(string $fecha): string
{
    $dias = [
        'Monday' => 'Lunes',
        'Tuesday' => 'Martes',
        'Wednesday' => 'Miércoles',
        'Thursday' => 'Jueves',
        'Friday' => 'Viernes',
        'Saturday' => 'Sábado',
        'Sunday' => 'Domingo'
    ];

    $diaIngles = date('l', strtotime($fecha));

    return $dias[$diaIngles] ?? $diaIngles;
}

function construir_celda_xml(string $columna, int $fila, string $valor, int $estilo): string
{
    return '<c r="' . $columna . $fila . '" t="inlineStr" s="' . $estilo . '">' .
           '<is><t xml:space="preserve">' . escapar_xml_excel($valor) . '</t></is></c>';
}

function tipo_reporte_excel(string $inicio, string $fin): string
{
    $fechaInicio = new DateTimeImmutable($inicio);
    $fechaFin = new DateTimeImmutable($fin);

    if ($inicio === $fin) {
        return 'Diario';
    }

    $dias = (int) $fechaInicio->diff($fechaFin)->format('%a');

    if ((int) $fechaInicio->format('N') === 1 && (int) $fechaFin->format('N') === 5 && $dias === 4) {
        return 'Semanal';
    }

    return 'Personalizado';
}

function id_usuario_reporte_excel(): ?int
{
    if (function_exists('obtener_id_usuario')) {
        $id = (int) obtener_id_usuario();
        return $id > 0 ? $id : null;
    }

    foreach (['id_usuario', 'usuario_id'] as $clave) {
        if (isset($_SESSION[$clave]) && (int) $_SESSION[$clave] > 0) {
            return (int) $_SESSION[$clave];
        }
    }

    return null;
}

function texto_archivo_excel(string $texto): string
{
    $texto = preg_replace('/[^A-Za-z0-9_\-]+/u', '_', $texto);
    $texto = trim((string) $texto, '_');

    return $texto !== '' ? $texto : 'Reporte';
}

$inicioDefecto = date('Y-m-d', strtotime('monday this week'));
$finDefecto = date('Y-m-d', strtotime('friday this week'));

$inicioSemana = limpiar_fecha_excel($_GET['inicio'] ?? $inicioDefecto, $inicioDefecto);
$finSemana = limpiar_fecha_excel($_GET['fin'] ?? $finDefecto, $finDefecto);

if (strtotime($inicioSemana) > strtotime($finSemana)) {
    $fechaTemporal = $inicioSemana;
    $inicioSemana = $finSemana;
    $finSemana = $fechaTemporal;
}

$consultaReporte = $pdo->prepare(
    "SELECT a.id_asistencia,
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
            e.programa_estudios,
            COALESCE(NULLIF(SUBSTRING_INDEX(p.nombre_periodo, ' ', 1), ''), e.ciclo) AS ciclo_reporte,
            COALESCE(p.nombre_periodo, 'Sin periodo académico') AS periodo_academico,
            h.id_sensor AS id_huella
     FROM asistencias a
     INNER JOIN estudiantes e ON a.id_estudiante = e.id_estudiante
     LEFT JOIN periodos_academicos p
        ON a.fecha BETWEEN p.fecha_inicio AND p.fecha_fin
     LEFT JOIN (
         SELECT id_estudiante, MIN(id_sensor) AS id_sensor
         FROM huellas
         WHERE estado = 'Activa'
         GROUP BY id_estudiante
     ) h ON e.id_estudiante = h.id_estudiante
     WHERE a.fecha BETWEEN :inicio_semana AND :fin_semana
     ORDER BY a.fecha ASC, e.codigo_estudiante ASC"
);

$consultaReporte->execute([
    'inicio_semana' => $inicioSemana,
    'fin_semana' => $finSemana
]);

$filas = $consultaReporte->fetchAll();

$periodosReporte = [];
foreach ($filas as $filaPeriodo) {
    $periodoFila = trim((string) ($filaPeriodo['periodo_academico'] ?? ''));
    if ($periodoFila !== '' && $periodoFila !== 'Sin periodo académico') {
        $periodosReporte[$periodoFila] = true;
    }
}

if (count($periodosReporte) === 1) {
    $etiquetaPeriodo = array_key_first($periodosReporte);
} elseif (count($periodosReporte) > 1) {
    $etiquetaPeriodo = 'Varios periodos';
} else {
    $consultaPeriodoRango = $pdo->prepare(
        "SELECT nombre_periodo
         FROM periodos_academicos
         WHERE fecha_inicio <= :fin
         AND fecha_fin >= :inicio
         ORDER BY fecha_inicio ASC"
    );
    $consultaPeriodoRango->execute([
        'inicio' => $inicioSemana,
        'fin' => $finSemana
    ]);
    $periodosRango = $consultaPeriodoRango->fetchAll(PDO::FETCH_COLUMN);

    if (count($periodosRango) === 1) {
        $etiquetaPeriodo = (string) $periodosRango[0];
    } elseif (count($periodosRango) > 1) {
        $etiquetaPeriodo = 'Varios periodos';
    } else {
        $etiquetaPeriodo = 'Sin periodo académico';
    }
}

$encabezados = [
    'N°',
    'Código de estudiante',
    'Estudiante',
    'Programa de estudios',
    'Ciclo',
    'Periodo académico',
    'ID Huella',
    'Fecha',
    'Día',
    'Hora de entrada',
    'Estado de entrada',
    'Hora de salida',
    'Estado de salida',
    'Método',
    'Observación'
];

$columnas = [
    'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O'
];

$filasXml = '';
$numeroFila = 1;

$filasXml .= '<row r="' . $numeroFila . '" ht="28" customHeight="1">';
$filasXml .= construir_celda_xml('A', $numeroFila, 'Reporte de Asistencias - Computación e Informática - ' . $etiquetaPeriodo, 1);
$filasXml .= '</row>';
$numeroFila++;

$filasXml .= '<row r="' . $numeroFila . '" ht="22" customHeight="1">';
$filasXml .= construir_celda_xml('A', $numeroFila, 'Rango: ' . $inicioSemana . ' al ' . $finSemana, 2);
$filasXml .= '</row>';
$numeroFila++;

$filasXml .= '<row r="' . $numeroFila . '" ht="22" customHeight="1">';
$filasXml .= construir_celda_xml('A', $numeroFila, 'Generado: ' . date('d/m/Y H:i:s'), 2);
$filasXml .= '</row>';
$numeroFila += 2;

$filaEncabezado = $numeroFila;
$filasXml .= '<row r="' . $numeroFila . '" ht="24" customHeight="1">';

foreach ($encabezados as $indice => $texto) {
    $filasXml .= construir_celda_xml($columnas[$indice], $numeroFila, $texto, 3);
}

$filasXml .= '</row>';
$numeroFila++;

$contador = 1;
$totalPuntuales = 0;
$totalTardanzas = 0;
$totalFaltas = 0;

foreach ($filas as $registro) {
    if ($registro['estado_entrada'] === 'Puntual') {
        $totalPuntuales++;
    }

    if ($registro['estado_entrada'] === 'Tardanza') {
        $totalTardanzas++;
    }

    if (in_array($registro['estado_entrada'], ['Falto', 'Falta'], true)) {
        $totalFaltas++;
    }

    $valores = [
        (string) $contador,
        $registro['codigo_estudiante'] ?? '',
        trim(($registro['nombres'] ?? '') . ' ' . ($registro['apellidos'] ?? '')),
        $registro['programa_estudios'] ?? '',
        $registro['ciclo_reporte'] ?? '',
        $registro['periodo_academico'] ?? 'Sin periodo académico',
        !empty($registro['id_huella']) ? (string) $registro['id_huella'] : 'Sin asignar',
        $registro['fecha'] ?? '',
        !empty($registro['fecha']) ? nombre_dia_espanol($registro['fecha']) : '',
        !empty($registro['hora_entrada']) ? substr($registro['hora_entrada'], 0, 5) : '-',
        $registro['estado_entrada'] ?: 'Falto',
        !empty($registro['hora_salida']) ? substr($registro['hora_salida'], 0, 5) : '-',
        $registro['estado_salida'] ?: '-',
        $registro['metodo_registro'] ?: '-',
        $registro['observacion'] ?: ''
    ];

    $filasXml .= '<row r="' . $numeroFila . '" ht="20" customHeight="1">';

    foreach ($valores as $indice => $valor) {
        $filasXml .= construir_celda_xml($columnas[$indice], $numeroFila, (string) $valor, 4);
    }

    $filasXml .= '</row>';

    $numeroFila++;
    $contador++;
}

$numeroFila++;

$filasXml .= '<row r="' . $numeroFila . '" ht="22" customHeight="1">';
$filasXml .= construir_celda_xml('A', $numeroFila, 'Resumen del reporte', 2);
$filasXml .= '</row>';
$numeroFila++;

$resumenes = [
    ['Total de registros', (string) count($filas)],
    ['Puntuales', (string) $totalPuntuales],
    ['Tardanzas', (string) $totalTardanzas],
    ['Faltas', (string) $totalFaltas]
];

foreach ($resumenes as $resumen) {
    $filasXml .= '<row r="' . $numeroFila . '" ht="20" customHeight="1">';
    $filasXml .= construir_celda_xml('A', $numeroFila, $resumen[0], 5);
    $filasXml .= construir_celda_xml('B', $numeroFila, $resumen[1], 4);
    $filasXml .= '</row>';
    $numeroFila++;
}

$ultimaFila = max(1, $numeroFila - 1);

$anchoColumnas = '<cols>' .
    '<col min="1" max="1" width="6" customWidth="1"/>' .
    '<col min="2" max="2" width="18" customWidth="1"/>' .
    '<col min="3" max="3" width="34" customWidth="1"/>' .
    '<col min="4" max="4" width="26" customWidth="1"/>' .
    '<col min="5" max="5" width="11" customWidth="1"/>' .
    '<col min="6" max="6" width="22" customWidth="1"/>' .
    '<col min="7" max="7" width="12" customWidth="1"/>' .
    '<col min="8" max="8" width="13" customWidth="1"/>' .
    '<col min="9" max="9" width="13" customWidth="1"/>' .
    '<col min="10" max="10" width="15" customWidth="1"/>' .
    '<col min="11" max="11" width="18" customWidth="1"/>' .
    '<col min="12" max="12" width="15" customWidth="1"/>' .
    '<col min="13" max="13" width="18" customWidth="1"/>' .
    '<col min="14" max="14" width="13" customWidth="1"/>' .
    '<col min="15" max="15" width="34" customWidth="1"/>' .
'</cols>';

$hojaXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
    '<sheetPr/>' .
    '<dimension ref="A1:O' . $ultimaFila . '"/>' .
    '<sheetViews>' .
        '<sheetView workbookViewId="0" showGridLines="1">' .
            '<pane ySplit="5" topLeftCell="A6" activePane="bottomLeft" state="frozen"/>' .
            '<selection pane="bottomLeft" activeCell="A6" sqref="A6"/>' .
        '</sheetView>' .
    '</sheetViews>' .
    '<sheetFormatPr defaultRowHeight="18"/>' .
    $anchoColumnas .
    '<sheetData>' . $filasXml . '</sheetData>' .
    '<autoFilter ref="A' . $filaEncabezado . ':O' . $filaEncabezado . '"/>' .
    '<mergeCells count="3">' .
        '<mergeCell ref="A1:O1"/>' .
        '<mergeCell ref="A2:O2"/>' .
        '<mergeCell ref="A3:O3"/>' .
    '</mergeCells>' .
    '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>' .
'</worksheet>';

$stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
    '<fonts count="5">' .
        '<font><sz val="11"/><name val="Calibri"/></font>' .
        '<font><b/><sz val="16"/><color rgb="FF0F172A"/><name val="Calibri"/></font>' .
        '<font><b/><sz val="11"/><color rgb="FF0F172A"/><name val="Calibri"/></font>' .
        '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>' .
        '<font><b/><sz val="11"/><color rgb="FF1E293B"/><name val="Calibri"/></font>' .
    '</fonts>' .
    '<fills count="5">' .
        '<fill><patternFill patternType="none"/></fill>' .
        '<fill><patternFill patternType="gray125"/></fill>' .
        '<fill><patternFill patternType="solid"><fgColor rgb="FF2563EB"/><bgColor indexed="64"/></patternFill></fill>' .
        '<fill><patternFill patternType="solid"><fgColor rgb="FFEFF6FF"/><bgColor indexed="64"/></patternFill></fill>' .
        '<fill><patternFill patternType="solid"><fgColor rgb="FFF8FAFC"/><bgColor indexed="64"/></patternFill></fill>' .
    '</fills>' .
    '<borders count="2">' .
        '<border><left/><right/><top/><bottom/><diagonal/></border>' .
        '<border>' .
            '<left style="thin"><color rgb="FFCBD5E1"/></left>' .
            '<right style="thin"><color rgb="FFCBD5E1"/></right>' .
            '<top style="thin"><color rgb="FFCBD5E1"/></top>' .
            '<bottom style="thin"><color rgb="FFCBD5E1"/></bottom>' .
            '<diagonal/>' .
        '</border>' .
    '</borders>' .
    '<cellStyleXfs count="1">' .
        '<xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>' .
    '</cellStyleXfs>' .
    '<cellXfs count="6">' .
        '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' .
        '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>' .
        '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>' .
        '<xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="center" vertical="center"/></xf>' .
        '<xf numFmtId="0" fontId="0" fillId="4" borderId="1" xfId="0" applyFill="1" applyBorder="1"><alignment vertical="center"/></xf>' .
        '<xf numFmtId="0" fontId="4" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment vertical="center"/></xf>' .
    '</cellXfs>' .
    '<cellStyles count="1">' .
        '<cellStyle name="Normal" xfId="0" builtinId="0"/>' .
    '</cellStyles>' .
'</styleSheet>';

$workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
    '<sheets>' .
        '<sheet name="Reporte" sheetId="1" r:id="rId1"/>' .
    '</sheets>' .
'</workbook>';

$workbookRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
    '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
    '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
'</Relationships>';

$contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
    '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
    '<Default Extension="xml" ContentType="application/xml"/>' .
    '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
    '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
    '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
'</Types>';

$rootRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
    '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
'</Relationships>';

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    echo 'ZipArchive no está habilitado en PHP. Activa la extensión zip para exportar reportes.';
    exit;
}

$tipoReporte = tipo_reporte_excel($inicioSemana, $finSemana);
$nombreReporte = 'Reporte de asistencias - ' . $inicioSemana . ' al ' . $finSemana;
$nombreArchivo = 'Reporte_Asistencias_' . texto_archivo_excel($etiquetaPeriodo) . '_' . $inicioSemana . '_a_' . $finSemana . '.xlsx';
$rutaTemporal = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('bioasistencia_', true) . '.xlsx';

$archivoZip = new ZipArchive();

if ($archivoZip->open($rutaTemporal, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    echo 'No se pudo generar el archivo Excel.';
    exit;
}

$archivoZip->addEmptyDir('_rels');
$archivoZip->addEmptyDir('xl');
$archivoZip->addEmptyDir('xl/_rels');
$archivoZip->addEmptyDir('xl/worksheets');
$archivoZip->addFromString('[Content_Types].xml', $contentTypesXml);
$archivoZip->addFromString('_rels/.rels', $rootRelsXml);
$archivoZip->addFromString('xl/workbook.xml', $workbookXml);
$archivoZip->addFromString('xl/_rels/workbook.xml.rels', $workbookRelsXml);
$archivoZip->addFromString('xl/styles.xml', $stylesXml);
$archivoZip->addFromString('xl/worksheets/sheet1.xml', $hojaXml);
$archivoZip->close();

if (!is_file($rutaTemporal) || filesize($rutaTemporal) <= 0) {
    @unlink($rutaTemporal);
    http_response_code(500);
    echo 'El archivo Excel no se generó correctamente.';
    exit;
}

try {
    $idUsuario = id_usuario_reporte_excel();

    $consultaExistente = $pdo->prepare(
        "SELECT id_reporte
         FROM reportes
         WHERE (fecha_inicio = :inicio_fecha AND fecha_fin = :fin_fecha)
            OR (semana_inicio = :inicio_semana AND semana_fin = :fin_semana)
         ORDER BY id_reporte ASC
         LIMIT 1"
    );

    $consultaExistente->execute([
        'inicio_fecha' => $inicioSemana,
        'fin_fecha' => $finSemana,
        'inicio_semana' => $inicioSemana,
        'fin_semana' => $finSemana
    ]);

    $idReporteExistente = (int) ($consultaExistente->fetchColumn() ?: 0);

    if ($idReporteExistente > 0) {
        $actualizarReporte = $pdo->prepare(
            "UPDATE reportes
             SET nombre_reporte = :nombre_reporte,
                 tipo_reporte = :tipo_reporte,
                 fecha_inicio = :fecha_inicio,
                 fecha_fin = :fecha_fin,
                 semana_inicio = :semana_inicio,
                 semana_fin = :semana_fin,
                 generado_por = COALESCE(:generado_por, generado_por),
                 ruta_archivo = :ruta_archivo,
                 fecha_generado = NOW()
             WHERE id_reporte = :id_reporte"
        );

        $actualizarReporte->execute([
            'nombre_reporte' => $nombreReporte,
            'tipo_reporte' => $tipoReporte,
            'fecha_inicio' => $inicioSemana,
            'fecha_fin' => $finSemana,
            'semana_inicio' => $inicioSemana,
            'semana_fin' => $finSemana,
            'generado_por' => $idUsuario,
            'ruta_archivo' => $nombreArchivo,
            'id_reporte' => $idReporteExistente
        ]);
    } else {
        $registrarReporte = $pdo->prepare(
            "INSERT INTO reportes
             (nombre_reporte, tipo_reporte, fecha_inicio, fecha_fin,
              semana_inicio, semana_fin, generado_por, ruta_archivo, fecha_generado)
             VALUES
             (:nombre_reporte, :tipo_reporte, :fecha_inicio, :fecha_fin,
              :semana_inicio, :semana_fin, :generado_por, :ruta_archivo, NOW())"
        );

        $registrarReporte->execute([
            'nombre_reporte' => $nombreReporte,
            'tipo_reporte' => $tipoReporte,
            'fecha_inicio' => $inicioSemana,
            'fecha_fin' => $finSemana,
            'semana_inicio' => $inicioSemana,
            'semana_fin' => $finSemana,
            'generado_por' => $idUsuario,
            'ruta_archivo' => $nombreArchivo
        ]);
    }
} catch (Throwable $e) {
    @unlink($rutaTemporal);
    http_response_code(500);
    echo 'El Excel fue generado, pero no se pudo registrar el historial del reporte.';
    exit;
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"; filename*=UTF-8\'\'' . rawurlencode($nombreArchivo));
header('Content-Length: ' . filesize($rutaTemporal));
header('Cache-Control: max-age=0');
header('Pragma: public');

readfile($rutaTemporal);
unlink($rutaTemporal);
exit;