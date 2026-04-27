/* global React, T, FONT_SANS, FONT_MONO, Icon, Sidebar, Topbar, KpiRow, ControlHeatmap, ActivityFeed, TaskQueue, FairyPanel */

function Dashboard() {
  return (
    <div style={{
      width: '100%', minWidth: 1440, height: '100vh', minHeight: 720, overflow: 'hidden',
      background: T.bg, color: T.fg, fontFamily: FONT_SANS,
      display: 'flex',
    }}>
      <Sidebar />

      {/* Center column */}
      <div style={{flex: 1, display:'flex', flexDirection:'column', minWidth: 0, overflow:'hidden'}}>
        <Topbar />

        <main style={{
          flex: 1, overflow:'auto', padding: '20px 24px',
          display:'flex', flexDirection:'column', gap: 16,
        }}>
          {/* Greeting */}
          <div style={{display:'flex', alignItems:'flex-end', justifyContent:'space-between', gap: 16}}>
            <div>
              <div style={{fontSize: 22, fontWeight: 600, color: T.fg, letterSpacing: -0.4, fontFamily: FONT_SANS}}>
                Guten Morgen, Anna.
              </div>
              <div style={{color: T.fg3, fontSize: 12, fontFamily: FONT_MONO, letterSpacing: '0.04em', marginTop: 2}}>
                Montag · 27.04.2026 · Kalenderwoche 18
              </div>
            </div>
            <div style={{display:'flex', gap: 8}}>
              <button style={{
                padding:'7px 12px', fontFamily: FONT_MONO, fontSize: 11, letterSpacing:'0.08em', textTransform:'uppercase',
                background: 'transparent', border:`1px solid ${T.borderStrong}`, borderRadius: 3, color: T.fg2,
                cursor:'pointer', display:'inline-flex', alignItems:'center', gap: 6,
              }}>
                <Icon name="report" size={12} />
                Bericht
              </button>
              <button style={{
                padding:'7px 14px', fontFamily: FONT_MONO, fontSize: 11, letterSpacing:'0.08em', textTransform:'uppercase', fontWeight: 600,
                background: `linear-gradient(135deg, ${T.primary}, ${T.primaryHover})`, border:'none',
                color:'#04121a', cursor:'pointer',
                clipPath: 'polygon(6px 0, 100% 0, 100% calc(100% - 6px), calc(100% - 6px) 100%, 0 100%, 0 6px)',
                boxShadow: `0 0 10px ${T.primaryGlow}`,
                display:'inline-flex', alignItems:'center', gap: 6,
              }}>
                <span style={{width: 5, height: 5, borderRadius: '50%', background:'#04121a'}} />
                Neues Control
              </button>
            </div>
          </div>

          <KpiRow />

          <ControlHeatmap />

          <div style={{display:'grid', gridTemplateColumns:'1.5fr 1fr', gap: 16}}>
            <ActivityFeed />
            <TaskQueue />
          </div>
        </main>
      </div>

      <FairyPanel />
    </div>
  );
}

ReactDOM.createRoot(document.getElementById('root')).render(<Dashboard />);
