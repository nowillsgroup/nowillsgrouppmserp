# Installing Nowills PMS on cPanel

For **shared and reseller cPanel hosting** — the kind where you get File Manager, phpMyAdmin and
Cron Jobs, but no SSH, no Composer and no Artisan.

> On **CyberPanel / OpenLiteSpeed** (Hostinger VPS and similar) use
> [INSTALL-CYBERPANEL.md](INSTALL-CYBERPANEL.md) instead: document roots, PHP versions, cron paths
> and git deploys all work differently there.

If you have SSH access, the shorter route in the [README](../README.md#installation) works and is
preferable. Everything below is the no-shell path.

Version 7.56.0 · PHP 8.3+ · MySQL 5.7+ / MariaDB 10.3+

---

## What you need before you start

| | |
|---|---|
| **PHP** | 8.3 or 8.4, chosen per-domain in *MultiPHP Manager* |
| **Extensions** | `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `ctype`, `json`, `dom`, `xml`, `fileinfo` — `gd`, `curl` and `zip` recommended |
| **Database** | one MySQL database + one user with `ALL PRIVILEGES` on it |
| **Disk** | ~250 MB (the `build/vendor` package tree is ~72 MB) |

The installer checks all of this for you and tells you exactly what to change if something is missing.

**You do not need Composer.** This project keeps its Composer packages committed in `build/vendor`, and
`artisan`, `public/index.php` and `tests/bootstrap.php` all load `build/vendor/autoload.php`. Upload that
folder with the project and the app runs as-is.

---

## Step 1 — Pick your layout

### Layout A — point the document root at `public/` (recommended)

On cPanel this is **Domains → your domain → Document Root**, set to
`/home/YOURUSER/pms/public`. Some hosts only let you set this for the primary domain or an addon domain;
if the field is greyed out, use Layout B.

Nothing outside `public/` is then reachable over HTTP, so `.env`, `bootstrap/`, `storage/` and `build/`
are protected by the filesystem rather than by rewrite rules.

### Layout B — upload the whole project to `public_html`

Keep the domain pointing at `public_html` and upload the project **into** that folder. The
[`.htaccess`](../.htaccess) shipped in the project root forwards every request into `public/` and refuses
to serve `.env`, `.git`, `bootstrap`, `build`, `storage`, `vendor`, `database`, `tests`, `deploy` and
`docs`.

This works, but it depends on `mod_rewrite` and on `AllowOverride` being permissive. Verify it after
uploading — see [Step 6](#step-6--verify) — rather than assuming.

> Layout B is what the `.htaccess` in the project root is for. If you use Layout A you can delete it.

---

## Step 2 — Upload

Use **File Manager** → *Upload*, or ZIP the project and use *Extract*. Upload:

- everything at the project root — `app/`, `bootstrap/`, `build/`, `config/`, `database/`, `public/`, `resources/`, `routes/`, `storage/`, `vendor/`, `artisan`, `composer.json`
- the hidden files `.htaccess` and `.env.example`

You can leave behind `.git/`, `tests/` and `docs/` if you want a smaller upload — but if you keep `docs/`
in Layout B, make sure the `.htaccess` block list above is in place, because it contains internal notes.

After uploading, set permissions (File Manager → *Change Permissions*):

| Path | Mode |
|---|---|
| `storage/` and everything under it | `755` (use `775` only if `755` is not writable) |
| `bootstrap/cache/` | `755` (or `775`) |
| `public/uploads/` | `755` (or `775`) |

Every other file and folder can stay at the default `644` / `755`. Never use `777` — it is refused on
some hosts and is a security problem on all of them.

---

## Step 3 — Create the database

**MySQL® Databases** in cPanel:

1. *Create New Database* — cPanel prefixes it with your account name, e.g. `myacct_pms`.
2. *Add New User* — e.g. `myacct_pmsuser`, with a strong password.
3. *Add User To Database* → tick **ALL PRIVILEGES** → *Make Changes*.

Write down the **full** prefixed name and the username. The password must not contain a line break;
the installer rejects that with a clear message rather than writing a broken `.env`.

You do not need to create any tables — the installer does that.

---

## Step 4 — Run the installer

Open:

```
https://your-domain.com/install.php
```

(In Layout B, `https://your-domain.com/install.php` also works — the `.htaccess` forwards it.)

You get five sections:

1. **Server check** — PHP version, extensions, writable folders, `build/vendor` present. The *Install*
   button stays disabled until every required row says **OK**.
2. **Database & site** — the values from Step 3, plus your site URL.
3. **Your first property** — the Hotel ID (what you type to log in, e.g. `NOWILLS`), its name, currency
   and time zone.
4. **Administrator account** — your own login. Pick a real password; 8 characters minimum.
5. **Demo data** — optional. Tick it for a trial or training install (two properties, ~200 reservations,
   staff logins). **Leave it off for a real hotel** — it contains fake guests and published demo passwords.

Press **Install Nowills PMS**. It writes `.env`, generates the application key, runs every migration,
creates your property and administrator, prepares the upload folders and caches the config.

If you ticked demo data this is the slow step — on shared hosting give it a minute or two. The installer
does not time out the request.

---

## Step 5 — Lock the installer

The installer refuses to run twice, but it still reports your PHP configuration and folder permissions to
anyone who loads it. **Delete `public/install.php`** (and the copy in the project root, if Layout B put
one there) as soon as the install finishes.

Then open your site and log in with **Hotel ID** + **username** + **password** from Step 4.

---

## Step 6 — Verify

| Check | How |
|---|---|
| The site loads | `https://your-domain.com/` shows the login page, not a directory listing or a 500 |
| Sensitive paths are blocked | `https://your-domain.com/.env` → **403**. Also try `/bootstrap/cache/config.php` → **403** |
| Uploads work | Log in → **Settings** → upload a logo |
| Writes work | Log in at all; a "permission denied" on `storage/` shows up here first |

In Layout B, test `/.env` specifically. If it downloads the file instead of returning 403, your host
ignores `.htaccess` — switch to Layout A or ask your host to enable `AllowOverride`.

---

## Step 7 — Cron

Almost everything scheduled — OTA calendar sync, backups, GRA E-VAT retries, pre-arrival e-mails, the
night audit safety nets — hangs off a single cron entry.

cPanel → **Cron Jobs** → *Add New Cron Job*:

| Field | Value |
|---|---|
| Common Settings | *Once Per Minute* |
| Command | `/usr/local/bin/php /home/YOURUSER/pms/artisan schedule:run >> /dev/null 2>&1` |

Replace the path with your real one. If `/usr/local/bin/php` is not the CLI PHP on your host, find the
right binary — on EasyApache 4 it is usually under `/opt/cpanel/ea-php83/root/usr/bin/php` — or ask your
host. The installer shows the exact line to paste, already filled in, on its completion screen.

> **`php` alone will not work** in the cPanel cron box: the job runs without your shell's `PATH`.

Some hosts disable `proc_open`. Three scheduled commands prefer a background worker; the scheduler
detects this and runs them in the foreground instead. Nothing breaks, a cron run may just take longer.

---

## Step 8 — Go live

- **HTTPS.** cPanel → *SSL/TLS Status* → *Run AutoSSL*. Then set `APP_URL` in `.env` to the `https://`
  address. HSTS is sent automatically once the app sees HTTPS.
- **Production mode.** The installer already writes `APP_ENV=production` and `APP_DEBUG=false`. Leave them.
- **Change every demo password** you did not set yourself (or install without demo data).
- **Mail.** `.env` ships with `MAIL_MAILER=log`, so nothing is actually sent — confirmations and
  reminders are written to `storage/logs/`. Put your SMTP credentials in *Email Accounts* and update
  `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` when you are ready.
- **Backups.** `pms:backup` runs nightly at 02:30 through the cron entry and keeps 14 days, written in
  pure PHP (no `mysqldump` needed). Download them from `storage/app/backups` and store them **off-site** —
  a backup on the same server is not a backup.
- **After any `.env` change**, the cached config is stale. Either delete `bootstrap/cache/config.php`
  in File Manager, or load `/install.php`... which you deleted. Deleting the single file is the reliable
  route; the app rebuilds nothing automatically, so do it deliberately.

---

## Troubleshooting

| Symptom | Cause and fix |
|---|---|
| **500 on every page** | Usually a folder permission or a stale cache. Set `storage/` and `bootstrap/cache/` to 755, then delete everything in `bootstrap/cache/`. Check `storage/logs/laravel.log`. |
| **Blank white page** | `APP_DEBUG=false` hides the real error. Temporarily set `APP_DEBUG=true`, reload, and read the message. **Set it back.** |
| **"could not find driver"** | The `pdo_mysql` extension is off. *Select PHP Version* → *Extensions* → tick it. |
| **403 on `/`** | `Options -Indexes` or a rule conflict — or the host ignores `.htaccess` entirely (Layout B then will not work). |
| **404 on every page except `/`** | `mod_rewrite` is off, or `AllowOverride` does not permit `FileInfo`. Ask your host, or use Layout A. |
| **CSS/JS unstyled** | The rewrite into `public/` is failing, so `/css/app.css` 404s. Confirm the `.htaccess` was uploaded (it is a hidden file — many FTP clients skip it). |
| **Login loops back to the login page** | `APP_URL` does not match the address in the browser, or the session folder is not writable. Fix `APP_URL`, then check `storage/framework/sessions`. |
| **Installer says `.env` is not writable** | The project folder is owned by a different user than the PHP process. Set the folder to 755, or ask your host to fix ownership. |
| **"Cannot connect to MySQL"** | Use the **full** prefixed database name and username, and make sure the user was added to that database with ALL PRIVILEGES. |
| **Cron does nothing** | Use the absolute PHP path, not `php`. Confirm with a temporary job that writes a file, and check the email cPanel sends for the job's output. |

---

## Why there is no `storage:link` step

Some Laravel deployments need `php artisan storage:link` to expose uploads. This one does not: the app
writes uploads, logos and room images straight into `public/uploads/`, and there is no
`config/filesystems.php` symlink in the request path. That is deliberate — symlink creation is disabled
on a lot of shared hosting, and this avoids the failure entirely.
