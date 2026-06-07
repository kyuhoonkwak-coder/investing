/* ===== 유틸 ===== */
const API = '/investing/api/kis.php';

function fmtPrice(n) { return Number(n).toLocaleString('ko-KR') + '원'; }
function fmtVolume(n) {
  n = Number(n);
  if (n >= 1e8) return (n / 1e8).toFixed(1) + '억주';
  if (n >= 1e4) return (n / 1e4).toFixed(0) + '만주';
  return n.toLocaleString('ko-KR') + '주';
}
function fmtAmount(n) {
  n = Number(n);
  if (n >= 1e12) return (n / 1e12).toFixed(1) + '조';
  if (n >= 1e8)  return (n / 1e8).toFixed(0) + '억';
  if (n >= 1e4)  return (n / 1e4).toFixed(0) + '만';
  return n.toLocaleString('ko-KR');
}
function changeClass(v) { return v > 0 ? 'up' : v < 0 ? 'down' : 'flat'; }
function changeSign(v)  { return v > 0 ? '+' : ''; }

const PALETTE = ['#1428A0','#EA1917','#002C5F','#A50034','#0066CC','#05141F','#03C75A','#FFB900','#6699CC','#FFBC00','#E8380D','#2D9900','#0046FF','#CC0000','#008000'];
const _colorMap = {};
function colorFor(code) {
  if (!_colorMap[code]) _colorMap[code] = PALETTE[Object.keys(_colorMap).length % PALETTE.length];
  return _colorMap[code];
}
function initialFor(name) { return (name || '?').slice(0, 1); }

async function apiFetch(params) {
  const url = API + '?' + new URLSearchParams(params);
  const res  = await fetch(url);
  const json = await res.json();
  if (!json.ok) throw new Error(json.msg || '알 수 없는 오류');
  return json.data;
}

/* ===== 시장 지수 스트립 ===== */
async function loadMarketStrip() {
  try {
    const indices = await apiFetch({ action: 'index' });
    const ids    = ['ms-kospi', 'ms-kosdaq', 'ms-kp200', 'ms-usd'];
    const chgIds = ['ms-kospi-chg', 'ms-kosdaq-chg', 'ms-kp200-chg', 'ms-usd-chg'];
    indices.forEach((idx, i) => {
      const cls  = changeClass(idx.change);
      const sign = changeSign(idx.change);
      const valEl = document.getElementById(ids[i]);
      const chgEl = document.getElementById(chgIds[i]);
      if (valEl) valEl.textContent = idx.value.toLocaleString('ko-KR', { maximumFractionDigits: 2 });
      if (chgEl) {
        chgEl.textContent = `${idx.change > 0 ? '▲' : '▼'} ${Math.abs(idx.change).toLocaleString('ko-KR', { maximumFractionDigits: 2 })} (${Math.abs(idx.changePct).toFixed(2)}%)`;
        chgEl.className = 'mstrip-change ' + cls;
      }
    });
  } catch (e) {
    console.warn('지수 로드 실패:', e.message);
  }
}

/* ===== 종목 목록 (좌측 패널) ===== */
let listFilterType = 'volume';
let allStocks = [];
let activeCode = null;

async function loadStockList() {
  const listEl = document.getElementById('stockList');
  try {
    allStocks = await apiFetch({ action: 'volume_rank', type: listFilterType, period: 'realtime' });
    if (!searchMode) renderStockList(allStocks);
  } catch (e) {
    if (listEl && !searchMode) listEl.innerHTML = `<div style="padding:16px;color:var(--up);font-size:12px">오류: ${e.message}</div>`;
  }
}

