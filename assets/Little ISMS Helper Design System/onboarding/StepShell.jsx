/* global React, T, FONT_SANS, FONT_MONO, Icon, CyberButton, CyberInput */

// Shared pieces used across setup steps

// A compact field-group header ("Schritt 4 · Admin-Benutzer")
function StepHeader({ num, total, kind, title, sub }) {
  return (
    <div>
      <div style={{fontFamily: FONT_MONO, fontSize: 10, letterSpacing:'0.12em', textTransform:'uppercase', color: T.fg3}}>
        Schritt {num} / {total} · {kind}
      </div>
      <div style={{color: T.fg, fontSize: 24, fontWeight: 600, letterSpacing: -0.5, marginTop: 8, lineHeight: 1.2}}>{title}</div>
      {sub && <div style={{color: T.fg2, fontSize: 13, marginTop: 10, lineHeight: 1.55}}>{sub}</div>}
    </div>
  );
}

// Check item row (for system-check, summary lists)
function CheckRow({ status, label, value, hint }) {
  const colors = { ok: T.success, warn: T.warning, err: T.danger, idle: T.fg3 };
  const glyphs = { ok: '✓', warn: '!', err: '✕', idle: '·' };
  return (
    <div style={{
      display:'grid', gridTemplateColumns:'20px 1fr auto', gap: 10, alignItems:'center',
      padding: '8px 12px', background: T.surface, border: `1px solid ${T.border}`,
      borderRadius: 4, borderLeft: `2px solid ${colors[status] || T.border}`,
    }}>
      <span style={{
        color: colors[status], fontFamily: FONT_MONO, fontWeight: 700, fontSize: 13,
        textAlign:'center',
      }}>{glyphs[status]}</span>
      <div>
        <div style={{color: T.fg, fontSize: 12.5, fontFamily: FONT_SANS, fontWeight: 500}}>{label}</div>
        {hint && <div style={{color: T.fg3, fontSize: 10.5, fontFamily: FONT_MONO, marginTop: 1}}>{hint}</div>}
      </div>
      {value && <div style={{color: colors[status] || T.fg2, fontFamily: FONT_MONO, fontSize: 11, letterSpacing:'0.04em'}}>{value}</div>}
    </div>
  );
}

// Toggle card
function ToggleCard({ on, onClick, title, sub, badge }) {
  return (
    <button onClick={onClick} style={{
      textAlign:'left', cursor:'pointer', position:'relative',
      padding:'10px 12px', background: on ? `${T.primary}12` : T.surface,
      border: `1px solid ${on ? T.primary : T.border}`, borderRadius: 5,
      display:'flex', alignItems:'flex-start', gap: 10,
      boxShadow: on ? `0 0 10px ${T.primaryGlow}, inset 0 0 0 1px ${T.primary}30` : 'none',
    }}>
      <span style={{
        width: 14, height: 14, borderRadius: 2,
        border: `1px solid ${on ? T.primary : T.borderStrong}`,
        background: on ? T.primary : 'transparent',
        display:'flex', alignItems:'center', justifyContent:'center', flexShrink: 0, marginTop: 2,
      }}>
        {on && <Icon name="check" size={10} strokeWidth={3} style={{color:'#04121a'}}/>}
      </span>
      <div style={{flex: 1, minWidth: 0}}>
        <div style={{color: T.fg, fontSize: 12.5, fontWeight: 500, fontFamily: FONT_SANS, display:'flex', alignItems:'center', gap: 6}}>
          {title}
          {badge && <span style={{fontFamily: FONT_MONO, fontSize: 8, letterSpacing:'0.1em', color: T.accent, padding:'1px 4px', background:`${T.accent}18`, borderRadius: 2}}>{badge}</span>}
        </div>
        {sub && <div style={{color: T.fg3, fontSize: 10.5, fontFamily: FONT_SANS, marginTop: 1}}>{sub}</div>}
      </div>
    </button>
  );
}

// Select (styled consistent)
function CyberSelect({ label, value, onChange, options }) {
  return (
    <label style={{display:'flex', flexDirection:'column', gap: 6}}>
      <span style={{fontFamily: FONT_MONO, fontSize: 10, letterSpacing:'0.1em', textTransform:'uppercase', color: T.fg3}}>{label}</span>
      <div style={{position:'relative'}}>
        <span style={{position:'absolute', top: -1, left: -1, width: 6, height: 6, borderTop:`1px solid ${T.primary}`, borderLeft:`1px solid ${T.primary}`}}/>
        <span style={{position:'absolute', bottom: -1, right: -1, width: 6, height: 6, borderBottom:`1px solid ${T.primary}`, borderRight:`1px solid ${T.primary}`}}/>
        <select value={value} onChange={onChange} style={{
          width:'100%', background: T.bg, border: `1px solid ${T.border}`, borderRadius: 4,
          color: T.fg, fontFamily: FONT_SANS, fontSize: 13.5, padding:'10px 12px', outline:'none', appearance:'none', cursor:'pointer',
        }}>
          {options.map(o => <option key={o.value} value={o.value} style={{background: T.bg}}>{o.label}</option>)}
        </select>
        <span style={{position:'absolute', right: 14, top:'50%', transform:'translateY(-50%)', color: T.fg3, pointerEvents:'none', fontFamily: FONT_MONO}}>▾</span>
      </div>
    </label>
  );
}

// NavBar (prev/next) — consistent across all steps
function NavBar({ onBack, onNext, backLabel = 'Zurück', nextLabel = 'Weiter', nextDisabled, secondary }) {
  return (
    <div style={{display:'flex', gap: 10, justifyContent:'space-between', alignItems:'center'}}>
      <div>
        {onBack && <CyberButton variant="secondary" onClick={onBack}>{backLabel}</CyberButton>}
      </div>
      <div style={{display:'flex', gap: 10}}>
        {secondary}
        {onNext && <CyberButton onClick={onNext} disabled={nextDisabled}>{nextLabel}</CyberButton>}
      </div>
    </div>
  );
}

Object.assign(window, { StepHeader, CheckRow, ToggleCard, CyberSelect, NavBar });
