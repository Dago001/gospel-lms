import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useGet, useAction } from '../../api/hooks.js';
import { post } from '../../api/client.js';
import { useAuth } from '../../auth/AuthContext.jsx';
import { Alert, TextField } from '../../components/ui/Fields.jsx';
import { Breadcrumbs, ErrorState, Loading, RichText, SectionHeading } from '../../components/ui/Display.jsx';
import { Icon } from '../../components/ui/Icon.jsx';
import { PriceBlock } from './Courses.jsx';
import { clock, duration, money, titleCase } from '../../lib/format.js';
import { useDocumentTitle } from '../../lib/useDocumentTitle.js';

export default function CourseDetail() {
  const { slug } = useParams();
  const { user } = useAuth();
  const q = useGet(['public', 'course', slug], `/public/courses/${slug}`);
  const mine = useGet(['learn', 'courses'], '/learn/courses', undefined, { enabled: !!user });
  const c = q.data?.data;
  useDocumentTitle(c?.title);
  if (q.isLoading) return <div className="container band"><Loading rows={6} /></div>;
  if (q.error) return <div className="container band">{q.error.status === 404 ? <Alert type="warning" title="Course not found">This course is not available. <Link to="/courses">Browse all courses</Link></Alert> : <ErrorState error={q.error} onRetry={q.refetch} />}</div>;
  const lessons = c.curriculum.reduce((n, m) => n + m.lessons.length, 0);
  const enrolled = mine.data?.data?.find((e) => e.course.id === c.id);
  return (
    <div className="container band">
      <Breadcrumbs items={[{ label: 'Courses', to: '/courses' }, { label: c.title }]} />
      <div className="lesson-layout cols cols-340">
        <div>
          <p className="section-label">{c.category?.name || 'Course'}</p>
          <h1 className="serif">{c.title}</h1>
          {c.summary && <p style={{ fontSize: '1.0625rem', color: 'var(--text-secondary)' }}>{c.summary}</p>}
          <dl className="kv" style={{ margin: '16px 0 24px' }}>
            <dt>Taught by</dt><dd>{c.instructors.map((i, k) => <span key={i.id}>{k > 0 && ', '}<Link to={`/lecturers/${i.id}`}>{i.name}</Link></span>)}</dd>
            <dt>Level</dt><dd>{titleCase(c.level)}</dd>
            {duration(c.duration_minutes) && <><dt>Duration</dt><dd>{duration(c.duration_minutes)} of lessons</dd></>}
            <dt>Lessons</dt><dd>{lessons} in {c.curriculum.length} module{c.curriculum.length === 1 ? '' : 's'}</dd>
          </dl>

          {c.description && <section><SectionHeading title="About this course" /><RichText html={c.description} /></section>}
          {c.objectives?.length > 0 && <section style={{ marginTop: 32 }}><SectionHeading title="What you will learn" /><ul>{c.objectives.map((o) => <li key={o}>{o}</li>)}</ul></section>}
          {c.requirements?.length > 0 && <section style={{ marginTop: 32 }}><SectionHeading title="Requirements" /><ul>{c.requirements.map((o) => <li key={o}>{o}</li>)}</ul></section>}
          {c.prerequisites?.length > 0 && <Alert type="info" title="Prerequisite courses">You must complete {c.prerequisites.map((p, i) => <span key={p.slug}>{i > 0 && ', '}<Link to={`/courses/${p.slug}`}>{p.title}</Link></span>)} first.</Alert>}

          <section style={{ marginTop: 32 }}>
            <SectionHeading title="Curriculum" />
            {c.curriculum.map((m) => (
              <details key={m.id} className="panel" style={{ marginBottom: 8 }} open={c.curriculum.length === 1}>
                <summary style={{ padding: '12px 16px', cursor: 'pointer', fontWeight: 700 }}>{m.title} <span className="muted small" style={{ fontWeight: 400 }}>· {m.lessons.length} lesson{m.lessons.length === 1 ? '' : 's'}</span></summary>
                <ul className="list-plain list-rule" style={{ padding: '0 16px' }}>
                  {m.lessons.map((l) => (
                    <li key={l.id} style={{ display: 'flex', gap: 12, alignItems: 'center' }}>
                      <Icon name={l.type === 'video' ? 'video' : l.type === 'live' ? 'calendar' : l.type === 'document' ? 'file' : 'book'} size={18} />
                      <span style={{ flex: 1 }}>{l.title}</span>
                      {l.is_preview && <span className="badge badge-info">Free preview</span>}
                      {l.duration_seconds && <span className="muted small">{clock(l.duration_seconds)}</span>}
                    </li>
                  ))}
                </ul>
              </details>
            ))}
          </section>
        </div>
        <aside><EnrolPanel course={c} enrolled={enrolled} /></aside>
      </div>
    </div>
  );
}

