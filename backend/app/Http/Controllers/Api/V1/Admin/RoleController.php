<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'roles' => Role::with('permissions:id,slug')->orderBy('name')->get()->map(fn ($r) => [
                'id' => $r->id, 'slug' => $r->slug, 'name' => $r->name, 'description' => $r->description,
                'is_privileged' => $r->is_privileged, 'permissions' => $r->permissions->pluck('slug')->sort()->values()]),
            'permissions' => Permission::orderBy('group')->orderBy('slug')->get(['slug', 'name', 'group']),
        ]);
    }

    public function syncPermissions(Request $request, Role $role, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['permissions' => ['present', 'array'], 'permissions.*' => ['distinct', 'exists:permissions,slug']]);
        // The administrator role is fixed so the platform can never be locked out of its own controls.
        if ($role->slug === 'admin') {
            return response()->json(['message' => 'The administrator role is fixed.', 'code' => 'role_fixed'], 422);
        }
        $old = $role->permissions()->pluck('slug')->sort()->values()->all();
        $role->permissions()->sync(Permission::whereIn('slug', $data['permissions'])->pluck('id'));
        $audit->log('role.permissions_changed', $role, ['permissions' => $old], ['permissions' => $data['permissions']]);

        return response()->json(['message' => 'Permissions updated.']);
    }
}
