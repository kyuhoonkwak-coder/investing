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
/* ── 종목별 동적 익절/손절 (변동성 ATR 기반) ── */
const TP_ATR_MULT      = 2.0;      // 익절 = ATR% × 2.0
const SL_ATR_MULT      = 1.5;      // 손절 = ATR% × 1.5
const TP_MIN           = 3.0;      // 익절 하한(%)
const TP_MAX           = 12.0;     // 익절 상한(%)
const SL_MIN           = 2.0;      // 손절 하한(%)
const SL_MAX           = 7.0;      // 손절 상한(%)
const ATR_DAYS         = 10;       // 변동성 산출 일수

/* 변동성 데이터가 없을 때 사용할 기본 익절/손절(%) */
const TAKE_PROFIT_PCT  = 5.0;
const STOP_LOSS_PCT    = -3.0;

const POS_FILE         = __DIR__ . '/positions.json';  // 종목별 목표가/손절가 저장

/* ── 트레일링 스톱 (고점 대비 하락 시 매도) ── */
const TRAIL_ARM_PCT    = 3.0;      // 이 수익률 이상 도달해야 트레일링 작동
const TRAIL_GIVEBACK_MIN = 1.5;    // 고점 반납 허용 하한(%)
const TRAIL_GIVEBACK_MAX = 5.0;    // 고점 반납 허용 상한(%)
const TRAIL_ATR_MULT   = 1.0;      // 반납 허용폭 = ATR% × 1.0 (위 범위로 제한)

/* ── 시장 방어 필터 ── */
const MARKET_HALT_PCT  = -1.5;     // 코스피 이 % 이하 하락 시 신규 매수 중단
const DAILY_LOSS_LIMIT = 500000;   // 당일 실현손실 이 금액(원) 초과 시 매수 중단

/* ── 성과 기록 ── */
const TRADES_FILE      = __DIR__ . '/trades.json';
const TRADES_MAX       = 500;      // 보관할 최대 체결기록 수

/* ── 품질 필터 (잡주 회피 + 추세 확인) ── */
const MIN_PRICE        = 2000;            // 최소 주가(원) — 동전주 제외
const MIN_MARKET_CAP   = 300000000000;    // 최소 시가총액 3,000억 — 소형 잡주 제외
const REQUIRE_ABOVE_MA5 = true;           // 5일 이동평균선 위 종목만 매수(상승추세)
const MA5_MAX_CHECKS   = 15;              // 5일선 확인 API 호출 상한(과도한 호출 방지)

const LOG_FILE         = __DIR__ . '/auto_trade_log.json';
const LOG_MAX          = 300;      // 보관할 최대 로그 수