function EnrolPanel({ course, enrolled }) {
  const { user } = useAuth();
  const nav = useNavigate();
  const [coupon, setCoupon] = useState('');
  const [preview, setPreview] = useState(null);
  const [err, setErr] = useState(null);
  const free = course.price.is_free;

  const enrolFree = useAction(() => post(`/learn/courses/${course.id}/enrol`), { invalidate: [['learn']], onSuccess: () => nav(`/student/courses/${course.id}`), success: 'You are enrolled.' });
  const applyCoupon = useAction(() => post('/checkout/preview', { course_ids: [course.id], coupon_code: coupon }), { quietError: true, onSuccess: (r) => { setPreview(r.data); setErr(null); } });
  const pay = useAction(() => post('/checkout', { course_ids: [course.id], coupon_code: preview ? coupon : undefined }), {
    quietError: true,
    onSuccess: (r) => {
      if (r.data.payment.authorization_url) window.location.assign(r.data.payment.authorization_url); // hosted Paystack checkout
      else nav(`/checkout/callback?reference=${r.data.payment.reference}`);                           // fully discounted: already settled
    },
  });
  const total = preview ? preview.total_minor : course.price.amount_minor;

  return (
    <div className="panel panel-pad" style={{ position: 'sticky', top: 88 }}>
      <PriceBlock price={course.price} />
      <div style={{ marginTop: 16 }}>
        {enrolled ? (
          <Link className="btn btn-primary btn-block" to={`/student/courses/${course.id}`}>Continue learning</Link>
        ) : !user ? (
          <div className="stack-sm">
            <Link className="btn btn-primary btn-block" to={`/register?next=${encodeURIComponent(`/courses/${course.slug}`)}`}>Create an account to enrol</Link>
            <Link className="btn btn-secondary btn-block" to={`/login?next=${encodeURIComponent(`/courses/${course.slug}`)}`}>Sign in</Link>
          </div>
        ) : free ? (
          <>
            <button className="btn btn-primary btn-block" disabled={enrolFree.isPending} onClick={() => enrolFree.mutate(undefined, { onError: (e) => setErr(e.message) })}>{enrolFree.isPending ? 'Enrolling…' : 'Enrol for free'}</button>
          </>
        ) : (
          <div className="stack-sm">
            <div style={{ display: 'flex', gap: 8, alignItems: 'flex-end' }}>
              <div style={{ flex: 1 }}><TextField label="Coupon code (optional)" value={coupon} onChange={(e) => { setCoupon(e.target.value); setPreview(null); }} autoComplete="off" /></div>
              <button type="button" className="btn btn-secondary" style={{ marginBottom: 16 }} disabled={!coupon || applyCoupon.isPending} onClick={() => applyCoupon.mutate(undefined, { onError: (e) => setErr(e.message) })}>Apply</button>
            </div>
            {preview && <dl className="kv"><dt>Price</dt><dd>{money(preview.subtotal_minor)}</dd><dt>Discount</dt><dd>−{money(preview.discount_minor)}</dd><dt><strong>Total</strong></dt><dd><strong>{money(preview.total_minor)}</strong></dd></dl>}
            <button className="btn btn-primary btn-block btn-lg" disabled={pay.isPending} onClick={() => { setErr(null); pay.mutate(undefined, { onError: (e) => setErr(e.message) }); }}>
              {pay.isPending ? 'Preparing checkout…' : total === 0 ? 'Enrol now' : `Pay ${money(total)}`}
            </button>
            <p className="micro muted" style={{ margin: 0 }}>Secure checkout by Paystack. You get access as soon as the payment is confirmed.</p>
          </div>
        )}
        {err && <div style={{ marginTop: 12 }}><Alert type="error">{err}</Alert></div>}
      </div>
      <hr />
      <ul className="list-plain small" style={{ display: 'grid', gap: 8 }}>
        <li><Icon name="award" size={16} /> Certificate on completion</li>
        <li><Icon name="clipboard" size={16} /> Graded assignments and exams</li>
        <li><Icon name="message" size={16} /> Discussion with your lecturer</li>
      </ul>
    </div>
  );
}
