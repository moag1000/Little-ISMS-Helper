/* global React, T, FONT_SANS, FONT_MONO, Icon, CyberButton, CyberInput, StepHeader, CheckRow, ToggleCard, CyberSelect, NavBar */

// ========== STEP 0 · WELCOME ==========
function Step0Welcome({ onNext, total }) {
  return (
    <div style={{display:'flex', flexDirection:'column', gap: 24, maxWidth: 480}}>
      <div style={{fontFamily: FONT_MONO, fontSize: 10, letterSpacing:'0.12em', textTransform:'uppercase', color: T.accent}}>
        ✦ Installation · {total} Schritte · ca. 8 Minuten
      </div>
      <div>
        <div style={{color: T.fg, fontSize: 32, fontWeight: 600, letterSpacing: -0.8, lineHeight: 1.15}}>Hi, ich bin Alva.</div>
        <div style={{color: T.fg, fontSize: 32, fontWeight: 600, letterSpacing: -0.8, lineHeight: 1.15, marginTop: 4}}>
          Lass uns dein ISMS einrichten.
        </div>
      </div>
      <div style={{color: T.fg2, fontSize: 15, lineHeight: 1.55}}>
        Wir prüfen erst dein System, richten Datenbank und Admin ein, und laden danach Organisation, Module und Rahmenwerke. Du kannst jederzeit unterbrechen — ich merke mir wo wir waren.
      </div>
      <div style={{
        background: T.surface, border: `1px solid ${T.border}`, borderRadius: 5,
        padding: 14, display:'grid', gridTemplateColumns:'1fr 1fr', gap: 10,
      }}>
        {[
          ['01–05', 'Technik', 'Requirements, DB, Backup, Admin, E-Mail'],
          ['06–10', 'Inhalt',  'Organisation, Module, Rahmenwerke, Daten'],
        ].map(([phase, title, sub]) => (
          <div key={phase}>
            <div style={{fontFamily: FONT_MONO, fontSize: 10, letterSpacing:'0.1em', color: T.primary}}>{phase}</div>
            <div style={{color: T.fg, fontSize: 13, fontWeight: 500, marginTop: 2}}>{title}</div>
            <div style={{color: T.fg3, fontSize: 11, marginTop: 2, lineHeight: 1.4}}>{sub}</div>
          </div>
        ))}
      </div>
      <div style={{display:'flex', gap: 10}}>
        <CyberButton onClick={onNext}>Los geht's</CyberButton>
        <CyberButton variant="secondary">Dokumentation</CyberButton>
      </div>
    </div>
  );
}

// ========== STEP 1 · REQUIREMENTS (animated check) ==========
const REQ_CHECKS = [
  { key:'php',   label:'PHP-Version',       req:'≥ 8.4',     val:'8.4.1',       status:'ok',   hint:'aktuell' },
  { key:'ext',   label:'PHP-Erweiterungen', req:'7 benötigt', val:'7 geladen',   status:'ok',   hint:'pdo_mysql · intl · mbstring · gd · zip · curl · xml' },
  { key:'db',    label:'Datenbank erreichbar', req:'MySQL 8 / MariaDB 10.6', val:'MariaDB 10.11', status:'ok', hint:'Verbindung erfolgreich' },
  { key:'perm',  label:'Schreibrechte',      req:'var/, public/uploads/', val:'alle schreibbar', status:'ok',   hint:'3 Verzeichnisse geprüft' },
  { key:'mem',   label:'Memory Limit',       req:'≥ 256 MB',  val:'512 MB',      status:'ok',   hint:'ausreichend' },
  { key:'exec',  label:'Max Execution Time', req:'≥ 60 s',    val:'120 s',       status:'ok',   hint:'für Imports ausreichend' },
  { key:'sym',   label:'Symfony-Umgebung',   req:'7.4',       val:'7.4.2 · prod',status:'ok',   hint:'keine Dev-Bundles aktiv' },
];

