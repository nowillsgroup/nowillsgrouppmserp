<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v3.6 – the approval trail of a budget version. */
class BudgetEvent extends Model
{
    use BelongsToHotel;

    public const UPDATED_AT = null;

    /**
     * What the History tab prints for each action. `ucfirst($action)` printed the stored code —
     * "Approved_first" — which is a label naming the wrong kind of thing; the rules changed in v8.3 and the
     * trail now has a first signature that is not an approval of the version, so it needs its own words.
     */
    public const LABELS = [
        'created' => 'Created', 'submitted' => 'Submitted', 'approved' => 'Approved', 'approved_first' => 'First approval',
        'returned' => 'Returned to the preparer', 'reopened' => 'Re-opened', 'activated' => 'Made active',
        'grid' => 'Figures saved', 'stats' => 'Rooms statistics saved', 'imported' => 'Imported', 'adjusted' => 'Adjusted',
    ];

    public static function label(?string $action): string
    {
        return self::LABELS[$action] ?? ucfirst(str_replace('_', ' ', (string) $action));
    }

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
