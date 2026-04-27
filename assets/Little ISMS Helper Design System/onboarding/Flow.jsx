/* global React, ReactDOM, T, FONT_SANS, FONT_MONO, Icon, FairyAurora, TechBackdrop, Brand, CyberButton, CyberInput,
   Step0Welcome, Step1Requirements, Step2Database, Step3Backup, Step4AdminUser, Step5Email,
   Step6Organisation, Step7Modules, Step8Frameworks, Step9MasterData, Step10DemoData, Step11Done */

// Fairy + line per step
const FLOW = [
  { id:'welcome',  kind:'welcome',     phase:'', mood:'happy',   line:"Hi, ich bin Alva.",                       sub:"Deine Begleiterin durch Alvara. Wir richten das System jetzt ein." },
  { id:'check',    kind:'requirements',phase:'Technik', mood:'scan',    line:"Ich mess' mal deinen Server aus.", sub:"PHP, Datenbank, Schreibrechte — das Übliche. Dauert 30 Sekunden." },
  { id:'db',       kind:'database',    phase:'Technik', mood:'focus',   line:"Sag mir, wo ich schreiben darf.",  sub:"Eine dedizierte DB. Ich lege das Schema selbst an." },
  { id:'backup',   kind:'backup',      phase:'Technik', mood:'idle',    line:"Hast du ein Altsystem?",           sub:"Wenn ja, spielen wir's jetzt ein. Wenn nicht, weiter." },
  { id:'admin',    kind:'admin',       phase:'Technik', mood:'focus',   line:"Wer führt hier das Kommando?",     sub:"Der erste Admin hat volle Rechte. Wähl weise." },
  { id:'mail',     kind:'email',       phase:'Technik', mood:'focus',   line:"Wie erreich' ich dich?",           sub:"SMTP für Erinnerungen, Resets, Reports. Test-Mail inklusive." },
  { id:'org',      kind:'organisation',phase:'Inhalt',  mood:'happy',   line:"Jetzt zu dir.",                    sub:"Basisdaten deiner Organisation — prägt den Scope." },
  { id:'modules',  kind:'modules',     phase:'Inhalt',  mood:'focus',   line:"Was willst du verwalten?",         sub:"Core-Module sind dabei, der Rest ist deine Wahl." },
  { id:'frmwrk',   kind:'frameworks',  phase:'Inhalt',  mood:'working', line:"Welchen Standards folgst du?",     sub:"Ich lade die Controls und verknüpfe alles quer." },
  { id:'master',   kind:'masterdata',  phase:'Inhalt',  mood:'focus',   line:"Standorte, Abteilungen.",          sub:"Nur die wichtigsten. Der Rest kommt später." },
  { id:'demo',     kind:'demo',        phase:'Inhalt',  mood:'idle',    line:"Beispieldaten zum Ausprobieren?",   sub:"Jederzeit löschbar. Gut für Schulungen." },
  { id:'done',     kind:'done',        phase:'',        mood:'happy',   line:"Fertig. Das war's.",               sub:"Dein ISMS ist einsatzbereit. Komm mit aufs Dashboard." },
];

