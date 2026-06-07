<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$code = preg_replace('/\D/', '', $_GET['code'] ?? '');
if (strlen($code) !== 6) { echo json_encode(['ok'=>false,'msg'=>'종목코드 오류']); exit; }

$cacheFile = __DIR__ . '/cache_ai_' . $code . '.json';
if (file_exists($cacheFile)) {
    $c = json_decode(file_get_contents($cacheFile), true);
    if ($c && time() < ($c['expires_at'] ?? 0)) {
        echo json_encode(['ok'=>true,'data'=>$c['payload'],'cached'=>true]); exit;
    }
}

// ===== KIS GET 헬퍼 =====
function kisGetSimple(string $path, array $params, string $trId): array {
    $cache = file_exists(TOKEN_CACHE_FILE) ? json_decode(file_get_contents(TOKEN_CACHE_FILE), true) : [];
    $token = $cache['access_token'] ?? '';
    if (!$token) throw new RuntimeException('토큰 없음');
    $url  = KIS_BASE_URL . $path . '?' . http_build_query($params);
    $opts = ['http'=>['method'=>'GET','header'=>implode("\r\n",[
        'Content-Type: application/json','Authorization: Bearer '.$token,
        'appkey: '.KIS_APP_KEY,'appsecret: '.KIS_APP_SECRET,
        'tr_id: '.$trId,'custtype: P',
    ]),'timeout'=>8,'ignore_errors'=>true],'ssl'=>['verify_peer'=>false,'verify_peer_name'=>false]];
    return json_decode(@file_get_contents($url, false, stream_context_create($opts)) ?: '{}', true) ?? [];
}

// ===== 기술적 지표 계산 =====
function calcMA(array $c, int $n): ?float {
    return count($c) >= $n ? round(array_sum(array_slice($c, -$n)) / $n, 0) : null;
}
function calcRSI(array $c, int $n = 14): ?float {
    if (count($c) < $n + 1) return null;
    $g = $l = 0;
    for ($i = count($c) - $n; $i < count($c); $i++) {
        $d = $c[$i] - $c[$i-1];
        if ($d > 0) $g += $d; else $l += abs($d);
    }
    if ($l == 0) return 100.0;
    return round(100 - 100 / (1 + ($g/$n) / ($l/$n)), 1);
}
function calcEMA(array $v, int $n): array {
    if (empty($v)) return [];
    $k = 2/($n+1); $e = [$v[0]];
    for ($i = 1; $i < count($v); $i++) $e[] = $v[$i]*$k + end($e)*(1-$k);
    return $e;
}
function calcMACD(array $c): array {
    if (count($c) < 35) return [];
    $e12 = calcEMA($c, 12); $e26 = calcEMA($c, 26);
    $ml  = array_map(fn($a,$b)=>$a-$b, $e12, $e26);
    $sg  = calcEMA($ml, 9); $last = count($ml)-1;
    return ['macd'=>round($ml[$last],0),'signal'=>round($sg[$last],0),'hist'=>round($ml[$last]-$sg[$last],0)];
}

// ===== 네이버 뉴스 =====
function fetchNaverNews(string $code): array {
    $opts = ['http'=>['method'=>'GET','header'=>implode("\r\n",[
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        'Referer: https://m.stock.naver.com/','Accept: application/json',
    ]),'timeout'=>5,'ignore_errors'=>true],'ssl'=>['verify_peer'=>false,'verify_peer_name'=>false]];
    $data = json_decode(@file_get_contents("https://m.stock.naver.com/api/news/stock/{$code}?pageSize=5&page=0", false, stream_context_create($opts)) ?: 'null', true);
    if (!is_array($data)) return [];
    $items = $data['items'] ?? (isset($data[0]) ? $data : []);
    if (!is_array($items) || empty($items)) return [];
    return array_map(fn($n)=>['title'=>trim($n['title']??''),'date'=>substr($n['wdate']??$n['publishDate']??'',0,10)], array_slice($items,0,5));
}

