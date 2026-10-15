import { useMemo, useState } from 'react';
import { Icon } from './Icon.jsx';
import { EmptyState, ErrorState, Loading } from './Display.jsx';

/**
 * columns: [{ key, header, render?(row), sortable?, numeric?, className? }]
 * Sorting is client-side over the loaded page; pagination is server-side via `meta` + `onPage`.
 */
export function DataTable({ caption, columns, rows, loading, error, onRetry, empty, meta, onPage, rowKey = 'id', stackOnMobile = true }) {
  const [sort, setSort] = useState(null);
  const sorted = useMemo(() => {
    if (!sort || !rows) return rows;
    const col = columns.find((c) => c.key === sort.key);
    const val = (r) => (col.sortValue ? col.sortValue(r) : r[sort.key]);
    return [...rows].sort((a, b) => { const x = val(a); const y = val(b); if (x == null) return 1; if (y == null) return -1; return (x > y ? 1 : x < y ? -1 : 0) * sort.dir; });
  }, [rows, sort, columns]);

  if (error) return <ErrorState error={error} onRetry={onRetry} />;
  if (loading) return <Loading rows={5} label="Loading table" />;
  if (!rows?.length) return empty || <div className="table-wrap"><div className="table-empty">Nothing to show yet.</div></div>;
  return (
    <>
      <div className="table-wrap" tabIndex={0} role="region" aria-label={caption || 'Data table'}>
        <table className={`table ${stackOnMobile ? 'stack-mobile' : ''}`}>
          {caption && <caption className="sr-only">{caption}</caption>}
          <thead>
            <tr>
              {columns.map((c) => (
                <th key={c.key} scope="col" className={c.numeric ? 'num' : ''}
                  aria-sort={sort?.key === c.key ? (sort.dir === 1 ? 'ascending' : 'descending') : undefined}>
                  {c.sortable ? (
                    <button type="button" className="th-sort" onClick={() => setSort((s) => (s?.key === c.key ? { key: c.key, dir: -s.dir } : { key: c.key, dir: 1 }))}>
                      {c.header}<Icon name="chevdown" size={14} />
                    </button>
                  ) : c.header}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {sorted.map((r, i) => (
              <tr key={r[rowKey] ?? i}>
                {columns.map((c) => <td key={c.key} data-label={typeof c.header === 'string' ? c.header : c.key} className={`${c.numeric ? 'num' : ''} ${c.className || ''}`}>{c.render ? c.render(r) : r[c.key]}</td>)}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {meta && onPage && <Pagination meta={meta} onPage={onPage} />}
    </>
  );
}

export function Pagination({ meta, onPage }) {
  if (!meta || meta.last_page <= 1) return meta?.total != null ? <div className="pagination muted">{meta.total} result{meta.total === 1 ? '' : 's'}</div> : null;
  return (
    <nav className="pagination" aria-label="Pagination">
      <span className="muted">Page {meta.current_page} of {meta.last_page} · {meta.total} results</span>
      <span className="btn-row">
        <button type="button" className="btn btn-secondary btn-sm" disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)}>Previous</button>
        <button type="button" className="btn btn-secondary btn-sm" disabled={meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)}>Next</button>
      </span>
    </nav>
  );
}

export { EmptyState };