// ===== Fairy =====
function OnboardingFairy({ mood }) {
  const wingTilt = { idle: 0, happy: 4, focus: -2, working: 6, scan: 0 }[mood] || 0;
  const glow = { idle: T.primaryGlow, happy: T.accentGlow, focus: T.primaryGlow, working: T.accentGlow, scan: T.accentGlow }[mood];
  return (
    <div style={{position:'relative', width: 220, height: 220, display:'flex', alignItems:'center', justifyContent:'center'}}>
      <div style={{position:'absolute', inset: 36, background: `radial-gradient(circle, ${glow} 0%, transparent 70%)`, filter:'blur(8px)', animation:'fairy-pulse 3.5s ease-in-out infinite'}}/>
      <svg style={{position:'absolute', inset: 0, animation:'fairy-orbit 18s linear infinite'}} width="220" height="220" viewBox="0 0 220 220">
        <circle cx="110" cy="18" r="2" fill={T.accent} opacity="0.8"/>
        <circle cx="202" cy="110" r="1.5" fill={T.primary} opacity="0.7"/>
        <circle cx="110" cy="202" r="1.2" fill={T.accent} opacity="0.5"/>
        <circle cx="18"  cy="110" r="1.8" fill={T.primary} opacity="0.6"/>
      </svg>
      <div style={{transform:`rotate(${wingTilt}deg)`, transition:'transform .6s'}}>
        <FairyAurora size={150} tokens={T.fairyTokens} tone="dark" />
      </div>
      <style>{`
        @keyframes fairy-pulse { 0%, 100% { opacity: 0.55; transform: scale(1); } 50% { opacity: 0.9; transform: scale(1.1); } }
        @keyframes fairy-orbit { to { transform: rotate(360deg); } }
        @keyframes caret { 50% { opacity: 0; } }
        @keyframes log-in { from { opacity: 0; transform: translateX(-4px); } to { opacity: 1; transform: translateX(0); } }
        @keyframes scan-line { 0% { top: 0; } 100% { top: 100%; } }
      `}</style>
    </div>
  );
}

function Typewriter({ text, speed = 22, style }) {
  const [shown, setShown] = React.useState('');
  React.useEffect(() => {
    setShown(''); let i = 0;
    const iv = setInterval(() => { i += 1; setShown(text.slice(0, i)); if (i >= text.length) clearInterval(iv); }, speed);
    return () => clearInterval(iv);
  }, [text, speed]);
  const done = shown.length >= text.length;
  return <span style={style}>{shown}{!done && <span style={{animation:'caret 0.8s infinite', color: T.accent}}>▍</span>}</span>;
}

// ===== Left panel =====
function LeftPanel({ step, fairy }) {
  const phaseMap = { 'Technik': [1,5], 'Inhalt': [6,10] };
  return (
    <aside style={{
      width: 480, flexShrink: 0, position:'relative',
      background: `linear-gradient(180deg, ${T.surface} 0%, ${T.bg} 100%)`,
      borderRight: `1px solid ${T.border}`,
      display:'flex', flexDirection:'column', padding: 36,
      overflow:'hidden',
    }}>
      <TechBackdrop intensity={0.9}/>
      <span style={{position:'absolute', top: 16, left: 16, width: 12, height: 12, borderTop:`1px solid ${T.primary}`, borderLeft:`1px solid ${T.primary}`}}/>
      <span style={{position:'absolute', top: 16, right: 16, width: 12, height: 12, borderTop:`1px solid ${T.primary}`, borderRight:`1px solid ${T.primary}`}}/>
      <span style={{position:'absolute', bottom: 16, left: 16, width: 12, height: 12, borderBottom:`1px solid ${T.primary}`, borderLeft:`1px solid ${T.primary}`}}/>
      <span style={{position:'absolute', bottom: 16, right: 16, width: 12, height: 12, borderBottom:`1px solid ${T.primary}`, borderRight:`1px solid ${T.primary}`}}/>

      <div style={{position:'relative', zIndex: 1}}>
        <Brand size="lg" />
      </div>

      <div style={{flex: 1, display:'flex', flexDirection:'column', alignItems:'center', justifyContent:'center', position:'relative', zIndex: 1, gap: 14}}>
        <OnboardingFairy mood={fairy.mood} />
        <div style={{textAlign:'center', maxWidth: 340}}>
          <div style={{fontFamily: FONT_MONO, fontSize: 10, letterSpacing:'0.15em', textTransform:'uppercase', color: T.accent, marginBottom: 10, display:'flex', alignItems:'center', justifyContent:'center', gap: 8}}>
            <span style={{width: 14, height: 1, background: T.accent}}/>
            Alva · Begleiterin
            <span style={{width: 14, height: 1, background: T.accent}}/>
          </div>
          <div style={{color: T.fg, fontSize: 19, fontWeight: 500, lineHeight: 1.35, letterSpacing: -0.3, minHeight: 50}}>
            <Typewriter text={fairy.line} key={fairy.line}/>
          </div>
          <div style={{color: T.fg3, fontSize: 12.5, marginTop: 8, fontFamily: FONT_SANS, lineHeight: 1.5, fontStyle:'italic'}}>
            {fairy.sub}
          </div>
        </div>
      </div>

      {/* Compact phase stepper */}
      <div style={{position:'relative', zIndex: 1, display:'flex', flexDirection:'column', gap: 12}}>
        <div style={{display:'flex', justifyContent:'space-between', fontFamily: FONT_MONO, fontSize: 9.5, letterSpacing:'0.12em', textTransform:'uppercase', color: T.fg3}}>
          <span>Schritt {step + 1} / {FLOW.length}</span>
          <span style={{color: T.primary}}>{FLOW[step]?.phase || 'Start'}</span>
        </div>
        <div style={{display:'flex', gap: 3}}>
          {FLOW.map((f, i) => {
            const done = i < step, active = i === step;
            return <div key={f.id} style={{flex: 1, height: 3, background: done || active ? T.primary : T.surface3, opacity: done ? 0.7 : 1, boxShadow: active ? `0 0 8px ${T.primaryGlow}` : 'none'}}/>;
          })}
        </div>
        <div style={{display:'flex', justifyContent:'space-between', fontFamily: FONT_MONO, fontSize: 9, color: T.fg3, letterSpacing:'0.08em'}}>
          {['Start','Technik','Inhalt','Fertig'].map((p, i) => {
            const [lo, hi] = [[0,0],[1,5],[6,10],[11,11]][i];
            const on = step >= lo && step <= hi;
            return <span key={p} style={{color: on ? T.fg : T.fg3, fontWeight: on ? 600 : 400, textTransform:'uppercase'}}>{p}</span>;
          })}
        </div>
      </div>
    </aside>
  );
}

