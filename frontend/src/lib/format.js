const TZ = 'Africa/Lagos';
export const money = (minor, currency = 'NGN') =>
  new Intl.NumberFormat('en-NG', { style: 'currency', currency, minimumFractionDigits: 2 }).format((minor ?? 0) / 100);
export const date = (iso) => (iso ? new Intl.DateTimeFormat('en-NG', { dateStyle: 'medium', timeZone: TZ }).format(new Date(iso)) : '-');
export const dateTime = (iso) => (iso ? new Intl.DateTimeFormat('en-NG', { dateStyle: 'medium', timeStyle: 'short', timeZone: TZ }).format(new Date(iso)) : '-');
export const duration = (minutes) => {
  if (!minutes) return null;
  const h = Math.floor(minutes / 60); const m = minutes % 60;
  return h ? `${h}h${m ? ` ${m}m` : ''}` : `${m}m`;
};
export const clock = (seconds) => {
  const s = Math.max(0, Math.floor(seconds)); const h = Math.floor(s / 3600); const m = Math.floor((s % 3600) / 60);
  const pad = (n) => String(n).padStart(2, '0');
  return h ? `${h}:${pad(m)}:${pad(s % 60)}` : `${pad(m)}:${pad(s % 60)}`;
};
export const bytes = (n) => (n < 1024 ? `${n} B` : n < 1048576 ? `${(n / 1024).toFixed(0)} KB` : `${(n / 1048576).toFixed(1)} MB`);
export const pct = (n) => (n == null ? '-' : `${Number(n).toFixed(Number(n) % 1 ? 1 : 0)}%`);
export const titleCase = (s) => String(s || '').replace(/[_-]+/g, ' ').replace(/^\w/, (c) => c.toUpperCase());
/** Naira input (e.g. "25000.50") -> kobo integer; null if invalid. */
export const toMinor = (v) => { const n = Number(String(v).replace(/,/g, '')); return Number.isFinite(n) && n >= 0 ? Math.round(n * 100) : null; };
export const fromMinor = (m) => ((m ?? 0) / 100).toFixed(2);
