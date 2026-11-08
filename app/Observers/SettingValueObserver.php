<?php

namespace App\Observers;

use App\Models\Setting;
use App\Models\SettingChange;
use App\Services\ConfigurationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Records what a setting was before it was changed.
 *
 * The activity log says a settings screen was saved and which settings it touched; it does
 * not say what the values were. So a reader could see "Updated hotel settings: Free
 * cancellation window" and still not know whether it went from 48 hours to 24 or to 72.
 *
 * Model saves and deletes are observed. Bulk query updates/deletes bypass model events;
 * paths that need value history must delete or update individual models.
 *
 * Three rules keep the trail honest rather than noisy:
 *   · a write that changed no value records nothing;
 *   · a setting *created* without a signed-in user is provisioning (the seeder writes 200+
 *     defaults a property), not a decision, so it is not a change anybody made;
 *   · a credential is stored as `Set — hidden` — the trail says when it changed, never what
 *     it is, and `was_secret` marks the row so no screen has to guess.
 *
 * A trail must never break the save that produced it: everything is caught.
 */
class SettingValueObserver
{
    /** How much of a value is kept. A policy paragraph is a value; a whole manual is not. */
    private const MAX = 2000;

    public function saved(Model $m): void
    {
        try {
            $this->record($m);
        } catch (\Throwable $e) {
            report($e);   // the setting was saved; the trail's problem must not undo it
        }
    }

    public function deleted(Model $m): void
    {
        try {
            $key = (string) $m->key;
            if ($key === '') {
                return;
            }
            $secret = ConfigurationService::isSecretKey($key);
            $change = new SettingChange([
                'key' => $key,
                'old_value' => $secret ? self::mask($m->value) : self::clip($m->value),
                'new_value' => null,
                'was_secret' => $secret,
                'created' => false,
                'user_id' => Auth::id(),
            ]);
            $change->hotel_id = $m->hotel_id;
            $change->save();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function record(Setting $m): void
    {
        $key = (string) $m->key;
        if ($key === '') {
            return;
        }

        $created = (bool) $m->wasRecentlyCreated;

        if ($created) {
            // the seeder / provisioner fills a new property in; nobody chose those values here
            if (! Auth::check()) {
                return;
            }
        } elseif (! $m->wasChanged('value')) {
            return;
        }

        $old = $created ? null : $m->getOriginal('value');
        $new = $m->value;

        if (! $created && (string) $old === (string) $new) {
            return;
        }

        $secret = ConfigurationService::isSecretKey($key);

        $change = new SettingChange([
            'key' => $key,
            'old_value' => $secret ? self::mask($old) : self::clip($old),
            'new_value' => $secret ? self::mask($new) : self::clip($new),
            'was_secret' => $secret,
            'created' => $created,
            'user_id' => Auth::id(),
        ]);
        // hotel_id is guarded against mass assignment. Use the setting's actual owner,
        // including for background work with no current tenant (or a different one).
        $change->hotel_id = $m->hotel_id;
        $change->save();
    }

    /** A credential's value never reaches the trail — only whether one is set. */
    private static function mask($value): ?string
    {
        return trim((string) $value) === '' ? null : ConfigurationService::HIDDEN;
    }

    private static function clip($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = (string) $value;

        return mb_strlen($text) > self::MAX ? mb_substr($text, 0, self::MAX).'…' : $text;
    }
}