// ===== Running terminal log =====
function TerminalLog({ lines }) {
  const ref = React.useRef(null);
  React.useEffect(() => { if (ref.current) ref.current.scrollTop = ref.current.scrollHeight; }, [lines]);
  return (
    <div style={{
      background:`${T.bg}cc`, border:`1px solid ${T.border}`, borderLeft:`2px solid ${T.accent}`,
      borderRadius: 4, padding:'10px 14px', position:'relative', overflow:'hidden',
    }}>
      <div style={{display:'flex', justifyContent:'space-between', alignItems:'center', marginBottom: 8, fontFamily: FONT_MONO, fontSize: 9.5, letterSpacing:'0.1em', textTransform:'uppercase', color: T.fg3}}>
        <span style={{display:'flex', alignItems:'center', gap: 6}}>
          <span style={{width: 5, height: 5, borderRadius:'50%', background: T.accent, boxShadow:`0 0 6px ${T.accent}`, animation:'fairy-pulse 1.4s ease-in-out infinite'}}/>
          INSTALL-LOG
        </span>
        <span>{lines.length} Einträge</span>
      </div>
      <div ref={ref} style={{maxHeight: 130, overflowY:'auto', fontFamily: FONT_MONO, fontSize: 11, lineHeight: 1.6}}>
        {lines.length === 0 && <span style={{color: T.fg3, fontStyle:'italic'}}>· wartend ·</span>}
        {lines.map((l, i) => (
          <div key={i} style={{color: l.c || T.fg2, animation:'log-in .3s ease'}}>
            <span style={{color: T.fg3}}>[{l.t}]</span> {l.text}
          </div>
        ))}
      </div>
    </div>
  );
}

