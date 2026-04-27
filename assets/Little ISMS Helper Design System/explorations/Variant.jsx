/* global React, FairyVault, FairyAurora, FairySignal */

// Palette definitions for each variant × mode
// Three futuristic/technical directions — all rooted in Aurora's DNA
// (cool, solid, reliable) but with varying warmth and personality.
// The fairy is the self-ironic wink inside an otherwise grown-up palette.
const PALETTES = {
  vault: {
    name: 'Terminal',
    tagline: 'Slate + Phosphor-Mint + Violett-Spur · Ops-Center, aufgeräumt',
    dark: {
      bg: '#0b1016', surface: '#141a24', surface2: '#1d2531', border: '#243042', borderStrong: '#3b4c63',
      fg: '#e6edf3', fg2: '#aebed0', fg3: '#6a7b90',
      primary: '#5eead4', primaryHover: '#7dedd8', primaryGlow: 'rgba(94,234,212,0.28)',
      accent: '#a78bfa', accentGlow: 'rgba(167,139,250,0.32)',
      success: '#5eead4', warning: '#fbbf24', danger: '#f87171',
      fairyTokens: { primary: '#5eead4', accent: '#a78bfa', aura: '#818cf8' },
    },
    light: {
      bg: '#f1f4f8', surface: '#ffffff', surface2: '#e8ecf2', border: '#d7dde5', borderStrong: '#9aa5b4',
      fg: '#0b1016', fg2: '#3a4556', fg3: '#6a7387',
      primary: '#0d9488', primaryHover: '#0f766e', primaryGlow: 'rgba(13,148,136,0.22)',
      accent: '#6d28d9', accentGlow: 'rgba(109,40,217,0.22)',
      success: '#0d9488', warning: '#b45309', danger: '#be123c',
      fairyTokens: { primary: '#0d9488', accent: '#6d28d9', aura: '#7c3aed' },
    },
  },
  aurora: {
    name: 'Aurora',
    tagline: 'Cyan + Violett · kühl, ätherisch, Favorit',
    dark: {
      bg: '#0a0e1a', surface: '#141829', surface2: '#1e2139', border: '#232845', borderStrong: '#3d4270',
      fg: '#e9eaf5', fg2: '#b9bad4', fg3: '#6d6f99',
      primary: '#38bdf8', primaryHover: '#7dd3fc', primaryGlow: 'rgba(56,189,248,0.3)',
      accent: '#a78bfa', accentGlow: 'rgba(167,139,250,0.3)',
      success: '#34d399', warning: '#fbbf24', danger: '#f87171',
      fairyTokens: { primary: '#38bdf8', accent: '#a78bfa', aura: '#6366f1' },
    },
    light: {
      bg: '#f5f6fa', surface: '#ffffff', surface2: '#eef0f9', border: '#dfe3f0', borderStrong: '#b9bfd6',
      fg: '#1e1b4b', fg2: '#4c4a73', fg3: '#6d6b92',
      primary: '#0284c7', primaryHover: '#0369a1', primaryGlow: 'rgba(2,132,199,0.2)',
      accent: '#7c3aed', accentGlow: 'rgba(124,58,237,0.2)',
      success: '#059669', warning: '#d97706', danger: '#dc2626',
      fairyTokens: { primary: '#0284c7', accent: '#7c3aed', aura: '#818cf8' },
    },
  },
  signal: {
    name: 'Console',
    tagline: 'Midnight + Elektrisches Zitronen-Amber · Fee als Debug-Wink',
    dark: {
      bg: '#0a0a1f', surface: '#13132d', surface2: '#1b1b3d', border: '#252552', borderStrong: '#3f3f75',
      fg: '#edf0ff', fg2: '#b4b8d6', fg3: '#7075a1',
      primary: '#818cf8', primaryHover: '#a5b4fc', primaryGlow: 'rgba(129,140,248,0.3)',
      accent: '#facc15', accentGlow: 'rgba(250,204,21,0.4)',
      success: '#4ade80', warning: '#facc15', danger: '#fb7185',
      fairyTokens: { primary: '#a5b4fc', accent: '#fde047', aura: '#facc15' },
    },
    light: {
      bg: '#f4f5fb', surface: '#ffffff', surface2: '#ebecf5', border: '#d9dbeb', borderStrong: '#9ca0c2',
      fg: '#1e1b4b', fg2: '#3f3d6b', fg3: '#6a6a8c',
      primary: '#4338ca', primaryHover: '#3730a3', primaryGlow: 'rgba(67,56,202,0.2)',
      accent: '#a16207', accentGlow: 'rgba(161,98,7,0.25)',
      success: '#047857', warning: '#a16207', danger: '#be123c',
      fairyTokens: { primary: '#4338ca', accent: '#a16207', aura: '#ca8a04' },
    },
  },
};

