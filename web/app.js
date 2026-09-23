document.addEventListener('DOMContentLoaded', () => {
  const menuButtons = document.querySelectorAll('.weather-menu-item');

  menuButtons.forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      const targetId = btn.getAttribute('data-tab');

      menuButtons.forEach(b => b.classList.remove('active'));
      btn.classList.add('active');

      document.querySelectorAll('.tab-section').forEach(sec => {
        sec.classList.remove('active');
      });

      const activeSec = document.getElementById(targetId);
      if (activeSec) {
        activeSec.classList.add('active');
      }

      if (window.innerWidth < 992) {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebar-overlay');
        if (sidebar) sidebar.classList.remove('open');
        if (overlay) overlay.classList.remove('active');
      }

      // Forza il resize dei grafici Plotly quando si passa alla tab Open-Meteo
      if (targetId === 'tab1') {
        setTimeout(() => {
          ['chart-temp', 'chart-t850', 'chart-t500', 'chart-wind', 'chart-precip'].forEach(id => {
            const el = document.getElementById(id);
            if (el && el.data) {
              Plotly.Plots.resize(el);
            }
          });
        }, 100);
      }
    });
  });

  const setToggle = document.getElementById('settings_toggle');
  const setPanel = document.getElementById('settings_panel');
  if (setToggle && setPanel) {
    setToggle.addEventListener('click', () => {
      setPanel.hidden = !setPanel.hidden;
    });
  }

  loadOpenMeteoData();

  // Ridisegna i grafici (senza rifare le chiamate API) quando cambia la
  // larghezza della finestra, così la legenda passa da verticale (desktop,
  // a lato) a orizzontale sotto al grafico (mobile) invece di "mangiarsi"
  // metà dello spazio orizzontale disponibile.
  let resizeTimer;
  window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => {
      if (cachedHourly && cachedTimes) {
        buildAllCharts(cachedHourly, cachedTimes);
      }
    }, 250);
  });
});

function toggleSidebar() {
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('sidebar-overlay');
  if (sidebar) sidebar.classList.toggle('open');
  if (overlay) overlay.classList.toggle('active');
}

/* ═══════════════════════════════════════════════════════
   Plotly — tema e layout responsive
   ═══════════════════════════════════════════════════════ */

function computeIsDark() {
  const theme = (typeof WEATHER_CONFIG !== 'undefined' && WEATHER_CONFIG.theme) || 'dark';
  if (theme === 'light') return false;
  if (theme === 'dark') return true;
  // 'system': stessa logica del CSS (scuro di default, chiaro solo se il
  // sistema lo richiede esplicitamente — vedi @media prefers-color-scheme in style.css)
  return !(window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches);
}

const IS_DARK    = computeIsDark();
const plotTpl    = IS_DARK ? 'plotly_dark' : 'plotly_white';
const textColor  = IS_DARK ? '#c9d1d9' : '#1b1f24';
const gridColor  = IS_DARK ? '#30363d' : '#d0d7de';
const legendBg   = IS_DARK ? '#1c2333' : '#eef1f5';
const borderCol  = IS_DARK ? '#30363d' : '#d0d7de';

const isMobile = () => window.innerWidth <= 640;
const chartHeight = (desktop = 360) => isMobile() ? Math.round(desktop * 0.85) : desktop;

const plotlyConfig = { responsive: true, displayModeBar: false };

