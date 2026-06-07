<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// ===== 파일 캐시 (KIS API 호출 횟수 최소화) =====

function cacheGet(string $key): mixed {
    $file = __DIR__ . '/cache_' . $key . '.json';
    if (!file_exists($file)) return null;
    $data = json_decode(file_get_contents($file), true);
    if (!$data || time() > ($data['expires_at'] ?? 0)) return null;
    return $data['payload'];
}

function cacheSet(string $key, mixed $payload, int $ttl): void {
    $file = __DIR__ . '/cache_' . $key . '.json';
    file_put_contents($file, json_encode([
        'expires_at' => time() + $ttl,
        'payload'    => $payload,
    ]));
}

// ===== HTTP 요청 (file_get_contents, curl 불필요) =====

function httpRequest(string $url, array $headers, ?string $postBody = null): array {
    $opts = [
        'http' => [
            'method'        => $postBody !== null ? 'POST' : 'GET',
            'header'        => implode("\r\n", $headers),
            'timeout'       => 10,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer'      => false,
            'verify_peer_name' => false,
        ],
    ];
    if ($postBody !== null) {
        $opts['http']['content'] = $postBody;
    }

    $ctx = stream_context_create($opts);
    $res = @file_get_contents($url, false, $ctx);

    if ($res === false) {
        throw new RuntimeException('네트워크 오류: ' . $url);
    }

    $code = 0;
    foreach ($http_response_header as $h) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $h, $m)) {
            $code = (int)$m[1];
        }
    }
    if ($code !== 200) {
        throw new RuntimeException('HTTP ' . $code . ': ' . $res);
    }

    $decoded = json_decode($res, true) ?? [];

    // KIS API 비즈니스 에러 체크
    if (isset($decoded['rt_cd']) && $decoded['rt_cd'] !== '0') {
        throw new RuntimeException('KIS API 오류: ' . ($decoded['msg1'] ?? $res));
    }

    return $decoded;
}

// ===== 토큰 관리 =====

function getAccessToken(): string {
    if (file_exists(TOKEN_CACHE_FILE)) {
        $cache = json_decode(file_get_contents(TOKEN_CACHE_FILE), true);
        if ($cache && isset($cache['access_token'], $cache['expires_at'])) {
            if (time() < $cache['expires_at'] - 60) {
                return $cache['access_token'];
            }
        }
    }

    $data = httpRequest(
        KIS_BASE_URL . '/oauth2/tokenP',
        ['Content-Type: application/json'],
        json_encode([
            'grant_type' => 'client_credentials',
            'appkey'     => KIS_APP_KEY,
            'appsecret'  => KIS_APP_SECRET,
        ])
    );

    if (empty($data['access_token'])) {
        throw new RuntimeException('토큰 발급 실패: ' . json_encode($data));
    }

    file_put_contents(TOKEN_CACHE_FILE, json_encode([
        'access_token' => $data['access_token'],
        'expires_at'   => time() + 82800,
    ]));

    return $data['access_token'];
}

// ===== KIS REST GET =====

function kisGet(string $path, array $params, string $trId): array {
    $token = getAccessToken();
    $url   = KIS_BASE_URL . $path . '?' . http_build_query($params);

    return httpRequest($url, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
        'appkey: '    . KIS_APP_KEY,
        'appsecret: ' . KIS_APP_SECRET,
        'tr_id: '     . $trId,
        'custtype: P',
    ]);
}

// ===== 핸들러 =====

function fetchVolumeRankOnce(string $iscd): array {
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
    $data = kisGet('/uapi/domestic-stock/v1/quotations/volume-rank', $params, 'FHPST01710000');
    return $data['output'] ?? [];
}

