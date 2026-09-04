import { useEffect, useState } from 'react';

/**
 * Chart colours, shared by every chart wrapper in this directory.
 *
 * Each chart used to declare its own COLORS array, all four led by indigo
 * (#6366f1) — a palette from the upstream product that has nothing to do with
 * our brand. These are the replacements.
 *
 * Both sets were checked rather than eyeballed: OKLCH lightness band, chroma
 * floor, adjacent-pair separation under protanopia/deuteranopia/tritanopia, and
 * contrast against the chart surface. Two consequences worth knowing before you
 * edit them:
 *
 *  - The ORDER is load-bearing. Hues sit next to each other only where they stay
 *    distinguishable; lime and rose are deliberately far apart because they
 *    collapse into each other under deuteranopia at these steps. Reordering the
 *    array can silently break that.
 *  - DARK is not a lightened LIGHT. The dark band is much narrower (OKLCH L
 *    0.48–0.67 against 0.43–0.77), so the 400-level steps that look right by eye
 *    fail it. These are 600-level steps chosen for that band.
 *
 * Slot N is the same entity in both modes, so a chart keeps its identity when the
 * viewer switches theme.
 */
export const CHART_COLORS_LIGHT = [
    '#047857', // brand emerald
    '#C2410C', // burnt orange
    '#1D4ED8', // blue
    '#65A30D', // lime
    '#7E22CE', // purple
    '#A16207', // ochre
    '#0891B2', // cyan
    '#BE123C', // rose
];

export const CHART_COLORS_DARK = [
    '#059669',
    '#EA580C',
    '#3B82F6',
    '#65A30D',
    '#A855F7',
    '#D97706',
    '#0891B2',
    '#F43F5E',
];

/**
 * Single-hue brand ramp, light to dark, for magnitude rather than identity —
 * a funnel's stages are one measure shrinking, not eight different things.
 */
export const CHART_RAMP_BRAND = [
    '#7CE3B1',
    '#34A87A',
    '#047857',
    '#036046',
    '#034D38',
];

/** Axis, grid and tick tones, so the four wrappers stay in step. */
export const CHART_INK = {
    grid: '#E6E8E3',
    gridDark: '#3F3F46',
    tick: '#6B7280',
};

/**
 * True when the app is in dark mode.
 *
 * Reads the `dark` class that ThemeProvider toggles on <html>, rather than
 * consuming the theme context: these wrappers also render in tests and in
 * Storybook-less isolation, where useTheme() would throw for want of a provider.
 * The observer keeps charts in step with the theme toggle without a remount.
 */
export function useIsDarkMode() {
    const [isDark, setIsDark] = useState(
        () => typeof document !== 'undefined' && document.documentElement.classList.contains('dark')
    );

    useEffect(() => {
        if (typeof document === 'undefined') return undefined;

        const el = document.documentElement;
        const sync = () => setIsDark(el.classList.contains('dark'));
        sync();

        const observer = new MutationObserver(sync);
        observer.observe(el, { attributes: true, attributeFilter: ['class'] });

        return () => observer.disconnect();
    }, []);

    return isDark;
}

/** The categorical palette for the active theme. */
export function useChartColors() {
    return useIsDarkMode() ? CHART_COLORS_DARK : CHART_COLORS_LIGHT;
}
