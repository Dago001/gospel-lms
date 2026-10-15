import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useGet } from '../../api/hooks.js';
import { HeroSlider } from '../../components/HeroSlider.jsx';
import { Reveal } from '../../components/Reveal.jsx';
import { Icon } from '../../components/ui/Icon.jsx';
import { Loading } from '../../components/ui/Display.jsx';
import { useDocumentTitle } from '../../lib/useDocumentTitle.js';
import { titleCase, duration } from '../../lib/format.js';
import { PriceBlock } from './Courses.jsx';

const PILLARS = [
  ['book', 'Scripture at the centre', 'Courses are built around the Word, written and taught by lecturers the academy has approved.'],
  ['video', 'Learn on any device', 'Lessons, live classes and exams work on a phone as well as a laptop, so study fits your week.'],
  ['award', 'Credentials you can verify', 'Every certificate has a QR code and a public verification page. Revoked certificates stop verifying.'],
  ['shield', 'Fair and transparent', 'Marks follow published rubrics, results are audited, and payments are confirmed by Paystack before access begins.'],
];

const STEPS = [
  ['Choose a course', 'Read the objectives, curriculum and requirements. Free previews let you sample a lesson before you enrol.'],
  ['Enrol securely', 'Free courses start immediately. Paid courses are paid in naira through Paystack, and access begins only when the payment is confirmed.'],
  ['Learn and be assessed', 'Watch lessons, join live classes, submit assignments and sit timed examinations. Lecturers mark and give feedback.'],
  ['Earn a certificate', 'When every requirement is met, a certificate is issued automatically, and anyone can verify it online.'],
];

function CourseCard({ course }) {
  return (
    <article className="ccard">
      <Link to={`/courses/${course.slug}`} className="ccard-art" aria-hidden="true" tabIndex={-1}>
        <img src="/logo-white.png" alt="" width="554" height="446" loading="lazy" />
        {course.category && <span className="ccard-tag">{course.category.name}</span>}
      </Link>
      <div className="ccard-body">
        <h3><Link to={`/courses/${course.slug}`}>{course.title}</Link></h3>
        {course.summary && <p className="ccard-sum">{course.summary}</p>}
        <div className="meta">
          <span>{titleCase(course.level)}</span>
          {duration(course.duration_minutes) && <span>{duration(course.duration_minutes)}</span>}
        </div>
        <div className="ccard-foot">
          <PriceBlock price={course.price} />
          <Link to={`/courses/${course.slug}`} className="ccard-go">View course <Icon name="chevron-right" size={16} /></Link>
        </div>
      </div>
    </article>
  );
}

