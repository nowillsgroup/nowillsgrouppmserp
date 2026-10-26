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
- **Web Installer** — `install.php` — browser-based setup, no SSH needed
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

### 3. Set Permissions
Make these directories writable (cPanel → File Manager → Change Permissions):
- `storage/` → 755
- `bootstrap/cache/` → 755

### 4. Open the Web Installer
1. Visit `https://yourdomain.com/install.php` in your browser
2. The installer will:
   - Check PHP version and extensions
   - Let you enter database credentials
   - Test the database connection
   - Write `.env` with your settings and a generated APP_KEY
   - Run all database migrations
   - Clear cache
3. Click **Test Connection** to verify your database
4. Click **Install Application** to complete setup

### 5. Verify in Browser
1. Visit `https://yourdomain.com` — you should see the login page
2. Log in with your admin credentials
3. Check that these menus appear:
   - **Front Office → Interfaces → Robita Locks**
   - **Operations → POS / Restaurant → POS Terminal**
4. Preview pages:
   - `https://yourdomain.com/preview-pos.html`
   - `https://yourdomain.com/preview-pos-order.html`
   - `https://yourdomain.com/preview-robita.html`

### 6. Secure the Installer (Optional)
After installation completes, delete `install.php` from cPanel File Manager:
- Right-click `install.php` → **Delete**

## Important Notes

- **No Composer needed** — all dependencies are pre-installed in `build/vendor/`
- **No SSH needed** — the web installer handles everything
- **PHP version** — requires PHP 8.3+ (check cPanel → Select PHP Version → MultiPHP Manager)
- **Required extensions** — `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `ctype`, `json`, `dom`, `xml`, `fileinfo`
- **Database** — MySQL 5.7+ or MariaDB 10.3+
- **Backup** — always backup your database before running migrations

## Troubleshooting

### Installer shows "PHP Version Error"
- In cPanel, go to **MultiPHP Manager** and select PHP 8.3 or 8.4 for your domain

### Installer shows "Missing Extensions"
- In cPanel, go to **MultiPHP INI Editor** or contact your host to enable the required extensions

### Database connection fails
- Verify database name, username, and password
- Check that the database user has ALL PRIVILEGES on the database
- Try `localhost` instead of `127.0.0.1` as the database host

### Migration fails
- Check database user has CREATE TABLE privileges
- Verify `.env` database settings are correct (check `storage/logs/laravel.log`)

### Blank page / 500 error after install
- Check `storage/logs/laravel.log` for errors
- Ensure `storage/` and `bootstrap/cache/` are writable (755)
- Verify `APP_KEY` is set in `.env`

### CSS/JS not loading
- Ensure files are in `public/css/` and `public/js/`
- Check `public/.htaccess` exists
- Verify document root points to `public/` (or the root `.htaccess` handles it)

### Robita Locks menu not showing
- Check that `robita.manage` permission is assigned to your user role
- Verify menu entry in `config/pms.php` under "Interfaces"

### POS Terminal not accessible
- Check that `pos.view` permission is assigned
- Verify POS menu entries in `config/pms.php`

### Preview pages show blank
- Verify `public/css/app.css` is accessible at `https://yourdomain.com/css/app.css`
- Check that preview HTML files are in the `public/` directory