// ===== Right panel =====
function RightPanel({ children, logLines }) {
  return (
    <main style={{flex: 1, position:'relative', display:'flex', flexDirection:'column', background: T.bg, padding:'32px 48px', overflow:'auto'}}>
      <div style={{display:'flex', justifyContent:'space-between', alignItems:'center', marginBottom: 24}}>
        <div style={{fontFamily: FONT_MONO, fontSize: 10, letterSpacing:'0.1em', textTransform:'uppercase', color: T.fg3, display:'flex', alignItems:'center', gap: 8}}>
          <span style={{width: 5, height: 5, borderRadius:'50%', background: T.success, boxShadow:`0 0 6px ${T.success}`}}/>
          Installer · v2024.11.3 · eu-central-1
        </div>
        <div style={{display:'flex', gap: 20, alignItems:'center', fontFamily: FONT_MONO, fontSize: 10, color: T.fg3}}>
          <a href="login.html" style={{color: T.fg3, textDecoration:'none'}}>Anmelden</a>
          <a href="#" style={{color: T.fg3, textDecoration:'none'}}>Hilfe</a>
          <span>DE · EN</span>
        </div>
      </div>
      <div style={{flex: 1, display:'flex', alignItems:'flex-start', paddingTop: 8}}>
        <div style={{width:'100%'}}>{children}</div>
      </div>
      <div style={{marginTop: 20}}>
        <TerminalLog lines={logLines}/>
      </div>
      <div style={{marginTop: 16, display:'flex', justifyContent:'space-between', alignItems:'center', fontFamily: FONT_MONO, fontSize: 10, letterSpacing:'0.08em', color: T.fg3}}>
        <span>© Alvara GmbH · ISO 27001 · SOC 2 Typ II</span>
        <span>Installation verschlüsselt · TLS 1.3</span>
      </div>
    </main>
  );
}

