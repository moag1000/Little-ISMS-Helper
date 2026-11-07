import { Controller } from '@hotwired/stimulus';
import { faT } from '../js/fa-i18n.js';

/**
 * Location map ("Standort-Lagebild").
 *
 * Draws one pin per site on an Equal Earth world map (Natural Earth outlines,
 * assets/data/world-map.json, projected with the same constants). No tile
 * service, no external request.
 *
 *   size  = protection need (max C/I/A of the site's assets, 0–5)
 *   shape = band of the active layer (circle ok, triangle warning, diamond high)
 *   colour repeats the band; colour is never the only signal.
 *
 * Layers: risk, gaps, emergency (bcm), thirdcountry (privacy), audit. Each
 * site carries its band per layer in site.bands. The third-country layer also
 * fills the countries by GDPR transfer zone (EU/EEA, adequacy, USA/DPF hatched
 * dots, other third countries hatched) and draws one square per country where
 * suppliers or subcontractors process data; selecting a square opens the
 * country panel and draws the subcontractor chains of the suppliers there.
 *
 * Pins closer than CLUSTER_PX on screen merge into a numbered cluster that
 * zooms in on click. The server-rendered table below the map is the
 * accessible equivalent; rows and pins select each other. Panel content is
 * server-rendered per site in <template data-site-panel="ID">.
 *
 * URL state (replaceState): layer, focus, site, country.
 */
const A1 = 1.340264, A2 = -0.081106, A3 = 0.000893, A4 = 0.003796, EM = Math.sqrt(3) / 2;
const NS = 'http://www.w3.org/2000/svg';
const WIDTH = 1000;
const RADIUS = [4, 5, 6, 7, 9, 11];
const CLUSTER_PX = 22;
const BAND_RANK = { none: 0, ok: 0, low: 0, warn: 1, medium: 1, danger: 2, high: 2, critical: 3 };
// Layers where any warning already counts as "needs action".
const STRICT_LAYERS = ['emergency', 'thirdcountry', 'audit'];
const SUPPLIER_PX = 5;

// Literal keys, so the translation check finds every one of them.
function layerText(layer, band) {
    const texts = {
        emergency: {
            ok: () => faT('location_map.emergency_ok'),
            warn: () => faT('location_map.emergency_warn'),
            danger: () => faT('location_map.emergency_danger'),
            none: () => faT('location_map.emergency_none'),
        },
        audit: {
            ok: () => faT('location_map.audit_ok'),
            warn: () => faT('location_map.audit_warn'),
            danger: () => faT('location_map.audit_danger'),
            none: () => faT('location_map.audit_danger'),
        },
        thirdcountry: {
            ok: () => faT('location_map.thirdcountry_ok'),
            warn: () => faT('location_map.thirdcountry_warn'),
            danger: () => faT('location_map.thirdcountry_warn'),
            none: () => faT('location_map.thirdcountry_none'),
        },
    };
    return (texts[layer]?.[band] ?? texts[layer]?.none)?.() ?? '';
}
const SHAPE = ['circle', 'triangle', 'diamond', 'diamond'];
const TONE = ['ok', 'warn', 'danger', 'crit'];

function raw(lon, lat) {
    const l = lon * Math.PI / 180;
    const t = Math.asin(EM * Math.sin(lat * Math.PI / 180));
    const t2 = t * t, t6 = t2 * t2 * t2;
    return [
        l * Math.cos(t) / (EM * (A1 + 3 * A2 * t2 + t6 * (7 * A3 + 9 * A4 * t2))),
        t * (A1 + A2 * t2 + t6 * (A3 + A4 * t2)),
    ];
}
const XMAX = raw(180, 0)[0];
const SCALE = WIDTH / (2 * XMAX);
const TOP = raw(0, 84)[1];
const project = (lon, lat) => {
    const [x, y] = raw(lon, lat);
    return [(x + XMAX) * SCALE, (TOP - y) * SCALE];
};

export default class extends Controller {
    static targets = ['svg', 'land', 'borders', 'zones', 'chainLines', 'pins', 'suppliers', 'tip', 'panel', 'layerButton', 'focusToggle', 'row', 'legend', 'empty'];
    static values = {
        sites: Array,
        worldUrl: String,
        layer: { type: String, default: 'risk' },
        layers: { type: Array, default: ['risk', 'gaps'] },
        zones: { type: Object, default: {} },
        countries: { type: Array, default: [] },
        chains: { type: Array, default: [] },
        focus: { type: Boolean, default: false },
        selected: { type: Number, default: 0 },
    };