const FAIRIES = { vault: FairyVault, aurora: FairyAurora, signal: FairySignal };

// Subtle glow helper — scales with `glowIntensity` (0..100). 30 keeps it dezent.
function glow(color, intensity = 30) {
  const a = (intensity / 100);
  return `0 0 ${Math.round(8 + intensity * 0.15)}px ${color.replace(/[\d.]+\)$/, `${a * 0.8})`)}`;
}

function Variant({ id, tone = 'dark', glowIntensity = 30 }) {
  const P = PALETTES[id];
  const T = P[tone];
  const Fairy = FAIRIES[id];

  const card = {
    background: T.surface,
    border: `1px solid ${T.border}`,
    borderRadius: 10,
    padding: 20,
    transition: 'all .25s',
  };

  const fontStack = '"Inter","Space Grotesk",-apple-system,BlinkMacSystemFont,"Segoe UI",system-ui,sans-serif';
  const monoStack = '"JetBrains Mono","SF Mono",Menlo,monospace';

  return (
    <div style={{
      width: '100%', height: '100%',
      background: T.bg,
      color: T.fg,
      fontFamily: fontStack,
      padding: 28,
      display: 'flex', flexDirection: 'column', gap: 20,
      boxSizing: 'border-box',
      overflow: 'hidden',
      position: 'relative',
    }}>
      {/* Subtle background pattern — grid for Vault, radial for Aurora, ring for Signal */}
      <div style={{
        position:'absolute', inset:0, pointerEvents:'none', opacity: tone === 'dark' ? 0.35 : 0.5,
        ...(id === 'vault' && {
          backgroundImage: `linear-gradient(${T.border} 1px, transparent 1px), linear-gradient(90deg, ${T.border} 1px, transparent 1px)`,
          backgroundSize: '48px 48px',
          maskImage: 'radial-gradient(ellipse at 50% 40%, black 20%, transparent 70%)',
          WebkitMaskImage: 'radial-gradient(ellipse at 50% 40%, black 20%, transparent 70%)',
        }),
        ...(id === 'aurora' && {
          background: `radial-gradient(ellipse 400px 220px at 30% 20%, ${T.fairyTokens.aura}22, transparent 70%), radial-gradient(ellipse 380px 240px at 80% 90%, ${T.accent}18, transparent 70%)`,
        }),
        ...(id === 'signal' && {
          backgroundImage: `radial-gradient(circle at 20% 30%, ${T.primary}15 0%, transparent 40%), radial-gradient(circle at 80% 80%, ${T.accent}10 0%, transparent 40%)`,
        }),
      }} />

      {/* Header row */}
      <div style={{display:'flex', justifyContent:'space-between', alignItems:'flex-start', position:'relative', zIndex:1}}>
        <div>
          <div style={{fontFamily: monoStack, fontSize: 10, letterSpacing: '0.12em', textTransform:'uppercase', color: T.primary, marginBottom: 4, opacity: 0.9}}>
            › Variante · {P.name}
          </div>
          <div style={{fontSize: 22, fontWeight: 700, letterSpacing: -0.3, lineHeight: 1.1}}>
            {tone === 'dark' ? 'Little ISMS Helper' : 'Little ISMS Helper'}
          </div>
          <div style={{fontSize: 12, color: T.fg2, marginTop: 4}}>
            {P.tagline}
          </div>
        </div>
        <div style={{
          fontFamily: monoStack, fontSize: 10, padding:'4px 10px', borderRadius: 4,
          background: tone === 'dark' ? T.surface2 : T.surface2,
          color: T.fg2, border: `1px solid ${T.border}`,
          textTransform: 'uppercase', letterSpacing: '0.08em',
        }}>
          {tone}
        </div>
      </div>

      {/* Main split: fee left, components right */}
      <div style={{display:'grid', gridTemplateColumns:'1fr 1.2fr', gap: 16, flex:1, position:'relative', zIndex:1, minHeight:0}}>

        {/* Fairy showcase */}
        <div style={{
          ...card,
          display:'flex', flexDirection:'column', alignItems:'center', justifyContent:'center', gap: 12,
          background: tone === 'dark'
            ? `radial-gradient(ellipse at center, ${T.surface2} 0%, ${T.surface} 100%)`
            : `radial-gradient(ellipse at center, ${T.surface} 0%, ${T.surface2} 100%)`,
          position: 'relative',
          overflow: 'hidden',
        }}>
          {/* Subtle tech/cyber backdrop — grid + dots + one trace, all very low opacity */}
          <svg
            width="100%" height="100%"
            viewBox="0 0 400 400" preserveAspectRatio="xMidYMid slice"
            style={{position:'absolute', inset: 0, pointerEvents:'none'}}
            aria-hidden
          >
            <defs>
              <pattern id={`grid-${id}-${tone}`} width="24" height="24" patternUnits="userSpaceOnUse">
                <path d="M 24 0 L 0 0 0 24" fill="none" stroke={T.fg} strokeWidth="0.5" opacity={tone === 'dark' ? 0.05 : 0.045} />
              </pattern>
              <pattern id={`dots-${id}-${tone}`} width="48" height="48" patternUnits="userSpaceOnUse">
                <circle cx="24" cy="24" r="0.9" fill={T.primary} opacity={tone === 'dark' ? 0.25 : 0.18} />
              </pattern>
              <radialGradient id={`fade-${id}-${tone}`} cx="50%" cy="50%" r="55%">
                <stop offset="0%" stopColor="#000" stopOpacity="0" />
                <stop offset="70%" stopColor="#000" stopOpacity="0" />
                <stop offset="100%" stopColor="#000" stopOpacity={tone === 'dark' ? 0.6 : 0.25} />
              </radialGradient>
              <mask id={`mask-${id}-${tone}`}>
                <rect width="100%" height="100%" fill="white" />
                <rect width="100%" height="100%" fill={`url(#fade-${id}-${tone})`} />
              </mask>
            </defs>
            <g mask={`url(#mask-${id}-${tone})`}>
              <rect width="100%" height="100%" fill={`url(#grid-${id}-${tone})`} />
              <rect width="100%" height="100%" fill={`url(#dots-${id}-${tone})`} />
              {/* Circuit traces — a few thin lines with node dots */}
              <g stroke={T.primary} strokeWidth="0.6" fill="none" opacity={tone === 'dark' ? 0.22 : 0.16}>
                <path d="M 0 80 L 90 80 L 120 110 L 200 110" strokeLinecap="round" />
                <path d="M 400 140 L 320 140 L 290 170 L 220 170" strokeLinecap="round" />
                <path d="M 40 320 L 120 320 L 150 290 L 220 290" strokeLinecap="round" />
                <path d="M 360 280 L 280 280" strokeLinecap="round" />
              </g>
              <g fill={T.accent} opacity={tone === 'dark' ? 0.45 : 0.3}>
                <circle cx="90" cy="80" r="1.6" />
                <circle cx="200" cy="110" r="1.6" />
                <circle cx="320" cy="140" r="1.6" />
                <circle cx="220" cy="170" r="1.6" />
                <circle cx="120" cy="320" r="1.6" />
                <circle cx="220" cy="290" r="1.6" />
                <circle cx="280" cy="280" r="1.6" />
              </g>
              {/* Scan-line hint */}
              <line x1="0" y1="200" x2="400" y2="200" stroke={T.primary} strokeWidth="0.4" opacity={tone === 'dark' ? 0.15 : 0.1} strokeDasharray="2 4" />
            </g>
          </svg>
          <div style={{
            position: 'relative', zIndex: 1,
            filter: tone === 'dark' && glowIntensity > 0
              ? `drop-shadow(0 0 ${6 + glowIntensity/6}px ${T.primaryGlow})`
              : 'none',
          }}>
            <Fairy size={140} tone={tone} tokens={T.fairyTokens} />
          </div>
          <div style={{fontFamily: monoStack, fontSize: 10, color: T.fg3, letterSpacing:'0.08em', textTransform:'uppercase', position:'relative', zIndex:1}}>
            Mascot · {P.name}
          </div>
        </div>

        {/* Right column — stacked UI samples */}
        <div style={{display:'flex', flexDirection:'column', gap: 12, minHeight:0}}>

          {/* Stat card */}
          <div style={{...card, padding: 16, position:'relative', overflow:'hidden'}}>
            <div style={{display:'flex', alignItems:'flex-start', gap: 12}}>
              <div style={{
                width: 40, height: 40, borderRadius: 8,
                background: `linear-gradient(135deg, ${T.primary}, ${T.primaryHover})`,
                display:'flex', alignItems:'center', justifyContent:'center',
                boxShadow: tone === 'dark' ? `0 0 ${12 + glowIntensity/5}px ${T.primaryGlow}` : 'none',
                color: '#fff', fontSize: 18, flexShrink: 0,
              }}>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                  <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                </svg>
              </div>
              <div style={{flex:1, minWidth:0}}>
                <div style={{fontFamily: monoStack, fontSize: 9, letterSpacing:'0.08em', textTransform:'uppercase', color: T.fg3, marginBottom: 2}}>
                  Reifegrad ISMS
                </div>
                <div style={{fontSize: 22, fontWeight: 700, letterSpacing:-0.3, lineHeight:1}}>87 %</div>
                <div style={{fontSize: 11, color: T.success, marginTop: 3}}>↑ 4 % vs. Vormonat</div>
              </div>
            </div>
          </div>

          {/* Fairy suggestion */}
          <div style={{
            padding:'10px 12px',
            background: tone === 'dark'
              ? `linear-gradient(135deg, ${T.accent}15, ${T.primary}08)`
              : `linear-gradient(135deg, ${T.accent}10, ${T.primary}05)`,
            border: `1px dashed ${T.accent}55`,
            borderRadius: 8,
            display:'flex', alignItems:'center', gap: 8,
            fontSize: 11.5, color: T.fg,
            boxShadow: tone === 'dark' && glowIntensity > 0 ? `0 0 ${glowIntensity/4}px ${T.accentGlow}` : 'none',
          }}>
            <span style={{color: T.accent, fontSize: 13}}>✦</span>
            <span style={{flex:1, lineHeight: 1.4}}>
              Fee-Vorschlag: <b style={{color: T.accent}}>A.5.15 Snippet übernehmen</b> — 87 % Ähnlichkeit
            </span>
          </div>

          {/* Buttons — cyber/tech styling: notched corners, mono caps, LED indicator */}
          <div style={{display:'flex', gap: 10, flexWrap:'wrap', alignItems:'center'}}>
            {/* Primary — notched cyber button */}
            <button style={{
              position:'relative',
              padding:'9px 16px 9px 18px',
              border:'none', cursor:'pointer',
              fontFamily: monoStack, fontSize: 11, fontWeight: 600, letterSpacing:'0.08em', textTransform:'uppercase',
              background: `linear-gradient(135deg, ${T.primary}, ${T.primaryHover})`,
              color: tone === 'dark' ? '#04121a' : '#ffffff',
              clipPath: 'polygon(8px 0, 100% 0, 100% calc(100% - 8px), calc(100% - 8px) 100%, 0 100%, 0 8px)',
              boxShadow: tone === 'dark' && glowIntensity > 0 ? `0 0 ${10+glowIntensity/6}px ${T.primaryGlow}` : `0 1px 2px ${T.primaryGlow}`,
              display:'inline-flex', alignItems:'center', gap: 8,
            }}>
              {/* LED status dot */}
              <span style={{
                width: 6, height: 6, borderRadius: '50%',
                background: tone === 'dark' ? '#04121a' : '#ffffff',
                boxShadow: tone === 'dark' ? 'none' : `0 0 4px rgba(255,255,255,0.8)`,
                flexShrink: 0,
              }} />
              Anmelden
              <span style={{fontSize: 10, opacity: 0.7}}>›</span>
            </button>

            {/* Secondary — bracketed outline */}
            <button style={{
              position:'relative',
              padding:'9px 14px',
              border:`1px solid ${T.borderStrong}`, cursor:'pointer', borderRadius: 3,
              fontFamily: monoStack, fontSize: 11, fontWeight: 500, letterSpacing:'0.08em', textTransform:'uppercase',
              background: 'transparent', color: T.fg2,
              display:'inline-flex', alignItems:'center', gap: 8,
            }}>
              <span style={{color: T.fg3, fontSize: 10}}>[</span>
              Abbrechen
              <span style={{color: T.fg3, fontSize: 10}}>]</span>
            </button>

            {/* Accent — notched ghost with fairy mark */}
            <button style={{
              position:'relative',
              padding:'9px 14px',
              border:`1px solid ${T.accent}`, cursor:'pointer',
              fontFamily: monoStack, fontSize: 11, fontWeight: 500, letterSpacing:'0.08em', textTransform:'uppercase',
              background: tone === 'dark' ? `${T.accent}10` : 'transparent', color: T.accent,
              clipPath: 'polygon(0 0, calc(100% - 8px) 0, 100% 8px, 100% 100%, 8px 100%, 0 calc(100% - 8px))',
              boxShadow: tone === 'dark' && glowIntensity > 0 ? `inset 0 0 ${glowIntensity/5}px ${T.accentGlow}, 0 0 6px ${T.accentGlow}` : 'none',
              display:'inline-flex', alignItems:'center', gap: 8,
            }}>
              <span style={{fontSize: 11}}>✦</span>
              Auto-ausfüllen
            </button>
          </div>

          {/* Badges */}
          <div style={{display:'flex', gap: 6, flexWrap:'wrap'}}>
            {[
              {lbl:'KRITISCH', col: T.danger},
              {lbl:'HOCH', col: T.warning},
              {lbl:'MITTEL', col: T.primary},
              {lbl:'UMGESETZT', col: T.success},
            ].map(b => (
              <span key={b.lbl} style={{
                padding:'3px 9px', borderRadius: 4,
                background: tone === 'dark' ? `${b.col}25` : `${b.col}18`,
                color: tone === 'dark' ? b.col : b.col,
                border: `1px solid ${b.col}55`,
                fontFamily: monoStack, fontSize: 9.5, fontWeight: 600, letterSpacing:'0.05em',
              }}>{b.lbl}</span>
            ))}
          </div>

          {/* Tiny data row */}
          <div style={{
            ...card, padding: '10px 14px',
            display:'flex', alignItems:'center', gap: 12, fontSize: 11.5,
          }}>
            <span style={{fontFamily: monoStack, fontSize: 11, color: T.primary, fontWeight: 600}}>A.5.16</span>
            <span style={{flex:1, color: T.fg}}>Identitätsmanagement</span>
            <span style={{fontFamily: monoStack, fontSize: 10, color: T.fg3}}>vor 12 Min</span>
          </div>

        </div>
      </div>

      {/* Bottom palette strip */}
      <div style={{display:'flex', gap: 6, position:'relative', zIndex:1}}>
        {[
          {c: T.primary, l:'Primary'},
          {c: T.accent, l:'Accent'},
          {c: T.success, l:'Success'},
          {c: T.warning, l:'Warning'},
          {c: T.danger, l:'Danger'},
          {c: T.surface, l:'Surface'},
          {c: T.bg, l:'BG'},
        ].map(s => (
          <div key={s.l} style={{flex:1, textAlign:'center'}}>
            <div style={{
              height: 26, borderRadius: 5, background: s.c,
              border: `1px solid ${T.border}`,
              boxShadow: tone==='dark' && glowIntensity > 0 && [T.primary, T.accent].includes(s.c) ? `0 0 ${glowIntensity/8}px ${s.c}66` : 'none',
            }} />
            <div style={{fontFamily: monoStack, fontSize: 8.5, color: T.fg3, marginTop: 3, letterSpacing:'0.04em'}}>
              {s.l}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

Object.assign(window, { Variant, PALETTES });
