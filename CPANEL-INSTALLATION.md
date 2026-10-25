# Nowills PMS — cPanel Direct Installation Guide

## What's in this ZIP

This ZIP contains all updated and new files for the Nowills PMS. The archive has a **flat root** — files are at the top level with their correct relative paths (e.g., `app/Http/Controllers/RobitaController.php`). No Composer or SSH is required.

## Files Included

- **Robita Lock Module** — Smart key door lock management (devices, credentials, events, settings)
- **POS Module** — Point of Sale terminal, order terminal, menu catalog, cart, KOT printing, settlement
- **Billing Reconciliation** — AP/AR reconciliation with foreign currency support
- **Migration Importers** — Opening balances, inventory, supplier importers
- **Preview Files** — HTML previews for POS Terminal, Order Terminal, Robita Locks, and other modules
- **Updated Core Files** — Controllers, models, services, config, routes, views

## Installation Steps (cPanel)

### 1. Download and Upload
1. Download the ZIP file from Google Drive
2. In cPanel, open **File Manager** and navigate to your Laravel app root directory
3. Click **Upload** and select the ZIP file
4. Wait for the upload to complete

### 2. Extract
1. Right-click the uploaded ZIP and select **Extract**
2. Choose "Extract to: current directory" (or the app root)
3. The files will be placed in their correct locations

### 3. Verify Files
Check that the following new files are present:
- `app/Http/Controllers/RobitaController.php`
- `app/Services/RobitaService.php`
- `app/Models/RobitaDevice.php`
- `app/Models/RobitaCredential.php`
- `app/Models/RobitaEvent.php`
- `database/migrations/2026_10_25_000100_create_robita_devices_table.php`
- `resources/views/robita/index.blade.php`
- `resources/views/robita/devices.blade.php`
- `resources/views/robita/events.blade.php`
- `resources/views/robita/settings.blade.php`
- `public/preview-pos.html`
- `public/preview-pos-order.html`
- `public/preview-robita.html`

### 4. Run Migrations
1. In cPanel, open **Terminal** (or use the command line if available)
2. Navigate to your app directory: `cd /path/to/your/app`
3. Run the migration: `php artisan migrate`

If Terminal is not available, you can run migrations via a temporary route:
- Create a file `migrate.php` in the app root with:
  ```php
  <?php require __DIR__.'/vendor/autoload.php';
  $app = require __DIR__.'/bootstrap/app.php';
  $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
  $kernel->call('migrate');
  ```
- Access `https://yourdomain.com/migrate.php` in your browser
- Delete `migrate.php` after migrations complete

### 5. Clear Cache
Run these commands (or use the temporary route method above):
```bash
php artisan cache:clear
php artisan config:clear
php artisan view:clear
php artisan route:clear
```

### 6. Verify in Browser
1. Log into the PMS admin panel
2. Check that the **Robita Locks** menu appears under **Front Office → Interfaces**
3. Check that the **POS Terminal** menu appears under **Operations → POS / Restaurant**
4. Visit `https://yourdomain.com/preview-pos.html` to see the POS preview
5. Visit `https://yourdomain.com/preview-pos-order.html` to see the Order Terminal preview

## Important Notes

- **No Composer needed** — all dependencies are already in `vendor/` on your existing installation
- **No SSH needed** — everything can be done through cPanel File Manager and Terminal
- **Database backup** — always backup your database before running migrations
- **Permissions** — ensure the `storage/` and `bootstrap/cache/` directories are writable
- **Preview server** — the preview HTML files are static and can be served directly by the web server (Apache/Nginx). No Node.js server is needed for production.

## Troubleshooting

### Migration fails
- Check that the `robita_devices`, `robita_events`, `robita_credentials` tables don't already exist
- Verify database connection settings in `.env`

### Robita Locks menu not showing
- Check that the `robita.manage` permission is assigned to your user role
- Verify the menu entry in `config/pms.php` under "Interfaces"

### POS Terminal not accessible
- Check that the `pos.view` permission is assigned
- Verify the POS menu entries in `config/pms.php`

### Preview pages show blank
- Verify that `public/css/app.css` is accessible at `https://yourdomain.com/css/app.css`
- Check that the preview HTML files are in the `public/` directory