function Step1Requirements({ onNext, onBack, appendLog }) {
  const [done, setDone] = React.useState(0);
  React.useEffect(() => {
    setDone(0);
    const iv = setInterval(() => {
      setDone(d => {
        if (d >= REQ_CHECKS.length) { clearInterval(iv); return d; }
        const c = REQ_CHECKS[d];
        appendLog?.({ c: T.success, text: `✓ ${c.label}: ${c.val}` });
        return d + 1;
      });
    }, 380);
    return () => clearInterval(iv);
  }, []);

  const allOk = done >= REQ_CHECKS.length;
  return (
    <div style={{display:'flex', flexDirection:'column', gap: 20, maxWidth: 560}}>
      <StepHeader num={1} total={11} kind="System-Check" title="Läuft dein Server so wie ich's mag?" sub="Ich prüfe PHP, Datenbank, Rechte — eine Minute, kein Input nötig." />
      <div style={{display:'flex', flexDirection:'column', gap: 6}}>
        {REQ_CHECKS.map((c, i) => (
          <div key={c.key} style={{opacity: i < done ? 1 : 0.25, transition: 'opacity .3s'}}>
            <CheckRow status={i < done ? c.status : 'idle'} label={c.label} value={i < done ? c.val : '…'} hint={i < done ? c.hint : c.req} />
          </div>
        ))}
      </div>
      <div style={{
        background: allOk ? `${T.success}12` : T.surface, border: `1px solid ${allOk ? T.success : T.border}`,
        padding:'10px 14px', borderRadius: 4, display:'flex', alignItems:'center', gap: 10,
      }}>
        <span style={{color: allOk ? T.success : T.fg3, fontFamily: FONT_MONO, fontSize: 12, fontWeight: 700}}>{allOk ? '✓' : '·'}</span>
        <span style={{color: T.fg, fontSize: 13, fontFamily: FONT_SANS}}>
          {allOk ? 'Alle Anforderungen erfüllt. Wir können weitermachen.' : `Prüfe System… ${done} / ${REQ_CHECKS.length}`}
        </span>
      </div>
      <NavBar onBack={onBack} onNext={onNext} nextDisabled={!allOk} nextLabel="Weiter zur Datenbank" />
    </div>
  );
}

// ========== STEP 2 · DATABASE CONFIG ==========
function Step2Database({ state, setState, onNext, onBack, appendLog }) {
  const [tested, setTested] = React.useState(false);
  const [testing, setTesting] = React.useState(false);

  const test = () => {
    setTesting(true); setTested(false);
    appendLog?.({ c: T.primary, text: `→ Teste DB-Verbindung zu ${state.dbHost}:${state.dbPort}/${state.dbName}` });
    setTimeout(() => {
      setTesting(false); setTested(true);
      appendLog?.({ c: T.success, text: `✓ DB-Verbindung erfolgreich · Latenz 4 ms · Schema leer` });
    }, 1200);
  };

  return (
    <div style={{display:'flex', flexDirection:'column', gap: 20, maxWidth: 560}}>
      <StepHeader num={2} total={11} kind="Datenbank" title="Wohin darf ich dein ISMS schreiben?" sub="Die App braucht eine eigene MySQL- oder MariaDB-Datenbank. Ich lege das Schema beim nächsten Schritt automatisch an." />
      <div style={{display:'grid', gridTemplateColumns:'2fr 1fr', gap: 10}}>
        <CyberInput label="Host"     value={state.dbHost} onChange={e=>setState({...state, dbHost: e.target.value})} />
        <CyberInput label="Port"     value={state.dbPort} onChange={e=>setState({...state, dbPort: e.target.value})} />
      </div>
      <CyberInput label="Datenbankname" value={state.dbName} onChange={e=>setState({...state, dbName: e.target.value})} />
      <div style={{display:'grid', gridTemplateColumns:'1fr 1fr', gap: 10}}>
        <CyberInput label="Benutzer"  value={state.dbUser} onChange={e=>setState({...state, dbUser: e.target.value})} />
        <CyberInput label="Passwort"  type="password" value={state.dbPass} onChange={e=>setState({...state, dbPass: e.target.value})} />
      </div>
      <div style={{display:'flex', alignItems:'center', gap: 10}}>
        <button onClick={test} disabled={testing} style={{
          padding:'8px 14px', fontFamily: FONT_MONO, fontSize: 10, letterSpacing:'0.1em', textTransform:'uppercase', fontWeight: 600,
          background:'transparent', border:`1px solid ${T.primary}`, color: T.primary, borderRadius: 3, cursor: testing ? 'wait' : 'pointer',
          clipPath:'polygon(8px 0, 100% 0, 100% calc(100% - 8px), calc(100% - 8px) 100%, 0 100%, 0 8px)',
        }}>{testing ? 'Prüfe…' : 'Verbindung testen'}</button>
        {tested && <span style={{color: T.success, fontFamily: FONT_MONO, fontSize: 11}}>✓ verbunden · 4 ms</span>}
      </div>
      <NavBar onBack={onBack} onNext={onNext} nextDisabled={!tested} nextLabel="Schema anlegen →" />
    </div>
  );
}

