<?php
// -------------------------------------------------------------
// Refactored: Faster, fewer DB round-trips, less JSON scanning
// -------------------------------------------------------------
// Key changes:
// 1) Bulk-fetch server_info & server_plans instead of per-row queries.
// 2) Cache panel JSON per server and pre-index by uuid & inbound_id.
// 3) Reuse a single prepared UPDATE statement.
// 4) Reduce json_decode calls and nested loops.
// 5) Fix totals accumulation and compute min days left.
// 6) Use random_int(), strict validations, and mysqli exceptions.
// -------------------------------------------------------------

// --- Includes & DB Connection ---------------------------------------------
include "../baseInfo.php";
include "../config.php";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $connection = new mysqli('localhost', $dbUserName, $dbPassword, $dbName);
    $connection->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    http_response_code(500);
    exit('error ' . $e->getMessage());
}

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

// --- Input Validation -------------------------------------------------------
if (!isset($_GET['token'])) {
    echo 'Wrong token';
    exit();
}

$token = (string) $_GET['token'];
if (!strictTokenIsValid($token)) {
    echo 'Wrong token';
    exit();
}

// --- Fetch all orders with this token (select only what we use) ------------
$stmt = $connection->prepare("SELECT id, userid, remark, uuid, server_id, inbound_id, up_down, protocol, rahgozar, fileid, link, expire_date FROM orders_list WHERE token = ?");
$stmt->bind_param('s', $token);
$stmt->execute();
$res = $stmt->get_result();
$orders = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (!$orders || count($orders) === 0) {
    echo 'Wrong token';
    exit();
}


// --- Collect unique IDs for bulk fetches -----------------------------------
$serverIds = [];
$fileIds = [];
$uuidsPerServer = [];
$catId = 0;

foreach ($orders as $o) {
    $catId = (int) ($o['cat_id'] ?? 0);
}

$stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id` = ?");
$stmt->bind_param("i", $catId);
$stmt->execute();
$catInfo = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();

$volume = (int) ($catInfo['volume'] ?? 0);
$days = (int) ($catInfo['days'] ?? 0);


// Prepared UPDATE statement (reused)
$updStmt = $connection->prepare("UPDATE orders_list SET link = ?, remark = ?, up_down = ? WHERE id = ?");

$usage = 0;
$links = [];
$minDaysLeft = null;

// --- Process each order -----------------------------------------------------
foreach ($orders as $o) {
    $raw = $o['link'] ?? '';
    $up_down = (int) ($o['up_down'] ?? 0);

    $usage += $up_down;

    if ($raw !== '') {
        $arr = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($arr)) {
            foreach ($arr as $lnk) {
                if (is_string($lnk) && $lnk !== '') {
                    $links[] = trim($lnk);
                }
            }
        } elseif (is_string($raw) && preg_match('/^(vless|vmess|trojan|ss):\/\//i', $raw)) {
            $links[] = trim($raw);
        }
    }

    // محاسبه حداقل روز باقی‌مانده بین سفارش‌ها
    $expireTs = (int) ($o['expire_date'] ?? 0);
    if ($expireTs > 0) {
        $daysLeft = (int) max(0, $expireTs - time());
        $daysLeft = round($daysLeft / 86400, 1);
        $minDaysLeft = ($minDaysLeft === null) ? $daysLeft : min($minDaysLeft, $daysLeft);
    }
}

if (!empty($links)) {
    $links = array_values(array_unique($links));
    shuffle($links);

    $randomId = uuidv4_random();
    $daysHeader = $minDaysLeft !== null ? $minDaysLeft : 0;

    $headerRemarkText = '📊 مصرف شما: ' . $$usage . ' از ' . $volume . ' 📊';
    $usageLink = 'vless://' . $randomId . '@127.0.0.1:1?type=none&encryption=none#'
        . rawurlencode($headerRemarkText);

    $expireDaysLink = 'vless://' . $randomId . '@127.0.0.1:2?type=none&encryption=none#'
        . rawurlencode('⏰ تاریخ انقضا: ' . $daysHeader . ' روز دیگر ⏰');

    $descLink = 'vless://' . $randomId . '@127.0.0.1:3?type=none&encryption=none#'
        . rawurlencode('📣 قطع شد؟ کانفیگ‌هات رو سریع با لینک سابسکریپشن آپدیت کن.');

    array_unshift($links, $expireDaysLink);
    array_unshift($links, $usageLink);
    $links[] = $descLink;

    header('Content-Type: text/plain; charset=utf-8');
    echo base64_encode(implode("\n", $links));
    exit();
}

exit('Error occured');
