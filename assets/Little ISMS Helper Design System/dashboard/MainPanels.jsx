/* global React, T, FONT_SANS, FONT_MONO, Icon */

// -------------- CONTROL HEATMAP --------------
// 14 ISO 27001 Annex A domains × maturity grid
const DOMAINS = [
  { id: 'A.5',  name: 'Organisationale Maßnahmen',   controls: 37, state: 'good' },
  { id: 'A.6',  name: 'Personalbezogene',             controls: 8,  state: 'good' },
  { id: 'A.7',  name: 'Physische',                    controls: 14, state: 'mixed' },
  { id: 'A.8',  name: 'Technologische',               controls: 34, state: 'gap' },
  { id: 'A.9',  name: 'Zugriffskontrolle',            controls: 14, state: 'good' },
  { id: 'A.10', name: 'Kryptographie',                controls: 2,  state: 'good' },
  { id: 'A.12', name: 'Betriebssicherheit',           controls: 14, state: 'mixed' },
  { id: 'A.13', name: 'Kommunikationssicherheit',     controls: 7,  state: 'good' },
  { id: 'A.14', name: 'Systementwicklung',            controls: 13, state: 'gap' },
  { id: 'A.15', name: 'Lieferantenbeziehungen',       controls: 5,  state: 'mixed' },
  { id: 'A.16', name: 'Incident-Management',          controls: 7,  state: 'good' },
  { id: 'A.17', name: 'Business Continuity',          controls: 4,  state: 'mixed' },
  { id: 'A.18', name: 'Compliance',                   controls: 8,  state: 'good' },
  { id: 'NIS',  name: 'NIS-2 Ergänzungen',            controls: 12, state: 'gap' },
];

const STATE_COLOR = {
  good:  T.success,
  mixed: T.warning,
  gap:   T.danger,
};

