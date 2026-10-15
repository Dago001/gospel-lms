import { useCallback, useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { Icon } from './ui/Icon.jsx';

export const SLIDES = [
  { img: 'bible', pos: '50% 35%', alt: 'Hands holding an open Bible', kicker: 'Online Christian academy', title: 'Study the Scriptures. Train for ministry.', text: 'Structured courses in theology, Bible study, evangelism and church leadership, taught by approved lecturers.', cta: [['/courses', 'Browse courses', 'primary'], ['/register', 'Create a free account', 'light']] },
  { img: 'sunrise', pos: '50% 60%', alt: 'Sunrise over a misty field', kicker: 'Learn at your pace', title: 'A new day to grow in the Word', text: 'Watch lessons whenever suits you, from any phone or laptop, and join live classes when you want to learn together.', cta: [['/courses', 'See what you can study', 'primary'], ['/faq', 'How it works', 'light']] },
  { img: 'class', pos: '50% 40%', alt: 'Students taking notes during a class', kicker: 'Taught and marked by people', title: 'Real lecturers. Honest feedback.', text: 'Assignments are graded against clear rubrics. Exams are timed and fair. Your results are never changed without a recorded reason.', cta: [['/lecturers', 'Meet the lecturers', 'primary'], ['/pricing', 'See pricing', 'light']] },
  { img: 'chapel', pos: '50% 55%', alt: 'Quiet chapel with wooden pews', kicker: 'Credentials you can trust', title: 'Certificates anyone can verify', text: 'Finish every requirement and a certificate with a QR code is issued automatically. Churches and ministries can confirm it online in seconds.', cta: [['/verify', 'Verify a certificate', 'primary'], ['/courses', 'Start a course', 'light']] },
  { img: 'worship', pos: '50% 45%', alt: 'Congregation worshipping with raised hands', kicker: 'Called to serve', title: 'Grow in faith and in your calling', text: 'Discipleship, preaching, evangelism and church leadership. Pay in naira, learn on your phone, and finish at your own pace.', cta: [['/register', 'Join the academy', 'primary'], ['/about', 'About us', 'light']] },
];
const INTERVAL = 7000;

export function HeroSlider() {
  const reduced = typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
  const [i, setI] = useState(0);
  const [playing, setPlaying] = useState(!reduced);
  const [held, setHeld] = useState(false);
  const touch = useRef(null);
  const go = useCallback((n) => setI((n + SLIDES.length) % SLIDES.length), []);

  useEffect(() => {
    if (!playing || held) return undefined;
    const t = setTimeout(() => setI((x) => (x + 1) % SLIDES.length), INTERVAL);
    return () => clearTimeout(t);
  }, [i, playing, held]);

  const onKey = (e) => { if (e.key === 'ArrowRight') { e.preventDefault(); go(i + 1); } if (e.key === 'ArrowLeft') { e.preventDefault(); go(i - 1); } };
  const onTouchStart = (e) => { touch.current = e.touches[0].clientX; };
  const onTouchEnd = (e) => {
    if (touch.current == null) return;
    const dx = e.changedTouches[0].clientX - touch.current; touch.current = null;
    if (Math.abs(dx) > 48) go(i + (dx < 0 ? 1 : -1));
  };

  return (
    <section className="hero-slider" aria-roledescription="carousel" aria-label="Featured" onKeyDown={onKey}
      onMouseEnter={() => setHeld(true)} onMouseLeave={() => setHeld(false)} onFocus={() => setHeld(true)} onBlur={() => setHeld(false)}
      onTouchStart={onTouchStart} onTouchEnd={onTouchEnd}>
      <div className="slides" aria-live={playing && !held ? 'off' : 'polite'}>
        {SLIDES.map((s, n) => (
          <div key={s.img} className={`slide ${n === i ? 'active' : ''}`} role="group" aria-roledescription="slide" aria-label={`${n + 1} of ${SLIDES.length}`} aria-hidden={n === i ? undefined : true} inert={n === i ? undefined : true}>
            <img className="slide-img" src={`/img/${s.img}-lg.jpg`} srcSet={`/img/${s.img}-sm.jpg 900w, /img/${s.img}-lg.jpg 1920w`} sizes="100vw" alt={s.alt} style={{ objectPosition: s.pos }}
              loading={n === 0 ? 'eager' : 'lazy'} fetchPriority={n === 0 ? 'high' : 'auto'} decoding="async" />
            <div className="slide-shade" />
            <div className="container slide-inner">
              <div className="slide-copy">
                <p className="section-label" style={{ '--i': 0 }}>{s.kicker}</p>
                {n === 0 ? <h1 style={{ '--i': 1 }}>{s.title}</h1> : <h2 className="slide-title" style={{ '--i': 1 }}>{s.title}</h2>}
                <div className="hero-rule" style={{ '--i': 2 }} aria-hidden="true" />
                <p className="lead" style={{ '--i': 3 }}>{s.text}</p>
                <div className="btn-row" style={{ '--i': 4 }}>
                  {s.cta.map(([to, label, kind]) => <Link key={to + label} to={to} className={`btn btn-lg ${kind === 'primary' ? 'btn-gold' : 'btn-ghost-light'}`}>{label}</Link>)}
                </div>
              </div>
            </div>
          </div>
        ))}
      </div>
      <div className="slider-controls">
        <button type="button" className="slider-btn" onClick={() => go(i - 1)} aria-label="Previous slide"><Icon name="chevron-left" size={20} /></button>
        <div className="slider-dots" role="group" aria-label="Choose a slide">
          {SLIDES.map((s, n) => (
            <button key={s.img} type="button" className={`dot-btn ${n === i ? 'on' : ''}`} aria-label={`Slide ${n + 1}`} aria-current={n === i ? 'true' : undefined} onClick={() => go(n)}>
              <span className="dot-fill" style={n === i && playing && !held ? { animationDuration: `${INTERVAL}ms` } : undefined} />
            </button>
          ))}
        </div>
        <button type="button" className="slider-btn" onClick={() => go(i + 1)} aria-label="Next slide"><Icon name="chevron-right" size={20} /></button>
        <button type="button" className="slider-btn" onClick={() => setPlaying((p) => !p)} aria-label={playing ? 'Pause slideshow' : 'Play slideshow'}><Icon name={playing ? 'pause' : 'play'} size={18} /></button>
      </div>
    </section>
  );
}
