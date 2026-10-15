import { useState } from 'react';
import { useAction, useGet } from '../../api/hooks.js';
import { del, post, put } from '../../api/client.js';
import { DataTable } from '../../components/ui/DataTable.jsx';
import { Alert, TextAreaField, TextField } from '../../components/ui/Fields.jsx';
import { PageHeader } from '../../components/ui/Display.jsx';
import { ConfirmDialog, Modal } from '../../components/ui/Modal.jsx';
import { useForm } from '../../lib/useForm.js';
import { useDocumentTitle } from '../../lib/useDocumentTitle.js';

export function Categories() {
  useDocumentTitle('Categories');
  const q = useGet(['admin', 'categories'], '/admin/categories');
  const [edit, setEdit] = useState(null); const [rm, setRm] = useState(null);
  const remove = useAction((id) => del(`/admin/categories/${id}`), { invalidate: [['admin', 'categories']], success: 'Deleted.', onSuccess: () => setRm(null), onError: () => setRm(null) });
  return (
    <div>
      <PageHeader title="Course categories" actions={<button className="btn btn-primary" onClick={() => setEdit({})}>Add category</button>} />
      <DataTable caption="Categories" loading={q.isLoading} error={q.error} rows={q.data?.data} columns={[
        { key: 'name', header: 'Name', sortable: true }, { key: 'description', header: 'Description' }, { key: 'courses_count', header: 'Courses', numeric: true, sortable: true }, { key: 'sort_order', header: 'Order', numeric: true },
        { key: 'a', header: '', render: (c) => <span className="btn-row"><button className="btn btn-secondary btn-sm" onClick={() => setEdit(c)}>Edit</button><button className="btn btn-quiet btn-sm" onClick={() => setRm(c)}>Delete</button></span> },
      ]} />
      {edit && <CatModal cat={edit.id ? edit : null} onClose={() => setEdit(null)} />}
      {rm && <ConfirmDialog title={`Delete “${rm.name}”?`} danger confirmLabel="Delete" busy={remove.isPending} onCancel={() => setRm(null)} onConfirm={() => remove.mutate(rm.id)} message="Categories that still contain courses cannot be deleted." />}
    </div>
  );
}
function CatModal({ cat, onClose }) {
  const f = useForm({ name: cat?.name || '', description: cat?.description || '', sort_order: cat?.sort_order ?? 0 });
  const act = useAction(() => Promise.resolve(), { invalidate: [['admin', 'categories']] });
  const save = f.handle(async (v) => { const b = { ...v, sort_order: Number(v.sort_order) }; await (cat ? put(`/admin/categories/${cat.id}`, b) : post('/admin/categories', b)); act.mutate(); onClose(); });
  return (
    <Modal title={cat ? 'Edit category' : 'Add category'} onClose={onClose} footer={<><button className="btn btn-secondary" onClick={onClose}>Cancel</button><button className="btn btn-primary" form="cf" disabled={f.submitting}>Save</button></>}>
      <form id="cf" onSubmit={save} noValidate>{f.formError && <Alert type="error">{f.formError}</Alert>}<TextField label="Name" required {...f.bind('name')} /><TextAreaField label="Description" rows={3} {...f.bind('description')} /><TextField label="Display order" type="number" min="0" {...f.bind('sort_order')} /></form>
    </Modal>
  );
}
