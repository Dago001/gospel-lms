<?php

return [
    'path' => env('BACKUP_PATH', storage_path('app/backups')),
    'keep_days' => (int) env('BACKUP_KEEP_DAYS', 14),
    'upload' => (bool) env('BACKUP_UPLOAD', false),     // also copy to the private object-storage disk under backups/
    'pg_dump' => env('PG_DUMP_BIN', 'pg_dump'),
    'pg_restore' => env('PG_RESTORE_BIN', 'pg_restore'),
    'tables_checked' => ['users', 'courses', 'course_enrollments', 'orders', 'payments', 'receipts', 'certificates', 'assessment_attempts', 'submission_grades', 'audit_logs'],
];
