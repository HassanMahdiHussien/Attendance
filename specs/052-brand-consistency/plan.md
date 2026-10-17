# Plan: One brand everywhere

## Existing code (read first)
- Current correct mark: `resources/views/layouts/workspace.blade.php` (the `<a href="{{ $home }}">` block, split-flap span).
- Wrong marks: `resources/views/layouts/guest.blade.php` (white header, `bg-primary` D), `resources/views/livewire/auth/login.blade.php` (card title), `resources/views/welcome.blade.php`, `resources/views/errors/403.blade.php`.
- Panels: `app/Providers/Filament/AdminPanelProvider.php`, `PlatformPanelProvider.php` (`brandName`, `favicon`, `viteTheme`), theme `resources/css/filament/theme.css`.
- Admin home: `app/Filament/Admin/Widgets/SetupChecklist.php` (only visible when setup incomplete) — the dashboard is otherwise empty.
- Mail: notifications in `app/Notifications/*` use `MailMessage` (Laravel default markdown theme); `app/Mail/OperationsMessage.php` + `resources/views/mail/operations-message.blade.php`; `User` implements `HasLocalePreference`.
- Tokens: `resources/css/app.css` (`--color-night`, `--color-signal`, `--color-signal-dark`), DESIGN.md.
- Guards to keep green: TranslationCoverageTest, BehaviourInventoryTest, RouteSweepTest, the browser tests.

## Design
- `resources/views/components/ui/brand.blade.php`: props `size` (sm|md|lg), `name` (bool, default true), `subtitle` (?string); the mark is a span with the existing linear-gradient style; the name uses `__('Displanery')`. Workspace switches to it (subtitle = company name).
- Guest layout: midnight header (`bg-night text-white`, same height/padding as workspace), `x-ui.brand` linking to `/`; login card title uses the mark (lg, no duplicate wordmark if redundant).
- Filament: `->brandLogo(fn () => view('filament.brand'))` with a small view rendering `x-ui.brand` (white name on the midnight top bar), `->brandLogoHeight('2rem')`, keep `brandName` for the page title.
- Favicon: SVG redrawn; ICO generated once (write a tiny PNG-in-ICO from a 32×32 PNG rendered by headless Chrome or GD) and committed.
- Errors: `resources/views/errors/layout.blade.php` (self-contained: no DB, no session, no auth calls unless guarded with try/catch-free checks like `auth()->hasUser()`? — use `@auth` only for 403/404/419; 500/503 must not touch auth or DB) + 404/419/500/503 views; 403 restyled on the same layout; texts translated.
- Mail: publish/override the mail views under `resources/views/vendor/mail/html/` (header with the mark as inline-styled HTML table — email clients need inline styles, no external CSS/fonts), a `displanery` markdown theme CSS (`config/mail.php` `markdown.theme`), amber button; `dir="rtl"` when `app()->getLocale() === 'ar'`; add Arabic translations for the notification template strings ("Hello!", "Whoops!", "Regards,", the trouble-clicking line, "All rights reserved.").
- Admin shortcuts widget `app/Filament/Admin/Widgets/AdminShortcuts.php` (+ view), always visible to company admins, links to the existing Filament resources/pages and to `/home`.

## Tests
`tests/Feature/Ui/BrandConsistencyTest.php`: render workspace page, login, admin dashboard, platform dashboard, 403/404/419/500/503, a notification mail (en + ar) → each contains the brand component's marker (e.g. `data-brand-mark`); grep views for leftover hand-drawn marks; mail ar has `dir="rtl"` and the Arabic "Regards" text. Plus translation coverage, Pint, full suite; screenshots before/after.
