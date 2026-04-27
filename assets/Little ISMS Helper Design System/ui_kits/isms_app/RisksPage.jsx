/* global React, Badge, Severity, Button, FairySpark */

function RisksPage({ onOpenControl }) {
  const cells = [
    // row = impact 5..1, col = likelihood 1..5
    ['m-med','m-med','m-high','m-crit','m-crit'],
    ['m-med','m-med','m-high','m-high','m-crit'],
    ['m-low','m-med','m-med','m-high','m-high'],
    ['m-low','m-low','m-med','m-med','m-high'],
    ['m-low','m-low','m-low','m-med','m-med'],
  ];
  const counts = [
    ['·','1','·','1','1'],
    ['·','2','·','2','·'],
    ['3','4','5','2','·'],
    ['·','1','2','·','·'],
    ['·','·','·','·','·'],
  ];
  const impactLbl = ['5 · Katastrophal','4 · Hoch','3 · Mittel','2 · Gering','1 · Marginal'];
  const likeLbl   = ['1 · Selten','2 · Unwahr.','3 · Möglich','4 · Wahrsch.','5 · Fast sicher'];

  return (
    <div>
      <div className="page-head">
        <div>
          <div className="eyebrow">› ISMS / Risiken</div>
          <h1>Risiko-Register</h1>
          <div className="sub">24 offene Risiken · 18 im Treatment · 6 akzeptiert · Stand 21.04.2026</div>
        </div>
        <div style={{display:'flex',gap:8}}>
          <Button variant="secondary" icon="funnel">Filter</Button>
          <Button variant="secondary" icon="share">Exportieren</Button>
          <Button variant="primary" icon="plus-lg">Neues Risiko</Button>
        </div>
      </div>

      <div className="grid-2" style={{gridTemplateColumns:'1fr 1.2fr'}}>
        <div className="widget">
          <div className="widget-head">
            <h3><i className="bi bi-grid-3x3" />Risiko-Matrix (5×5)</h3>
            <span style={{fontSize:11,fontFamily:'var(--font-mono)',color:'var(--fg-3)'}}>Brutto · inhärent</span>
          </div>
          <div className="matrix">
            {/* top-left corner */}
            <div />
            {likeLbl.map((l,i)=>(<div key={'x'+i} className="axis-x">{l}</div>))}
            {cells.map((row,r)=> (
              <React.Fragment key={'r'+r}>
                <div className="axis-y">{impactLbl[r]}</div>
                {row.map((c,i)=> (
                  <div key={r+'-'+i} className={`cell ${c}`}>{counts[r][i]}</div>
                ))}
              </React.Fragment>
            ))}
          </div>
          <div style={{display:'flex',gap:14,fontSize:11,color:'var(--fg-3)',marginTop:14,fontFamily:'var(--font-mono)',textTransform:'uppercase',letterSpacing:'.05em'}}>
            <span><span style={{display:'inline-block',width:10,height:10,background:'#10b981',borderRadius:3,marginRight:6,verticalAlign:'middle'}} />Niedrig</span>
            <span><span style={{display:'inline-block',width:10,height:10,background:'#f59e0b',borderRadius:3,marginRight:6,verticalAlign:'middle'}} />Mittel</span>
            <span><span style={{display:'inline-block',width:10,height:10,background:'#ef4444',borderRadius:3,marginRight:6,verticalAlign:'middle'}} />Hoch</span>
            <span><span style={{display:'inline-block',width:10,height:10,background:'#f97316',borderRadius:3,marginRight:6,verticalAlign:'middle'}} />Kritisch</span>
          </div>
        </div>

        <div className="widget">
          <div className="widget-head">
            <h3><i className="bi bi-list-columns-reverse" />Top Risiken (Brutto)</h3>
            <Button variant="ghost" size="sm">Alle 24</Button>
          </div>
          <table className="data" style={{border:'none'}}>
            <thead>
              <tr>
                <th style={{width:'95px'}}>ID</th>
                <th>Beschreibung</th>
                <th style={{width:'100px'}}>Brutto</th>
                <th style={{width:'100px'}}>Netto</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td><span className="ctrl-code">R-2025-014</span></td>
                <td><span className="ctrl-name">Ransomware-Angriff auf Datei-Server</span><span className="ctrl-clause">Asset: FS-01 · Owner: S. Klein</span></td>
                <td><Severity level="critical" /></td>
                <td><Severity level="medium" /></td>
              </tr>
              <tr>
                <td><span className="ctrl-code">R-2025-021</span></td>
                <td><span className="ctrl-name">Phishing-Kompromittierung privilegierte Accounts</span><span className="ctrl-clause">Asset: AD · NIS-2 Relevanz</span></td>
                <td><Severity level="critical" /></td>
                <td><Severity level="high" /></td>
              </tr>
              <tr>
                <td><span className="ctrl-code">R-2025-007</span></td>
                <td><span className="ctrl-name">Ausfall externer CSP (IaaS) &gt; 4h</span><span className="ctrl-clause">Asset: Cloud-Workloads</span></td>
                <td><Severity level="high" /></td>
                <td><Severity level="medium" /></td>
              </tr>
              <tr>
                <td><span className="ctrl-code">R-2025-033</span></td>
                <td><span className="ctrl-name">Insider-Threat durch verärgerten Admin</span><span className="ctrl-clause">Mitigiert: A.5.16, A.8.2</span></td>
                <td><Severity level="high" /></td>
                <td><Severity level="low" /></td>
              </tr>
              <tr>
                <td><span className="ctrl-code">R-2025-041</span></td>
                <td><span className="ctrl-name">Unzureichende Lieferantenbewertung</span><span className="ctrl-clause">A.5.19 · TISAX 1.5.1</span></td>
                <td><Severity level="medium" /></td>
                <td><Severity level="low" /></td>
              </tr>
              <tr>
                <td><span className="ctrl-code">R-2025-052</span></td>
                <td><span className="ctrl-name">Datenverlust mobile Endgeräte</span><span className="ctrl-clause">A.8.1, A.7.9</span></td>
                <td><Severity level="medium" /></td>
                <td><Severity level="low" /></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div className="widget">
        <div className="widget-head">
          <h3><i className="bi bi-shield-check" />Controls (ISO 27001:2022 Annex A)</h3>
          <div style={{display:'flex',gap:10,alignItems:'center'}}>
            <div style={{background:'var(--bg-2)',border:'1px solid var(--border-1)',borderRadius:6,padding:'6px 10px',fontSize:12,color:'var(--fg-2)',display:'flex',alignItems:'center',gap:6}}>
              <i className="bi bi-search" />
              <span>Filter · Kategorie, Status, Owner</span>
            </div>
            <Button variant="secondary" size="sm" icon="download">CSV</Button>
          </div>
        </div>
        <table className="data">
          <thead>
            <tr>
              <th style={{width:'85px'}}>Code</th>
              <th>Titel</th>
              <th style={{width:'130px'}}>Typ</th>
              <th style={{width:'130px'}}>Mapping</th>
              <th style={{width:'110px'}}>Prio</th>
              <th style={{width:'110px'}}>Status</th>
              <th style={{width:'95px'}}>Evidence</th>
            </tr>
          </thead>
          <tbody>
            <tr onClick={onOpenControl} style={{cursor:'pointer'}}>
              <td><span className="ctrl-code">A.5.1</span></td>
              <td><span className="ctrl-name">Informationssicherheitsrichtlinien</span></td>
              <td>Organisatorisch</td>
              <td><Badge variant="info" icon="diagram-3">3 Frameworks</Badge></td>
              <td><Severity level="high" /></td>
              <td><Badge variant="success" icon="check2">Umgesetzt</Badge></td>
              <td><span style={{color:'#10b981',fontFamily:'var(--font-mono)',fontSize:12}}>8 Belege</span></td>
            </tr>
            <tr onClick={onOpenControl} style={{cursor:'pointer'}}>
              <td><span className="ctrl-code">A.5.15</span></td>
              <td><span className="ctrl-name">Zugriffskontrolle</span></td>
              <td>Organisatorisch</td>
              <td><Badge variant="info" icon="diagram-3">2 Frameworks</Badge></td>
              <td><Severity level="critical" /></td>
              <td><Badge variant="success" icon="check2">Umgesetzt</Badge></td>
              <td><span style={{color:'#10b981',fontFamily:'var(--font-mono)',fontSize:12}}>12 Belege</span></td>
            </tr>
            <tr onClick={onOpenControl} style={{cursor:'pointer',background:'rgba(236,72,153,0.04)'}}>
              <td><span className="ctrl-code">A.5.16</span></td>
              <td>
                <span className="ctrl-name">
                  Identitätsmanagement <FairySpark />
                </span>
                <span className="ctrl-clause" style={{color:'#ec4899'}}>Fee-Hinweis: 3 Belege fehlen für Audit</span>
              </td>
              <td>Organisatorisch</td>
              <td><Badge variant="info" icon="diagram-3">3 Frameworks</Badge></td>
              <td><Severity level="critical" /></td>
              <td><Badge variant="warning" icon="hourglass-split">In Arbeit</Badge></td>
              <td><span style={{color:'#f59e0b',fontFamily:'var(--font-mono)',fontSize:12}}>2/5 Belege</span></td>
            </tr>
            <tr>
              <td><span className="ctrl-code">A.5.23</span></td>
              <td><span className="ctrl-name">InfoSec für Cloud-Dienste</span></td>
              <td>Organisatorisch</td>
              <td><Badge variant="info" icon="diagram-3">2 Frameworks</Badge></td>
              <td><Severity level="high" /></td>
              <td><Badge variant="warning" icon="hourglass-split">In Arbeit</Badge></td>
              <td><span style={{color:'#f59e0b',fontFamily:'var(--font-mono)',fontSize:12}}>3/6 Belege</span></td>
            </tr>
            <tr>
              <td><span className="ctrl-code">A.6.3</span></td>
              <td><span className="ctrl-name">Bewusstseinsbildung &amp; Schulung</span></td>
              <td>Personen</td>
              <td><Badge variant="info" icon="diagram-3">4 Frameworks</Badge></td>
              <td><Severity level="medium" /></td>
              <td><Badge variant="info" icon="clock">Geplant</Badge></td>
              <td><span style={{color:'var(--fg-3)',fontFamily:'var(--font-mono)',fontSize:12}}>0 Belege</span></td>
            </tr>
            <tr>
              <td><span className="ctrl-code">A.7.4</span></td>
              <td><span className="ctrl-name">Physische Sicherheitsüberwachung</span></td>
              <td>Physisch</td>
              <td><Badge variant="info" icon="diagram-3">1 Framework</Badge></td>
              <td><Severity level="low" /></td>
              <td><Badge variant="success" icon="check2">Umgesetzt</Badge></td>
              <td><span style={{color:'#10b981',fontFamily:'var(--font-mono)',fontSize:12}}>4 Belege</span></td>
            </tr>
            <tr style={{background:'rgba(239,68,68,0.04)'}}>
              <td><span className="ctrl-code">A.8.7</span></td>
              <td>
                <span className="ctrl-name">
                  Schutz vor Schadsoftware
                </span>
                <span className="ctrl-clause" style={{color:'#ef4444'}}>Überfällig seit 14.04.2026</span>
              </td>
              <td>Technisch</td>
              <td><Badge variant="info" icon="diagram-3">3 Frameworks</Badge></td>
              <td><Severity level="critical" /></td>
              <td><Badge variant="danger" icon="x-circle">Überfällig</Badge></td>
              <td><span style={{color:'#ef4444',fontFamily:'var(--font-mono)',fontSize:12}}>1/4 Belege</span></td>
            </tr>
            <tr>
              <td><span className="ctrl-code">A.8.28</span></td>
              <td><span className="ctrl-name">Sicheres Coden</span></td>
              <td>Technisch</td>
              <td><Badge variant="info" icon="diagram-3">2 Frameworks</Badge></td>
              <td><Severity level="high" /></td>
              <td><Badge variant="info" icon="clipboard-check">Review</Badge></td>
              <td><span style={{color:'#06b6d4',fontFamily:'var(--font-mono)',fontSize:12}}>5 Belege</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  );
}

window.RisksPage = RisksPage;
