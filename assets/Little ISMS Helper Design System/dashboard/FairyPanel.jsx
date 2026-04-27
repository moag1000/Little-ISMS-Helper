/* global React, T, FONT_SANS, FONT_MONO, Icon, FairyAurora */

// Fee-Assistant-Panel: sitzt rechts, beobachtet Kontext, gibt Vorschläge, Chat unten
function FairyPanel() {
  const observations = [
    { tag: 'BEOBACHTUNG', title: '3 Controls warten > 7 Tage auf Review', body: 'A.8.12, A.8.16, A.14.2 — alle in deinem Verantwortungsbereich.', action: 'Jetzt sichten' },
    { tag: 'KONTEXT',     title: 'Audit in 18 Tagen', body: 'Vorbereitung startet normalerweise 4 Wochen vorher. Soll ich einen Plan vorschlagen?', action: 'Plan ansehen' },
    { tag: 'HINWEIS',     title: '2 Richtlinien laufen in 30 Tagen ab', body: 'Zugriffsrichtlinie v3 · BYOD v2. Beide haben unveränderte Inhalte.', action: 'Verlängern' },
  ];

  const history = [
    { time:'08:14', text:'NIS-2 Scan ausgewertet · 3 Befunde' },
    { time:'07:02', text:'Controls A.5.x auf Policy-Updates geprüft' },
    { time:'gestern',text:'Finding F-2025-0082 einem Owner zugewiesen' },
  ];

  return (
    <aside style={{
      width: 340, flexShrink: 0,
      background: T.surface, borderLeft: `1px solid ${T.border}`,
      display:'flex', flexDirection:'column', overflow:'hidden',
    }}>
      {/* Header with fairy */}
      <div style={{
        padding: '16px 18px 12px', borderBottom: `1px solid ${T.border}`,
        display:'flex', alignItems:'center', gap: 12, position:'relative',
        background: `linear-gradient(180deg, ${T.surface2}, ${T.surface})`,
      }}>
        {/* tiny tech backdrop */}
        <svg style={{position:'absolute', inset: 0, pointerEvents:'none', opacity: 0.5}} width="100%" height="100%" viewBox="0 0 340 80" preserveAspectRatio="none">
          <defs>
            <pattern id="fp-grid" width="18" height="18" patternUnits="userSpaceOnUse">
              <path d="M 18 0 L 0 0 0 18" fill="none" stroke={T.fg} strokeWidth="0.4" opacity="0.06"/>
            </pattern>
          </defs>
          <rect width="100%" height="100%" fill="url(#fp-grid)" />
          <path d="M 0 40 L 80 40 L 95 25 L 180 25" stroke={T.primary} strokeWidth="0.5" fill="none" opacity="0.25" />
          <circle cx="80" cy="40" r="1.3" fill={T.accent} opacity="0.5" />
          <circle cx="180" cy="25" r="1.3" fill={T.accent} opacity="0.5" />
        </svg>
        <div style={{filter:`drop-shadow(0 0 10px ${T.fairyTokens.aura}55)`, position:'relative'}}>
          <FairyAurora size={54} tone="dark" tokens={T.fairyTokens} />
        </div>
        <div style={{flex: 1, position:'relative'}}>
          <div style={{display:'flex', alignItems:'center', gap: 6}}>
            <span style={{color: T.fg, fontSize: 14, fontWeight: 600, fontFamily: FONT_SANS}}>Alva</span>
            <span style={{
              fontFamily: FONT_MONO, fontSize: 9, letterSpacing:'0.1em', textTransform:'uppercase',
              color: T.success, padding: '1px 6px',
              background: `${T.success}18`, border: `1px solid ${T.success}30`, borderRadius: 3,
            }}>Aktiv</span>
          </div>
          <div style={{color: T.fg3, fontSize: 11, fontFamily: FONT_MONO, letterSpacing: '0.04em', marginTop: 2}}>
            14 Domänen im Blick · seit 127 Tagen dabei
          </div>
        </div>
      </div>

      {/* Observations */}
      <div style={{flex: 1, overflowY:'auto', padding: '14px 16px', display:'flex', flexDirection:'column', gap: 10}}>
        <div style={{fontFamily: FONT_MONO, fontSize: 10, letterSpacing:'0.1em', textTransform:'uppercase', color: T.fg3}}>
          Was ich gerade sehe
        </div>
        {observations.map((o, i) => (
          <div key={i} style={{
            background: T.bg, border: `1px solid ${T.border}`, borderRadius: 6,
            padding: 12, position:'relative', overflow:'hidden',
          }}>
            {/* subtle left accent */}
            <div style={{
              position:'absolute', left: 0, top: 10, bottom: 10, width: 2,
              background: i === 0 ? T.warning : i === 1 ? T.primary : T.accent,
              boxShadow: `0 0 6px ${i === 0 ? T.warning : i === 1 ? T.primary : T.accent}`,
              borderRadius: 2,
            }} />
            <div style={{fontFamily: FONT_MONO, fontSize: 9, letterSpacing:'0.12em', color: i === 0 ? T.warning : i === 1 ? T.primary : T.accent, marginBottom: 4}}>{o.tag}</div>
            <div style={{color: T.fg, fontSize: 12.5, fontWeight: 500, fontFamily: FONT_SANS, lineHeight: 1.35, marginBottom: 4}}>{o.title}</div>
            <div style={{color: T.fg2, fontSize: 11, fontFamily: FONT_SANS, lineHeight: 1.45, marginBottom: 10}}>{o.body}</div>
            <button style={{
              padding: '5px 10px', fontFamily: FONT_MONO, fontSize: 10, letterSpacing:'0.08em', textTransform:'uppercase',
              background: 'transparent', border: `1px solid ${T.accent}`, color: T.accent,
              cursor:'pointer', fontWeight: 600,
              clipPath: 'polygon(0 0, calc(100% - 6px) 0, 100% 6px, 100% 100%, 6px 100%, 0 calc(100% - 6px))',
              display:'inline-flex', alignItems:'center', gap: 5,
            }}>
              <span>✦</span>
              {o.action}
            </button>
          </div>
        ))}

        {/* History */}
        <div style={{fontFamily: FONT_MONO, fontSize: 10, letterSpacing:'0.1em', textTransform:'uppercase', color: T.fg3, marginTop: 8}}>
          Heute erledigt
        </div>
        <div style={{display:'flex', flexDirection:'column', gap: 2, fontFamily: FONT_SANS, fontSize: 11}}>
          {history.map((h, i) => (
            <div key={i} style={{display:'grid', gridTemplateColumns:'60px 1fr', gap: 8, padding: '4px 0', color: T.fg2}}>
              <span style={{fontFamily: FONT_MONO, fontSize: 10, color: T.fg3}}>{h.time}</span>
              <span>{h.text}</span>
            </div>
          ))}
        </div>
      </div>

      {/* Status footer — kein Chat, Alva ist Beobachterin, keine Dialog-Partnerin */}
      <div style={{
        padding: '10px 14px', borderTop: `1px solid ${T.border}`, background: T.surface2,
        display:'flex', alignItems:'center', gap: 10,
      }}>
        <div style={{
          width: 6, height: 6, borderRadius: '50%',
          background: T.success, boxShadow: `0 0 8px ${T.success}`,
          animation: 'alva-breathe 2.4s ease-in-out infinite',
        }}/>
        <div style={{flex: 1, display:'flex', flexDirection:'column'}}>
          <span style={{fontFamily: FONT_MONO, fontSize: 9.5, letterSpacing:'0.1em', textTransform:'uppercase', color: T.fg3}}>Nächste Prüfung</span>
          <span style={{color: T.fg2, fontSize: 11, fontFamily: FONT_SANS, marginTop: 1}}>in 4 min · alle 15 min</span>
        </div>
        <button style={{
          padding:'4px 8px', fontFamily: FONT_MONO, fontSize: 9.5, letterSpacing:'0.08em', textTransform:'uppercase',
          background:'transparent', border: `1px solid ${T.border}`, color: T.fg3, borderRadius: 3, cursor:'pointer',
        }}>Pausieren</button>
        <style>{`@keyframes alva-breathe { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }`}</style>
      </div>
    </aside>
  );
}

Object.assign(window, { FairyPanel });
