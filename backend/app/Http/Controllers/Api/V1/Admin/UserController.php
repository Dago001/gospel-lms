<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Notifications\SecurityNotice;
use App\Services\AuditLogger;
use App\Services\SessionTracker;
use App\Support\Roles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function __construct(private AuditLogger $audit, private SessionTracker $sessions) {}

    public function index(Request $request)
    {
        $r = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'role' => ['nullable', Rule::in(Roles::ALL)],
            'status' => ['nullable', Rule::in(['active', 'suspended'])], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $users = User::query()->with('roles')
            ->when($r['q'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'ilike', "%$s%")->orWhere('email', 'ilike', "%$s%")))
            ->when($r['role'] ?? null, fn ($q, $role) => $q->whereHas('roles', fn ($w) => $w->where('slug', $role)))
            ->when($r['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderBy('created_at', 'desc')->paginate($r['per_page'] ?? 25);

        return UserResource::collection($users);
    }

    public function show(User $user): UserResource
    {
        return new UserResource($user->load('roles'));
    }

    /** Create a staff or lecturer account; the person sets their own password through an emailed link. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['distinct', Rule::in(Roles::ALL)],
        ]);
        $user = DB::transaction(function () use ($data, $request) {
            $user = new User(['name' => $data['name'], 'email' => Str::lower($data['email']), 'password' => Str::random(48)]);
            $user->forceFill(['email_verified_at' => now()])->save(); // staff accounts are created by an administrator, so the address is trusted
            $user->roles()->attach(Role::whereIn('slug', $data['roles'])->pluck('id')->mapWithKeys(fn ($id) => [$id => ['assigned_by' => $request->user()->id]]));
            $user->profile()->create([]);
            $this->audit->log('user.created', $user, new: ['roles' => $data['roles']]);

            return $user;
        });
        Password::sendResetLink(['email' => $user->email]);

        return (new UserResource($user->load('roles')))->response()->setStatusCode(201);
    }

    public function updateRoles(Request $request, User $user): UserResource|JsonResponse
    {
        $data = $request->validate(['roles' => ['required', 'array', 'min:1'], 'roles.*' => ['distinct', Rule::in(Roles::ALL)]]);
        $old = $user->roleSlugs();

        if (in_array(Roles::ADMIN, $old, true) && ! in_array(Roles::ADMIN, $data['roles'], true)) {
            if ($user->is($request->user())) {
                return response()->json(['message' => 'You cannot remove your own administrator role.', 'code' => 'self_demotion'], 422);
            }
            if (User::whereHas('roles', fn ($q) => $q->where('slug', Roles::ADMIN))->where('status', 'active')->count() <= 1) {
                return response()->json(['message' => 'At least one active administrator must remain.', 'code' => 'last_admin'], 422);
            }
        }
        $ids = Role::whereIn('slug', $data['roles'])->pluck('id')->mapWithKeys(fn ($id) => [$id => ['assigned_by' => $request->user()->id]]);
        $user->roles()->sync($ids);
        $user->flushAuthorizationCache();
        $this->audit->log('user.roles_changed', $user, ['roles' => $old], ['roles' => $data['roles']]);

        return new UserResource($user->load('roles'));
    }

    public function suspend(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        if ($user->is($request->user())) {
            return response()->json(['message' => 'You cannot suspend your own account.', 'code' => 'self_suspend'], 422);
        }
        $user->forceFill(['status' => 'suspended', 'suspended_at' => now(), 'suspension_reason' => $data['reason']])->save();
        $this->sessions->revokeAll($user);
        $this->audit->log('user.suspended', $user, reason: $data['reason']);
        $user->notify(new SecurityNotice('Your account was suspended', 'Your account has been suspended by an administrator. Contact support for assistance.'));

        return response()->json(['message' => 'User suspended.']);
    }

    public function reactivate(User $user): JsonResponse
    {
        $user->forceFill(['status' => 'active', 'suspended_at' => null, 'suspension_reason' => null])->save();
        $this->audit->log('user.reactivated', $user);
        $user->notify(new SecurityNotice('Your account was reactivated', 'Your account has been reactivated. You can sign in again.'));

        return response()->json(['message' => 'User reactivated.']);
    }
}
