# HMS UI Foundation

The HMS frontend is React + TypeScript + Inertia + Tailwind, built on a
shadcn/ui component library (Radix primitives under `resources/js/components/ui`).
All modules **reuse** these components rather than inventing their own. This
document indexes the reusable pieces and the standards for using them.

## Layout & Navigation

| Component | Location | Purpose |
|---|---|---|
| `AppLayout` | `layouts/app-layout.tsx` | Page shell (header + sidebar + content). Pass `breadcrumbs`. |
| `AppSidebar` | `components/app-sidebar.tsx` | Permission-gated navigation. Items are data-driven and filtered by `can(...)`. |
| `AppSidebarHeader` | `components/app-sidebar-header.tsx` | Header with sidebar toggle + breadcrumbs. |
| `Breadcrumbs` | `components/breadcrumbs.tsx` | Accessible breadcrumb trail. |
| `PageHeader` | `components/page-header.tsx` | `title` + optional `description` + `actions`. Every major page uses it. |

Sidebar is responsive out of the box: expanded on desktop, collapsible to
icons, and a drawer on mobile (`components/ui/sidebar.tsx`).

## Core UI (`components/ui`)

`Button` (variants: default/secondary/destructive/outline/ghost/link; sizes;
`asChild`), `Input`, `Textarea`, `Select`, `Checkbox`, `Switch`, `RadioGroup`/`Radio`,
`Label`, `Card`, `Badge`, `Alert`, `Dialog`, `DropdownMenu`, `Tooltip`, `Separator`,
`Skeleton`, `Spinner`, and the `Table` primitives (`Table`, `TableHeader`,
`TableBody`, `TableRow`, `TableHead`, `TableCell`).

```tsx
<Button variant="destructive" disabled={processing}>Deactivate</Button>
<Spinner label="Saving patient" />
<Switch checked={data.api_enabled} onChange={(e) => setData('api_enabled', e.target.checked)} />
```

## Data Components

- **Table** — use the `Table` primitives; wrap content and it scrolls horizontally on small screens.
- **Pagination** (`components/pagination.tsx`) — renders Laravel/Inertia paginator `links`.
- **Search & Filters** — `Input` + `Select`, submitting to the server via `router.get(...)` with `preserveState`. Never filter large datasets client-side.
- **EmptyState** (`components/empty-state.tsx`) — title, description, optional action; drop into a full-width table cell when there are no rows.
- **ErrorState** (`components/error-state.tsx`) — safe error copy + optional `onRetry`.

## Forms

- **`Field`** (`components/form/field.tsx`) — standardized label + required indicator + hint + validation error, wired for `aria-describedby`.
- Works with Inertia `useForm`: bind `data`, `setData`, show `errors`, disable on `processing`.

```tsx
<Field label="First name" required error={errors.first_name}>
  {(props) => <Input {...props} value={data.first_name} onChange={(e) => setData('first_name', e.target.value)} />}
</Field>
```

Form data interfaces used with `useForm<T>()` need an index signature
(`[key: string]: ...`) to satisfy Inertia's `FormDataType` constraint.

## Notifications

- **Toasts** — `useToast().toast({ variant, description })`, variants `success | error | warning | info`. Rendered by `<Toaster />`, mounted once in `AppContent`.
- **Flash bridge** — `FlashToaster` turns Laravel session flashes into toasts automatically. Controllers keep using `->with('success'|'error'|'warning'|'info', '...')`; shared via `HandleInertiaRequests`.
- **Inline `Alert`** — for persistent page-level messages.

## Permission-aware UI

- `<Can permission="patients.create">…</Can>` and `usePermissions().can('...')`.
- **UX only** — the backend (policies + Spatie permissions) is the real security boundary. Sidebar items and action buttons are gated for convenience, never for security.

## States & Resilience

- **Loading**: `Spinner`, `Skeleton`, disabled buttons (`processing`).
- **Empty**: `EmptyState`. **Error**: `ErrorState`.
- **ErrorBoundary** (`components/error-boundary.tsx`) wraps the app root; unexpected render errors show a safe reload fallback, never a stack trace.

## Accessibility

Semantic elements, real `<button>`/`<a>` (never `<div onClick>`), labels tied to
inputs, visible focus rings, `aria-invalid`/`aria-describedby` on invalid fields,
toasts in a live region, and dialogs via Radix. Status is never conveyed by
colour alone (icon + text accompany badges/toasts).

## Theme & Types

- Dark mode is supported (`use-appearance`); all new components include dark variants.
- Reusable types live in `resources/js/types` (`SharedData`, `Auth`, `Paginated<T>`,
  `NavItem`, `Flash`, `ValidationErrors`, `ToastItem`). Avoid `any`.

## Conventions

- Inertia routing only (`Link`, `router`, `route()`); no React Router.
- No global state library — Inertia props + React state/context + URL/query state.
- One icon library: `lucide-react`.