// ===== DART 공통 =====
function dartOpts(): array {
    return ['http'=>['timeout'=>6,'ignore_errors'=>true],'ssl'=>['verify_peer'=>false,'verify_peer_name'=>false]];
}
function fetchDartCorpCode(string $stockCode): string {
    if (!defined('DART_API_KEY')||!DART_API_KEY) return '';
    $res = @file_get_contents('https://opendart.fss.or.kr/api/company.json?'.http_build_query(['crtfc_key'=>DART_API_KEY,'stock_code'=>$stockCode]),false,stream_context_create(dartOpts()));
    return json_decode($res??'{}',true)['corp_code']??'';
}
function fetchDartDisclosures(string $corpCode): array {
    if (!$corpCode||!defined('DART_API_KEY')||!DART_API_KEY) return [];
    $res  = @file_get_contents('https://opendart.fss.or.kr/api/list.json?'.http_build_query(['crtfc_key'=>DART_API_KEY,'corp_code'=>$corpCode,'bgn_de'=>date('Ymd',strtotime('-90 days')),'end_de'=>date('Ymd'),'page_count'=>5,'sort'=>'date','sort_mth'=>'desc']),false,stream_context_create(dartOpts()));
    $list = json_decode($res??'{}',true)['list']??[];
    return array_map(fn($d)=>'['.($d['rcept_dt']??'').'] '.($d['report_nm']??''),array_slice($list,0,5));
}
function fetchDartEarnings(string $corpCode): array {
    if (!$corpCode||!defined('DART_API_KEY')||!DART_API_KEY) return [];
    $year = (int)date('Y');
    foreach ([[$year,'11014'],[$year,'11013'],[$year,'11012'],[$year,'11011'],[$year-1,'11011']] as [$y,$rc]) {
        $res  = @file_get_contents('https://opendart.fss.or.kr/api/fnlttSinglAcntAll.json?'.http_build_query(['crtfc_key'=>DART_API_KEY,'corp_code'=>$corpCode,'bsns_year'=>$y,'reprt_code'=>$rc,'fs_div'=>'CFS']),false,stream_context_create(dartOpts()));
        $list = json_decode($res??'{}',true)['list']??[];
        if (empty($list)) continue;
        $result = [];
        foreach ($list as $item) {
            $nm  = $item['account_nm']??'';
            $amt = (int)str_replace([',',' '],['',''],$item['thstrm_amount']??'0');
            if (in_array($nm,['매출액','영업이익','당기순이익'])) $result[$nm] = number_format(round($amt/100000000),0).'억원';
        }
        if (!empty($result)) {
            $lbl = ['11011'=>'연간','11012'=>'반기','11013'=>'1분기보고서','11014'=>'3분기보고서'];
            $result['_period'] = $y.'년 '.($lbl[$rc]??'');
            return $result;
        }
    }
    return [];
}

// ===== KIS 재무비율 =====
function fetchKisFinancialRatio(string $code): array {
    try {
        usleep(300000);
        $data = kisGetSimple('/uapi/domestic-stock/v1/finance/financial-ratio',['FID_COND_MRKT_DIV_CODE'=>'J','FID_INPUT_ISCD'=>$code,'FID_PERIOD_DIV_CODE'=>'A'],'FHKST66430100');
        $o = is_array($data['output']??null)&&isset($data['output'][0]) ? $data['output'][0] : ($data['output']??[]);
        if (empty($o)) return [];
        return array_filter(['roe'=>$o['roe_val']??null,'roa'=>$o['roa_val']??null,'debt'=>$o['lblt_rate']??null,'opMargin'=>$o['bsop_prfi_rate']??null,'netMargin'=>$o['net_prfi_rate']??null],fn($v)=>$v!==null&&$v!==''&&$v!=='-');
    } catch (Throwable) { return []; }
}

