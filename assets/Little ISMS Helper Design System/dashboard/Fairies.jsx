/* global React */
// Three fairy variants — each takes `size`, `tone` ('dark'|'light'), `tokens`
// `tokens` = { primary, accent, aura } so each variant uses its own palette.

function FairyVault({ size = 120, tone = 'dark', tokens }) {
  const { primary, accent, aura } = tokens;
  const fg = tone === 'dark' ? '#e2e8f0' : '#1e293b';
  const bg = tone === 'dark' ? 'transparent' : 'transparent';
  const id = React.useId().replace(/[:]/g, '');
  return (
    <svg width={size} height={size} viewBox="0 0 120 120" fill="none" style={{display:'block'}}>
      <defs>
        <radialGradient id={`aura-${id}`} cx="50%" cy="50%" r="50%">
          <stop offset="0%" stopColor={aura} stopOpacity="0.5" />
          <stop offset="70%" stopColor={aura} stopOpacity="0.08" />
          <stop offset="100%" stopColor={aura} stopOpacity="0" />
        </radialGradient>
        <linearGradient id={`wing-${id}`} x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stopColor={primary} stopOpacity="0.9" />
          <stop offset="100%" stopColor={primary} stopOpacity="0.25" />
        </linearGradient>
      </defs>

      {/* Aura halo */}
      <circle cx="60" cy="60" r="54" fill={`url(#aura-${id})`} />

      {/* Hexagonal shield frame — security / vault motif */}
      <path d="M60 18 L92 36 L92 72 L60 90 L28 72 L28 36 Z"
            stroke={primary} strokeOpacity="0.35" strokeWidth="1" fill="none" />
      <path d="M60 24 L87 39 L87 69 L60 84 L33 69 L33 39 Z"
            stroke={primary} strokeOpacity="0.18" strokeWidth="0.7" fill="none" />

      {/* Wings — precise, mirrored, geometric; like stealth-fighter silhouettes */}
      <path d="M60 54 L28 42 Q24 46 26 54 Q30 62 60 62 Z"
            fill={`url(#wing-${id})`} opacity="0.85" />
      <path d="M60 54 L92 42 Q96 46 94 54 Q90 62 60 62 Z"
            fill={`url(#wing-${id})`} opacity="0.85" />
      <path d="M60 62 L32 74 Q30 78 36 80 Q50 78 60 68 Z"
            fill={`url(#wing-${id})`} opacity="0.55" />
      <path d="M60 62 L88 74 Q90 78 84 80 Q70 78 60 68 Z"
            fill={`url(#wing-${id})`} opacity="0.55" />

      {/* Body — simple capsule silhouette, no face */}
      <path d="M60 44 Q66 44 66 54 L66 68 Q66 76 60 78 Q54 76 54 68 L54 54 Q54 44 60 44 Z"
            fill={fg} opacity="0.92" />

      {/* Head — just a clean dot, no face */}
      <circle cx="60" cy="42" r="6" fill={fg} opacity="0.92" />

      {/* Wand — thin line with sparkle */}
      <line x1="52" y1="70" x2="34" y2="88" stroke={accent} strokeWidth="1.5" strokeLinecap="round" />
      <g transform="translate(32 88)">
        <path d="M0 -5 L1 -1 L5 0 L1 1 L0 5 L-1 1 L-5 0 L-1 -1 Z" fill={accent} />
      </g>

      {/* Sparkle ✦ drifting */}
      <text x="86" y="34" fontSize="10" fill={accent} opacity="0.9" fontFamily="monospace">✦</text>
      <text x="26" y="30" fontSize="6" fill={accent} opacity="0.55" fontFamily="monospace">✦</text>
    </svg>
  );
}