// ========== STEP 3 · RESTORE BACKUP (optional, with progress animation) ==========
function Step3Backup({ state, setState, onNext, onBack, appendLog }) {
  const [progress, setProgress] = React.useState(0);
  const [running, setRunning] = React.useState(false);

  const startRestore = () => {
    setRunning(true); setProgress(0);
    const steps = [
      { at: 15, log: '→ Backup-Datei validiert · Version 2024.11.2 · 187 Tabellen' },
      { at: 40, log: '→ Schema migriert · 12 Änderungen gegenüber aktuell' },
      { at: 65, log: '→ 247 Assets importiert · 89 Mitarbeiter · 14 Lieferanten' },
      { at: 85, log: '→ 114 Controls mit Evidenzen wiederhergestellt' },
      { at: 100, log: '✓ Backup vollständig eingespielt' },
    ];
    let i = 0;
    const iv = setInterval(() => {
      if (i >= steps.length) { clearInterval(iv); setRunning(false); setState({...state, restored: true}); return; }
      setProgress(steps[i].at);
      appendLog?.({ c: i === steps.length - 1 ? T.success : T.primary, text: steps[i].log });
      i += 1;
    }, 700);
  };

  return (
    <div style={{display:'flex', flexDirection:'column', gap: 20, maxWidth: 560}}>
      <StepHeader num={3} total={11} kind="Backup · optional" title="Möchtest du ein Backup einspielen?" sub="Wenn du von einer anderen Instanz migrierst, lade jetzt deinen Export hoch. Sonst überspringen wir das einfach." />
      <div style={{display:'grid', gridTemplateColumns:'1fr 1fr', gap: 10}}>
        <ToggleCard on={state.restoreMode === 'none'} onClick={()=>setState({...state, restoreMode: 'none'})} title="Frisch starten" sub="Neue Instanz, leere Datenbank" />
        <ToggleCard on={state.restoreMode === 'backup'} onClick={()=>setState({...state, restoreMode: 'backup'})} title="Backup einspielen" sub=".zip · bis 500 MB" />
      </div>

      {state.restoreMode === 'backup' && (
        <>
          <div style={{
            border: `1.5px dashed ${T.border}`, borderRadius: 5, padding: 20,
            display:'flex', flexDirection:'column', alignItems:'center', gap: 8, cursor:'pointer',
            background: T.surface,
          }}>
            <div style={{fontSize: 22, color: T.accent}}>⇪</div>
            <div style={{color: T.fg, fontSize: 13, fontFamily: FONT_SANS}}>
              {state.backupFile || 'Datei hierher ziehen oder auswählen'}
            </div>
            <div style={{color: T.fg3, fontSize: 11, fontFamily: FONT_MONO}}>
              {state.backupFile ? '42,3 MB · alvara-backup-2024-11-20.zip' : '.zip oder .sql.gz · max 500 MB'}
            </div>
            {!state.backupFile && (
              <button onClick={()=>setState({...state, backupFile: 'alvara-backup-2024-11-20.zip'})} style={{
                marginTop: 4, padding:'4px 12px', fontFamily: FONT_MONO, fontSize: 10, letterSpacing:'0.08em',
                background:'transparent', color: T.primary, border:`1px solid ${T.primary}`, borderRadius: 3, cursor:'pointer',
              }}>DEMO-DATEI WÄHLEN</button>
            )}
          </div>

          {state.backupFile && !state.restored && (
            <div style={{display:'flex', flexDirection:'column', gap: 10}}>
              <div style={{display:'flex', justifyContent:'space-between', fontFamily: FONT_MONO, fontSize: 10.5, color: T.fg3}}>
                <span>Fortschritt</span>
                <span style={{color: T.primary}}>{progress}%</span>
              </div>
              <div style={{height: 6, background: T.surface, borderRadius: 3, overflow:'hidden', border:`1px solid ${T.border}`}}>
                <div style={{width:`${progress}%`, height:'100%', background:`linear-gradient(90deg, ${T.primary}, ${T.accent})`, boxShadow:`0 0 10px ${T.primaryGlow}`, transition:'width .4s'}}/>
              </div>
              {!running && <CyberButton onClick={startRestore}>Einspielen starten</CyberButton>}
              {running && <span style={{color: T.accent, fontSize: 12, fontFamily: FONT_MONO}}>läuft…</span>}
            </div>
          )}

          {state.restored && (
            <div style={{background:`${T.success}12`, border:`1px solid ${T.success}`, padding:'10px 14px', borderRadius: 4, color: T.fg, fontSize: 13}}>
              ✓ Backup wiederhergestellt. Weiter mit Admin-Account anlegen?
            </div>
          )}
        </>
      )}

      <NavBar onBack={onBack} onNext={onNext}
        nextLabel={state.restoreMode === 'backup' ? (state.restored ? 'Weiter' : 'Später') : 'Überspringen'}
        secondary={state.restoreMode === 'none' && <CyberButton variant="secondary">Dokumentation</CyberButton>}
      />
    </div>
  );
}

