/* global React, T, FONT_SANS, FONT_MONO, Icon, CyberButton, CyberInput, StepHeader, CheckRow, ToggleCard, CyberSelect, NavBar */

// ========== STEP 6 · ORGANISATION ==========
function Step6Organisation({ state, setState, onNext, onBack }) {
  return (
    <div style={{display:'flex', flexDirection:'column', gap: 18, maxWidth: 560}}>
      <StepHeader num={6} total={11} kind="Organisation" title="Erzähl mir von deiner Organisation." sub="Das prägt den Geltungsbereich, Berichtszeiträume und Standort-Defaults im ISMS." />
      <CyberInput label="Name der Organisation" value={state.orgName} onChange={e=>setState({...state, orgName: e.target.value})} />
      <div style={{display:'grid', gridTemplateColumns:'2fr 1fr', gap: 10}}>
        <CyberSelect label="Branche" value={state.industry} onChange={e=>setState({...state, industry: e.target.value})} options={[
          { value:'tech',      label:'IT & Software' },
          { value:'finance',   label:'Banken & Versicherungen' },
          { value:'health',    label:'Gesundheitswesen' },
          { value:'public',    label:'Öffentliche Verwaltung' },
          { value:'industry',  label:'Produktion & Industrie' },
          { value:'energy',    label:'Energie & KRITIS' },
          { value:'retail',    label:'Handel' },
          { value:'other',     label:'Andere' },
        ]} />
        <CyberSelect label="Mitarbeitende" value={state.orgSize} onChange={e=>setState({...state, orgSize: e.target.value})} options={[
          { value:'s',  label:'< 50' },
          { value:'m',  label:'50 – 250' },
          { value:'l',  label:'250 – 1 000' },
          { value:'xl', label:'> 1 000' },
        ]} />
      </div>
      <div style={{display:'grid', gridTemplateColumns:'1fr 1fr', gap: 10}}>
        <CyberSelect label="Hauptsitz" value={state.country} onChange={e=>setState({...state, country: e.target.value})} options={[
          { value:'DE', label:'Deutschland' },
          { value:'AT', label:'Österreich' },
          { value:'CH', label:'Schweiz' },
          { value:'EU', label:'EU · andere' },
          { value:'ww', label:'International' },
        ]} />
        <CyberSelect label="Zeitzone" value={state.tz} onChange={e=>setState({...state, tz: e.target.value})} options={[
          { value:'Europe/Berlin', label:'Europa/Berlin (CET)' },
          { value:'Europe/Vienna', label:'Europa/Wien' },
          { value:'Europe/Zurich', label:'Europa/Zürich' },
          { value:'UTC',           label:'UTC' },
        ]} />
      </div>
      <div style={{display:'grid', gridTemplateColumns:'1fr 1fr', gap: 10}}>
        <CyberSelect label="Sprache" value={state.lang} onChange={e=>setState({...state, lang: e.target.value})} options={[
          { value:'de', label:'Deutsch' }, { value:'en', label:'English' },
        ]} />
        <CyberSelect label="Geschäftsjahr beginnt" value={state.fy} onChange={e=>setState({...state, fy: e.target.value})} options={[
          { value:'01', label:'Januar' }, { value:'04', label:'April' },
          { value:'07', label:'Juli' }, { value:'10', label:'Oktober' },
        ]} />
      </div>
      <NavBar onBack={onBack} onNext={onNext} nextDisabled={!state.orgName} nextLabel="Weiter zu den Modulen" />
    </div>
  );
}

// ========== STEP 7 · MODULE ==========
const MODULES = [
  { key:'assets',     title:'Asset-Management',    sub:'Systeme, Software, Daten', badge:'Core' },
  { key:'risks',      title:'Risikomanagement',    sub:'Register, Bewertung, Behandlung', badge:'Core' },
  { key:'controls',   title:'Controls & Maßnahmen',sub:'Umsetzung, Evidenzen, Wirksamkeit', badge:'Core' },
  { key:'compliance', title:'Compliance-Mapping',  sub:'Rahmenwerke, Anforderungen' },
  { key:'audit',      title:'Audit-Management',    sub:'Interne & externe Audits' },
  { key:'dpia',       title:'Datenschutz (DPIA)',  sub:'DSGVO, Auftragsverarbeitung' },
  { key:'bcm',        title:'BCM',                  sub:'Business Continuity, BIA' },
  { key:'supply',     title:'Lieferanten',          sub:'TPRM, Vertragsrisiken' },
  { key:'incident',   title:'Incident-Management', sub:'Vorfälle, NIS-2-Meldung' },
  { key:'training',   title:'Awareness & Trainings', sub:'Kampagnen, Nachweise' },
];

