/* global React */
// Shared UI primitives for the ISMS app kit

const { useState, useEffect } = React;

/* =========================================================
   FAIRY ICON — reusable sparkle / mascot
   ========================================================= */
function FairySpark({ size = 14, style = {} }) {
  return (
    <span
      style={{
        display: 'inline-flex',
        color: '#ec4899',
        textShadow: '0 0 8px rgba(236,72,153,0.6)',
        animation: 'fairy-pulse 2.5s ease-in-out infinite',
        fontSize: size,
        ...style,
      }}
    >
      ✦
    </span>
  );
}

/* =========================================================
   HEADER
   ========================================================= */
function Header({ onToggleTheme, theme, onSignOut, onOpenCmdK }) {
  return (
    <header className="app-header">
      <img src="../../assets/logo.svg" alt="Little ISMS Helper" className="logo" />
      <div className="brand">
        Little ISMS Helper
        <small>ISO 27001 Companion</small>
      </div>
      <div className="search" onClick={onOpenCmdK}>
        <i className="bi bi-search" />
        <span>Controls, Risiken, Dokumente durchsuchen …</span>
        <kbd>⌘ K</kbd>
      </div>
      <div className="header-actions">
        <button className="hdr-btn" title="Notifications">
          <i className="bi bi-bell" />
        </button>
        <button className="theme-toggle" title="Theme umschalten" onClick={onToggleTheme}>
          <i className={theme === 'dark' ? 'bi bi-sun' : 'bi bi-moon-stars'} />
        </button>
        <div className="avatar" title="Dr. M. Schubert — CISO">MS</div>
        <button className="hdr-btn" title="Abmelden" onClick={onSignOut}>
          <i className="bi bi-box-arrow-right" />
        </button>
      </div>
    </header>
  );
}

/* =========================================================
   SIDEBAR NAV
   ========================================================= */
function Sidebar({ active, onNavigate }) {
  const items = [
    { g: 'Übersicht', rows: [
      { id: 'dashboard', icon: 'speedometer2', label: 'Dashboard' },
      { id: 'reports',   icon: 'graph-up',    label: 'Reports' },
    ]},
    { g: 'ISMS', rows: [
      { id: 'controls', icon: 'shield-check',  label: 'Controls',       count: 93 },
      { id: 'risks',    icon: 'exclamation-triangle', label: 'Risiken', count: 24 },
      { id: 'assets',   icon: 'hdd-stack',     label: 'Assets',         count: 147 },
      { id: 'docs',     icon: 'file-earmark-text', label: 'Dokumente', count: 58 },
    ]},
    { g: 'Betrieb', rows: [
      { id: 'audits',    icon: 'clipboard-check', label: 'Audits' },
      { id: 'incidents', icon: 'shield-exclamation', label: 'Incidents', count: 3 },
      { id: 'training',  icon: 'mortarboard',    label: 'Schulungen' },
    ]},
    { g: 'System', rows: [
      { id: 'users',    icon: 'people',    label: 'Benutzer' },
      { id: 'settings', icon: 'gear',      label: 'Einstellungen' },
    ]},
  ];

  return (
    <aside className="app-nav">
      {items.map((group, gi) => (
        <div key={gi} className={gi === 0 ? '' : 'nav-group'}>
          <div className="nav-group-label">{group.g}</div>
          {group.rows.map((r) => (
            <a
              key={r.id}
              className={'nav-item' + (active === r.id ? ' active' : '')}
              onClick={(e) => { e.preventDefault(); onNavigate && onNavigate(r.id); }}
            >
              <i className={`bi bi-${r.icon}`} />
              <span>{r.label}</span>
              {r.count ? <span className="count">{r.count}</span> : null}
            </a>
          ))}
        </div>
      ))}
    </aside>
  );
}

/* =========================================================
   BADGE
   ========================================================= */
function Badge({ children, variant = 'neutral', icon }) {
  return (
    <span className={`badge bg-${variant}`}>
      {icon && <i className={`bi bi-${icon}`} />}
      {children}
    </span>
  );
}
function Severity({ level }) {
  const label = { critical: 'KRITISCH', high: 'HOCH', medium: 'MITTEL', low: 'NIEDRIG' }[level] || level;
  return <span className={`sev sev-${level}`}>{label}</span>;
}

/* =========================================================
   STAT CARD
   ========================================================= */
function Stat({ icon, iconVariant = 'i-cyan', title, value, sub, subClass = '' }) {
  return (
    <div className="stat">
      <div className={`icon ${iconVariant}`}>
        <i className={`bi bi-${icon}`} />
      </div>
      <div className="title">{title}</div>
      <div className="val">{value}</div>
      {sub && <div className={`sub ${subClass}`}>{sub}</div>}
    </div>
  );
}

/* =========================================================
   BUTTON
   ========================================================= */
function Button({ children, variant = 'primary', size, icon, onClick }) {
  const cls = `btn btn-${variant}` + (size === 'sm' ? ' btn-sm' : '');
  return (
    <button className={cls} onClick={onClick}>
      {icon && <i className={`bi bi-${icon}`} />}
      {children}
    </button>
  );
}

/* Export to window for cross-file use */
Object.assign(window, {
  FairySpark, Header, Sidebar, Badge, Severity, Stat, Button,
});
