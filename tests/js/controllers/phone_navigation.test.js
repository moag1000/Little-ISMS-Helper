import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest';
import MegaMenuController from '../../../assets/controllers/mega_menu_controller.js';
import SidebarToggleController from '../../../assets/controllers/sidebar_toggle_controller.js';
import { mount, stopAll } from '../support/mount.js';

// Phone navigation: a tap fires pointerenter (touch) + click on the L1 trigger.
// iOS Safari used to open the flyout on the emulated hover and the click shut
// it again — the flyout never opened on an iPhone.

const pointer = (type, pointerType) => {
    const ev = new Event(type, { bubbles: false });
    Object.defineProperty(ev, 'pointerType', { value: pointerType });
    return ev;
};

const megaFixture = () => `
    <div data-controller="mega-menu">
        <aside class="app-sidebar">
            <button class="sb-link" data-mega-menu-target="trigger" data-category="risk"
                    data-action="click->mega-menu#toggle">Risiken</button>
        </aside>
        <div class="flyout" data-mega-menu-target="panel" data-category="risk">
            <button class="flyout__close" data-action="click->mega-menu#closePanel">✕</button>
            <a class="fly-item" href="/de/risk">Risiken</a>
        </div>
    </div>`;

const isOpen = () => document.querySelector('.flyout').classList.contains('is-open');

describe('mega-menu on touch screens', () => {
    afterEach(() => stopAll());

    it('opens the flyout on a tap (touch enter + click)', async () => {
        await mount('mega-menu', MegaMenuController, megaFixture());
        const trigger = document.querySelector('.sb-link');
        // what iOS Safari sends for one tap: touch pointer events, emulated mouse hover, click
        trigger.dispatchEvent(pointer('pointerenter', 'touch'));
        trigger.dispatchEvent(new MouseEvent('mouseenter'));
        trigger.click();
        expect(isOpen()).toBe(true);

        // the touch "leave" after the tap must not schedule a close
        document.querySelector('.app-sidebar').dispatchEvent(pointer('pointerleave', 'touch'));
        await new Promise((r) => setTimeout(r, 250));
        expect(isOpen()).toBe(true);

        // a second tap closes it, the back button too
        trigger.click();
        expect(isOpen()).toBe(false);
        trigger.click();
        document.querySelector('.flyout__close').click();
        expect(isOpen()).toBe(false);
    });

    it('keeps mouse hover + the click that follows it open on desktop', async () => {
        await mount('mega-menu', MegaMenuController, megaFixture());
        const trigger = document.querySelector('.sb-link');
        trigger.dispatchEvent(pointer('pointerenter', 'mouse'));
        expect(isOpen()).toBe(true);
        trigger.click();
        expect(isOpen()).toBe(true);
    });
});

const sidebarFixture = () => `
    <div data-controller="sidebar-toggle">
        <button data-sidebar-toggle-target="menuBtn" data-action="click->sidebar-toggle#toggle">☰</button>
        <div data-sidebar-toggle-target="backdrop" data-action="click->sidebar-toggle#close"></div>
        <aside data-sidebar-toggle-target="sidebar"><a href="#x">Mein Tag</a></aside>
    </div>`;

const touch = (type, x, y) => {
    const ev = new Event(type, { bubbles: true, cancelable: true });
    Object.defineProperty(ev, 'touches', { value: type === 'touchend' ? [] : [{ clientX: x, clientY: y }] });
    return ev;
};

describe('sidebar-toggle on a phone', () => {
    beforeEach(() => {
        window.matchMedia = vi.fn().mockReturnValue({ matches: true, addEventListener() {}, removeEventListener() {} });
    });
    afterEach(() => { stopAll(); delete window.matchMedia; });

    it('is inert while closed and locks page scroll while open', async () => {
        const { controller } = await mount('sidebar-toggle', SidebarToggleController, sidebarFixture());
        const sidebar = controller.sidebarTarget;
        expect(sidebar.inert).toBe(true);

        controller.open();
        expect(sidebar.inert).toBe(false);
        expect(document.documentElement.style.overflow).toBe('hidden');

        controller.close();
        expect(sidebar.inert).toBe(true);
        expect(document.documentElement.style.overflow).toBe('');
    });

    it('scrolls vertically without closing, closes on a left swipe', async () => {
        const { controller } = await mount('sidebar-toggle', SidebarToggleController, sidebarFixture());
        const sidebar = controller.sidebarTarget;
        controller.open();

        // vertical scroll with a little horizontal jitter: not cancelled, stays open
        sidebar.dispatchEvent(touch('touchstart', 150, 400));
        const move = touch('touchmove', 138, 300);
        sidebar.dispatchEvent(move);
        sidebar.dispatchEvent(touch('touchend'));
        expect(move.defaultPrevented).toBe(false);
        expect(controller.isOpen).toBe(true);

        // horizontal swipe to the left closes
        sidebar.dispatchEvent(touch('touchstart', 200, 400));
        sidebar.dispatchEvent(touch('touchmove', 120, 404));
        sidebar.dispatchEvent(touch('touchend'));
        expect(controller.isOpen).toBe(false);
    });

    it('closes after a link inside it was tapped', async () => {
        const { controller } = await mount('sidebar-toggle', SidebarToggleController, sidebarFixture());
        controller.open();
        controller.sidebarTarget.querySelector('a').click();
        expect(controller.isOpen).toBe(false);
    });
});
