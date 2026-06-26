<?php
/**
 * 자동매매 봇 (모의투자 / VTS)
 * ──────────────────────────────────────────────
 * 전략:
 *   매수 - 거래량 상위 50개 중 전일대비 +1%~+4% 상승 종목
 *          · 최대 3종목 동시 보유
 *          · 1종목당 100,000원
 *          · 이미 보유한 종목은 재매수 안 함
 *   매도 - 평가손익률 +5% 이상(익절) 또는 -3% 이하(손절)
 *
 * 실행:
 *   cron  : php /.../api/auto_trade.php
 *   web   : /investing/api/auto_trade.php?key=AUTO_TRADE_SECRET
 *           &dry=1 (실주문 없이 시뮬레이션)
 */

require_once __DIR__ . '/config.php';

/* ───────── 설정 ───────── */
const BUDGET_PER_STOCK = 10000000; // 1종목당 매수 예산(원)
const MAX_POSITIONS    = 5;        // 최대 동시 보유 종목 수
const BUY_PCT_MIN      = 1.0;      // 매수 하한 등락률(%)
const BUY_PCT_MAX      = 4.0;      // 매수 상한 등락률(%)
const TAKE_PROFIT_PCT  = 5.0;      // 익절 기준(%)
const STOP_LOSS_PCT    = -3.0;     // 손절 기준(%)
const LOG_FILE         = __DIR__ . '/auto_trade_log.json';
const LOG_MAX          = 300;      // 보관할 최대 로그 수

$isCli = (php_sapi_name() === 'cli');
$dry   = isset($_GET['dry']) && $_GET['dry'] === '1';

/* ───────── 접근 제어 (웹 호출 시) ───────── */
if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
    $secret = defined('AUTO_TRADE_SECRET') ? AUTO_TRADE_SECRET : '';
    if (!$secret || ($_GET['key'] ?? '') !== $secret) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'msg' => '접근 거부']);
        exit;
    }
}

/* ───────── 라우팅 ───────── */
$action = $_GET['action'] ?? 'run';
if ($action === 'status') { handleStatus(); }   // 현황만 조회 (매매 안 함)

/* ───────── 메인 (매매 실행) ───────── */
$report = ['ts' => date('Y-m-d H:i:s'), 'dry' => $dry, 'actions' => [], 'errors' => []];

try {
    if (!isMarketOpen()) {
        $report['skipped'] = '장 시간 아님 (평일 09:00~15:30만 거래)';
        finish($report);
    }

    // 1) 보유 종목 조회
    $holdings = fetchHoldings();             // [code => ['qty','avgPrice','curPrice','pnlRate','name']]
    $report['holdings_count'] = count($holdings);

    // 2) 매도 판단 (익절/손절)
    foreach ($holdings as $code => $h) {
        $rate = $h['pnlRate'];
        if ($rate >= TAKE_PROFIT_PCT || $rate <= STOP_LOSS_PCT) {
            $reason = $rate >= TAKE_PROFIT_PCT ? '익절' : '손절';
            $res = $dry
                ? ['dry' => true]
                : placeOrder($code, 'sell', 'market', (int)$h['qty'], 0);
            $report['actions'][] = [
                'type' => 'SELL', 'reason' => $reason, 'code' => $code,
                'name' => $h['name'], 'qty' => (int)$h['qty'],
                'pnlRate' => $rate, 'result' => $res,
            ];
        }
    }

    // 매도 반영을 위해 보유 수 재계산 (매도한 종목 제외)
    $soldCodes = array_column(array_filter($report['actions'], fn($a) => $a['type'] === 'SELL'), 'code');
    $activeCount = count($holdings) - count($soldCodes);

    // 3) 매수 판단 (여유 슬롯이 있을 때만)
    $slots = MAX_POSITIONS - $activeCount;
    if ($slots > 0) {
        $candidates = pickBuyCandidates(array_keys($holdings));
        foreach ($candidates as $c) {
            if ($slots <= 0) break;
            $price = $c['price'];
            if ($price <= 0 || $price > BUDGET_PER_STOCK) continue;
            $qty = intdiv(BUDGET_PER_STOCK, $price);
            if ($qty < 1) continue;

            $res = $dry
                ? ['dry' => true]
                : placeOrder($c['code'], 'buy', 'market', $qty, 0);
            $report['actions'][] = [
                'type' => 'BUY', 'reason' => '모멘텀', 'code' => $c['code'],
                'name' => $c['name'], 'qty' => $qty, 'price' => $price,
                'changePct' => $c['changePct'], 'result' => $res,
            ];
            $slots--;
        }
    }

    if (empty($report['actions'])) $report['actions'][] = ['type' => 'HOLD', 'msg' => '조건 충족 종목 없음'];

} catch (Throwable $e) {
    $report['errors'][] = $e->getMessage();
}

