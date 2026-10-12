<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\HrAnnouncement;
use App\Models\HrDocument;
use App\Models\HrProfileUpdate;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * v7.40 EmployeeSelfService — the staff-facing half of HR self-service:
 *   • profile change requests (contact / bank / tax fields) with HR approval
 *   • document uploads (ID, certificates …) with HR verification
 *   • an HR announcements feed
 *
 * Sensitive fields never apply themselves: a change request stores {old, new}
 * per field and only touches the Employee row when HR approves.
 */
class EmployeeSelfService
{
    /** fields an employee may ask to change — never salary, department, designation */
    public const PROFILE_FIELDS = [
        'phone' => 'Phone',
        'email' => 'Personal e-mail',
        'address' => 'Address',
        'bank_name' => 'Bank name',
        'bank_account' => 'Bank account',
        'bank_ifsc' => 'Bank sort / IFSC',
        'tax_id' => 'Tax ID',
        'social_security_no' => 'Social security no.',
    ];

    public const DOC_KINDS = ['id' => 'ID / passport', 'certificate' => 'Certificate', 'contract' => 'Contract', 'other' => 'Other'];

    // ------------------------------------------------------------------ profile

    /** @param array<string, mixed> $input */
    public static function requestUpdate(Employee $e, array $input, User $u): HrProfileUpdate
    {
        $changes = [];
        foreach (self::PROFILE_FIELDS as $field => $label) {
            $new = trim((string) ($input[$field] ?? ''));
            $old = (string) ($e->{$field} ?? '');
            if ($new === '' || $new === $old) {
                continue;
            }
            if ($field === 'email' && $new !== '' && ! filter_var($new, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages([$field => ["The {$label} is not a valid e-mail address."]]);
            }
            if ($field === 'phone' && $new !== '' && ! preg_match('/^[0-9+\-\s()]{6,30}$/', $new)) {
                throw ValidationException::withMessages([$field => ["The {$label} looks invalid."]]);
            }
            if (mb_strlen($new) > 255) {
                throw ValidationException::withMessages([$field => ["The {$label} may not exceed 255 characters."]]);
            }
            $changes[$field] = ['old' => $old, 'new' => $new];
        }
        if (! $changes) {
            throw ValidationException::withMessages(['profile' => ['Nothing changed — edit at least one field.']]);
        }

        $req = new HrProfileUpdate(['employee_id' => $e->id, 'requested_by' => $u->id, 'changes' => $changes, 'status' => 'pending']);
        $req->hotel_id = $e->hotel_id; // guarded — set explicitly
        $req->save();
        ActivityLogger::log('hr', 'profile_request', "Profile change requested by {$e->name} (".implode(', ', array_keys($changes)).")", $req);

        return $req;
    }

    public static function approve(HrProfileUpdate $r, ?string $note, User $u): HrProfileUpdate
    {
        self::needPending($r);
        DB::transaction(function () use ($r, $note, $u) {
            $e = Employee::findOrFail($r->employee_id);
            foreach ($r->changes as $field => $pair) {
                if (! array_key_exists($field, self::PROFILE_FIELDS)) {
                    continue;
                }
                $e->{$field} = $pair['new'];
            }
            $e->save();
            $r->update(['status' => 'approved', 'reviewed_by' => $u->id, 'reviewed_at' => now(), 'review_note' => $note ? mb_substr($note, 0, 500) : null]);
        });
        ActivityLogger::log('hr', 'profile_request', 'Profile change approved for '.Employee::find($r->employee_id)->name, $r);

        return $r->fresh();
    }

    public static function reject(HrProfileUpdate $r, ?string $note, User $u): HrProfileUpdate
    {
        self::needPending($r);
        $r->update(['status' => 'rejected', 'reviewed_by' => $u->id, 'reviewed_at' => now(), 'review_note' => $note ? mb_substr($note, 0, 500) : null]);
        ActivityLogger::log('hr', 'profile_request', 'Profile change rejected for '.Employee::find($r->employee_id)->name, $r);

        return $r->fresh();
    }

    protected static function needPending(HrProfileUpdate $r): void
    {
        if ($r->status !== 'pending') {
            throw ValidationException::withMessages(['request' => ['This request has already been '.str_replace('ed', 'd', $r->status).'.']]);
        }
    }

    // ------------------------------------------------------------------ documents

    public static function upload(Employee $e, UploadedFile $file, string $title, ?string $kind, User $u): HrDocument
    {
        if (! $file->isValid() || $file->getSize() <= 0) {
            throw ValidationException::withMessages(['file' => ['The upload failed — try again.']]);
        }
        if ($file->getSize() > 10 * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => ['Documents may not exceed 10 MB.']]);
        }
        if (! in_array($file->getMimeType(), ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true)) {
            throw ValidationException::withMessages(['file' => ['Upload a PDF, JPEG, PNG or WebP file.']]);
        }
        $path = $file->store('employee-docs/'.now()->format('Ym'));

        $doc = new HrDocument([
            'employee_id' => $e->id, 'uploaded_by' => $u->id,
            'title' => mb_substr(trim($title) ?: $file->getClientOriginalName(), 0, 200),
            'kind' => ($kind && isset(self::DOC_KINDS[$kind])) ? $kind : null,
            'file_path' => $path, 'mime' => $file->getMimeType(), 'bytes' => $file->getSize(), 'status' => 'new',
        ]);
        $doc->hotel_id = $e->hotel_id; // guarded — set explicitly
        $doc->save();

        return $doc;
    }

    public static function reviewDocument(HrDocument $d, string $status, ?string $note, User $u): HrDocument
    {
        if (! in_array($status, ['verified', 'rejected'], true)) {
            throw ValidationException::withMessages(['status' => ['Choose verified or rejected.']]);
        }
        $d->update(['status' => $status, 'review_note' => $note ? mb_substr($note, 0, 500) : null, 'reviewed_by' => $u->id, 'reviewed_at' => now()]);
        ActivityLogger::log('hr', 'document', "Document “{$d->title}” {$status} (".Employee::find($d->employee_id)->name.")", $d);

        return $d;
    }

    // ------------------------------------------------------------------ announcements

    /** @param array<string, mixed> $d */
    public static function announce(array $d, User $u): HrAnnouncement
    {
        $title = trim((string) ($d['title'] ?? ''));
        $body = trim((string) ($d['body'] ?? ''));
        if ($title === '' || mb_strlen($title) > 200) {
            throw ValidationException::withMessages(['title' => ['Give the announcement a title (up to 200 characters).']]);
        }
        if ($body === '') {
            throw ValidationException::withMessages(['body' => ['Write the announcement text.']]);
        }
        $audience = in_array($d['audience'] ?? '', ['all', 'staff', 'department'], true) ? $d['audience'] : 'staff';

        $a = new HrAnnouncement([
            'title' => $title, 'body' => $body, 'audience' => $audience,
            'department_id' => $audience === 'department' ? (int) ($d['department_id'] ?? 0) ?: null : null,
            'is_pinned' => (bool) ($d['is_pinned'] ?? false),
            'publish_at' => ! empty($d['publish_at']) ? $d['publish_at'] : now(),
            'created_by' => $u->id,
        ]);
        $a->hotel_id = $u->hotel_id; // guarded — set explicitly
        $a->save();

        return $a;
    }

    public static function feed(Employee $e)
    {
        return HrAnnouncement::visibleTo($e)
            ->orderByDesc('is_pinned')->orderByDesc('publish_at')->limit(50)->get();
    }
}