function renderStockList(stocks) {
  const listEl = document.getElementById('stockList');
  if (!listEl) return;
  const query = (document.getElementById('listSearch')?.value || '').trim().toLowerCase();
  const filtered = query
    ? stocks.filter(s => s.name.toLowerCase().includes(query) || s.code.includes(query))
    : stocks;

  if (!filtered.length) {
    listEl.innerHTML = '<div style="padding:20px;text-align:center;color:var(--text3);font-size:12px">검색 결과 없음</div>';
    return;
  }

  listEl.innerHTML = filtered.map(s => {
    const cls  = changeClass(s.changePct);
    const sign = changeSign(s.changePct);
    const col  = colorFor(s.code);
    return `
      <div class="slist-row${s.code === activeCode ? ' active' : ''}" data-code="${s.code}" data-name="${s.name}">
        <div class="slist-rank">${s.rank}</div>
        <div class="slist-logo" style="background:${col}20;color:${col}">${initialFor(s.name)}</div>
        <div class="slist-info">
          <div class="slist-name">${s.name}</div>
          <div class="slist-code">${s.code}</div>
        </div>
        <div class="slist-price-col">
          <div class="slist-price">${fmtPrice(s.price)}</div>
          <div class="slist-change ${cls}">${sign}${Number(s.changePct).toFixed(2)}%</div>
        </div>
      </div>`;
  }).join('');

  listEl.querySelectorAll('.slist-row').forEach(row => {
    row.addEventListener('click', () => loadDetail(row.dataset.code, row.dataset.name));
  });
}

/* ===== 종목 상세 (우측 패널) ===== */
let _currentCode = null;

function mobileBack() {
  document.querySelector('.stock-list-panel')?.classList.remove('mobile-hidden');
  document.getElementById('detailPanel')?.classList.remove('mobile-visible');
}