// ===== KIS 기관/외국인 수급 =====
function fetchInvestorTrend(string $code): array {
    try {
        usleep(300000);
        $data = kisGetSimple('/uapi/domestic-stock/v1/quotations/inquire-investor',['FID_COND_MRKT_DIV_CODE'=>'J','FID_INPUT_ISCD'=>$code],'FHKST01010900');
        $o = $data['output']??[];
        if (empty($o)) return [];
        return ['foreign'=>(int)($o['frgn_ntby_qty']??0),'institution'=>(int)($o['orgn_ntby_qty']??0),'individual'=>(int)($o['prsn_ntby_qty']??0)];
    } catch (Throwable) { return []; }
}

// ===== KIS 차트 + 기술적 지표 =====
function fetchChartAndIndicators(string $code): array {
    try {
        usleep(300000);
        $data    = kisGetSimple('/uapi/domestic-stock/v1/quotations/inquire-daily-itemchartprice',['FID_COND_MRKT_DIV_CODE'=>'J','FID_INPUT_ISCD'=>$code,'FID_INPUT_DATE_1'=>date('Ymd',strtotime('-6 months')),'FID_INPUT_DATE_2'=>date('Ymd'),'FID_PERIOD_DIV_CODE'=>'D','FID_ORG_ADJ_PRC'=>'0'],'FHKST03010100');
        $candles = array_reverse($data['output2']??[]);
        $closes  = array_values(array_filter(array_map(fn($c)=>(int)($c['stck_clpr']??0),$candles)));
        if (count($closes) < 5) return [];
        $macd = calcMACD($closes);
        $rsi  = calcRSI($closes, 14);
        return [
            'ma5'    => calcMA($closes,5),
            'ma20'   => calcMA($closes,20),
            'ma60'   => calcMA($closes,60),
            'rsi14'  => $rsi,
            'rsiDesc'=> $rsi !== null ? ($rsi>=70?'과매수':($rsi<=30?'과매도':'중립')) : null,
            'macd'   => $macd['macd']??null,
            'signal' => $macd['signal']??null,
            'hist'   => $macd['hist']??null,
            'macdDesc'=> isset($macd['hist']) ? ($macd['hist']>0?'상승 모멘텀':'하락 모멘텀') : null,
        ];
    } catch (Throwable) { return []; }
}

// ========== 메인 수집 ==========
try {
    $priceData = kisGetSimple('/uapi/domestic-stock/v1/quotations/inquire-price',['FID_COND_MRKT_DIV_CODE'=>'J','FID_INPUT_ISCD'=>$code],'FHKST01010100');
    $out  = $priceData['output']??[];
    $neg  = in_array($out['prdy_vrss_sign']??'3',['4','5']) ? -1 : 1;
    $name        = $out['hts_kor_isnm']??$code;
    $price       = (int)($out['stck_prpr']??0);
    $change      = (int)($out['prdy_vrss']??0)*$neg;
    $changePct   = (float)($out['prdy_ctrt']??0)*$neg;
    $open        = (int)($out['stck_oprc']??0);
    $high        = (int)($out['stck_hgpr']??0);
    $low         = (int)($out['stck_lwpr']??0);
    $high52w     = (int)($out['w52_hgpr']??0);
    $low52w      = (int)($out['w52_lwpr']??0);
    $volume      = (int)($out['acml_vol']??0);
    $tradeAmount = (int)($out['acml_tr_pbmn']??0);
    $per = $out['per']??'-'; $pbr = $out['pbr']??'-'; $eps = $out['eps']??'-';
    $range52 = $high52w-$low52w;
    $pos52   = $range52>0 ? round(($price-$low52w)/$range52*100,1) : 50;
} catch (Throwable $e) { echo json_encode(['ok'=>false,'msg'=>'시세 조회 실패: '.$e->getMessage()]); exit; }

$indicators  = fetchChartAndIndicators($code);
$investor    = fetchInvestorTrend($code);
$financials  = fetchKisFinancialRatio($code);
$news        = fetchNaverNews($code);
$corpCode    = fetchDartCorpCode($code);
$disclosures = fetchDartDisclosures($corpCode);
$earnings    = fetchDartEarnings($corpCode);

// ========== 프롬프트 조립 ==========
$amtFmt = $tradeAmount>0 ? number_format($tradeAmount/100000000,0).'억원' : '-';

