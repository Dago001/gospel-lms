import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useGet } from '../../api/hooks.js';
import { DataTable } from '../../components/ui/DataTable.jsx';
import { SelectField, TextField } from '../../components/ui/Fields.jsx';
import { PageHeader, StatusBadge } from '../../components/ui/Display.jsx';
import { date, money } from '../../lib/format.js';
import { useDocumentTitle } from '../../lib/useDocumentTitle.js';

export function AdminCourses() {
  useDocumentTitle('Courses');
  const [f, setF] = useState({ q: '', status: 'pending_review', page: 1 });
  const q = useGet(['admin', 'courses'], '/manage/courses', f);
  return (
    <div>
      <PageHeader title="Courses" subtitle="Review, approve and publish." />
      <div className="filters">
        <TextField label="Search" type="search" value={f.q} onChange={(e) => setF({ ...f, q: e.target.value, page: 1 })} />
        <SelectField label="Status" value={f.status} onChange={(e) => setF({ ...f, status: e.target.value, page: 1 })} placeholder="All" options={[['pending_review', 'Awaiting review'], ['approved', 'Approved'], ['published', 'Published'], ['draft', 'Draft'], ['archived', 'Archived']].map(([value, label]) => ({ value, label }))} />
      </div>
      <DataTable caption="Courses" loading={q.isLoading} error={q.error} onRetry={q.refetch} rows={q.data?.data} meta={q.data?.meta} onPage={(p) => setF({ ...f, page: p })}
        columns={[
          { key: 'title', header: 'Course', sortable: true, render: (c) => <Link to={`/lecturer/courses/${c.id}`}>{c.title}</Link> },
          { key: 'lecturers', header: 'Lecturers', render: (c) => c.instructors.map((i) => i.name).join(', ') || '-' },
          { key: 'status', header: 'Status', render: (c) => <StatusBadge status={c.status} /> },
          { key: 'price', header: 'Price', numeric: true, render: (c) => (c.price.is_free ? 'Free' : money(c.price.amount_minor, c.price.currency)) },
          { key: 'updated_at', header: 'Updated', sortable: true, render: (c) => date(c.updated_at) },
        ]} />
    </div>
  );
}