function Step7Modules({ state, setState, onNext, onBack }) {
  const toggle = (key) => {
    const mods = {...state.modules, [key]: !state.modules[key]};
    setState({...state, modules: mods});
  };
  const active = Object.values(state.modules).filter(Boolean).length;
  return (
    <div style={{display:'flex', flexDirection:'column', gap: 18, maxWidth: 620}}>
      <StepHeader num={7} total={11} kind="Module" title="Was willst du verwalten?" sub="Core-Module sind immer aktiv. Alles andere kannst du jederzeit nachziehen." />
      <div style={{
        display:'flex', justifyContent:'space-between', alignItems:'center',
        padding:'6px 10px', background: T.surface, border: `1px solid ${T.border}`, borderRadius: 4,
      }}>
        <span style={{fontFamily: FONT_MONO, fontSize: 11, color: T.fg3}}>AKTIVE MODULE</span>
        <span style={{fontFamily: FONT_MONO, fontSize: 11, color: T.primary, fontWeight: 600}}>{active} / {MODULES.length}</span>
      </div>
      <div style={{display:'grid', gridTemplateColumns:'1fr 1fr', gap: 8}}>
        {MODULES.map(m => (
          <ToggleCard key={m.key} on={!!state.modules[m.key]} onClick={() => m.badge !== 'Core' && toggle(m.key)}
            title={m.title} sub={m.sub} badge={m.badge} />
        ))}
      </div>
      <NavBar onBack={onBack} onNext={onNext} nextLabel="Weiter zu den Rahmenwerken" />
    </div>
  );
}

// ========== STEP 8 · RAHMENWERKE ==========
const FRAMEWORKS = [
  { key:'iso27001', title:'ISO/IEC 27001:2022',   sub:'ISMS · 93 Controls', ctrls: 93 },
  { key:'iso27701', title:'ISO/IEC 27701',         sub:'Datenschutz-Erweiterung', ctrls: 49 },
  { key:'nis2',     title:'NIS-2 Richtlinie',      sub:'EU · Kritische Sektoren', ctrls: 22 },
  { key:'bsi',      title:'BSI IT-Grundschutz',    sub:'Basis · Standard · Kern', ctrls: 84 },
  { key:'tisax',    title:'TISAX / VDA ISA',       sub:'Automotive · AL-Stufen', ctrls: 76 },
  { key:'dora',     title:'DORA',                   sub:'Finanzsektor · ICT-Risiko', ctrls: 39 },
  { key:'c5',       title:'BSI C5:2020',            sub:'Cloud-Dienste', ctrls: 125 },
  { key:'soc2',     title:'SOC 2 Trust Services',  sub:'TSC · Type I/II', ctrls: 64 },
  { key:'dsgvo',    title:'DSGVO / GDPR',          sub:'EU-Datenschutz', ctrls: 42 },
  { key:'nist',     title:'NIST CSF 2.0',          sub:'Cybersecurity Framework', ctrls: 106 },
];

function Step8Frameworks({ state, setState, onNext, onBack }) {
  const toggle = k => setState({...state, frameworks:{...state.frameworks, [k]: !state.frameworks[k]}});
  const sel = Object.keys(state.frameworks).filter(k => state.frameworks[k]);
  const totalCtrls = FRAMEWORKS.filter(f => sel.includes(f.key)).reduce((a,f)=>a+f.ctrls, 0);
  return (
    <div style={{display:'flex', flexDirection:'column', gap: 18, maxWidth: 620}}>
      <StepHeader num={8} total={11} kind="Rahmenwerke" title="Welche Standards befolgst du?" sub="Ich lade die Control-Kataloge und verknüpfe sie mit deinen Maßnahmen. Cross-Walk inklusive." />
      <div style={{
        display:'flex', justifyContent:'space-between', alignItems:'center',
        padding:'6px 10px', background: T.surface, border: `1px solid ${T.border}`, borderRadius: 4,
      }}>
        <span style={{fontFamily: FONT_MONO, fontSize: 11, color: T.fg3}}>AUSGEWÄHLT</span>
        <span style={{fontFamily: FONT_MONO, fontSize: 11, color: T.primary, fontWeight: 600}}>
          {sel.length} Rahmenwerke · {totalCtrls} Controls
        </span>
      </div>
      <div style={{display:'grid', gridTemplateColumns:'1fr 1fr', gap: 8}}>
        {FRAMEWORKS.map(f => (
          <ToggleCard key={f.key} on={!!state.frameworks[f.key]} onClick={()=>toggle(f.key)}
            title={f.title} sub={`${f.sub} · ${f.ctrls} Controls`} />
        ))}
      </div>
      <NavBar onBack={onBack} onNext={onNext} nextLabel="Weiter zu den Stammdaten" nextDisabled={!sel.length} />
    </div>
  );
}

