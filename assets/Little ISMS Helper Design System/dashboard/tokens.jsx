/* global React */
// Aurora Dark tokens — extracted from explorations/Variant.jsx
const T = {
  bg: '#0a0e1a', surface: '#141829', surface2: '#1e2139', surface3: '#252a43',
  border: '#232845', borderStrong: '#3d4270',
  fg: '#e9eaf5', fg2: '#b9bad4', fg3: '#6d6f99',
  primary: '#38bdf8', primaryHover: '#7dd3fc', primaryGlow: 'rgba(56,189,248,0.3)',
  accent: '#a78bfa', accentGlow: 'rgba(167,139,250,0.3)',
  success: '#34d399', warning: '#fbbf24', danger: '#f87171',
  fairyTokens: { primary: '#38bdf8', accent: '#a78bfa', aura: '#6366f1' },
};

const FONT_SANS = '"Inter","Space Grotesk",-apple-system,BlinkMacSystemFont,"Segoe UI",system-ui,sans-serif';
const FONT_MONO = '"JetBrains Mono","SF Mono",Monaco,Menlo,Consolas,monospace';

Object.assign(window, { T, FONT_SANS, FONT_MONO });
