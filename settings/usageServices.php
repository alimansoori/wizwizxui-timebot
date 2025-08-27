<?php
include_once '../baseInfo.php';
include_once '../config.php';
include_once 'jdf.php';

$rateLimit = $botState['rateLimitUsageServices'] ?? 0;

if (time() < $rateLimit)
    exit();

sendMessage("🤖 Start", null, null, $admin);

$botState['rateLimitUsageServices'] = strtotime("+1 hour");

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

// 1) ابتدا توکن‌های یکتا
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

foreach ($ordersByToken as $token => $orders) {


    foreach ($orders as $order) {
        $inbound_id = $order["inbound_id"];
        $server_id = $order["server_id"];
        $uuid = $order["uuid"];
        $catId = $order["cat_id"];

        if (!isset($catCache[$catId])) {
            $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id` = ?");
            $stmt->bind_param("i", $catId);
            $stmt->execute();
            $catCache[$catId] = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();
        }
        $cat_detail = $catCache[$catId];
        $volume = (int)$cat_detail['volume'];

        if (!isset($serverJsonCache[$server_id])) {
            $serverJsonCache[$server_id] = getJson($server_id)->obj; // external helper
        }
        $response = $serverJsonCache[$server_id];

        if (!$response->success) {
            sendMessage("Error fetching data for server ID: {$server_id}", null, null, $admin);
            continue;
        }

        if ($inbound_id == 0) {
            foreach ($response as $row) {
                $clients = json_decode($row->settings)->clients;
                if ($clients[0]->id == $uuid || $clients[0]->password == $uuid) {
                    $total = $row->total;
                    $up = $row->up;
                    $enable = $row->enable;
                    $down = $row->down;
                    $netType = json_decode($row->streamSettings)->network;
                    $security = json_decode($row->streamSettings)->security;
                    break;
                }
            }
        } else {
            foreach ($response as $row) {
                if ($row->id == $inbound_id) {
                    $netType = json_decode($row->streamSettings)->network;
                    $security = json_decode($row->streamSettings)->security;
                    $clientsStates = $row->clientStats;
                    $clients = json_decode($row->settings)->clients;
                    foreach ($clients as $key => $client) {
                        if ($client->id == $uuid || $client->password == $uuid) {
                            $email = $client->email;
                            $emails = array_column($clientsStates, 'email');
                            $emailKey = array_search($email, $emails);

                            $total = $clientsStates[$emailKey]->total;
                            $up = $clientsStates[$emailKey]->up;
                            $enable = $clientsStates[$emailKey]->enable;
                            if (!$client->enable)
                                $enable = false;
                            else
                                $hasEnable = true;
                            $down = $clientsStates[$emailKey]->down;
                            break;
                        }
                    }
                }
            }
        }
        $total_leftgb += round(($up + $down) / 1073741824, 2);
    }

    $leftgb = ($volume - $total_leftgb) . " GB";

    sendMessage("Token: {$token}\nTotal Orders: " . count($orders) . "\nLeft GB: {$leftgb}", null, 'HTML', $admin);
}

sendMessage("🤖 END", null, null, $admin);


?>