<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetDepLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['opening_nbv' => 'decimal:2', 'amount' => 'decimal:2'];
    }

    public function run()
    {
        return $this->belongsTo(AssetDepRun::class, 'asset_dep_run_id');
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }
}
