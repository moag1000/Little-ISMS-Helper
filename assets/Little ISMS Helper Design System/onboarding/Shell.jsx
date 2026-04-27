/* global React, T, FONT_SANS, FONT_MONO, Icon, FairyAurora */

// ====== Shared bits ======
function TechBackdrop({ intensity = 1 }) {
  const id = React.useId().replace(/[:]/g, '');
  return (
    <svg style={{position:'absolute', inset: 0, pointerEvents:'none', opacity: intensity}} width="100%" height="100%" viewBox="0 0 600 800" preserveAspectRatio="xMidYMid slice" aria-hidden>
      <defs>
        <pattern id={`g-${id}`} width="24" height="24" patternUnits="userSpaceOnUse">
          <path d="M 24 0 L 0 0 0 24" fill="none" stroke={T.fg} strokeWidth="0.4" opacity="0.06"/>
        </pattern>
        <pattern id={`d-${id}`} width="48" height="48" patternUnits="userSpaceOnUse">
          <circle cx="24" cy="24" r="0.9" fill={T.primary} opacity="0.22"/>
        </pattern>
        <radialGradient id={`f-${id}`} cx="40%" cy="45%" r="60%">
          <stop offset="0%" stopColor="#000" stopOpacity="0"/>
          <stop offset="65%" stopColor="#000" stopOpacity="0"/>
          <stop offset="100%" stopColor="#000" stopOpacity="0.55"/>
        </radialGradient>
        <mask id={`m-${id}`}>
          <rect width="100%" height="100%" fill="white"/>
          <rect width="100%" height="100%" fill={`url(#f-${id})`}/>
        </mask>
      </defs>
      <g mask={`url(#m-${id})`}>
        <rect width="100%" height="100%" fill={`url(#g-${id})`}/>
        <rect width="100%" height="100%" fill={`url(#d-${id})`}/>
        <g stroke={T.primary} strokeWidth="0.55" fill="none" opacity="0.22">
          <path d="M 0 160 L 140 160 L 170 190 L 290 190" strokeLinecap="round"/>
          <path d="M 600 280 L 460 280 L 430 310 L 340 310" strokeLinecap="round"/>
          <path d="M 60 560 L 180 560 L 210 530 L 330 530" strokeLinecap="round"/>
          <path d="M 520 620 L 400 620" strokeLinecap="round"/>
        </g>
        <g fill={T.accent} opacity="0.5">
          <circle cx="140" cy="160" r="1.8"/>
          <circle cx="290" cy="190" r="1.8"/>
          <circle cx="460" cy="280" r="1.8"/>
          <circle cx="340" cy="310" r="1.8"/>
          <circle cx="180" cy="560" r="1.8"/>
          <circle cx="330" cy="530" r="1.8"/>
          <circle cx="400" cy="620" r="1.8"/>
        </g>
      </g>
    </svg>
  );
}

function Brand({ size = 'md' }) {
  const s = size === 'lg' ? 36 : 28;
  return (
    <div style={{display:'flex', alignItems:'center', gap: 10}}>
      <div style={{
        width: s, height: s, borderRadius: 7,
        background: `linear-gradient(135deg, ${T.primary}, ${T.accent})`,
        display:'flex', alignItems:'center', justifyContent:'center',
        boxShadow: `0 0 14px ${T.primaryGlow}`,
        color:'#04121a', fontSize: s * 0.45,
      }}>✦</div>
      <div>
        <div style={{color: T.fg, fontSize: size === 'lg' ? 18 : 15, fontWeight: 600, letterSpacing: -0.3}}>Alvara</div>
        <div style={{color: T.fg3, fontSize: 10, fontFamily: FONT_MONO, letterSpacing: '0.08em', textTransform:'uppercase'}}>ISMS · v3.4</div>
      </div>
    </div>
  );
}