// ========== STEP 4 · ADMIN USER ==========
function Step4AdminUser({ state, setState, onNext, onBack }) {
  const valid = state.adminName && state.adminEmail && state.adminPass && state.adminPass === state.adminPass2;
  return (
    <div style={{display:'flex', flexDirection:'column', gap: 18, maxWidth: 520}}>
      <StepHeader num={4} total={11} kind="Admin-Account" title="Wer darf alles?" sub="Dieser Account hat volle Rechte — inklusive System-Konfiguration. Später kannst du weitere Admins ergänzen." />
      <CyberInput label="Vollständiger Name" value={state.adminName} onChange={e=>setState({...state, adminName: e.target.value})} />
      <CyberInput label="E-Mail" type="email" value={state.adminEmail} onChange={e=>setState({...state, adminEmail: e.target.value})} />
      <div style={{display:'grid', gridTemplateColumns:'1fr 1fr', gap: 10}}>
        <CyberInput label="Passwort" type="password" value={state.adminPass} onChange={e=>setState({...state, adminPass: e.target.value})} hint="mind. 12 Zeichen · 1 Zahl · 1 Sonderzeichen" />
        <CyberInput label="Passwort wiederholen" type="password" value={state.adminPass2} onChange={e=>setState({...state, adminPass2: e.target.value})} hint={state.adminPass && state.adminPass2 && state.adminPass !== state.adminPass2 ? 'stimmt nicht überein' : ' '} />
      </div>
      <div style={{
        background: T.surface, border: `1px solid ${T.border}`, borderRadius: 5,
        padding: 12, display:'flex', gap: 10, alignItems:'flex-start', fontSize: 12, color: T.fg2, lineHeight: 1.5,
      }}>
        <Icon name="shield" size={14} style={{color: T.accent, marginTop: 2, flexShrink: 0}}/>
        Das Passwort wird mit bcrypt gehashed gespeichert. Wir sehen es nie im Klartext — du verlierst es, du bekommst es nicht zurück.
      </div>
      <NavBar onBack={onBack} onNext={onNext} nextDisabled={!valid} nextLabel="Admin anlegen" />
    </div>
  );
}