// ========== STEP 9 · STAMMDATEN (Standorte, Abteilungen) ==========
function Step9MasterData({ state, setState, onNext, onBack, appendLog }) {
  const addSite = () => {
    const v = state.siteInput?.trim();
    if (!v) return;
    setState({...state, sites: [...state.sites, v], siteInput: ''});
    appendLog?.({ c: T.primary, text: `+ Standort: ${v}` });
  };
  const addDept = () => {
    const v = state.deptInput?.trim();
    if (!v) return;
    setState({...state, depts: [...state.depts, v], deptInput: ''});
    appendLog?.({ c: T.primary, text: `+ Abteilung: ${v}` });
  };
  const useTemplate = () => {
    const sites = ['Zentrale München','Büro Berlin','RZ Frankfurt'];
    const depts = ['IT-Betrieb','Informationssicherheit','Personal','Rechnungswesen','Vertrieb','Entwicklung'];
    setState({...state, sites, depts});
    appendLog?.({ c: T.accent, text: `✦ Vorlage "Mittelstand DE" angewendet · 3 Standorte · 6 Abteilungen` });
  };
  return (
    <div style={{display:'flex', flexDirection:'column', gap: 18, maxWidth: 580}}>
      <StepHeader num={9} total={11} kind="Stammdaten" title="Wie ist die Organisation strukturiert?" sub="Standorte und Abteilungen bilden die Basis für Rollen, Asset-Zuordnung und Auditscope. Nur ein paar brauchst du jetzt — den Rest pflegen wir später." />

      <div style={{display:'grid', gridTemplateColumns:'1fr 1fr', gap: 20}}>
        {/* Sites */}
        <div>
          <div style={{fontFamily: FONT_MONO, fontSize: 10, letterSpacing:'0.1em', textTransform:'uppercase', color: T.fg3, marginBottom: 8}}>Standorte · {state.sites.length}</div>
          <div style={{display:'flex', gap: 6, marginBottom: 10}}>
            <input value={state.siteInput || ''} onChange={e=>setState({...state, siteInput: e.target.value})}
              onKeyDown={e=>e.key==='Enter' && addSite()}
              placeholder="z.B. Zentrale München"
              style={{flex:1, background: T.bg, color: T.fg, border:`1px solid ${T.border}`, padding:'8px 10px', fontFamily: FONT_SANS, fontSize: 13, borderRadius: 3, outline:'none'}}/>
            <button onClick={addSite} style={{padding:'6px 12px', background:`${T.primary}18`, color: T.primary, border:`1px solid ${T.primary}`, fontFamily: FONT_MONO, fontSize: 12, borderRadius: 3, cursor:'pointer'}}>+</button>
          </div>
          <div style={{display:'flex', flexDirection:'column', gap: 4}}>
            {state.sites.map((s, i) => (
              <div key={i} style={{padding:'6px 10px', background: T.surface, border:`1px solid ${T.border}`, borderLeft:`2px solid ${T.primary}`, color: T.fg, fontSize: 12, borderRadius: 3, display:'flex', justifyContent:'space-between'}}>
                <span>{s}</span>
                <button onClick={()=>setState({...state, sites: state.sites.filter((_,j)=>j!==i)})} style={{background:'none', border:'none', color: T.fg3, cursor:'pointer', fontFamily: FONT_MONO}}>×</button>
              </div>
            ))}
            {!state.sites.length && <span style={{color: T.fg3, fontSize: 11, fontFamily: FONT_MONO, padding:'6px 0'}}>— noch nichts —</span>}
          </div>
        </div>
        {/* Depts */}
        <div>
          <div style={{fontFamily: FONT_MONO, fontSize: 10, letterSpacing:'0.1em', textTransform:'uppercase', color: T.fg3, marginBottom: 8}}>Abteilungen · {state.depts.length}</div>
          <div style={{display:'flex', gap: 6, marginBottom: 10}}>
            <input value={state.deptInput || ''} onChange={e=>setState({...state, deptInput: e.target.value})}
              onKeyDown={e=>e.key==='Enter' && addDept()}
              placeholder="z.B. IT-Betrieb"
              style={{flex:1, background: T.bg, color: T.fg, border:`1px solid ${T.border}`, padding:'8px 10px', fontFamily: FONT_SANS, fontSize: 13, borderRadius: 3, outline:'none'}}/>
            <button onClick={addDept} style={{padding:'6px 12px', background:`${T.primary}18`, color: T.primary, border:`1px solid ${T.primary}`, fontFamily: FONT_MONO, fontSize: 12, borderRadius: 3, cursor:'pointer'}}>+</button>
          </div>
          <div style={{display:'flex', flexDirection:'column', gap: 4}}>
            {state.depts.map((s, i) => (
              <div key={i} style={{padding:'6px 10px', background: T.surface, border:`1px solid ${T.border}`, borderLeft:`2px solid ${T.accent}`, color: T.fg, fontSize: 12, borderRadius: 3, display:'flex', justifyContent:'space-between'}}>
                <span>{s}</span>
                <button onClick={()=>setState({...state, depts: state.depts.filter((_,j)=>j!==i)})} style={{background:'none', border:'none', color: T.fg3, cursor:'pointer', fontFamily: FONT_MONO}}>×</button>
              </div>
            ))}
            {!state.depts.length && <span style={{color: T.fg3, fontSize: 11, fontFamily: FONT_MONO, padding:'6px 0'}}>— noch nichts —</span>}
          </div>
        </div>
      </div>

      <button onClick={useTemplate} style={{
        padding:'10px', background: T.surface, border:`1px dashed ${T.accent}`, color: T.accent,
        fontFamily: FONT_MONO, fontSize: 11, letterSpacing:'0.06em', borderRadius: 3, cursor:'pointer',
      }}>✦ VORLAGE "MITTELSTAND DE" ANWENDEN</button>

      <NavBar onBack={onBack} onNext={onNext} nextLabel="Weiter" />
    </div>
  );
}

