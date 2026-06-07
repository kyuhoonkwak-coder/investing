<?php
require __DIR__ . '/config.php';

$body = json_encode([
    'grant_type' => 'client_credentials',
    'appkey'     => KIS_APP_KEY,
    'appsecret'  => KIS_APP_SECRET,
]);
$opts = [
    'http' => ['method'=>'POST','header'=>'Content-Type: application/json','content'=>$body,'ignore_errors'=>true,'timeout'=>10],
    'ssl'  => ['verify_peer'=>false,'verify_peer_name'=>false],
];
$res = @file_get_contents(KIS_BASE_URL . '/oauth2/tokenP', false, stream_context_create($opts));
$d   = json_decode($res, true);

if (!empty($d['access_token'])) {
    file_put_contents(TOKEN_CACHE_FILE, json_encode([
        'access_token' => $d['access_token'],
        'expires_at'   => time() + 82800,
    ]));
    echo "토큰 저장 완료\n";
} else {
    echo "실패: $res\n";
}
