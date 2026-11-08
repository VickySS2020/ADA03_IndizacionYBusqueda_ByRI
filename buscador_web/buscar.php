<?php
declare(strict_types=1);
require_once __DIR__ . '/config/conexion.php';

function normalizarConsulta(string $texto): array {
    $texto = function_exists('mb_strtolower') ? mb_strtolower($texto, 'UTF-8') : strtolower($texto);
    preg_match_all('/[\p{L}\p{N}]+/u', $texto, $m);
    return $m[0] ?? [];
}
function pesoTf(int $frecuencia): float {
    return $frecuencia > 0 ? 1.0 + log($frecuencia) : 0.0;
}

$consultaOriginal = trim((string)($_GET['q'] ?? ''));
$terminosConsulta = normalizarConsulta($consultaOriginal);
$resultados = [];
$mensaje = '';

if ($consultaOriginal === '' || !$terminosConsulta) {
    $mensaje = 'Escribe una consulta que contenga al menos un término.';
} else {
    $qtf = array_count_values($terminosConsulta);
    $N = (int)$conexion->query("SELECT COUNT(*) AS n FROM Documento")->fetch_assoc()['n'];

    // Se recuperan postings y metadatos para calcular el vector TF-IDF de cada documento.
    $sql = "SELECT p.id_documento, p.frecuencia, p.fragmento, v.token, v.documentos AS df,
                   d.archivo, d.url
            FROM Posting p
            JOIN Vocabulario v ON v.id_token = p.id_token
            JOIN Documento d ON d.id_documento = p.id_documento";
    $filas = $conexion->query($sql)->fetch_all(MYSQLI_ASSOC);

    $idf = [];
    $vectores = [];
    $metadatos = [];
    foreach ($filas as $fila) {
        $token = $fila['token'];
        $docId = (int)$fila['id_documento'];
        $idf[$token] = log(($N + 1) / ((int)$fila['df'] + 1)) + 1.0;
        $vectores[$docId][$token] = pesoTf((int)$fila['frecuencia']) * $idf[$token];
        $metadatos[$docId] = [
            'archivo' => $fila['archivo'],
            'url' => $fila['url']
        ];
        if (isset($qtf[$token])) {
            $metadatos[$docId]['fragmentos'][$token] = $fila['fragmento'];
        }
    }

    $vectorConsulta = [];
    foreach ($qtf as $token => $frecuencia) {
        if (isset($idf[$token])) {
            $vectorConsulta[$token] = pesoTf((int)$frecuencia) * $idf[$token];
        }
    }

    if (!$vectorConsulta) {
        $mensaje = 'No se encontraron documentos que contengan los términos de la consulta.';
    } else {
        $normaConsulta = sqrt(array_sum(array_map(fn($x) => $x * $x, $vectorConsulta)));
        foreach ($vectores as $docId => $vectorDoc) {
            $producto = 0.0;
            foreach ($vectorConsulta as $token => $peso) {
                $producto += $peso * ($vectorDoc[$token] ?? 0.0);
            }
            if ($producto <= 0) continue;
            $normaDoc = sqrt(array_sum(array_map(fn($x) => $x * $x, $vectorDoc)));
            $coseno = ($normaConsulta > 0 && $normaDoc > 0) ? $producto / ($normaConsulta * $normaDoc) : 0.0;
            $fragmentos = $metadatos[$docId]['fragmentos'] ?? [];
            $resultados[] = [
                'archivo' => $metadatos[$docId]['archivo'],
                'url' => $metadatos[$docId]['url'],
                'coseno' => $coseno,
                'fragmento' => reset($fragmentos) ?: ''
            ];
        }
        usort($resultados, fn($a, $b) => $b['coseno'] <=> $a['coseno']);
        if (!$resultados) $mensaje = 'No se encontraron documentos que coincidan con la consulta.';
    }
}
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Resultados de búsqueda</title><link rel="stylesheet" href="estilos.css"></head>
<body>
<header class="encabezado"><div class="contenedor"><h1>Resultados de búsqueda</h1><p>Consulta: <?= htmlspecialchars($consultaOriginal, ENT_QUOTES, 'UTF-8') ?></p></div></header>
<main class="contenedor">
<section class="panel">
<form action="buscar.php" method="get"><label for="q">Modificar consulta</label><div class="fila"><input type="search" id="q" name="q" value="<?= htmlspecialchars($consultaOriginal, ENT_QUOTES, 'UTF-8') ?>" required><button type="submit">Buscar</button></div></form>
<?php if ($mensaje !== ''): ?><p class="aviso"><?= htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<p><?= count($resultados) ?> documento(s) encontrado(s).</p>
<?php foreach ($resultados as $r): ?>
<article class="resultado">
  <h2><a href="descargar.php?archivo=<?= rawurlencode(basename($r['url'])) ?>"><?= htmlspecialchars($r['archivo'], ENT_QUOTES, 'UTF-8') ?></a></h2>
  <p><strong>Similitud Coseno:</strong> <?= number_format($r['coseno'], 6) ?></p>
  <p><strong>Fragmento:</strong> <?= htmlspecialchars($r['fragmento'], ENT_QUOTES, 'UTF-8') ?></p>
  <a href="descargar.php?archivo=<?= rawurlencode(basename($r['url'])) ?>">Descargar archivo</a>
</article>
<?php endforeach; ?>
<p><a href="index.php">Volver al inicio</a></p>
</section></main></body></html>