$isCli = (php_sapi_name() === 'cli');
if ($isCli && isset($argv[1])) $_GET['action'] = $argv[1];   // php auto_trade.php candidates
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
if ($action === 'status')     { handleStatus(); }      // 현황만 조회 (매매 안 함)
if ($action === 'candidates') { handleCandidates(); }  // 매수 후보 미리보기 (주문 안 함)

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

    $positions = loadPositions();            // [code => ['tp','sl','atrPct','peak',...]]
    $positions = prunePositions($positions, array_keys($holdings));  // 미보유 종목 정리

    // 2) 매도 판단 (손절 / 익절 / 트레일링)
    foreach ($holdings as $code => $h) {
        $rate = $h['pnlRate'];
        $p    = $positions[$code] ?? [];
        $tp   = $p['tp'] ?? TAKE_PROFIT_PCT;
        $sl   = $p['sl'] ?? STOP_LOSS_PCT;

        // 고점 갱신 (트레일링용)
        $peak = max($p['peak'] ?? $rate, $rate);
        $positions[$code]['peak'] = $peak;

        // 반납 허용폭 = ATR% × 배수 (범위 제한)
        $atr      = (float)($p['atrPct'] ?? 0);
        $giveback = $atr > 0
            ? min(TRAIL_GIVEBACK_MAX, max(TRAIL_GIVEBACK_MIN, $atr * TRAIL_ATR_MULT))
            : TRAIL_GIVEBACK_MIN;

        $reason = null;
        if ($rate <= $sl)                                              $reason = '손절';
        elseif ($rate >= $tp)                                         $reason = '익절';
        elseif ($peak >= TRAIL_ARM_PCT && ($peak - $rate) >= $giveback) $reason = '트레일링';

        if ($reason !== null) {
            $res = $dry
                ? ['dry' => true]
                : placeOrder($code, 'sell', 'market', (int)$h['qty'], 0);
            $report['actions'][] = [
                'type' => 'SELL', 'reason' => $reason, 'code' => $code,
                'name' => $h['name'], 'qty' => (int)$h['qty'],
                'pnlRate' => $rate, 'tp' => $tp, 'sl' => $sl, 'peak' => round($peak, 2),
                'result' => $res,
            ];
            if (!$dry && ($res['ok'] ?? false)) {
                recordTrade($code, $h, $rate, $reason, $p);  // 성과 기록
                unset($positions[$code]);
            }
        }
    }

    // 매도 반영을 위해 보유 수 재계산 (매도한 종목 제외)
    $soldCodes = array_column(array_filter($report['actions'], fn($a) => $a['type'] === 'SELL'), 'code');
    $activeCount = count($holdings) - count($soldCodes);

    // 3) 시장 방어 필터 — 신규 매수 허용 여부 (빈 슬롯 있을 때만 확인)
    $slots   = MAX_POSITIONS - $activeCount;
    $buyHalt = null;
    if ($slots > 0) {
        $kospi     = fetchKospiChange();
        $todayLoss = todayRealizedPnl();
        if ($kospi !== null && $kospi <= MARKET_HALT_PCT) {
            $buyHalt = "코스피 {$kospi}% — 급락 매수중단";
        } elseif ($todayLoss <= -DAILY_LOSS_LIMIT) {
            $buyHalt = '당일 손실한도 도달 — 매수중단';
        }
        $report['market'] = ['kospi' => $kospi, 'todayPnl' => $todayLoss, 'buyHalt' => $buyHalt];
        if ($buyHalt !== null) $slots = 0;
    }

    // 4) 매수 판단 (여유 슬롯 + 방어 통과 시에만)
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
                'changePct' => $c['changePct'],
                'tp' => $c['tp'] ?? null, 'sl' => $c['sl'] ?? null, 'atrPct' => $c['atrPct'] ?? null,
                'result' => $res,
            ];
            // 매수 성공 시 종목별 목표가/손절가 저장
            if (!$dry && ($res['ok'] ?? false)) {
                $positions[$c['code']] = [
                    'tp'         => $c['tp']     ?? TAKE_PROFIT_PCT,
                    'sl'         => $c['sl']     ?? STOP_LOSS_PCT,
                    'atrPct'     => $c['atrPct'] ?? null,
                    'entryPrice' => $price,
                    'entryTs'    => date('Y-m-d H:i:s'),
                    'peak'       => 0.0,
                ];
            }
            $slots--;
        }
    }

    if (empty($report['actions'])) {
        $msg = $buyHalt ?? '조건 충족 종목 없음';
        $report['actions'][] = ['type' => 'HOLD', 'msg' => $msg];
    }

    savePositions($positions);

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
            'budget'   => BUDGET_PER_STOCK,
            'maxPos'   => MAX_POSITIONS,
            'buyMin'   => BUY_PCT_MIN,
            'buyMax'   => BUY_PCT_MAX,
            'dynamic'  => true,
            'tpMin'    => TP_MIN, 'tpMax' => TP_MAX,
            'slMin'    => SL_MIN, 'slMax' => SL_MAX,
            'minPrice' => MIN_PRICE,
            'minCap'   => MIN_MARKET_CAP,
            'ma5'      => REQUIRE_ABOVE_MA5,
            'trail'    => TRAIL_ARM_PCT,
            'haltPct'  => MARKET_HALT_PCT,
            'lossLimit'=> DAILY_LOSS_LIMIT,
        ],
    ];
    try {
        $holdings  = fetchHoldings();
        $positions = loadPositions();
        $list = [];
        foreach ($holdings as $code => $h) {
            $p = $positions[$code] ?? [];
            $h['tp']   = $p['tp']   ?? TAKE_PROFIT_PCT;
            $h['sl']   = $p['sl']   ?? STOP_LOSS_PCT;
            $h['peak'] = $p['peak'] ?? null;
            $list[] = ['code' => $code] + $h;
        }
        $out['holdings'] = $list;
    } catch (Throwable $e) {
        $out['holdings']      = [];
        $out['holdingsError'] = $e->getMessage();
    }
    $out['stats']  = computeStats();
    $out['trades'] = array_reverse(array_slice(loadTrades(), -20));
    $log = file_exists(LOG_FILE) ? (json_decode(file_get_contents(LOG_FILE), true) ?? []) : [];
    $out['log'] = array_reverse(array_slice($log, -30));
    finish($out);
}

