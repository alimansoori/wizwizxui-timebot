<?php

include_once '../baseInfo.php';
include_once '../config.php';
include_once 'jdf.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $connection = new mysqli('localhost', $dbUserName, $dbPassword, $dbName);
    $connection->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    http_response_code(500);
    exit('error ' . $e->getMessage());
}

$rateLimit = $botState['rateLimitUpdateLinks'] ?? 0;

if (time() < $rateLimit)
    exit();

$botState['rateLimitUpdateLinks'] = strtotime("+1 hour");

$stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'BOT_STATES'");
$stmt->execute();
$isExists = $stmt->get_result();
$stmt->close();
if ($isExists->num_rows > 0)
    $query = "UPDATE `setting` SET `value` = ? WHERE `type` = 'BOT_STATES'";
else
    $query = "INSERT INTO `setting` (`type`, `value`) VALUES ('BOT_STATES', ?)";
$newData = json_encode($botState);

$stmt = $connection->prepare($query);
$stmt->bind_param("s", $newData);
$stmt->execute();
$stmt->close();

$stmt = $connection->prepare("SELECT DISTINCT `token` FROM `orders_list` WHERE `status` = 1 AND `token` <> ''");
$stmt->execute();
$allActiveTokens = $stmt->get_result();

$detailsStmt = $connection->prepare("
    SELECT * FROM `orders_list`
    WHERE `status` = 1 AND `token` = ?
    ORDER BY `id` DESC
");

$ordersByToken = [];

if ($allActiveTokens->num_rows > 0) {
    while ($row = $allActiveTokens->fetch_assoc()) {
        $token = $row['token'];

        $detailsStmt->bind_param('s', $token);
        $detailsStmt->execute();
        $detailsRes = $detailsStmt->get_result();

        $ordersByToken[$token] = $detailsRes->fetch_all(MYSQLI_ASSOC);
    }
} else {
    $ordersByToken = [];
}

$detailsStmt->close();
$stmt->close();

$catCache = [];
$serverJsonCache = [];

// --- Helpers ---------------------------------------------------------------
function strictTokenIsValid(string $token): bool
{
    return (bool) preg_match('/^[a-zA-Z0-9]{30}$/', $token);
}

function decodeFirstLinkFromJson(?string $jsonLinks): ?string
{
    if ($jsonLinks === null || $jsonLinks === '')
        return null;
    $arr = json_decode($jsonLinks, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($arr) && isset($arr[0])) {
        return $arr[0];
    }
    return null;
}

function uuidv4_random(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function array_unique_int(array $values): array
{
    $out = [];
    foreach ($values as $v) {
        $v = (int) $v;
        if ($v > 0)
            $out[$v] = true;
    }
    return array_keys($out);
}

// Build index of panel data once per server
function buildServerPanelIndex($server_id, $uuidsNeeded): array
{
    // getJson is provided elsewhere; we expect ->obj (array of inbounds)
    $response = getJson($server_id)->obj ?? [];

    $uuidIndex = [];       // uuid => [inbound_id, port, net, security, up, down, total, enable]
    $inboundById = [];     // inbound_id => [port, net, security, up, down, total, clients(email=>...)]

    // Ensure we can check uuids quickly
    $uuidSet = [];
    foreach ($uuidsNeeded as $u) {
        if ($u)
            $uuidSet[$u] = true;
    }

    foreach ($response as $row) {
        // Coerce stdClass to arrays where needed, but avoid extra decodes later
        $inboundId = isset($row->id) ? (int) $row->id : 0;
        $port = isset($row->port) ? $row->port : null;
        $total = isset($row->total) ? (int) $row->total : 0;
        $up = isset($row->up) ? (int) $row->up : 0;
        $down = isset($row->down) ? (int) $row->down : 0;

        $streamArr = [];
        if (!empty($row->streamSettings)) {
            $tmp = json_decode($row->streamSettings, true);
            if (is_array($tmp))
                $streamArr = $tmp;
        }
        $netType = $streamArr['network'] ?? null;
        $security = $streamArr['security'] ?? null;

        // Decode clients and clientStats once
        $clients = [];
        if (!empty($row->settings)) {
            $settingsArr = json_decode($row->settings, true);
            if (is_array($settingsArr) && isset($settingsArr['clients']) && is_array($settingsArr['clients'])) {
                $clients = $settingsArr['clients'];
            }
        }

        $clientStats = is_array($row->clientStats ?? null) ? $row->clientStats : [];
        $statsByEmail = [];
        foreach ($clientStats as $cs) {
            if (isset($cs->email)) {
                $statsByEmail[$cs->email] = $cs;
            }
        }

        // Store inbound-level info
        $inboundById[$inboundId] = [
            'port' => $port,
            'net' => $netType,
            'security' => $security,
            'up' => $up,
            'down' => $down,
            'total' => $total,
            'statsByEmail' => $statsByEmail,
        ];

        // Only index uuids we actually need (keeps memory/CPU lower)
        if (!empty($uuidSet) && !empty($clients)) {
            foreach ($clients as $c) {
                $cid = $c['id'] ?? null;
                $cpw = $c['password'] ?? null;
                $uuidCandidate = $cid ?: $cpw;
                if (!$uuidCandidate || !isset($uuidSet[$uuidCandidate]))
                    continue;

                $email = $c['email'] ?? null;
                $stats = $email && isset($statsByEmail[$email]) ? $statsByEmail[$email] : null;
                $u2 = isset($stats->up) ? (int) $stats->up : $up;
                $d2 = isset($stats->down) ? (int) $stats->down : $down;
                $t2 = isset($stats->total) ? (int) $stats->total : $total;
                $en2 = isset($stats->enable) ? $stats->enable : null;

                $uuidIndex[$uuidCandidate] = [
                    'inbound_id' => $inboundId,
                    'port' => $port,
                    'net' => $netType,
                    'security' => $security,
                    'up' => $u2,
                    'down' => $d2,
                    'total' => $t2,
                    'enable' => $en2,
                ];
            }
        }
    }

    return ['uuidIndex' => $uuidIndex, 'inboundById' => $inboundById];
}

$catInfoCache = [];

foreach ($ordersByToken as $token => $orders) {
    // --- Collect unique IDs for bulk fetches -----------------------------------
    $serverIds = [];
    $fileIds = [];
    $uuidsPerServer = [];
    $catId = 0;

    foreach ($orders as $o) {
        $sid = (int) ($o['server_id'] ?? 0);
        $fid = (int) ($o['fileid'] ?? 0);
        $catId = (int) ($o['cat_id'] ?? 0);
        if ($sid > 0) {
            $serverIds[] = $sid;
            $uuid = trim((string) ($o['uuid'] ?? ''));
            if ($uuid !== '')
                $uuidsPerServer[$sid][$uuid] = true;
        }
        if ($fid > 0)
            $fileIds[] = $fid;
    }
    $serverIds = array_unique_int($serverIds);
    $fileIds = array_unique_int($fileIds);

    // --- Bulk fetch server_info and server_plans -------------------------------
    $serverInfoCache = []; // id => row
    $planCache = []; // id => row

    if (!empty($serverIds)) {
        $idsStr = implode(',', array_map('intval', $serverIds));
        $q = $connection->query("SELECT id, remark, flag FROM server_info WHERE id IN ($idsStr)");
        while ($row = $q->fetch_assoc()) {
            $serverInfoCache[(int) $row['id']] = $row;
        }
    }

    if (!empty($fileIds)) {
        $idsStr = implode(',', array_map('intval', $fileIds));
        $q = $connection->query("SELECT id, custom_path, custom_port, custom_sni FROM server_plans WHERE id IN ($idsStr)");
        while ($row = $q->fetch_assoc()) {
            $planCache[(int) $row['id']] = $row;
        }
    }

    if (!isset($catInfoCache[$catId])) {
        $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id` = ?");
        $stmt->bind_param("i", $catId);
        $stmt->execute();
        $catInfoCache[$catId] = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
    }

    $catInfo = $catInfoCache[$catId] ?? [];
    $volume = (int) ($catInfo['volume'] ?? 0);
    $days = (int) ($catInfo['days'] ?? 0);

    // --- Panel JSON cache & indices per server ---------------------------------
    $panelIndexByServer = []; // server_id => ['uuidIndex'=>..., 'inboundById'=>...]
    foreach ($serverIds as $sid) {
        $uuidsNeeded = isset($uuidsPerServer[$sid]) ? array_keys($uuidsPerServer[$sid]) : [];
        $panelIndexByServer[$sid] = buildServerPanelIndex($sid, $uuidsNeeded);
    }

    // --- Caches & accumulators --------------------------------------------------
    $allLinksFlat = [];
    $accUsed = 0; // sum of up+down over all orders
    $accTotal = $volume; // sum of total over all orders
    $minDaysLeft = null; // min remaining days across orders

    // Prepared UPDATE statement (reused)
    $updStmt = $connection->prepare("UPDATE orders_list SET link = ?, remark = ?, up_down = ? WHERE id = ?");

    // --- Process each order -----------------------------------------------------
    foreach ($orders as $order) {
        sleep(3);

        $id = (int) ($order['id'] ?? 0);
        $userId = (string) ($order['userid'] ?? '');
        $uuid = trim((string) ($order['uuid'] ?? ''));
        $server_id = (int) ($order['server_id'] ?? 0);
        $inbound_id = (int) ($order['inbound_id'] ?? 0);
        $protocol = (string) ($order['protocol'] ?? '');
        $rahgozar = (string) ($order['rahgozar'] ?? '');
        $file_id = (int) ($order['fileid'] ?? 0);

        if ($server_id === 0)
            continue;

        $file_detail = $planCache[$file_id] ?? [];
        $customPath = $file_detail['custom_path'] ?? null;
        $customPort = $file_detail['custom_port'] ?? null;
        $customSni = $file_detail['custom_sni'] ?? null;

        $server_info = $serverInfoCache[$server_id] ?? [];
        $serverRemark = $server_info['remark'] ?? '';
        $serverFlag = $server_info['flag'] ?? '';

        $rnd = random_int(1111, 99999);
        if (!empty($botState['remark']) && $botState['remark'] === 'digits') {
            $remark = trim("{$serverFlag} {$serverRemark}-{$rnd}");
        } else {
            $remark = trim("{$serverFlag} {$serverRemark}-{$userId}-{$rnd}");
        }

        // Pull indexed panel data for this server
        $panelIndex = $panelIndexByServer[$server_id] ?? ['uuidIndex' => [], 'inboundById' => []];
        $uuidInfo = ($uuid !== '') ? ($panelIndex['uuidIndex'][$uuid] ?? null) : null;

        $port = $uuidInfo['port'] ?? null;
        $netType = $uuidInfo['net'] ?? null;
        $security = $uuidInfo['security'] ?? null;
        $up = (int) ($uuidInfo['up'] ?? 0);
        $down = (int) ($uuidInfo['down'] ?? 0);
        $total = (int) ($uuidInfo['total'] ?? 0);

        if ($inbound_id !== 0 && !$uuidInfo) {
            // Fall back to inbound-level info when uuid mapping was not found
            $ib = $panelIndex['inboundById'][$inbound_id] ?? null;
            if ($ib) {
                $port = $port ?? $ib['port'];
                $netType = $netType ?? $ib['net'];
                $security = $security ?? $ib['security'];
                $up = $up ?: (int) $ib['up'];
                $down = $down ?: (int) $ib['down'];
                $total = $total ?: (int) $ib['total'];
            }
        }

        // Accumulate totals safely
        $accUsed += round(($up + $down) / 1073741824, 2);

        $up_down = round(($up + $down) / 1073741824, 2);

        // days left per order
        $expireTs = (int) ($order['expire_date'] ?? 0);
        $daysLeft = (int) max(0, $expireTs - time());
        $daysLeft = round($daysLeft / 86400, 1);
        if ($minDaysLeft === null)
            $minDaysLeft = $daysLeft;
        else
            $minDaysLeft = min($minDaysLeft, $daysLeft);

        // Determine uniq id to embed in the link
        $uniqid = $uuid !== '' ? $uuid : null;
        if ($uniqid === null) {
            $origLink = decodeFirstLinkFromJson($order['link'] ?? '') ?: '';
            if ($origLink && preg_match('/^vmess:\/\//i', $origLink)) {
                $b64 = substr($origLink, strlen('vmess://'));
                $decoded = json_decode(base64_decode($b64));
                if ($decoded) {
                    $uniqid = $decoded->id ?? null;
                    $protocol = $protocol ?: 'vmess';
                    $port = $port ?: ($decoded->port ?? null);
                    $netType = $netType ?: ($decoded->net ?? null);
                }
            } elseif ($origLink) {
                $linkInfo = @parse_url($origLink);
                if ($linkInfo !== false) {
                    $uniqid = $linkInfo['user'] ?? $uniqid;
                    $protocol = $protocol ?: ($linkInfo['scheme'] ?? '');
                }
            }
        }

        if ($uniqid === null || $uniqid === '') {
            continue; // cannot build a safe link
        }

        // Push remark to panel (choose API based on inbound_id presence)
        if ($inbound_id === 0) {
            $res = editInboundRemark($server_id, $uniqid, $remark);
        } else {
            $useInbound = $uuidInfo['inbound_id'] ?? $inbound_id; // prefer detected inbound
            $res = editClientRemark($server_id, $useInbound, $uniqid, $remark);
        }

        if (!isset($res->success) || !$res->success) {
            // panel update failed; skip this order to avoid bad links
            continue;
        }

        // Build fresh connection links
        $vraylink = getConnectionLink(
            $server_id,
            $uniqid,
            $protocol,
            $remark,
            $port,
            $netType,
            $inbound_id,
            $rahgozar,
            $customPath,
            $customPort,
            $customSni
        );

        if (is_array($vraylink)) {
            // Persist per-row update using reused statement
            $newLinkJson = json_encode($vraylink, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $updStmt->bind_param('ssdi', $newLinkJson, $remark, $up_down, $id);
            $updStmt->execute();
        }
    }

    $updStmt->close();

    $leftgb = ($accTotal - $accUsed);

    /* if ($leftgb < 0) {
        foreach ($orders as $order) {
            changeUserConfigStateDisable($order["id"]);
        }
    } */

}
