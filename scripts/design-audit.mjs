import { spawnSync } from 'node:child_process';
import { mkdir, writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import { chromium } from 'playwright';

const emails = [
    'company.admin@example.test',
    'hr.staff@example.test',
    'reporter@example.test',
    'committee.legal@example.test',
    'platform.admin@example.test',
    'reviewer@example.test',
    'partner@example.test',
];
const base = new URL(process.env.AUDIT_BASE_URL || 'http://localhost');
if (!['localhost', '127.0.0.1', '::1'].includes(base.hostname) || !['http:', 'https:'].includes(base.protocol)) {
    throw new Error('AUDIT_BASE_URL must be a local loopback URL with demo data.');
}
const limit = 40;
const timestamp = new Date().toISOString().replace(/[-:T]/g, '').slice(0, 8) + '-' +
    new Date().toISOString().replace(/[-:T]/g, '').slice(8, 14);
const output = join('storage', 'app', 'design-audit', timestamp);
const shots = join(output, 'shots');
await mkdir(shots, { recursive: true });

function artisan(email, options = []) {
    const run = spawnSync('php', ['artisan', 'dev:session-cookie', email, ...options], {
        encoding: 'utf8',
        maxBuffer: 4 * 1024 * 1024,
    });
    if (run.error) throw run.error;
    if (run.status !== 0) throw new Error((run.stderr || run.stdout).trim());
    return JSON.parse(run.stdout.trim());
}

function target(href) {
    try {
        const url = new URL(href, base);
        if (url.origin !== base.origin || !['http:', 'https:'].includes(url.protocol)) return null;
        const path = decodeURIComponent(url.pathname).toLowerCase();
        if (/(^|\/)(logout|locale|livewire)(\/|$)/.test(path) ||
            path.includes('/pdf') || path.includes('/download') ||
            path.includes('/attachments/') || path.includes('/statistics/exports/') ||
            path.includes('/filament/exports') || path.includes('/filament/imports') ||
            path.includes('/help/shots/')) return null;
        // Keep the query: drill-down links need their filters (dropping them produced false 404s).
        url.hash = '';
        return url.pathname + url.search;
    } catch {
        return null;
    }
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, char => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    })[char]);
}

function signatureKey(signature) {
    return JSON.stringify(signature);
}