// Cyber button — Primary (notched corners)
function CyberButton({ children, variant = 'primary', onClick, icon, disabled, full }) {
  const variants = {
    primary: {
      bg: `linear-gradient(135deg, ${T.primary}, ${T.primaryHover})`,
      color: '#04121a', border: 'none',
      clip: 'polygon(8px 0, 100% 0, 100% calc(100% - 8px), calc(100% - 8px) 100%, 0 100%, 0 8px)',
      shadow: `0 0 14px ${T.primaryGlow}`,
    },
    secondary: {
      bg: 'transparent', color: T.fg2, border: `1px solid ${T.borderStrong}`, radius: 3, shadow: 'none',
    },
    accent: {
      bg: `${T.accent}15`, color: T.accent, border: `1px solid ${T.accent}`,
      clip: 'polygon(0 0, calc(100% - 8px) 0, 100% 8px, 100% 100%, 8px 100%, 0 calc(100% - 8px))',
      shadow: `0 0 10px ${T.accentGlow}`,
    },
  };
  const v = variants[variant];
  return (
    <button onClick={onClick} disabled={disabled} style={{
      padding:'11px 18px',
      fontFamily: FONT_MONO, fontSize: 11, fontWeight: 600, letterSpacing:'0.1em', textTransform:'uppercase',
      background: v.bg, color: v.color, border: v.border, borderRadius: v.radius || 0,
      clipPath: v.clip, boxShadow: v.shadow,
      cursor: disabled ? 'not-allowed' : 'pointer', opacity: disabled ? 0.5 : 1,
      display:'inline-flex', alignItems:'center', justifyContent:'center', gap: 8,
      width: full ? '100%' : 'auto', transition: 'all .2s',
    }}>
      {variant === 'primary' && <span style={{width: 6, height: 6, borderRadius: '50%', background:'#04121a'}}/>}
      {icon && <Icon name={icon} size={13}/>}
      {children}
      {variant === 'primary' && <span style={{fontSize: 11, opacity: 0.7}}>›</span>}
    </button>
  );
}

// Text input with cyber framing
function CyberInput({ label, hint, value, onChange, type = 'text', placeholder, suffix, prefilled }) {
  return (
    <label style={{display:'flex', flexDirection:'column', gap: 6}}>
      <span style={{
        fontFamily: FONT_MONO, fontSize: 10, letterSpacing:'0.1em', textTransform:'uppercase',
        color: T.fg3, display:'flex', alignItems:'center', gap: 8,
      }}>
        {label}
        {prefilled && (
          <span style={{
            display:'inline-flex', alignItems:'center', gap: 3,
            color: T.accent, fontSize: 9,
            padding:'1px 5px', background:`${T.accent}18`, borderRadius: 2,
          }}>✦ von Alva</span>
        )}
      </span>
      <div style={{
        position:'relative',
        display:'flex', alignItems:'center',
        background: T.bg, border: `1px solid ${prefilled ? T.accent + '60' : T.border}`,
        borderRadius: 4,
        boxShadow: prefilled ? `inset 0 0 0 1px ${T.accent}15` : 'none',
      }}>
        {/* corner markers */}
        <span style={{position:'absolute', top: -1, left: -1, width: 6, height: 6, borderTop: `1px solid ${T.primary}`, borderLeft: `1px solid ${T.primary}`}}/>
        <span style={{position:'absolute', bottom: -1, right: -1, width: 6, height: 6, borderBottom: `1px solid ${T.primary}`, borderRight: `1px solid ${T.primary}`}}/>
        <input
          type={type} value={value} onChange={onChange} placeholder={placeholder}
          style={{
            flex: 1, background:'transparent', border:'none', outline:'none',
            color: T.fg, fontFamily: FONT_SANS, fontSize: 14, padding:'11px 12px',
          }}
        />
        {suffix && <span style={{color: T.fg3, fontFamily: FONT_MONO, fontSize: 11, paddingRight: 12}}>{suffix}</span>}
      </div>
      {hint && <span style={{color: T.fg3, fontSize: 11}}>{hint}</span>}
    </label>
  );
}

Object.assign(window, { TechBackdrop, Brand, CyberButton, CyberInput });
