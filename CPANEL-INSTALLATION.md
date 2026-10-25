# Nowills PMS — cPanel Direct Installation Guide

## What's in this ZIP

This ZIP contains the **complete Nowills PMS application** — all files needed for a fresh cPanel installation. The archive has a **flat root** (no top-level folder), so when you extract it, files land in their correct relative paths directly.

### Included
- **Complete Laravel application** — all app code, controllers, models, services, views, routes, config
- **Composer dependencies** — pre-installed in `build/vendor/` (no Composer needed)
- **Public assets** — CSS, JS, fonts in `public/`
- **Robita Lock Module** — Smart key door lock management (devices, credentials, events, settings)
- **POS Module** — Point of Sale terminal, order terminal, menu catalog, cart, KOT printing, settlement
- **Billing Reconciliation** — AP/AR reconciliation with foreign currency support
- **Migration Importers** — Opening balances, inventory, supplier importers
- **Preview Files** — HTML previews for POS Terminal, Order Terminal, Robita Locks
- **.env.example** — environment configuration template
- **Apache .htaccess** — ready for cPanel/Apache

## Installation Steps (cPanel)

### 1. Download and Upload
1. Download `nowillsgrouppmserp-cpanel.zip` from Google Drive
2. In cPanel, open **File Manager** and navigate to where you want the app
3. Click **Upload** and select the ZIP file
4. Wait for upload to complete

### 2. Extract
1. Right-click the uploaded ZIP → **Extract**
2. Choose "Extract to: current directory"
3. All files will be placed in their correct locations

### 3. Configure Environment
1. Copy `.env.example` to `.env`:
   - In File Manager, go to the extracted files
   - Duplicate `.env.example` and rename to `.env`
2. Edit `.env` and update:
   ```
   APP_URL=https://yourdomain.com
   DB_HOST=localhost
   DB_PORT=3306
   DB_DATABASE=your_database_name
   DB_USERNAME=your_database_user
   DB_PASSWORD=your_database_password
   ```
3. Save the file

### 4. Set Permissions
Make these directories writable (cPanel → File Manager → Permissions):
- `storage/` → 755
- `bootstrap/cache/` → 755
- `public/uploads/` → 777 (if it exists)

### 5. Run Migrations
**Option A — cPanel Terminal:**
1. Open **Terminal** in cPanel
2. Navigate: `cd /path/to/your/app`
3. Run: `php artisan migrate`

**Option B — Temporary web route (no Terminal needed):**
1. Create `migrate.php` in the app root:
   ```php
   <?php
   require __DIR__.'/build/vendor/autoload.php';
   $app = require __DIR__.'/bootstrap/app.php';
   $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
   $kernel->call('migrate');
   echo "Migration complete";
   ```
2. Visit `https://yourdomain.com/migrate.php` in your browser
3. Delete `migrate.php` after completion

### 6. Generate App Key
Run: `php artisan key:generate`
(Or add `APP_KEY=` to `.env` and run migrations — Laravel will auto-generate it)

### 7. Clear Cache
```bash
php artisan cache:clear
php artisan config:clear
php artisan view:clear
php artisan route:clear
```
(Use the temporary web route method above if Terminal is unavailable)

### 8. Seed Database (Optional)
To populate demo data:
```bash
php artisan db:seed
```

### 9. Verify in Browser
1. Visit `https://yourdomain.com` — you should see the login page
2. Log in with your admin credentials
3. Check that these menus appear:
   - **Front Office → Interfaces → Robita Locks**
   - **Operations → POS / Restaurant → POS Terminal**
4. Preview pages:
   - `https://yourdomain.com/preview-pos.html`
   - `https://yourdomain.com/preview-pos-order.html`
   - `https://yourdomain.com/preview-robita.html`

## Important Notes

- **No Composer needed** — all dependencies are pre-installed in `build/vendor/`
- **No SSH needed** — everything works through cPanel File Manager and Terminal
- **PHP version** — requires PHP 8.3+ (check cPanel → Select PHP Version)
- **Database** — MySQL 5.7+ or MariaDB 10.3+
- **Backup** — always backup your database before running migrations

## Troubleshooting

### Blank page / 500 error
- Check `storage/logs/laravel.log` for errors
- Verify `.env` has correct database credentials
- Ensure `APP_KEY` is set in `.env`

### Migration fails
- Check database user has CREATE TABLE privileges
- Verify `.env` database settings are correct
- Try: `php artisan migrate:fresh` (drops all tables first — **only on fresh installs**)

### CSS/JS not loading
- Ensure files are in `public/css/` and `public/js/`
- Check `.htaccess` is in the `public/` directory
- Verify `public/` is the web root in cPanel

### Robita Locks menu not showing
- Check that `robita.manage` permission is assigned to your user role
- Verify menu entry in `config/pms.php` under "Interfaces"

### POS Terminal not accessible
- Check that `pos.view` permission is assigned
- Verify POS menu entries in `config/pms.php`

### Preview pages show blank
- Verify `public/css/app.css` is accessible at `https://yourdomain.com/css/app.css`
- Check that preview HTML files are in the `public/` directory
