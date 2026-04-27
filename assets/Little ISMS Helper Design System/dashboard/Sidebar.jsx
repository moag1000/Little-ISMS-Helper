/* global React, T, FONT_SANS, FONT_MONO, Icon */

const NAV_ITEMS = [
  { id: 'cockpit', label: 'Cockpit', icon: 'dashboard', active: true },
  { id: 'controls', label: 'Controls', icon: 'shield', badge: '12' },
  { id: 'assets', label: 'Assets', icon: 'box' },
  { id: 'risks', label: 'Risiken', icon: 'warn', badge: '3' },
  { id: 'audits', label: 'Audits', icon: 'audit' },
  { id: 'policies', label: 'Richtlinien', icon: 'policy' },
  { id: 'training', label: 'Schulungen', icon: 'training' },
  { id: 'suppliers', label: 'Lieferanten', icon: 'suppliers' },
  { id: 'reports', label: 'Berichte', icon: 'report' },
];

function Sidebar() {
  return (
    <aside style={{
      width: 224, background: T.surface, borderRight: `1px solid ${T.border}`,
      display:'flex', flexDirection:'column', flexShrink: 0,
    }}>
      {/* Logo */}
      <div style={{
        padding: '16px 18px', borderBottom: `1px solid ${T.border}`,
        display:'flex', alignItems:'center', gap: 10,
      }}>
        <div style={{
          width: 28, height: 28, borderRadius: 6,
          background: `linear-gradient(135deg, ${T.primary}, ${T.accent})`,
          display:'flex', alignItems:'center', justifyContent:'center',
          boxShadow: `0 0 12px ${T.primaryGlow}`,
        }}>
          <span style={{color:'#04121a', fontSize: 14}}>✦</span>
        </div>
        <div>
          <div style={{color: T.fg, fontSize: 14, fontWeight: 600, letterSpacing: -0.2}}>Alvara</div>
          <div style={{color: T.fg3, fontSize: 10, fontFamily: FONT_MONO, letterSpacing: '0.08em', textTransform:'uppercase'}}>ISMS · v3.4</div>
        </div>
      </div>

      {/* Org */}
      <button style={{
        margin: 10, padding: '10px 12px', background: T.surface2,
        border: `1px solid ${T.border}`, borderRadius: 6, cursor: 'pointer',
        display:'flex', alignItems:'center', gap: 10, textAlign:'left',
        color: T.fg, fontFamily: FONT_SANS, fontSize: 12,
      }}>
        <div style={{
          width: 22, height: 22, borderRadius: 4, background: T.surface3,
          display:'flex', alignItems:'center', justifyContent:'center',
          color: T.primary, fontSize: 10, fontWeight: 700,
        }}>MG</div>
        <div style={{flex: 1, minWidth: 0}}>
          <div style={{fontWeight: 500, overflow:'hidden', textOverflow:'ellipsis', whiteSpace:'nowrap'}}>Mustermann GmbH</div>
          <div style={{color: T.fg3, fontSize: 10}}>ISO 27001 · NIS-2</div>
        </div>
        <Icon name="chevron" size={12} style={{color: T.fg3}} />
      </button>

      {/* Nav */}
      <nav style={{flex: 1, padding: '4px 6px', overflowY: 'auto'}}>
        {NAV_ITEMS.map(item => (
          <a key={item.id} href="#" style={{
            display:'flex', alignItems:'center', gap: 10,
            padding: '8px 12px', margin: '1px 0',
            borderRadius: 5, textDecoration:'none',
            color: item.active ? T.fg : T.fg2,
            background: item.active ? `linear-gradient(90deg, ${T.primary}20, transparent)` : 'transparent',
            borderLeft: item.active ? `2px solid ${T.primary}` : '2px solid transparent',
            fontSize: 13, fontFamily: FONT_SANS, fontWeight: item.active ? 500 : 400,
            position:'relative',
          }}>
            <Icon name={item.icon} size={15} style={{color: item.active ? T.primary : T.fg3, flexShrink: 0}} />
            <span style={{flex: 1}}>{item.label}</span>
            {item.badge && (
              <span style={{
                fontSize: 10, padding: '1px 6px', borderRadius: 8,
                background: item.id === 'risks' ? `${T.warning}20` : `${T.fg3}20`,
                color: item.id === 'risks' ? T.warning : T.fg2,
                fontFamily: FONT_MONO, fontWeight: 600,
              }}>{item.badge}</span>
            )}
          </a>
        ))}
      </nav>

      {/* Footer */}
      <div style={{
        padding: '8px 6px', borderTop: `1px solid ${T.border}`,
        display:'flex', flexDirection:'column', gap: 1,
      }}>
        {[
          { icon: 'settings', label: 'Einstellungen' },
          { icon: 'help', label: 'Hilfe · Docs' },
        ].map(item => (
          <a key={item.label} href="#" style={{
            display:'flex', alignItems:'center', gap: 10,
            padding: '8px 12px', borderRadius: 5, textDecoration:'none',
            color: T.fg2, fontSize: 12, fontFamily: FONT_SANS,
          }}>
            <Icon name={item.icon} size={14} style={{color: T.fg3}} />
            <span>{item.label}</span>
          </a>
        ))}
      </div>
    </aside>
  );
}

Object.assign(window, { Sidebar });
