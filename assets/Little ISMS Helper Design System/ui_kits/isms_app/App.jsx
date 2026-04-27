/* global React, ReactDOM, Header, Sidebar, Dashboard, RisksPage, ControlDrawer, Button */

const { useState, useEffect } = React;

function SignIn({ onSignIn }) {
  return (
    <div className="signin">
      <div className="signin-card">
        <img src="../../assets/logo.svg" alt="Little ISMS Helper" />
        <h2>Little ISMS Helper</h2>
        <div className="sub">ISO 27001 Companion · Tenant: <span style={{color:'#06b6d4',fontFamily:'var(--font-mono)'}}>acme-gmbh</span></div>
        <div className="signin-field">
          <label>E-Mail</label>
          <input type="email" defaultValue="m.schubert@acme.de" />
        </div>
        <div className="signin-field">
          <label>Passwort</label>
          <input type="password" defaultValue="••••••••••••" />
        </div>
        <Button variant="primary" icon="shield-lock" onClick={onSignIn}>Anmelden</Button>
        <div className="hint">› verschlüsselt über TLS 1.3 · 2FA aktiv</div>
      </div>
    </div>
  );
}

function App() {
  const [theme, setTheme] = useState('dark');
  const [signedIn, setSignedIn] = useState(false);
  const [page, setPage] = useState('dashboard');
  const [drawerOpen, setDrawerOpen] = useState(false);

  useEffect(() => {
    document.documentElement.setAttribute('data-theme', theme);
  }, [theme]);

  if (!signedIn) return <SignIn onSignIn={() => setSignedIn(true)} />;

  return (
    <>
      <Header
        theme={theme}
        onToggleTheme={() => setTheme(theme === 'dark' ? 'light' : 'dark')}
        onSignOut={() => setSignedIn(false)}
      />
      <div className="app-body">
        <Sidebar active={page} onNavigate={setPage} />
        <main className="app-main">
          {(page === 'dashboard') && <Dashboard onOpenControl={() => setDrawerOpen(true)} />}
          {(page === 'risks' || page === 'controls') && <RisksPage onOpenControl={() => setDrawerOpen(true)} />}
          {!['dashboard','risks','controls'].includes(page) && (
            <div className="widget" style={{textAlign:'center',padding:'60px 20px'}}>
              <div style={{fontSize:40,marginBottom:12,opacity:.4}}>✨</div>
              <h3 style={{fontSize:18,fontWeight:600,marginBottom:6}}>Diese Ansicht ist Teil des vollen Tools</h3>
              <div style={{color:'var(--fg-3)',fontSize:13,fontFamily:'var(--font-mono)'}}>
                Nur Dashboard, Risiken und Controls sind in dieser Demo voll ausgebaut.
              </div>
            </div>
          )}
        </main>
      </div>
      <ControlDrawer open={drawerOpen} onClose={() => setDrawerOpen(false)} />
    </>
  );
}

ReactDOM.createRoot(document.getElementById('root')).render(<App />);
