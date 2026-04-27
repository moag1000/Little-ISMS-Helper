/* global React */
// Fairy with mood states — extends FairyAurora's visual language.
// Moods: idle, thinking, sleeping, warning, happy, focused, scanning, celebrating, working

function FairyCharacter({ size = 140, tone = 'dark', tokens, mood = 'idle' }) {
  const { primary, accent, aura } = tokens;
  const fg = tone === 'dark' ? '#e2e8f0' : '#f4f7ff';
  const bodyStroke = tone === 'dark' ? 'rgba(15,23,42,0.35)' : 'rgba(30,27,75,0.45)';
  const ink = tone === 'dark' ? '#0a0e1a' : '#1e1b4b';
  const id = React.useId().replace(/[:]/g, '');

  // Mood-driven aura tint + intensity
  const moodAura = {
    idle:        { color: aura,      scale: 1,    opacity: 0.55 },
    thinking:    { color: primary,   scale: 0.9,  opacity: 0.45 },
    sleeping:    { color: '#6366f1', scale: 0.85, opacity: 0.35 },
    warning:     { color: '#fbbf24', scale: 1.15, opacity: 0.75 },
    happy:       { color: accent,    scale: 1.1,  opacity: 0.72 },
    focused:     { color: primary,   scale: 0.95, opacity: 0.65 },
    scanning:    { color: '#22d3ee', scale: 1.05, opacity: 0.7  },
    celebrating: { color: '#ec4899', scale: 1.2,  opacity: 0.85 },
    working:     { color: accent,    scale: 1.0,  opacity: 0.6  },
  }[mood] || { color: aura, scale: 1, opacity: 0.55 };

  // Body bob animation per mood
  const bobAnim = {
    idle:        'fc-breathe 3s ease-in-out infinite',
    thinking:    'fc-tilt 4s ease-in-out infinite',
    sleeping:    'fc-sleep 5s ease-in-out infinite',
    warning:     'fc-shake 0.5s ease-in-out infinite',
    happy:       'fc-bounce 1.2s ease-in-out infinite',
    focused:     'fc-breathe 6s ease-in-out infinite',
    scanning:    'fc-breathe 2.5s ease-in-out infinite',
    celebrating: 'fc-bounce 0.6s ease-in-out infinite',
    working:     'fc-work 1.4s ease-in-out infinite',
  }[mood];

  return (
    <svg width={size} height={size} viewBox="0 0 120 120" fill="none" style={{display:'block', overflow:'visible'}}>
      <defs>
        <radialGradient id={`fca-${id}`} cx="50%" cy="55%" r="55%">
          <stop offset="0%"  stopColor={moodAura.color} stopOpacity={moodAura.opacity} />
          <stop offset="55%" stopColor={moodAura.color} stopOpacity={moodAura.opacity * 0.3} />
          <stop offset="100%" stopColor={moodAura.color} stopOpacity="0" />
        </radialGradient>
        <linearGradient id={`fcwL-${id}`} x1="1" y1="0" x2="0" y2="1">
          <stop offset="0%"   stopColor={primary} stopOpacity="0.85" />
          <stop offset="100%" stopColor={accent}  stopOpacity="0.3" />
        </linearGradient>
        <linearGradient id={`fcwR-${id}`} x1="0" y1="0" x2="1" y2="1">
          <stop offset="0%"   stopColor={primary} stopOpacity="0.85" />
          <stop offset="100%" stopColor={accent}  stopOpacity="0.3" />
        </linearGradient>
        <clipPath id={`fcclip-${id}`}>
          <rect x="0" y="0" width="120" height="120"/>
        </clipPath>
      </defs>

      {/* Aura */}
      <circle cx="60" cy="62" r={56 * moodAura.scale} fill={`url(#fca-${id})`}
              style={{ transformOrigin:'60px 62px', animation: mood === 'warning' ? 'fc-aura-pulse 0.8s ease-in-out infinite' : 'fc-aura-pulse 3s ease-in-out infinite' }}/>

      {/* Scan line (scanning mood only) */}
      {mood === 'scanning' && (
        <g clipPath={`url(#fcclip-${id})`}>
          <rect x="0" width="120" height="1.5" fill={moodAura.color} opacity="0.9" style={{ animation:'fc-scan 1.8s linear infinite' }}/>
          <rect x="0" width="120" height="18" fill={moodAura.color} opacity="0.12" style={{ animation:'fc-scan 1.8s linear infinite' }}/>
        </g>
      )}

      {/* All the "stuff" that should bob as one unit */}
      <g style={{ transformOrigin:'60px 60px', animation: bobAnim }}>

        {/* Wings — flap speed varies per mood */}
        <g style={{
          transformOrigin:'60px 58px',
          animation: mood === 'sleeping' ? 'none'
                   : mood === 'celebrating' ? 'fc-flap 0.18s ease-in-out infinite'
                   : mood === 'happy' ? 'fc-flap 0.35s ease-in-out infinite'
                   : mood === 'warning' ? 'fc-flap 0.2s ease-in-out infinite'
                   : 'fc-flap 1.1s ease-in-out infinite',
        }}>
          <path d="M55 50 Q20 30 14 54 Q16 72 44 66 Q52 62 55 52 Z" fill={`url(#fcwL-${id})`} stroke={primary} strokeWidth="0.6" strokeOpacity="0.5" />
          <path d="M65 50 Q100 30 106 54 Q104 72 76 66 Q68 62 65 52 Z" fill={`url(#fcwR-${id})`} stroke={primary} strokeWidth="0.6" strokeOpacity="0.5" />
          <path d="M56 64 Q34 72 32 86 Q40 92 54 82 Q58 76 56 66 Z" fill={`url(#fcwL-${id})`} opacity="0.6" />
          <path d="M64 64 Q86 72 88 86 Q80 92 66 82 Q62 76 64 66 Z" fill={`url(#fcwR-${id})`} opacity="0.6" />
          <path d="M55 52 Q40 48 22 54" stroke={fg} strokeOpacity="0.25" strokeWidth="0.5" fill="none" />
          <path d="M65 52 Q80 48 98 54" stroke={fg} strokeOpacity="0.25" strokeWidth="0.5" fill="none" />
          <circle cx="28" cy="52" r="1.1" fill={accent} opacity="0.75" />
          <circle cx="92" cy="52" r="1.1" fill={accent} opacity="0.75" />
        </g>

        {/* Body */}
        <path d="M60 44 Q64.5 46 64.5 54 Q63 58 63 62 Q64.5 66 64 74 Q62 80 60 82 Q58 80 56 74 Q55.5 66 57 62 Q57 58 55.5 54 Q55.5 46 60 44 Z"
              fill={fg} opacity="0.92" stroke={bodyStroke} strokeWidth="0.4" />

        {/* Head — tilted in thinking mood */}
        <g style={{ transformOrigin:'60px 39px', transform: mood === 'thinking' ? 'rotate(-8deg)' : mood === 'sleeping' ? 'rotate(10deg)' : 'none' }}>
          <ellipse cx="60" cy="39" rx="7.5" ry="8.5" fill={fg} opacity="0.97" stroke={bodyStroke} strokeWidth="0.4" />
          {/* Hair */}
          <path d="M54 36 Q50 32 48 28" stroke={primary} strokeWidth="0.8" strokeLinecap="round" fill="none" opacity="0.65" />
          <path d="M66 36 Q70 32 72 28" stroke={primary} strokeWidth="0.8" strokeLinecap="round" fill="none" opacity="0.65" />
          {/* Crown */}
          <path d="M54 32.5 Q60 29.5 66 32.5" stroke={accent} strokeWidth="0.7" strokeLinecap="round" fill="none" opacity="0.75" />
          <circle cx="60" cy="30" r="0.9" fill={accent} opacity="0.9" />

          {/* Face — per-mood */}
          {renderFace(mood, ink, tone)}
        </g>

        {/* Wand — hidden when sleeping */}
        {mood !== 'sleeping' && (
          <g>
            <line x1="55" y1="70" x2="36" y2="90" stroke={accent} strokeWidth="1.3" strokeLinecap="round" />
            <circle cx="36" cy="90" r="2.8" fill={accent} />
            <circle cx="36" cy="90" r="5" fill={accent} opacity="0.25"
                    style={{ transformOrigin:'36px 90px', animation: mood === 'celebrating' ? 'fc-wand-pop 0.5s ease-in-out infinite' : mood === 'warning' ? 'fc-wand-alert 0.6s ease-in-out infinite' : 'none' }}/>
          </g>
        )}
      </g>

      {/* Mood-specific external decorations (outside bob group) */}
      {mood === 'sleeping' && (
        <g>
          <text x="74" y="30" fontSize="10" fontWeight="600" fill={fg} opacity="0.7" fontFamily="monospace" style={{animation:'fc-zzz 2.5s ease-in-out infinite'}}>z</text>
          <text x="82" y="22" fontSize="8" fontWeight="600" fill={fg} opacity="0.5" fontFamily="monospace" style={{animation:'fc-zzz 2.5s ease-in-out infinite .4s'}}>z</text>
          <text x="88" y="16" fontSize="6" fontWeight="600" fill={fg} opacity="0.35" fontFamily="monospace" style={{animation:'fc-zzz 2.5s ease-in-out infinite .8s'}}>z</text>
        </g>
      )}

      {mood === 'thinking' && (
        <g>
          <circle cx="78" cy="24" r="6" fill={primary} opacity="0.15" stroke={primary} strokeOpacity="0.5" strokeWidth="0.6" style={{animation:'fc-think 1.8s ease-in-out infinite'}}/>
          <text x="75" y="27.5" fontSize="7" fontWeight="700" fill={primary} fontFamily="sans-serif" style={{animation:'fc-think 1.8s ease-in-out infinite'}}>?</text>
        </g>
      )}

      {mood === 'warning' && (
        <g style={{animation:'fc-alert-blink 0.5s ease-in-out infinite'}}>
          <path d="M84 16 L96 36 L72 36 Z" fill={moodAura.color} stroke={ink} strokeWidth="0.5" strokeLinejoin="round"/>
          <text x="82" y="32" fontSize="9" fontWeight="800" fill={ink} fontFamily="sans-serif">!</text>
        </g>
      )}

      {mood === 'happy' && (
        <>
          <text x="92" y="32" fontSize="10" fill={accent} opacity="0.9" fontFamily="monospace" style={{animation:'fc-sparkle 1.5s ease-in-out infinite'}}>✦</text>
          <text x="16" y="36" fontSize="7" fill={accent} opacity="0.7" fontFamily="monospace" style={{animation:'fc-sparkle 1.5s ease-in-out infinite .5s'}}>✦</text>
          <text x="94" y="68" fontSize="5" fill={primary} opacity="0.7" fontFamily="monospace" style={{animation:'fc-sparkle 1.5s ease-in-out infinite 1s'}}>✦</text>
        </>
      )}

      {mood === 'celebrating' && (
        <g style={{animation:'fc-confetti 0.8s ease-out infinite'}}>
          <rect x="20" y="18" width="3" height="7" fill="#ec4899" transform="rotate(20 21 21)"/>
          <rect x="96" y="26" width="3" height="7" fill="#fbbf24" transform="rotate(-30 97 29)"/>
          <rect x="30" y="10" width="2.5" height="6" fill={primary} transform="rotate(45 31 13)"/>
          <rect x="88" y="14" width="2.5" height="6" fill={accent} transform="rotate(-15 89 17)"/>
          <circle cx="14" cy="52" r="1.5" fill="#ec4899"/>
          <circle cx="104" cy="44" r="1.5" fill="#22d3ee"/>
          <text x="100" y="100" fontSize="10" fill={accent}>✦</text>
          <text x="14" y="100" fontSize="10" fill="#ec4899">✦</text>
        </g>
      )}

      {mood === 'focused' && (
        <g>
          {/* targeting reticle around body */}
          <circle cx="60" cy="60" r="36" stroke={primary} strokeWidth="0.6" strokeDasharray="2 3" opacity="0.5" fill="none" style={{transformOrigin:'60px 60px', animation:'fc-reticle 8s linear infinite'}}/>
          <line x1="60" y1="20" x2="60" y2="26" stroke={primary} strokeWidth="0.8" opacity="0.7"/>
          <line x1="60" y1="94" x2="60" y2="100" stroke={primary} strokeWidth="0.8" opacity="0.7"/>
          <line x1="20" y1="60" x2="26" y2="60" stroke={primary} strokeWidth="0.8" opacity="0.7"/>
          <line x1="94" y1="60" x2="100" y2="60" stroke={primary} strokeWidth="0.8" opacity="0.7"/>
        </g>
      )}

      {mood === 'working' && (
        <g>
          {/* small cog/gear turning near wand */}
          <g style={{transformOrigin:'105px 50px', animation:'fc-spin 2s linear infinite'}}>
            <circle cx="105" cy="50" r="4" stroke={accent} strokeWidth="0.7" fill="none"/>
            <line x1="105" y1="45" x2="105" y2="47" stroke={accent} strokeWidth="0.8"/>
            <line x1="105" y1="53" x2="105" y2="55" stroke={accent} strokeWidth="0.8"/>
            <line x1="100" y1="50" x2="102" y2="50" stroke={accent} strokeWidth="0.8"/>
            <line x1="108" y1="50" x2="110" y2="50" stroke={accent} strokeWidth="0.8"/>
            <circle cx="105" cy="50" r="1" fill={accent}/>
          </g>
          <g style={{transformOrigin:'14px 74px', animation:'fc-spin-rev 3s linear infinite'}}>
            <circle cx="14" cy="74" r="3" stroke={primary} strokeWidth="0.6" fill="none"/>
            <line x1="14" y1="70" x2="14" y2="72" stroke={primary} strokeWidth="0.7"/>
            <line x1="14" y1="76" x2="14" y2="78" stroke={primary} strokeWidth="0.7"/>
            <line x1="10" y1="74" x2="12" y2="74" stroke={primary} strokeWidth="0.7"/>
            <line x1="16" y1="74" x2="18" y2="74" stroke={primary} strokeWidth="0.7"/>
          </g>
        </g>
      )}

      {/* Global styles scoped to this component */}
      <style>{`
        @keyframes fc-breathe { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-2px); } }
        @keyframes fc-tilt { 0%, 100% { transform: translateY(0) rotate(-1deg); } 50% { transform: translateY(-1.5px) rotate(1deg); } }
        @keyframes fc-sleep { 0%, 100% { transform: translateY(0) rotate(6deg); } 50% { transform: translateY(-1px) rotate(6deg); } }
        @keyframes fc-shake { 0%, 100% { transform: translateX(0) translateY(0); } 25% { transform: translateX(-1.5px) translateY(-1px); } 75% { transform: translateX(1.5px) translateY(-1px); } }
        @keyframes fc-bounce { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-5px); } }
        @keyframes fc-work { 0%, 100% { transform: translateY(0) rotate(-0.5deg); } 50% { transform: translateY(-1.5px) rotate(0.5deg); } }
        @keyframes fc-flap { 0%, 100% { transform: scaleX(1); } 50% { transform: scaleX(0.82); } }
        @keyframes fc-aura-pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.6; } }
        @keyframes fc-zzz { 0% { opacity: 0; transform: translateY(4px); } 50% { opacity: 1; } 100% { opacity: 0; transform: translateY(-4px); } }
        @keyframes fc-think { 0%, 100% { opacity: 0.4; transform: translateY(0); } 50% { opacity: 1; transform: translateY(-2px); } }
        @keyframes fc-alert-blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.35; } }
        @keyframes fc-sparkle { 0%, 100% { opacity: 0.3; transform: scale(0.8); } 50% { opacity: 1; transform: scale(1.1); } }
        @keyframes fc-confetti { 0% { opacity: 0; transform: translateY(-4px); } 40% { opacity: 1; } 100% { opacity: 0; transform: translateY(8px); } }
        @keyframes fc-reticle { to { transform: rotate(360deg); } }
        @keyframes fc-scan { 0% { transform: translateY(-20px); } 100% { transform: translateY(140px); } }
        @keyframes fc-wand-pop { 0%, 100% { transform: scale(1); opacity: 0.25; } 50% { transform: scale(2); opacity: 0.6; } }
        @keyframes fc-wand-alert { 0%, 100% { transform: scale(1); opacity: 0.3; } 50% { transform: scale(1.4); opacity: 0.7; } }
        @keyframes fc-spin { to { transform: rotate(360deg); } }
        @keyframes fc-spin-rev { to { transform: rotate(-360deg); } }
        @keyframes fc-blink { 0%, 92%, 100% { transform: scaleY(1); } 95% { transform: scaleY(0.15); } }
      `}</style>
    </svg>
  );
}

