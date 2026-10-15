import { Link, useSearchParams } from 'react-router-dom';
import { useGet } from '../../api/hooks.js';
import { Money, EmptyState, ErrorState, Loading, PageHeader } from '../../components/ui/Display.jsx';
import { Pagination } from '../../components/ui/DataTable.jsx';
import { SelectField, TextField } from '../../components/ui/Fields.jsx';
import { duration, money, titleCase } from '../../lib/format.js';
import { useDocumentTitle } from '../../lib/useDocumentTitle.js';

export function PriceBlock({ price }) {
  if (price.is_free) return <div className="price">Free</div>;
  return <div className="price">{price.discount_active && <s>{money(price.list_minor, price.currency)}</s>}<Money minor={price.amount_minor} currency={price.currency} /></div>;
}

export function CourseRow({ course }) {
  return (
    <article className="course-row">
      <div>
        <h3><Link to={`/courses/${course.slug}`}>{course.title}</Link></h3>
        {course.summary && <p style={{ marginBottom: 8 }}>{course.summary}</p>}
        <div className="meta">
          {course.category && <span>{course.category.name}</span>}
          <span>{titleCase(course.level)}</span>
          {duration(course.duration_minutes) && <span>{duration(course.duration_minutes)}</span>}
          {course.instructors.length > 0 && <span>Taught by {course.instructors.map((i) => i.name).join(', ')}</span>}
        </div>
      </div>
      <PriceBlock price={course.price} />
    </article>
  );
}

export default function Courses() {
  useDocumentTitle('Courses');
  const [sp, setSp] = useSearchParams();
  const f = { q: sp.get('q') || '', category: sp.get('category') || '', level: sp.get('level') || '', price: sp.get('price') || '', sort: sp.get('sort') || 'newest', page: sp.get('page') || 1 };
  const set = (k, v) => { const n = new URLSearchParams(sp); v ? n.set(k, v) : n.delete(k); if (k !== 'page') n.delete('page'); setSp(n); };
  const cats = useGet(['public', 'categories'], '/public/categories');
  const res = useGet(['public', 'courses'], '/public/courses', { ...f, per_page: 10 }, { placeholderData: (p) => p });
  return (
    <div className="container band">
      <PageHeader title="Course catalogue" subtitle="Courses currently open for enrolment." />
      <div className="catalogue">
        <form role="search" aria-label="Filter courses" onSubmit={(e) => e.preventDefault()}>
          <TextField label="Search" type="search" value={f.q} onChange={(e) => set('q', e.target.value)} placeholder="Title or topic" />
          <SelectField label="Subject" value={f.category} onChange={(e) => set('category', e.target.value)} placeholder="All subjects" options={(cats.data?.data || []).map((c) => ({ value: c.slug, label: c.name }))} />
          <SelectField label="Level" value={f.level} onChange={(e) => set('level', e.target.value)} placeholder="Any level" options={['beginner', 'intermediate', 'advanced'].map((v) => ({ value: v, label: titleCase(v) }))} />
          <SelectField label="Price" value={f.price} onChange={(e) => set('price', e.target.value)} placeholder="Free and paid" options={[{ value: 'free', label: 'Free only' }, { value: 'paid', label: 'Paid only' }]} />
          <SelectField label="Sort by" value={f.sort} onChange={(e) => set('sort', e.target.value)} options={[{ value: 'newest', label: 'Newest' }, { value: 'title', label: 'Title' }, { value: 'price_asc', label: 'Price: low to high' }, { value: 'price_desc', label: 'Price: high to low' }]} />
          {(f.q || f.category || f.level || f.price) && <button type="button" className="btn btn-quiet btn-sm" onClick={() => setSp({})}>Clear filters</button>}
        </form>
        <section aria-live="polite" aria-label="Search results">
          {res.isLoading ? <Loading rows={5} /> : res.error ? <ErrorState error={res.error} onRetry={res.refetch} /> : res.data.data.length === 0 ? (
            <EmptyState title="No courses match" action={<button type="button" className="btn btn-secondary" onClick={() => setSp({})}>Clear filters</button>}>Try a different search, or clear the filters.</EmptyState>
          ) : (
            <>
              <p className="small muted">{res.data.meta.total} course{res.data.meta.total === 1 ? '' : 's'}</p>
              {res.data.data.map((c) => <CourseRow key={c.id} course={c} />)}
              <Pagination meta={res.data.meta} onPage={(p) => set('page', p)} />
            </>
          )}
        </section>
      </div>
    </div>
  );
}