// Layout comune a tutti i grafici Open-Meteo.
// Su mobile la legenda va orizzontale SOTTO al grafico (non di lato):
// è questo che evitava, nella vecchia versione desktop-oriented, che la
// legenda occupasse metà della larghezza disponibile su schermi stretti.
function baseLayout(title, yTitle, extra = {}) {
  const mobile = isMobile();

  const legend = mobile
    ? { orientation: 'h', x: 0.5, xanchor: 'center', y: -0.34, yanchor: 'top', font: { size: 10, color: textColor }, bgcolor: 'transparent' }
    : { orientation: 'v', x: 1.02, xanchor: 'left', y: 1, font: { size: 12, color: textColor }, bgcolor: legendBg, bordercolor: borderCol };

  return {
    template: plotTpl,
    paper_bgcolor: 'transparent',
    plot_bgcolor: 'transparent',
    hovermode: 'x unified',
    autosize: true,
    height: chartHeight(),
    font: { family: "'Space Grotesk', sans-serif", size: mobile ? 11 : 13, color: textColor },
    title: { text: title, font: { size: mobile ? 13 : 15 } },
    legend,
    margin: mobile
      ? { t: 34, r: 14, b: 74, l: 42 }
      : { t: 44, r: extra.yaxis2 ? 64 : 26, b: 44, l: 56 },
    xaxis: { type: 'date', tickformat: '%d %b %H:%M', gridcolor: gridColor },
    yaxis: { title: yTitle, gridcolor: gridColor },
    ...extra,
  };
}

// Statistica per-timestamp su una matrice [serie][tempo]
function colAt(mat, i) { return mat.map(a => a[i]); }
function avgAt(mat, i) { const c = colAt(mat, i); return c.reduce((s, v) => s + v, 0) / c.length; }
function quantile(arr, q) {
  const sorted = [...arr].sort((a, b) => a - b);
  const pos = (sorted.length - 1) * q;
  const base = Math.floor(pos);
  const rest = pos - base;
  return sorted[base + 1] !== undefined
    ? sorted[base] + rest * (sorted[base + 1] - sorted[base])
    : sorted[base];
}

// Una traccia sottile e senza didascalia per ogni "corsa" (membro ensemble),
// al posto della banda ombreggiata P10-P90: mostra la dispersione reale
// membro per membro invece di un intervallo aggregato.
function spaghettiTraces(mat, times, color) {
  return mat.map(series => ({
    x: times, y: series, mode: 'lines',
    line: { width: 0.8, color }, opacity: 0.35,
    showlegend: false, hoverinfo: 'skip',
  }));
}

function showWeatherError(message) {
  let box = document.getElementById('weather-error-box');
  if (!box) {
    box = document.createElement('div');
    box.id = 'weather-error-box';
    box.style.cssText = [
      'background:#3d1f1f', 'color:#ff8080', 'border:1px solid #ff4d4d',
      'border-radius:6px', 'padding:10px 14px', 'margin:0 0 12px', 'font-size:14px'
    ].join(';');
    const tab1 = document.getElementById('tab1');
    (tab1 || document.body).prepend(box);
  }
  box.textContent = message;
  box.hidden = false;
}

function clearWeatherError() {
  const box = document.getElementById('weather-error-box');
  if (box) box.hidden = true;
}

/* ═══════════════════════════════════════════════════════
   Costruzione dei singoli grafici (dati già in memoria)
   ═══════════════════════════════════════════════════════ */

// Temperatura a 2m: banda P10-P90 + media ensemble (stile "vecchio" grafico,
// solo 2 voci in legenda indipendentemente dal numero di membri)
function renderTempChart(hourly, times) {
  const el = document.getElementById('chart-temp');
  if (!el) return false;
  const keys = Object.keys(hourly).filter(k => k.startsWith('temperature_2m'));
  if (!keys.length) return false;

  const mat = keys.map(k => hourly[k]);
  const mean = times.map((_, i) => avgAt(mat, i));

  const traces = [
    ...spaghettiTraces(mat, times, '#ff8080'),
    { x: times, y: mean, mode: 'lines', line: { color: '#ff4b4b', width: 2.5 }, name: 'Media ensemble' },
  ];

  Plotly.newPlot(el, traces, baseLayout(`Temperatura a 2m — ensemble (${keys.length} membri)`, '°C'), plotlyConfig);
  return true;
}

