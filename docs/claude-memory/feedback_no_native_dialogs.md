---
name: feedback_no_native_dialogs
description: Never use native alert()/confirm()/prompt() as UX — use Aurora faToast/faConfirm; native only as last-resort fallback
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 8524ab1e-7912-42ad-be81-bb1c9410a3e9
---

Native browser dialogs (`alert()`, `confirm()`, `prompt()`, `window.alert`,
`window.confirm`) are **banned as primary UX** — they're jarring and off-brand
("ist ja furchtbar"). Use the app's Aurora helpers instead:

- `window.faConfirm(message, { tone: 'info'|'warning'|'danger' })` → returns
  `Promise<boolean>` (Aurora modal). Replaces `confirm()`.
- `window.faToast(message, 'info'|'success'|'warning'|'danger')` → non-blocking
  Aurora toast. Replaces `alert()` for notices/errors.

**Native is allowed ONLY as a worst-case fallback** — when the global helper
isn't mounted yet:
```js
if (typeof window.faConfirm === 'function') { window.faConfirm(...).then(...); }
else { /* fallback */ }
```

**Why:** consistent Aurora look + non-blocking; native popups break the brand.

**How to apply:** when writing/reviewing any Stimulus controller or inline
script, grep for `alert(`/`confirm(`/`prompt(` and route through faToast/
faConfirm. Fixed in PR #822 (control-status confirm on /soa/edit,
lifecycle-dropdown errors). Related: [[feedback_alva_fairy_pattern]]
