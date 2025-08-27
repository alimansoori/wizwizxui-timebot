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

foreach ($ordersByToken as $token => $orders) {
    sendMessage("Token: {$token}\nTotal Orders: " . count($orders), null, 'HTML', $admin);
    // echo "<h3>Token: {$token}</h3>";
    // echo "Total Orders: " . count($orders) . "<br>";
    /* foreach ($orders as $order) {
        echo "Order ID: {$order['id']} - User: {$order['userid']} - Amount: {$order['amount']}<br>";
    } */
}

sendMessage("🤖 END", null, null, $admin);


?>