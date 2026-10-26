# Deploying Nowills PMS on a Hostinger VPS with CyberPanel

Target in this guide: **https://pms.nowills.com** — the PMS running on a CyberPanel
(OpenLiteSpeed) server, deployed straight from the GitHub repository
`nowillsgroup/nowillsgrouppmserp`, with a post-deploy hook so a `git push` becomes a live update.

Version 7.54.0 · PHP 8.3+ · MySQL 8 / MariaDB 10.3+ · OpenLiteSpeed

> This is the CyberPanel counterpart of [INSTALL-CPANEL.md](INSTALL-CPANEL.md). The CMS-style
> "Git deploy" in Hostinger's hPanel does not apply to a VPS with CyberPanel — code lives on the
> VPS filesystem and is pulled over SSH (or by CyberPanel's Git Manager).

**This deployment** (these values are used by every command below — check them against *List Websites* first):

| | Value |
|---|---|
| Domain | `pms.nowills.com` — created in CyberPanel as its **own website** |
| Site path | `/home/pms.nowills.com/public_html` |
| Site user | `pms.nowills.com` |
| Repository | `https://github.com/nowillsgroup/nowillsgrouppmserp.git` (public, branch `main`) |
| Updates | **manual pull over SSH** after each merge to `main` (step 9) — no webhook, nothing exposed |

---

## What the finished layout looks like

```
/home/pms.nowills.com/public_html/          ← the git checkout (repo root)
├── app/ bootstrap/ build/ config/ database/ resources/ routes/ storage/
├── public/                                 ← the ONLY web-reachable folder
│   ├── index.php  .htaccess  install.php  css/ js/ uploads/
├── .env                                    ← written by the installer, never in git
└── deploy/cyberpanel/post-deploy.sh        ← post-deploy hook (steps 7 and 9)
```

The virtual host's **Document Root must point at `public/`**. Everything that is secret or
heavy — `.env`, `bootstrap/cache/config.php` (which contains `APP_KEY` and the DB password),
`build/vendor`, `storage/` — then sits *above* the document root and simply cannot be fetched
over HTTP, whatever rewrite rules do or do not load.

> Hostinger's CyberPanel image gives you root SSH. Do the one-time steps in the CyberPanel UI,
> then use SSH (or Hostinger's **Browser terminal** in hPanel) for the commands. Nothing in this
> guide needs Composer — the packages ship in `build/vendor` (≈72 MB, part of the repo).

Two values you will need throughout; note them down:

| | Example | Where to find it |
|---|---|---|
| **Site path** | `/home/pms.nowills.com/public_html` | *Websites → List Websites* — the path shown for the domain |
| **Site user** | `pms.nowills.com` | `stat -c '%U' <site path>` over SSH, or the owner shown in File Manager |

CyberPanel gives every **main website** a system user named after the domain (`pms.nowills.com`)
and a `/home/<domain>/public_html` folder — that is the layout this guide assumes. If you later add
a property as a *child domain* of another site, the path becomes `/home/<master>/<child>` and the
owner is the master's user; everything else in the guide is identical, just with those two values
swapped.

---

## The whole deployment on one screen

Three clicks in CyberPanel (steps 2–4), one vHost edit (step 6), then this block over SSH as root.
Every line is explained in the step it comes from — read those before running this.

```bash
SITE=/home/pms.nowills.com/public_html
SITE_USER=$(stat -c '%U' "$SITE")
echo "$SITE owner=$SITE_USER"                    # expect /home/pms.nowills.com/public_html owner=pms.nowills.com
ls -A "$SITE"                                    # back up anything you want to keep first

# 5. code onto the server, owned by the site user
find "$SITE" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
sudo -u "$SITE_USER" git clone https://github.com/nowillsgroup/nowillsgrouppmserp.git "$SITE"

# 6. <-- now do the vHost Conf edit: docRoot must end in /public, then restart LiteSpeed

# 7. runtime folders + permissions
sudo -u "$SITE_USER" bash "$SITE/deploy/cyberpanel/post-deploy.sh"

# 4. create the database in the panel, then open
#    https://pms.nowills.com/install.php   (NOWILLS · GHS · Africa/Accra · NO demo data)
#    and when it finishes, remove the installer:
rm -f "$SITE/public/install.php"

# 8. cron: Manage -> Cron Jobs -> once per minute
#    /usr/local/lsws/lsphp83/bin/php /home/pms.nowills.com/public_html/artisan schedule:run >> /dev/null 2>&1
```

Then check the table in step 10 and the go-live list below it.

> **Already had something in `public_html`?** The `find … rm` clears the folder. Download it first
> (File Manager → compress), or clone into a fresh folder name and point the docroot at *that*
> `public/`, e.g. `/home/pms.nowills.com/app/public`.

---

## Step 1 — DNS

In Hostinger (hPanel → **Domains → DNS / Nameservers → DNS zone** for `nowills.com`) add:

| Type | Name | Value | TTL |
|---|---|---|---|
| `A` | `pms` | your VPS IPv4 address | 300 |

Wait for it to resolve before asking for a certificate:

```bash
dig +short pms.nowills.com
# must echo the VPS IP
```

---

## Step 2 — Confirm the site and its certificate

The website already exists, so this is a check, not a build. In
**Websites → List Websites**, confirm for `pms.nowills.com`:

1. The **path** is `/home/pms.nowills.com/public_html` (that is what the commands below use).
2. The **PHP** column shows 8.3 or 8.4 — if not, fix it in step 3.
3. **Manage → SSL** shows an issued certificate. If not, and DNS already answers,
   **Manage → SSL → Issue SSL** → Let's Encrypt.
4. Optional but recommended: **Manage → SSL** → enable **Force HTTPS**.

`https://pms.nowills.com` should answer now — a 404, a CyberPanel placeholder or a directory
listing is fine; it becomes the PMS in step 7.

> Changing the document root in step 6 does **not** break certificates: CyberPanel answers the
> Let's Encrypt challenge from `/usr/local/lsws/…/.well-known/acme-challenge` through its own
> `context` block, not from the site's document root. Renewals keep working.

---

## Step 3 — Make sure PHP is 8.3+ and has the right extensions

The site's PHP and the *cron* PHP are separate settings — check both.

```bash
ls /usr/local/lsws/ | grep lsphp          # which versions are installed
/usr/local/lsws/lsphp83/bin/php -v        # should print 8.3.x or newer
```

* **Per-site PHP:** *Manage → Configurations → Change PHP* → **PHP 8.3** (or 8.4) → *Change PHP*.
* **Not installed?** Only `lsphp81`/`lsphp82` present? Install 8.3 over SSH:

  ```bash
  apt-get update
  apt-get install -y lsphp83 lsphp83-common lsphp83-mysql lsphp83-mbstring \
      lsphp83-xml lsphp83-gd lsphp83-curl lsphp83-zip lsphp83-intl lsphp83-opcache
  systemctl restart lsws
  ```

* **Extensions** the installer insists on: `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`,
  `ctype`, `json`, `filter`, `hash`, `session`, `dom`, `xml`, `fileinfo`.
  `gd`, `curl`, `zip` are strongly recommended (logos/menu photos, OTA sync, exports):

  ```bash
  /usr/local/lsws/lsphp83/bin/php -m | tr '\n' ' '
  ```

  Anything missing: `apt-get install -y lsphp83-<name>` (package suffix: `mysql`, `mbstring`,
  `xml`, `gd`, `curl`, `zip`, `intl`), then restart `lsws`.

> `session`: CyberPanel's lsphp builds ship the session extension; if it is missing, PHP cannot
> keep a login. Install `lsphp83-common` first — it usually brings it in.

---

## Step 4 — Create the database

**Databases → Create Database** (CyberPanel creates it *under the site user*, which is exactly
what the installer needs):

| Field | Example |
|---|---|
| Database name | `pms` |
| User | `pmsuser` |
| Password | generate a strong one, **no line breaks** |

CyberPanel may prefix the finished names with the site — copy **exactly** what the panel shows
after you save (the Database list is the truth; `nowills_pms` and `nowills_pmsuser` in this example).
Grant **ALL PRIVILEGES** (the Create Database screen does this for you). DB host on the same VPS:
`localhost`.

phpMyAdmin is available from the **Databases** page if you want to look inside.

---

## Step 5 — Get the code onto the server

The repository is **public**, so a plain HTTPS clone needs no keys.

```bash
# as root
SITE=/home/pms.nowills.com/public_html
SITE_USER=$(stat -c '%U' "$SITE")

# print both and eyeball them before the next command — it deletes what it names
echo "$SITE  owner=$SITE_USER"

# 1. clear the folder — on a fresh site it only holds CyberPanel's index.html + error pages
ls -A "$SITE"                    # look first; if there is anything real, download it before deleting
find "$SITE" -mindepth 1 -maxdepth 1 -exec rm -rf {} +

# 2. clone AS THE SITE USER so PHP can write .env, storage/ and uploads/
sudo -u "$SITE_USER" bash -c "git clone https://github.com/nowillsgroup/nowillsgrouppmserp.git '$SITE'"

# 3. the repo's default branch is main — confirm you are on it
sudo -u "$SITE_USER" bash -c "git -C '$SITE' checkout main && git -C '$SITE' log -1 --oneline"

# 4. sanity: the Composer tree must be there
ls -l "$SITE/build/vendor/autoload.php"
```

If you would rather let CyberPanel clone the repository for you, use **Manage → Manage Git → Attach
Now** (see the appendix at the end of this guide) — it needs an empty folder, which is why the `find
… -delete` above is there. Cloning by hand as shown above keeps you independent of that feature, and
manual pulls are all this deployment needs.

If `sudo -u` reports an unknown user, read the real owner first — `stat -c '%U' "$SITE"` — and use
that name. (CyberPanel creates these users without a login shell, which is why `sudo -u` is used
instead of `su -`.)

**Do not clone as root.** Files owned by root break `storage/`, `bootstrap/cache/`, `public/uploads/`
and the installer's `.env` write. If it already happened:

```bash
stat -c '%U:%G' /home/pms.nowills.com/public_html      # should be the site user
chown -R pms.nowills.com:pms.nowills.com /home/pms.nowills.com/public_html
```

---

## Step 6 — Point the document root at `public/`

**Websites → List Websites → (pms.nowills.com) Manage → vHost Conf**.

The first line is the document root. Append `/public` to it:

```apacheconf
docRoot                   /home/pms.nowills.com/public_html/public
```

Notes:

* A main website normally shows `docRoot $VH_ROOT/public_html` there. Either edit it in place —
  `docRoot $VH_ROOT/public_html/public` — or replace the whole line with the absolute path above;
  both work, the absolute path is just harder to get wrong. (For a *child domain* on this panel,
  `$VH_ROOT` is the master's home, not the site folder — use the absolute path there.)
* Leave everything else alone. In particular the `rewrite { … }` block must keep
  `enable 1` and `autoLoadHtaccess 1` — that is what makes `public/.htaccess` (Laravel's front
  controller) work on OpenLiteSpeed:

  ```apacheconf
  rewrite  {
    enable                  1
    autoLoadHtaccess        1
    ...
  }
  ```

* `open_basedir` must include the **project root**, not just `public/`. CyberPanel sets it from the
  site folder by default, which is correct; if the app reports an `open_basedir restriction`, open
  **Manage → open_basedir** and set it to the site path (or disable the protection for this site).

**Save**, then restart the web server — **Server Status → LiteSpeed Status → Reboot Litespeed**, or:

```bash
systemctl restart lsws
```

---

## Step 7 — Permissions, then run the installer

```bash
sudo -u pms.nowills.com bash /home/pms.nowills.com/public_html/deploy/cyberpanel/post-deploy.sh
```

(As root also works — the script re-chowns what it touches to the site user — but running it as the
site user keeps every file's owner correct from the start.)

That script (safe to run any number of times, before or after the install) creates the gitignored
runtime folders (`public/uploads{,/logos,/menu,/room-images}`, `storage/app/backups`, the framework
caches), sets **755 folders / 644 files** — never 777 — and, when run as root, hands them back to
the site user. It logs every step to `storage/logs/deploy.log`.

Now open:

```
https://pms.nowills.com/install.php
```

Five sections — *Server check*, *Database & site*, *Your first property*, *Administrator account*,
*Demo data*:

| Section | What to enter |
|---|---|
| Server check | Must be all **OK** before the button enables. Anything red names its own fix. |
| Database & site | `localhost`, port `3306`, the **full** DB name/user from step 4, and site URL `https://pms.nowills.com` |
| Property | Hotel ID (what you type at login, e.g. `NOWILLS`), name, currency `GHS`, time zone `Africa/Accra` |
| Administrator | Your own login. 8+ characters. |
| Demo data | **Leave OFF for a real property** — it inserts fake guests and published demo passwords |

The installer writes `.env`, generates `APP_KEY`, runs every migration, creates the property and the
admin, prepares the upload folders and caches config/routes/views. Do not close the tab; it ignores
timeouts.

Then **remove the installer** — it reports your PHP configuration and folder permissions to anyone
who loads it:

```bash
rm -f /home/pms.nowills.com/public_html/public/install.php
```

It refuses to install twice (it drops `storage/framework/installed.lock`), but delete it anyway.
If a later `git pull` brings it back (a commit that touches the file), delete it again — or make the
block permanent in **vHost Conf**:

```apacheconf
# inside the existing rewrite { rules <<<END_rules … END_rules } block
RewriteRule ^install\.php$ - [F,L]
```

---

## Step 8 — Cron (one entry, once a minute)

Almost everything scheduled depends on it: nightly SQL backup at 02:30, OTA iCalendar sync every
15 minutes, GRA E-VAT retries, pre-arrival e-mails, webhook deliveries, the night-audit and
general-ledger safety nets (`routes/console.php` lists all of them).

**Websites → List Websites → Manage → Cron Jobs → ADD CRON** (this page writes into the *site user's*
crontab, which is what you want):

| Field | Value |
|---|---|
| Minute | `*` |
| Hour / Day / Month / Weekday | leave empty (CyberPanel treats empty as every) |
| Command | `/usr/local/lsws/lsphp83/bin/php /home/pms.nowills.com/public_html/artisan schedule:run >> /dev/null 2>&1` |

Use the **absolute lsphp path** — cron has no `PATH`, and the global `php` may be a different
version. Confirm the version first with `/usr/local/lsws/lsphp83/bin/php -v`.

If you instead add it to CyberPanel's **root** cron (Dashboard → *Cron Jobs*), run it as the site
user so the log files it writes stay writable by PHP:

```cron
* * * * * sudo -u pms.nowills.com /usr/local/lsws/lsphp83/bin/php /home/pms.nowills.com/public_html/artisan schedule:run >> /dev/null 2>&1
```

Prove it works instead of hoping:

```bash
cd /home/pms.nowills.com/public_html
sudo -u pms.nowills.com /usr/local/lsws/lsphp83/bin/php artisan schedule:list | head
tail -n 20 storage/logs/laravel.log      # the first cron run writes here within a minute
```

---

## Step 9 — Updating the live site (manual pull)

This is how a release reaches production: merge the pull request into `main` on GitHub, then run two
commands on the VPS. No keys to manage, no webhook, no port exposed.

```bash
SITE=/home/pms.nowills.com/public_html
SITE_USER=$(stat -c '%U' "$SITE")

# 1. get the new code (fast-forward only: it fails loudly instead of inventing a merge)
sudo -u "$SITE_USER" bash -c "git -C '$SITE' pull --ff-only"

# 2. make it live — this is the part people forget
sudo -u "$SITE_USER" bash "$SITE/deploy/cyberpanel/post-deploy.sh"
```

Why step 2 is not optional: `git pull` alone leaves the compiled config, routes and Blade views
from the previous release in `bootstrap/cache/` and `storage/framework/views/`. The new classes are
on disk, but the running app keeps executing the old ones — so migrations don't apply and fixes
appear not to work. The hook clears them, recreates the gitignored runtime folders, sets 755/644,
runs `artisan migrate --force`, re-caches config/routes/views, and writes a line to
`storage/logs/deploy.log` for each step. It is safe to run twice.

Rollback:

```bash
sudo -u "$SITE_USER" bash -c "git -C '$SITE' reset --hard <previous-commit>"
bash "$SITE/deploy/cyberpanel/post-deploy.sh"
```

If the bad release added migrations, also restore the pre-deploy database backup from
`storage/app/backups/` (or the CyberPanel snapshot) — code rollback does not undo a schema change.

### Optional later — push-to-deploy with CyberPanel Git Manager

Only if you get tired of the two commands (and are willing to expose port 8090 to GitHub).
It does exactly the same pull, plus the same hook as the post-pull command.

1. **Manage → Manage Git** (top right of the website panel).
2. *Select Folder to Track*: the site folder. It already contains a git repository from step 5, so
   CyberPanel adopts it. (If you skipped step 5 and let CyberPanel clone, the folder must be empty.)
3. **View/Setup Remote** → *Deployment Key*: copy the key →
   GitHub → repo → **Settings → Deploy keys → Add deploy key** → paste → *Allow write access* **off**
   (read is enough). Back in CyberPanel: host `github.com`, username `nowillsgroup`,
   repo `nowillsgrouppmserp` → **Save**.
   *A public repository still needs this key, because CyberPanel pulls over SSH
   (`git@github.com:nowillsgroup/nowillsgrouppmserp.git`).*
4. **Commands** — the hook that runs after every successful pull. Paste exactly this one line
   (CyberPanel's input filter rejects `;`, `&&`, `|`, `$`):

   ```
   bash /home/pms.nowills.com/public_html/deploy/cyberpanel/post-deploy.sh
   ```

5. Copy the **Webhook URL** CyberPanel shows (it already carries the site's secret).
6. GitHub → repo → **Settings → Webhooks → Add webhook**:
   * Payload URL: the URL from step 5
   * Content type: `application/json`
   * Secret: only if your CyberPanel version shows a separate Secret field
   * **SSL verification: disable** when the URL is `https://<IP>:8090` — the CyberPanel panel
     certificate is self-signed and GitHub would reject it. (With a valid panel hostname + SSL you
     can leave verification on.)
   * Events: *Just the push event* · Active: ✔
7. Push something to `main`, then check **Manage Git** (the pull log) and
   `storage/logs/deploy.log` on the server.

Caveats worth knowing:

* The webhook has to reach port **8090** from the internet. If your firewall restricts 8090 to your
  own IP, the webhook will time out — keep using the manual pull (step 9).
* The pull target for the webhook is the **site folder** for a main website and the child domain's
  path for a subdomain — i.e. always the folder you pointed the document root into. Nothing to
  configure.
* CyberPanel 2.4.2 had a bug where a security filter rejected webhook JSON containing `{}` and the
  pull never ran. If deliveries show a 403/500 in GitHub, update CyberPanel, or go back to the
  manual pull (step 9).
* Git Manager pulls `git pull` (no `--ff-only`) as the site user. If you ever committed directly on
  the server via File Manager, reconcile first (`git status`, `git stash`, `git checkout -- <file>`).
* Nothing in a pull touches `.env`, `public/uploads/` or `storage/` — they are gitignored, so logos,
  uploads, backups and your keys survive every deploy.

---

## Step 10 — Verify

| Check | Expected |
|---|---|
| `https://pms.nowills.com/` | The Nowills login page (Hotel ID + username + password) |
| `http://pms.nowills.com/` | 301 → `https://` |
| `https://pms.nowills.com/css/app.css` | CSS loads (if 404: docroot or `autoLoadHtaccess` — step 6) |
| `https://pms.nowills.com/.env` | **403** — the file is above the document root, so it is not even reachable |
| `https://pms.nowills.com/install.php` | 404 (deleted) — that is correct |
| `https://pms.nowills.com/up` | A small JSON health response from Laravel |
| Login, then **Settings → upload a logo** | Saves, and the image renders (proves `public/uploads` is writable) |
| `git -C <site> status` | clean |

Log in with **Hotel ID + username + password** from the installer. Then work through
[step 8 of the cPanel guide](INSTALL-CPANEL.md#step-8--go-live) for mail, backups and hardening —
all of it applies here.

---

## Going live — the short list

* **`.env`**: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://pms.nowills.com`. The
  installer writes the first two; check `APP_URL` after the certificate exists.
  After any `.env` edit, delete `bootstrap/cache/config.php` (or run the hook) — the cached config
  is otherwise stale and your change does nothing.
* **Mail**: `.env` ships `MAIL_MAILER=log`, so confirmations and reminders are only written to
  `storage/logs/`. Hostinger SMTP:

  ```dotenv
  MAIL_MAILER=smtp
  MAIL_SCHEME=smtps          # TLS on 465; use "smtp" with MAIL_PORT=587 for STARTTLS
  MAIL_HOST=smtp.hostinger.com
  MAIL_PORT=465
  MAIL_USERNAME=no-reply@nowills.com
  MAIL_PASSWORD=…
  MAIL_FROM_ADDRESS="no-reply@nowills.com"
  MAIL_FROM_NAME="${APP_NAME}"
  ```

  This app is configured with `MAIL_SCHEME` (not the old `MAIL_ENCRYPTION`). Send a test from the
  app afterwards, and remember to clear `bootstrap/cache/config.php` after the edit.
* **Backups**: `pms:backup` runs nightly at 02:30 through the cron entry and keeps 14 days in
  `storage/app/backups` (pure PHP — no `mysqldump` needed). Download them and keep a copy **off the
  VPS**; also take a CyberPanel/Provider snapshot before your first real data lands.
* **Panel hygiene**: keep 8090 closed to the world once your IP is allow-listed, use SSH keys only,
  and keep CyberPanel updated (`cyberpanel upgrade` or the panel's upgrade button).
* **No demo data**, and change any password you did not set yourself.

---

## Troubleshooting (CyberPanel / OpenLiteSpeed)

| Symptom | Cause and fix |
|---|---|
| **500 on every page** | Permissions or a stale cache. `sudo -u pms.nowills.com bash /home/pms.nowills.com/public_html/deploy/cyberpanel/post-deploy.sh`; then read `/usr/local/lsws/logs/stderr.log`, the site's `logs/<domain>.error_log`, and `storage/logs/laravel.log`. Temporarily set `APP_DEBUG=true` to see the real message — set it back. |
| **404 on everything except `/`** | Document root still `public_html`, or `autoLoadHtaccess 1` missing from the `rewrite` block. Fix step 6 and restart `lsws`. |
| **Directory listing instead of the app** | Document root still points at the site folder. Point it at `public/`. |
| **Blank white page** | `APP_DEBUG=false` is hiding a fatal error — check `storage/logs/laravel.log`, or `build/vendor` is missing (re-clone). |
| **`could not find driver`** | `lsphp83-mysql` not installed: `apt-get install -y lsphp83-mysql && systemctl restart lsws`. |
| **`open_basedir restriction in effect`** | **Manage → open_basedir**: allow the project root (the folder above `public/`), not just `public/`. |
| **403 on `/`** | The document root path is wrong (typo, or trailing `/public/public`), or the folder is not readable by the site user. `ls -ld <site>/public`. |
| **Login bounces back to the login page** | `APP_URL` does not match the browser address, or `storage/framework/sessions` is not writable by the site user. Fix `APP_URL`, delete `bootstrap/cache/config.php`, fix ownership. |
| **Uploads 404 / logo missing** | `public/uploads` missing or unwritable — the hook creates it; check the owner is the site user. |
| **Installer says `.env` is not writable** | The checkout is owned by root (you cloned as root). `chown -R pms.nowills.com:pms.nowills.com <site>`. |
| **Installer refuses to run / says locked** | It already installed. To start over (empty database), delete `storage/framework/installed.lock` and re-upload `public/install.php`. |
| **503 Maintenance** | `rm storage/framework/maintenance.php` (or `artisan up`). |
| **Scheduled work never happens** | Cron command must use the absolute `lsphp` path and the absolute `artisan` path. Check the cron log / that the job landed in the right user's crontab (`crontab -u pms.nowills.com -l`). |
| **New release deploys but the UI is unchanged** | Compiled config/routes/views were not cleared. Run the post-deploy hook (step 9) — that is exactly what it does. |
| **Webhook says 403 / nothing pulls** | Use the exact URL (with secret) from the panel; disable SSL verification for a self-signed panel cert; make sure 8090 is reachable from GitHub; on CyberPanel 2.4.2 upgrade the panel. |
| **CSS/JS 404 after a pull** | `public/.htaccess` was overwritten or the pull left the working tree dirty — `git -C <site> status`, then `git checkout -- public/.htaccess`. |

---

## Release checklist (pin this next to the deploy commands)

1. Pull request reviewed and **merged into `main`** on GitHub.
2. **Backup first** for release with migrations: `sudo -u pms.nowills.com /usr/local/lsws/lsphp83/bin/php
   /home/pms.nowills.com/public_html/artisan pms:backup` (or take a CyberPanel snapshot).
3. **Pull + hook** — the two commands in [step 9](#step-9--updating-the-live-site-manual-pull).
4. **Verify**: site loads, log in, open one module; read `storage/logs/deploy.log` and
   `storage/logs/laravel.log`.
5. **If it breaks**: reset to the previous commit, run the hook, restore the database if the release
   had migrations.

Cron, SSL, `.env`, uploads and the database are untouched by every one of those steps — a pull never
overwrites them (all gitignored).
