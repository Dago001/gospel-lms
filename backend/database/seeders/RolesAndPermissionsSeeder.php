<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/** Idempotent: safe to run on every deploy. */
class RolesAndPermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'users' => ['users.view' => 'View users', 'users.manage' => 'Create, suspend and change roles of users'],
        'roles' => ['roles.manage' => 'Manage roles and permissions'],
        'courses' => ['courses.manage-own' => 'Manage assigned courses', 'courses.manage-all' => 'Manage all courses', 'courses.approve' => 'Approve and publish courses', 'categories.manage' => 'Manage categories'],
        'academics' => ['grades.view-all' => 'View all grades', 'grades.amend' => 'Amend published grades', 'enrolments.manage' => 'Manage enrolments', 'certificates.manage' => 'Manage and revoke certificates'],
        'finance' => ['finance.view' => 'View orders and payments', 'finance.refund' => 'Process refunds', 'finance.reconcile' => 'Reconcile payments', 'coupons.manage' => 'Manage coupons'],
        'reports' => ['reports.academic' => 'View academic reports', 'reports.finance' => 'View finance reports'],
        'support' => ['support.manage' => 'Manage support tickets'],
        'system' => ['settings.manage' => 'Manage system and integration settings', 'audit.view' => 'View audit logs', 'notifications.manage' => 'Manage notifications'],
    ];

    public const ROLES = [
        'student' => ['Student', false, 'Enrols in and studies courses.', []],
        'lecturer' => ['Lecturer', false, 'Teaches assigned courses and grades student work.', ['courses.manage-own']],
        'finance' => ['Finance officer', true, 'Handles payments, refunds and reconciliation. No access to grades or security settings.',
            ['finance.view', 'finance.refund', 'finance.reconcile', 'coupons.manage', 'reports.finance']],
        'admin' => ['Administrator', true, 'Full administrative access.', '*'],
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $group => $perms) {
            foreach ($perms as $slug => $name) {
                Permission::updateOrCreate(['slug' => $slug], ['name' => $name, 'group' => $group]);
            }
        }
        foreach (self::ROLES as $slug => [$name, $privileged, $desc, $perms]) {
            $role = Role::updateOrCreate(['slug' => $slug], ['name' => $name, 'is_privileged' => $privileged, 'description' => $desc]);
            // Seed defaults only for new roles / the fixed admin role so admin edits to other roles survive redeploys.
            if ($slug === 'admin') {
                $role->permissions()->sync(Permission::pluck('id'));
            } elseif ($role->wasRecentlyCreated) {
                $role->permissions()->sync(Permission::whereIn('slug', $perms)->pluck('id'));
            }
        }
    }
}