async function loadDetail(code, name) {
  _currentCode = code;
  activeCode = code;

  // 모바일: 목록 숨기고 상세 표시
  const isMobile = window.innerWidth <= 768;
  if (isMobile) {
    document.querySelector('.stock-list-panel')?.classList.add('mobile-hidden');
    document.getElementById('detailPanel')?.classList.add('mobile-visible');
  }

  // URL 업데이트
  const url = new URL(location.href);
  url.searchParams.set('code', code);
  url.searchParams.set('name', name);
  history.replaceState({}, '', url);

  // 목록 active 상태 갱신
  document.querySelectorAll('.slist-row').forEach(r =>
    r.classList.toggle('active', r.dataset.code === code));

  const col = colorFor(code);

  // 기본 레이아웃 렌더링
  document.getElementById('detailPanel').innerHTML = `
  <div class="detail-inner">

    <button class="mobile-back-btn" onclick="mobileBack()">‹ 목록</button>

    <!-- 헤더 카드: 종목명 + 가격 + 스탯 -->
    <div class="dcard dcard-hd">
      <div class="dchd-top">
        <div class="detail-name-block">
          <div class="detail-icon" id="detailIcon" style="background:${col}22;color:${col}">${initialFor(name)}</div>
          <div>
            <div class="detail-stock-name" id="detailName">${name}</div>
            <div class="detail-stock-meta">
              <span>${code}</span>
            </div>
          </div>
        </div>
        <div class="detail-price-block" id="detailPriceBlock">
          <div style="color:var(--text3);font-size:14px;font-weight:500">시세 로딩 중…</div>
        </div>
      </div>
      <div class="dchd-stats" id="detailStatsBar"></div>
    </div>

    <!-- 좌우 레이아웃 -->
    <div class="detail-lr">

      <!-- 왼쪽: 차트 + 하단 2열 -->
      <div class="detail-lr-main">

        <!-- 차트 카드 -->
        <div class="dcard dcard-chart">
          <div class="dcard-chart-tabs" id="detailChartTabs">
            <button class="mchart-tab active" data-period="D">일봉</button>
            <button class="mchart-tab" data-period="W">주봉</button>
            <button class="mchart-tab" data-period="M">월봉</button>
          </div>
          <div id="detailChart">
            <div style="height:200px;display:flex;align-items:center;justify-content:center;color:var(--text3)">차트 로딩 중…</div>
          </div>
        </div>

        <!-- 하단 2열: 일자별 시세 + 투자자 동향 -->
        <div class="detail-sub-grid">
          <div class="dcard">
            <div class="dcard-tl">일자별 시세</div>
            <div class="dcard-bd" id="detailPriceTable">
              <div style="padding:16px 20px;color:var(--text3);font-size:12px">로딩 중…</div>
            </div>
          </div>
          <div class="dcard">
            <div class="dcard-tl">투자자 동향 (당일)</div>
            <div id="detailInvestor">
              <div style="padding:16px 20px;color:var(--text3);font-size:12px">로딩 중…</div>
            </div>
          </div>
        </div>

      </div>

      <!-- 오른쪽: 주문 카드 -->
      <div class="detail-lr-side">

        <!-- 주문 카드 -->
        <div class="order-card">
          <div class="order-card-hd">일반주문</div>
          <div class="order-card-bd">

            <!-- 매수/매도 탭 -->
            <div class="order-bs-tabs">
              <button class="order-bs-tab buy active" id="orderBuyTab" onclick="toggleOrderSide('buy')">매수</button>
              <button class="order-bs-tab sell" id="orderSellTab" onclick="toggleOrderSide('sell')">매도</button>
            </div>

            <!-- 주문 유형 -->
            <div>
              <div class="order-label">주문 유형</div>
              <select class="order-select">
                <option>정규장 주문 예약</option>
                <option>시간외 단일가</option>
                <option>시간외 종가</option>
              </select>
            </div>

            <!-- 가격 -->
            <div>
              <div class="order-label">구매 가격</div>
              <div class="order-price-type">
                <button class="order-price-type-btn active" id="orderLimitBtn" onclick="togglePriceType('limit')">지정가</button>
                <button class="order-price-type-btn" id="orderMarketBtn" onclick="togglePriceType('market')">시장가</button>
              </div>
              <div class="order-input-wrap" id="orderPriceWrap">
                <input type="text" class="order-num-input" id="orderPriceInput" placeholder="0" oninput="calcOrderTotal()">
                <span class="order-unit">원</span>
                <button class="order-step-btn" onclick="stepOrderPrice(-1)">−</button>
                <button class="order-step-btn" onclick="stepOrderPrice(1)">+</button>
              </div>
            </div>

            <!-- 수량 -->
            <div>
              <div class="order-label">수량</div>
              <div class="order-input-wrap">
                <input type="text" class="order-num-input" id="orderQtyInput" placeholder="수량 입력" oninput="calcOrderTotal()">
                <span class="order-unit">주</span>
                <button class="order-step-btn" onclick="stepOrderQty(-1)">−</button>
                <button class="order-step-btn" onclick="stepOrderQty(1)">+</button>
              </div>
              <div class="order-pct-row">
                <button class="order-pct-btn" onclick="setOrderPct(10)">10%</button>
                <button class="order-pct-btn" onclick="setOrderPct(25)">25%</button>
                <button class="order-pct-btn" onclick="setOrderPct(50)">50%</button>
                <button class="order-pct-btn" onclick="setOrderPct(100)">최대</button>
              </div>
            </div>

            <div class="order-divider"></div>

            <!-- 총 주문 금액 -->
            <div>
              <div class="order-label">총 주문 금액</div>
              <div class="order-input-wrap">
                <input type="text" class="order-num-input order-total-input" id="orderTotalInput" placeholder="금액 입력" readonly>
                <span class="order-unit">원</span>
              </div>
              <div class="order-avail" id="orderAvailText">주문 가능 금액 조회 중…</div>
            </div>

            <!-- 결과 메시지 -->
            <div id="orderResultMsg" class="order-result-msg" style="display:none"></div>

            <!-- 주문 버튼 -->
            <button class="order-submit-btn" id="orderSubmitBtn" onclick="submitOrder()">
              매수 주문하기
            </button>

          </div>
        </div>

      </div>

    </div>

  </div>`;

  // 시세 + 차트 병렬 로드
  try {
    const [price, chart] = await Promise.all([
      apiFetch({ action: 'price', code }),
      apiFetch({ action: 'chart', code, period: 'D' }),
    ]);

    if (_currentCode !== code) return;

    const displayName = price.name || name;
    document.getElementById('detailName').textContent = displayName;
    renderDetailPriceBlock(price);
    renderDetailStats(price);
    renderDetailChart(chart, price, code, 'D');
    renderDetailPriceTable(chart);
    initOrderCard(price.price, code);

  } catch (e) {
    const pb = document.getElementById('detailPriceBlock');
    if (pb) pb.innerHTML = `<div style="color:var(--up);font-size:13px">오류: ${e.message}</div>`;
  }

  // 투자자 동향 독립 로드
  apiFetch({ action: 'investor_stock', code }).then(inv => {
    if (_currentCode !== code) return;
    renderDetailInvestor(inv);
  }).catch(() => {
    const el = document.getElementById('detailInvestor');
    if (el) el.innerHTML = '<div style="color:var(--text3);font-size:12px">데이터 없음</div>';
  });
}

