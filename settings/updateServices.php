<?php
include_once '../baseInfo.php';
include_once '../config.php';
include_once 'jdf.php';

$rateLimit = $botState['rateLimitUpdateServices'] ?? 0;

if (time() < $rateLimit)
    exit();

sendMessage("🤖 FilterBeshcan robot has been successfully updated!", null, null, $admin);

$botState['rateLimitUpdateServices'] = strtotime("+1 hour");

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

$stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `status` = 1");
$stmt->execute();
$allActiveOrders = $stmt->get_result();
$stmt->close();

if ($allActiveOrders->num_rows > 0) {
    $order = $allActiveOrders->fetch_assoc();
}

?>