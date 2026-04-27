/* global React, Badge, Severity, Button, FairySpark */

function ControlDrawer({ open, onClose }) {
  return (
    <>
      <div className={`backdrop ${open ? 'show' : ''}`} onClick={onClose} />
      <aside className={`drawer ${open ? 'open' : ''}`}>
        <div className="drawer-head">
          <div>
            <div className="code">A.5.16 · ISO 27001:2022 · Annex A</div>
            <h2>Identitätsmanagement</h2>
            <div style={{display:'flex',gap:8,marginTop:10,flexWrap:'wrap'}}>
              <Severity level="critical" />
              <Badge variant="warning" icon="hourglass-split">In Arbeit</Badge>
              <Badge variant="info" icon="diagram-3">3 Mappings</Badge>
              <Badge variant="neutral" icon="person">M. Schubert</Badge>
            </div>
          </div>
          <button className="drawer-close" onClick={onClose}>✕</button>
        </div>
        <div className="drawer-body">
          <div className="fairy-suggestion" style={{marginBottom:18}}>
            <FairySpark size={15} />
            <span>
              Fee-Vorschlag: <b>Richtlinien-Snippet aus A.5.15 übernehmen</b> &nbsp;·&nbsp; 87 % Ähnlichkeit &nbsp;·&nbsp; <b>Anwenden →</b>
            </span>
          </div>

          <h4>Zielsetzung (ISO 27002 §5.16)</h4>
          <p>
            Der gesamte Lebenszyklus von Identitäten — Erstellung, Überprüfung, Änderung,
            Deaktivierung und Löschung — ist zu steuern, um eine eindeutige Zuordnung von
            Handlungen zu natürlichen Personen und Systemen zu gewährleisten.
          </p>

          <h4>Umsetzungshinweise</h4>
          <p>
            Jede Identität muss einem verantwortlichen Eigentümer zugewiesen sein.
            Gemeinsam genutzte Accounts sind zu vermeiden; wo unvermeidbar, ist eine
            kompensierende Kontrolle zu dokumentieren. Periodische Re-Zertifizierung
            mindestens halbjährlich, für privilegierte Identitäten quartalsweise.
          </p>

          <h4>Mappings</h4>
          <div style={{display:'grid',gridTemplateColumns:'1fr',gap:8,marginBottom:8}}>
            <div style={{padding:'10px 12px',background:'var(--bg-2)',borderRadius:8,border:'1px solid var(--border-2)',display:'flex',justifyContent:'space-between',alignItems:'center',fontSize:13}}>
              <span><span className="ctrl-code">ISO 27002</span> &nbsp; §5.16 Identity Management</span>
              <Badge variant="success" icon="check2">exakt</Badge>
            </div>
            <div style={{padding:'10px 12px',background:'var(--bg-2)',borderRadius:8,border:'1px solid var(--border-2)',display:'flex',justifyContent:'space-between',alignItems:'center',fontSize:13}}>
              <span><span className="ctrl-code">BSI ORP.4.A9</span> &nbsp; Regelung für Einrichtung / Änderung von Benutzerkennungen</span>
              <Badge variant="info" icon="arrow-left-right">teilweise</Badge>
            </div>
            <div style={{padding:'10px 12px',background:'var(--bg-2)',borderRadius:8,border:'1px solid var(--border-2)',display:'flex',justifyContent:'space-between',alignItems:'center',fontSize:13}}>
              <span><span className="ctrl-code">TISAX 4.1.1</span> &nbsp; Identitätsmanagement</span>
              <Badge variant="success" icon="check2">exakt</Badge>
            </div>
          </div>

          <h4>Evidence (2 / 5)</h4>
          <div style={{display:'grid',gap:6}}>
            <div style={{padding:'10px 12px',background:'var(--bg-2)',borderRadius:8,border:'1px solid var(--border-2)',display:'flex',alignItems:'center',gap:10,fontSize:13}}>
              <i className="bi bi-file-earmark-pdf" style={{color:'#ef4444',fontSize:18}} />
              <div style={{flex:1}}>
                <div style={{fontWeight:500}}>Richtlinie_Identitaetsmanagement_v2.3.pdf</div>
                <div style={{fontSize:11,color:'var(--fg-3)',fontFamily:'var(--font-mono)'}}>1.2 MB · hochgeladen 12.03.2026 · M. Schubert</div>
              </div>
              <Badge variant="success">gültig</Badge>
            </div>
            <div style={{padding:'10px 12px',background:'var(--bg-2)',borderRadius:8,border:'1px solid var(--border-2)',display:'flex',alignItems:'center',gap:10,fontSize:13}}>
              <i className="bi bi-file-earmark-spreadsheet" style={{color:'#10b981',fontSize:18}} />
              <div style={{flex:1}}>
                <div style={{fontWeight:500}}>Rezertifizierung_Q1-2026.xlsx</div>
                <div style={{fontSize:11,color:'var(--fg-3)',fontFamily:'var(--font-mono)'}}>84 KB · 04.04.2026 · automatisch generiert</div>
              </div>
              <Badge variant="success">gültig</Badge>
            </div>
            <div style={{padding:'10px 12px',background:'var(--bg-2)',border:'1px dashed rgba(236,72,153,0.4)',borderRadius:8,display:'flex',alignItems:'center',gap:10,fontSize:13,color:'var(--fg-2)'}}>
              <i className="bi bi-plus-circle" style={{color:'#ec4899',fontSize:18}} />
              <div style={{flex:1}}>
                Beleg hochladen (Audit-Log, Review-Protokoll, Screenshot…)
              </div>
            </div>
          </div>

          <div style={{display:'flex',gap:8,marginTop:22,justifyContent:'flex-end'}}>
            <Button variant="secondary" icon="chat-left-text">Kommentar</Button>
            <Button variant="secondary" icon="pencil">Bearbeiten</Button>
            <Button variant="primary" icon="check2-all">Als umgesetzt markieren</Button>
          </div>
        </div>
      </aside>
    </>
  );
}

window.ControlDrawer = ControlDrawer;