function renderDetailPriceBlock(price) {
  const cls  = changeClass(price.changePct);
  const sign = changeSign(price.changePct);
  const el   = document.getElementById('detailPriceBlock');
  if (!el) return;
  el.innerHTML = `
    <div class="detail-current-price ${cls}">${fmtPrice(price.price)}</div>
    <div class="detail-change ${cls}">${sign}${price.change.toLocaleString()}원&nbsp;(${sign}${Number(price.changePct).toFixed(2)}%)</div>`;

  // 이름 업데이트 + 마켓 태그
  const nameEl = document.getElementById('detailName');
  if (nameEl && price.name) nameEl.textContent = price.name;
}

function renderDetailStats(price) {
  const el = document.getElementById('detailStatsBar');
  if (!el) return;
  const items = [
    { label: '1일 범위',  value: `${fmtPrice(price.low)} ~ ${fmtPrice(price.high)}` },
    { label: '52주 범위', value: `${fmtPrice(price.low52w)} ~ ${fmtPrice(price.high52w)}` },
    { label: '거래량',    value: fmtVolume(price.volume) },
    { label: 'PER',      value: price.per ? `${price.per}배` : '-' },
    { label: 'PBR',      value: price.pbr ? `${price.pbr}배` : '-' },
    { label: 'EPS',      value: price.eps ? `${Number(price.eps).toLocaleString()}원` : '-' },
  ];
  el.innerHTML = items.map(it =>
    `<div class="modal-stat"><div class="modal-stat-label">${it.label}</div><div class="modal-stat-value">${it.value}</div></div>`
  ).join('');
}

function renderDetailChart(candles, price, code, period) {
  const chartEl = document.getElementById('detailChart');
  if (!chartEl) return;
  if (!candles || !candles.length) {
    chartEl.innerHTML = '<div style="padding:20px;color:var(--text3)">차트 데이터 없음</div>';
    return;
  }
  const cls = changeClass(price.changePct);
  chartEl.innerHTML = buildInteractiveChart(candles, cls);

  document.querySelectorAll('#detailChartTabs .mchart-tab').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.period === period);
    btn.onclick = () => {
      if (_currentCode !== code) return;
      document.querySelectorAll('#detailChartTabs .mchart-tab').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      chartEl.innerHTML = '<div style="height:200px;display:flex;align-items:center;justify-content:center;color:var(--text3)">로딩 중…</div>';
      apiFetch({ action: 'chart', code, period: btn.dataset.period }).then(c => {
        if (_currentCode !== code) return;
        renderDetailChart(c, price, code, btn.dataset.period);
        renderDetailPriceTable(c);
      });
    };
  });

  initChartInteraction(chartEl, candles);
}

function renderDetailPriceTable(candles) {
  const el = document.getElementById('detailPriceTable');
  if (!el || !candles || !candles.length) return;
  const rows = [...candles].reverse().slice(0, 30);
  let prev = null;
  const html = rows.map(c => {
    const chg    = prev ? ((c.close - prev) / prev * 100).toFixed(2) : '0.00';
    const chgCls = parseFloat(chg) > 0 ? 'up' : parseFloat(chg) < 0 ? 'down' : '';
    const sign   = parseFloat(chg) > 0 ? '+' : '';
    const d      = String(c.date || '');
    const dateStr = d.length === 8 ? `${d.slice(0,4)}.${d.slice(4,6)}.${d.slice(6,8)}` : d;
    prev = c.close;
    return `<tr>
      <td>${dateStr}</td>
      <td>${c.close.toLocaleString()}원</td>
      <td class="${chgCls}">${sign}${chg}%</td>
      <td>${fmtVolume(c.volume)}</td>
    </tr>`;
  }).join('');
  el.innerHTML = `
    <table class="modal-price-tbl" style="width:100%">
      <thead><tr><th>날짜</th><th>종가</th><th>등락률</th><th>거래량</th></tr></thead>
      <tbody>${html}</tbody>
    </table>`;
}