export default function Home() {
  useDocumentTitle();
  const nav = useNavigate();
  const [q, setQ] = useState('');
  const cats = useGet(['public', 'categories'], '/public/categories');
  const latest = useGet(['public', 'courses'], '/public/courses', { per_page: 6 });
  const categories = (cats.data?.data || []).filter((c) => c.course_count > 0);
  const courses = latest.data?.data || [];
  return (
    <>
      <HeroSlider />

      <div className="container search-float">
        <Reveal className="search-card">
          <form role="search" onSubmit={(e) => { e.preventDefault(); nav(`/courses${q ? `?q=${encodeURIComponent(q)}` : ''}`); }}>
            <label className="label" htmlFor="home-search">Find a course</label>
            <div className="search-row">
              <input id="home-search" className="input" type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder="e.g. Romans, discipleship, preaching" />
              <button className="btn btn-primary" type="submit"><Icon name="search" size={18} /> Search</button>
            </div>
          </form>
          {categories.length > 0 && (
            <ul className="list-plain chip-row" aria-label="Subjects">
              {categories.map((c) => <li key={c.slug}><Link className="chip" to={`/courses?category=${c.slug}`}>{c.name} <span>{c.course_count}</span></Link></li>)}
            </ul>
          )}
        </Reveal>
      </div>

      <section className="band container" aria-labelledby="why-h">
        <Reveal><p className="section-label">Why GOSPEL LMS</p><h2 id="why-h" className="display-h">Serious study, built for real life</h2></Reveal>
        <ul className="pillars list-plain">
          {PILLARS.map(([icon, t, d], n) => (
            <Reveal as="li" key={t} delay={n * 90} className="pillar"><span className="pillar-icon"><Icon name={icon} size={24} /></span><h3>{t}</h3><p>{d}</p></Reveal>
          ))}
        </ul>
      </section>

      <section className="band band-soft" aria-labelledby="latest-h">
        <div className="container">
          <Reveal className="head-row"><div><p className="section-label">Courses</p><h2 id="latest-h" className="display-h">Start with the latest</h2></div><Link className="btn btn-secondary" to="/courses">All courses</Link></Reveal>
          {latest.isLoading ? <Loading rows={3} /> : courses.length ? (
            <div className="ccard-grid">
              {courses.map((c, n) => <Reveal key={c.id} delay={(n % 3) * 100}><CourseCard course={c} /></Reveal>)}
            </div>
          ) : <p className="muted">New courses will appear here as soon as the academy publishes them.</p>}
        </div>
      </section>

      <section className="band container split" aria-labelledby="how-h">
        <Reveal className="split-art">
          <img src="/img/class-sm.jpg" srcSet="/img/class-sm.jpg 900w, /img/class-lg.jpg 1920w" sizes="(max-width: 860px) 100vw, 46vw" alt="Students studying together" loading="lazy" />
          <div className="split-badge"><img src="/logo-white.png" alt="" width="554" height="446" /></div>
        </Reveal>
        <div>
          <Reveal><p className="section-label">How studying works</p><h2 id="how-h" className="display-h">From enrolment to certificate</h2></Reveal>
          <ol className="steps-v">
            {STEPS.map(([t, d], n) => <Reveal as="li" key={t} delay={n * 110}><h3>{t}</h3><p>{d}</p></Reveal>)}
          </ol>
        </div>
      </section>

      <section className="verse" aria-label="Scripture">
        <img src="/img/sunrise-lg.jpg" srcSet="/img/sunrise-sm.jpg 900w, /img/sunrise-lg.jpg 1920w" sizes="100vw" alt="" loading="lazy" />
        <div className="verse-shade" />
        <Reveal className="container verse-inner">
          <Icon name="quote" size={36} />
          <blockquote>
            <p>Thy word is a lamp unto my feet, and a light unto my path.</p>
            <footer>Psalm 119:105</footer>
          </blockquote>
        </Reveal>
      </section>

      <section className="band container">
        <div className="grid-2">
          <Reveal className="info-card"><h2>Verify a certificate</h2><p>Employers, churches and ministries can confirm that a certificate is genuine by entering its verification code or scanning its QR code.</p><Link className="btn btn-secondary" to="/verify">Verify a certificate</Link></Reveal>
          <Reveal className="info-card" delay={120}><h2>Teach with us</h2><p>Lecturer accounts are created by the academy after review. If you would like to teach, tell us about your background.</p><Link className="btn btn-secondary" to="/contact">Contact the academy</Link></Reveal>
        </div>
      </section>

      <section className="cta-band">
        <img src="/img/nave-lg.jpg" srcSet="/img/nave-sm.jpg 900w, /img/nave-lg.jpg 1920w" sizes="100vw" alt="" loading="lazy" />
        <div className="cta-shade" />
        <Reveal className="container cta-inner">
          <img className="cta-logo" src="/logo-white.png" alt="GOSPEL LMS" width="554" height="446" />
          <h2>Begin your studies today</h2>
          <p>Create a free account in a minute. No card is needed until you choose a paid course.</p>
          <div className="btn-row" style={{ justifyContent: 'center' }}>
            <Link className="btn btn-lg btn-gold" to="/register">Create a free account</Link>
            <Link className="btn btn-lg btn-ghost-light" to="/courses">Browse courses</Link>
          </div>
        </Reveal>
      </section>
    </>
  );
}