writeLog($report);
finish($report);

/* ═════════ 함수 ═════════ */

/** 현황 조회: 보유 종목 + 최근 로그 + 전략 설정 (매매 안 함) */
function handleStatus(): void {
    $out = [
        'ts'         => date('Y-m-d H:i:s'),
        'marketOpen' => isMarketOpen(),
        'config'     => [
            'budget' => BUDGET_PER_STOCK,
            'maxPos' => MAX_POSITIONS,
            'buyMin' => BUY_PCT_MIN,
            'buyMax' => BUY_PCT_MAX,
            'tp'     => TAKE_PROFIT_PCT,
            'sl'     => STOP_LOSS_PCT,
        ],
    ];
    try {
        $holdings = fetchHoldings();
        $list = [];
        foreach ($holdings as $code => $h) $list[] = ['code' => $code] + $h;
        $out['holdings'] = $list;
    } catch (Throwable $e) {
        $out['holdings']      = [];
        $out['holdingsError'] = $e->getMessage();
    }
    $log = file_exists(LOG_FILE) ? (json_decode(file_get_contents(LOG_FILE), true) ?? []) : [];
    $out['log'] = array_reverse(array_slice($log, -30));
    finish($out);
}

/** 장 운영 시간 체크 (KST 평일 09:00~15:30) */
function isMarketOpen(): bool {
    $tz  = new DateTimeZone('Asia/Seoul');
    $now = new DateTime('now', $tz);
    $dow = (int)$now->format('N');          // 1=월 ~ 7=일
    if ($dow >= 6) return false;            // 주말 제외
    $hm = (int)$now->format('Hi');          // 0930 형태
    return $hm >= 900 && $hm <= 1530;
}

/** 모의투자 보유 종목 조회 (VTTC8434R) */
function fetchHoldings(): array {
    $token = vtsToken();
    $params = [
        'CANO'                  => KIS_ACCOUNT_NO,
        'ACNT_PRDT_CD'          => KIS_ACCOUNT_CD,
        'AFHR_FLPR_YN'          => 'N',
        'OFL_YN'                => '',
        'INQR_DVSN'             => '02',
        'UNPR_DVSN'             => '01',
        'FUND_STTL_ICLD_YN'     => 'N',
        'FNCG_AMT_AUTO_RDPT_YN' => 'N',
        'PRCS_DVSN'             => '00',
        'CTX_AREA_FK100'        => '',
        'CTX_AREA_NK100'        => '',
    ];
    $raw = kisHttp('GET', KIS_VTS_BASE_URL . '/uapi/domestic-stock/v1/trading/inquire-balance',
        $params, '', vtsHeaders($token, 'VTTC8434R'));

    $r = json_decode($raw, true) ?? [];
    if (($r['rt_cd'] ?? '') !== '0') {
        throw new RuntimeException('잔고조회 실패: ' . ($r['msg1'] ?? $raw));
    }

    $holdings = [];
    foreach (($r['output1'] ?? []) as $row) {
        $qty = (int)($row['hldg_qty'] ?? 0);
        if ($qty <= 0) continue;
        $code = $row['pdno'] ?? '';
        $holdings[$code] = [
            'name'     => $row['prdt_name'] ?? '',
            'qty'      => $qty,
            'avgPrice' => (float)($row['pchs_avg_pric'] ?? 0),
            'curPrice' => (int)($row['prpr'] ?? 0),
            'pnlRate'  => (float)($row['evlu_pfls_rt'] ?? 0),
        ];
    }
    return $holdings;
}

