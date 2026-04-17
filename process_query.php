<?php
set_time_limit(300);
ini_set('memory_limit', '1G');

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store, no-cache, must-revalidate, private');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['error' => 'Método não permitido.']));
}

// Validate Content-Type
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($contentType, 'application/json') === false) {
    http_response_code(400);
    die(json_encode(['error' => 'Content-Type inválido.']));
}

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || !is_array($data)) {
    http_response_code(400);
    die(json_encode(['error' => 'Payload inválido.']));
}

// Input length validation
$maxLengths = [
    'dbHost'      => 253,
    'dbName'      => 64,
    'dbUser'      => 64,
    'dbPass'      => 128,
    'filterGroup' => 100,
    'filterHost'  => 100,
];
foreach ($maxLengths as $field => $max) {
    if (isset($data[$field]) && strlen((string)$data[$field]) > $max) {
        http_response_code(400);
        die(json_encode(['error' => "Valor inválido para o campo '$field'."]));
    }
}

$db_host = trim($data['dbHost'] ?? '');
$db_name = trim($data['dbName'] ?? '');
$db_user = trim($data['dbUser'] ?? '');
$db_pass = $data['dbPass'] ?? '';
$start   = $data['dateStart'] ?? '';
$end     = $data['dateEnd'] ?? '';
$fGroup  = trim($data['filterGroup'] ?? '');
$fHost   = trim($data['filterHost'] ?? '');

// Validate required fields
if (empty($db_host) || empty($db_name) || empty($db_user)) {
    http_response_code(400);
    die(json_encode(['error' => 'Host, banco e usuário são obrigatórios.']));
}

// Validate db_host to prevent DSN injection — only valid hostname/IP chars allowed
if (!preg_match('/^[a-zA-Z0-9.\-]{1,253}$/', $db_host)) {
    http_response_code(400);
    die(json_encode(['error' => 'Host de banco de dados inválido.']));
}

// Validate db_name to prevent DSN injection
if (!preg_match('/^[a-zA-Z0-9_\-]{1,64}$/', $db_name)) {
    http_response_code(400);
    die(json_encode(['error' => 'Nome do banco de dados inválido.']));
}

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    $tsStart = strtotime($start);
    $tsEnd   = strtotime($end);
    $diff    = $tsEnd - $tsStart;

    if ($tsStart === false || $tsEnd === false || $diff <= 0) {
        throw new Exception("Intervalo de data inválido.");
    }

    // Prevent excessively large queries (max 1 year)
    if ($diff > 31536000) {
        throw new Exception("Intervalo máximo permitido é de 1 ano.");
    }

    $params = [':start' => $tsStart, ':end' => $tsEnd];
    $where = [];

    if (!empty($fHost)) {
        $where[] = "h.name LIKE :fHost";
        $params[':fHost'] = "%$fHost%";
    }

    if (!empty($fGroup)) {
        $where[] = "EXISTS (
            SELECT 1 FROM hosts_groups hg
            INNER JOIN hstgrp g ON hg.groupid = g.groupid
            WHERE hg.hostid = h.hostid AND g.name LIKE :fGroup
        )";
        $params[':fGroup'] = "%$fGroup%";
    }

    $where[] = "h.status = 0"; // Apenas hosts monitorados
    $where[] = "tr.status = 0"; // Apenas triggers habilitadas

    $whereClause = implode(" AND ", $where);

    $sql = "
    SELECT
        h.name AS Host,
        tr.description AS Nome,
        ROUND(COALESCE(sla.total_downtime, 0) / :diff * 100, 4) AS Incidentes
    FROM triggers tr
    INNER JOIN (
        SELECT triggerid, MIN(functionid) as functionid
        FROM functions
        GROUP BY triggerid
    ) as f_unique ON tr.triggerid = f_unique.triggerid
    INNER JOIN functions f ON f_unique.functionid = f.functionid
    INNER JOIN items i ON f.itemid = i.itemid
    INNER JOIN hosts h ON i.hostid = h.hostid
    LEFT JOIN (
        SELECT
            objectid,
            SUM(GREATEST(0, LEAST(next_clock, :end) - GREATEST(clock, :start))) as total_downtime
        FROM (
            SELECT
                objectid,
                clock,
                value,
                COALESCE(
                    LEAD(clock, 1) OVER (PARTITION BY objectid ORDER BY clock ASC, eventid ASC),
                    :end
                ) as next_clock
            FROM events
            WHERE source = 0 AND object = 0 AND clock <= :end
              AND clock >= :start - 7776000
        ) as e_win
        WHERE value = 1 AND next_clock > :start
        GROUP BY objectid
    ) as sla ON tr.triggerid = sla.objectid
    WHERE $whereClause
    ORDER BY h.name ASC, tr.description ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':start', $tsStart, PDO::PARAM_INT);
    $stmt->bindValue(':end', $tsEnd, PDO::PARAM_INT);
    $stmt->bindValue(':diff', $diff, PDO::PARAM_INT);

    if (!empty($fHost)) $stmt->bindValue(':fHost', "%$fHost%", PDO::PARAM_STR);
    if (!empty($fGroup)) $stmt->bindValue(':fGroup', "%$fGroup%", PDO::PARAM_STR);

    $stmt->execute();
    $results = $stmt->fetchAll();

    foreach ($results as &$row) {
        $inc = (float)$row['Incidentes'];
        if ($inc <= 0.0001) {
            $row['Incidentes'] = '';
            $row['Ok'] = '100.0000%';
        } else {
            if ($inc > 100) $inc = 100.0000;
            $row['Incidentes'] = number_format($inc, 4, '.', '') . '%';
            $row['Ok'] = number_format(100 - $inc, 4, '.', '') . '%';
        }
    }

    echo json_encode($results);

} catch (PDOException $e) {
    // Log DB errors server-side without exposing internals to the client
    error_log('[SLA Flow] DB Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao conectar ou consultar o banco de dados.']);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
