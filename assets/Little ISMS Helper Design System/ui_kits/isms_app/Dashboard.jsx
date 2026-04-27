/* global React, Stat, Severity, Badge, Button, FairySpark */

const { useState: useStateD } = React;

function Dashboard({ onOpenControl }) {
  return (
    <div>
      <div className="page-head">
        <div>
          <div className="eyebrow">› CISO / Übersicht</div>
          <h1>Informationssicherheit — Dashboard</h1>
          <div className="sub">Q4 2025 · Zertifizierungsaudit in 47 Tagen · letzter Sync 08:42</div>
        </div>
        <div style={{display:'flex',gap:8}}>
          <Button variant="secondary" icon="download">Report exportieren</Button>
          <Button variant="primary" icon="plus-lg">Neues Risiko</Button>
        </div>
      </div>

      <div className="fairy-suggestion">
        <FairySpark size={16} />
        <span>
          Die ISMS-Fee hat bemerkt: <b>3 Controls ohne Evidence</b> &nbsp;·&nbsp; A.5.16, A.8.7, A.8.28 benötigen Belege vor dem Audit am 06.12. &nbsp;·&nbsp; <b>Jetzt zuweisen →</b>
        </span>
      </div>

      <div className="stats">
        <Stat icon="shield-check" iconVariant="i-cyan"  title="Reifegrad ISMS" value="87 %" sub="↑ 4 % vs. Vormonat" subClass="up" />
        <Stat icon="check2-circle" iconVariant="i-green" title="Controls umgesetzt" value="84 / 93" sub="9 offen · 2 überfällig" subClass="down" />
        <Stat icon="exclamation-triangle" iconVariant="i-amber" title="Offene Risiken" value="24" sub="3 kritisch · 7 hoch" subClass="down" />
        <Stat icon="stars" iconVariant="i-pink" title="Fee-Vorschläge" value="12" sub="neu in dieser Woche" subClass="pink" />
      </div>

      <div className="grid-2">
        <div className="widget">
          <div className="widget-head">
            <h3><i className="bi bi-bar-chart-line" />Compliance-Fortschritt nach Framework</h3>
            <Button variant="ghost" size="sm" icon="arrow-right">Details</Button>
          </div>
          <div className="prog-row">
            <div className="framework">ISO 27001:2022<small>Annex A (93 controls)</small></div>
            <div className="prog-track"><div className="prog-bar" style={{width:'90%'}} /></div>
            <div className="prog-pct">90%</div>
          </div>
          <div className="prog-row">
            <div className="framework">ISO 27002:2022<small>Guidance-Mapping</small></div>
            <div className="prog-track"><div className="prog-bar cyan" style={{width:'76%'}} /></div>
            <div className="prog-pct">76%</div>
          </div>
          <div className="prog-row">
            <div className="framework">BSI IT-Grundschutz<small>Bausteine Kern</small></div>
            <div className="prog-track"><div className="prog-bar warn" style={{width:'52%'}} /></div>
            <div className="prog-pct">52%</div>
          </div>
          <div className="prog-row">
            <div className="framework">NIS-2 Richtlinie<small>Art. 21 Maßnahmen</small></div>
            <div className="prog-track"><div className="prog-bar cyan" style={{width:'68%'}} /></div>
            <div className="prog-pct">68%</div>
          </div>
          <div className="prog-row">
            <div className="framework">TISAX AL3<small>Prototypenschutz</small></div>
            <div className="prog-track"><div className="prog-bar warn" style={{width:'41%'}} /></div>
            <div className="prog-pct">41%</div>
          </div>
        </div>

        <div className="widget">
          <div className="widget-head">
            <h3><i className="bi bi-activity" />Audit-Log</h3>
            <Button variant="ghost" size="sm">Alle</Button>
          </div>
          <div className="feed-item">
            <div className="feed-avatar">MS</div>
            <div className="feed-body">
              <b>M. Schubert</b> hat Evidence zu <span style={{color:'#06b6d4',fontFamily:'var(--font-mono)'}}>A.5.15</span> hochgeladen
              <div className="meta">vor 12 Min · Zugriffskontrolle</div>
            </div>
          </div>
          <div className="feed-item">
            <div className="feed-avatar" style={{background:'linear-gradient(135deg,#ec4899,#db2777)'}}>✦</div>
            <div className="feed-body">
              <span className="fresh">Fee-Vorschlag:</span> SoA um 4 neue Controls ergänzen
              <div className="meta">vor 34 Min · automatisch</div>
            </div>
          </div>
          <div className="feed-item">
            <div className="feed-avatar" style={{background:'linear-gradient(135deg,#10b981,#059669)'}}>JK</div>
            <div className="feed-body">
              <b>J. Krämer</b> Review abgeschlossen: <span style={{color:'#06b6d4',fontFamily:'var(--font-mono)'}}>R-2025-014</span>
              <div className="meta">vor 1 Std · Risiko-Register</div>
            </div>
          </div>
          <div className="feed-item">
            <div className="feed-avatar" style={{background:'linear-gradient(135deg,#f59e0b,#d97706)',color:'#0f172a'}}>SK</div>
            <div className="feed-body">
              <b>S. Klein</b> Incident <span style={{color:'#ef4444',fontFamily:'var(--font-mono)'}}>INC-088</span> eskaliert
              <div className="meta">vor 2 Std · Major</div>
            </div>
          </div>
          <div className="feed-item">
            <div className="feed-avatar">TH</div>
            <div className="feed-body">
              <b>T. Habermann</b> Schulung "Phishing Q4" veröffentlicht
              <div className="meta">vor 4 Std · 142 Empfänger</div>
            </div>
          </div>
        </div>
      </div>

      <div className="widget">
        <div className="widget-head">
          <h3><i className="bi bi-diagram-3" />Top offene Controls</h3>
          <div style={{display:'flex',gap:12,alignItems:'center'}}>
            <div className="tabs" style={{marginBottom:0,borderBottom:'none'}}>
              <div className="tab active">Alle (9)</div>
              <div className="tab">Kritisch (3)</div>
              <div className="tab">Fee-Hinweise (4)</div>
            </div>
          </div>
        </div>
        <table className="data">
          <thead>
            <tr>
              <th style={{width:'85px'}}>Control</th>
              <th>Titel</th>
              <th style={{width:'130px'}}>Kategorie</th>
              <th style={{width:'110px'}}>Priorität</th>
              <th style={{width:'130px'}}>Owner</th>
              <th style={{width:'110px'}}>Status</th>
            </tr>
          </thead>
          <tbody>
            <tr onClick={onOpenControl} style={{cursor:'pointer'}}>
              <td><span className="ctrl-code">A.5.16</span></td>
              <td><span className="ctrl-name">Identitätsmanagement</span><span className="ctrl-clause">ISO 27001 · A.5.16 · ISO 27002 §5.16</span></td>
              <td>Organisatorisch</td>
              <td><Severity level="critical" /></td>
              <td>M. Schubert</td>
              <td><Badge variant="warning" icon="hourglass-split">In Arbeit</Badge></td>
            </tr>
            <tr>
              <td><span className="ctrl-code">A.8.7</span></td>
              <td><span className="ctrl-name">Schutz vor Schadsoftware</span><span className="ctrl-clause">ISO 27001 · A.8.7 · NIS-2 Art. 21(2)(e)</span></td>
              <td>Technisch</td>
              <td><Severity level="critical" /></td>
              <td>S. Klein</td>
              <td><Badge variant="danger" icon="x-circle">Überfällig</Badge></td>
            </tr>
            <tr>
              <td><span className="ctrl-code">A.8.28</span></td>
              <td><span className="ctrl-name">Sicheres Coden</span><span className="ctrl-clause">ISO 27001 · A.8.28 · TISAX 4.2.1</span></td>
              <td>Technisch</td>
              <td><Severity level="high" /></td>
              <td>T. Habermann</td>
              <td><Badge variant="info" icon="clipboard-check">Review</Badge></td>
            </tr>
            <tr>
              <td><span className="ctrl-code">A.5.23</span></td>
              <td><span className="ctrl-name">InfoSec für Cloud-Dienste</span><span className="ctrl-clause">ISO 27001 · A.5.23</span></td>
              <td>Organisatorisch</td>
              <td><Severity level="high" /></td>
              <td>J. Krämer</td>
              <td><Badge variant="warning" icon="hourglass-split">In Arbeit</Badge></td>
            </tr>
            <tr>
              <td><span className="ctrl-code">A.6.3</span></td>
              <td><span className="ctrl-name">Bewusstseinsbildung &amp; Schulung</span><span className="ctrl-clause">ISO 27001 · A.6.3</span></td>
              <td>Personen</td>
              <td><Severity level="medium" /></td>
              <td>T. Habermann</td>
              <td><Badge variant="info" icon="clock">Geplant</Badge></td>
            </tr>
            <tr>
              <td><span className="ctrl-code">A.7.4</span></td>
              <td><span className="ctrl-name">Physische Sicherheitsüberwachung</span><span className="ctrl-clause">ISO 27001 · A.7.4</span></td>
              <td>Physisch</td>
              <td><Severity level="low" /></td>
              <td>Facility</td>
              <td><Badge variant="success" icon="check2">Umgesetzt</Badge></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  );
}

window.Dashboard = Dashboard;