/** 매수 후보 선정: 거래량 상위 50 중 +1%~+4% 상승 (보유 종목 제외) */
function pickBuyCandidates(array $heldCodes): array {
    $rows = fetchVolumeRank();
    $rows = array_slice($rows, 0, 50);

    $cand = [];
    foreach ($rows as $s) {
        $pct = $s['changePct'];
        if ($pct < BUY_PCT_MIN || $pct > BUY_PCT_MAX) continue;
        if (in_array($s['code'], $heldCodes, true)) continue;
        $cand[] = $s;
    }
    // 등락률 낮은 순(=막 오르기 시작한 종목 우선) — 과열 회피
    usort($cand, fn($a, $b) => $a['changePct'] <=> $b['changePct']);
    return $cand;
}

/** 거래량 순위 (실전 시세 API, FHPST01710000) — 코스피+코스닥 */
function fetchVolumeRank(): array {
    $merge = [];
    foreach (['0001', '1001'] as $i => $iscd) {
        if ($i > 0) usleep(400000);
        $params = [
            'FID_COND_MRKT_DIV_CODE'  => 'J',
            'FID_COND_SCR_DIV_CODE'   => '20171',
            'FID_INPUT_ISCD'          => $iscd,
            'FID_DIV_CLS_CODE'        => '0',
            'FID_BLNG_CLS_CODE'       => '0',
            'FID_TRGT_CLS_CODE'       => '111111111',
            'FID_TRGT_EXLS_CLS_CODE'  => '0000000000',
            'FID_INPUT_PRICE_1'       => '0',
            'FID_INPUT_PRICE_2'       => '0',
            'FID_VOL_CNT'             => '0',
            'FID_INPUT_DATE_1'        => '0',
        ];
        $raw = kisHttp('GET', KIS_BASE_URL . '/uapi/domestic-stock/v1/quotations/volume-rank',
            $params, '', realHeaders(realToken(), 'FHPST01710000'));
        $d = json_decode($raw, true) ?? [];
        foreach (($d['output'] ?? []) as $it) $merge[] = $it;
    }

    $rows = [];
    $seen = [];
    foreach ($merge as $it) {
        $code = $it['mksc_shrn_iscd'] ?? '';
        if (!$code || isset($seen[$code])) continue;
        $seen[$code] = true;
        $rows[] = [
            'code'      => $code,
            'name'      => $it['hts_kor_isnm'] ?? '',
            'price'     => (int)($it['stck_prpr'] ?? 0),
            'changePct' => (float)($it['prdy_ctrt'] ?? 0),
            'volume'    => (int)($it['acml_vol'] ?? 0),
        ];
    }
    usort($rows, fn($a, $b) => $b['volume'] <=> $a['volume']);
    return $rows;
}

/** 주문 실행 (VTS) */
function placeOrder(string $code, string $side, string $type, int $qty, int $price): array {
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

    $raw = kisHttp('POST', KIS_VTS_BASE_URL . '/uapi/domestic-stock/v1/trading/order-cash',
        [], $payload, vtsHeaders($token, $trId, true));

    $r = json_decode($raw, true) ?? [];
    if (($r['rt_cd'] ?? '') === '0') {
        return ['ok' => true, 'ordNo' => $r['output']['ODNO'] ?? '', 'msg' => $r['msg1'] ?? '주문완료'];
    }
    return ['ok' => false, 'msg' => $r['msg1'] ?? ('주문실패 rt_cd=' . ($r['rt_cd'] ?? '?'))];
}