function FairyAurora({ size = 120, tone = 'dark', tokens }) {
  const { primary, accent, aura } = tokens;
  // Dark mode: soft pale body. Light mode: ethereal light body (not navy — avoid creepy).
  const fg = tone === 'dark' ? '#e2e8f0' : '#f4f7ff';
  const bodyStroke = tone === 'dark' ? 'rgba(15,23,42,0.35)' : 'rgba(30,27,75,0.45)'; // gentle outline so light body has definition
  const ink = tone === 'dark' ? '#0a0e1a' : '#1e1b4b'; // face ink — dark in both modes for legibility
  const id = React.useId().replace(/[:]/g, '');
  return (
    <svg width={size} height={size} viewBox="0 0 120 120" fill="none" style={{display:'block'}}>
      <defs>
        <radialGradient id={`aura-${id}`} cx="50%" cy="55%" r="55%">
          <stop offset="0%" stopColor={aura} stopOpacity="0.55" />
          <stop offset="55%" stopColor={primary} stopOpacity="0.15" />
          <stop offset="100%" stopColor={primary} stopOpacity="0" />
        </radialGradient>
        <linearGradient id={`wingL-${id}`} x1="1" y1="0" x2="0" y2="1">
          <stop offset="0%" stopColor={primary} stopOpacity="0.85" />
          <stop offset="100%" stopColor={accent} stopOpacity="0.3" />
        </linearGradient>
        <linearGradient id={`wingR-${id}`} x1="0" y1="0" x2="1" y2="1">
          <stop offset="0%" stopColor={primary} stopOpacity="0.85" />
          <stop offset="100%" stopColor={accent} stopOpacity="0.3" />
        </linearGradient>
      </defs>

      {/* Ethereal aura */}
      <circle cx="60" cy="62" r="56" fill={`url(#aura-${id})`} />

      {/* Wings — organic, dragonfly-ish, overlapping teardrops */}
      <path d="M55 50 Q20 30 14 54 Q16 72 44 66 Q52 62 55 52 Z"
            fill={`url(#wingL-${id})`} stroke={primary} strokeWidth="0.6" strokeOpacity="0.5" />
      <path d="M65 50 Q100 30 106 54 Q104 72 76 66 Q68 62 65 52 Z"
            fill={`url(#wingR-${id})`} stroke={primary} strokeWidth="0.6" strokeOpacity="0.5" />
      {/* Lower wings, smaller */}
      <path d="M56 64 Q34 72 32 86 Q40 92 54 82 Q58 76 56 66 Z"
            fill={`url(#wingL-${id})`} opacity="0.6" />
      <path d="M64 64 Q86 72 88 86 Q80 92 66 82 Q62 76 64 66 Z"
            fill={`url(#wingR-${id})`} opacity="0.6" />

      {/* Wing veins — linework detail */}
      <path d="M55 52 Q40 48 22 54" stroke={fg} strokeOpacity="0.25" strokeWidth="0.5" fill="none" />
      <path d="M55 54 Q42 56 28 66" stroke={fg} strokeOpacity="0.2" strokeWidth="0.5" fill="none" />
      <path d="M65 52 Q80 48 98 54" stroke={fg} strokeOpacity="0.25" strokeWidth="0.5" fill="none" />
      <path d="M65 54 Q78 56 92 66" stroke={fg} strokeOpacity="0.2" strokeWidth="0.5" fill="none" />

      {/* Wing ocelli — tiny highlight dots like dragonfly eyespots */}
      <circle cx="28" cy="52" r="1.1" fill={accent} opacity="0.75" />
      <circle cx="92" cy="52" r="1.1" fill={accent} opacity="0.75" />
      <circle cx="42" cy="82" r="0.8" fill={accent} opacity="0.55" />
      <circle cx="78" cy="82" r="0.8" fill={accent} opacity="0.55" />

      {/* Hair — delicate trailing strands behind head */}
      <path d="M54 36 Q50 32 48 28" stroke={primary} strokeWidth="0.8" strokeLinecap="round" fill="none" opacity="0.65" />
      <path d="M66 36 Q70 32 72 28" stroke={primary} strokeWidth="0.8" strokeLinecap="round" fill="none" opacity="0.65" />
      <path d="M52 38 Q47 36 43 34" stroke={accent} strokeWidth="0.6" strokeLinecap="round" fill="none" opacity="0.5" />
      <path d="M68 38 Q73 36 77 34" stroke={accent} strokeWidth="0.6" strokeLinecap="round" fill="none" opacity="0.5" />

      {/* Body — elongated with a soft waist indent for silhouette */}
      <path d="M60 44 Q64.5 46 64.5 54 Q63 58 63 62 Q64.5 66 64 74 Q62 80 60 82 Q58 80 56 74 Q55.5 66 57 62 Q57 58 55.5 54 Q55.5 46 60 44 Z"
            fill={fg} opacity="0.92" stroke={bodyStroke} strokeWidth="0.4" />
      {/* Subtle body highlight — light catches the left */}
      <path d="M58 48 Q57 54 58 62 Q57.5 68 58 76" stroke={primary} strokeWidth="0.5" strokeLinecap="round" fill="none" opacity="0.4" />

      {/* Head — larger to carry a face */}
      <ellipse cx="60" cy="39" rx="7.5" ry="8.5" fill={fg} opacity="0.97" stroke={bodyStroke} strokeWidth="0.4" />
      {/* Tiny crown/halo — thin arc above head */}
      <path d="M54 32.5 Q60 29.5 66 32.5" stroke={accent} strokeWidth="0.7" strokeLinecap="round" fill="none" opacity="0.75" />
      <circle cx="60" cy="30" r="0.9" fill={accent} opacity="0.9" />

      {/* Face — dignified: soft dot eyes + serene smile */}
      {size >= 90 && (
        <g>
          <circle cx="57" cy="38" r="1.15" fill={ink} opacity="0.92" />
          <circle cx="63" cy="38" r="1.15" fill={ink} opacity="0.92" />
          {/* tiny eye-catch highlight — only on dark */}
          <circle cx="57.3" cy="37.7" r="0.3" fill="#fff" opacity={tone === 'dark' ? 0.8 : 0} />
          <circle cx="63.3" cy="37.7" r="0.3" fill="#fff" opacity={tone === 'dark' ? 0.8 : 0} />
          {/* serene smile curve */}
          <path d="M57.3 42.2 Q60 43.7 62.7 42.2" stroke={ink} strokeWidth="0.7" strokeLinecap="round" fill="none" opacity="0.85" />
        </g>
      )}

      {/* Wand — angled, ornate */}
      <line x1="55" y1="70" x2="36" y2="90" stroke={accent} strokeWidth="1.3" strokeLinecap="round" />
      {/* Wand mid-band detail */}
      <circle cx="45.5" cy="80" r="0.8" fill={accent} opacity="0.8" />
      <circle cx="36" cy="90" r="2.8" fill={accent} />
      <circle cx="36" cy="90" r="5" fill={accent} opacity="0.25" />
      {/* Tiny 4-point sparkle on wand tip */}
      <path d="M36 85 L36 95 M31 90 L41 90" stroke={accent} strokeWidth="0.4" opacity="0.6" strokeLinecap="round" />

      {/* Trailing sparkles */}
      <text x="92" y="38" fontSize="11" fill={accent} opacity="0.85" fontFamily="monospace">✦</text>
      <text x="20" y="50" fontSize="7" fill={accent} opacity="0.6" fontFamily="monospace">✦</text>
      <text x="100" y="74" fontSize="5" fill={accent} opacity="0.45" fontFamily="monospace">✦</text>
      <text x="14" y="86" fontSize="5" fill={primary} opacity="0.5" fontFamily="monospace">·</text>
      <text x="104" y="96" fontSize="6" fill={primary} opacity="0.45" fontFamily="monospace">✦</text>
    </svg>
  );
}

