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

$queueOrders = $botState['queueOrders'] ?? 0;

if ($queueOrders == 1)
    exit();

$botState['queueOrders'] = 1;

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

// Start

$stmt1 = $connection->prepare("SELECT * FROM `orders_queue` WHERE `status` = 0");
$stmt1->execute();
$allQueueOrders = $stmt1->get_result();

if ($allQueueOrders->num_rows > 0) {
    while ($row = $allQueueOrders->fetch_assoc()) {
        $userId = $row['user_id'];
        $token = $row['token'];
        $catId = $row['cat_id'];
        $accountCount = $row['agent_count'];
    }
}

$stmt1->close();




// END
$botState['queueOrders'] = 0;

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
?>