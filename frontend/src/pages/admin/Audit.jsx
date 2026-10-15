import { useState } from 'react';
import { useGet } from '../../api/hooks.js';
import { DataTable } from '../../components/ui/DataTable.jsx';
import { TextField } from '../../components/ui/Fields.jsx';
import { PageHeader } from '../../components/ui/Display.jsx';
import { Modal } from '../../components/ui/Modal.jsx';
import { dateTime } from '../../lib/format.js';
import { useDocumentTitle } from '../../lib/useDocumentTitle.js';

export function Audit() {
  useDocumentTitle('Audit log');
  const [f, setF] = useState({ action: '', subject_type: '', from: '', to: '', page: 1 });
  const set = (k, v) => setF({ ...f, [k]: v, page: 1 });
  const q = useGet(['admin', 'audit'], '/admin/audit-logs', f);
  const [view, setView] = useState(null);
  const csv = `/api/v1/admin/audit-logs?${new URLSearchParams({ ...Object.fromEntries(Object.entries(f).filter(([k, v]) => v && k !== 'page')), format: 'csv' })}`;
  return (
    <div>
      <PageHeader title="Audit log" subtitle="An append-only record of sensitive actions. Entries cannot be edited or deleted." actions={<a className="btn btn-secondary" href={csv} download>Export CSV</a>} />
      <div className="filters"><TextField label="Action starts with" placeholder="e.g. grade." value={f.action} onChange={(e) => set('action', e.target.value)} /><TextField label="Subject type" placeholder="e.g. Course" value={f.subject_type} onChange={(e) => set('subject_type', e.target.value)} /><TextField label="From" type="date" value={f.from} onChange={(e) => set('from', e.target.value)} /><TextField label="To" type="date" value={f.to} onChange={(e) => set('to', e.target.value)} /></div>
      <DataTable caption="Audit entries" loading={q.isLoading} error={q.error} onRetry={q.refetch} rows={q.data?.data} meta={q.data?.meta} onPage={(p) => setF({ ...f, page: p })}
        columns={[{ key: 'at', header: 'When', render: (l) => dateTime(l.at) }, { key: 'action', header: 'Action' }, { key: 'subject_type', header: 'Subject', render: (l) => l.subject_type ? `${l.subject_type} ${String(l.subject_id).slice(0, 8)}…` : '-' }, { key: 'actor_id', header: 'By', render: (l) => (l.actor_id ? `${l.actor_id.slice(0, 8)}…` : 'System') }, { key: 'reason', header: 'Reason' }, { key: 'ip', header: 'IP' },
          { key: 'd', header: '', render: (l) => (l.old || l.new) && <button className="link-btn" onClick={() => setView(l)}>Details</button> }]} />
      {view && <Modal title={view.action} onClose={() => setView(null)}><dl className="kv"><dt>When</dt><dd>{dateTime(view.at)}</dd><dt>Reason</dt><dd>{view.reason || '-'}</dd></dl><h3>Before</h3><pre className="panel panel-pad small" style={{ overflow: 'auto' }}>{JSON.stringify(view.old, null, 2) || '—'}</pre><h3>After</h3><pre className="panel panel-pad small" style={{ overflow: 'auto' }}>{JSON.stringify(view.new, null, 2) || '—'}</pre></Modal>}
    </div>
  );
}
