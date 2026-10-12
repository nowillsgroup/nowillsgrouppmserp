<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\HrAnnouncement;
use App\Models\HrDocument;
use App\Models\HrProfileUpdate;
use App\Services\EmployeeSelfService as Ess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** v7.40 Employee self-service: profile change requests, document uploads, announcements + the HR review desk. */
class EssController extends Controller
{
    protected function me(): Employee
    {
        $e = Employee::where('user_id', Auth::id())->where('is_active', true)->first();
        if (! $e) {
            // graceful, like me/hr: explain instead of a bare 404
            abort(404, 'Your login is not linked to an employee record – ask HR to link it.');
        }

        return $e;
    }

    // ------------------------------------------------------------------ staff

    public function profile()
    {
        $e = $this->me();

        return view('me.profile', [
            'e' => $e,
            'fields' => Ess::PROFILE_FIELDS,
            'requests' => HrProfileUpdate::where('employee_id', $e->id)->latest('id')->limit(10)->get(),
        ]);
    }

    public function profileUpdate(Request $request)
    {
        Ess::requestUpdate($this->me(), $request->all(), Auth::user());

        return back()->with('success', 'Your change request has been sent to HR for approval.');
    }

    public function documents()
    {
        $e = $this->me();

        return view('me.documents', [
            'e' => $e,
            'docs' => HrDocument::where('employee_id', $e->id)->latest('id')->limit(50)->get(),
            'kinds' => Ess::DOC_KINDS,
        ]);
    }

    public function documentUpload(Request $request)
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'kind' => ['nullable', 'string'],
            'file' => ['required', 'file'],
        ]);
        Ess::upload($this->me(), $request->file('file'), (string) ($data['title'] ?? ''), $data['kind'] ?? null, Auth::user());

        return back()->with('success', 'Document uploaded — HR will verify it shortly.');
    }

    public function announcements()
    {
        $e = $this->me();

        return view('me.announcements', ['e' => $e, 'items' => Ess::feed($e)]);
    }

    // ------------------------------------------------------------------ HR desk

    public function desk()
    {
        return view('hr.ess', [
            'requests' => HrProfileUpdate::with('employee')->latest('id')->limit(40)->get(),
            'docs' => HrDocument::with('employee')->latest('id')->limit(40)->get(),
            'announcements' => HrAnnouncement::latest('id')->limit(20)->get(),
            'departments' => \App\Models\Department::orderBy('name')->get(),
        ]);
    }

    public function decide(Request $request, HrProfileUpdate $req)
    {
        $data = $request->validate(['action' => ['required', 'in:approve,reject'], 'note' => ['nullable', 'string', 'max:500']]);
        $data['action'] === 'approve'
            ? Ess::approve($req, $data['note'] ?? null, Auth::user())
            : Ess::reject($req, $data['note'] ?? null, Auth::user());

        return back()->with('success', 'The profile change request has been '.$data['action'].'d.');
    }

    public function reviewDoc(Request $request, HrDocument $doc)
    {
        $data = $request->validate(['status' => ['required', 'in:verified,rejected'], 'note' => ['nullable', 'string', 'max:500']]);
        Ess::reviewDocument($doc, $data['status'], $data['note'] ?? null, Auth::user());

        return back()->with('success', 'Document marked '.$data['status'].'.');
    }

    public function announce(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string'],
            'audience' => ['required', 'in:all,staff,department'],
            'department_id' => ['nullable', 'integer'],
            'is_pinned' => ['nullable', 'boolean'],
            'publish_at' => ['nullable', 'date'],
        ]);
        Ess::announce($data, Auth::user());

        return back()->with('success', 'Announcement published to the staff feed.');
    }
}
