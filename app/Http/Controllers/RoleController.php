<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    public function index()
    {
        $counts = User::where('hotel_id', Tenant::id())->selectRaw('role_id, COUNT(*) as c')->groupBy('role_id')->pluck('c', 'role_id');

        return view('roles.index', ['roles' => Role::withCount('permissions')->orderBy('name')->get(), 'counts' => $counts, 'total' => Permission::count()]);
    }

    public function create()
    {
        return view('roles.form', ['role' => new Role, 'groups' => Permission::orderBy('id')->get()->groupBy('module'), 'selected' => []]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $role = Role::create(['name' => $data['name'], 'description' => $data['description'] ?? null]);
        $role->permissions()->sync($data['permissions'] ?? []);
        ActivityLogger::log('users', 'role_create', "Created role {$role->name}", $role);

        return redirect()->route('roles.index')->with('success', 'Role created.');
    }

    public function edit(Role $role)
    {
        return view('roles.form', ['role' => $role, 'groups' => Permission::orderBy('id')->get()->groupBy('module'), 'selected' => $role->permissions()->pluck('permissions.id')->all()]);
    }

    public function update(Request $request, Role $role)
    {
        $data = $this->validated($request, $role);
        $role->update(['name' => $data['name'], 'description' => $data['description'] ?? null]);
        $role->permissions()->sync($data['permissions'] ?? []);
        ActivityLogger::log('users', 'role_update', "Updated role {$role->name} (".count($data['permissions'] ?? []).' permissions)', $role);

        return redirect()->route('roles.index')->with('success', 'Role updated.');
    }

    public function destroy(Role $role)
    {
        if ($role->is_system || User::where('hotel_id', Tenant::id())->where('role_id', $role->id)->exists()) {
            return back()->with('error', 'System roles and roles assigned to users cannot be deleted.');
        }
        $role->delete();
        ActivityLogger::log('users', 'role_delete', "Deleted role {$role->name}");

        return back()->with('success', 'Role deleted.');
    }

    protected function validated(Request $request, ?Role $role = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('roles', 'name')->where('hotel_id', Tenant::id())->ignore($role?->id)],
            'description' => 'nullable|string|max:255', 'permissions' => 'array', 'permissions.*' => 'integer|exists:permissions,id',
        ]);
    }
}
