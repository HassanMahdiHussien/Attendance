# Editing the Help Center

Each guide is a plain text Markdown file. English files are in `resources/help/en/` and Arabic files are in `resources/help/ar/`. Each folder below a language is a section. The file name is the guide's web address, so keep it short and use lowercase letters, numbers, and hyphens.

Start every file with this header:

```
---
title: A short title
summary: One sentence about the guide.
audience: all
order: 10
---
```

Use `audience: all` for everyone, or list roles separated by commas, such as `company_admin, hr_staff`. `order` controls the position within a section. Write the guide below the second `---`. Use `#` for headings, `1.` for steps, and `**text**` for a bold button label. Copy button names exactly from the screen.

To add a guide, create a file with the same section and file name in both `en` and `ar`. Write each language naturally. Link to another guide with `[read more](help:getting-started/welcome)`. Refer to a screenshot with `![Description](shots/home)`. The screenshot name must be listed in `resources/help/screenshots.php` and its image must exist for both languages.

On a local development machine with the demo data, regenerate screenshots with `php artisan help:screenshots` or one image with `php artisan help:screenshots --only=home`. Review both languages after a UI change. The command never runs outside the local environment. Screenshots contain only seeded demo data.

Editing on a deployed server requires deploying the changed files. After deployment, the Help Center notices article changes without a build step. Run the Help tests before deployment; they check both languages, links, and screenshots.
