// Navigation is derived from roles/permissions so users only see areas they can use. The server enforces the same rules.
export const STUDENT_NAV = [
  { group: 'Learning', items: [['/student', 'Dashboard', 'home', true], ['/student/courses', 'My courses', 'book'], ['/student/results', 'Results', 'clipboard'], ['/student/certificates', 'Certificates', 'award']] },
  { group: 'Account', items: [['/student/billing', 'Payments & receipts', 'card'], ['/student/notifications', 'Notifications', 'bell'], ['/student/support', 'Support', 'help'], ['/account', 'Profile & security', 'settings']] },
];
export const LECTURER_NAV = [
  { group: 'Teaching', items: [['/lecturer', 'Dashboard', 'home', true], ['/lecturer/courses', 'My courses', 'book']] },
  { group: 'Account', items: [['/lecturer/notifications', 'Notifications', 'bell'], ['/account', 'Profile & security', 'settings']] },
];
// [to, label, icon, end?, permissionAny[]]
export const ADMIN_NAV = [
  { group: 'Overview', items: [['/admin', 'Dashboard', 'home', true, ['reports.academic', 'reports.finance']]] },
  { group: 'People', items: [['/admin/users', 'Users', 'users', false, ['users.view']], ['/admin/roles', 'Roles & permissions', 'shield', false, ['roles.manage']], ['/admin/enrolments', 'Enrolments', 'clipboard', false, ['enrolments.manage']]] },
  { group: 'Academics', items: [['/admin/courses', 'Courses', 'book', false, ['courses.manage-all', 'courses.approve']], ['/admin/categories', 'Categories', 'file', false, ['categories.manage']], ['/admin/certificates', 'Certificates', 'award', false, ['certificates.manage']], ['/admin/reports', 'Reports', 'chart', false, ['reports.academic']]] },
  { group: 'Finance', items: [['/admin/finance', 'Payments', 'card', true, ['finance.view']], ['/admin/finance/orders', 'Orders', 'clipboard', false, ['finance.view']], ['/admin/finance/refunds', 'Refunds', 'card', false, ['finance.view']], ['/admin/finance/coupons', 'Coupons', 'gift', false, ['coupons.manage']], ['/admin/finance/exceptions', 'Exceptions', 'alert', false, ['finance.view']], ['/admin/finance/webhooks', 'Webhook history', 'file', false, ['finance.view']], ['/admin/finance/reports', 'Revenue reports', 'chart', false, ['reports.finance']]] },
  { group: 'Operations', items: [['/admin/support', 'Support desk', 'help', false, ['support.manage']], ['/admin/notifications', 'Notifications', 'bell', false, ['notifications.manage']], ['/admin/audit', 'Audit log', 'lock', false, ['audit.view']], ['/admin/system', 'System & integrations', 'settings', false, ['settings.manage']]] },
  { group: 'Account', items: [['/account', 'Profile & security', 'user']] },
];
