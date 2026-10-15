import { Link } from 'react-router-dom';
import { useGet } from '../../api/hooks.js';
import { DataTable } from '../../components/ui/DataTable.jsx';
import { EmptyState, ErrorState, Loading, PageHeader, SectionHeading, StatusBadge, Stats } from '../../components/ui/Display.jsx';
import { dateTime } from '../../lib/format.js';
import { useDocumentTitle } from '../../lib/useDocumentTitle.js';

export function LecturerDashboard() {
  useDocumentTitle('Teaching dashboard');
  const q = useGet(['lecturer', 'dashboard'], '/manage/dashboard');
  if (q.isLoading) return <Loading rows={5} />;
  if (q.error) return <ErrorState error={q.error} onRetry={q.refetch} />;
  const d = q.data.data;
  return (
    <div className="stack">
      <PageHeader title="Teaching dashboard" actions={<Link className="btn btn-primary" to="/lecturer/courses">Manage courses</Link>} />
      <Stats items={[
        { label: 'Courses', value: d.courses.length },
        { label: 'Active students', value: d.courses.reduce((n, c) => n + c.active_students, 0) },
        { label: 'Awaiting your marking', value: d.totals.awaiting_grading },
        { label: 'Upcoming live classes', value: d.upcoming_live_sessions.length },
      ]} />
      <section><SectionHeading title="Your courses" />
        <DataTable caption="Your courses" rows={d.courses} columns={[
          { key: 'title', header: 'Course', sortable: true, render: (c) => <Link to={`/lecturer/courses/${c.id}`}>{c.title}</Link> },
          { key: 'status', header: 'Status', render: (c) => <StatusBadge status={c.status} /> },
          { key: 'active_students', header: 'Active', numeric: true, sortable: true }, { key: 'completed_students', header: 'Completed', numeric: true, sortable: true },
          { key: 'assignments_awaiting_grade', header: 'Assignments to grade', numeric: true, sortable: true },
          { key: 'assessments_awaiting_marking', header: 'Exams to mark', numeric: true, sortable: true },
        ]} empty={<EmptyState title="No courses assigned yet">An administrator assigns you to courses, or you can create a draft yourself.</EmptyState>} />
      </section>
      <section><SectionHeading title="Upcoming live classes" />
        {d.upcoming_live_sessions.length ? <ul className="list-plain list-rule">{d.upcoming_live_sessions.map((s) => <li key={s.id}><Link to={`/lecturer/courses/${s.course_id}?tab=live`}>{s.title}</Link> <span className="muted small">· {dateTime(s.starts_at)}</span></li>)}</ul> : <p className="muted">None scheduled.</p>}
      </section>
    </div>
  );
}
