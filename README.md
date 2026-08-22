# VotingGame

A browser board game. PHP + mysqli backend on GreenGeeks shared hosting,
Vite + React frontend, server-authoritative rules engine.

Design and rules: [`docs/DESIGN.md`](docs/DESIGN.md).

## Layout

```
backend/     one thin PHP endpoint per action; lib.php + engine.php shared
database/    numbered migrations, run BY HAND in phpMyAdmin
frontend/    Vite + React + Tailwind; builds to dist/
docs/        DESIGN.md and archived playtest exports
```

Deploy assembles `frontend/dist/` + `backend/*.php` into one flat docroot,
so in production the app and the API share an origin.

## First-run setup on the server

1. Run `database/01_tables.sql` in phpMyAdmin against the subdomain database.
2. Add an admin secret to `dbConfig.php` (server-only, never committed):

   ```php
   define('ADMIN_TOKEN', '<a long random string>');
   ```

   Without it, `admin_exportAll.php` and `admin_clearFinished.php` stay
   closed. `admin_schemaCheck.php` works without it, by design.
3. Visit `/admin_schemaCheck.php` — it names any migration still to run.

## Deploy

```bash
gh workflow run deploy.yml
```

Then `gh run watch`, and confirm the run's headSha matches HEAD. Push-triggered
runs are unreliable on this host; the manual dispatch is the real path.

The workflow lints every `backend/*.php` with `php -l` before uploading and
fails the build on a parse error. There is no local PHP runtime — that gate is
the only PHP syntax check in the project.

## Local development

```bash
cd frontend
npm install
npm run dev
```

`npm run dev` proxies `/api/*` to the **live** install (there is no local PHP),
so dev talks to the real database. Keep that in mind before doing anything
destructive.

## Conventions

- One commit per design decision, with the evidence in the message.
- Tailwind: literal class strings only — never interpolated class names.
- PHP single-quoted strings: escape apostrophes.
- Multi-file edits: an assert-guarded Python script run with `py -X utf8`.
