import { describe, it, expect } from 'vitest';
import { escapeHtml, safeUrl } from '../../assets/js/fa-escape.js';

describe('fa-escape (S10)', () => {
    it('escapes markup characters', () => {
        expect(escapeHtml('<a data-action="x">&\'')).toBe('&lt;a data-action=&quot;x&quot;&gt;&amp;&#39;');
        expect(escapeHtml(null)).toBe('');
    });

    it('keeps relative and http(s) links, drops script URLs', () => {
        expect(safeUrl('/de/risk/1')).toBe('/de/risk/1');
        expect(safeUrl('https://example.org/?a=1&b=2')).toBe('https://example.org/?a=1&amp;b=2');
        expect(safeUrl('javascript:alert(1)')).toBe('');
        expect(safeUrl(' JaVaScRiPt:alert(1)')).toBe('');
        expect(safeUrl('data:text/html,x')).toBe('');
        expect(safeUrl('//cdn.example/x')).toBe('//cdn.example/x');
    });
});
