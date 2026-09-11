<?php
/*
====================================================================
 panel_api.php
 Endpoint que alimenta el panel de TV (Seguimiento de Contenedores).
 Devuelve JSON en el formato exacto que espera panel.html:

 [{ "doc":"473367", "cont":"MSCU7234891-2", "trans":"MSC Mediterranean",
    "eta":"2026-08-24", "devEst":null, "devReal":null }]

 doc     = DocImpoID (identificador interno del documento de importación)
 cont    = DocTransp (usado como número de contenedor/BL)
 trans   = inferido del prefijo de DocTransp (misma lógica que usan
           los 14 bots de consulta de ETA para diferenciar navieras)
 eta     = EtaBOT si ya tiene valor, si no EtaTRK
 devEst  = null (no tenemos ese dato en ManifiestoMuisca todavía)
 devReal = null (no tenemos ese dato en ManifiestoMuisca todavía)
====================================================================
*/

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // El TV/navegador que muestra el panel puede estar en otra IP/puerto

require __DIR__ . '/conexionDB.php'; // Ajusta la ruta si tu conexionDB.php está en otra carpeta

/**
 * Infiere el nombre de la naviera/aerolínea a partir del prefijo de
 * DocTransp, usando los mismos patrones que ya usan los 14 bots de
 * consulta de ETA para diferenciar cada transportador.
 */
function inferirTransportadora($docTransp)
{
    $doc = strtoupper(trim($docTransp));

    $patrones = [
        '/^.*DHL.*$/'                 => 'DHL',
        '/^.*PHL.*$/'                 => 'DHL',
        '/^.*HLCU.*$/'                => 'Hapag-Lloyd',
        '/^729-?/'                    => 'Avianca Cargo',
        '/^.*FDX.*$/'                 => 'FedEx',
        '/^.*FEDEX.*$/'               => 'FedEx',
        '/^OOLU/'                     => 'OOCL',
        '/^(MAEU|MRKU|MSKU|MRSU|MNBU|PONU)/' => 'Maersk',
        '/^.*EGLV.*$/'                => 'Evergreen',
        '/^(020|220)-?/'              => 'Lufthansa Cargo',
        '/^(XIA|TDH|SWA|SU|SGN|SEL|GGZ|CN|CHN|AYN)/' => 'CMA CGM',
        '/^COSU/'                     => 'COSCO Shipping',
        '/^045-?/'                    => 'Latam Cargo',
        '/^MEDU/'                     => 'MSC Mediterranean',
        '/^(074|057)-?/'              => 'AFKL (KLM/Air France)',
        '/^ONEY?/'                    => 'Ocean Network Express',
    ];

    foreach ($patrones as $regex => $nombre) {
        if (preg_match($regex, $doc)) {
            return $nombre;
        }
    }

    return 'Sin identificar';
}

/**
 * Convierte un valor de fecha de SQL Server (DateTime object o string)
 * a "YYYY-MM-DD" (o null si viene vacío/ausente).
 */
function formatearFecha($valor)
{
    if (empty($valor)) {
        return null;
    }

    if ($valor instanceof DateTime) {
        return $valor->format('Y-m-d');
    }

    // Si ya es un string, intenta quedarse solo con la parte de fecha.
    $texto = (string) $valor;
    return substr($texto, 0, 10);
}

try {

    // Mismo criterio que usan los bots: sin manifestar, y con ETA
    // dentro del filtro de julio en adelante (mismo año actual).
    $sql = "
        SELECT
            DocImpoID,
            DocTransp,
            EtaTRK,
            EtaBOT
        FROM [BotRepecev].[dbo].[ManifiestoMuisca]
        WHERE
            NoManif IS NULL
            AND DocTransp IS NOT NULL
            AND DocTransp <> ''
            AND EtaTRK >= DATEFROMPARTS(YEAR(GETDATE()), 7, 1)
        ORDER BY EtaTRK ASC
    ";

    $stmt = sqlsrv_query($conn, $sql);

    if ($stmt === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Error en la consulta', 'detalle' => sqlsrv_errors()]);
        exit;
    }

    $resultado = [];

    while ($fila = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {

        $etaBot = formatearFecha($fila['EtaBOT'] ?? null);
        $etaTrk = formatearFecha($fila['EtaTRK'] ?? null);

        // Si EtaBOT nunca se actualizó (queda en 1000-01-01), usar EtaTRK.
        $eta = ($etaBot && $etaBot !== '1000-01-01') ? $etaBot : $etaTrk;

        $transportadora = inferirTransportadora($fila['DocTransp'] ?? '');

        // Solo se incluyen las filas donde SI se pudo identificar la
        // naviera/aerolinea -- se descartan las que quedarian como
        // "Sin identificar".
        if ($transportadora === 'Sin identificar') {
            continue;
        }

        // Solo se muestran las que TODAVIA no han llegado (ETA hoy o en
        // el futuro) -- se descartan las que ya deberian haber arribado.
        $hoy = date('Y-m-d');
        if ($eta === null || $eta < $hoy) {
            continue;
        }

        $resultado[] = [
            'doc'     => (string) ($fila['DocImpoID'] ?? ''),
            'cont'    => $fila['DocTransp'] ?? '',
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