function fetchBaseRank(): array {
    // 거래량 순위 원본 데이터 (30초 캐시) — 모든 필터의 기반
    $cached = cacheGet('base_rank');
    if ($cached !== null) return $cached;

    // 코스피(0001) + 코스닥(1001) 각각 호출 후 합산 → 100개 확보
    $kospi  = fetchVolumeRankOnce('0001');
    usleep(400000); // 400ms — rate limit 방지
    $kosdaq = fetchVolumeRankOnce('1001');

    $raw = array_merge($kospi, $kosdaq);

    $normalize = function (array $item): array {
        $price  = (int)($item['stck_prpr'] ?? 0);
        $volume = (int)($item['acml_vol']  ?? 0);
        return [
            'code'      => $item['mksc_shrn_iscd'] ?? '',
            'name'      => $item['hts_kor_isnm']   ?? '',
            'price'     => $price,
            'change'    => (int)($item['prdy_vrss']   ?? 0),
            'changePct' => (float)($item['prdy_ctrt'] ?? 0),
            'volume'    => $volume,
            'amount'    => $price * $volume,
        ];
    };

    $rows = array_map($normalize, $raw);

    // 코드 중복 제거 후 거래량 내림차순 정렬
    $seen = [];
    $unique = [];
    foreach ($rows as $r) {
        if ($r['code'] && !isset($seen[$r['code']])) {
            $seen[$r['code']] = true;
            $unique[] = $r;
        }
    }
    usort($unique, fn($a, $b) => $b['volume'] <=> $a['volume']);

    $rows = array_slice($unique, 0, 100);

    cacheSet('base_rank', $rows, 30);
    return $rows;
}

function handleVolumeRank(): void {
    $type   = preg_replace('/\W/', '', $_GET['type']   ?? 'volume');
    $period = preg_replace('/\W/', '', $_GET['period'] ?? 'realtime');

    $cacheKey = 'rank_' . $type . '_' . $period;
    $cached   = cacheGet($cacheKey);
    if ($cached !== null) { echo json_encode(['ok' => true, 'data' => $cached, 'cached' => true]); return; }

    $rows = fetchBaseRank();

    // 타입별 정렬
    switch ($type) {
        case 'amount':
            usort($rows, fn($a, $b) => $b['amount'] <=> $a['amount']);
            break;
        case 'rise':
            $rows = array_values(array_filter($rows, fn($s) => $s['changePct'] > 0));
            usort($rows, fn($a, $b) => $b['changePct'] <=> $a['changePct']);
            break;
        case 'fall':
            $rows = array_values(array_filter($rows, fn($s) => $s['changePct'] < 0));
            usort($rows, fn($a, $b) => $a['changePct'] <=> $b['changePct']);
            break;
    }

    // 실시간: 100개, 기간 필터: 30개 (종목당 API 1회 호출 → 30개 = 약 7.5초)
    $limit  = ($period === 'realtime') ? 100 : 30;
    $top20  = array_slice(array_values($rows), 0, $limit);

    // 기간 필터 — 실시간이 아니면 기간 수익률로 changePct 교체
    if ($period !== 'realtime') {
        $top20 = applyPeriodReturns($top20, $period);
    }

    // 순위 부여
    $result = [];
    foreach ($top20 as $i => $item) {
        $result[] = ['rank' => $i + 1] + $item;
    }

    $ttl = ($period === 'realtime') ? 30 : 300; // 기간 데이터는 5분 캐시
    cacheSet($cacheKey, $result, $ttl);
    echo json_encode(['ok' => true, 'data' => $result]);
}

// 기간별 수익률 계산 — 각 종목의 일봉 데이터에서 기간 시작가 추출
function applyPeriodReturns(array $stocks, string $period): array {
    // 기간별 조회 시작일 (충분한 여유 포함)
    $lookback = ['1d' => 5, '1w' => 14, '1m' => 40, '3m' => 100, '6m' => 200, '1y' => 400];
    $days      = $lookback[$period] ?? 14;
    $startDate = date('Ymd', strtotime("-{$days} days"));
    $endDate   = date('Ymd');

    foreach ($stocks as &$stock) {
        usleep(250000); // 250ms 간격 (rate limit 방지)
        try {
            $data = kisGet(
                '/uapi/domestic-stock/v1/quotations/inquire-daily-itemchartprice',
                [
                    'FID_COND_MRKT_DIV_CODE' => 'J',
                    'FID_INPUT_ISCD'         => $stock['code'],
                    'FID_INPUT_DATE_1'       => $startDate,
                    'FID_INPUT_DATE_2'       => $endDate,
                    'FID_PERIOD_DIV_CODE'    => 'D',
                    'FID_ORG_ADJ_PRC'        => '0',
                ],
                'FHKST03010100'
            );
            // output2: 최신 → 과거 순. 마지막 = 기간 시작가
            $candles   = $data['output2'] ?? [];
            $startClose = (int)(end($candles)['stck_clpr'] ?? 0);
            if ($startClose > 0 && $stock['price'] > 0) {
                $stock['changePct'] = round(($stock['price'] - $startClose) / $startClose * 100, 2);
                $stock['change']    = $stock['price'] - $startClose;
            }
        } catch (Throwable) {
            // 실패 시 일간 등락률 유지
        }
    }
    unset($stock);
    return $stocks;
}

