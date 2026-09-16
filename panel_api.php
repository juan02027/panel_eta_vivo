<?php
/*
====================================================================
 panel_api.php
 ?doccargo=0 → Repecev  (SQL Server)
 ?doccargo=1 → Cargo    (API externa apps.abcrepecev.com)
====================================================================
*/

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// ── Validar parámetro ──────────────────────────────────────────
$doccargo = isset($_GET['doccargo']) ? (int)$_GET['doccargo'] : 0;
$doccargo = ($doccargo === 1) ? 1 : 0;

// ── Helpers ────────────────────────────────────────────────────
function fmtFecha($val) {
    if (empty($val)) return null;
    return substr(trim($val), 0, 10); // "2026-08-24 00:00:00" → "2026-08-24"
}

// ══════════════════════════════════════════════════════════════
//  CARGO — API externa
// ══════════════════════════════════════════════════════════════
if ($doccargo === 1) {

    define('CARGO_URL',  'https://apps.abcrepecev.com:1901/api-abc/controldata.php');
    define('CARGO_CRED', 'ControlOP:d679551a0b7f3e4ffd61f');

    function cargoRequest($headers, $body = null) {
        // Construir cabeceras en formato string
        $hdrs = [];
        foreach ($headers as $h) $hdrs[] = $h;

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", $hdrs),
                'content'       => $body ?? '',
                'timeout'       => 15,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);

        $res = @file_get_contents(CARGO_URL, false, $context);
        if ($res === false) {
            $err = error_get_last();
            throw new Exception('Error de conexión: ' . ($err['message'] ?? 'desconocido'));
        }
        return trim($res);
    }

    try {
        // 1. Obtener token
        $token = cargoRequest([
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . base64_encode(CARGO_CRED),
        ]);

        if (empty($token)) throw new Exception('No se obtuvo token de la API de Cargo');

        // 2. Obtener contenedores
        $raw = cargoRequest([
            'Content-Type: application/x-www-form-urlencoded',
            'TK: ' . $token,
        ], 'ConsultaCargoContenedores=ASC');

        // Limpiar caracteres de control (igual que hace el JS)
        $raw  = preg_replace('/[\x00-\x1F\x7F]/', '', $raw);
        $json = json_decode($raw, true);

        if (!isset($json['data'])) {
            throw new Exception('La API de Cargo no devolvió el campo "data". Respuesta: ' . substr($raw, 0, 200));
        }

        $hoy       = date('Y-m-d');
        $resultado = [];

        foreach ($json['data'] as $row) {
            $eta     = fmtFecha($row['DocOperFechaETA'] ?? null);
            $devEst  = fmtFecha($row['FechaLimitDev']   ?? null);
            $devReal = fmtFecha($row['FechaDevolucion']  ?? null);

            // Mostrar solo ETAs de hoy en adelante (sin devolver aún)
            // o los que ya fueron devueltos en los últimos 7 días
            if (!empty($devReal)) {
                if ($devReal < date('Y-m-d', strtotime('-7 days'))) continue;
            } else {
                if (empty($eta) || $eta < $hoy) continue;
            }

            $resultado[] = [
                'doc'     => trim($row['DocOperNoDocTransp'] ?? ''),
                'cont'    => trim($row['DConteNumero']        ?? ''),
                'trans'   => trim($row['DocOperNaviera']      ?? ''),
                'eta'     => $eta,
                'devEst'  => $devEst,
                'devReal' => $devReal,
            ];
        }

        echo json_encode($resultado, JSON_UNESCAPED_UNICODE);

    } catch (Exception $ex) {
        http_response_code(500);
        echo json_encode(['error' => $ex->getMessage()]);
    }

// ══════════════════════════════════════════════════════════════
//  REPECEV — SQL Server
// ══════════════════════════════════════════════════════════════
} else {

    require __DIR__ . '/conexionDB.php';

    function inferirTransportadora($docTransp) {
        $doc = strtoupper(trim($docTransp));
        $patrones = [
            '/^.*DHL.*$/'                          => 'DHL',
            '/^.*PHL.*$/'                          => 'DHL',
            '/^.*HLCU.*$/'                         => 'Hapag-Lloyd',
            '/^729-?/'                             => 'Avianca Cargo',
            '/^.*FDX.*$/'                          => 'FedEx',
            '/^.*FEDEX.*$/'                        => 'FedEx',
            '/^OOLU/'                              => 'OOCL',
            '/^(MAEU|MRKU|MSKU|MRSU|MNBU|PONU)/'  => 'Maersk',
            '/^.*EGLV.*$/'                         => 'Evergreen',
            '/^(020|220)-?/'                       => 'Lufthansa Cargo',
            '/^(XIA|TDH|SWA|SU|SGN|SEL|GGZ|CN|CHN|AYN)/' => 'CMA CGM',
            '/^COSU/'                              => 'COSCO Shipping',
            '/^045-?/'                             => 'Latam Cargo',
            '/^MEDU/'                              => 'MSC Mediterranean',
            '/^(074|057)-?/'                       => 'AFKL (KLM/Air France)',
            '/^ONEY?/'                             => 'Ocean Network Express',
        ];
        foreach ($patrones as $regex => $nombre) {
            if (preg_match($regex, $doc)) return $nombre;
        }
        return 'Sin identificar';
    }

    function formatearFecha($valor) {
        if (empty($valor)) return null;
        if ($valor instanceof DateTime) return $valor->format('Y-m-d');
        return substr((string)$valor, 0, 10);
    }

    try {
        $sql = "
            SELECT DocImpoID, DocTransp, EtaTRK, EtaBOT
            FROM [BotRepecev].[dbo].[ManifiestoMuisca]
            WHERE
                NoManif IS NULL
                AND DocTransp IS NOT NULL
                AND DocTransp <> ''
                AND EtaTRK >= DATEFROMPARTS(YEAR(GETDATE()), 7, 1)
                AND DocCargo = 0
            ORDER BY EtaTRK ASC
        ";

        $stmt = sqlsrv_query($conn, $sql);

        if ($stmt === false) {
            http_response_code(500);
            echo json_encode(['error' => 'Error en la consulta', 'detalle' => sqlsrv_errors()]);
            exit;
        }

        $resultado = [];
        $hoy = date('Y-m-d');

        while ($fila = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $etaBot = formatearFecha($fila['EtaBOT'] ?? null);
            $etaTrk = formatearFecha($fila['EtaTRK'] ?? null);
            $eta    = ($etaBot && $etaBot !== '1000-01-01') ? $etaBot : $etaTrk;

            $transportadora = inferirTransportadora($fila['DocTransp'] ?? '');
            if ($transportadora === 'Sin identificar') continue;
            if ($eta === null || $eta < $hoy) continue;

            $resultado[] = [
                'doc'     => $fila['DocTransp'] ?? '',
                'cont'    => '',
                'trans'   => $transportadora,
                'eta'     => $eta,
                'devEst'  => null,
                'devReal' => null,
            ];
        }

        sqlsrv_free_stmt($stmt);
        sqlsrv_close($conn);

        echo json_encode($resultado, JSON_UNESCAPED_UNICODE);

    } catch (Exception $ex) {
        http_response_code(500);
        echo json_encode(['error' => $ex->getMessage()]);
    }
}