// Vento a 10m: P90 raffiche + media ensemble
function renderWindChart(hourly, times) {
  const el = document.getElementById('chart-wind');
  if (!el) return false;
  const keys = Object.keys(hourly).filter(k => k.startsWith('wind_speed_10m'));
  if (!keys.length) return false;

  const mat = keys.map(k => hourly[k]);
  const mean = times.map((_, i) => avgAt(mat, i));
  const p90 = times.map((_, i) => quantile(colAt(mat, i), 0.90));

  const traces = [
    { x: times, y: p90, mode: 'lines', line: { color: '#ffa94d', width: 1.5, dash: 'dot' }, name: 'P90 raffiche' },
    { x: times, y: mean, mode: 'lines', line: { color: '#50c878', width: 2.5 }, name: 'Media ensemble' },
  ];

  Plotly.newPlot(el, traces, baseLayout('Vento a 10m — ensemble', 'km/h', { yaxis: { title: 'km/h', gridcolor: gridColor, rangemode: 'tozero' } }), plotlyConfig);
  return true;
}

// Precipitazioni: media (barre) + probabilità di pioggia (linea, asse destro) —
// come nel grafico vecchio, che l'utente ha indicato come riferimento.
function renderPrecipChart(hourly, times) {
  const el = document.getElementById('chart-precip');
  if (!el) return false;
  const keys = Object.keys(hourly).filter(k => k.startsWith('precipitation'));
  if (!keys.length) return false;

  const mat = keys.map(k => hourly[k]);
  const avgPrecip = times.map((_, i) => avgAt(mat, i));
  const prob = times.map((_, i) => (colAt(mat, i).filter(v => v > 0.1).length / mat.length) * 100);

  const traces = [
    { x: times, y: avgPrecip, type: 'bar', name: 'Precip. media (mm)', marker: { color: 'rgba(77,166,255,0.6)' }, yaxis: 'y' },
    { x: times, y: prob, mode: 'lines', name: 'Probabilità pioggia (%)', line: { color: '#ff79c6', width: 2 }, yaxis: 'y2' },
  ];

  const layout = baseLayout('Precipitazioni — media e probabilità', 'mm', {
    yaxis2: { title: 'Probabilità %', range: [0, 100], overlaying: 'y', side: 'right', gridcolor: 'transparent' },
    barmode: 'overlay',
  });

  Plotly.newPlot(el, traces, layout, plotlyConfig);
  return true;
}

// Temperatura in quota (850hPa e 500hPa): ENTRAMBI i grafici mostrano sempre
// la temperatura (linee spaghetti sottili per membro + media), stesso stile
// del grafico a 2m. Il geopotenziale, se disponibile, e l'isoterma 0°C
// (solo a 850hPa) partono nascosti ("legendonly") e si attivano dalla legenda.
function renderLevelChart(level, colorMean, colorSpaghetti, hourly, times) {
  const elId = `chart-t${level}`;
  const el = document.getElementById(elId);
  if (!el) return false;

  const tKeys = Object.keys(hourly).filter(k => k.startsWith(`temperature_${level}hPa`));
  if (!tKeys.length) {
    el.innerHTML = '<p style="padding:1rem;">Temperatura a ' + level + ' hPa non disponibile per questo modello.</p>';
    return false;
  }

  const mat = tKeys.map(k => hourly[k]);
  const mean = times.map((_, i) => avgAt(mat, i));

  const traces = [
    ...spaghettiTraces(mat, times, colorSpaghetti),
    { x: times, y: mean, mode: 'lines', line: { color: colorMean, width: 2.5 }, name: `Media T${level}` },
  ];

  const extra = {};
  const gKeys = Object.keys(hourly).filter(k => k.startsWith(`geopotential_height_${level}hPa`));
  if (gKeys.length) {
    const gMat = gKeys.map(k => hourly[k]);
    const gMean = times.map((_, i) => avgAt(gMat, i) / 10); // metri -> dam
    traces.push({
      x: times, y: gMean, mode: 'lines', yaxis: 'y2', visible: 'legendonly',
      line: { color: '#74c0fc', width: 1.5, dash: 'dash' }, name: `Geopotenziale ${level}hPa (dam)`,
    });
    extra.yaxis2 = { title: 'Geopotenziale (dam)', overlaying: 'y', side: 'right', gridcolor: 'transparent' };
  }

  // Isoterma 0°C: utile a 850hPa per la distinzione pioggia/neve, ma
  // nascosta di default — un clic sulla voce in legenda la mostra.
  if (level === '850') {
    traces.push({
      x: times, y: new Array(times.length).fill(0), mode: 'lines', visible: 'legendonly',
      line: { color: 'rgba(0,212,255,0.5)', dash: 'dot', width: 1.5 }, name: '0 °C',
    });
  }

  Plotly.newPlot(el, traces, baseLayout(`Temperatura a ${level}hPa — ensemble (${tKeys.length} membri)`, '°C', extra), plotlyConfig);
  return true;
}