function handleIndex(): void {
    // 캐시 30초
    $cached = cacheGet('index');
    if ($cached !== null) { echo json_encode(['ok' => true, 'data' => $cached, 'cached' => true]); return; }

    $indices = [
        ['code' => '0001', 'name' => '코스피',    'market' => 'U'],
        ['code' => '1001', 'name' => '코스닥',    'market' => 'U'],
        ['code' => '2001', 'name' => '코스피200', 'market' => 'U'],
    ];

    $result = [];
    foreach ($indices as $i => $idx) {
        if ($i > 0) usleep(600000); // 600ms 간격
        $data  = kisGet(
            '/uapi/domestic-stock/v1/quotations/inquire-index-price',
            ['FID_COND_MRKT_DIV_CODE' => $idx['market'], 'FID_INPUT_ISCD' => $idx['code']],
            'FHPUP02100000'
        );
        $out   = $data['output'] ?? [];
        $sign  = $out['prdy_vrss_sign'] ?? '3';
        $neg   = ($sign === '4' || $sign === '5') ? -1 : 1;
        $value = (float)($out['bstp_nmix_prpr']      ?? 0);
        $chg   = (float)($out['bstp_nmix_prdy_vrss'] ?? 0) * $neg;
        $rawPct = (float)($out['bstp_nmix_prdy_ctrt'] ?? $out['prdy_ctrt'] ?? 0);
        $pct    = $rawPct !== 0.0
            ? $rawPct * $neg
            : ($value - $chg > 0 ? round($chg / ($value - $chg) * 100, 2) : 0);

        $result[] = ['code' => $idx['code'], 'name' => $idx['name'],
                     'value' => $value, 'change' => $chg, 'changePct' => $pct];
    }

    cacheSet('index', $result, 30);
    echo json_encode(['ok' => true, 'data' => $result]);
}

function handlePrice(): void {
    $code = preg_replace('/\D/', '', $_GET['code'] ?? '');
    if (strlen($code) !== 6) { echo json_encode(['ok' => false, 'msg' => '종목코드 오류']); return; }

    $cacheKey = 'price_' . $code;
    $cached   = cacheGet($cacheKey);
    if ($cached !== null) { echo json_encode(['ok' => true, 'data' => $cached, 'cached' => true]); return; }

    $data = kisGet(
        '/uapi/domestic-stock/v1/quotations/inquire-price',
        ['FID_COND_MRKT_DIV_CODE' => 'J', 'FID_INPUT_ISCD' => $code],
        'FHKST01010100'
    );
    $out  = $data['output'] ?? [];
    $sign = $out['prdy_vrss_sign'] ?? '3';
    $neg  = ($sign === '4' || $sign === '5') ? -1 : 1;

    $result = [
        'code'      => $code,
        'name'      => $out['hts_kor_isnm'] ?? '',
        'price'     => (int)($out['stck_prpr'] ?? 0),
        'change'    => (int)($out['prdy_vrss'] ?? 0) * $neg,
        'changePct' => (float)($out['prdy_ctrt'] ?? 0) * $neg,
        'open'      => (int)($out['stck_oprc'] ?? 0),
        'high'      => (int)($out['stck_hgpr'] ?? 0),
        'low'       => (int)($out['stck_lwpr'] ?? 0),
        'volume'    => (int)($out['acml_vol']  ?? 0),
        'high52w'   => (int)($out['w52_hgpr']  ?? 0),
        'low52w'    => (int)($out['w52_lwpr']  ?? 0),
        'per'       => $out['per'] ?? '',
        'pbr'       => $out['pbr'] ?? '',
        'eps'       => $out['eps'] ?? '',
    ];

    cacheSet($cacheKey, $result, 30);
    echo json_encode(['ok' => true, 'data' => $result]);
}

