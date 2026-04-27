/* global React, FairyAurora */
// Face variants for Aurora — 3 options side-by-side

function AuroraFace({ variant = 'pixel-smile', tone = 'dark', tokens }) {
  const { primary, accent, aura } = tokens;
  const fg = tone === 'dark' ? '#e2e8f0' : '#1e1b4b';
  const id = React.useId().replace(/[:]/g, '');
  const inkColor = tone === 'dark' ? '#0a0e1a' : '#ffffff';

  const faces = {
    'pixel-smile': (
      <g>
        <rect x="56.3" y="37.3" width="1.6" height="1.6" fill={inkColor} />
        <rect x="62.1" y="37.3" width="1.6" height="1.6" fill={inkColor} />
        <rect x="62.1" y="37.3" width="1.6" height="0.55" fill={accent} />
        <path d="M57.5 42.2 Q60 43.4 62.5 42.2" stroke={inkColor} strokeWidth="0.7" strokeLinecap="round" fill="none" opacity="0.75" />
      </g>
    ),
    'dignified': (
      <g>
        <circle cx="57" cy="38" r="1.1" fill={inkColor} />
        <circle cx="63" cy="38" r="1.1" fill={inkColor} />
        <path d="M57.3 42" stroke={inkColor} />
        <path d="M57.3 42.2 Q60 43.6 62.7 42.2" stroke={inkColor} strokeWidth="0.65" strokeLinecap="round" fill="none" opacity="0.8" />
      </g>
    ),
    'pixel-3dot': (
      <g>
        <rect x="56.3" y="37.3" width="1.6" height="1.6" fill={inkColor} />
        <rect x="62.1" y="37.3" width="1.6" height="1.6" fill={inkColor} />
        <rect x="62.1" y="37.3" width="1.6" height="0.55" fill={accent} />
        <rect x="58.4" y="41.4" width="0.9" height="0.9" fill={inkColor} opacity="0.65" />
        <rect x="59.55" y="41.4" width="0.9" height="0.9" fill={inkColor} opacity="0.65" />
        <rect x="60.7" y="41.4" width="0.9" height="0.9" fill={inkColor} opacity="0.65" />
      </g>
    ),
  };

  return (
    <svg width="220" height="220" viewBox="0 0 120 120" fill="none">
      <defs>
        <radialGradient id={`a-${id}`} cx="50%" cy="55%" r="55%">
          <stop offset="0%" stopColor={aura} stopOpacity="0.55" />
          <stop offset="55%" stopColor={primary} stopOpacity="0.15" />
          <stop offset="100%" stopColor={primary} stopOpacity="0" />
        </radialGradient>
        <linearGradient id={`wL-${id}`} x1="1" y1="0" x2="0" y2="1">
          <stop offset="0%" stopColor={primary} stopOpacity="0.85" />
          <stop offset="100%" stopColor={accent} stopOpacity="0.3" />
        </linearGradient>
        <linearGradient id={`wR-${id}`} x1="0" y1="0" x2="1" y2="1">
          <stop offset="0%" stopColor={primary} stopOpacity="0.85" />
          <stop offset="100%" stopColor={accent} stopOpacity="0.3" />
        </linearGradient>
      </defs>
      <circle cx="60" cy="62" r="56" fill={`url(#a-${id})`} />
      <path d="M55 50 Q20 30 14 54 Q16 72 44 66 Q52 62 55 52 Z" fill={`url(#wL-${id})`} stroke={primary} strokeWidth="0.6" strokeOpacity="0.5" />
      <path d="M65 50 Q100 30 106 54 Q104 72 76 66 Q68 62 65 52 Z" fill={`url(#wR-${id})`} stroke={primary} strokeWidth="0.6" strokeOpacity="0.5" />
      <path d="M56 64 Q34 72 32 86 Q40 92 54 82 Q58 76 56 66 Z" fill={`url(#wL-${id})`} opacity="0.6" />
      <path d="M64 64 Q86 72 88 86 Q80 92 66 82 Q62 76 64 66 Z" fill={`url(#wR-${id})`} opacity="0.6" />
      <path d="M60 44 Q64 46 64 56 Q64 72 60 82 Q56 72 56 56 Q56 46 60 44 Z" fill={fg} opacity="0.88" />
      <ellipse cx="60" cy="39" rx="7.5" ry="8.5" fill={fg} opacity="0.92" />
      {faces[variant]}
      <line x1="55" y1="70" x2="36" y2="90" stroke={accent} strokeWidth="1.2" strokeLinecap="round" />
      <circle cx="36" cy="90" r="2.5" fill={accent} />
      <circle cx="36" cy="90" r="4.5" fill={accent} opacity="0.25" />
      <text x="92" y="38" fontSize="11" fill={accent} opacity="0.85" fontFamily="monospace">✦</text>
    </svg>
  );
}

function FacePortrait({ label, note, variant, tokens }) {
  return (
    <div style={{
      width: '100%', height: '100%',
      background: 'radial-gradient(ellipse at center, #141829 0%, #0a0e1a 100%)',
      display:'flex', flexDirection:'column', alignItems:'center', justifyContent:'center', gap: 16,
      padding: 24, boxSizing: 'border-box', color:'#e9eaf5',
      fontFamily:'"Inter",system-ui,sans-serif',
    }}>
      <div style={{filter:`drop-shadow(0 0 16px ${tokens.aura}66)`}}>
        <AuroraFace variant={variant} tone="dark" tokens={tokens} />
      </div>
      <div style={{textAlign:'center'}}>
        <div style={{fontSize:15, fontWeight:600, letterSpacing:-0.2}}>{label}</div>
        <div style={{fontSize:12, color:'#8d90b5', marginTop:4, maxWidth:260}}>{note}</div>
      </div>
    </div>
  );
}

Object.assign(window, { AuroraFace, FacePortrait });