let cachedHourly = null;
let cachedTimes = null;

function buildAllCharts(hourly, times) {
  cachedHourly = hourly;
  cachedTimes = times;

  const rendered = [
    renderTempChart(hourly, times),
    renderLevelChart('850', '#ffa94d', '#ffa94d', hourly, times),
    renderLevelChart('500', '#ff6b6b', '#ff6b6b', hourly, times),
    renderWindChart(hourly, times),
    renderPrecipChart(hourly, times),
  ];

  if (!rendered.some(Boolean)) {
    showWeatherError('Nessuna serie ensemble disponibile per i grafici richiesti.');
  }
}

/* ═══════════════════════════════════════════════════════
   Caricamento dati da Open-Meteo
   ═══════════════════════════════════════════════════════ */

async function loadOpenMeteoData() {
  if (typeof WEATHER_CONFIG === 'undefined') return;

  clearWeatherError();

  const urlCurrent = `https://api.open-meteo.com/v1/forecast?latitude=${WEATHER_CONFIG.lat}&longitude=${WEATHER_CONFIG.lon}&current=temperature_2m,relative_humidity_2m,surface_pressure,wind_speed_10m,precipitation&timezone=auto`;
  const hourlyVars = [
    'temperature_2m', 'precipitation', 'wind_speed_10m',
    'temperature_850hPa', 'geopotential_height_850hPa',
    'temperature_500hPa', 'geopotential_height_500hPa',
  ].join(',');
  const urlEnsemble = `https://ensemble-api.open-meteo.com/v1/ensemble?latitude=${WEATHER_CONFIG.lat}&longitude=${WEATHER_CONFIG.lon}&hourly=${hourlyVars}&models=ecmwf_ifs025&forecast_days=${WEATHER_CONFIG.forecast_days}&timezone=auto`;

  // 1. Dati attuali
  try {
    const resCurr = await fetch(urlCurrent);
    if (!resCurr.ok) throw new Error(`HTTP ${resCurr.status}`);
    const dataCurr = await resCurr.json();
    if (dataCurr.error) throw new Error(dataCurr.reason || 'risposta di errore da Open-Meteo');
    if (dataCurr.current) {
      document.getElementById('curr-temp').textContent = `${dataCurr.current.temperature_2m} °C`;
      document.getElementById('curr-hum').textContent = `${dataCurr.current.relative_humidity_2m} %`;
      document.getElementById('curr-press').textContent = `${dataCurr.current.surface_pressure} hPa`;
      document.getElementById('curr-wind').textContent = `${dataCurr.current.wind_speed_10m} km/h`;
      document.getElementById('curr-precip').textContent = `${dataCurr.current.precipitation} mm`;
    }
  } catch (e) {
    console.error('Errore dati attuali:', e);
    showWeatherError(`Dati attuali non disponibili (${e.message}).`);
  }

  // 2. Grafici ensemble
  try {
    const res = await fetch(urlEnsemble);
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const data = await res.json();
    if (data.error) throw new Error(data.reason || 'risposta di errore da Open-Meteo');

    if (!data || !data.hourly || !Array.isArray(data.hourly.time) || data.hourly.time.length === 0) {
      showWeatherError('Open-Meteo non ha restituito serie orarie per i grafici ensemble.');
      return;
    }

    buildAllCharts(data.hourly, data.hourly.time);
  } catch (e) {
    console.error('Errore recupero grafici Open-Meteo:', e);
    showWeatherError(`Grafici ensemble non disponibili (${e.message}).`);
  }
}
