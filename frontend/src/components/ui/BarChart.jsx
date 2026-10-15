/**
 * Minimal accessible bar chart: one series, one hue, values labelled directly. Every chart also ships a data table
 * for screen readers so no information is conveyed by graphics alone.
 */
export function BarChart({ data, label, valueLabel = 'Value', format = (v) => v, height = 160 }) {
  if (!data?.length) return <p className="muted small">No data for this period.</p>;
  const max = Math.max(...data.map((d) => d.value), 1);
  const w = Math.max(data.length * 34, 300);
  return (
    <figure style={{ margin: 0 }}>
      <svg role="img" aria-label={label} viewBox={`0 0 ${w} ${height + 34}`} style={{ width: '100%', maxHeight: height + 40 }}>
        <line x1="0" x2={w} y1={height} y2={height} stroke="var(--border-strong)" />
        {data.map((d, i) => {
          const h = Math.round((d.value / max) * (height - 22));
          const x = i * 34 + 6;
          return (
            <g key={d.label}>
              <rect x={x} y={height - h} width="22" height={h} fill="var(--green)" rx="1" />
              <text x={x + 11} y={height - h - 4} textAnchor="middle" fontSize="9" fill="var(--text)">{format(d.value)}</text>
              {(data.length <= 14 || i % Math.ceil(data.length / 14) === 0) && <text x={x + 11} y={height + 14} textAnchor="middle" fontSize="8" fill="var(--text-secondary)">{d.label}</text>}
            </g>
          );
        })}
      </svg>
      <table className="sr-only"><caption>{label}</caption><thead><tr><th>Period</th><th>{valueLabel}</th></tr></thead><tbody>{data.map((d) => <tr key={d.label}><td>{d.label}</td><td>{format(d.value)}</td></tr>)}</tbody></table>
    </figure>
  );
}