function FairySignal({ size = 120, tone = 'dark', tokens }) {
  // "Atelier" — Fee als Glühwürmchen über nächtlicher Werkstatt.
  // Soft-glow body, feine Linien-Flügel, kein Kompass, kein Siegel.
  // Das Werkzeug ist ein feiner Stift/Feder statt Schwert-Wand.
  const { primary, accent, aura } = tokens;
  const fg = tone === 'dark' ? '#fdf6e3' : '#1f1406';
  const id = React.useId().replace(/[:]/g, '');
  return (
    <svg width={size} height={size} viewBox="0 0 120 120" fill="none" style={{display:'block'}}>
      <defs>
        <radialGradient id={`aura-${id}`} cx="50%" cy="50%" r="50%">
          <stop offset="0%" stopColor={aura} stopOpacity="0.65" />
          <stop offset="40%" stopColor={aura} stopOpacity="0.2" />
          <stop offset="100%" stopColor={aura} stopOpacity="0" />
        </radialGradient>
        <radialGradient id={`body-${id}`} cx="50%" cy="45%" r="55%">
          <stop offset="0%" stopColor={accent} stopOpacity="1" />
          <stop offset="60%" stopColor={accent} stopOpacity="0.7" />
          <stop offset="100%" stopColor={primary} stopOpacity="0.4" />
        </radialGradient>
        <linearGradient id={`wing-${id}`} x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stopColor={primary} stopOpacity="0.5" />
          <stop offset="100%" stopColor={accent} stopOpacity="0.15" />
        </linearGradient>
      </defs>

      {/* Warm aura — firefly glow */}
      <circle cx="60" cy="58" r="52" fill={`url(#aura-${id})`} />

      {/* Delicate wings — dragonfly veins, transparent */}
      <g opacity="0.85">
        {/* Upper-left wing */}
        <path d="M56 48 Q38 30 22 42 Q18 52 28 58 Q42 60 55 54 Z"
              fill={`url(#wing-${id})`} stroke={primary} strokeWidth="0.5" strokeOpacity="0.7" />
        <path d="M55 52 Q42 46 28 50" stroke={primary} strokeWidth="0.4" strokeOpacity="0.5" fill="none" />
        <path d="M54 54 Q44 54 32 58" stroke={primary} strokeWidth="0.4" strokeOpacity="0.4" fill="none" />

        {/* Upper-right wing */}
        <path d="M64 48 Q82 30 98 42 Q102 52 92 58 Q78 60 65 54 Z"
              fill={`url(#wing-${id})`} stroke={primary} strokeWidth="0.5" strokeOpacity="0.7" />
        <path d="M65 52 Q78 46 92 50" stroke={primary} strokeWidth="0.4" strokeOpacity="0.5" fill="none" />
        <path d="M66 54 Q76 54 88 58" stroke={primary} strokeWidth="0.4" strokeOpacity="0.4" fill="none" />

        {/* Lower wings, smaller */}
        <path d="M58 60 Q46 68 40 80 Q48 82 56 72 Z"
              fill={`url(#wing-${id})`} opacity="0.7" />
        <path d="M62 60 Q74 68 80 80 Q72 82 64 72 Z"
              fill={`url(#wing-${id})`} opacity="0.7" />
      </g>

      {/* Soft glowing body — like a firefly */}
      <ellipse cx="60" cy="58" rx="5.5" ry="11" fill={`url(#body-${id})`} />
      <ellipse cx="60" cy="58" rx="4" ry="9" fill={accent} opacity="0.8" />
      {/* Head — soft, warm */}
      <circle cx="60" cy="46" r="4.5" fill={`url(#body-${id})`} />
      <circle cx="60" cy="46" r="3" fill={accent} opacity="0.9" />
      {/* Face — tiny bright dots on the glow, like a firefly's eyes reflecting a screen */}
      {size >= 90 && (
        <g>
          <circle cx="58.5" cy="45.5" r="0.7" fill={tone === 'dark' ? '#0a0a1f' : '#1e1b4b'} />
          <circle cx="61.5" cy="45.5" r="0.7" fill={tone === 'dark' ? '#0a0a1f' : '#1e1b4b'} />
          {/* tiny mouth dot — pixel-console wink */}
          <rect x="59.6" y="47.4" width="0.8" height="0.5" fill={tone === 'dark' ? '#0a0a1f' : '#1e1b4b'} opacity="0.6" />
        </g>
      )}

      {/* Feder / Stift — the craftswoman's tool, angled like she's writing */}
      <line x1="56" y1="64" x2="38" y2="82" stroke={primary} strokeWidth="1.3" strokeLinecap="round" opacity="0.85" />
      {/* Feather tip — split nib */}
      <path d="M38 82 L36 84 L34 86 L36 87 L38 85 Z" fill={primary} opacity="0.9" />
      {/* Ink dot */}
      <circle cx="34" cy="87" r="1.2" fill={accent} />

      {/* Drifting sparkles — like pollen */}
      <text x="84" y="30" fontSize="9" fill={accent} opacity="0.85" fontFamily="monospace">✦</text>
      <text x="22" y="32" fontSize="6" fill={primary} opacity="0.55" fontFamily="monospace">✦</text>
      <text x="96" y="76" fontSize="7" fill={accent} opacity="0.6" fontFamily="monospace">✦</text>
    </svg>
  );
}

Object.assign(window, { FairyVault, FairyAurora, FairySignal });