async function inspect(page, names) {
    return page.evaluate(ignoredNames => {
        const clean = value => (value || '').replace(/\s+/g, ' ').trim();
        const path = href => {
            try { return new URL(href, location.href).pathname; } catch { return ''; }
        };
        const links = root => [...root.querySelectorAll('a[href]')]
            .filter(element => !element.hasAttribute('download') && !element.querySelector('[data-brand-mark], .fi-logo'))
            .map(element => ({ label: clean(element.textContent).replace(/[٠-٩0-9]+/g, '').trim(), target: path(element.href) }))
            .filter(link => link.label && link.target);
        const workspace = document.querySelector('body > header:has([data-brand-mark])');
        const filament = document.querySelector('.fi-topbar');
        let menus = [];
        let signature = null;
        if (workspace) {
            const nav = workspace.querySelector('nav[aria-label]');
            const settings = workspace.querySelector('#workspace-settings');
            menus = [...links(nav || workspace),
                ...links(workspace.querySelector('#workspace-account-menu') || document.createElement('div'))];
            signature = {
                kind: 'workspace',
                brand: clean(workspace.querySelector('[data-brand-mark]')?.parentElement?.textContent),
                top: links(nav || workspace).filter(link =>
                    !links(settings || document.createElement('div')).some(setting =>
                        setting.label === link.label && setting.target === link.target)),
                settings: links(settings || document.createElement('div')),
                search: !!workspace.querySelector('input[type=search], [role=searchbox]'),
            };
        } else if (filament) {
            const top = links(filament);
            menus = [...document.querySelectorAll('.fi-topbar, .fi-sidebar, .fi-dropdown-panel, [role=menu]')]
                .flatMap(root => links(root));
            signature = {
                kind: 'filament',
                brand: clean(filament.querySelector('[data-brand-mark], .fi-logo')?.parentElement?.textContent),
                top,
                settings: links(document.querySelector('.fi-sidebar') || document.createElement('div')),
                search: !!filament.querySelector('input[type=search], [role=searchbox]'),
            };
        }
        const duplicates = [];
        for (let i = 0; i < menus.length; i++) {
            for (let j = i + 1; j < menus.length; j++) {
                if ((menus[i].label === menus[j].label && menus[i].target !== menus[j].target) ||
                    (menus[i].target === menus[j].target && menus[i].label !== menus[j].label)) {
                    duplicates.push(menus[i].label + ' → ' + menus[i].target + ' / ' +
                        menus[j].label + ' → ' + menus[j].target);
                }
            }
        }
        const language = document.documentElement.lang?.slice(0, 2);
        const selector = 'button, label, th, nav a, [role=tab], [role=menuitem], .fi-btn, h1, h2, h3';
        const wrongLanguage = [];
        const names = ignoredNames.filter(Boolean).sort((a, b) => b.length - a.length);
        for (const element of document.querySelectorAll(selector)) {
            if (element.closest('[lang]')?.lang && element.closest('[lang]').lang !== language) continue;
            if (element.querySelector(selector)) continue;
            let value = clean(element.innerText || element.getAttribute('aria-label'));
            if (!value || /^[\d٠-٩\s#.,:/()%+-]+$/.test(value)) continue;
            // Avatar initials of (Arabic) names, the language switch and file-format names are not UI text.
            if (/^[؀-ۿ]\.?[؀-ۿ]?$/.test(value) || /^(English|العربية|اللغة العربية)$/.test(value)) continue;
            value = value.replace(/(Excel|PDF|CSV|English)/g, ' ');
            for (const name of names) value = value.replaceAll(name, ' ');
            value = value.replace(/[\w.+-]+@[\w.-]+/g, ' ')
                .replace(/#?\d{4,}/g, ' ').replace(/\b[A-Z]{2,4}\b/g, ' ')
                .replace(/\b(?:EMP|CASE)-\d+\b/gi, ' ');
            const mismatch = language === 'ar' ? /\b[A-Za-z]{2,}\b/.test(value) :
                language === 'en' && /[\u0600-\u06ff]/.test(value);
            if (mismatch) wrongLanguage.push(clean(value).slice(0, 140));
        }
        return {
            brand: !!document.querySelector('[data-brand-mark]'),
            signature,
            duplicates: [...new Set(duplicates)],
            wrongLanguage: [...new Set(wrongLanguage)].slice(0, 30),
            overflow: Math.max(document.documentElement.scrollWidth, document.body.scrollWidth) -
                document.documentElement.clientWidth,
            hrefs: [...document.querySelectorAll('a[href]:not([download])')].map(a => a.href),
        };
    }, names);
}

const results = {
    runAt: new Date().toISOString(),
    baseUrl: base.origin,
    pageLimitPerRoleLocale: limit,
    notes: [],
    pages: [],
    problems: [],
    signatures: [],
};
const problem = (type, role, locale, path, detail, images = {}) =>
    results.problems.push({ type, role, locale, path, detail, images });
let browser;
try {
    browser = await chromium.launch({ headless: true });
    for (const email of emails) {
        const role = email.split('@')[0];
        let originalLocale;
        try {
            for (const locale of ['ar', 'en']) {
                let cookie;
                try {
                    cookie = artisan(email, ['--locale=' + locale]);
                    if (originalLocale === undefined) originalLocale = cookie.originalLocale;
                } catch (error) {
                    if (String(error.message).includes('Demo user not found.')) {
                        results.notes.push(email + ': seeded user does not exist; skipped.');
                        break;
                    }
                    throw error;
                }
                const context = await browser.newContext({ viewport: { width: 1280, height: 800 } });
                try {
                    await context.addCookies([{
                        name: cookie.name, value: cookie.value, url: base.origin,
                        httpOnly: true, sameSite: 'Lax',
                    }]);
                    await context.route('**/*', route => {
                        const request = route.request();
                        if (request.method() !== 'GET' ||
                            (request.isNavigationRequest() && !target(request.url()))) {
                            return route.abort();
                        }
                        return route.continue();
                    });
                    const desktop = await context.newPage();
                    const mobile = await context.newPage();
                    await mobile.setViewportSize({ width: 375, height: 812 });
                    const start = role === 'platform.admin' ? ['/platform', '/home'] : ['/home'];
                    const queue = [...start];
                    const visited = new Set();
                    let workspaceSignature = null;
                    while (queue.length && visited.size < limit) {
                        const path = target(queue.shift());
                        if (!path || visited.has(path)) continue;
                        visited.add(path);
                        const errors = [];
                        const consoleListener = message => {
                            if (message.type() === 'error' && !/net::ERR_FAILED|^Object$/.test(message.text())) errors.push(message.text());
                        };
                        const pageErrorListener = error => errors.push(error.message);
                        desktop.on('console', consoleListener);
                        desktop.on('pageerror', pageErrorListener);
                        let response;
                        try {
                            response = await desktop.goto(new URL(path, base).href, {
                                waitUntil: 'domcontentloaded', timeout: 30000,
                            });
                            await desktop.waitForLoadState('networkidle', { timeout: 5000 }).catch(() => {});
                        } catch (error) {
                            problem('navigation', role, locale, path, error.message);
                            desktop.off('console', consoleListener);
                            desktop.off('pageerror', pageErrorListener);
                            continue;
                        }
                        desktop.off('console', consoleListener);
                        desktop.off('pageerror', pageErrorListener);
                        const status = response?.status() ?? 0;
                        const data = await inspect(desktop, cookie.names);
                        const imageStem = role.replaceAll('.', '-') + '-' + locale + '-' +
                            String(visited.size).padStart(2, '0');
                        const images = {
                            desktop: 'shots/' + imageStem + '-desktop.jpg',
                            phone: 'shots/' + imageStem + '-phone.jpg',
                        };
                        await desktop.screenshot({ path: join(output, images.desktop), type: 'jpeg',
                            quality: 60, fullPage: true });
                        let phoneData = null;
                        const phoneErrors = [];
                        const phoneConsoleListener = message => {
                            if (message.type() === 'error') phoneErrors.push(message.text());
                        };
                        const phonePageErrorListener = error => phoneErrors.push(error.message);
                        mobile.on('console', phoneConsoleListener);
                        mobile.on('pageerror', phonePageErrorListener);
                        try {
                            const phoneResponse = await mobile.goto(new URL(path, base).href, {
                                waitUntil: 'domcontentloaded', timeout: 30000,
                            });
                            await mobile.waitForLoadState('networkidle', { timeout: 5000 }).catch(() => {});
                            phoneData = await inspect(mobile, cookie.names);
                            if (phoneResponse?.status() >= 400) {
                                problem('HTTP status', role, locale, path,
                                    'Phone: ' + phoneResponse.status(), images);
                            }
                            await mobile.screenshot({ path: join(output, images.phone), type: 'jpeg',
                                quality: 60, fullPage: false });
                        } catch (error) {
                            images.phone = null;
                            problem('navigation', role, locale, path, 'Phone: ' + error.message, images);
                        } finally {
                            mobile.off('console', phoneConsoleListener);
                            mobile.off('pageerror', phonePageErrorListener);
                        }
                        results.pages.push({ role, locale, path, status, images,
                            signature: data.signature, overflowDesktop: data.overflow,
                            overflowPhone: phoneData?.overflow ?? null });
                        if (status >= 400) problem('HTTP status', role, locale, path, String(status), images);
                        for (const error of [...new Set(errors)]) {
                            problem('browser error', role, locale, path, error.slice(0, 300), images);
                        }
                        for (const error of [...new Set(phoneErrors)]) {
                            problem('browser error', role, locale, path, 'Phone: ' + error.slice(0, 300), images);
                        }
                        if (new URL(desktop.url()).pathname === '/login') {
                            problem('navigation', role, locale, path, 'Redirected to login.', images);
                        }
                        if (!data.brand) problem('brand mark', role, locale, path, '[data-brand-mark] missing', images);
                        if (phoneData && !phoneData.brand) {
                            problem('brand mark', role, locale, path, 'Phone: [data-brand-mark] missing', images);
                        }
                        if (data.overflow > 1) problem('horizontal overflow', role, locale, path,
                            'Desktop: ' + data.overflow + ' px', images);
                        if (phoneData?.overflow > 1) problem('horizontal overflow', role, locale, path,
                            'Phone: ' + phoneData.overflow + ' px', images);
                        for (const detail of data.duplicates) problem('duplicate menu', role, locale, path, detail, images);
                        for (const detail of data.wrongLanguage) problem('wrong language', role, locale, path, detail, images);
                        for (const detail of phoneData?.wrongLanguage || []) {
                            if (!data.wrongLanguage.includes(detail)) {
                                problem('wrong language', role, locale, path, 'Phone: ' + detail, images);
                            }
                        }
                        if (path === '/home' && data.signature?.kind === 'workspace') {
                            workspaceSignature = data.signature;
                        }
                        for (const href of data.hrefs) {
                            const next = target(href);
                            if (next && !visited.has(next) && !queue.includes(next)) queue.push(next);
                        }
                    }
                    if (queue.length) results.notes.push(role + ' / ' + locale + ': stopped at ' + limit + ' pages.');
                    const rolePages = results.pages.filter(page => page.role === role && page.locale === locale);
                    if (!workspaceSignature) {
                        workspaceSignature = rolePages.find(page => page.signature?.kind === 'workspace')?.signature;
                    }
                    if (!workspaceSignature) results.notes.push(role + ' / ' + locale + ': workspace baseline unavailable.');
                    for (const page of rolePages) {
                        const deviates = !!workspaceSignature && signatureKey(page.signature) !== signatureKey(workspaceSignature);
                        results.signatures.push({ role, locale, path: page.path,
                            signature: page.signature, deviates });
                        if (deviates) problem('header signature', role, locale, page.path,
                            'Header differs from workspace baseline.', page.images);
                    }
                } finally {
                    await context.close();
                }
            }
        } catch (error) {
            results.notes.push(email + ': audit failed: ' + error.message);
        } finally {
            if (originalLocale !== undefined) {
                try { artisan(email, ['--restore-locale=' + originalLocale]); }
                catch (error) { results.notes.push(email + ': locale restore failed: ' + error.message); }
            }
        }
    }
} finally {
    await browser?.close();
    await writeFile(join(output, 'results.json'), JSON.stringify(results, null, 2));
    await writeFile(join(output, 'report.html'), renderReport(results));
    console.log(join(output, 'report.html'));
}

function renderReport(run) {
    const groups = Map.groupBy(run.problems, item => item.type);
    const pageGroups = Map.groupBy(run.pages, item => item.role);
    const shot = (path, label) => path ?
        '<a href="' + escapeHtml(path) + '">' + escapeHtml(label) + '</a>' : '';
    const card = page => page ?
        '<div class="capture"><a href="' + escapeHtml(page.images.desktop) +
        '"><img loading="lazy" src="' + escapeHtml(page.images.desktop) + '" alt="Desktop screenshot"></a>' +
        (page.images.phone ? '<a href="' + escapeHtml(page.images.phone) +
        '"><img class="phone" loading="lazy" src="' + escapeHtml(page.images.phone) +
        '" alt="Phone screenshot"></a>' : '') + '</div>' : '<p>No capture</p>';
    const signatures = run.signatures.map(item =>
        '<tr><td>' + escapeHtml(item.role) + '</td><td>' + escapeHtml(item.locale) +
        '</td><td>' + escapeHtml(item.path) + '</td><td>' +
        (item.deviates ? '<strong>Differs</strong>' : 'Matches') +
        '</td><td><code>' + escapeHtml(JSON.stringify(item.signature)) + '</code></td></tr>').join('');
    const problems = [...groups].map(([type, items]) =>
        '<section><h3>' + escapeHtml(type) + ' <small>' + items.length + '</small></h3><ul>' +
        items.map(item => '<li><strong>' + escapeHtml(item.role) + ' · ' +
            escapeHtml(item.locale) + ' · ' + escapeHtml(item.path) + '</strong><p>' +
            escapeHtml(item.detail) + '</p><span>' +
            shot(item.images.desktop, 'Desktop') + ' ' + shot(item.images.phone, 'Phone') +
            '</span></li>').join('') + '</ul></section>').join('');
    const gallery = [...pageGroups].map(([role, pages]) => {
        const byPath = Map.groupBy(pages, page => page.path);
        return '<section><h3>' + escapeHtml(role) + '</h3>' +
            [...byPath].map(([path, variants]) =>
                '<article><h4>' + escapeHtml(path) + '</h4><div class="variants">' +
                '<div><h5>Arabic</h5>' + card(variants.find(page => page.locale === 'ar')) + '</div>' +
                '<div><h5>English</h5>' + card(variants.find(page => page.locale === 'en')) + '</div>' +
                '</div></article>').join('') + '</section>';
    }).join('');
    return '<!doctype html><html lang="en"><meta charset="utf-8">' +
        '<meta name="viewport" content="width=device-width,initial-scale=1"><title>Design audit</title>' +
        '<style>body{margin:0;background:#f5f7f8;color:#172534;font:15px/1.55 system-ui,sans-serif}' +
        'main{max-width:1120px;margin:auto;padding:24px}header{background:#13283b;color:white;padding:24px}' +
        'header div{max-width:1120px;margin:auto}h1{margin:0}h2{margin-top:40px;border-bottom:2px solid #d9e2e8;padding-bottom:6px}' +
        'h3{margin-bottom:12px}small{color:#687986}section,article{margin:16px 0}li,article{background:white;border:1px solid #dce4e9;border-radius:10px;padding:14px;margin:8px 0}' +
        'li p{margin:4px 0}a{color:#125b83}ul{list-style:none;padding:0}.variants{display:grid;grid-template-columns:1fr 1fr;gap:16px}' +
        '.capture{display:flex;gap:8px;align-items:start}.capture a:first-child{flex:1;min-width:0}.capture img{width:100%;border:1px solid #dce4e9;border-radius:5px}.capture a:last-child{width:27%}' +
        'table{border-collapse:collapse;width:100%;background:white}td,th{border:1px solid #dce4e9;padding:8px;vertical-align:top;text-align:start}' +
        'code{white-space:pre-wrap;overflow-wrap:anywhere;font-size:11px}td:nth-child(5){max-width:430px}' +
        '@media(max-width:650px){main{padding:14px}.variants{grid-template-columns:1fr}table{display:block;overflow-x:auto}header{padding:16px}}</style>' +
        '<header><div><h1>Design audit</h1><p>' + escapeHtml(run.runAt) + ' · ' +
        run.pages.length + ' pages · ' + run.problems.length + ' problems · ' +
        new Set(run.pages.map(page => page.role)).size + ' roles</p></div></header><main>' +
        '<h2>Problems</h2>' + (problems || '<p>No problems found.</p>') +
        '<h2>Run notes</h2><ul>' + run.notes.map(note => '<li>' + escapeHtml(note) + '</li>').join('') + '</ul>' +
        '<h2>Header signatures</h2><p>Compared with the workspace header for the same role and locale.</p>' +
        '<table><thead><tr><th>Role</th><th>Locale</th><th>Page</th><th>Result</th><th>Signature</th></tr></thead><tbody>' +
        signatures + '</tbody></table><h2>Gallery</h2>' + gallery + '</main></html>';
}
