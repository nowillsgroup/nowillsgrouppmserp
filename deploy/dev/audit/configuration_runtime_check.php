<?php
/** Run with PHP 8.3+: php deploy/dev/audit/configuration_runtime_check.php
 * Focused service tests with isolated model doubles; this is not a Laravel/DB integration test.
 */
namespace Illuminate\Database\Eloquent { class Model {} }
namespace Illuminate\Support\Facades { class Auth { public static function check(): bool { return true; } public static function id(): int { return 7; } } }
namespace App\Models {
    class Bag { public function __construct(private array $data) {} public function all(): array { return $this->data; } }
    class Setting extends \Illuminate\Database\Eloquent\Model {
        public static array $data = [];
        public bool $wasRecentlyCreated = false;
        public string $key = 'one'; public string $value = 'new'; public int $hotel_id = 42;
        public function wasChanged($key): bool { return true; }
        public function getOriginal($key): string { return 'old'; }
        public static function pluck($a, $b): Bag { return new Bag(self::$data); }
    }
    class SettingChange {
        public static array $rows = [];
        public static ?self $saved = null;
        public array $attributes;
        public $hotel_id = null;
        public function __construct(array $attributes = []) { unset($attributes['hotel_id']); $this->attributes = $attributes; }
        public function save(): void { self::$saved = $this; }
        public static function query(): FakeQuery { return new FakeQuery(self::$rows); }
    }
    class FakeQuery {
        private array $predicates = [];
        private int $limit = PHP_INT_MAX;
        public function __construct(private array $rows) {}
        public function getQuery(): self { return $this; }
        public function getGrammar(): self { return $this; }
        public function wrap($value): string { return '"'.$value.'"'; }
        public function with($a): self { return $this; }
        public function latest($a): self { return $this; }
        public function take($n): self { $this->limit = $n; return $this; }
        public function distinct(): self { return $this; }
        public function oldest($key): self { return $this; }
        public function count(): int { return count($this->rows); }
        public function pluck($key): array { return array_values(array_unique(array_map(fn ($r) => $r->$key, $this->rows))); }
        public function value($a) { return null; }
        public function where($callback): self { $q = new self([]); $callback($q); $this->predicates[] = fn ($r) => $q->matches($r); return $this; }
        public function whereIn($column, $values): self { $this->predicates[] = fn ($r) => in_array($r->$column, $values, true); return $this; }
        public function orWhereRaw($sql, $params): self { $this->predicates[] = fn ($r) => substr($r->key, 0, $params[0]) === $params[1]; return $this; }
        private function matches($row): bool { foreach ($this->predicates as $test) if ($test($row)) return true; return false; }
        public function get(): array { return array_slice(array_values(array_filter($this->rows, fn ($r) => ! $this->predicates || $this->matches($r))), 0, $this->limit); }
    }
}
namespace App\Http\Controllers { class SettingController { public const KEYS = []; } }
namespace {
    function config($key, $default = null) {
        $config = ['settings' => ['modules' => [
            'test' => ['label' => 'Test', 'keys' => ['one' => 'One', 'two' => 'Two', 'family_' => 'Family', 'password' => 'Password'], 'secret' => ['password']],
            'other' => ['label' => 'Other', 'keys' => ['other' => 'Other']],
        ]]];
        $v = $config;
        foreach (explode('.', $key) as $part) { if (!is_array($v) || !array_key_exists($part, $v)) return $default; $v = $v[$part]; }
        return $v;
    }
    require __DIR__.'/../../../app/Services/ConfigurationService.php';
    require __DIR__.'/../../../app/Observers/SettingValueObserver.php';
    use App\Services\ConfigurationService as Service;
    use App\Models\Setting;
    use App\Models\SettingChange;
    $checks = 0;
    function check($condition, $message) { global $checks; ++$checks; if (!$condition) throw new \RuntimeException($message); }
    $cell = new \ReflectionMethod(Service::class, 'csvSafe');
    foreach (['=1+1', '+CMD()', '-CMD()', '@SUM(A1)', "  =1+1", "\tword", "\n=1+1"] as $text) {
        check($cell->invoke(null, $text) === "'".$text, 'unsafe CSV cell: '.json_encode($text));
    }
    foreach (['-2.5', '+12', 'normal', ''] as $text) check($cell->invoke(null, $text) === $text, 'ordinary CSV cell changed');
    Setting::$data = ['one' => "x\ntwo=y", 'two' => 'z', 'password' => 'first'];
    $a = Service::fingerprint()['full'];
    Setting::$data = ['one' => 'x', 'two' => "y\ntwo=z", 'password' => 'first'];
    check($a !== Service::fingerprint()['full'], 'line boundary values must not collide');
    Setting::$data = []; $missing = Service::fingerprint()['full'];
    Setting::$data = ['one' => '']; check($missing !== Service::fingerprint()['full'], 'missing differs from empty');
    Setting::$data = ['one' => null]; check($missing !== Service::fingerprint()['full'], 'missing differs from null');
    Setting::$data = ['password' => 'first', 'family_a' => 'A']; $a = Service::fingerprint()['full'];
    Setting::$data = ['family_a' => 'A', 'password' => 'second']; check($a === Service::fingerprint()['full'], 'ordering and secret rotation');
    Setting::$data['family_a'] = 'B'; check($a !== Service::fingerprint()['full'], 'family value changes fingerprint');
    Setting::$data = []; check($a !== Service::fingerprint()['full'], 'credential status and family changes fingerprint');
    $row = fn ($id, $key, $old, $new, $secret = false) => (object) ['id' => $id, 'key' => $key, 'old_value' => $old, 'new_value' => $new, 'was_secret' => $secret, 'created' => false, 'created_at' => null, 'user' => null];
    SettingChange::$rows = [$row(4, 'other', 'a', 'b'), $row(3, 'family_a', 'x', 'y'), $row(2, 'familyXa', 'x', 'y'), $row(1, 'password', 'historical secret', 'new secret')];
    $history = Service::valueTrail('test', 10);
    check(count($history) === 2, 'literal family filter must exclude other modules and wildcard lookalikes');
    check($history[0]['module'] === 'test' && str_contains($history[0]['label'], 'family_a'), 'family label and module');
    check($history[1]['secret'] && $history[1]['was'] === Service::HIDDEN && $history[1]['now'] === Service::HIDDEN && $history[1]['was_raw'] === null, 'old credentials must be masked on read');
    check(!$history[1]['restore_ok'], 'historical credentials cannot be restored');
    $coverage = Service::trailCoverage();
    check($coverage['rows'] === 4 && $coverage['keys'] === 3 && $coverage['keys_total'] === 5, 'coverage counts registered keys, not unknown keys or individual family members');
    $log = (object) ['module' => 'transport', 'action' => 'wht_settings'];
    check(Service::isChangeFor($log, ['modules' => ['transport'], 'actions' => ['fleet']]), 'module settings log alias');
    check(!Service::isChangeFor($log, ['modules' => ['other'], 'actions' => ['fleet']]), 'unrelated activity log');
    (new \App\Observers\SettingValueObserver())->saved(new Setting());
    check(SettingChange::$saved?->hotel_id === 42, 'observer must explicitly carry the setting owner despite guarded hotel_id');
    check(SettingChange::$saved?->attributes['old_value'] === 'old', 'observer preserves the previous value');
    $credential = new Setting(); $credential->key = 'password';
    (new \App\Observers\SettingValueObserver())->saved($credential);
    check(SettingChange::$saved?->attributes['old_value'] === Service::HIDDEN && SettingChange::$saved?->attributes['new_value'] === Service::HIDDEN, 'observer masks credentials');
    echo "configuration isolated PHP checks: $checks passed\n";
}
