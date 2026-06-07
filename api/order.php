<?php
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

/* ───────── 입력 파싱 ───────── */
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? ($_GET['action'] ?? '');

if      ($action === 'balance') handleBalance();
elseif  ($action === 'order')   handleOrder($body);
else    err('알 수 없는 액션');

/* ───────── VTS 토큰 ───────── */
function vtsToken(): string {
    $cache = VTS_TOKEN_CACHE_FILE;
    if (file_exists($cache)) {
        $c = json_decode(file_get_contents($cache), true) ?? [];
        if (!empty($c['access_token']) && time() < ($c['expires_at'] - 300)) {
            return $c['access_token'];
        }
    }

    if (!KIS_VTS_APP_KEY || !KIS_VTS_APP_SECRET) {
        throw new RuntimeException('모의투자 앱키가 config.php에 설정되지 않았습니다');
    }

    $payload = json_encode([
        'grant_type' => 'client_credentials',
        'appkey'     => KIS_VTS_APP_KEY,
        'appsecret'  => KIS_VTS_APP_SECRET,
    ]);

    $raw = kis_http('POST', KIS_VTS_BASE_URL . '/oauth2/tokenP', [], $payload, [
        'Content-Type: application/json',
    ]);

    $json = json_decode($raw, true) ?? [];
    if (empty($json['access_token'])) {
        throw new RuntimeException('VTS 토큰 발급 실패: ' . ($json['error_description'] ?? $raw));
    }

    file_put_contents($cache, json_encode([
        'access_token' => $json['access_token'],
        'expires_at'   => time() + (int)($json['expires_in'] ?? 86400),
    ]));

    return $json['access_token'];
}

/* ───────── 잔고 조회 (주문 가능 금액) ───────── */
function handleBalance(): void {
    try {
        if (!KIS_ACCOUNT_NO) { ok(['available' => 0, 'note' => '계좌번호 미설정']); return; }

        $token = vtsToken();
        $code  = $_GET['code'] ?? '';
        $price = (int)($_GET['price'] ?? 0);

        $params = [
            'CANO'                  => KIS_ACCOUNT_NO,
            'ACNT_PRDT_CD'          => KIS_ACCOUNT_CD,
            'PDNO'                  => $code ?: '005930',
            'ORD_UNPR'              => (string)max(1, $price),
            'ORD_DVSN'              => '00',
            'CMA_EVLU_AMT_ICLD_YN'  => 'Y',
            'OVRS_ICLD_YN'          => 'N',
        ];

        $raw = kis_http('GET', KIS_VTS_BASE_URL . '/uapi/domestic-stock/v1/trading/inquire-psbl-order',
            $params, '', [
                'authorization: Bearer ' . $token,
                'appkey: '    . KIS_VTS_APP_KEY,
                'appsecret: ' . KIS_VTS_APP_SECRET,
                'tr_id: VTTC8908R',
                'custtype: P',
            ]);

        $r = json_decode($raw, true) ?? [];
        if (($r['rt_cd'] ?? '') === '0') {
            $out = $r['output'] ?? [];
            $avail = (int)($out['nrcvb_buy_amt'] ?? $out['ord_psbl_cash'] ?? $out['NRCVB_BUY_AMT'] ?? $out['ORD_PSBL_CASH'] ?? 0);
            ok(['available' => $avail]);
        } elseif (($r['msg_cd'] ?? '') === 'EGW9999') {
            // 앱 권한 미설정 — 주문은 시도 가능, 잔고만 미조회
            ok(['available' => 0, 'perm_error' => true]);
        } else {
            ok(['available' => 0, 'msg' => $r['msg1'] ?? '조회 실패']);
        }
    } catch (Throwable $e) {
        ok(['available' => 0, 'msg' => $e->getMessage()]);
    }
}

/* ───────── 주문 ───────── */
function handleOrder(array $b): void {
    try {
        if (!KIS_ACCOUNT_NO) { err('config.php에 KIS_ACCOUNT_NO를 설정하세요'); return; }

        $code  = trim($b['code'] ?? '');
        $side  = $b['side']  ?? 'buy';
        $type  = $b['type']  ?? 'limit';
        $qty   = (int)($b['qty']   ?? 0);
        $price = (int)($b['price'] ?? 0);

        if (!$code)       { err('종목코드 없음'); return; }
        if ($qty <= 0)    { err('수량을 입력하세요'); return; }
        if ($type === 'limit' && $price <= 0) { err('가격을 입력하세요'); return; }

        $token = vtsToken();
        $trId  = $side === 'buy' ? 'VTTC0802U' : 'VTTC0801U';

        $payload = json_encode([
            'CANO'         => KIS_ACCOUNT_NO,
            'ACNT_PRDT_CD' => KIS_ACCOUNT_CD,
            'PDNO'         => $code,
            'ORD_DVSN'     => $type === 'market' ? '01' : '00',
            'ORD_QTY'      => (string)$qty,
            'ORD_UNPR'     => $type === 'market' ? '0' : (string)$price,
            'CTAC_TLNO'    => '',
            'SLL_TYPE'     => '',
            'ALGO_NO'      => '',
        ], JSON_UNESCAPED_UNICODE);

        $raw = kis_http('POST', KIS_VTS_BASE_URL . '/uapi/domestic-stock/v1/trading/order-cash',
            [], $payload, [
                'Content-Type: application/json',
                'authorization: Bearer ' . $token,
                'appkey: '    . KIS_VTS_APP_KEY,
                'appsecret: ' . KIS_VTS_APP_SECRET,
                'tr_id: '     . $trId,
                'custtype: P',
            ]);

        $r = json_decode($raw, true) ?? [];
        if (($r['rt_cd'] ?? '') === '0') {
            $out = $r['output'] ?? [];
            ok([
                'ordNo'  => $out['ODNO']     ?? '',
                'ordQty' => $out['ORD_QTY']  ?? $qty,
                'ordPrc' => $out['ORD_UNPR'] ?? $price,
                'msg'    => $r['msg1']        ?? '주문 완료',
            ]);
        } else {
            err($r['msg1'] ?? '주문 실패 (rt_cd=' . ($r['rt_cd'] ?? '?') . ')');
        }
    } catch (Throwable $e) {
        err($e->getMessage());
    }
}

/* ───────── HTTP 헬퍼 ───────── */
function kis_http(string $method, string $url, array $params, string $body, array $headers): string {
    if ($params) $url .= '?' . http_build_query($params);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => $method,
        ]);
        if ($body !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $res = (string)curl_exec($ch);
        curl_close($ch);
        return $res;
    }

    $opts = [
        'http' => [
            'method'        => $method,
            'header'        => implode("\r\n", $headers),
            'content'       => $body,
            'timeout'       => 10,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ];
    return (string)@file_get_contents($url, false, stream_context_create($opts));
}

function ok(array $data): void  { echo json_encode(['ok' => true,  'data' => $data]); }
function err(string $msg): void { echo json_encode(['ok' => false, 'msg'  => $msg]);  }
