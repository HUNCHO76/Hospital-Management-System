# St. Joseph DMI brand assets

Place the official St. Joseph DMI logo here so the application can display it.

## Required file

- `st-joseph-dmi-logo.png` — the official logo (PNG with transparent background
  preferred; a square/near-square crop works best in the sidebar and favicon).

The app already references this exact path:

- Sidebar & mobile header: `components/brand-mark.tsx` → `lib/brand.ts` (`logoSrc`)
- Login page: `layouts/auth/auth-simple-layout.tsx`
- Favicon & browser tab: `resources/views/app.blade.php`

Until the file is added, the UI falls back cleanly to the "St. Joseph DMI"
wordmark (no broken image). Drop the file in and refresh — no code change needed.

An SVG version (`st-joseph-dmi-logo.svg`) may be added later for crisper
scaling; update `lib/brand.ts` `logoSrc` to point at it if you do.