function handleChart(): void {
    $code   = preg_replace('/\D/', '', $_GET['code'] ?? '');
    $period = in_array($_GET['period'] ?? '', ['D','W','M','Y']) ? $_GET['period'] : 'D';
    if (strlen($code) !== 6) { echo json_encode(['ok' => false, 'msg' => '종목코드 오류']); return; }

    $cacheKey = 'chart_' . $code . '_' . $period;
    $cached   = cacheGet($cacheKey);
    if ($cached !== null) { echo json_encode(['ok' => true, 'data' => $cached, 'cached' => true]); return; }

    $data = kisGet(
        '/uapi/domestic-stock/v1/quotations/inquire-daily-itemchartprice',
        [
            'FID_COND_MRKT_DIV_CODE' => 'J',
            'FID_INPUT_ISCD'         => $code,
            'FID_INPUT_DATE_1'       => date('Ymd', strtotime('-3 months')),
            'FID_INPUT_DATE_2'       => date('Ymd'),
            'FID_PERIOD_DIV_CODE'    => $period,
            'FID_ORG_ADJ_PRC'        => '0',
        ],
        'FHKST03010100'
    );

    $candles = array_map(fn($c) => [
        'date'   => $c['stck_bsop_date'] ?? '',
        'open'   => (int)($c['stck_oprc'] ?? 0),
        'high'   => (int)($c['stck_hgpr'] ?? 0),
        'low'    => (int)($c['stck_lwpr'] ?? 0),
        'close'  => (int)($c['stck_clpr'] ?? 0),
        'volume' => (int)($c['acml_vol']  ?? 0),
    ], $data['output2'] ?? []);

    $result = array_reverse($candles);
    cacheSet($cacheKey, $result, 300); // 차트는 5분 캐시
    echo json_encode(['ok' => true, 'data' => $result]);
}

// ===== 지금 뜨는 카테고리 =====
function handleTrending(): void {
    $cached = cacheGet('trending');
    if ($cached !== null) { echo json_encode(['ok'=>true,'data'=>$cached,'cached'=>true]); return; }

    // 기반 데이터: 거래량 순위 100개 (이미 캐시됨, 추가 API 호출 없음)
    $baseRank = fetchBaseRank();
    $byCode   = [];
    foreach ($baseRank as $s) $byCode[$s['code']] = $s;

    $categories = [
        ['name' => 'AI·반도체',  'codes' => ['005930','000660','042700','403870','058470','036930']],
        ['name' => '방산/우주',   'codes' => ['012450','047810','079550','064350','000140','006340']],
        ['name' => '2차전지',    'codes' => ['373220','006400','247540','096770','005490','009150']],
        ['name' => '바이오·헬스', 'codes' => ['207940','068270','000100','128940','185750','326030']],
        ['name' => '자동차',     'codes' => ['005380','000270','012330','018880','204320','241560']],
        ['name' => '금융·은행',  'codes' => ['105560','055550','086790','316140','032830','139480']],
    ];

    $result = [];
    foreach ($categories as $cat) {
        $matched = [];
        foreach ($cat['codes'] as $code) {
            if (isset($byCode[$code])) $matched[] = $byCode[$code];
            // 거래량 순위에 없으면 개별 가격 캐시에서 보완
            elseif (($pc = cacheGet('price_' . $code)) !== null) $matched[] = $pc;
        }
        if (empty($matched)) {
            $result[] = ['name'=>$cat['name'],'stocks'=>'-','change'=>0,'up'=>true];
            continue;
        }
        $avgChange = round(array_sum(array_column($matched,'changePct')) / count($matched), 2);
        $names     = array_slice(array_column($matched,'name'), 0, 2);
        $result[]  = [
            'name'   => $cat['name'],
            'stocks' => implode(', ', $names) . (count($matched) > 2 ? ' 외' : ''),
            'change' => $avgChange,
            'up'     => $avgChange >= 0,
        ];
    }

    cacheSet('trending', $result, 60);
    echo json_encode(['ok' => true, 'data' => $result]);
}

// ===== 종목별 투자자 동향 =====
function handleInvestorStock(): void {
    $code = preg_replace('/\D/', '', $_GET['code'] ?? '');
    if (strlen($code) !== 6) { echo json_encode(['ok'=>false,'msg'=>'코드 오류']); return; }

    $cacheKey = 'investor_stk_' . $code;
    $cached   = cacheGet($cacheKey);
    if ($cached !== null) { echo json_encode(['ok'=>true,'data'=>$cached,'cached'=>true]); return; }

    $data = kisGet(
        '/uapi/domestic-stock/v1/quotations/inquire-investor',
        ['FID_COND_MRKT_DIV_CODE'=>'J','FID_INPUT_ISCD'=>$code],
        'FHKST01010900'
    );
    $o = $data['output'] ?? [];

    $result = [
        ['label'=>'개인',   'qty'=>(int)($o['prsn_ntby_qty']??0), 'amt'=>(int)($o['prsn_ntby_tr_pbmn']??0)],
        ['label'=>'외국인', 'qty'=>(int)($o['frgn_ntby_qty']??0), 'amt'=>(int)($o['frgn_ntby_tr_pbmn']??0)],
        ['label'=>'기관',   'qty'=>(int)($o['orgn_ntby_qty']??0), 'amt'=>(int)($o['orgn_ntby_tr_pbmn']??0)],
    ];

    cacheSet($cacheKey, $result, 30);
    echo json_encode(['ok'=>true,'data'=>$result]);
}

