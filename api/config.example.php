<?php
// ===== 한국투자증권 KIS Open API 설정 =====
// 1. https://apiportal.koreainvestment.com 에서 실전투자 앱 생성
// 2. 아래 키 입력 후 파일명을 config.php 로 변경

define('KIS_APP_KEY',    'YOUR_KIS_APP_KEY');
define('KIS_APP_SECRET', 'YOUR_KIS_APP_SECRET');

// 실전투자: https://openapi.koreainvestment.com:9443
define('KIS_BASE_URL', 'https://openapi.koreainvestment.com:9443');

define('TOKEN_CACHE_FILE', __DIR__ . '/token_cache.json');

// ===== 거래 계좌 설정 =====
define('KIS_ACCOUNT_NO', '');   // 계좌번호 앞 8자리 (예: 50012345)
define('KIS_ACCOUNT_CD', '01'); // 보통 01

// ===== 모의투자 앱키 =====
// apiportal.koreainvestment.com → 모의투자 앱 별도 생성
define('KIS_VTS_APP_KEY',    'YOUR_VTS_APP_KEY');
define('KIS_VTS_APP_SECRET', 'YOUR_VTS_APP_SECRET');
define('KIS_VTS_BASE_URL',   'https://openapivts.koreainvestment.com:29443');
define('VTS_TOKEN_CACHE_FILE', __DIR__ . '/vts_token_cache.json');

// ===== Groq API =====
// https://console.groq.com 에서 발급
define('GROQ_API_KEY', 'YOUR_GROQ_API_KEY');

// ===== DART 전자공시 API =====
// https://opendart.fss.or.kr 에서 발급
define('DART_API_KEY', 'YOUR_DART_API_KEY');
