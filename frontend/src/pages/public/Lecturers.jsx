import { Link, useParams } from 'react-router-dom';
import { useGet } from '../../api/hooks.js';
import { Breadcrumbs, EmptyState, ErrorState, Loading, PageHeader } from '../../components/ui/Display.jsx';
import { CourseRow } from './Courses.jsx';
import { useDocumentTitle } from '../../lib/useDocumentTitle.js';

export function Lecturers() {
  useDocumentTitle('Lecturers');
  const q = useGet(['public', 'lecturers'], '/public/lecturers');
  return (
    <div className="container band">
      <PageHeader title="Lecturers" subtitle="The people who teach at the academy." />
      {q.isLoading ? <Loading /> : q.error ? <ErrorState error={q.error} onRetry={q.refetch} /> : q.data.data.length === 0 ? (
        <EmptyState icon="users" title="Lecturer profiles are coming soon">Profiles appear here when lecturers choose to make theirs public.</EmptyState>
      ) : (
        <ul className="list-plain list-rule">{q.data.data.map((l) => (
          <li key={l.id}><h3 style={{ marginBottom: 4 }}><Link to={`/lecturers/${l.id}`}>{l.name}</Link></h3>{l.headline && <p className="muted" style={{ marginBottom: 4 }}>{l.headline}</p>}{l.bio && <p style={{ margin: 0 }}>{l.bio.slice(0, 220)}{l.bio.length > 220 ? '…' : ''}</p>}</li>
        ))}</ul>
      )}
    </div>
  );
}

export function LecturerProfile() {
  const { id } = useParams();
  const q = useGet(['public', 'lecturer', id], `/public/lecturers/${id}`);
  const l = q.data?.data;
  useDocumentTitle(l?.name);
  if (q.isLoading) return <div className="container band"><Loading /></div>;
  if (q.error) return <div className="container band"><ErrorState error={q.error} onRetry={q.refetch} /></div>;
  return (
    <div className="container band">
      <Breadcrumbs items={[{ label: 'Lecturers', to: '/lecturers' }, { label: l.name }]} />
      <h1 className="serif">{l.name}</h1>
      {l.headline && <p className="muted">{l.headline}</p>}
      {l.bio && <p>{l.bio}</p>}
      <h2 style={{ marginTop: 32 }}>Courses</h2>
      {l.courses.length ? l.courses.map((c) => <CourseRow key={c.id} course={c} />) : <p className="muted">No published courses yet.</p>}
    </div>
  );
}