/** 매수 후보 미리보기: 필터 통과 종목 + 통과/탈락 사유 (주문·VTS 없음, 실전 시세만) */
function handleCandidates(): void {
    $rows = array_slice(fetchVolumeRank(), 0, 50);

    // 저비용 필터 통과 종목
    $passed = [];
    foreach ($rows as $s) {
        if ($s['changePct'] < BUY_PCT_MIN || $s['changePct'] > BUY_PCT_MAX) continue;
        if ($s['price'] < MIN_PRICE) continue;
        if (($s['warn'] ?? '00') !== '00') continue;
        if ($s['marketCap'] > 0 && $s['marketCap'] < MIN_MARKET_CAP) continue;
        $passed[] = $s;
    }
    usort($passed, fn($a, $b) => $b['amount'] <=> $a['amount']);

    // 5일선 확인 + 종목별 변동성 익절/손절 (상한 내)
    $out = [];
    $checks = 0;
    foreach ($passed as $s) {
        if ($checks < MA5_MAX_CHECKS) {
            $checks++;
            usleep(250000);
            $candles = fetchDaily($s['code']);
            $ma5  = computeMA5($candles);
            $plan = planTpSl(computeATRpct($candles));
            $s['ma5']     = $ma5 > 0 ? (int)round($ma5) : 0;
            $s['ma5pass'] = $ma5 > 0 ? ($s['price'] >= $ma5) : null;
            $s['atrPct']  = $plan['atrPct'];
            $s['tp']      = $plan['tp'];
            $s['sl']      = $plan['sl'];
        } else {
            $s['ma5'] = 0; $s['ma5pass'] = null;
        }
        $out[] = $s;
    }

    $buyList = array_values(array_filter($out, fn($s) => $s['ma5pass'] === true));
    finish([
        'ts'         => date('Y-m-d H:i:s'),
        'passedCount'=> count($out),
        'buyCount'   => min(count($buyList), MAX_POSITIONS),
        'wouldBuy'   => array_slice($buyList, 0, MAX_POSITIONS),
        'candidates' => $out,
    ]);
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

/**
 * 매수 후보 선정 (다단계 품질 필터)
 *   1) 거래량 상위 50 풀
 *   2) 등락률 +1~+4% / 보유중복 제외 / 최소주가 / 시가총액 / 관리·경고종목 제외
 *   3) 거래대금 많은 순 정렬 (유동성 우량 우선)
 *   4) 5일선 위 종목만 (상승추세 확인) — 호출 상한 내에서
 */
function pickBuyCandidates(array $heldCodes): array {
    $rows = fetchVolumeRank();
    $rows = array_slice($rows, 0, 50);

    // 2) 저비용 필터
    $cand = [];
    foreach ($rows as $s) {
        $pct = $s['changePct'];
        if ($pct < BUY_PCT_MIN || $pct > BUY_PCT_MAX) continue;       // 모멘텀 구간
        if (in_array($s['code'], $heldCodes, true))    continue;       // 보유 중복
        if ($s['price'] < MIN_PRICE)                   continue;       // 동전주 제외
        if (($s['warn'] ?? '00') !== '00')             continue;       // 관리/경고/위험 제외
        if ($s['marketCap'] > 0 && $s['marketCap'] < MIN_MARKET_CAP) continue; // 소형 잡주 제외
        $cand[] = $s;
    }

    // 3) 거래대금 많은 순 — 실거래 활발한 종목 우선
    usort($cand, fn($a, $b) => $b['amount'] <=> $a['amount']);

    // 4) 5일선 위 종목만 통과 + 종목별 변동성 기반 익절/손절 산출
    if (!REQUIRE_ABOVE_MA5) {
        return array_map(function ($s) { return $s + planTpSl(0); }, $cand);
    }

    $final  = [];
    $checks = 0;
    foreach ($cand as $s) {
        if ($checks >= MA5_MAX_CHECKS || count($final) >= MAX_POSITIONS) break;
        $checks++;
        usleep(250000);                       // rate limit 방지
        $candles = fetchDaily($s['code']);
        $ma5 = computeMA5($candles);
        if ($ma5 > 0 && $s['price'] >= $ma5) {
            $atr  = computeATRpct($candles);
            $plan = planTpSl($atr);
            $s['ma5']    = (int)round($ma5);
            $s['atrPct'] = round($atr, 2);
            $s['tp']     = $plan['tp'];
            $s['sl']     = $plan['sl'];
            $final[]     = $s;
        }
    }
    return $final;
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
        $price  = (int)($it['stck_prpr'] ?? 0);
        $volume = (int)($it['acml_vol'] ?? 0);
        $shares = (int)($it['lstn_stcn'] ?? 0);         // 상장주수
        $rows[] = [
            'code'      => $code,
            'name'      => $it['hts_kor_isnm'] ?? '',
            'price'     => $price,
            'changePct' => (float)($it['prdy_ctrt'] ?? 0),
            'volume'    => $volume,
            'amount'    => (int)($it['acml_tr_pbmn'] ?? ($price * $volume)),  // 거래대금
            'marketCap' => $shares > 0 ? ($shares * $price) : 0,             // 시가총액
            'warn'      => $it['mrkt_warn_cls_code'] ?? '00',               // 시장경고(00=정상)
        ];
    }
    usort($rows, fn($a, $b) => $b['volume'] <=> $a['volume']);
    return $rows;
}

