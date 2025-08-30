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
        $command = $row['command'];
        $hashId = $row['hash_id'];
        $messageId = $row['message_id'];

        if (preg_match('/^servicePayWithWallet(.*)/', $command, $match)) {
            $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
            $stmt->bind_param("s", $hashId);
            $stmt->execute();
            $payInfo = $stmt->get_result();
            $stmt->close();

            if ($payInfo->num_rows == 0)
                continue;

            $payInfo = $payInfo->fetch_assoc();

            $fid = $payInfo['plan_id'];
            $userId = $payInfo['user_id'];
            $catId = $payInfo['cat_id'];
            $price = $payInfo['price'];
            $acctxt = '';

            $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
            $stmt->bind_param("s", $userId);
            $stmt->execute();
            $userInfo = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $username = $userInfo['username'];
            $first_name = $userInfo['first_name'];

            if ($payInfo['state'] == "paid_with_wallet")
                continue;

            if ($userInfo['wallet'] < $price)
                continue;

            $accountCount = $payInfo['agent_count'] != 0 ? $payInfo['agent_count'] : 1;

            $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `catid`=? AND `acount` > ?");
            $stmt->bind_param("ii", $catId, $accountCount);
            $stmt->execute();
            $files_detail = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id`=?");
            $stmt->bind_param("i", $catId);
            $stmt->execute();
            $cat_detail = $stmt->get_result()->fetch_assoc();
            $serviceName = $cat_detail['title'];
            $days = (int) $cat_detail['days'];
            $volume = (float) $cat_detail['volume'];
            $limitip = (int) $cat_detail['limit_ip'];
            $stmt->close();

            if ($payInfo['type'] == "RENEW_SCONFIG") {
                foreach ($files_detail as $file_detail) {
                    /* $planId = (int) $file_detail['id'];
                    $date = time();
                    $expire_microdate = floor(microtime(true) * 1000) + (864000 * $days * 100);
                    $expire_date = $date + (86400 * $days);
                    $type = $file_detail['type'];
                    $protocol = $file_detail['protocol'];
                    $price = $payInfo['price'];
                    $server_id = (int) $file_detail['server_id'];
                    $acount = (int) $file_detail['acount'];
                    $inbound_id = (int) $file_detail['inbound_id'];
                    $netType = $file_detail['type'];
                    $rahgozar = $file_detail['rahgozar'];
                    $customPath = (int) $file_detail['custom_path'];
                    $customPort = (int) $file_detail['custom_port'];
                    $customSni = (int) $file_detail['custom_sni'];

                    $configInfo = json_decode($payInfo['description'], true);
                    $uuid = $configInfo['uuid'];
                    $remark = $configInfo['remark'];
                    $isMarzban = $configInfo['marzban'];

                    $inbound_id = $payInfo['volume'];

                    if ($isMarzban) {
                        $response = editMarzbanConfig($server_id, ['remark' => $remark, 'days' => $days, 'volume' => $volume]);
                    } else {
                        if ($inbound_id > 0)
                            $response = editClientTraffic($server_id, $inbound_id, $uuid, $volume, $days, "renew");
                        else
                            $response = editInboundTraffic($server_id, $uuid, $volume, $days, "renew");
                    }

                    if (is_null($response)) {
                        alert('🔻مشکل فنی در اتصال به سرور. لطفا به مدیریت اطلاع بدید', true);
                        exit;
                    }
                    $stmt = $connection->prepare("INSERT INTO `increase_order` VALUES (NULL, ?, ?, ?, ?, ?, ?);");
                    $stmt->bind_param("iiisii", $uid, $server_id, $inbound_id, $remark, $price, $time);
                    $stmt->execute();
                    $stmt->close();
                    $keys = json_encode([
                        'inline_keyboard' => [
                            [
                                ['text' => $buttonValues['back_to_main'], 'callback_data' => "mainMenu"]
                            ],
                        ]
                    ]);
                    editText($message_id, "✅سرویس $remark با موفقیت تمدید شد", $keys); */
                }
            } else {
                $eachPrice = ($price / $accountCount);

                for ($i = 1; $i <= $accountCount; $i++) {
                    $linkCounter = 0;

                    foreach ($files_detail as $file_detail) {
                        $planId = (int) $file_detail['id'];
                        $date = time();
                        $expire_microdate = floor(microtime(true) * 1000) + (864000 * $days * 100);
                        $expire_date = $date + (86400 * $days);
                        $type = $file_detail['type'];
                        $protocol = $file_detail['protocol'];
                        $server_id = (int) $file_detail['server_id'];
                        $acount = (int) $file_detail['acount'];
                        $inbound_id = (int) $file_detail['inbound_id'];
                        $netType = $file_detail['type'];
                        $rahgozar = $file_detail['rahgozar'];
                        $customPath = (int) $file_detail['custom_path'];
                        $customPort = (int) $file_detail['custom_port'];
                        $customSni = $file_detail['custom_sni'];

                        if ($acount == 0 and $inbound_id != 0) {
                            sendMessage($mainValues['out_of_connection_capacity'], null, null, $admin);
                            continue;
                        }

                        if ($inbound_id == 0) {
                            $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
                            $stmt->bind_param("i", $server_id);
                            $stmt->execute();
                            $server_info = $stmt->get_result()->fetch_assoc();
                            $stmt->close();

                            if ($server_info['ucount'] <= 0) {
                                sendMessage($mainValues['out_of_server_capacity'], null, null, $admin);
                                continue;
                            }
                        }

                        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
                        $stmt->bind_param("i", $server_id);
                        $stmt->execute();
                        $serverInfo = $stmt->get_result()->fetch_assoc();
                        $srv_remark = $serverInfo['remark'];
                        $srv_flag = $serverInfo['flag'];
                        $serverTitle = $serverInfo['title'];
                        $stmt->close();

                        $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=?");
                        $stmt->bind_param("i", $server_id);
                        $stmt->execute();
                        $serverConfig = $stmt->get_result()->fetch_assoc();
                        $portType = $serverConfig['port_type'];
                        $serverType = $serverConfig['type'];
                        $panelUrl = $serverConfig['panel_url'];
                        $stmt->close();

                        $agent_bought = $payInfo['agent_bought'];

                        $uniqid = generateRandomString(42, $protocol);

                        $savedinfo = file_get_contents('settings/temp.txt');
                        $savedinfo = explode('-', $savedinfo);
                        $port = $savedinfo[0] + 1;
                        $last_num = $savedinfo[1] + 1;


                        if ($botState['remark'] == "digits") {
                            $rnd = rand(10000, 99999);
                            $remark = "{$srv_flag} {$srv_remark}-{$rnd}";
                        } elseif ($botState['remark'] == "manual") {
                            $remark = $payInfo['description'];
                        } else {
                            $rnd = rand(1111, 99999);
                            $remark = "{$srv_flag} {$srv_remark}-{$userId}-{$rnd}";
                        }

                        if ($portType == "auto") {
                            file_put_contents('settings/temp.txt', $port . '-' . $last_num);
                        } else {
                            $port = rand(1111, 65000);
                        }

                        if ($inbound_id == 0) {
                            $response = addUser($server_id, $uniqid, $protocol, $port, $expire_microdate, $remark, $volume, $netType, 'none', $rahgozar, $planId);
                            if (!$response->success) {
                                if (strstr($response->msg, "Duplicate email"))
                                    $remark .= RandomString();
                                elseif (strstr($response->msg, "Port already exists"))
                                    $port = rand(1111, 65000);

                                $response = addUser($server_id, $uniqid, $protocol, $port, $expire_microdate, $remark, $volume, $netType, 'none', $rahgozar, $planId);
                            }
                        } else {
                            $response = addInboundAccount($server_id, $uniqid, $inbound_id, $expire_microdate, $remark, $volume, $limitip, null, $planId);
                            if (!$response->success) {
                                if (strstr($response->msg, "Duplicate email"))
                                    $remark .= RandomString();

                                $response = addInboundAccount($server_id, $uniqid, $inbound_id, $expire_microdate, $remark, $volume, $limitip, null, $planId);
                            }
                        }

                        if (is_null($response)) {
                            sendMessage('❌ | 🥺 گلم ، اتصال به سرور برقرار نیست لطفا مدیر رو در جریان بزار ...', null, null, $admin);
                            continue;
                        }
                        if ($response == "inbound not Found") {
                            sendMessage("❌ | 🥺 سطر (inbound) با آیدی $inbound_id تو این سرور وجود نداره ، مدیر رو در جریان بزار ...", null, null, $admin);
                            continue;
                        }
                        if (!$response->success) {
                            sendMessage('❌ | 😮 وای خطا داد لطفا سریع به مدیر بگو ...');
                            sendMessage("خطای سرور {$serverInfo['title']}:\n\n" . ($response->msg), null, null, $admin);
                            continue;
                        }

                        $vraylink = getConnectionLink($server_id, $uniqid, $protocol, $remark, $port, $netType, $inbound_id, $rahgozar, $customPath, $customPort, $customSni);
                        $vray_link = json_encode($vraylink);
                        $linkCounter += 1;

                        $stmt = $connection->prepare("INSERT INTO `orders_list` 
        	    (`userid`, `token`, `transid`, `fileid`, `cat_id`, `server_id`, `inbound_id`, `remark`, `uuid`, `protocol`, `expire_date`, `link`, `amount`, `status`, `date`, `notif`, `rahgozar`, `agent_bought`)
        	    VALUES (?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, 0, ?, ?);");
                        $stmt->bind_param("ssiiiisssisiiii", $uid, $token, $planId, $cat_id, $server_id, $inbound_id, $remark, $uniqid, $protocol, $expire_date, $vray_link, $eachPrice, $date, $rahgozar, $agent_bought);
                        $stmt->execute();
                        $order = $stmt->get_result();
                        $stmt->close();

                        $decrement = 1;
                        if ($inbound_id == 0) {
                            $stmt = $connection->prepare("UPDATE `server_info` SET `ucount` = `ucount` - ? WHERE `id`=?");
                            $stmt->bind_param("ii", $decrement, $server_id);
                            $stmt->execute();
                            $stmt->close();
                        } else {
                            $stmt = $connection->prepare("UPDATE `server_plans` SET `acount` = `acount` - ? WHERE id=?");
                            $stmt->bind_param("ii", $decrement, $planId);
                            $stmt->execute();
                            $stmt->close();
                        }
                    }

                    include 'phpqrcode/qrlib.php';

                    define('IMAGE_WIDTH', 540);
                    define('IMAGE_HEIGHT', 540);

                    $subLink = $botState['subLinkState'] == "on" ? $botUrl . "settings/subLink.php?token=" . $token : "";

                    if ($linkCounter > 0) {

                        $acc_text = "
                            😍 سفارش جدید شما
                            🔮 نام سرویس: $serviceName
                            🔋 حجم سرویس: $volume گیگ
                            ⏰ مدت سرویس: $days روز

                            \n🌐 Subscription : <code>$subLink</code>";

                        $file = RandomString() . ".png";
                        $ecc = 'L';
                        $pixel_Size = 11;
                        $frame_Size = 0;
                        QRcode::png($subLink, $file, $ecc, $pixel_Size, $frame_size);

                        $backgroundImage = imagecreatefromjpeg("settings/QRCode.jpg");
                        $qrImage = imagecreatefrompng($file);

                        $qrSize = array('width' => imagesx($qrImage), 'height' => imagesy($qrImage));
                        imagecopy($backgroundImage, $qrImage, 300, 300, 0, 0, $qrSize['width'], $qrSize['height']);
                        imagepng($backgroundImage, $file);
                        imagedestroy($backgroundImage);
                        imagedestroy($qrImage);

                        sendPhoto($botUrl . $file, $acc_text, json_encode(['inline_keyboard' => [[['text' => $buttonValues['back_to_main'], 'callback_data' => "mainMenu"]]]]), "HTML", $userId);
                        unlink($file);
                    } else {
                        sendMessage("❌ Error occurred while generating connection link. Please contact the admin. User ID: {$userId}, Token: {$token}, Hash: {$hash}", null, null, $admin);
                        continue;
                    }

                    if ($userInfo['refered_by'] != null) {
                        $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'INVITE_BANNER_AMOUNT'");
                        $stmt->execute();
                        $inviteAmount = $stmt->get_result()->fetch_assoc()['value'] ?? 0;
                        $stmt->close();
                        $inviterId = $userInfo['refered_by'];

                        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
                        $stmt->bind_param("ii", $inviteAmount, $inviterId);
                        $stmt->execute();
                        $stmt->close();

                        sendMessage("تبریک یکی از زیر مجموعه های شما خرید انجام داد شما مبلغ " . number_format($inviteAmount) . " تومان جایزه دریافت کردید", null, null, $inviterId);
                    }
                }

                $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'paid_with_wallet' WHERE `hash_id` = ?");
                $stmt->bind_param("s", $hashId);
                $stmt->execute();
                $stmt->close();

                $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` - ? WHERE `userid` = ?");
                $stmt->bind_param("ii", $price, $userId);
                $stmt->execute();
                $stmt->close();

                $keys = json_encode([
                    'inline_keyboard' => [
                        [
                            ['text' => "بنازم خرید جدید ❤️", 'callback_data' => "wizwizch"]
                        ],
                    ]
                ]);

                if ($payInfo['type'] == "RENEW_SCONFIG") {
                    $msg = str_replace(
                        ['TYPE', 'USER-ID', 'USERNAME', 'NAME', 'PRICE', 'REMARK', 'VOLUME', 'DAYS'],
                        ['کیف پول', $userId, $username, $first_name, $price, $remark, $volume, $days],
                        $mainValues['renew_account_request_message']
                    );
                } else {
                    $msg = str_replace(
                        ['SERVERNAME', 'TYPE', 'USER-ID', 'USERNAME', 'NAME', 'PRICE', 'REMARK', 'VOLUME', 'DAYS'],
                        [$serverName, 'کیف پول', $userId, $username, $first_name, $price, $serverName, $volume, $days],
                        $mainValues['buy_new_account_request']
                    );
                }

                sendMessage($msg, $keys, "html", $admin);
            }
        }
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