import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useGet } from '../../api/hooks.js';
import { CourseRow } from './Courses.jsx';
import { Loading, SectionHeading } from '../../components/ui/Display.jsx';
import { useDocumentTitle } from '../../lib/useDocumentTitle.js';

export default function Home() {
  useDocumentTitle();
  const nav = useNavigate();
  const [q, setQ] = useState('');
  const cats = useGet(['public', 'categories'], '/public/categories');
  const latest = useGet(['public', 'courses'], '/public/courses', { per_page: 5 });
  const categories = (cats.data?.data || []).filter((c) => c.course_count > 0);
  return (
    <>
      <section className="hero">
        <div className="container hero-grid">
          <div>
            <p className="section-label">Online Christian academy</p>
            <h1>Study the Scriptures. Train for ministry.</h1>
            <div className="hero-rule" aria-hidden="true" />
            <p className="lead">Structured courses in theology, Bible study, evangelism and church leadership, taught by approved lecturers, with graded work and verifiable certificates.</p>
            <div className="btn-row">
              <Link className="btn btn-primary btn-lg" to="/courses">Browse courses</Link>
              <Link className="btn btn-secondary btn-lg" to="/register">Create a free account</Link>
            </div>
          </div>
          <div className="hero-side">
            <form role="search" onSubmit={(e) => { e.preventDefault(); nav(`/courses${q ? `?q=${encodeURIComponent(q)}` : ''}`); }}>
              <label className="label" htmlFor="home-search">Find a course</label>
              <div style={{ display: 'flex', gap: 8 }}>
                <input id="home-search" className="input" type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder="e.g. Romans, discipleship, preaching" />
                <button className="btn btn-primary" type="submit">Search</button>
              </div>
            </form>
            {categories.length > 0 && (
              <>
                <p className="small muted" style={{ margin: '20px 0 8px' }}>Or start from a subject</p>
                <ul className="list-plain" style={{ display: 'flex', flexWrap: 'wrap', gap: 8 }}>
                  {categories.map((c) => <li key={c.slug}><Link className="btn btn-secondary btn-sm" to={`/courses?category=${c.slug}`}>{c.name} ({c.course_count})</Link></li>)}
                </ul>
              </>
            )}
          </div>
        </div>
      </section>

      <section className="band container" aria-labelledby="latest-h">
        <SectionHeading title="Latest courses" action={<Link to="/courses">All courses</Link>} />
        <span id="latest-h" className="sr-only">Latest courses</span>
        {latest.isLoading ? <Loading rows={4} /> : latest.data?.data?.length ? latest.data.data.map((c) => <CourseRow key={c.id} course={c} />) : (
          <p className="muted">New courses will appear here as soon as the academy publishes them.</p>
        )}
      </section>

      <section className="band band-white">
        <div className="container">
          <SectionHeading title="How studying works" />
          <ol className="steps">
            <li><h3>Choose a course</h3><p>Read the objectives, curriculum and requirements. Free previews let you sample a lesson before you enrol.</p></li>
            <li><h3>Enrol securely</h3><p>Free courses start immediately. Paid courses are checked out in naira through Paystack, and access begins only when the payment is confirmed.</p></li>
            <li><h3>Learn and be assessed</h3><p>Watch lessons, join live classes, submit assignments and sit timed examinations. Lecturers mark and give feedback.</p></li>
            <li><h3>Earn a certificate</h3><p>When every requirement is met, a certificate with a QR code is issued automatically, and anyone can verify it online.</p></li>
          </ol>
        </div>
      </section>

      <section className="band container">
        <div className="grid-2">
          <div>
            <h2>Verify a certificate</h2>
            <p>Employers, churches and ministries can confirm that a certificate is genuine by entering its verification code or scanning its QR code.</p>
            <Link className="btn btn-secondary" to="/verify">Verify a certificate</Link>
          </div>
          <div>
            <h2>Teach with us</h2>
            <p>Lecturer accounts are created by the academy after review. If you would like to teach, tell us about your background.</p>
            <Link className="btn btn-secondary" to="/contact">Contact the academy</Link>
          </div>
        </div>
      </section>
    </>
  );
}
