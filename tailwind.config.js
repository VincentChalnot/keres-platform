/**
 * Tailwind 3 configuration of the platform. Every colour, font, radius and shadow comes from
 * the Keres design system (@keres/design-system, see docs/design-system.md): the preset maps
 * its tokens to Tailwind names, the DS stylesheet (assets/design-system.css) carries the
 * components (.btn, .card, .alert, .navbar ...).
 */
import keresPreset from '@keres/design-system/tailwind.preset';

/** @type {import('tailwindcss').Config} */
export default {
    presets: [keresPreset],

    // Tailwind only emits the utilities and components whose class name appears, as a whole
    // word, in one of these files. Write class names out in full: `'tag--' ~ kind` or
    // `btn--${variant}` is invisible to the scanner (the DS CSS below is scanned too, so every
    // DS component and variant is always available even when its name is built dynamically).
    content: [
        './templates/**/*.twig',
        './assets/*.js',
        './assets/typescript/src/**/*.ts',
        './src/**/*.php',
        './config/admin/**/*.yaml',
        './config/datagrid/**/*.yaml',
        './translations/**/*.yaml',
        './node_modules/@keres/design-system/src/**/*.css',
    ],

    corePlugins: {
        // The DS wrapper is `.container-page` (centred, max-w-container, responsive padding).
        // Tailwind's `.container` (fixed max-width per breakpoint, no padding) is not used.
        container: false,
    },
};
