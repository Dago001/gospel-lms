import { useState } from 'react';
import { useAction, useGet } from '../../api/hooks.js';
import { post } from '../../api/client.js';
import { DataTable } from '../../components/ui/DataTable.jsx';
import { Alert, CheckField, SelectField, TextAreaField, TextField } from '../../components/ui/Fields.jsx';
import { PageHeader, StatusBadge, Stats } from '../../components/ui/Display.jsx';
import { Tabs } from '../../components/ui/Tabs.jsx';
import { dateTime } from '../../lib/format.js';
import { useForm } from '../../lib/useForm.js';
import { useDocumentTitle } from '../../lib/useDocumentTitle.js';

export function NotificationsAdmin() {
  useDocumentTitle('Notifications');
  const [tab, setTab] = useState('log');
  const [f, setF] = useState({ status: '', page: 1 });
  const sum = useGet(['admin', 'notif-summary'], '/admin/notifications/summary');
  const log = useGet(['admin', 'notif-log'], '/admin/notifications/log', f);
  const bf = useForm({ title: '', body: '', audience: 'all', notify: true });
  const [ok, setOk] = useState(false);
  const send = bf.handle(async (v) => { await post('/admin/announcements', v); setOk(true); bf.setValues({ title: '', body: '', audience: 'all', notify: true }); });
  const s = sum.data?.data;
  return (
    <div className="stack">
      <PageHeader title="Notifications" subtitle="Delivery health and platform-wide announcements." />
      {s && <Stats items={[{ label: 'Sent (7 days)', value: s.last_7_days.sent ?? 0 }, { label: 'Failed (7 days)', value: s.last_7_days.failed ?? 0 }, { label: 'Suppressed by user choice', value: s.last_7_days.suppressed ?? 0 }, { label: 'Queued jobs', value: s.pending_jobs }, { label: 'Failed jobs', value: s.failed_jobs }]} />}
      <Tabs tabs={[{ id: 'log', label: 'Delivery log' }, { id: 'broadcast', label: 'Send an announcement' }]} active={tab} onChange={setTab}>
        {tab === 'log' && <>
          <div className="filters"><SelectField label="Status" value={f.status} onChange={(e) => setF({ status: e.target.value, page: 1 })} placeholder="Any" options={['sent', 'failed', 'suppressed']} /></div>
          <DataTable caption="Delivery log" loading={log.isLoading} error={log.error} rows={log.data?.data} meta={log.data?.meta} onPage={(p) => setF({ ...f, page: p })}
            columns={[{ key: 'created_at', header: 'When', render: (l) => dateTime(l.created_at) }, { key: 'notification', header: 'Notification' }, { key: 'channel', header: 'Channel' }, { key: 'status', header: 'Status', render: (l) => <StatusBadge status={l.status} /> }, { key: 'error', header: 'Error' }]} /></>}
        {tab === 'broadcast' && (
          <form onSubmit={send} style={{ maxWidth: 640 }} noValidate>
            {ok && <Alert type="success">Announcement posted. Emails and in-app notifications are being sent in the background.</Alert>}{bf.formError && <Alert type="error">{bf.formError}</Alert>}
            <TextField label="Title" required {...bf.bind('title')} /><TextAreaField label="Message" rows={6} required {...bf.bind('body')} />
            <SelectField label="Audience" {...bf.bind('audience')} options={[{ value: 'all', label: 'Everyone' }, { value: 'students', label: 'Students' }, { value: 'lecturers', label: 'Lecturers' }]} />
            <CheckField {...bf.bind('notify', { checkbox: true })}>Also send by email and in-app notification</CheckField>
            <button className="btn btn-primary" disabled={bf.submitting}>Post announcement</button>
          </form>
        )}
      </Tabs>
    </div>
  );
}
