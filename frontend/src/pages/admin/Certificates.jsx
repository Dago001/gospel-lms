import { useState } from 'react';
import { useAction, useGet } from '../../api/hooks.js';
import { post } from '../../api/client.js';
import { DataTable } from '../../components/ui/DataTable.jsx';
import { Alert, CheckField, SelectField, TextAreaField, TextField } from '../../components/ui/Fields.jsx';
import { PageHeader, StatusBadge } from '../../components/ui/Display.jsx';
import { Modal } from '../../components/ui/Modal.jsx';
import { Tabs } from '../../components/ui/Tabs.jsx';
import { CertificateTemplateTab } from './CertificateTemplate.jsx';
import { date, dateTime } from '../../lib/format.js';
import { useForm } from '../../lib/useForm.js';
import { useDocumentTitle } from '../../lib/useDocumentTitle.js';

export function AdminCertificates() {
  useDocumentTitle('Certificates');
  const [tab, setTab] = useState('issued');
  return (
    <div>
      <PageHeader title="Certificates" subtitle="Certificates are issued automatically when a student meets every requirement. Upload the certificate template here." />
      <Tabs tabs={[{ id: 'issued', label: 'Issued certificates' }, { id: 'template', label: 'Template & layout' }]} active={tab} onChange={setTab}>
        {tab === 'issued' ? <IssuedList /> : <CertificateTemplateTab />}
      </Tabs>
    </div>
  );
}

function IssuedList() {
  const [f, setF] = useState({ q: '', status: '', page: 1 });
  const q = useGet(['admin', 'certificates'], '/admin/certificates', f);
  const [modal, setModal] = useState(null);
  return (
    <div>
      <div className="filters"><TextField label="Search number or name" type="search" value={f.q} onChange={(e) => setF({ ...f, q: e.target.value, page: 1 })} /><SelectField label="Status" value={f.status} onChange={(e) => setF({ ...f, status: e.target.value, page: 1 })} placeholder="Any" options={['active', 'revoked']} /></div>
      <DataTable caption="Certificates" loading={q.isLoading} error={q.error} onRetry={q.refetch} rows={q.data?.data} meta={q.data?.meta} onPage={(p) => setF({ ...f, page: p })}
        columns={[
          { key: 'number', header: 'Number' }, { key: 'holder_name', header: 'Holder', sortable: true }, { key: 'course_title', header: 'Course', sortable: true },
          { key: 'issued_at', header: 'Issued', sortable: true, render: (c) => date(c.issued_at) },
          { key: 'status', header: 'Status', render: (c) => <>{<StatusBadge status={c.status} />}{c.revocations[0] && <div className="micro muted">{dateTime(c.revocations[0].revoked_at)}: {c.revocations[0].reason}</div>}</> },
          { key: 'a', header: '', render: (c) => c.status === 'active' ? <button className="btn btn-secondary btn-sm" onClick={() => setModal({ kind: 'revoke', c })}>Revoke</button> : <button className="btn btn-quiet btn-sm" onClick={() => setModal({ kind: 'reissue', c })}>Reissue</button> },
        ]} />
      {modal && <Act modal={modal} onClose={() => setModal(null)} />}
    </div>
  );
}
function Act({ modal, onClose }) {
  const revoke = modal.kind === 'revoke';
  const f = useForm({ reason: '', block_reissue: true });
  const act = useAction(() => post(`/admin/certificates/${modal.c.id}/${modal.kind}`, revoke ? f.values : { reason: f.values.reason }), { invalidate: [['admin', 'certificates']], success: revoke ? 'Certificate revoked.' : 'New certificate issued.', onSuccess: onClose });
  return (
    <Modal title={`${revoke ? 'Revoke' : 'Reissue'} ${modal.c.number}`} onClose={onClose} footer={<><button className="btn btn-secondary" onClick={onClose}>Cancel</button><button className={`btn ${revoke ? 'btn-danger' : 'btn-primary'}`} disabled={f.values.reason.length < 10 || act.isPending} onClick={() => act.mutate()}>{revoke ? 'Revoke certificate' : 'Reissue'}</button></>}>
      <Alert type={revoke ? 'warning' : 'info'}>{revoke ? 'The public verification page will show this certificate as revoked immediately. The reason is kept in the audit log and is not shown publicly.' : 'A new certificate with a new number and verification code is created. The revoked one stays revoked.'}</Alert>
      <TextAreaField label="Reason (at least 10 characters)" rows={3} {...f.bind('reason')} />
      {revoke && <CheckField {...f.bind('block_reissue', { checkbox: true })}>Do not issue a new certificate automatically if the student qualifies again</CheckField>}
    </Modal>
  );
}
