<?php
/** SQLite query test for the real EInvoiceService retry sweep (no network calls). */
namespace App\Services { class GstService { public static function isGhana(): bool { return true; } } }
namespace {
    function setting($key, $default = null) { return $key === 'gst_einvoice_enabled' ? '1' : $default; }
    $root = dirname(__DIR__, 3);
    $temporaryLink = ! is_file($root.'/vendor/autoload.php') && ! file_exists($root.'/build/app');
    if ($temporaryLink) symlink('../app', $root.'/build/app');
    try {
        $loader = require is_file($root.'/vendor/autoload.php') ? $root.'/vendor/autoload.php' : $root.'/build/vendor/autoload.php';
        $loader->addPsr4('App\\', $root.'/app', true);
    } finally { if ($temporaryLink) unlink($root.'/build/app'); }
}
namespace App\Models {
    // Avoid real submission: generate() returns immediately for these fixtures.
    class Folio extends \Illuminate\Database\Eloquent\Model {
        protected $table = 'folios'; public $timestamps = false; protected $guarded = [];
        public function hasActiveIrn(): bool { return true; }
    }
}
namespace {
    $capsule = new \Illuminate\Database\Capsule\Manager;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $capsule->setAsGlobal(); $capsule->bootEloquent();
    $db = $capsule->getConnection();
    $db->statement('CREATE TABLE folios (id INTEGER PRIMARY KEY, status TEXT, invoice_date TEXT, irn_status TEXT, irn_retryable INTEGER, irn_next_try_at TEXT)');
    $db->insert("INSERT INTO folios (status, invoice_date, irn_status, irn_retryable, irn_next_try_at) VALUES ('closed','2025-01-01','pending',1,NULL), ('closed','2025-01-01','pending',1,'2020-01-01'), ('closed','2025-01-01','pending',1,'2999-01-01'), ('closed','2025-01-01','failed',1,'2020-01-01'), ('closed','2025-01-01','failed',1,'2999-01-01'), ('closed','2025-01-01','failed',0,'2020-01-01'), ('open','2025-01-01','pending',1,NULL)");
    $result = \App\Services\EInvoiceService::retryDue();
    if ($result !== [3, 0]) throw new \RuntimeException('Retry sweep selected the wrong pending/failed documents: '.json_encode($result));
    echo "Retry sweep correctly selects due and legacy pending invoices; delayed, non-retryable and open rows skipped.\n";
}
