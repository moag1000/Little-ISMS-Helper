/* global React, T, FONT_SANS, FONT_MONO, Icon */

function Topbar() {
  return (
    <header style={{
      height: 52, background: T.surface, borderBottom: `1px solid ${T.border}`,
      display:'flex', alignItems:'center', padding: '0 20px', gap: 16, flexShrink: 0,
    }}>
      {/* Search */}
      <div style={{
        flex: 1, maxWidth: 520,
        display:'flex', alignItems:'center', gap: 8,
        padding: '6px 10px', background: T.bg,
        border: `1px solid ${T.border}`, borderRadius: 6,
      }}>
        <Icon name="search" size={14} style={{color: T.fg3}} />
        <input
          placeholder="Controls, Assets, Richtlinien durchsuchen…"
          style={{
            flex: 1, background:'transparent', border:'none', outline:'none',
            color: T.fg, fontFamily: FONT_SANS, fontSize: 12,
          }}
        />
        <span style={{
          fontFamily: FONT_MONO, fontSize: 10, color: T.fg3,
          border: `1px solid ${T.border}`, padding: '1px 5px', borderRadius: 3,
        }}>⌘K</span>
      </div>

      <div style={{flex: 1}} />

      {/* Status pill */}
      <div style={{
        display:'flex', alignItems:'center', gap: 6,
        padding: '4px 10px', background: `${T.success}15`, borderRadius: 12,
        border: `1px solid ${T.success}30`,
      }}>
        <span style={{width: 6, height: 6, borderRadius: '50%', background: T.success, boxShadow: `0 0 6px ${T.success}`}} />
        <span style={{color: T.success, fontFamily: FONT_MONO, fontSize: 10, letterSpacing:'0.08em', textTransform:'uppercase'}}>System · OK</span>
      </div>

      {/* Notifications */}
      <button style={{
        position: 'relative', width: 32, height: 32, borderRadius: 6,
        background: 'transparent', border: `1px solid ${T.border}`, cursor:'pointer',
        color: T.fg2, display:'flex', alignItems:'center', justifyContent:'center',
      }}>
        <Icon name="bell" size={15} />
        <span style={{
          position:'absolute', top: 4, right: 5, width: 7, height: 7,
          borderRadius: '50%', background: T.warning, boxShadow: `0 0 4px ${T.warning}`,
        }} />
      </button>

      {/* Avatar */}
      <button style={{
        display:'flex', alignItems:'center', gap: 8,
        padding: '3px 8px 3px 3px', background: T.surface2,
        border: `1px solid ${T.border}`, borderRadius: 20, cursor:'pointer',
      }}>
        <div style={{
          width: 26, height: 26, borderRadius: '50%',
          background: `linear-gradient(135deg, ${T.accent}, ${T.primary})`,
          display:'flex', alignItems:'center', justifyContent:'center',
          color:'#04121a', fontSize: 11, fontWeight: 700,
        }}>AM</div>
        <div style={{textAlign:'left'}}>
          <div style={{color: T.fg, fontSize: 11, fontFamily: FONT_SANS, fontWeight: 500, lineHeight: 1.2}}>Anna Meier</div>
          <div style={{color: T.fg3, fontSize: 9, fontFamily: FONT_MONO, letterSpacing: '0.06em', textTransform:'uppercase'}}>CISO</div>
        </div>
      </button>
    </header>
  );
}

Object.assign(window, { Topbar });
