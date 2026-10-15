import { useState } from 'react';
import { useAction, useGet } from '../../api/hooks.js';
import { put } from '../../api/client.js';
import { DataTable } from '../../components/ui/DataTable.jsx';
import { Alert, TextField } from '../../components/ui/Fields.jsx';
import { ErrorState, Loading, PageHeader, StatusBadge } from '../../components/ui/Display.jsx';
import { Tabs } from '../../components/ui/Tabs.jsx';
import { bytes, dateTime } from '../../lib/format.js';
import { useDocumentTitle } from '../../lib/useDocumentTitle.js';

export function System() {
  useDocumentTitle('System & integrations');
  const [tab, setTab] = useState('health');
  return (
    <div>
      <PageHeader title="System & integrations" />
      <Tabs tabs={[['health', 'Health'], ['integrations', 'Integrations'], ['settings', 'Settings'], ['backups', 'Backups']].map(([id, label]) => ({ id, label }))} active={tab} onChange={setTab}>
        {tab === 'health' && <Health />}{tab === 'integrations' && <Integrations />}{tab === 'settings' && <Settings />}{tab === 'backups' && <Backups />}
      </Tabs>
    </div>
  );
}

const LABEL = { database: 'Database', cache: 'Cache', storage: 'Private file storage', queue: 'Background queue', scheduler: 'Scheduled tasks', backups: 'Backups' };
function Health() {
  const q = useGet(['admin', 'health'], '/admin/health', undefined, { refetchInterval: 30_000, retry: false });
  if (q.isLoading) return <Loading />;
  const d = q.data?.data;
  if (!d) return <ErrorState error={q.error} onRetry={q.refetch} />;
  return (
    <div className="stack">
      <Alert type={d.status === 'ok' ? 'success' : d.status === 'degraded' ? 'warning' : 'error'} title={`System status: ${d.status}`}>Checked {dateTime(d.checked_at)}. This page refreshes every 30 seconds.</Alert>
      <DataTable caption="Health checks" rows={Object.entries(d.checks).map(([k, v]) => ({ id: k, name: LABEL[k] || k, ...v }))}
        columns={[{ key: 'name', header: 'Check' }, { key: 'ok', header: 'Result', render: (c) => <StatusBadge status={c.ok ? 'ok' : 'failed'} label={c.ok ? 'OK' : 'Needs attention'} /> },
          { key: 'detail', header: 'Detail', render: (c) => [c.ms != null && `${c.ms} ms`, c.pending != null && `${c.pending} queued, ${c.failed} failed`, c.last_beat && `last beat ${dateTime(c.last_beat)}`, c.driver, c.last_backup && `last backup ${dateTime(c.last_backup)}`, c.last_restore_test && `last restore test ${dateTime(c.last_restore_test)}`].filter(Boolean).join(' · ') || '-' }]} />
    </div>
  );
}
function Integrations() {
  const q = useGet(['admin', 'integrations'], '/admin/integrations');
  return (
    <div className="stack">
      <Alert type="info">Credentials are kept in the server environment, never in the database or this screen. This page only shows whether each service is configured.</Alert>
      <DataTable caption="Integrations" loading={q.isLoading} error={q.error} rows={q.data?.data?.map((r) => ({ ...r, id: r.name }))}
        columns={[{ key: 'name', header: 'Service' }, { key: 'configured', header: 'Status', render: (r) => <StatusBadge status={r.configured ? 'ok' : 'pending'} label={r.configured ? 'Configured' : 'Not configured'} /> }, { key: 'mode', header: 'Mode' }, { key: 'warning', header: 'Warning', render: (r) => (r.warning ? <span className="badge badge-error">{r.warning}</span> : '-') }]} />
    </div>
  );
}
function Settings() {
  const q = useGet(['admin', 'settings'], '/admin/settings');
  const [vals, setVals] = useState({});
  const save = useAction(() => put('/admin/settings', { settings: vals }), { invalidate: [['admin', 'settings'], ['public']], success: 'Settings saved.', onSuccess: () => setVals({}) });
  if (q.isLoading) return <Loading />;
  if (q.error) return <ErrorState error={q.error} onRetry={q.refetch} />;
  return (
    <form style={{ maxWidth: 640 }} onSubmit={(e) => { e.preventDefault(); save.mutate(); }}>
      <p className="muted">These appear on receipts, certificates and public pages. Nothing is invented: leave a field blank until the academy has the real information.</p>
      {q.data.data.map((s) => <TextField key={s.key} label={s.description} help={s.key} value={vals[s.key] ?? s.value ?? ''} onChange={(e) => setVals({ ...vals, [s.key]: e.target.value })} />)}
      <button className="btn btn-primary" disabled={!Object.keys(vals).length || save.isPending}>Save settings</button>
    </form>
  );
}
function Backups() {
  const q = useGet(['admin', 'backups'], '/admin/backups');
  return (
    <div className="stack">
      <Alert type="info">Backups run nightly and a restore test runs weekly into a scratch database. Run <code>php artisan gospel:backup</code> and <code>gospel:backup-verify</code> on the server for an immediate check.</Alert>
      <DataTable caption="Backup and restore-test history" loading={q.isLoading} error={q.error} rows={q.data?.data}
        columns={[{ key: 'started_at', header: 'Started', render: (b) => dateTime(b.started_at) }, { key: 'type', header: 'Type', render: (b) => (b.type === 'backup' ? 'Backup' : 'Restore test') }, { key: 'status', header: 'Result', render: (b) => <StatusBadge status={b.status} /> },
          { key: 'size_bytes', header: 'Size', numeric: true, render: (b) => (b.size_bytes ? bytes(b.size_bytes) : '-') }, { key: 'error', header: 'Error' }]} />
    </div>
  );
}