/* ───────── 토큰 ───────── */

function realToken(): string {
    static $t = null;
    if ($t !== null) return $t;
    if (file_exists(TOKEN_CACHE_FILE)) {
        $c = json_decode(file_get_contents(TOKEN_CACHE_FILE), true) ?? [];
        if (!empty($c['access_token']) && time() < ($c['expires_at'] - 60)) return $t = $c['access_token'];
    }
    $raw = kisHttp('POST', KIS_BASE_URL . '/oauth2/tokenP', [], json_encode([
        'grant_type' => 'client_credentials',
        'appkey'     => KIS_APP_KEY,
        'appsecret'  => KIS_APP_SECRET,
    ]), ['Content-Type: application/json']);
    $j = json_decode($raw, true) ?? [];
    if (empty($j['access_token'])) throw new RuntimeException('실전 토큰 발급 실패: ' . $raw);
    file_put_contents(TOKEN_CACHE_FILE, json_encode([
        'access_token' => $j['access_token'], 'expires_at' => time() + 82800,
    ]));
    return $t = $j['access_token'];
}

function vtsToken(): string {
    static $t = null;
    if ($t !== null) return $t;
    if (file_exists(VTS_TOKEN_CACHE_FILE)) {
        $c = json_decode(file_get_contents(VTS_TOKEN_CACHE_FILE), true) ?? [];
        if (!empty($c['access_token']) && time() < ($c['expires_at'] - 300)) return $t = $c['access_token'];
    }
    $raw = kisHttp('POST', KIS_VTS_BASE_URL . '/oauth2/tokenP', [], json_encode([
        'grant_type' => 'client_credentials',
        'appkey'     => KIS_VTS_APP_KEY,
        'appsecret'  => KIS_VTS_APP_SECRET,
    ]), ['Content-Type: application/json']);
    $j = json_decode($raw, true) ?? [];
    if (empty($j['access_token'])) throw new RuntimeException('VTS 토큰 발급 실패: ' . $raw);
    file_put_contents(VTS_TOKEN_CACHE_FILE, json_encode([
        'access_token' => $j['access_token'], 'expires_at' => time() + (int)($j['expires_in'] ?? 86400),
    ]));
    return $t = $j['access_token'];
}

function realHeaders(string $token, string $trId): array {
    return [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
        'appkey: '    . KIS_APP_KEY,
        'appsecret: ' . KIS_APP_SECRET,
        'tr_id: '     . $trId,
        'custtype: P',
    ];
}

function vtsHeaders(string $token, string $trId, bool $json = false): array {
    $h = [
        'authorization: Bearer ' . $token,
        'appkey: '    . KIS_VTS_APP_KEY,
        'appsecret: ' . KIS_VTS_APP_SECRET,
        'tr_id: '     . $trId,
        'custtype: P',
    ];
    if ($json) array_unshift($h, 'Content-Type: application/json');
    return $h;
}

/* ───────── HTTP ───────── */
function kisHttp(string $method, string $url, array $params, string $body, array $headers): string {
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
        'http' => ['method' => $method, 'header' => implode("\r\n", $headers),
                   'content' => $body, 'timeout' => 10, 'ignore_errors' => true],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
    ];
    return (string)@file_get_contents($url, false, stream_context_create($opts));
}

/* ───────── 로그 / 출력 ───────── */
function writeLog(array $report): void {
    $log = [];
    if (file_exists(LOG_FILE)) $log = json_decode(file_get_contents(LOG_FILE), true) ?? [];
    $log[] = $report;
    if (count($log) > LOG_MAX) $log = array_slice($log, -LOG_MAX);
    file_put_contents(LOG_FILE, json_encode($log, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function finish(array $report): void {
    global $isCli;
    if ($isCli) {
        echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    } else {
        echo json_encode(['ok' => true, 'data' => $report], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