/** 일봉 데이터 조회 (최신→과거 순) — MA5·변동성 계산용 */
function fetchDaily(string $code): array {
    $params = [
        'FID_COND_MRKT_DIV_CODE' => 'J',
        'FID_INPUT_ISCD'         => $code,
        'FID_INPUT_DATE_1'       => date('Ymd', strtotime('-30 days')),
        'FID_INPUT_DATE_2'       => date('Ymd'),
        'FID_PERIOD_DIV_CODE'    => 'D',
        'FID_ORG_ADJ_PRC'        => '0',
    ];
    $raw = kisHttp('GET', KIS_BASE_URL . '/uapi/domestic-stock/v1/quotations/inquire-daily-itemchartprice',
        $params, '', realHeaders(realToken(), 'FHKST03010100'));
    $d = json_decode($raw, true) ?? [];
    return $d['output2'] ?? [];
}

/** 5일 이동평균선 (일봉 종가 5개 평균) */
function computeMA5(array $candles): float {
    $closes = [];
    foreach ($candles as $c) {
        $v = (int)($c['stck_clpr'] ?? 0);
        if ($v > 0) $closes[] = $v;
        if (count($closes) >= 5) break;
    }
    return count($closes) >= 5 ? array_sum($closes) / 5 : 0.0;
}

/** 변동성 ATR% = 최근 ATR_DAYS일 평균 일중 변동폭(고가-저가)/종가 (%) */
function computeATRpct(array $candles): float {
    $ranges = [];
    foreach ($candles as $c) {
        $hi = (int)($c['stck_hgpr'] ?? 0);
        $lo = (int)($c['stck_lwpr'] ?? 0);
        $cl = (int)($c['stck_clpr'] ?? 0);
        if ($hi > 0 && $lo > 0 && $cl > 0) $ranges[] = ($hi - $lo) / $cl * 100;
        if (count($ranges) >= ATR_DAYS) break;
    }
    return $ranges ? array_sum($ranges) / count($ranges) : 0.0;
}

/** 변동성 → 종목별 익절/손절(%) (상·하한 적용) */
function planTpSl(float $atrPct): array {
    if ($atrPct <= 0) return ['tp' => TAKE_PROFIT_PCT, 'sl' => STOP_LOSS_PCT, 'atrPct' => 0.0];
    $tp = min(TP_MAX, max(TP_MIN, $atrPct * TP_ATR_MULT));
    $sl = -min(SL_MAX, max(SL_MIN, $atrPct * SL_ATR_MULT));
    return ['tp' => round($tp, 1), 'sl' => round($sl, 1), 'atrPct' => round($atrPct, 2)];
}

