import { Link } from 'react-router-dom';
import { useAuth } from '../../auth/AuthContext.jsx';
import { useGet } from '../../api/hooks.js';
import { EmptyState, ErrorState, Loading, PageHeader, ProgressBar, SectionHeading, Stats } from '../../components/ui/Display.jsx';
import { dateTime } from '../../lib/format.js';
import { useDocumentTitle } from '../../lib/useDocumentTitle.js';

export function Dashboard() {
  useDocumentTitle('Dashboard');
  const { user } = useAuth();
  const q = useGet(['student', 'dashboard'], '/student/dashboard');
  const ann = useGet(['announcements'], '/announcements');
  if (q.isLoading) return <Loading rows={5} />;
  if (q.error) return <ErrorState error={q.error} onRetry={q.refetch} />;
  const d = q.data.data;
  return (
    <div className="stack">
      <PageHeader title={`Welcome, ${user.name.split(' ')[0]}`} subtitle="Your studies at a glance." />
      <Stats items={[
        { label: 'Courses in progress', value: d.courses.filter((c) => c.status === 'active').length },
        { label: 'Completed courses', value: d.courses.filter((c) => c.status === 'completed').length },
        { label: 'Certificates', value: d.certificates },
        { label: 'Results awaiting', value: d.results_awaiting },
        { label: 'Unread notifications', value: d.unread_notifications },
      ]} />
      {ann.data?.data?.length > 0 && (
        <section><SectionHeading title="Announcements" />
          {ann.data.data.slice(0, 2).map((a) => <div key={a.id} className="alert alert-info"><strong>{a.title}</strong><div dangerouslySetInnerHTML={{ __html: a.body }} /></div>)}
        </section>
      )}
      <section>
        <SectionHeading title="Continue learning" action={<Link to="/student/courses">All my courses</Link>} />
        {d.courses.length === 0 ? <EmptyState title="You are not enrolled in any course yet" action={<Link className="btn btn-primary" to="/courses">Browse courses</Link>}>When you enrol, your courses and progress appear here.</EmptyState> : (
          <div className="grid-2">
            {d.courses.map((c) => (
              <div key={c.course_id} className="panel panel-pad">
                <h3 style={{ marginBottom: 8 }}><Link to={`/student/courses/${c.course_id}`}>{c.title}</Link></h3>
                {c.progress_percent != null ? <><ProgressBar value={c.progress_percent} label={`${c.title} progress`} /><p className="small muted" style={{ margin: '6px 0 12px' }}>{c.required_completed} of {c.required_total} required lessons</p></> : <p className="small muted">No required lessons.</p>}
                <Link className="btn btn-primary btn-sm" to={c.next_lesson_id ? `/student/courses/${c.course_id}/lessons/${c.next_lesson_id}` : `/student/courses/${c.course_id}`}>{c.status === 'completed' ? 'Review course' : c.progress_percent ? 'Resume' : 'Start'}</Link>
              </div>
            ))}
          </div>
        )}
      </section>
      <div className="grid-2">
        <section>
          <SectionHeading title="Upcoming deadlines" />
          {d.upcoming_deadlines.length ? <ul className="list-plain list-rule">{d.upcoming_deadlines.map((x) => <li key={x.type + x.id}><Link to={`/student/courses/${x.course_id}?tab=${x.type === 'assignment' ? 'assignments' : 'assessments'}`}>{x.title}</Link><div className="small muted">{x.type === 'assignment' ? 'Due' : 'Closes'} {dateTime(x.due_at)}</div></li>)}</ul> : <p className="muted">Nothing due soon.</p>}
        </section>
        <section>
          <SectionHeading title="Live classes" />
          {d.upcoming_live_sessions.length ? <ul className="list-plain list-rule">{d.upcoming_live_sessions.map((x) => <li key={x.id}><Link to={`/student/courses/${x.course_id}?tab=live`}>{x.title}</Link><div className="small muted">{dateTime(x.starts_at)}</div></li>)}</ul> : <p className="muted">No live classes scheduled in the next two weeks.</p>}
        </section>
      </div>
    </div>
  );
}