$techLines = [];
if ($indicators['ma5']   !==null) $techLines[]="- 5일 이동평균: ".number_format($indicators['ma5'])."원";
if ($indicators['ma20']  !==null) $techLines[]="- 20일 이동평균: ".number_format($indicators['ma20'])."원";
if ($indicators['ma60']  !==null) $techLines[]="- 60일 이동평균: ".number_format($indicators['ma60'])."원";
if ($indicators['rsi14'] !==null) $techLines[]="- RSI(14): {$indicators['rsi14']} ({$indicators['rsiDesc']})";
if ($indicators['macd']  !==null) $techLines[]="- MACD: {$indicators['macd']} / Signal: {$indicators['signal']} / Histogram: {$indicators['hist']} ({$indicators['macdDesc']})";
$techBlock = $techLines ? "\n[기술적 지표]\n".implode("\n",$techLines) : '';

$finLines = [];
if (!empty($financials['roe']))       $finLines[]="- ROE: {$financials['roe']}%";
if (!empty($financials['roa']))       $finLines[]="- ROA: {$financials['roa']}%";
if (!empty($financials['debt']))      $finLines[]="- 부채비율: {$financials['debt']}%";
if (!empty($financials['opMargin']))  $finLines[]="- 영업이익률: {$financials['opMargin']}%";
if (!empty($financials['netMargin'])) $finLines[]="- 순이익률: {$financials['netMargin']}%";
$finBlock = $finLines ? "\n".implode("\n",$finLines) : '';

$earLines = [];
$period   = $earnings['_period']??'';
foreach (['매출액','영업이익','당기순이익'] as $k) { if (!empty($earnings[$k])) $earLines[]="- {$k}: {$earnings[$k]}"; }
$earBlock = $earLines ? "\n[최근 실적 ({$period})]\n".implode("\n",$earLines) : '';

$invLines = [];
if (!empty($investor)) {
    $invLines[]="- 외국인 순매수: ".number_format($investor['foreign'])."주";
    $invLines[]="- 기관 순매수:   ".number_format($investor['institution'])."주";
    $invLines[]="- 개인 순매수:   ".number_format($investor['individual'])."주";
}
$invBlock = $invLines ? "\n[당일 수급]\n".implode("\n",$invLines) : '';

$newsBlock = $news ? "\n[최근 뉴스]\n".implode("\n",array_map(fn($n)=>'- ['.($n['date']?:'날짜미상').'] '.$n['title'],$news)) : '';
$discBlock = $disclosures ? "\n[최근 공시]\n".implode("\n",array_map(fn($d)=>"- {$d}",$disclosures)) : '';

$priceFmt = number_format($price);

$prompt = <<<PROMPT
당신은 국내 주식 전문 애널리스트입니다. 아래 데이터를 종합 분석하여 기관투자자 수준의 전문 의견을 제공하세요.

[종목 기본 정보]
- 종목명: {$name} ({$code})
- 현재가: {$priceFmt}원 | 전일대비: {$change}원 ({$changePct}%)
- 시가: {$open}원 / 고가: {$high}원 / 저가: {$low}원
- 52주 최고: {$high52w}원 / 52주 최저: {$low52w}원 (현재 하위 {$pos52}% 위치)
- 거래량: {$volume}주 / 거래대금: {$amtFmt}

[밸류에이션]
- PER(주가수익비율): {$per}배 / PBR(주가순자산비율): {$pbr}배 / EPS(주당순이익): {$eps}원{$finBlock}{$techBlock}{$invBlock}{$earBlock}{$newsBlock}{$discBlock}

위 데이터를 바탕으로 아래 6가지 항목을 각각 3~4문장으로 전문적으로 분석하세요.
- 수치를 직접 인용하며 구체적으로 분석하세요.
- 제공되지 않은 데이터는 절대 언급하지 마세요.
- 순수 한국어만 사용하세요. 외국어 단어·문자는 절대 금지입니다.
- JSON 형식으로만 답변하세요.

