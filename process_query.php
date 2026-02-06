<?php
set_time_limit(300); 
ini_set('memory_limit', '1G');
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) die(json_encode(['error' => 'Payload inválido.']));

$db_host = $data['dbHost'] ?? '';
$db_name = $data['dbName'] ?? '';
$db_user = $data['dbUser'] ?? '';
$db_pass = $data['dbPass'] ?? '';
$start   = $data['dateStart'] ?? '';
$end     = $data['dateEnd'] ?? '';
$fGroup  = $data['filterGroup'] ?? '';
$fHost   = $data['filterHost'] ?? '';

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

    // SQL Compatível com MariaDB e MySQL 8 (Protegido contra erro de sintaxe no LEAD)
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
            $row['Incidentes'] = ''; // Vazio se não houver incidente real
            $row['Ok'] = '100.0000';
        } else {
            if ($inc > 100) $inc = 100.0000;
            $row['Incidentes'] = number_format($inc, 4, '.', '') . '%';
            $row['Ok'] = number_format(100 - $inc, 4, '.', '') . '%';
        }
    }

    echo json_encode($results);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}