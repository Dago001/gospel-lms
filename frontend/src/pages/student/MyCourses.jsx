import { Link } from 'react-router-dom';
import { useGet } from '../../api/hooks.js';
import { EmptyState, ErrorState, Loading, PageHeader, StatusBadge } from '../../components/ui/Display.jsx';
import { date } from '../../lib/format.js';
import { useDocumentTitle } from '../../lib/useDocumentTitle.js';

export function MyCourses() {
  useDocumentTitle('My courses');
  const q = useGet(['learn', 'courses'], '/learn/courses');
  return (
    <div>
      <PageHeader title="My courses" actions={<Link className="btn btn-secondary" to="/courses">Find more courses</Link>} />
      {q.isLoading ? <Loading /> : q.error ? <ErrorState error={q.error} onRetry={q.refetch} /> : q.data.data.length === 0 ? (
        <EmptyState title="No courses yet" action={<Link className="btn btn-primary" to="/courses">Browse the catalogue</Link>}>Courses you enrol in will be listed here.</EmptyState>
      ) : (
        <ul className="list-plain panel list-rule" style={{ padding: '0 20px' }}>
          {q.data.data.map((e) => (
            <li key={e.enrollment_id} className="split">
              <div><h3 style={{ margin: 0 }}><Link to={`/student/courses/${e.course.id}`}>{e.course.title}</Link></h3><div className="small muted">Enrolled {date(e.enrolled_at)} · {e.lesson_count} lessons{e.completed_at ? ` · Completed ${date(e.completed_at)}` : ''}</div></div>
              <div className="btn-row"><StatusBadge status={e.status} /><Link className="btn btn-secondary btn-sm" to={`/student/courses/${e.course.id}`}>Open</Link></div>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