    connect() {
        const params = new URLSearchParams(window.location.search);
        if (this.layersValue.includes(params.get('layer'))) this.layerValue = params.get('layer');
        if (params.has('focus')) this.focusValue = params.get('focus') === '1';
        if (params.get('site')) this.selectedValue = parseInt(params.get('site'), 10) || 0;

        this.points = this.sitesValue
            .filter((s) => s.lat !== null && s.lng !== null)
            .map((s) => ({ site: s, xy: project(s.lng, s.lat) }));
        this.countryPoints = this.countriesValue.map((c) => ({ country: c, xy: project(c.lng, c.lat) }));
        this.countryXy = Object.fromEntries(this.countryPoints.map((p) => [p.country.code, p.xy]));
        this.selectedCountry = params.get('country') || '';
        this.full = [0, 0, WIDTH, (TOP - raw(0, -58)[1]) * SCALE];
        this.view = this.fitView(this.points.map((p) => p.xy));
        this.onResize = () => this.render();
        window.addEventListener('resize', this.onResize);
        this.bindPanZoom();
        this.loadWorld();
        this.syncControls();
        if (this.selectedValue) this.showPanel(this.selectedValue);
        else if (this.selectedCountry) this.showCountryPanel(this.selectedCountry);
        this.render();
    }

    disconnect() {
        window.removeEventListener('resize', this.onResize);
    }

    async loadWorld() {
        try {
            const res = await fetch(this.worldUrlValue, { credentials: 'same-origin' });
            const world = await res.json();
            this.landTarget.setAttribute('d', world.land);
            this.bordersTarget.setAttribute('d', world.grenzen);
            if (this.hasZonesTarget && world.laender) this.drawZones(world.laender);
        } catch (e) {
            // The pins and the table still work without outlines.
        }
    }

    // ---- actions ---------------------------------------------------------

    setLayer(event) {
        this.layerValue = event.currentTarget.dataset.layer;
        if (this.layerValue !== 'thirdcountry' && this.selectedCountry) this.selectCountry('');
        this.syncControls();
        this.render();
        if (this.selectedValue) this.showPanel(this.selectedValue);
    }

    toggleFocus() {
        this.focusValue = this.focusToggleTarget.checked;
        this.syncControls();
        this.render();
    }

    zoomIn() { this.zoomBy(0.5); }
    zoomOut() { this.zoomBy(2); }

    reset() {
        this.view = this.fitView(this.points.map((p) => p.xy));
        this.render();
    }

    world() {
        this.view = this.full.slice();
        this.render();
    }

    selectRow(event) {
        if (event.target.closest('a, button, input')) return;
        const id = parseInt(event.currentTarget.dataset.siteId, 10);
        const point = this.points.find((p) => p.site.id === id);
        if (point) this.centerOn(point.xy);
        this.select(id);
    }

    closePanel() {
        if (this.selectedCountry) this.selectCountry('');
        this.select(0);
    }

    // ---- state -----------------------------------------------------------

    bandKey(site) {
        return (site.bands || {})[this.layerValue] ?? (this.layerValue === 'gaps' ? site.gapBand : site.riskBand);
    }

    band(site) {
        return BAND_RANK[this.bandKey(site)] ?? 0;
    }

    needsAction(site) {
        if (STRICT_LAYERS.includes(this.layerValue)) return this.band(site) >= 1;
        return this.band(site) >= 2 || (this.layerValue === 'gaps' && site.gaps.length > 0);
    }

    visible(site) {
        return !this.focusValue || this.needsAction(site);
    }

    select(id) {
        if (id && this.selectedCountry) this.selectedCountry = '';
        this.selectedValue = id;
        this.writeUrl();
        // Panel first: it narrows the map, and pin sizes depend on the width.
        if (id) this.showPanel(id); else this.hidePanel();
        this.render();
    }

