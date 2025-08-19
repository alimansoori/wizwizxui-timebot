<?php
// --- Includes & DB Connection -------------------------------------------------
include "../baseInfo.php";
include "../config.php";

$connection = new mysqli('localhost', $dbUserName, $dbPassword, $dbName);
if ($connection->connect_error) {
    http_response_code(500);
    exit("error " . $connection->connect_error);
}
$connection->set_charset("utf8mb4");

// --- Helpers ------------------------------------------------------------------
function strictTokenIsValid($token)
{
    return (bool) preg_match('/^[a-zA-Z0-9]{30}$/', $token);
}

function decodeFirstLinkFromJson($jsonLinks)
{
    $arr = json_decode($jsonLinks, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($arr) && isset($arr[0])) {
        return $arr[0];
    }
    return null;
}

function uuidv4_random()
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// --- Input Validation ---------------------------------------------------------
if (!isset($_GET['token'])) {
    echo "Wrong token";
    exit();
}

$token = $_GET['token'];
if (!strictTokenIsValid($token)) {
    echo "Wrong token";
    exit();
}

// --- Fetch all orders with this token ----------------------------------------
$stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `token` = ?");
$stmt->bind_param("s", $token);
$stmt->execute();
$result = $stmt->get_result();
$orderList = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (!$orderList || count($orderList) === 0) {
    echo "Wrong token";
    exit();
}

// --- Caches to avoid repetitive queries/calls --------------------------------
$serverInfoCache = []; // server_id => server_info row
$serverJsonCache = []; // server_id => getJson(...)->obj
$planCache = []; // file_id  => server_plans row

$allLinksFlat = [];          // for final base64 output (merged links of all orders)
$accUsedBytes = 0;           // sum of (up+down) over all orders
$accTotalBytes = 0;          // sum of total over all orders
$daysLeft = 0;

