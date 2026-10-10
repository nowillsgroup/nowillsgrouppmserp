<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Users are not globally scoped — every query here filters by the current hotel explicitly. */
class UserController extends Controller
{
    protected function scoped()
    {
        return User::where('hotel_id', Tenant::id());
    }

    protected function findUser(int $id): User
    {
        return $this->scoped()->findOrFail($id);
    }

    public function index(Request $request)
    {
        $users = $this->scoped()->with('role')
            ->when($request->q, fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'like', "%$t%")->orWhere('username', 'like', "%$t%")))
            ->orderByDesc('is_active')->orderBy('name')->paginate(30)->withQueryString();

        return view('users.index', compact('users'));
    }

    public function create()
    {
        return view('users.form', ['user' => new User(['is_active' => true]), 'roles' => Role::orderBy('name')->pluck('name', 'id')]);
    }

    protected function rules(?User $user = null): array
    {
        return [
            'name' => 'required|string|max:255',
            'username' => ['required', 'alpha_dash', 'max:60', Rule::unique('users', 'username')->where('hotel_id', Tenant::id())->ignore($user?->id)],
            'email' => 'nullable|email|max:255', 'phone' => 'nullable|string|max:30',
            'role_id' => 'nullable|integer|owned:roles',
            'password' => [$user ? 'nullable' : 'required', 'confirmed', Password::min(8)->letters()->numbers()],
            'is_active' => 'boolean', 'is_super_admin' => 'boolean',
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());
        $user = new User(collect($data)->only(['name', 'username', 'email', 'phone', 'role_id', 'password'])->all());
        $user->hotel_id = Tenant::id();
        $user->is_active = $request->boolean('is_active', true);
        $user->is_super_admin = $request->user()->is_super_admin && $request->boolean('is_super_admin');
        $user->save();
        ActivityLogger::log('users', 'create', "Created user {$user->username}", $user);

        return redirect()->route('users.index')->with('success', "User {$user->username} created.");
    }

    public function edit(int $user)
    {
        return view('users.form', ['user' => $this->findUser($user), 'roles' => Role::orderBy('name')->pluck('name', 'id')]);
    }

    public function update(Request $request, int $user)
    {
        $u = $this->findUser($user);
        $data = $request->validate($this->rules($u));
        $u->fill(collect($data)->only(['name', 'username', 'email', 'phone', 'role_id'])->all());
        if (! empty($data['password'])) {
            $u->password = $data['password'];
            $u->failed_logins = 0;
            $u->locked_until = null;
        }
        $self = $u->id === $request->user()->id;
        $u->is_active = $self ? true : $request->boolean('is_active');
        if ($request->user()->is_super_admin && ! $self) {
            $u->is_super_admin = $request->boolean('is_super_admin');
        }
        if ($request->boolean('unlock')) {
            $u->failed_logins = 0;
            $u->locked_until = null;
        }
        $u->save();
        ActivityLogger::log('users', 'update', "Updated user {$u->username}".(! empty($data['password']) ? ' (password reset)' : ''), $u);

        return redirect()->route('users.index')->with('success', 'User updated.');
    }

    public function profile(Request $request)
    {
        return view('users.profile', ['user' => $request->user()]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => 'required|string|max:255', 'email' => 'nullable|email|max:255', 'phone' => 'nullable|string|max:30',
            'current_password' => 'nullable|required_with:password|current_password',
            'password' => ['nullable', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);
        $user->fill(collect($data)->only(['name', 'email', 'phone'])->all());
        if (! empty($data['password'])) {
            $user->password = $data['password'];
            ActivityLogger::log('users', 'password', 'Changed own password', $user);
        }
        $user->save();

        return back()->with('success', 'Profile updated.');
    }
}
