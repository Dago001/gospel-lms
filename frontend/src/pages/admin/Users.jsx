import { useState } from 'react';
import { useAction, useGet } from '../../api/hooks.js';
import { post, put } from '../../api/client.js';
import { DataTable } from '../../components/ui/DataTable.jsx';
import { Alert, CheckField, SelectField, TextField } from '../../components/ui/Fields.jsx';
import { PageHeader, StatusBadge } from '../../components/ui/Display.jsx';
import { Modal } from '../../components/ui/Modal.jsx';
import { date, dateTime, titleCase } from '../../lib/format.js';
import { useAuth } from '../../auth/AuthContext.jsx';
import { useForm } from '../../lib/useForm.js';
import { useDocumentTitle } from '../../lib/useDocumentTitle.js';

const ROLES = ['student', 'lecturer', 'finance', 'admin'];

export function Users() {
  useDocumentTitle('Users');
  const { can, user: me } = useAuth();
  const [f, setF] = useState({ q: '', role: '', status: '', page: 1 });
  const set = (k, v) => setF({ ...f, [k]: v, page: k === 'page' ? v : 1 });
  const q = useGet(['admin', 'users'], '/admin/users', f);
  const [modal, setModal] = useState(null);
  return (
    <div>
      <PageHeader title="Users" actions={can('users.manage') && <button className="btn btn-primary" onClick={() => setModal({ kind: 'create' })}>Add staff or lecturer</button>} />
      <div className="filters">
        <TextField label="Search name or email" type="search" value={f.q} onChange={(e) => set('q', e.target.value)} />
        <SelectField label="Role" value={f.role} onChange={(e) => set('role', e.target.value)} placeholder="Any role" options={ROLES.map((r) => ({ value: r, label: titleCase(r) }))} />
        <SelectField label="Status" value={f.status} onChange={(e) => set('status', e.target.value)} placeholder="Any" options={['active', 'suspended']} />
      </div>
      <DataTable caption="Users" loading={q.isLoading} error={q.error} onRetry={q.refetch} rows={q.data?.data} meta={q.data?.meta} onPage={(p) => set('page', p)}
        columns={[
          { key: 'name', header: 'Name', sortable: true, render: (u) => <>{u.name}<div className="micro muted">{u.email}</div></> },
          { key: 'roles', header: 'Roles', render: (u) => u.roles.map((r) => <span key={r} className="badge badge-neutral" style={{ marginRight: 4 }}>{titleCase(r)}</span>) },
          { key: 'status', header: 'Status', render: (u) => <StatusBadge status={u.status} /> },
          { key: 'last_login_at', header: 'Last sign-in', sortable: true, render: (u) => (u.last_login_at ? dateTime(u.last_login_at) : 'Never') },
          { key: 'created_at', header: 'Joined', sortable: true, render: (u) => date(u.created_at) },
          { key: 'a', header: '', render: (u) => can('users.manage') && u.id !== me.id && u.status !== 'anonymised' && <button className="btn btn-secondary btn-sm" onClick={() => setModal({ kind: 'manage', user: u })}>Manage</button> },
        ]} />
      {modal?.kind === 'create' && <CreateUser onClose={() => setModal(null)} />}
      {modal?.kind === 'manage' && <ManageUser user={modal.user} onClose={() => setModal(null)} />}
    </div>
  );
}

function CreateUser({ onClose }) {
  const [roles, setRoles] = useState(['lecturer']);
  const act = useAction(() => Promise.resolve(), { invalidate: [['admin', 'users']], success: 'Account created. A link to set the password was emailed.' });
  const f = useForm({ name: '', email: '' });
  const save = f.handle(async (v) => { await post('/admin/users', { ...v, roles }); act.mutate(); onClose(); });
  return (
    <Modal title="Add a staff or lecturer account" onClose={onClose} footer={<><button className="btn btn-secondary" onClick={onClose}>Cancel</button><button className="btn btn-primary" form="cu" disabled={f.submitting || !roles.length}>Create account</button></>}>
      <form id="cu" onSubmit={save} noValidate>
        {f.formError && <Alert type="error">{f.formError}</Alert>}
        <TextField label="Full name" required {...f.bind('name')} /><TextField label="Email" type="email" required {...f.bind('email')} />
        <fieldset style={{ border: 0, padding: 0 }}><legend className="label">Roles</legend>{ROLES.map((r) => <CheckField key={r} checked={roles.includes(r)} onChange={(e) => setRoles(e.target.checked ? [...roles, r] : roles.filter((x) => x !== r))}>{titleCase(r)}{['admin', 'finance'].includes(r) ? ' (must use two-step verification)' : ''}</CheckField>)}</fieldset>
        <p className="small muted">The person receives an email to choose their own password. You never see it.</p>
      </form>
    </Modal>
  );
}

function ManageUser({ user, onClose }) {
  const [roles, setRoles] = useState(user.roles);
  const reason = useForm({ reason: '', confirm_email: '' });
  const [tab, setTab] = useState('roles');
  const inv = { invalidate: [['admin', 'users']], onSuccess: onClose };
  const saveRoles = useAction(() => put(`/admin/users/${user.id}/roles`, { roles }), { ...inv, success: 'Roles updated.' });
  const suspend = useAction(() => post(`/admin/users/${user.id}/suspend`, { reason: reason.values.reason }), { ...inv, success: 'User suspended and signed out everywhere.' });
  const reactivate = useAction(() => post(`/admin/users/${user.id}/reactivate`), { ...inv, success: 'User reactivated.' });
  const anon = useAction(() => post(`/admin/users/${user.id}/anonymise`, reason.values), { ...inv, success: 'Account anonymised.' });
  return (
    <Modal title={user.name} onClose={onClose}>
      <p className="muted small">{user.email}</p>
      <div className="tabs" role="tablist">{[['roles', 'Roles'], ['access', user.status === 'active' ? 'Suspend' : 'Reactivate'], ['erase', 'Erase personal data']].map(([id, label]) => <button key={id} role="tab" aria-selected={tab === id} className="tab" onClick={() => setTab(id)}>{label}</button>)}</div>
      {tab === 'roles' && <>{ROLES.map((r) => <CheckField key={r} checked={roles.includes(r)} onChange={(e) => setRoles(e.target.checked ? [...roles, r] : roles.filter((x) => x !== r))}>{titleCase(r)}</CheckField>)}<button className="btn btn-primary" disabled={!roles.length || saveRoles.isPending} onClick={() => saveRoles.mutate()}>Save roles</button></>}
      {tab === 'access' && (user.status === 'active' ? <><TextField label="Reason (shown in the audit log)" required {...reason.bind('reason')} /><button className="btn btn-danger" disabled={reason.values.reason.length < 3 || suspend.isPending} onClick={() => suspend.mutate()}>Suspend account</button></> : <><p>This account is suspended{user.suspension_reason ? `: ${user.suspension_reason}` : ''}.</p><button className="btn btn-primary" onClick={() => reactivate.mutate()}>Reactivate</button></>)}
      {tab === 'erase' && <><Alert type="warning" title="Irreversible">Removes the person&apos;s name, email, profile and security details. Grades, payments and certificates are kept, without identifying details.</Alert><TextField label="Reason" {...reason.bind('reason')} /><TextField label={`Type ${user.email} to confirm`} {...reason.bind('confirm_email')} /><button className="btn btn-danger" disabled={reason.values.reason.length < 5 || reason.values.confirm_email.toLowerCase() !== user.email.toLowerCase() || anon.isPending} onClick={() => anon.mutate()}>Erase personal data</button></>}
    </Modal>
  );
}