// --- Process each order -------------------------------------------------------
foreach ($orderList as $info) {
    // Extract basic fields from order
    $remark = $info['remark'] ?? '';
    $uuid = trim($info['uuid'] ?? ""); // هر سفارش uuid مخصوص خودش را دارد
    $server_id = (int) ($info['server_id'] ?? 0);
    $inbound_id = (int) ($info['inbound_id'] ?? 0);
    $protocol = $info['protocol'] ?? '';
    $rahgozar = $info['rahgozar'] ?? '';
    $file_id = (int) ($info['fileid'] ?? 0);

    if ($server_id === 0) {
        continue;
    }

    // ---- Fetch plan details (custom path/port/sni) --------------------------
    if (!isset($planCache[$file_id])) {
        $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ?");
        $stmt->bind_param("i", $file_id);
        $stmt->execute();
        $planCache[$file_id] = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
    }
    $file_detail = $planCache[$file_id];
    $customPath = $file_detail['custom_path'] ?? null;
    $customPort = $file_detail['custom_port'] ?? null;
    $customSni = $file_detail['custom_sni'] ?? null;

    // ---- Fetch server config ------------------------------------------------
    if (!isset($serverInfoCache[$server_id])) {
        $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $serverInfoCache[$server_id] = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
    }
    $server_info = $serverInfoCache[$server_id];

    // ---- Pull inbounds JSON from panel (once per server) --------------------
    if (!isset($serverJsonCache[$server_id])) {
        $serverJsonCache[$server_id] = getJson($server_id)->obj; // external helper
    }
    $response = $serverJsonCache[$server_id];

    // ---- Find client usage/port/network ------------------------------------
    $clientInbound = null;
    $port = null;
    $netType = null;
    $security = null;
    $up = 0;
    $down = 0;
    $total = 0;
    $enable = null;

    if ($inbound_id === 0) {
        // Search across all inbounds and ALL clients
        foreach ($response as $row) {
            $clients = json_decode($row->settings)->clients ?? [];
            foreach ($clients as $c) {
                $match = false;
                if (isset($c->id) && $c->id == $uuid)
                    $match = true;
                if (isset($c->password) && $c->password == $uuid)
                    $match = true;
                if ($match) {
                    $clientInbound = $row->id;
                    $total = $row->total ?? 0;
                    $port = $row->port ?? null;
                    $up = $row->up ?? 0;
                    $down = $row->down ?? 0;
                    $stream = json_decode($row->streamSettings);
                    $netType = $stream->network ?? null;
                    $security = $stream->security ?? null;
                    break 2; // found
                }
            }
        }
    } else {
        foreach ($response as $row) {
            if ((int) $row->id === $inbound_id) {
                $clientInbound = $row->id;
                $port = $row->port ?? null;
                $stream = json_decode($row->streamSettings);
                $netType = $stream->network ?? null;
                $security = $stream->security ?? null;

                $clientsStates = $row->clientStats ?? [];
                $clients = json_decode($row->settings)->clients ?? [];
                foreach ($clients as $client) {
                    $match = false;
                    if (isset($client->id) && $client->id == $uuid)
                        $match = true;
                    if (isset($client->password) && $client->password == $uuid)
                        $match = true;
                    if ($match) {
                        $email = $client->email ?? null;
                        if ($email !== null && is_array($clientsStates)) {
                            $emails = array_column($clientsStates, 'email');
                            $emailKey = array_search($email, $emails, true);
                            if ($emailKey !== false && isset($clientsStates[$emailKey])) {
                                $state = $clientsStates[$emailKey];
                                $total = $state->total ?? 0;
                                $up = $state->up ?? 0;
                                $down = $state->down ?? 0;
                                $enable = $state->enable ?? null;
                            }
                        }
                        break;
                    }
                }
                break;
            }
        }
    }

    // ---- Accumulate totals for the header link ------------------------------
    $accUsedBytes += (int) $up + (int) $down;
    $accTotalBytes = (int) $total;

    // ---- Compute usage/days -------------------------------------------------
    $totalUsedGb = round(($up + $down) / 1073741824, 2) . " GB";
    $totalGb = round(($total) / 1073741824, 2) . " GB";

    $expireTs = (int) ($info['expire_date'] ?? 0);
    $daysLeft = round(max(0, $expireTs - time()) / 86400, 1);

    // ---- Determine uniq id explicitly from this order's uuid ----------------
    // نکته اصلی: هر سفارش باید uuid مخصوص خودش را در لینک داشته باشد.
    $uniqid = $uuid !== '' ? $uuid : null;

    // اگر uuid خالی بود (انتظار نمی‌رود)، از لینک ذخیره‌شده fallback می‌کنیم تا خراب نشود.
    if ($uniqid === null) {
        $origLink = decodeFirstLinkFromJson($info['link'] ?? '') ?: '';
        if ($origLink && preg_match('/^vmess:\/\//i', $origLink)) {
            $b64 = substr($origLink, strlen('vmess://'));
            $decoded = json_decode(base64_decode($b64));
            if ($decoded) {
                $uniqid = $decoded->id ?? null;
                if (!$protocol) {
                    $protocol = 'vmess';
                }
                $port = $decoded->port ?? $port;
                $netType = $decoded->net ?? $netType;
            }
        } elseif ($origLink) {
            $linkInfo = @parse_url($origLink);
            if ($linkInfo !== false) {
                $uniqid = $linkInfo['user'] ?? $uniqid;
                if (!$protocol) {
                    $protocol = $linkInfo['scheme'] ?? $protocol;
                }
            }
        }
    }

    // اگر هنوز هم uniqid نداشتیم، این سفارش را رد می‌کنیم.
    if ($uniqid === null || $uniqid === '') {
        continue;
    }

    // ---- Build new remark with usage & days ---------------------------------
    $newRemarkBase = preg_replace("/\(📊.+-.+\|📆.+\)/u", "", $remark);
    $newRemark = rtrim($newRemarkBase) . "(📊" . $totalUsedGb . " - " . $totalGb . "|📆" . $daysLeft . ")";

    // ---- Push remark to panel ----------------------------------------------
    if ($inbound_id === 0) {
        $res = editInboundRemark($server_id, $uuid, $remark);
    } else {
        $res = editClientRemark($server_id, $clientInbound, $uuid, $remark);
    }

    if (!isset($res->success) || !$res->success) {
        // skip this order if panel update failed
        continue;
    }

    // ---- Build fresh connection links & update DB ---------------------------
    $vraylink = getConnectionLink(
        $server_id,
        $uniqid,        // <<-- استفاده از uuid همان سفارش
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
        // Update only this specific row
        $stmt = $connection->prepare("UPDATE `orders_list` SET `link` = ?, `remark` = ? WHERE `id` = ?");
        $newLinkJson = json_encode($vraylink, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $id = (int) $info['id'];
        $stmt->bind_param("ssi", $newLinkJson, $remark, $id);
        $stmt->execute();
        $stmt->close();

        // Collect for final output
        foreach ($vraylink as $lnk) {
            $allLinksFlat[] = $lnk;
        }
    }
}

// --- Build and prepend the header link ---------------------------------------
$usedGbAll = round($accUsedBytes / 1073741824, 2) . ' GB';
$totalGbAll = round($accTotalBytes / 1073741824, 2) . ' GB';
$headerRemarkText = '📊مصرف شما: ' . $usedGbAll . ' از ' . $totalGbAll;
$randomId = uuidv4_random();

// ساخت یک لینک VLESS ساده به عنوان هدر (localhost:1)
$usageLink = 'vless://' . $randomId . '@127.0.0.1:1?type=none&encryption=none#' . rawurlencode($headerRemarkText);
$expireDaysLink = 'vless://' . $randomId . '@127.0.0.1:2?type=none&encryption=none#' . rawurlencode('⏰ تاریخ انقضا: ' . $daysLeft . ' روز دیگر⏰');
$descLink = 'vless://' . $randomId . '@127.0.0.1:3?type=none&encryption=none#' . rawurlencode('📣 زمانی که دسترسی شما قطع شد، کانفیگ‌های خود را با لینک سابسکریپشن آپدیت کنید.');

shuffle($allLinksFlat);

// قرار دادن در ابتدای آرایه
array_unshift($allLinksFlat, $usageLink);
array_unshift($allLinksFlat, $expireDaysLink);
array_unshift($allLinksFlat, $descLink);

// --- Final Output ------------------------------------------------------------
if (!empty($allLinksFlat)) {
    header('Content-Type: text/plain; charset=utf-8');
    echo base64_encode(implode("\n", $allLinksFlat));
    exit();
}

exit("Error occured");

?>