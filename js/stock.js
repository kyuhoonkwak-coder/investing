/* ===== 종목 상세 페이지 ===== */
const API = '/investing/api/kis.php';

/* 유틸 */
function fmtPrice(n) { return Number(n).toLocaleString('ko-KR') + '원'; }
function fmtVolume(n) {
  n = Number(n);
  if (n >= 1e8) return (n / 1e8).toFixed(1) + '억주';
  if (n >= 1e4) return (n / 1e4).toFixed(0) + '만주';
  return n.toLocaleString('ko-KR') + '주';
}
function changeClass(v) { return v > 0 ? 'up' : v < 0 ? 'down' : 'flat'; }
function changeSign(v)  { return v > 0 ? '+' : ''; }

const PALETTE = ['#1428A0','#EA1917','#002C5F','#A50034','#0066CC','#05141F','#03C75A','#FFB900','#6699CC','#FFBC00','#E8380D','#2D9900','#0046FF','#CC0000','#008000'];
const _colorMap = {};
function colorFor(code) {
  if (!_colorMap[code]) _colorMap[code] = PALETTE[Object.keys(_colorMap).length % PALETTE.length];
  return _colorMap[code];
}
function initialFor(name) { return name.slice(0, 1); }

async function apiFetch(params) {
  const url = API + '?' + new URLSearchParams(params);
  const res  = await fetch(url);
  const json = await res.json();
  if (!json.ok) throw new Error(json.msg || '알 수 없는 오류');
  return json.data;
}

