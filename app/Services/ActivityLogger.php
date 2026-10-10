<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Support\Tenant;

class ActivityLogger
{
    public static function log(string $module, string $action, string $description, $subject = null, array $props = []): void
    {
        try {
            $log = new ActivityLog([
                'user_id' => auth()->id(),
                'module' => $module,
                'action' => $action,
                'description' => mb_substr($description, 0, 500),
                'subject_type' => $subject ? class_basename($subject) : null,
                'subject_id' => $subject?->getKey(),
                'properties' => $props ?: null,
                'ip' => request()?->ip(),
                'user_agent' => mb_substr((string) request()?->userAgent(), 0, 255),
            ]);
            $log->hotel_id = Tenant::id();
            $log->save();
        } catch (\Throwable $e) {
            report($e); // never break a business transaction because of logging
        }
    }
}