function ControlHeatmap() {
  return (
    <div style={{
      background: T.surface, border: `1px solid ${T.border}`, borderRadius: 8,
      padding: 16, display:'flex', flexDirection:'column', gap: 12,
    }}>
      <div style={{display:'flex', alignItems:'center', justifyContent:'space-between'}}>
        <div>
          <div style={{color: T.fg, fontSize: 13, fontWeight: 600, fontFamily: FONT_SANS}}>Control-Heatmap</div>
          <div style={{color: T.fg3, fontSize: 11, fontFamily: FONT_MONO, letterSpacing:'0.04em'}}>ISO 27001:2022 · Annex A</div>
        </div>
        <div style={{display:'flex', gap: 12, fontSize: 10, fontFamily: FONT_MONO, color: T.fg3, letterSpacing:'0.04em'}}>
          {[['good','Konform'],['mixed','Teilweise'],['gap','Lücke']].map(([s, l]) => (
            <span key={s} style={{display:'flex', alignItems:'center', gap: 4}}>
              <span style={{width: 8, height: 8, borderRadius: 2, background: STATE_COLOR[s]}} />
              {l}
            </span>
          ))}
        </div>
      </div>

      <div style={{display:'grid', gridTemplateColumns:'repeat(7, 1fr)', gap: 6}}>
        {DOMAINS.map(d => (
          <div key={d.id} style={{
            position:'relative',
            background: T.bg, border: `1px solid ${T.border}`, borderRadius: 5,
            padding: 10, minHeight: 70,
            display:'flex', flexDirection:'column', justifyContent:'space-between',
            overflow:'hidden',
          }}>
            <div style={{
              position:'absolute', top: 0, left: 0, bottom: 0, width: 2,
              background: STATE_COLOR[d.state],
              boxShadow: `0 0 8px ${STATE_COLOR[d.state]}`,
            }} />
            <div>
              <div style={{color: T.fg3, fontSize: 9, fontFamily: FONT_MONO, letterSpacing:'0.08em'}}>{d.id}</div>
              <div style={{color: T.fg, fontSize: 11, fontFamily: FONT_SANS, marginTop: 2, lineHeight: 1.25}}>{d.name}</div>
            </div>
            <div style={{display:'flex', alignItems:'baseline', gap: 3}}>
              <span style={{color: STATE_COLOR[d.state], fontSize: 15, fontWeight: 600, fontFamily: FONT_SANS}}>{d.controls}</span>
              <span style={{color: T.fg3, fontSize: 9, fontFamily: FONT_MONO}}>Controls</span>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

// -------------- ACTIVITY FEED --------------
const ACTIVITIES = [
  { time:'vor 12 Min',  user:'MS', action:'hat Richtlinie',  target:'IT-Sicherheit v4.2', suffix:'freigegeben',  tone:'success', icon:'check' },
  { time:'vor 34 Min',  user:'TF', action:'hat Asset',       target:'DC-EU-West-01',      suffix:'inventarisiert', tone:'neutral', icon:'plus' },
  { time:'vor 1 Std.',  user:'AM', action:'hat Finding',     target:'F-2025-0088',        suffix:'als kritisch markiert', tone:'warning', icon:'warn' },
  { time:'vor 2 Std.',  user:'✦',  action:'hat',             target:'6 Controls',         suffix:'dokumentiert', tone:'fairy', icon:'sparkle' },
  { time:'vor 4 Std.',  user:'JW', action:'hat',             target:'Phishing-Test Q2',   suffix:'gestartet · 142 MA', tone:'neutral', icon:'training' },
  { time:'heute 08:14', user:'SYS',action:'',                target:'NIS-2 Scan',         suffix:'abgeschlossen · 3 Ergebnisse', tone:'neutral', icon:'shield' },
];

function ActivityFeed() {
  return (
    <div style={{
      background: T.surface, border: `1px solid ${T.border}`, borderRadius: 8,
      padding: 16, display:'flex', flexDirection:'column', gap: 12,
    }}>
      <div style={{display:'flex', alignItems:'center', justifyContent:'space-between'}}>
        <div>
          <div style={{color: T.fg, fontSize: 13, fontWeight: 600, fontFamily: FONT_SANS}}>Aktivität</div>
          <div style={{color: T.fg3, fontSize: 11, fontFamily: FONT_MONO}}>Letzte 24 h · Audit-Log</div>
        </div>
        <a href="#" style={{color: T.primary, fontSize: 11, fontFamily: FONT_MONO, letterSpacing:'0.06em', textTransform:'uppercase', textDecoration:'none'}}>Alle anzeigen →</a>
      </div>
      <div style={{display:'flex', flexDirection:'column'}}>
        {ACTIVITIES.map((a, i) => {
          const toneColor = a.tone === 'success' ? T.success : a.tone === 'warning' ? T.warning : a.tone === 'fairy' ? T.accent : T.fg3;
          return (
            <div key={i} style={{
              display:'grid', gridTemplateColumns:'24px 24px 1fr auto', gap: 10, alignItems:'center',
              padding: '8px 0', borderTop: i === 0 ? 'none' : `1px solid ${T.border}`,
            }}>
              <div style={{
                width: 22, height: 22, borderRadius: 4, background: `${toneColor}18`,
                display:'flex', alignItems:'center', justifyContent:'center', color: toneColor,
                border: `1px solid ${toneColor}30`,
              }}>
                <Icon name={a.icon} size={11} />
              </div>
              <div style={{
                width: 22, height: 22, borderRadius: '50%',
                background: a.user === '✦' ? `linear-gradient(135deg, ${T.primary}, ${T.accent})` : T.surface3,
                display:'flex', alignItems:'center', justifyContent:'center',
                color: a.user === '✦' ? '#04121a' : T.fg2, fontSize: 9, fontWeight: 700,
                fontFamily: FONT_MONO,
              }}>{a.user}</div>
              <div style={{fontSize: 12, color: T.fg2, fontFamily: FONT_SANS, lineHeight: 1.4}}>
                {a.action && `${a.action} `}
                <span style={{color: T.fg, fontWeight: 500}}>{a.target}</span>
                {a.suffix && ` ${a.suffix}`}
              </div>
              <div style={{fontSize: 10, color: T.fg3, fontFamily: FONT_MONO}}>{a.time}</div>
            </div>
          );
        })}
      </div>
    </div>
  );
}

// -------------- TASK QUEUE --------------
function TaskQueue() {
  const tasks = [
    { title: 'Review: Zugriffsrechte Finance-Team',  due: 'Heute',     priority: 'high',   owner: 'AM' },
    { title: 'BCM-Test Q2 dokumentieren',            due: 'Morgen',    priority: 'mid',    owner: 'TF' },
    { title: 'Lieferanten-Assessment: Acme Cloud',   due: 'in 3 Tagen', priority: 'mid',   owner: 'AM' },
    { title: 'Schulung Phishing · 12 Nachzügler',    due: 'in 5 Tagen', priority: 'low',   owner: 'JW' },
  ];
  return (
    <div style={{
      background: T.surface, border: `1px solid ${T.border}`, borderRadius: 8,
      padding: 16, display:'flex', flexDirection:'column', gap: 10,
    }}>
      <div style={{display:'flex', alignItems:'center', justifyContent:'space-between'}}>
        <div>
          <div style={{color: T.fg, fontSize: 13, fontWeight: 600, fontFamily: FONT_SANS}}>Deine Aufgaben</div>
          <div style={{color: T.fg3, fontSize: 11, fontFamily: FONT_MONO}}>4 offen · 1 fällig heute</div>
        </div>
      </div>
      {tasks.map((t, i) => {
        const pColor = t.priority === 'high' ? T.danger : t.priority === 'mid' ? T.warning : T.fg3;
        return (
          <div key={i} style={{
            display:'flex', alignItems:'center', gap: 10,
            padding: '8px 10px', background: T.bg, borderRadius: 5,
            border: `1px solid ${T.border}`,
          }}>
            <span style={{width: 6, height: 6, borderRadius: '50%', background: pColor, boxShadow: `0 0 4px ${pColor}`, flexShrink: 0}} />
            <div style={{flex: 1, minWidth: 0}}>
              <div style={{color: T.fg, fontSize: 12, fontFamily: FONT_SANS, fontWeight: 500, overflow:'hidden', textOverflow:'ellipsis', whiteSpace:'nowrap'}}>{t.title}</div>
              <div style={{color: T.fg3, fontSize: 10, fontFamily: FONT_MONO, marginTop: 1}}>{t.due}</div>
            </div>
            <div style={{
              width: 20, height: 20, borderRadius: '50%',
              background: T.surface3, color: T.fg2, fontSize: 9, fontWeight: 600, fontFamily: FONT_MONO,
              display:'flex', alignItems:'center', justifyContent:'center',
            }}>{t.owner}</div>
          </div>
        );
      })}
    </div>
  );
}

Object.assign(window, { ControlHeatmap, ActivityFeed, TaskQueue });
