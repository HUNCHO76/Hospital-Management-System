/**
 * Centralized St. Joseph DMI branding. The visual theme is driven by the CSS
 * custom properties in resources/css/app.css (so components stay theme-aware);
 * the values here are the canonical reference for JS-side needs (charts, docs)
 * and the paths to the official brand assets.
 *
 * The colours are derived from the official St. Joseph DMI logo:
 *   orange (globe/ribbon), green (palm leaves & "DMI"), cream (fill).
 */
export const brand = {
    name: 'St. Joseph DMI HMS',
    shortName: 'St. Joseph DMI',
    hospitalName: 'St. Joseph DMI',

    /** Official logo asset. Place the supplied file at public/branding/. */
    logoSrc: '/branding/st-joseph-dmi-logo.png',

    colors: {
        primary: 'hsl(22, 84%, 47%)', // orange
        primaryDark: 'hsl(22, 84%, 38%)',
        primaryLight: 'hsl(22, 84%, 60%)',
        secondary: 'hsl(142, 71%, 36%)', // green
        accent: 'hsl(48, 82%, 82%)', // cream
        success: 'hsl(142, 71%, 36%)',
        warning: 'hsl(38, 92%, 50%)',
        danger: 'hsl(0, 84%, 60%)',
        info: 'hsl(217, 91%, 60%)',
    },
} as const;