    syncControls() {
        this.layerButtonTargets.forEach((b) => {
            const active = b.dataset.layer === this.layerValue;
            b.classList.toggle('fa-filter-chip--active', active);
            b.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        if (this.hasFocusToggleTarget) this.focusToggleTarget.checked = this.focusValue;
        this.legendTargets.forEach((l) => { l.hidden = l.dataset.layer !== this.layerValue; });
        // Table columns and sections that belong to one layer.
        this.element.querySelectorAll('[data-layer-only]').forEach((el) => {
            if (!el.closest('[data-location-map-target="panel"]')) el.hidden = el.dataset.layerOnly !== this.layerValue;
        });
        if (this.hasZonesTarget) this.zonesTarget.toggleAttribute('hidden', this.layerValue !== 'thirdcountry');
        this.rowTargets.forEach((row) => {
            const site = this.sitesValue.find((s) => s.id === parseInt(row.dataset.siteId, 10));
            row.hidden = site ? !this.visible(site) : false;
        });
        if (this.hasEmptyTarget) {
            this.emptyTarget.hidden = !this.focusValue || this.sitesValue.some((s) => this.needsAction(s));
        }
        this.writeUrl();
    }

    writeUrl() {
        const url = new URL(window.location.href);
        url.searchParams.set('layer', this.layerValue);
        url.searchParams.set('focus', this.focusValue ? '1' : '0');
        if (this.selectedValue) url.searchParams.set('site', String(this.selectedValue));
        else url.searchParams.delete('site');
        if (this.selectedCountry) url.searchParams.set('country', this.selectedCountry);
        else url.searchParams.delete('country');
        window.history.replaceState(window.history.state, '', url);
    }

    // ---- view box --------------------------------------------------------

    fitView(points) {
        if (points.length === 0) return this.full.slice();
        const xs = points.map((p) => p[0]), ys = points.map((p) => p[1]);
        let [x0, x1, y0, y1] = [Math.min(...xs), Math.max(...xs), Math.min(...ys), Math.max(...ys)];
        const minW = 60;
        let w = Math.max(x1 - x0, minW) * 1.3;
        let h = Math.max(y1 - y0, minW * 0.44) * 1.3;
        const aspect = this.full[3] / this.full[2];
        if (h / w > aspect) w = h / aspect; else h = w * aspect;
        const cx = (x0 + x1) / 2, cy = (y0 + y1) / 2;
        return this.clamp([cx - w / 2, cy - h / 2, w, h]);
    }

    clamp([x, y, w, h]) {
        const [fx, fy, fw, fh] = this.full;
        w = Math.min(Math.max(w, 12), fw);
        h = w * (fh / fw);
        x = Math.min(Math.max(x, fx), fx + fw - w);
        y = Math.min(Math.max(y, fy), fy + fh - h);
        return [x, y, w, h];
    }

    // Drag to pan (mouse, pen, one finger), Ctrl/⌘ + wheel or pinch-like
    // wheel to zoom around the pointer. Plain wheel scrolls the page.
    bindPanZoom() {
        const svg = this.svgTarget;
        let drag = null;
        svg.addEventListener('pointerdown', (e) => {
            if (e.button !== 0 || e.target.closest('.location-map__pin, .location-map__cluster, .location-map__supplier')) return;
            drag = { x: e.clientX, y: e.clientY, view: this.view.slice(), moved: false };
            svg.setPointerCapture(e.pointerId);
        });
        svg.addEventListener('pointermove', (e) => {
            if (!drag) return;
            const dx = e.clientX - drag.x, dy = e.clientY - drag.y;
            if (!drag.moved && Math.hypot(dx, dy) < 4) return;
            drag.moved = true;
            svg.classList.add('is-panning');
            const unit = drag.view[2] / (svg.clientWidth || WIDTH);
            this.view = this.clamp([drag.view[0] - dx * unit, drag.view[1] - dy * unit, drag.view[2], drag.view[3]]);
            this.render();
        });
        const end = () => { drag = null; svg.classList.remove('is-panning'); };
        svg.addEventListener('pointerup', end);
        svg.addEventListener('pointercancel', end);
        svg.addEventListener('wheel', (e) => {
            if (!e.ctrlKey && !e.metaKey) return;
            e.preventDefault();
            const rect = svg.getBoundingClientRect();
            const [x, y, w, h] = this.view;
            const px = x + ((e.clientX - rect.left) / rect.width) * w;
            const py = y + ((e.clientY - rect.top) / rect.height) * h;
            const f = e.deltaY > 0 ? 1.25 : 0.8;
            this.view = this.clamp([px - (px - x) * f, py - (py - y) * f, w * f, h * f]);
            this.render();
        }, { passive: false });
    }

    zoomBy(factor) {
        const [x, y, w, h] = this.view;
        const cx = x + w / 2, cy = y + h / 2;
        this.view = this.clamp([cx - (w * factor) / 2, cy - (h * factor) / 2, w * factor, h * factor]);
        this.render();
    }

    centerOn([cx, cy]) {
        const [, , w, h] = this.view;
        this.view = this.clamp([cx - w / 2, cy - h / 2, w, h]);
    }

    // ---- drawing ---------------------------------------------------------

    render() {
        const svg = this.svgTarget;
        svg.setAttribute('viewBox', this.view.join(' '));
        const pxPerUnit = (svg.clientWidth || WIDTH) / this.view[2];
        const k = 1 / pxPerUnit;
        this.bordersTarget.style.strokeWidth = String(0.8 * k);
        this.landTarget.style.strokeWidth = String(0.6 * k);

        const group = this.pinsTarget;
        group.replaceChildren();
        const shown = this.points.filter((p) => this.visible(p.site));
        for (const cluster of this.cluster(shown, CLUSTER_PX * k)) {
            group.appendChild(cluster.members.length > 1 ? this.drawCluster(cluster, k) : this.drawPin(cluster.members[0], k));
        }
        this.drawSuppliers(k);
    }

    // ---- third-country layer ---------------------------------------------

    drawZones(paths) {
        const frag = document.createDocumentFragment();
        for (const [code, d] of Object.entries(paths)) {
            const el = document.createElementNS(NS, 'path');
            const zone = this.zonesValue[code] || 'third';
            el.setAttribute('d', d);
            el.setAttribute('class', `location-map__zone location-map__zone--${zone}`);
            el.dataset.country = code;
            frag.appendChild(el);
        }
        this.zonesTarget.replaceChildren(frag);
    }

    drawSuppliers(k) {
        if (!this.hasSuppliersTarget) return;
        const group = this.suppliersTarget;
        const lines = this.hasChainLinesTarget ? this.chainLinesTarget : null;
        group.replaceChildren();
        if (lines) lines.replaceChildren();
        if (this.layerValue !== 'thirdcountry') return;

        for (const { country, xy: [x, y] } of this.countryPoints) {
            const rank = BAND_RANK[country.band] ?? 0;
            const r = SUPPLIER_PX * k;
            const g = document.createElementNS(NS, 'g');
            g.setAttribute('class', `location-map__supplier location-map__pin--${country.band === 'none' ? 'none' : TONE[rank]}`
                + (country.code === this.selectedCountry ? ' is-selected' : ''));
            g.setAttribute('tabindex', '0');
            g.setAttribute('role', 'button');
            const label = faT('location_map.suppliers', { '%count%': country.entries.length, '%country%': country.code });
            g.setAttribute('aria-label', label);
            const sq = document.createElementNS(NS, 'rect');
            sq.setAttribute('x', x - r); sq.setAttribute('y', y - r);
            sq.setAttribute('width', 2 * r); sq.setAttribute('height', 2 * r);
            sq.style.strokeWidth = String(1.4 * k);
            g.appendChild(sq);
            const open = () => this.selectCountry(country.code === this.selectedCountry ? '' : country.code);
            g.addEventListener('click', open);
            g.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); } });
            g.addEventListener('mouseenter', () => this.showTipText(country.code, label, x, y));
            g.addEventListener('focus', () => this.showTipText(country.code, label, x, y));
            g.addEventListener('mouseleave', () => this.hideTip());
            g.addEventListener('blur', () => this.hideTip());
            group.appendChild(g);
        }

        // Chains of the suppliers in the selected country: one dashed line from
        // each own processing location to every subcontractor country.
        if (!lines || !this.selectedCountry) return;
        const here = this.countriesValue.find((c) => c.code === this.selectedCountry);
        const ids = new Set((here?.entries || []).map((e) => e.supplier));
        for (const chain of this.chainsValue.filter((c) => ids.has(c.id))) {
            for (const from of chain.locations) {
                const a = this.countryXy[from];
                for (const link of chain.chain) {
                    const b = this.countryXy[link.code];
                    if (!a || !b || link.code === from) continue;
                    const line = document.createElementNS(NS, 'line');
                    line.setAttribute('x1', a[0]); line.setAttribute('y1', a[1]);
                    line.setAttribute('x2', b[0]); line.setAttribute('y2', b[1]);
                    line.setAttribute('class', `location-map__chain location-map__chain--${link.band}`);
                    line.style.strokeWidth = String(1.4 * k);
                    line.style.strokeDasharray = `${4 * k} ${3 * k}`;
                    lines.appendChild(line);
                }
            }
        }
    }

    selectCountry(code) {
        this.selectedCountry = code;
        if (code) {
            this.selectedValue = 0;
            this.showCountryPanel(code);
        } else if (!this.selectedValue) {
            this.hidePanel();
        }
        this.writeUrl();
        this.render();
    }

    showCountryPanel(code) {
        const tpl = this.element.querySelector(`template[data-country-panel="${code}"]`);
        if (!tpl || !this.hasPanelTarget) return;
        const body = this.panelTarget.querySelector('[data-panel-body]');
        body.replaceChildren(tpl.content.cloneNode(true));
        this.panelTarget.hidden = false;
        this.rowTargets.forEach((row) => row.classList.remove('is-selected'));
    }

    cluster(points, distance) {
        const clusters = [];
        const ordered = [...points].sort((a, b) => this.band(b.site) - this.band(a.site));
        for (const point of ordered) {
            const hit = clusters.find((c) => Math.hypot(c.xy[0] - point.xy[0], c.xy[1] - point.xy[1]) < distance);
            if (hit) {
                hit.members.push(point);
                const n = hit.members.length;
                hit.xy = [hit.xy[0] + (point.xy[0] - hit.xy[0]) / n, hit.xy[1] + (point.xy[1] - hit.xy[1]) / n];
            } else {
                clusters.push({ xy: point.xy.slice(), members: [point] });
            }
        }
        return clusters;
    }

    shape(kind, x, y, r) {
        let el;
        if (kind === 'circle') {
            el = document.createElementNS(NS, 'circle');
            el.setAttribute('cx', x); el.setAttribute('cy', y); el.setAttribute('r', r);
        } else {
            el = document.createElementNS(NS, 'path');
            const d = kind === 'triangle'
                ? `M${x} ${y - r * 1.25}L${x + r * 1.15} ${y + r * 0.8}L${x - r * 1.15} ${y + r * 0.8}Z`
                : `M${x} ${y - r * 1.3}L${x + r * 1.3} ${y}L${x} ${y + r * 1.3}L${x - r * 1.3} ${y}Z`;
            el.setAttribute('d', d);
        }
        return el;
    }

    drawPin(point, k) {
        const { site, xy: [x, y] } = point;
        const rank = this.band(site);
        const neutral = this.bandKey(site) === 'none';
        const r = RADIUS[Math.min(5, Math.max(0, site.protection))] * k;
        const g = document.createElementNS(NS, 'g');
        g.setAttribute('class', `location-map__pin location-map__pin--${neutral ? 'none' : TONE[rank]}`
            + (site.id === this.selectedValue ? ' is-selected' : '')
            + (site.geoSource === 'country' ? ' is-coarse' : '')
            + (site.type === 'region' ? ' is-region' : ''));
        g.setAttribute('tabindex', '0');
        g.setAttribute('role', 'button');
        g.setAttribute('aria-label', `${site.name}: ${this.summary(site)}`);
        if (rank === 3) {
            const ring = this.shape('circle', x, y, r * 2);
            ring.setAttribute('class', 'location-map__ring');
            ring.style.strokeWidth = String(1.2 * k);
            g.appendChild(ring);
        }
        if (site.type === 'region') {
            // A region covers an area, not a point: dashed outline around it.
            const area = this.shape('circle', x, y, r * 2.6);
            area.setAttribute('class', 'location-map__area');
            area.style.strokeWidth = String(1.2 * k);
            area.style.strokeDasharray = `${3 * k} ${2 * k}`;
            g.appendChild(area);
        }
        const mark = this.shape(SHAPE[rank], x, y, r);
        mark.style.strokeWidth = String(1.4 * k);
        g.appendChild(mark);

        g.addEventListener('click', () => this.select(site.id === this.selectedValue ? 0 : site.id));
        g.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this.select(site.id); }
        });
        g.addEventListener('mouseenter', () => this.showTip(site, x, y));
        g.addEventListener('focus', () => this.showTip(site, x, y));
        g.addEventListener('mouseleave', () => this.hideTip());
        g.addEventListener('blur', () => this.hideTip());
        return g;
    }

    drawCluster(cluster, k) {
        const [x, y] = cluster.xy;
        const rank = Math.max(...cluster.members.map((m) => this.band(m.site)));
        const g = document.createElementNS(NS, 'g');
        g.setAttribute('class', `location-map__cluster location-map__pin--${TONE[rank]}`);
        g.setAttribute('tabindex', '0');
        g.setAttribute('role', 'button');
        const label = faT('location_map.cluster', { '%count%': cluster.members.length });
        g.setAttribute('aria-label', label);
        const c = this.shape('circle', x, y, 11 * k);
        c.style.strokeWidth = String(2 * k);
        const t = document.createElementNS(NS, 'text');
        t.setAttribute('x', x); t.setAttribute('y', y);
        t.setAttribute('font-size', String(11 * k));
        t.textContent = String(cluster.members.length);
        g.append(c, t);
        const zoom = () => {
            this.hideTip();
            const before = this.view[2];
            const fitted = this.fitView(cluster.members.map((m) => m.xy));
            // Members almost on top of each other: halve the width instead.
            this.view = fitted[2] < before * 0.8 ? fitted : this.clamp([x - before / 4, y - this.view[3] / 4, before / 2, this.view[3] / 2]);
            this.render();
        };
        g.addEventListener('click', zoom);
        g.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); zoom(); } });
        return g;
    }

    summary(site) {
        const parts = [];
        if (this.layerValue === 'gaps') {
            parts.push(site.gaps.length
                ? faT('location_map.gaps', { '%count%': site.gaps.length, '%total%': site.checksApplicable })
                : faT('location_map.gaps_none'));
        } else if (STRICT_LAYERS.includes(this.layerValue)) {
            parts.push(layerText(this.layerValue, this.bandKey(site)));
        } else {
            parts.push(site.riskBand === 'none' ? faT('location_map.no_risks') : faT('location_map.score', { '%score%': site.score }));
        }
        if (site.tenantName) parts.push(site.tenantName);
        parts.push(faT('location_map.protection', { '%level%': site.protection }));
        if (site.geoSource === 'country') parts.push(faT('location_map.coarse'));
        if (site.type === 'region') parts.push(faT('location_map.region'));
        return parts.join(', ');
    }

    // ---- tooltip + panel -------------------------------------------------

    showTip(site, x, y) {
        this.showTipText(site.name, this.summary(site), x, y);
    }

    showTipText(title, text, x, y) {
        if (!this.hasTipTarget) return;
        const svg = this.svgTarget;
        const [vx, vy, vw, vh] = this.view;
        const px = ((x - vx) / vw) * svg.clientWidth;
        const py = ((y - vy) / vh) * svg.clientHeight;
        const tip = this.tipTarget;
        tip.replaceChildren();
        const strong = document.createElement('strong');
        strong.textContent = title;
        const line = document.createElement('span');
        line.textContent = text;
        tip.append(strong, line);
        tip.hidden = false;
        tip.style.left = `${Math.min(px + 14, svg.clientWidth - 220)}px`;
        tip.style.top = `${Math.max(py - 10, 0)}px`;
    }

    hideTip() {
        if (this.hasTipTarget) this.tipTarget.hidden = true;
    }

    showPanel(id) {
        const tpl = this.element.querySelector(`template[data-site-panel="${id}"]`);
        if (!tpl || !this.hasPanelTarget) return;
        const body = this.panelTarget.querySelector('[data-panel-body]');
        body.replaceChildren(tpl.content.cloneNode(true));
        body.querySelectorAll('[data-layer-only]').forEach((el) => { el.hidden = el.dataset.layerOnly !== this.layerValue; });
        this.panelTarget.hidden = false;
        this.rowTargets.forEach((row) => row.classList.toggle('is-selected', parseInt(row.dataset.siteId, 10) === id));
    }

    hidePanel() {
        if (this.hasPanelTarget) this.panelTarget.hidden = true;
        this.rowTargets.forEach((row) => row.classList.remove('is-selected'));
    }
}