function renderFace(mood, ink, tone) {
  const highlight = tone === 'dark' ? 0.8 : 0;
  switch (mood) {
    case 'sleeping':
      return (
        <g>
          {/* closed eyes with lashes */}
          <path d="M55.5 38.2 Q57 39.2 58.5 38.2" stroke={ink} strokeWidth="0.8" strokeLinecap="round" fill="none"/>
          <path d="M61.5 38.2 Q63 39.2 64.5 38.2" stroke={ink} strokeWidth="0.8" strokeLinecap="round" fill="none"/>
          {/* tiny lashes */}
          <line x1="55.5" y1="37.6" x2="55" y2="37" stroke={ink} strokeWidth="0.4" opacity="0.6"/>
          <line x1="64.5" y1="37.6" x2="65" y2="37" stroke={ink} strokeWidth="0.4" opacity="0.6"/>
          {/* peaceful mouth */}
          <path d="M58.2 42.4 Q60 43 61.8 42.4" stroke={ink} strokeWidth="0.6" strokeLinecap="round" fill="none" opacity="0.7"/>
        </g>
      );
    case 'thinking':
      return (
        <g>
          {/* one eye looking up */}
          <circle cx="57" cy="37.5" r="1.15" fill={ink} opacity="0.92" style={{animation:'fc-blink 4s infinite'}}/>
          <circle cx="63" cy="37.5" r="1.15" fill={ink} opacity="0.92" style={{animation:'fc-blink 4s infinite'}}/>
          <circle cx="57.3" cy="37.2" r="0.3" fill="#fff" opacity={highlight} />
          <circle cx="63.3" cy="37.2" r="0.3" fill="#fff" opacity={highlight} />
          {/* pursed mouth — small circle */}
          <circle cx="60" cy="42.5" r="0.7" fill={ink} opacity="0.7"/>
        </g>
      );
    case 'warning':
      return (
        <g>
          {/* wide alert eyes */}
          <circle cx="57" cy="38" r="1.5" fill={ink} opacity="0.95"/>
          <circle cx="63" cy="38" r="1.5" fill={ink} opacity="0.95"/>
          <circle cx="57.3" cy="37.6" r="0.4" fill="#fff" opacity={highlight}/>
          <circle cx="63.3" cy="37.6" r="0.4" fill="#fff" opacity={highlight}/>
          {/* concerned mouth — small flat */}
          <line x1="58" y1="42.5" x2="62" y2="42.5" stroke={ink} strokeWidth="0.8" strokeLinecap="round"/>
        </g>
      );
    case 'happy':
    case 'celebrating':
      return (
        <g>
          {/* squint-happy ^ ^ eyes */}
          <path d="M55.5 38.5 Q57 37 58.5 38.5" stroke={ink} strokeWidth="0.9" strokeLinecap="round" fill="none"/>
          <path d="M61.5 38.5 Q63 37 64.5 38.5" stroke={ink} strokeWidth="0.9" strokeLinecap="round" fill="none"/>
          {/* big smile */}
          <path d="M56.5 41.5 Q60 44.5 63.5 41.5" stroke={ink} strokeWidth="0.9" strokeLinecap="round" fill="none"/>
          {/* cheek blush */}
          <circle cx="54" cy="40" r="1.6" fill="#ec4899" opacity="0.3"/>
          <circle cx="66" cy="40" r="1.6" fill="#ec4899" opacity="0.3"/>
        </g>
      );
    case 'focused':
      return (
        <g>
          {/* narrowed determined eyes */}
          <path d="M55 38 L59 38" stroke={ink} strokeWidth="1.2" strokeLinecap="round"/>
          <path d="M61 38 L65 38" stroke={ink} strokeWidth="1.2" strokeLinecap="round"/>
          {/* neutral small mouth */}
          <path d="M58.5 42.3 Q60 42.8 61.5 42.3" stroke={ink} strokeWidth="0.7" strokeLinecap="round" fill="none"/>
        </g>
      );
    case 'scanning':
      return (
        <g>
          {/* radar eyes */}
          <circle cx="57" cy="38" r="1.15" fill={ink} opacity="0.92"/>
          <circle cx="63" cy="38" r="1.15" fill={ink} opacity="0.92"/>
          <circle cx="57" cy="38" r="2.2" stroke="#22d3ee" strokeWidth="0.3" fill="none" opacity="0.6"/>
          <circle cx="63" cy="38" r="2.2" stroke="#22d3ee" strokeWidth="0.3" fill="none" opacity="0.6"/>
          <path d="M57.3 42.2 Q60 43.2 62.7 42.2" stroke={ink} strokeWidth="0.7" strokeLinecap="round" fill="none" opacity="0.85"/>
        </g>
      );
    case 'working':
      return (
        <g>
          {/* concentrated eyes with slight lean */}
          <circle cx="57" cy="38" r="1.15" fill={ink} opacity="0.92" style={{animation:'fc-blink 5s infinite'}}/>
          <circle cx="63" cy="38" r="1.15" fill={ink} opacity="0.92" style={{animation:'fc-blink 5s infinite .3s'}}/>
          <circle cx="57.3" cy="37.7" r="0.3" fill="#fff" opacity={highlight}/>
          <circle cx="63.3" cy="37.7" r="0.3" fill="#fff" opacity={highlight}/>
          {/* slight tongue-out focused */}
          <path d="M58.5 42.2 Q60 43.5 61.5 42.2" stroke={ink} strokeWidth="0.7" strokeLinecap="round" fill="none"/>
          <circle cx="60" cy="43" r="0.5" fill="#ec4899" opacity="0.6"/>
        </g>
      );
    case 'idle':
    default:
      return (
        <g>
          <circle cx="57" cy="38" r="1.15" fill={ink} opacity="0.92" style={{animation:'fc-blink 6s infinite'}}/>
          <circle cx="63" cy="38" r="1.15" fill={ink} opacity="0.92" style={{animation:'fc-blink 6s infinite .1s'}}/>
          <circle cx="57.3" cy="37.7" r="0.3" fill="#fff" opacity={highlight}/>
          <circle cx="63.3" cy="37.7" r="0.3" fill="#fff" opacity={highlight}/>
          <path d="M57.3 42.2 Q60 43.7 62.7 42.2" stroke={ink} strokeWidth="0.7" strokeLinecap="round" fill="none" opacity="0.85"/>
        </g>
      );
  }
}

Object.assign(window, { FairyCharacter });
