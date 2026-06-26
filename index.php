<?php require_once __DIR__ . '/api/config.php'; ?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="theme-color" content="#ffffff">
  <title>자동매매 봇</title>
  <style>
    :root {
      --bg:      #F2F4F6;
      --card:    #ffffff;
      --border:  #E5E8EB;
      --text:    #191F28;
      --text2:   #4E5968;
      --text3:   #8B95A1;
      --accent:  #3182F6;
      --up:      #F04452;   /* 상승/매수 = 빨강 */
      --down:    #3182F6;   /* 하락/매도 = 파랑 */
      --green:   #1BC47D;
      --radius:  16px;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, "Apple SD Gothic Neo", "Pretendard", "Malgun Gothic", sans-serif;
      background: var(--bg);
      color: var(--text);
      -webkit-font-smoothing: antialiased;
      padding-bottom: 40px;
    }
    .wrap { max-width: 760px; margin: 0 auto; padding: 0 16px; }

    /* 헤더 */
    .top {
      padding: 24px 0 18px;
      display: flex; align-items: center; justify-content: space-between;
    }
    .top h1 { font-size: 22px; font-weight: 800; letter-spacing: -0.5px; display: flex; align-items: center; gap: 8px; }
    .badge {
      font-size: 12px; font-weight: 700; padding: 5px 11px; border-radius: 999px;
    }
    .badge.open  { background: #E7F9F1; color: var(--green); }
    .badge.close { background: #F2F4F6; color: var(--text3); }

    /* 카드 공통 */
    .card {
      background: var(--card); border-radius: var(--radius);
      padding: 20px; margin-bottom: 14px;
      box-shadow: 0 1px 3px rgba(0,0,0,.04);
    }
    .card-title { font-size: 13px; font-weight: 700; color: var(--text3); margin-bottom: 14px; letter-spacing: -0.2px; }

    /* 전략 요약 */
    .rules { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .rule { background: var(--bg); border-radius: 12px; padding: 13px 14px; }
    .rule .k { font-size: 11px; color: var(--text3); margin-bottom: 5px; }
    .rule .v { font-size: 15px; font-weight: 700; letter-spacing: -0.3px; }
    .rule .v.buy  { color: var(--up); }
    .rule .v.sell { color: var(--down); }

    /* 품질 필터 칩 */
    .filters { display: flex; flex-wrap: wrap; gap: 7px; margin-top: 14px; }
    .filters:empty { display: none; }
    .chip {
      font-size: 12px; font-weight: 600; color: var(--text2);
      background: var(--bg); border-radius: 999px; padding: 6px 12px;
    }
    .chip b { color: var(--text); font-weight: 700; }

    /* 성과 */
    .stat-grid { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 10px; margin-bottom: 4px; }
    .stat { background: var(--bg); border-radius: 12px; padding: 12px 10px; text-align: center; }
    .stat .sk { font-size: 11px; color: var(--text3); margin-bottom: 5px; }
    .stat .sv { font-size: 16px; font-weight: 800; letter-spacing: -0.3px; }
    .sv.up { color: var(--up); }
    .sv.down { color: var(--down); }
    .trade-row {
      display: flex; align-items: center; gap: 10px;
      padding: 11px 0; border-bottom: 1px solid var(--border); font-size: 13px;
    }
    .trade-row:last-child { border-bottom: none; }
    .trade-row:first-child { border-top: 1px solid var(--border); margin-top: 10px; }
    .trade-reason { font-size: 11px; font-weight: 700; padding: 2px 7px; border-radius: 6px; flex-shrink: 0; }
    .tr-win  { background: #FDECEC; color: var(--up); }
    .tr-lose { background: #E8F0FE; color: var(--down); }
    .trade-name { flex: 1; font-weight: 600; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .trade-pnl { font-weight: 700; text-align: right; }

    @media (max-width: 480px) { .stat-grid { grid-template-columns: 1fr 1fr; } }

    /* 보유 종목 */
    .hold-row {
      display: flex; align-items: center; gap: 12px;
      padding: 13px 0; border-bottom: 1px solid var(--border);
    }
    .hold-row:last-child { border-bottom: none; }
    .hold-logo {
      width: 40px; height: 40px; border-radius: 12px; flex-shrink: 0;
      display: flex; align-items: center; justify-content: center;
      font-size: 15px; font-weight: 800; background: #EEF3FB; color: var(--accent);
    }
    .hold-info { flex: 1; min-width: 0; }
    .hold-name { font-size: 15px; font-weight: 700; letter-spacing: -0.3px; }
    .hold-sub { font-size: 12px; color: var(--text3); margin-top: 2px; }
    .hold-right { text-align: right; }
    .hold-price { font-size: 15px; font-weight: 700; }
    .hold-pnl { font-size: 13px; font-weight: 700; margin-top: 2px; }
    .pnl-up { color: var(--up); }
    .pnl-down { color: var(--down); }

    .empty { text-align: center; color: var(--text3); font-size: 14px; padding: 28px 0; }

    /* 로그 타임라인 */
    .log-row {
      display: flex; gap: 12px; padding: 12px 0; border-bottom: 1px solid var(--border);
    }
    .log-row:last-child { border-bottom: none; }
    .log-tag {
      flex-shrink: 0; width: 46px; height: 24px; border-radius: 7px;
      font-size: 11px; font-weight: 800; display: flex; align-items: center; justify-content: center;
    }
    .tag-buy  { background: #FDECEC; color: var(--up); }
    .tag-sell { background: #E8F0FE; color: var(--down); }
    .tag-hold { background: #F2F4F6; color: var(--text3); }
    .tag-skip { background: #FFF6E5; color: #C98A00; }
    .log-body { flex: 1; min-width: 0; }
    .log-main { font-size: 14px; font-weight: 600; letter-spacing: -0.2px; }
    .log-time { font-size: 11px; color: var(--text3); margin-top: 3px; }
    .log-res { font-size: 12px; color: var(--text2); margin-top: 3px; }
    .log-res.fail { color: var(--up); }

    /* 버튼 */
    .actions { display: flex; gap: 10px; margin-bottom: 14px; }
    .btn {
      flex: 1; padding: 14px; border: none; border-radius: 13px;
      font-size: 14px; font-weight: 700; cursor: pointer; transition: opacity .15s;
      font-family: inherit;
    }
    .btn:active { opacity: .7; }
    .btn-dry  { background: #E8F0FE; color: var(--accent); }
    .btn-run  { background: var(--accent); color: #fff; }
    .btn:disabled { opacity: .5; cursor: default; }

    /* 후보 카드 */
    .card-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
    .card-head .card-title { margin-bottom: 0; }
    .mini-btn {
      font-size: 12px; font-weight: 700; color: var(--accent);
      background: #E8F0FE; border: none; border-radius: 8px;
      padding: 6px 12px; cursor: pointer; font-family: inherit;
    }
    .mini-btn:active { opacity: .7; }
    .mini-btn:disabled { opacity: .5; }
    .cand-badge { font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 6px; margin-left: 6px; }
    .cand-buy  { background: #E7F9F1; color: var(--green); }
    .cand-no   { background: #F2F4F6; color: var(--text3); }
    .cand-wait { background: #FFF6E5; color: #C98A00; }

    .refresh-note { text-align: center; font-size: 11px; color: var(--text3); margin-top: 6px; }
    .spin { display: inline-block; animation: sp 1s linear infinite; }
    @keyframes sp { to { transform: rotate(360deg); } }

    @media (max-width: 480px) {
      .rules { grid-template-columns: 1fr; }
      .top h1 { font-size: 19px; }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="top">
      <h1>🤖 자동매매 봇</h1>
      <span id="marketBadge" class="badge close">―</span>
    </div>

    <!-- 전략 -->
    <div class="card">
      <div class="card-title">전략 (모의투자)</div>
      <div class="rules" id="rules">
        <div class="rule"><div class="k">매수 조건</div><div class="v buy">―</div></div>
        <div class="rule"><div class="k">매도 조건</div><div class="v sell">―</div></div>
        <div class="rule"><div class="k">종목당 예산</div><div class="v">―</div></div>
        <div class="rule"><div class="k">최대 보유</div><div class="v">―</div></div>
      </div>
      <div class="filters" id="filters"></div>
    </div>

    <!-- 매수 후보 -->
    <div class="card">
      <div class="card-head">
        <div class="card-title">매수 후보 <span id="candCount"></span></div>
        <button class="mini-btn" id="btnCand">후보 불러오기</button>
      </div>
      <div id="candidates"><div class="empty">‘후보 불러오기’를 누르면<br>실시간으로 종목을 계산합니다</div></div>
    </div>

    <!-- 수동 실행 -->
    <div class="actions">
      <button class="btn btn-dry" id="btnDry">시뮬레이션 실행</button>
      <button class="btn btn-run" id="btnRun">지금 매매 실행</button>
    </div>

    <!-- 보유 종목 -->
    <div class="card">
      <div class="card-title">보유 종목 <span id="holdCount"></span></div>
      <div id="holdings"><div class="empty">불러오는 중…</div></div>
    </div>

    <!-- 성과 -->
    <div class="card">
      <div class="card-title">누적 성과</div>
      <div class="stat-grid" id="statGrid">
        <div class="stat"><div class="sk">실현 손익</div><div class="sv" id="stPnl">―</div></div>
        <div class="stat"><div class="sk">승률</div><div class="sv" id="stWin">―</div></div>
        <div class="stat"><div class="sk">거래 수</div><div class="sv" id="stCnt">―</div></div>
        <div class="stat"><div class="sk">당일 손익</div><div class="sv" id="stToday">―</div></div>
      </div>
      <div id="trades"></div>
    </div>

    <!-- 거래 로그 -->
    <div class="card">
      <div class="card-title">최근 실행 기록</div>
      <div id="log"><div class="empty">불러오는 중…</div></div>
    </div>

    <div class="refresh-note" id="lastTs">―</div>
  </div>

  <script>
    const API = '/investing/api/auto_trade.php';
    const KEY = '<?= AUTO_TRADE_SECRET ?>';

    const won = n => Number(n || 0).toLocaleString('ko-KR');
    const pct = n => (n >= 0 ? '+' : '') + Number(n).toFixed(2) + '%';
    const initial = s => (s || '?').slice(0, 1);

    async function call(params) {
      const url = `${API}?key=${KEY}&` + new URLSearchParams(params);
      const res = await fetch(url);
      const json = await res.json();
      if (!json.ok) throw new Error(json.msg || '오류');
      return json.data;
    }

    function renderRules(c) {
      if (!c) return;
      const sellTxt = c.dynamic
        ? `변동성 기반 (익절 ${c.tpMin}~${c.tpMax}% / 손절 ${c.slMin}~${c.slMax}%)`
        : `+${c.tp}% 익절 / ${c.sl}% 손절`;
      document.getElementById('rules').innerHTML = `
        <div class="rule"><div class="k">매수 조건</div><div class="v buy">전일대비 +${c.buyMin}~+${c.buyMax}%</div></div>
        <div class="rule"><div class="k">매도 조건</div><div class="v sell">${sellTxt}</div></div>
        <div class="rule"><div class="k">종목당 예산</div><div class="v">${won(c.budget)}원</div></div>
        <div class="rule"><div class="k">최대 보유</div><div class="v">${c.maxPos}종목</div></div>`;

      const chips = ['거래량·거래대금 상위'];
      if (c.minPrice) chips.push(`주가 <b>${won(c.minPrice)}원</b> 이상`);
      if (c.minCap)   chips.push(`시총 <b>${Math.round(c.minCap / 1e8).toLocaleString()}억</b> 이상`);
      if (c.ma5)      chips.push('<b>5일선 위</b> 상승추세');
      chips.push('관리·경고종목 제외');
      if (c.trail)     chips.push(`<b>트레일링</b> +${c.trail}%↑ 고점반납 시`);
      if (c.haltPct)   chips.push(`코스피 <b>${c.haltPct}%</b> 급락 시 매수중단`);
      if (c.lossLimit) chips.push(`당일 <b>-${Math.round(c.lossLimit/1e4)}만</b> 손실 시 매수중단`);
      document.getElementById('filters').innerHTML =
        '<span style="font-size:11px;color:var(--text3);width:100%;margin-bottom:2px">품질 필터</span>' +
        chips.map(t => `<span class="chip">${t}</span>`).join('');
    }

    async function loadCandidates() {
      const el  = document.getElementById('candidates');
      const btn = document.getElementById('btnCand');
      const orig = btn.textContent;
      btn.disabled = true;
      btn.innerHTML = '<span class="spin">⏳</span>';
      el.innerHTML = '<div class="empty">실시간 후보 계산 중…<br><span style="font-size:12px">최대 10초 소요</span></div>';
      try {
        renderCandidates(await call({ action: 'candidates' }));
      } catch (e) {
        el.innerHTML = `<div class="empty">오류: ${e.message}</div>`;
      } finally {
        btn.disabled = false;
        btn.textContent = orig;
      }
    }

    function renderCandidates(d) {
      const el  = document.getElementById('candidates');
      const cnt = document.getElementById('candCount');
      const list = d.candidates || [];
      cnt.textContent = list.length ? `· 매수대상 ${d.buyCount}종목` : '';
      if (!list.length) { el.innerHTML = '<div class="empty">조건 충족 종목 없음</div>'; return; }
      el.innerHTML = list.map(s => {
        let badge;
        if (s.ma5pass === true)       badge = '<span class="cand-badge cand-buy">매수 대상</span>';
        else if (s.ma5pass === false) badge = '<span class="cand-badge cand-no">추세 미달</span>';
        else                          badge = '<span class="cand-badge cand-wait">미확인</span>';
        const up = (s.changePct || 0) >= 0;
        const cap = s.marketCap > 0 ? ` · 시총 ${Math.round(s.marketCap / 1e8).toLocaleString()}억` : '';
        const ma5 = s.ma5 > 0 ? ` · 5일선 ${won(s.ma5)}` : '';
        const exit = (s.ma5pass === true && s.tp != null)
          ? `<div class="hold-sub" style="margin-top:1px">변동성 ${s.atrPct}% → 목표 <span style="color:var(--up)">+${s.tp}%</span> · 손절 <span style="color:var(--down)">${s.sl}%</span></div>`
          : '';
        return `
        <div class="hold-row">
          <div class="hold-logo">${initial(s.name)}</div>
          <div class="hold-info">
            <div class="hold-name">${s.name}${badge}</div>
            <div class="hold-sub">${s.code} · 거래대금 ${Math.round(s.amount / 1e8).toLocaleString()}억${cap}${ma5}</div>
            ${exit}
          </div>
          <div class="hold-right">
            <div class="hold-price">${won(s.price)}원</div>
            <div class="hold-pnl ${up ? 'pnl-up' : 'pnl-down'}">${pct(s.changePct)}</div>
          </div>
        </div>`;
      }).join('');
    }

    function renderHoldings(list, err) {
      const el = document.getElementById('holdings');
      const cnt = document.getElementById('holdCount');
      if (err) { el.innerHTML = `<div class="empty">잔고 조회 오류<br><span style="font-size:12px">${err}</span></div>`; cnt.textContent=''; return; }
      if (!list || !list.length) { el.innerHTML = '<div class="empty">보유 종목 없음</div>'; cnt.textContent=''; return; }
      cnt.textContent = `${list.length}종목`;
      el.innerHTML = list.map(h => {
        const up = (h.pnlRate || 0) >= 0;
        const peakTxt = (h.peak != null && h.peak >= 3) ? ` · 고점 +${h.peak}%` : '';
        const exit = (h.tp != null && h.sl != null)
          ? `<div class="hold-sub" style="margin-top:1px">목표 <span style="color:var(--up)">+${h.tp}%</span> · 손절 <span style="color:var(--down)">${h.sl}%</span>${peakTxt}</div>`
          : '';
        return `
        <div class="hold-row">
          <div class="hold-logo">${initial(h.name)}</div>
          <div class="hold-info">
            <div class="hold-name">${h.name || h.code}</div>
            <div class="hold-sub">${h.qty}주 · 평균 ${won(Math.round(h.avgPrice))}원</div>
            ${exit}
          </div>
          <div class="hold-right">
            <div class="hold-price">${won(h.curPrice)}원</div>
            <div class="hold-pnl ${up ? 'pnl-up' : 'pnl-down'}">${pct(h.pnlRate)}</div>
          </div>
        </div>`;
      }).join('');
    }

    function renderLog(log) {
      const el = document.getElementById('log');
      if (!log || !log.length) { el.innerHTML = '<div class="empty">실행 기록 없음</div>'; return; }
      const rows = [];
      log.forEach(entry => {
        const ts = entry.ts || '';
        const dry = entry.dry ? ' (시뮬)' : '';
        if (entry.skipped) {
          rows.push(logRow('SKIP', entry.skipped, ts + dry, ''));
          return;
        }
        (entry.actions || []).forEach(a => {
          if (a.type === 'BUY') {
            const r = a.result || {};
            const res = r.dry ? '시뮬레이션' : (r.ok ? `주문 #${r.ordNo}` : `실패: ${r.msg}`);
            rows.push(logRow('매수', `${a.name} ${a.qty}주 · ${won(a.price)}원 (${pct(a.changePct)})`, ts + dry, res, r.ok === false));
          } else if (a.type === 'SELL') {
            const r = a.result || {};
            const res = r.dry ? '시뮬레이션' : (r.ok ? `주문 #${r.ordNo}` : `실패: ${r.msg}`);
            rows.push(logRow('매도', `${a.name} ${a.qty}주 · ${a.reason} (${pct(a.pnlRate)})`, ts + dry, res, r.ok === false));
          } else if (a.type === 'HOLD') {
            rows.push(logRow('HOLD', a.msg || '조건 충족 종목 없음', ts + dry, ''));
          }
        });
        (entry.errors || []).forEach(e => rows.push(logRow('SKIP', '오류: ' + e, ts + dry, '', true)));
      });
      el.innerHTML = rows.slice(0, 40).join('') || '<div class="empty">실행 기록 없음</div>';
    }

    function logRow(tag, main, time, res, fail) {
      const cls = tag === '매수' ? 'tag-buy' : tag === '매도' ? 'tag-sell' : tag === 'HOLD' ? 'tag-hold' : 'tag-skip';
      return `
        <div class="log-row">
          <div class="log-tag ${cls}">${tag}</div>
          <div class="log-body">
            <div class="log-main">${main}</div>
            ${res ? `<div class="log-res ${fail ? 'fail' : ''}">${res}</div>` : ''}
            <div class="log-time">${time}</div>
          </div>
        </div>`;
    }

    function renderStats(s, trades) {
      if (!s) return;
      const setPnl = (id, v, withSign) => {
        const el = document.getElementById(id);
        el.textContent = (v > 0 && withSign ? '+' : '') + won(v) + '원';
        el.className = 'sv ' + (v > 0 ? 'up' : v < 0 ? 'down' : '');
      };
      setPnl('stPnl', s.totalPnl, true);
      setPnl('stToday', s.todayPnl, true);
      document.getElementById('stWin').textContent = s.trades ? `${s.winRate}%` : '―';
      document.getElementById('stCnt').textContent = `${s.trades}회`;

      const el = document.getElementById('trades');
      if (!trades || !trades.length) { el.innerHTML = ''; return; }
      el.innerHTML = trades.map(t => {
        const win = (t.pnlKrw || 0) >= 0;
        return `
        <div class="trade-row">
          <span class="trade-reason ${win ? 'tr-win' : 'tr-lose'}">${t.reason}</span>
          <span class="trade-name">${t.name || t.code}</span>
          <span class="trade-pnl ${win ? 'pnl-up' : 'pnl-down'}">${pct(t.pnlRate)} · ${win ? '+' : ''}${won(t.pnlKrw)}원</span>
        </div>`;
      }).join('');
    }

    async function loadStatus() {
      try {
        const d = await call({ action: 'status' });
        renderRules(d.config);
        renderHoldings(d.holdings, d.holdingsError);
        renderStats(d.stats, d.trades);
        renderLog(d.log);
        const badge = document.getElementById('marketBadge');
        badge.textContent = d.marketOpen ? '장중' : '장 마감';
        badge.className = 'badge ' + (d.marketOpen ? 'open' : 'close');
        document.getElementById('lastTs').textContent = '업데이트 ' + d.ts;
      } catch (e) {
        document.getElementById('holdings').innerHTML = `<div class="empty">오류: ${e.message}</div>`;
      }
    }

    async function runTrade(dry) {
      const btn = dry ? document.getElementById('btnDry') : document.getElementById('btnRun');
      const orig = btn.textContent;
      if (!dry && !confirm('실제로 모의투자 계좌에 매매 주문을 넣습니다. 진행할까요?')) return;
      btn.disabled = true;
      btn.innerHTML = '<span class="spin">⏳</span> 실행 중…';
      try {
        const params = dry ? { action: 'run', dry: '1' } : { action: 'run' };
        await call(params);
        await loadStatus();
      } catch (e) {
        alert('실행 오류: ' + e.message);
      } finally {
        btn.disabled = false;
        btn.textContent = orig;
      }
    }

    document.getElementById('btnDry').addEventListener('click', () => runTrade(true));
    document.getElementById('btnRun').addEventListener('click', () => runTrade(false));
    document.getElementById('btnCand').addEventListener('click', loadCandidates);

    loadStatus();
    setInterval(loadStatus, 30000);   // 30초마다 현황 갱신
  </script>
</body>
</html>