/* 인터랙티브 차트 */
function buildInteractiveChart(candles, cls) {
  if (!candles || !candles.length) return '';
  const W = 680, H = 180, PAD = 8;
  const closes = candles.map(c => c.close);
  const minV = Math.min(...closes), maxV = Math.max(...closes);
  const range = maxV - minV || 1;

  const pts = candles.map((c, i) => {
    const x = PAD + (i / (candles.length - 1)) * (W - PAD * 2);
    const y = H - PAD - ((c.close - minV) / range) * (H - PAD * 2);
    return `${x.toFixed(1)},${y.toFixed(1)}`;
  }).join(' ');

  const strokeColor = cls === 'up' ? 'var(--up)' : 'var(--down)';

  return `
    <div class="chart-interactive-wrap" style="--chart-w:${W}px">
      <div class="chart-svg-wrap">
        <svg viewBox="0 0 ${W} ${H}" class="chart-svg" style="width:100%;height:${H}px">
          <polyline class="featured-sparkline ${cls}" points="${pts}"/>
          <line class="chart-crosshair" x1="0" y1="0" x2="0" y2="${H}" style="display:none"/>
          <circle class="chart-dot ${cls}" r="4" cx="0" cy="0" stroke="${strokeColor}" style="display:none"/>
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

  const W = 680, H = 180, PAD = 8;
  const closes = candles.map(c => c.close);
  const minV = Math.min(...closes), maxV = Math.max(...closes);
  const range = maxV - minV || 1;

  svg.addEventListener('mousemove', e => {
    const rect = svg.getBoundingClientRect();
    const xRel = (e.clientX - rect.left) / rect.width;
    const idx  = Math.max(0, Math.min(candles.length - 1, Math.round(xRel * (candles.length - 1))));
    const c    = candles[idx];
    if (!c) return;

    const cx = PAD + (idx / (candles.length - 1)) * (W - PAD * 2);
    const cy = H - PAD - ((c.close - minV) / range) * (H - PAD * 2);
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

/* 렌더링 함수들 */
let _currentCode = null;

function renderHeader(price, name) {
  const cls  = changeClass(price.changePct);
  const sign = changeSign(price.changePct);
  const col  = colorFor(price.code);
  document.title = `${price.name || name} - 주식투자`;
  document.getElementById('stockHeader').innerHTML = `
    <div class="modal-header-inner" style="margin-bottom:16px">
      <div class="modal-name-block">
        <div class="modal-icon" style="background:${col}22;color:${col};width:52px;height:52px;font-size:20px">${initialFor(name)}</div>
        <div>
          <div class="modal-name" style="font-size:22px">${price.name || name}</div>
          <div class="modal-code" style="font-size:13px">${price.code}</div>
        </div>
      </div>
      <div class="modal-price-block">
        <div class="modal-current-price ${cls}" style="font-size:28px">${fmtPrice(price.price)}</div>
        <div class="modal-change ${cls}">${sign}${price.change.toLocaleString()}원 (${sign}${Number(price.changePct).toFixed(2)}%)</div>
      </div>
    </div>`;
}

function renderStats(price) {
  const items = [
    { label: '1일 범위', value: `${fmtPrice(price.low)} ~ ${fmtPrice(price.high)}` },
    { label: '52주 범위', value: `${fmtPrice(price.low52w)} ~ ${fmtPrice(price.high52w)}` },
    { label: '거래량',    value: fmtVolume(price.volume) },
    { label: 'PER',      value: price.per ? `${price.per}배` : '-' },
    { label: 'PBR',      value: price.pbr ? `${price.pbr}배` : '-' },
    { label: 'EPS',      value: price.eps ? `${Number(price.eps).toLocaleString()}원` : '-' },
  ];
  document.getElementById('stockStatsBar').innerHTML = items.map(it =>
    `<div class="modal-stat"><div class="modal-stat-label">${it.label}</div><div class="modal-stat-value">${it.value}</div></div>`
  ).join('');
}

function renderChart(candles, price, code, period) {
  const chartEl = document.getElementById('stockChart');
  if (!candles || !candles.length) {
    chartEl.innerHTML = '<div style="padding:20px;color:var(--text2)">차트 데이터 없음</div>';
    return;
  }
  const cls = changeClass(price.changePct);
  chartEl.innerHTML = buildInteractiveChart(candles, cls);

  document.querySelectorAll('.mchart-tab').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.period === period);
    btn.onclick = () => {
      if (_currentCode !== code) return;
      document.querySelectorAll('.mchart-tab').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      chartEl.innerHTML = '<div style="height:180px;display:flex;align-items:center;justify-content:center;color:var(--text2)">로딩 중…</div>';
      apiFetch({ action: 'chart', code, period: btn.dataset.period }).then(c => {
        if (_currentCode !== code) return;
        renderChart(c, price, code, btn.dataset.period);
        renderPriceTable(c);
      });
    };
  });

  initChartInteraction(chartEl, candles);
}

function renderPriceTable(candles) {
  if (!candles || !candles.length) return;
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
  document.getElementById('stockPriceTable').innerHTML = `
    <table class="modal-price-tbl">
      <thead><tr><th>날짜</th><th>종가</th><th>등락률</th><th>거래량</th></tr></thead>
      <tbody>${html}</tbody>
    </table>`;
}

function renderInvestor(data) {
  const el = document.getElementById('stockInvestor');
  if (!data || !data.length) { el.innerHTML = '<div style="color:var(--text2);font-size:13px;padding:8px 0">데이터 없음</div>'; return; }
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

/* AI 분석 */
async function loadAiAnalysis(code, name) {
  const card = document.getElementById('aiAnalysisCard');
  if (!card) return;

  card.innerHTML = `
    <div class="ai-loading">
      <div class="ai-spinner"></div>
      <div><strong>${name}</strong> AI 분석 중…</div>
    </div>`;

  try {
    const res  = await fetch(`/investing/api/ai_analysis.php?code=${code}`);
    const json = await res.json();
    if (!json.ok) throw new Error(json.msg);

    const { analysis, changePct } = json.data;
    const cls  = changeClass(changePct);
    const sign = changePct > 0 ? '+' : '';
    const now  = new Date().toLocaleTimeString('ko-KR', { hour: '2-digit', minute: '2-digit' });

    const sections = [
      { key: 'movement',  label: '오늘 주가 흐름',   cls: 'move'    },
      { key: 'technical', label: '기술적 분석',       cls: 'tech'    },
      { key: 'valuation', label: '밸류에이션',        cls: 'value'   },
      { key: 'earnings',  label: '실적 · 재무 분석', cls: 'earn'    },
      { key: 'opinion',   label: '투자 의견',         cls: 'opinion' },
      { key: 'risk',      label: '리스크 요인',       cls: 'risk'    },
    ];
    const sectionsHtml = sections
      .filter(s => analysis[s.key])
      .map(s => `
        <div class="ai-section ${s.cls}">
          <div class="ai-section-label"><span class="dot dot-${s.cls}"></span>${s.label}</div>
          <div class="ai-section-text">${analysis[s.key]}</div>
        </div>`).join('');

    card.innerHTML = `
      <div class="ai-header">
        <div class="ai-title"><span class="ai-badge">AI</span> 종목 분석</div>
        <div class="ai-stock-info ${cls}">${name} ${sign}${Number(changePct).toFixed(2)}%</div>
      </div>
      ${sectionsHtml}
      <div class="ai-refresh" onclick="loadAiAnalysis('${code}','${name}')">
        ↻ 새로고침 · ${now} 기준
      </div>`;

  } catch (e) {
    card.innerHTML = `
      <div class="ai-header">
        <div class="ai-title"><span class="ai-badge">AI</span> 종목 분석</div>
      </div>
      <div style="color:var(--up);font-size:13px;padding:8px 0">오류: ${e.message}</div>
      <div class="ai-refresh" onclick="loadAiAnalysis('${code}','${name}')">↻ 다시 시도</div>`;
  }
}

/* 페이지 초기화 */
async function init() {
  const params = new URLSearchParams(location.search);
  const code = params.get('code');
  const name = decodeURIComponent(params.get('name') || '');

  if (!code) {
    document.getElementById('stockHeader').innerHTML = '<div style="padding:40px;color:var(--up)">종목 코드가 없습니다.</div>';
    return;
  }

  _currentCode = code;

  // 로딩 상태
  document.getElementById('stockHeader').innerHTML = `
    <div class="modal-header-inner" style="margin-bottom:16px">
      <div class="modal-name-block">
        <div class="modal-icon" style="background:${colorFor(code)}22;color:${colorFor(code)}">${initialFor(name || code)}</div>
        <div>
          <div class="modal-name" style="font-size:22px">${name || code}</div>
          <div class="modal-code">${code}</div>
        </div>
      </div>
      <div class="modal-price-block"><div style="color:var(--text2)">시세 불러오는 중…</div></div>
    </div>`;

  document.getElementById('stockChart').innerHTML = '<div style="height:180px;display:flex;align-items:center;justify-content:center;color:var(--text2)">차트 로딩 중…</div>';

  try {
    const [price, chart] = await Promise.all([
      apiFetch({ action: 'price', code }),
      apiFetch({ action: 'chart', code, period: 'D' }),
    ]);

    const displayName = price.name || name || code;
    renderHeader(price, displayName);
    renderStats(price);
    renderChart(chart, price, code, 'D');
    renderPriceTable(chart);

    // AI 분석 (비동기, 독립적으로)
    loadAiAnalysis(code, displayName);

  } catch (e) {
    document.getElementById('stockHeader').innerHTML = `<div style="padding:40px;color:var(--up)">오류: ${e.message}</div>`;
  }

  // 투자자 동향 (독립적으로)
  try {
    const inv = await apiFetch({ action: 'investor_stock', code });
    renderInvestor(inv);
  } catch (e) {
    document.getElementById('stockInvestor').innerHTML = '<div style="color:var(--text2);font-size:13px">투자자 데이터 없음</div>';
  }
}

document.addEventListener('DOMContentLoaded', init);