function renderDetailInvestor(data) {
  const el = document.getElementById('detailInvestor');
  if (!el) return;
  if (!data || !data.length) {
    el.innerHTML = '<div style="color:var(--text3);font-size:12px;padding:8px 0">데이터 없음</div>';
    return;
  }
  const maxAbs = Math.max(...data.map(r => Math.abs(r.qty)), 1);
  el.innerHTML = `
    <div class="modal-investor-grid">
      ${data.map(r => {
        const cls  = r.qty >= 0 ? 'up' : 'down';
        const sign = r.qty >= 0 ? '+' : '';
        const pct  = (Math.abs(r.qty) / maxAbs * 100).toFixed(1);
        const col  = r.qty >= 0 ? 'var(--up)' : 'var(--down)';
        return `
          <div class="modal-inv-item">
            <div class="modal-inv-label">${r.label}</div>
            <div class="modal-inv-qty ${cls}">${sign}${r.qty.toLocaleString()}주</div>
            <div class="modal-inv-bar-wrap">
              <div class="modal-inv-bar" style="width:${pct}%;background:${col}"></div>
            </div>
          </div>`;
      }).join('')}
    </div>`;
}

/* ===== 인터랙티브 차트 ===== */
function buildInteractiveChart(candles, cls) {
  if (!candles || !candles.length) return '';
  const W = 700, H = 210, PAD_X = 0, PAD_T = 12, PAD_B = 12;

  const closes = candles.map(c => c.close);
  const minV = Math.min(...closes), maxV = Math.max(...closes);
  const range = maxV - minV || 1;

  const points = candles.map((c, i) => {
    const x = PAD_X + (i / (candles.length - 1)) * (W - PAD_X * 2);
    const y = PAD_T + (1 - (c.close - minV) / range) * (H - PAD_T - PAD_B);
    return [+x.toFixed(1), +y.toFixed(1)];
  });

  const pts      = points.map(p => `${p[0]},${p[1]}`).join(' ');
  const firstX   = points[0][0], lastX = points[points.length - 1][0];
  const fillPts  = `${pts} ${lastX},${H} ${firstX},${H}`;

  const isUp      = cls === 'up';
  const lineColor = isUp ? '#E83A3A' : '#1E88E5';
  const gradId    = 'cg_' + Math.random().toString(36).slice(2, 7);

  // 수평 그리드 라인 3개
  const gridLines = [0.25, 0.5, 0.75].map(t => {
    const y = (PAD_T + t * (H - PAD_T - PAD_B)).toFixed(1);
    return `<line x1="0" y1="${y}" x2="${W}" y2="${y}" stroke="#E8EFF8" stroke-width="1"/>`;
  }).join('');

  return `
    <div class="chart-interactive-wrap">
      <div class="chart-svg-wrap">
        <svg viewBox="0 0 ${W} ${H}" class="chart-svg" style="width:100%;height:${H}px;display:block" preserveAspectRatio="none">
          <defs>
            <linearGradient id="${gradId}" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%"   stop-color="${lineColor}" stop-opacity="0.18"/>
              <stop offset="100%" stop-color="${lineColor}" stop-opacity="0"/>
            </linearGradient>
          </defs>
          ${gridLines}
          <polygon points="${fillPts}" fill="url(#${gradId})"/>
          <polyline class="featured-sparkline ${cls}" points="${pts}" style="stroke-width:2"/>
          <line class="chart-crosshair" x1="0" y1="0" x2="0" y2="${H}" style="display:none"/>
          <circle class="chart-dot ${cls}" r="4" cx="0" cy="0" stroke="${lineColor}" fill="#fff" stroke-width="2" style="display:none"/>
        </svg>
        <div class="chart-tooltip"></div>
      </div>
      <div class="chart-labels"><span>기간 시작</span><span>오늘</span></div>
    </div>`;
}

