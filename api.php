<?php

function isValidCloudzyToken(string $token): bool {
    $url = "https://panel.cloudzy.com/developers/v1/instances";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "API-Token: $token"
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $apiResult = json_decode($response, true);

    return $httpCode === 200 && isset($apiResult['code']) && $apiResult['code'] === 'OKAY';
}

?>