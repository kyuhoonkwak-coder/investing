// 목업 데이터 — 나중에 KIS API로 교체
const MOCK_STOCKS = [
  { rank: 1, name: '삼성전자', code: '005930', price: 58400, change: -800, changePct: -1.35, volume: 18542310, buy: 42, sell: 58, color: '#1428A0', initial: '삼' },
  { rank: 2, name: 'SK하이닉스', code: '000660', price: 196500, change: -4500, changePct: -2.24, volume: 5218740, buy: 38, sell: 62, color: '#EA1917', initial: 'SK' },
  { rank: 3, name: '현대차', code: '005380', price: 202000, change: 3000, changePct: 1.51, volume: 1845220, buy: 67, sell: 33, color: '#002C5F', initial: '현' },
  { rank: 4, name: 'LG에너지솔루션', code: '373220', price: 289500, change: -5500, changePct: -1.86, volume: 892140, buy: 31, sell: 69, color: '#A50034', initial: 'LG' },
  { rank: 5, name: '셀트리온', code: '068270', price: 148000, change: 2500, changePct: 1.72, volume: 784510, buy: 71, sell: 29, color: '#0066CC', initial: '셀' },
  { rank: 6, name: '기아', code: '000270', price: 93400, change: 1200, changePct: 1.30, volume: 2140880, buy: 62, sell: 38, color: '#05141F', initial: '기' },
  { rank: 7, name: 'NAVER', code: '035420', price: 164500, change: -2500, changePct: -1.50, volume: 654320, buy: 44, sell: 56, color: '#03C75A', initial: 'N' },
  { rank: 8, name: '카카오', code: '035720', price: 41250, change: -750, changePct: -1.79, volume: 2984760, buy: 36, sell: 64, color: '#FEE500', initial: 'K' },
  { rank: 9, name: '포스코홀딩스', code: '005490', price: 282000, change: -5000, changePct: -1.74, volume: 421580, buy: 40, sell: 60, color: '#6699CC', initial: '포' },
  { rank: 10, name: 'KB금융', code: '105560', price: 81800, change: 400, changePct: 0.49, volume: 1058430, buy: 55, sell: 45, color: '#FFBC00', initial: 'KB' },
  { rank: 11, name: '한화에어로스페이스', code: '012450', price: 468000, change: 12000, changePct: 2.63, volume: 382140, buy: 78, sell: 22, color: '#E8380D', initial: '한' },
  { rank: 12, name: 'LG화학', code: '051910', price: 204500, change: -3500, changePct: -1.68, volume: 298760, buy: 33, sell: 67, color: '#A50034', initial: 'LG' },
];

const MOCK_TRENDING = [
  { name: '방산/우주', stocks: '한화에어로스페이스, 한국항공우주 외', count: 12, change: '+3.24%', up: true },
  { name: 'AI·반도체', stocks: '삼성전자, SK하이닉스 외', count: 24, change: '-1.42%', up: false },
  { name: '2차전지', stocks: 'LG에너지솔루션, 에코프로 외', count: 18, change: '-2.10%', up: false },
  { name: '바이오·헬스', stocks: '삼성바이오로직스, 셀트리온 외', count: 15, change: '+0.87%', up: true },
  { name: '자동차', stocks: '현대차, 기아 외', count: 8, change: '+1.35%', up: true },
  { name: '금융·은행', stocks: 'KB금융, 신한지주 외', count: 10, change: '+0.22%', up: true },
];

const MOCK_INVESTOR = [
  { label: '개인', buy: 4820, sell: 5130, unit: '억' },
  { label: '외국인', buy: 3240, sell: 2980, unit: '억' },
  { label: '기관', buy: 1580, sell: 1520, unit: '억' },
];

const MOCK_SCHEDULE = [
  { date: '오늘', text: '미국 주간 신규실업수당 청구건수 발표', badge: 'today' },
  { date: '오늘', text: '평균임금 상승률 발표', badge: 'today' },
  { date: '내일', text: '미국 5월 CPI 발표', badge: 'soon' },
  { date: '06.10', text: '한국은행 기준금리 결정', badge: '' },
  { date: '06.12', text: 'FOMC 금리 결정', badge: '' },
];

const MOCK_FEATURED = {
  name: '한화에어로스페이스',
  code: '012450',
  price: 468000,
  change: 12000,
  changePct: 2.63,
  color: '#E8380D',
  initial: '한',
  summary: '방산 수출 계약 기대감에 외국인 매수세가 3일 연속 유입되고 있어요.',
  chartPoints: [360000, 372000, 358000, 381000, 395000, 408000, 420000, 435000, 450000, 468000],
};

const SEARCH_DATA = [...MOCK_STOCKS,
  { name: '삼성바이오로직스', code: '207940', price: 842000, change: 8000, changePct: 0.96, color: '#1428A0', initial: '삼' },
  { name: '에코프로', code: '086520', price: 68200, change: -1400, changePct: -2.01, color: '#2D9900', initial: '에' },
  { name: '신한지주', code: '055550', price: 52300, change: 300, changePct: 0.58, color: '#0046FF', initial: '신' },
];