// ===== Orchestrator =====
function App() {
  const STORAGE_KEY = 'alvara.onboarding.v2';
  const initial = (() => { try { return JSON.parse(localStorage.getItem(STORAGE_KEY)) || {}; } catch { return {}; } })();

  const [step, setStep] = React.useState(initial.step ?? 0);
  const [logLines, setLogLines] = React.useState(initial.logLines ?? [
    { t:'00:00:01', c: T.accent, text: '✦ Alvara Installer gestartet · build 2024.11.3' },
  ]);

  // Form state consolidated
  const [state, setState] = React.useState(initial.state ?? {
    dbHost:'localhost', dbPort:'3306', dbName:'alvara_prod', dbUser:'alvara', dbPass:'',
    restoreMode:'none', backupFile:null, restored: false,
    adminName:'', adminEmail:'', adminPass:'', adminPass2:'',
    smtpHost:'smtp.deinefirma.de', smtpPort:'587', smtpUser:'', smtpPass:'', smtpFrom:'noreply@deinefirma.de', smtpEnc:'tls',
    orgName:'', industry:'tech', orgSize:'m', country:'DE', tz:'Europe/Berlin', lang:'de', fy:'01',
    modules:{ assets:true, risks:true, controls:true, compliance:true, audit:true, dpia:false, bcm:false, supply:true, incident:true, training:false },
    frameworks:{ iso27001:true, nis2:true, bsi:false, tisax:false, dora:false, c5:false, soc2:false, dsgvo:true, iso27701:false, nist:false },
    sites:[], depts:[], siteInput:'', deptInput:'',
    demoMode:null, demoLoaded: false,
  });

  React.useEffect(() => {
    localStorage.setItem(STORAGE_KEY, JSON.stringify({ step, state, logLines }));
  }, [step, state, logLines]);

  const appendLog = (entry) => {
    const now = new Date();
    const t = `${String(now.getMinutes()).padStart(2,'0')}:${String(now.getSeconds()).padStart(2,'0')}`;
    setLogLines(prev => [...prev.slice(-40), { ...entry, t }]);
  };

  const go = (i) => setStep(Math.max(0, Math.min(FLOW.length - 1, i)));
  const next = () => {
    const cur = FLOW[step];
    // Log a marker when advancing
    const markers = {
      requirements: { c: T.success, text:'✓ System-Check bestanden' },
      database:     { c: T.success, text:'✓ DB-Schema angelegt · 187 Tabellen' },
      backup:       { c: T.primary, text:'· Backup-Schritt abgeschlossen' },
      admin:        { c: T.success, text:`✓ Admin "${state.adminName || 'admin'}" erstellt` },
      email:        { c: T.success, text:'✓ E-Mail-Konfiguration gespeichert' },
      organisation: { c: T.success, text:`✓ Organisation "${state.orgName}" angelegt` },
      modules:      { c: T.success, text:`✓ ${Object.values(state.modules).filter(Boolean).length} Module aktiviert` },
      frameworks:   { c: T.success, text:`✓ ${Object.keys(state.frameworks).filter(k=>state.frameworks[k]).length} Rahmenwerke geladen` },
      masterdata:   { c: T.success, text:`✓ ${state.sites.length} Standorte · ${state.depts.length} Abteilungen` },
      demo:         { c: T.primary, text: state.demoMode === 'yes' ? '· Demo-Daten geladen' : '· Demo-Daten übersprungen' },
    };
    if (markers[cur.kind]) appendLog(markers[cur.kind]);
    go(step + 1);
  };
  const back = () => go(step - 1);
  const reset = () => { localStorage.removeItem(STORAGE_KEY); window.location.reload(); };

  const cur = FLOW[step];
  const p = { state, setState, onNext: next, onBack: back, appendLog };

  let content;
  switch (cur.kind) {
    case 'welcome':      content = <Step0Welcome onNext={next} total={FLOW.length - 1}/>; break;
    case 'requirements': content = <Step1Requirements {...p}/>; break;
    case 'database':     content = <Step2Database {...p}/>; break;
    case 'backup':       content = <Step3Backup {...p}/>; break;
    case 'admin':        content = <Step4AdminUser {...p}/>; break;
    case 'email':        content = <Step5Email {...p}/>; break;
    case 'organisation': content = <Step6Organisation {...p}/>; break;
    case 'modules':      content = <Step7Modules {...p}/>; break;
    case 'frameworks':   content = <Step8Frameworks {...p}/>; break;
    case 'masterdata':   content = <Step9MasterData {...p}/>; break;
    case 'demo':         content = <Step10DemoData {...p}/>; break;
    case 'done':         content = <Step11Done state={state}/>; break;
    default:             content = null;
  }

  return (
    <div style={{display:'flex', minHeight:'100vh', minWidth: 1440, color: T.fg, fontFamily: FONT_SANS}}>
      <LeftPanel step={step} fairy={cur}/>
      <RightPanel logLines={logLines}>{content}</RightPanel>

      {/* Dev jumper */}
      <div style={{position:'fixed', bottom: 12, right: 16, zIndex: 50, display:'flex', gap: 3, padding:'4px 6px', background: T.surface, border: `1px solid ${T.border}`, borderRadius: 4, fontFamily: FONT_MONO, fontSize: 9, color: T.fg3}}>
        {FLOW.map((f, i) => (
          <button key={f.id} onClick={()=>go(i)} title={f.id} style={{
            width: 18, height: 18,
            background: i === step ? T.primary : i < step ? `${T.primary}40` : 'transparent',
            color: i === step ? '#04121a' : T.fg3,
            border:`1px solid ${i === step ? T.primary : T.border}`, borderRadius: 2, cursor:'pointer',
            fontFamily: FONT_MONO, fontSize: 9, fontWeight: 600,
          }}>{i}</button>
        ))}
        <button onClick={reset} style={{marginLeft: 4, padding:'0 6px', background:'transparent', color: T.fg3, border:`1px solid ${T.border}`, borderRadius: 2, cursor:'pointer', fontFamily: FONT_MONO, fontSize: 9}}>↺</button>
      </div>
    </div>
  );
}

ReactDOM.createRoot(document.getElementById('root')).render(<App />);