// ========== STEP 5 · EMAIL CONFIG ==========
function Step5Email({ state, setState, onNext, onBack, appendLog }) {
  const [testState, setTestState] = React.useState('idle');
  const test = () => {
    setTestState('sending');
    appendLog?.({ c: T.primary, text: `→ Sende Testmail an ${state.adminEmail || 'admin@…'} via ${state.smtpHost}` });
    setTimeout(() => {
      setTestState('ok');
      appendLog?.({ c: T.success, text: `✓ Testmail zugestellt · TLS · 312 ms` });
    }, 1400);
  };
  return (
    <div style={{display:'flex', flexDirection:'column', gap: 18, maxWidth: 560}}>
      <StepHeader num={5} total={11} kind="E-Mail" title="Wie soll ich dir schreiben?" sub="SMTP-Zugang für Passwort-Resets, Audit-Erinnerungen und Benachrichtigungen. Du kannst das später noch ändern." />
      <div style={{display:'grid', gridTemplateColumns:'2fr 1fr', gap: 10}}>
        <CyberInput label="SMTP-Host" value={state.smtpHost} onChange={e=>setState({...state, smtpHost: e.target.value})} />
        <CyberInput label="Port" value={state.smtpPort} onChange={e=>setState({...state, smtpPort: e.target.value})} />
      </div>
      <div style={{display:'grid', gridTemplateColumns:'1fr 1fr', gap: 10}}>
        <CyberInput label="Benutzer" value={state.smtpUser} onChange={e=>setState({...state, smtpUser: e.target.value})} />
        <CyberInput label="Passwort" type="password" value={state.smtpPass} onChange={e=>setState({...state, smtpPass: e.target.value})} />
      </div>
      <CyberInput label="Absender-Adresse" value={state.smtpFrom} onChange={e=>setState({...state, smtpFrom: e.target.value})} hint="erscheint als Absender · z.B. noreply@deinefirma.de" />
      <CyberSelect label="Verschlüsselung" value={state.smtpEnc} onChange={e=>setState({...state, smtpEnc: e.target.value})} options={[
        { value:'tls',  label:'TLS (empfohlen)' },
        { value:'ssl',  label:'SSL' },
        { value:'none', label:'Keine' },
      ]} />
      <div style={{display:'flex', alignItems:'center', gap: 12}}>
        <button onClick={test} disabled={testState === 'sending'} style={{
          padding:'8px 14px', fontFamily: FONT_MONO, fontSize: 10, letterSpacing:'0.1em', textTransform:'uppercase', fontWeight: 600,
          background:'transparent', border:`1px solid ${T.primary}`, color: T.primary, borderRadius: 3, cursor: testState === 'sending' ? 'wait' : 'pointer',
          clipPath:'polygon(8px 0, 100% 0, 100% calc(100% - 8px), calc(100% - 8px) 100%, 0 100%, 0 8px)',
        }}>{testState === 'sending' ? 'Sende…' : 'Testmail senden'}</button>
        {testState === 'ok' && <span style={{color: T.success, fontFamily: FONT_MONO, fontSize: 11}}>✓ zugestellt · 312 ms</span>}
      </div>
      <NavBar onBack={onBack} onNext={onNext} nextLabel="Weiter" secondary={<CyberButton variant="secondary">Später</CyberButton>} />
    </div>
  );
}

Object.assign(window, { Step0Welcome, Step1Requirements, Step2Database, Step3Backup, Step4AdminUser, Step5Email });