function initChartInteraction(container, candles) {
  const svg       = container.querySelector('.chart-svg');
  const tooltip   = container.querySelector('.chart-tooltip');
  const crosshair = container.querySelector('.chart-crosshair');
  const dot       = container.querySelector('.chart-dot');
  if (!svg || !candles || !candles.length) return;

  const W = 700, H = 210, PAD_X = 0, PAD_T = 12, PAD_B = 12;
  const closes = candles.map(c => c.close);
  const minV = Math.min(...closes), maxV = Math.max(...closes);
  const range = maxV - minV || 1;

  svg.addEventListener('mousemove', e => {
    // SVG viewBox 좌표계로 정확히 변환 (preserveAspectRatio, 스크롤, 줌 모두 처리)
    let svgX;
    try {
      const pt = svg.createSVGPoint();
      pt.x = e.clientX;
      pt.y = e.clientY;
      svgX = pt.matrixTransform(svg.getScreenCTM().inverse()).x;
    } catch (_) {
      const rect = svg.getBoundingClientRect();
      svgX = (e.clientX - rect.left) / rect.width * W;
    }

    const xRel = Math.max(0, Math.min(1, svgX / W));
    const idx  = Math.max(0, Math.min(candles.length - 1, Math.round(xRel * (candles.length - 1))));
    const c    = candles[idx];
    if (!c) return;

    const cx = PAD_X + (idx / (candles.length - 1)) * (W - PAD_X * 2);
    const cy = PAD_T + (1 - (c.close - minV) / range) * (H - PAD_T - PAD_B);
    if (crosshair) { crosshair.setAttribute('x1', cx); crosshair.setAttribute('x2', cx); crosshair.style.display = ''; }
    if (dot)       { dot.setAttribute('cx', cx); dot.setAttribute('cy', cy); dot.style.display = ''; }

    const chgPct = c.open > 0 ? ((c.close - c.open) / c.open * 100).toFixed(2) : '0.00';
    const chgCls = c.close >= c.open ? 'up' : 'down';
    const sign   = c.close >= c.open ? '+' : '';
    const d = String(c.date || '');
    const dateStr = d.length === 8 ? `${d.slice(0,4)}.${d.slice(4,6)}.${d.slice(6,8)}` : d;

    if (tooltip) {
      tooltip.innerHTML = `
        <div class="tt-date">${dateStr}</div>
        <div class="tt-row"><span>시작</span><span>${fmtPrice(c.open)}</span></div>
        <div class="tt-row"><span>마지막</span><span>${fmtPrice(c.close)}</span></div>
        <div class="tt-row"><span>최고</span><span class="up">${fmtPrice(c.high)}</span></div>
        <div class="tt-row"><span>최저</span><span class="down">${fmtPrice(c.low)}</span></div>
        <div class="tt-row"><span>거래량</span><span>${Number(c.volume).toLocaleString()}</span></div>
        <div class="tt-row"><span>등락률</span><span class="${chgCls}">${sign}${chgPct}%</span></div>`;
      tooltip.style.display = 'block';
      const isRight = xRel > 0.5;
      tooltip.style.left  = isRight ? '0'    : 'auto';
      tooltip.style.right = isRight ? 'auto' : '0';
    }
  });

  svg.addEventListener('mouseleave', () => {
    if (tooltip)   tooltip.style.display = 'none';
    if (crosshair) crosshair.style.display = 'none';
    if (dot)       dot.style.display = 'none';
  });
}

/* ===== 목록 필터 & 검색 ===== */
let searchMode = false;

function initListFilters() {
  document.querySelectorAll('.list-filter-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
      if (listFilterType === btn.dataset.type) return;
      listFilterType = btn.dataset.type;
      document.querySelectorAll('.list-filter-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      document.getElementById('stockList').innerHTML =
        '<div style="padding:24px;text-align:center;color:var(--text3);font-size:12px">로딩 중…</div>';
      await loadStockList();
    });
  });
}

function initListSearch() {
  const input = document.getElementById('listSearch');
  if (!input) return;
  let timer;
  input.addEventListener('input', () => {
    clearTimeout(timer);
    const q = input.value.trim();
    if (!q) {
      searchMode = false;
      renderStockList(allStocks);
      return;
    }
    timer = setTimeout(() => searchStocks(q), 300);
  });
}

async function searchStocks(q) {
  const listEl = document.getElementById('stockList');
  if (!listEl) return;

  // 1글자면 로컬 목록만 필터링 (API 호출 절약)
  if (q.length < 2) {
    searchMode = false;
    renderStockList(allStocks);
    return;
  }

  searchMode = true;
  listEl.innerHTML = '<div style="padding:20px;text-align:center;color:var(--text3);font-size:12px">검색 중…</div>';

  try {
    const results = await apiFetch({ action: 'search', q });

    // 입력이 지워졌으면 무시
    const currentQ = document.getElementById('listSearch')?.value.trim();
    if (!currentQ) { searchMode = false; renderStockList(allStocks); return; }

    renderSearchResults(results);
  } catch (e) {
    if (listEl) listEl.innerHTML = `<div style="padding:16px;color:var(--up);font-size:12px">오류: ${e.message}</div>`;
  }
}

