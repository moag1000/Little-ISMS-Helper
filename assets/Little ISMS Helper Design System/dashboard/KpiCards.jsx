/* global React, T, FONT_SANS, FONT_MONO, Icon */

// -------------- KPI CARD --------------
function KpiCard({ label, value, unit, delta, deltaTone = 'positive', sparkline, hint, emphasis }) {
  return (
    <div style={{
      background: emphasis ? `linear-gradient(135deg, ${T.surface2}, ${T.surface})` : T.surface,
      border: `1px solid ${emphasis ? T.borderStrong : T.border}`,
      borderRadius: 8, padding: 16,
      position: 'relative', overflow: 'hidden',
      boxShadow: emphasis ? `0 0 0 1px ${T.primaryGlow}, 0 8px 24px -12px ${T.primaryGlow}` : 'none',
    }}>
      {emphasis && (
        <div style={{
          position:'absolute', top: 0, left: 0, right: 0, height: 1,
          background: `linear-gradient(90deg, transparent, ${T.primary}, transparent)`,
        }} />
      )}
      <div style={{display:'flex', justifyContent:'space-between', alignItems:'flex-start', marginBottom: 10}}>
        <div style={{
          fontFamily: FONT_MONO, fontSize: 10, letterSpacing: '0.1em', textTransform: 'uppercase',
          color: T.fg3,
        }}>{label}</div>
        {delta && (
          <div style={{
            display:'flex', alignItems:'center', gap: 2,
            fontFamily: FONT_MONO, fontSize: 10, fontWeight: 600,
            color: deltaTone === 'positive' ? T.success : deltaTone === 'negative' ? T.danger : T.warning,
          }}>
            <Icon name={deltaTone === 'positive' ? 'up' : 'down'} size={10} strokeWidth={2.5} />
            {delta}
          </div>
        )}
      </div>
      <div style={{display:'flex', alignItems:'baseline', gap: 4, marginBottom: 4}}>
        <div style={{
          fontFamily: FONT_SANS, fontSize: 30, fontWeight: 600,
          color: emphasis ? T.primary : T.fg, letterSpacing: -0.5, lineHeight: 1,
        }}>{value}</div>
        {unit && <span style={{fontFamily: FONT_MONO, fontSize: 13, color: T.fg3}}>{unit}</span>}
      </div>
      {hint && (
        <div style={{fontSize: 11, color: T.fg3, marginTop: 6, fontFamily: FONT_SANS}}>{hint}</div>
      )}
      {sparkline && (
        <div style={{marginTop: 10, height: 32, display:'flex', alignItems:'flex-end'}}>
          {sparkline}
        </div>
      )}
    </div>
  );
}

function Sparkline({ data, color, fillOpacity = 0.15 }) {
  const max = Math.max(...data);
  const min = Math.min(...data);
  const range = max - min || 1;
  const w = 140, h = 32;
  const step = w / (data.length - 1);
  const pts = data.map((d, i) => [i * step, h - ((d - min) / range) * (h - 4) - 2]);
  const path = 'M ' + pts.map(p => `${p[0].toFixed(1)} ${p[1].toFixed(1)}`).join(' L ');
  const areaPath = path + ` L ${w} ${h} L 0 ${h} Z`;
  return (
    <svg width="100%" height={h} viewBox={`0 0 ${w} ${h}`} preserveAspectRatio="none">
      <path d={areaPath} fill={color} opacity={fillOpacity} />
      <path d={path} stroke={color} strokeWidth="1.5" fill="none" strokeLinecap="round" strokeLinejoin="round" />
      <circle cx={pts[pts.length - 1][0]} cy={pts[pts.length - 1][1]} r="2.5" fill={color} />
      <circle cx={pts[pts.length - 1][0]} cy={pts[pts.length - 1][1]} r="5" fill={color} opacity="0.2" />
    </svg>
  );
}

// -------------- KPI ROW --------------
function KpiRow() {
  return (
    <div style={{display:'grid', gridTemplateColumns:'1.1fr 1fr 1fr 1fr', gap: 12, marginBottom: 16}}>
      <KpiCard
        emphasis
        label="Reifegrad ISMS"
        value="73"
        unit="%"
        delta="+4"
        deltaTone="positive"
        hint="ISO 27001:2022 · Ziel Q4: 85 %"
        sparkline={<Sparkline data={[54, 56, 58, 61, 60, 63, 66, 69, 71, 73]} color={T.primary} />}
      />
      <KpiCard
        label="Offene Findings"
        value="12"
        delta="-3"
        deltaTone="positive"
        hint="3 kritisch · 5 mittel · 4 niedrig"
        sparkline={<Sparkline data={[21, 19, 18, 20, 17, 15, 14, 13, 12]} color={T.accent} />}
      />
      <KpiCard
        label="Nächstes Audit"
        value="18"
        unit="Tage"
        hint="NIS-2 Bestandsaufnahme · 15. Mai"
        sparkline={
          <div style={{display:'flex', gap: 2, width:'100%', alignItems:'flex-end', height: 32}}>
            {Array.from({length:18}).map((_, i) => (
              <div key={i} style={{
                flex: 1, height: `${30 - (i * 1.3)}%`,
                background: i < 4 ? T.warning : T.primary,
                opacity: 0.2 + (i/18) * 0.6, borderRadius: 1,
              }} />
            ))}
          </div>
        }
      />
      <KpiCard
        label="Control-Abdeckung"
        value="94"
        unit="/ 114"
        delta="+2"
        deltaTone="positive"
        hint="20 offen · 6 nicht anwendbar"
        sparkline={
          <div style={{
            height: 6, background: T.surface3, borderRadius: 3, overflow:'hidden', marginTop: 20,
          }}>
            <div style={{
              width: '82%', height: '100%',
              background: `linear-gradient(90deg, ${T.primary}, ${T.accent})`,
              boxShadow: `0 0 8px ${T.primaryGlow}`,
            }} />
          </div>
        }
      />
    </div>
  );
}

Object.assign(window, { KpiCard, KpiRow, Sparkline });