{
  "movement": "오늘 주가 흐름 (시가 대비 고저 흐름, 거래량·수급 연계, 뉴스·공시 영향)",
  "technical": "기술적 분석 (이동평균선 배열 상태, RSI 수준, MACD 모멘텀 방향과 의미)",
  "valuation": "밸류에이션 평가 (PER·PBR 수준, ROE·부채비율, 52주 위치 기반 적정가 판단)",
  "earnings": "실적 및 재무 분석 (최근 실적 수치 인용, 수익성·안정성 종합 평가)",
  "opinion": "투자 의견 (단기·중기·장기 관점별 포지션, 목표 주가 범위 제시)",
  "risk": "리스크 요인 (기술적·재무적·공시·뉴스 기반 주요 위험 요소)"
}

JSON 외 다른 텍스트는 절대 포함하지 마세요.
PROMPT;

// ========== Groq API ==========
$requestBody = json_encode([
    'model'      => 'llama-3.3-70b-versatile',
    'max_tokens' => 2048,
    'messages'   => [
        ['role'=>'system','content'=>'당신은 한국어만 사용하는 한국 주식 전문 애널리스트입니다. 한국어, 숫자, 기본 문장부호만 허용됩니다. 영어·일본어·중국어·러시아어 등 모든 외국어는 절대 금지입니다.'],
        ['role'=>'user','content'=>$prompt],
    ],
]);
$aiOpts = ['http'=>['method'=>'POST','header'=>implode("\r\n",['Content-Type: application/json','Authorization: Bearer '.GROQ_API_KEY]),'content'=>$requestBody,'timeout'=>30,'ignore_errors'=>true],'ssl'=>['verify_peer'=>false,'verify_peer_name'=>false]];
$aiRes  = @file_get_contents('https://api.groq.com/openai/v1/chat/completions',false,stream_context_create($aiOpts));
$aiData = json_decode($aiRes??'{}',true);
if (empty($aiData['choices'][0]['message']['content'])) {
    echo json_encode(['ok'=>false,'msg'=>'AI 응답 오류: '.($aiData['error']['message']??$aiRes)]); exit;
}
$text = preg_replace('/```(?:json)?\s*([\s\S]*?)```/','$1',$aiData['choices'][0]['message']['content']);
$jsonStart = strpos($text,'{'); $jsonEnd = strrpos($text,'}');
$analysis = [];
if ($jsonStart!==false&&$jsonEnd!==false) $analysis = json_decode(substr($text,$jsonStart,$jsonEnd-$jsonStart+1),true)??[];
if (empty($analysis)) { echo json_encode(['ok'=>false,'msg'=>'AI 분석 파싱 실패']); exit; }

function sanitizeKorean(string $t): string {
    $t = preg_replace('/[^\x{AC00}-\x{D7A3}\x{1100}-\x{11FF}\x{3130}-\x{318F}0-9\s\.,!?%()·\-\/\:\n]/u','',$t);
    return trim(preg_replace('/\s+/',' ',$t));
}
function formatNumbers(string $t): string {
    // 정수 뒤에 붙은 .0 / .00 제거 (예: 255500.00 → 255500)
    $t = preg_replace('/\b(\d+)\.0+\b/', '$1', $t);
    // 4자리 이상 순수 정수에 천 단위 쉼표 (이미 쉼표 있는 숫자는 건드리지 않음)
    $t = preg_replace_callback('/(?<![,\d\.])(\d{4,})(?![,\d\.])/', fn($m) => number_format((int)$m[1]), $t);
    return $t;
}
foreach ($analysis as $k=>$v) {
    if (is_string($v)) $analysis[$k] = formatNumbers(sanitizeKorean($v));
}

$result = ['name'=>$name,'code'=>$code,'price'=>$price,'changePct'=>$changePct,'analysis'=>$analysis];
file_put_contents($cacheFile,json_encode(['expires_at'=>time()+600,'payload'=>$result]));
echo json_encode(['ok'=>true,'data'=>$result]);