function renderSearchResults(results) {
  const listEl = document.getElementById('stockList');
  if (!listEl) return;

  if (!results.length) {
    listEl.innerHTML = '<div style="padding:20px;text-align:center;color:var(--text3);font-size:12px">검색 결과 없음</div>';
    return;
  }

  listEl.innerHTML = results.map(s => {
    const col     = colorFor(s.code);
    const typeTag = s.type === 'ETF'
      ? `<span style="font-size:9px;background:#EEF6FF;color:var(--accent);border-radius:3px;padding:1px 4px;margin-left:4px">ETF</span>`
      : '';
    const mktTag  = s.market
      ? `<span style="font-size:10px;color:var(--text3);margin-left:4px">${s.market}</span>`
      : '';
    return `
      <div class="slist-row${s.code === activeCode ? ' active' : ''}" data-code="${s.code}" data-name="${s.name}">
        <div class="slist-logo" style="background:${col}20;color:${col}">${initialFor(s.name)}</div>
        <div class="slist-info">
          <div class="slist-name">${s.name}${typeTag}</div>
          <div class="slist-code">${s.code}${mktTag}</div>
        </div>
      </div>`;
  }).join('');

  listEl.querySelectorAll('.slist-row').forEach(row => {
    row.addEventListener('click', () => {
      document.getElementById('listSearch').value = '';
      searchMode = false;
      renderStockList(allStocks);
      loadDetail(row.dataset.code, row.dataset.name);
    });
  });
}

/* ===== 주문 카드 ===== */
let _orderSide = 'buy';
let _orderPriceType = 'limit';
let _orderCode = '';
let _orderAvailable = 0;

function initOrderCard(price, code) {
  _orderCode = code;
  _orderSide = 'buy';
  _orderPriceType = 'limit';
  const input = document.getElementById('orderPriceInput');
  if (input) { input.value = Number(price).toLocaleString('ko-KR'); input.addEventListener('input', calcOrderTotal); }
  document.getElementById('orderBuyTab')?.classList.add('active');
  document.getElementById('orderSellTab')?.classList.remove('active');
  document.getElementById('orderLimitBtn')?.classList.add('active');
  document.getElementById('orderMarketBtn')?.classList.remove('active');
  const wrap = document.getElementById('orderPriceWrap');
  if (wrap) { wrap.style.opacity = '1'; wrap.style.pointerEvents = ''; }
  const btn = document.getElementById('orderSubmitBtn');
  if (btn) { btn.textContent = '매수 주문하기'; btn.classList.remove('sell-mode'); btn.onclick = () => submitOrder(); }
  updateOrderAvailable(0);
  loadOrderBalance(code, price);
}

async function loadOrderBalance(code, price) {
  try {
    const res  = await fetch(`/investing/api/order.php?action=balance&code=${code}&price=${price}`);
    const json = await res.json();
    if (json.ok) {
      if (json.data.perm_error) {
        const el = document.getElementById('orderAvailText');
        if (el) el.textContent = '주문 가능 금액 조회 불가 (KIS 포털 거래 권한 확인 필요)';
      } else {
        updateOrderAvailable(json.data.available);
      }
    }
  } catch (_) {}
}

function updateOrderAvailable(amount) {
  _orderAvailable = amount;
  const el = document.getElementById('orderAvailText');
  if (el) el.textContent = '주문 가능 금액 ' + Number(amount).toLocaleString('ko-KR') + '원';
}

function toggleOrderSide(side) {
  _orderSide = side;
  document.getElementById('orderBuyTab')?.classList.toggle('active', side === 'buy');
  document.getElementById('orderSellTab')?.classList.toggle('active', side === 'sell');
  const btn = document.getElementById('orderSubmitBtn');
  if (btn) {
    btn.textContent = side === 'buy' ? '매수 주문하기' : '매도 주문하기';
    btn.classList.toggle('sell-mode', side === 'sell');
  }
}

function togglePriceType(type) {
  _orderPriceType = type;
  document.getElementById('orderLimitBtn')?.classList.toggle('active', type === 'limit');
  document.getElementById('orderMarketBtn')?.classList.toggle('active', type === 'market');
  const wrap = document.getElementById('orderPriceWrap');
  if (wrap) { wrap.style.opacity = type === 'market' ? '0.4' : '1'; wrap.style.pointerEvents = type === 'market' ? 'none' : ''; }
}