/* ───────── 종목별 목표가/손절가 저장 ───────── */
function loadPositions(): array {
    if (!file_exists(POS_FILE)) return [];
    return json_decode(file_get_contents(POS_FILE), true) ?? [];
}
function savePositions(array $pos): void {
    file_put_contents(POS_FILE, json_encode($pos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}
/** 더 이상 보유하지 않는 종목 기록 제거 */
function prunePositions(array $pos, array $heldCodes): array {
    foreach (array_keys($pos) as $code) {
        if (!in_array($code, $heldCodes, true)) unset($pos[$code]);
    }
    return $pos;
}

/* ───────── 성과 기록 (체결 저널) ───────── */
function loadTrades(): array {
    if (!file_exists(TRADES_FILE)) return [];
    return json_decode(file_get_contents(TRADES_FILE), true) ?? [];
}

/** 매도 체결을 저널에 기록 (실현 손익 계산) */
function recordTrade(string $code, array $h, float $pnlRate, string $reason, array $pos): void {
    $qty   = (int)$h['qty'];
    $avg   = (float)($h['avgPrice'] ?? $pos['entryPrice'] ?? 0);
    $pnlKrw = (int)round($qty * $avg * $pnlRate / 100);   // 실현손익(원, 근사)
    $trades = loadTrades();
    $trades[] = [
        'ts'      => date('Y-m-d H:i:s'),
        'code'    => $code,
        'name'    => $h['name'] ?? '',
        'qty'     => $qty,
        'avgPrice'=> (int)round($avg),
        'exitPrice'=> (int)($h['curPrice'] ?? 0),
        'pnlRate' => round($pnlRate, 2),
        'pnlKrw'  => $pnlKrw,
        'reason'  => $reason,
        'entryTs' => $pos['entryTs'] ?? null,
    ];
    if (count($trades) > TRADES_MAX) $trades = array_slice($trades, -TRADES_MAX);
    file_put_contents(TRADES_FILE, json_encode($trades, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

/** 당일 실현 손익(원) 합계 */
function todayRealizedPnl(): int {
    $today = date('Y-m-d');
    $sum = 0;
    foreach (loadTrades() as $t) {
        if (str_starts_with($t['ts'] ?? '', $today)) $sum += (int)($t['pnlKrw'] ?? 0);
    }
    return $sum;
}

/** 누적 성과 통계 */
function computeStats(): array {
    $trades = loadTrades();
    $n = count($trades);
    if ($n === 0) return ['trades' => 0, 'wins' => 0, 'winRate' => 0, 'totalPnl' => 0, 'avgPct' => 0, 'todayPnl' => 0];
    $wins = 0; $totalPnl = 0; $sumPct = 0;
    foreach ($trades as $t) {
        $pnl = (int)($t['pnlKrw'] ?? 0);
        if ($pnl > 0) $wins++;
        $totalPnl += $pnl;
        $sumPct   += (float)($t['pnlRate'] ?? 0);
    }
    return [
        'trades'   => $n,
        'wins'     => $wins,
        'winRate'  => round($wins / $n * 100, 1),
        'totalPnl' => $totalPnl,
        'avgPct'   => round($sumPct / $n, 2),
        'todayPnl' => todayRealizedPnl(),
    ];
}

/** 코스피 등락률(%) — 시장 방어 필터용. 실패 시 null */
function fetchKospiChange(): ?float {
    try {
        $raw = kisHttp('GET', KIS_BASE_URL . '/uapi/domestic-stock/v1/quotations/inquire-index-price',
            ['FID_COND_MRKT_DIV_CODE' => 'U', 'FID_INPUT_ISCD' => '0001'], '',
            realHeaders(realToken(), 'FHPUP02100000'));
        $d = json_decode($raw, true) ?? [];
        $o = $d['output'] ?? [];
        if (!isset($o['bstp_nmix_prdy_ctrt'])) return null;
        $sign = $o['prdy_vrss_sign'] ?? '3';
        $neg  = ($sign === '4' || $sign === '5') ? -1 : 1;
        return round((float)$o['bstp_nmix_prdy_ctrt'] * $neg, 2);
    } catch (Throwable $e) {
        return null;
    }
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