// ========== STEP 10 · DEMO DATA ==========
function Step10DemoData({ state, setState, onNext, onBack, appendLog }) {
  const [loading, setLoading] = React.useState(false);
  const [loaded, setLoaded] = React.useState(false);
  const load = () => {
    setLoading(true);
    const msgs = [
      '→ Lade 47 Beispiel-Assets',
      '→ Lade 14 Beispiel-Risiken mit Bewertungen',
      '→ Lade 89 Controls mit Demo-Evidenzen',
      '→ Lade 6 Beispiel-Audits',
      '✓ Demo-Daten bereit · jederzeit löschbar',
    ];
    let i = 0;
    const iv = setInterval(() => {
      if (i >= msgs.length) { clearInterval(iv); setLoading(false); setLoaded(true); setState({...state, demoLoaded: true}); return; }
      appendLog?.({ c: i === msgs.length - 1 ? T.success : T.primary, text: msgs[i] });
      i++;
    }, 500);
  };
  return (
    <div style={{display:'flex', flexDirection:'column', gap: 18, maxWidth: 560}}>
      <StepHeader num={10} total={11} kind="Demo-Daten · optional" title="Möchtest du was zum Ausprobieren?" sub="Ich kann einen realistischen Beispiel-Bestand laden — gut für Schulungen und um das UI mal mit Leben zu sehen. Alles löschbar." />
      <div style={{display:'grid', gridTemplateColumns:'1fr 1fr', gap: 10}}>
        <ToggleCard on={state.demoMode === 'yes'} onClick={()=>setState({...state, demoMode:'yes'})} title="Ja, mit Beispielen" sub="47 Assets · 14 Risiken · 89 Controls" />
        <ToggleCard on={state.demoMode === 'no'}  onClick={()=>setState({...state, demoMode:'no'})}  title="Leer starten" sub="Nur meine Daten" />
      </div>
      {state.demoMode === 'yes' && !loaded && (
        <div style={{display:'flex', gap: 10, alignItems:'center'}}>
          <CyberButton onClick={load} disabled={loading}>{loading ? 'Lade…' : 'Demo-Daten laden'}</CyberButton>
          {loading && <span style={{color: T.accent, fontSize: 12, fontFamily: FONT_MONO}}>das dauert 3 Sekunden</span>}
        </div>
      )}
      {loaded && (
        <div style={{background:`${T.success}12`, border:`1px solid ${T.success}`, padding:'10px 14px', borderRadius: 4, color: T.fg, fontSize: 13}}>
          ✓ Demo-Bestand geladen. Du kannst ihn später jederzeit unter Einstellungen → Daten entfernen.
        </div>
      )}
      <NavBar onBack={onBack} onNext={onNext} nextLabel="Zum Abschluss" nextDisabled={!state.demoMode || (state.demoMode === 'yes' && !loaded)} />
    </div>
  );
}