function stepOrderPrice(dir) {
  const input = document.getElementById('orderPriceInput');
  if (!input) return;
  const cur  = Number((input.value || '0').replace(/,/g, '')) || 0;
  const step = cur >= 500000 ? 1000 : cur >= 50000 ? 500 : cur >= 5000 ? 100 : 10;
  input.value = Math.max(0, cur + dir * step).toLocaleString('ko-KR');
  calcOrderTotal();
}

function stepOrderQty(dir) {
  const input = document.getElementById('orderQtyInput');
  if (!input) return;
  input.value = Math.max(0, (Number(input.value) || 0) + dir);
  calcOrderTotal();
}

function setOrderPct(pct) {
  const price = Number((document.getElementById('orderPriceInput')?.value || '0').replace(/,/g, '')) || 0;
  if (!price || !_orderAvailable) return;
  const maxQty = Math.floor(_orderAvailable / price);
  const qty = Math.floor(maxQty * pct / 100);
  const input = document.getElementById('orderQtyInput');
  if (input) { input.value = qty; calcOrderTotal(); }
}

function calcOrderTotal() {
  const price = Number((document.getElementById('orderPriceInput')?.value || '0').replace(/,/g, '')) || 0;
  const qty   = Number(document.getElementById('orderQtyInput')?.value || '0') || 0;
  const total = price * qty;
  const el    = document.getElementById('orderTotalInput');
  if (el) el.value = total > 0 ? total.toLocaleString('ko-KR') : '';
}

async function submitOrder() {
  const priceRaw = Number((document.getElementById('orderPriceInput')?.value || '0').replace(/,/g, '')) || 0;
  const qty      = Number(document.getElementById('orderQtyInput')?.value || '0') || 0;

  if (qty <= 0) { showOrderMsg('수량을 입력하세요', 'error'); return; }
  if (_orderPriceType === 'limit' && priceRaw <= 0) { showOrderMsg('가격을 입력하세요', 'error'); return; }

  const btn = document.getElementById('orderSubmitBtn');
  if (btn) { btn.disabled = true; btn.textContent = '주문 처리 중…'; }

  try {
    const res  = await fetch('/investing/api/order.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'order',
        code:   _orderCode,
        side:   _orderSide,
        type:   _orderPriceType,
        price:  priceRaw,
        qty,
      }),
    });
    const json = await res.json();
    if (json.ok) {
      const d = json.data;
      showOrderMsg(`✓ ${_orderSide === 'buy' ? '매수' : '매도'} 주문 완료 (주문번호 ${d.ordNo})`, 'success');
      document.getElementById('orderQtyInput').value = '';
      document.getElementById('orderTotalInput').value = '';
      loadOrderBalance(_orderCode, priceRaw);
    } else {
      showOrderMsg(json.msg, 'error');
    }
  } catch (e) {
    showOrderMsg('통신 오류: ' + e.message, 'error');
  } finally {
    if (btn) {
      btn.disabled = false;
      btn.textContent = _orderSide === 'buy' ? '매수 주문하기' : '매도 주문하기';
    }
  }
}

function showOrderMsg(text, type) {
  const el = document.getElementById('orderResultMsg');
  if (!el) return;
  el.textContent = text;
  el.className = 'order-result-msg ' + type;
  el.style.display = 'block';
  clearTimeout(el._t);
  el._t = setTimeout(() => { el.style.display = 'none'; }, 5000);
}

/* ===== 자동 갱신 ===== */
function startAutoRefresh() {
  let turn = 0;
  setInterval(() => {
    if (turn % 4 === 0) loadMarketStrip();
    else loadStockList();
    turn++;
  }, 15000);
}

/* ===== 초기화 ===== */
async function init() {
  initListFilters();
  initListSearch();

  await Promise.all([loadMarketStrip(), loadStockList()]);

  const params   = new URLSearchParams(location.search);
  const urlCode  = params.get('code');
  const urlName  = params.get('name') || '';
  const isMobile = window.innerWidth <= 768;

  if (urlCode) {
    loadDetail(urlCode, urlName || urlCode);
  } else if (allStocks.length && !isMobile) {
    // 데스크톱: 첫 번째 종목 자동 선택
    loadDetail(allStocks[0].code, allStocks[0].name);
  }

  startAutoRefresh();
}

document.addEventListener('DOMContentLoaded', init);