// ===== 투자자 동향 (코스피 전체) =====
function handleInvestorTrend(): void {
    $cached = cacheGet('investor_trend');
    if ($cached !== null) { echo json_encode(['ok'=>true,'data'=>$cached,'cached'=>true]); return; }

    // 코스피(0001) 투자자별 매매현황
    $data = kisGet(
        '/uapi/domestic-stock/v1/quotations/inquire-investor',
        ['FID_COND_MRKT_DIV_CODE' => 'J', 'FID_INPUT_ISCD' => '0001'],
        'FHKST01010900'
    );
    $o = $data['output'] ?? [];

    // 순매수금액(억원) — tr_pbmn 필드 없으면 수량 사용
    $toAmt = function(string $key) use ($o): int {
        $amt = (int)($o[$key . '_ntby_tr_pbmn'] ?? 0); // 순매수 거래대금
        if ($amt !== 0) return (int)round($amt / 100000000); // 원 → 억
        return (int)($o[$key . '_ntby_qty'] ?? 0);            // 없으면 수량
    };

    $result = [
        ['label' => '개인',   'net' => $toAmt('prsn')],
        ['label' => '외국인', 'net' => $toAmt('frgn')],
        ['label' => '기관',   'net' => $toAmt('orgn')],
    ];

    cacheSet('investor_trend', $result, 60);
    echo json_encode(['ok' => true, 'data' => $result]);
}

// ===== 종목 검색 (NAVER Finance 자동완성) =====

function handleSearch(): void {
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 1) {
        echo json_encode(['ok' => true, 'data' => []]);
        return;
    }

    $cacheKey = 'search_' . md5($q);
    $cached   = cacheGet($cacheKey);
    if ($cached !== null) {
        echo json_encode(['ok' => true, 'data' => $cached]);
        return;
    }

    // ac.stock.naver.com — 국내주식 자동완성 (JSON 응답 확인)
    $url  = 'https://ac.stock.naver.com/ac?q=' . urlencode($q) . '&target=stock,etf';
    $opts = [
        'http' => [
            'method'        => 'GET',
            'header'        => implode("\r\n", [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Referer: https://finance.naver.com/',
                'Accept: application/json',
                'Accept-Encoding: identity',
            ]),
            'timeout'       => 5,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ];
    $raw = @file_get_contents($url, false, stream_context_create($opts));

    $results = [];
    if ($raw) {
        $json  = json_decode($raw, true) ?? [];
        $items = $json['items'] ?? [];
        foreach ($items as $item) {
            $code = $item['code'] ?? '';
            $name = $item['name'] ?? '';
            // 국내 종목만 (6자리 숫자 코드, KOR)
            if (!$code || !$name) continue;
            if (!preg_match('/^\d{6}$/', $code)) continue;
            if (($item['nationCode'] ?? '') !== 'KOR') continue;
            $category = $item['category'] ?? 'stock';
            $results[] = [
                'code' => $code,
                'name' => $name,
                'type' => strtoupper($category) === 'ETF' ? 'ETF' : 'STOCK',
                'market' => $item['typeName'] ?? '',
            ];
        }
    }

    cacheSet($cacheKey, $results, 300);
    echo json_encode(['ok' => true, 'data' => $results]);
}

// ===== 라우팅 =====

$action = $_GET['action'] ?? '';

try {
    match ($action) {
        'volume_rank'    => handleVolumeRank(),
        'trending'       => handleTrending(),
        'investor_trend' => handleInvestorTrend(),
        'investor_stock' => handleInvestorStock(),
        'index'          => handleIndex(),
        'price'          => handlePrice(),
        'chart'          => handleChart(),
        'search'         => handleSearch(),
        default          => throw new RuntimeException('알 수 없는 action: ' . htmlspecialchars($action)),
    };
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}