// ========== STEP 11 · DONE ==========
function Step11Done({ state }) {
  const frameworks = Object.keys(state.frameworks).filter(k => state.frameworks[k]).length;
  const modules = Object.values(state.modules).filter(Boolean).length;
  return (
    <div style={{display:'flex', flexDirection:'column', gap: 22, maxWidth: 560}}>
      <div>
        <div style={{fontFamily: FONT_MONO, fontSize: 10, letterSpacing:'0.12em', textTransform:'uppercase', color: T.success}}>
          ✓ Installation abgeschlossen · 7 min 42 s
        </div>
        <div style={{color: T.fg, fontSize: 32, fontWeight: 600, letterSpacing: -0.8, marginTop: 10, lineHeight: 1.15}}>
          Fertig. Das war's.
        </div>
        <div style={{color: T.fg2, fontSize: 14, marginTop: 10, lineHeight: 1.55}}>
          Dein Alvara ISMS ist einsatzbereit, {state.adminName?.split(' ')[0] || 'Admin'}. Ich hab dir eine Bestätigung an {state.adminEmail || 'deine E-Mail'} geschickt — falls du später nochmal reinschauen willst.
        </div>
      </div>

      <div style={{display:'grid', gridTemplateColumns:'1fr 1fr', gap: 8}}>
        <CheckRow status="ok" label="Datenbank"       value="verbunden"        hint={`${state.dbName} @ ${state.dbHost}`} />
        <CheckRow status="ok" label="Admin-Account"   value={state.adminName || '—'} hint={state.adminEmail} />
        <CheckRow status="ok" label="E-Mail"          value={state.smtpEnc?.toUpperCase() || 'TLS'} hint={state.smtpHost} />
        <CheckRow status="ok" label="Organisation"    value={state.orgName || '—'}  hint={`${state.sites.length} Standorte · ${state.depts.length} Abteilungen`} />
        <CheckRow status="ok" label="Module"          value={`${modules} aktiv`}    hint="Core + Erweiterungen" />
        <CheckRow status="ok" label="Rahmenwerke"     value={`${frameworks} gewählt`} hint="Controls verknüpft" />
      </div>

      <div style={{
        background: T.surface, border:`1px solid ${T.accent}`, borderRadius: 5, padding: 14,
        display:'flex', gap: 12, alignItems:'flex-start',
      }}>
        <div style={{fontSize: 18, color: T.accent}}>✦</div>
        <div>
          <div style={{color: T.fg, fontSize: 13, fontWeight: 500}}>Ein Tipp für den Start</div>
          <div style={{color: T.fg2, fontSize: 12, marginTop: 3, lineHeight: 1.5}}>
            Beginne mit dem Asset-Inventar. Alles andere — Risiken, Controls, Audits — verknüpft sich daraufhin automatisch. Ich führe dich durch.
          </div>
        </div>
      </div>

      <div style={{display:'flex', gap: 10}}>
        <a href="../dashboard/dashboard.html" style={{textDecoration:'none'}}>
          <CyberButton>Zum Dashboard →</CyberButton>
        </a>
        <a href="login.html" style={{textDecoration:'none'}}>
          <CyberButton variant="secondary">Zum Login</CyberButton>
        </a>
      </div>
    </div>
  );
}

Object.assign(window, { Step6Organisation, Step7Modules, Step8Frameworks, Step9MasterData, Step10DemoData, Step11Done });
