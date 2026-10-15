import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { useAction, useGet } from '../../api/hooks.js';
import { del, post, put } from '../../api/client.js';
import { useAuth } from '../../auth/AuthContext.jsx';
import { Alert, TextAreaField } from '../../components/ui/Fields.jsx';
import { Breadcrumbs, ErrorState, Loading, RichText } from '../../components/ui/Display.jsx';
import { Pagination } from '../../components/ui/DataTable.jsx';
import { dateTime } from '../../lib/format.js';

export function ThreadPage() {
  const { courseId, threadId } = useParams();
  const { user } = useAuth();
  const [page, setPage] = useState(1);
  const [body, setBody] = useState('');
  const q = useGet(['learn', 'thread', threadId], `/learn/threads/${threadId}`, { page });
  const staff = user.permissions.includes('courses.manage-own') || user.permissions.includes('courses.manage-all');
  const reply = useAction(() => post(`/learn/threads/${threadId}/posts`, { body }), { invalidate: [['learn', 'thread']], onSuccess: () => { setBody(''); setPage(99999); }, success: 'Reply posted.' });
  const remove = useAction((id) => del(`/learn/posts/${id}`), { invalidate: [['learn', 'thread']], success: 'Removed.' });
  const mod = useAction((b) => put(`/learn/threads/${threadId}`, b), { invalidate: [['learn', 'thread']], success: 'Updated.' });
  if (q.isLoading) return <Loading />;
  if (q.error) return <ErrorState error={q.error} onRetry={q.refetch} />;
  const t = q.data.data;
  return (
    <div className="stack">
      <Breadcrumbs items={[{ label: 'My courses', to: '/student/courses' }, { label: 'Discussions', to: `/student/courses/${courseId}?tab=discussions` }, { label: t.title }]} />
      <div className="split"><h1 style={{ margin: 0 }}>{t.title}</h1>{staff && <div className="btn-row"><button className="btn btn-secondary btn-sm" onClick={() => mod.mutate({ pinned: !t.pinned })}>{t.pinned ? 'Unpin' : 'Pin'}</button><button className="btn btn-secondary btn-sm" onClick={() => mod.mutate({ locked: !t.locked })}>{t.locked ? 'Unlock' : 'Lock'}</button></div>}</div>
      <div className="panel panel-pad"><div className="small muted">{t.author} · {dateTime(t.created_at)}</div><RichText html={t.body} /></div>
      {t.posts.map((p) => (
        <div className="panel panel-pad" key={p.id}>
          <div className="split small"><span><strong>{p.author.name}</strong>{p.author.is_staff && <span className="badge badge-info" style={{ marginLeft: 8 }}>Staff</span>} <span className="muted">{dateTime(p.created_at)}{p.edited ? ' · edited' : ''}</span></span>
            {(p.author.id === user.id || staff) && <button className="link-btn" onClick={() => remove.mutate(p.id)}>Remove</button>}</div>
          <RichText html={p.body} />
        </div>
      ))}
      <Pagination meta={q.data.meta} onPage={setPage} />
      {t.locked ? <Alert type="info">This discussion is locked.</Alert> : (
        <form className="panel panel-pad" onSubmit={(e) => { e.preventDefault(); reply.mutate(); }}>
          <TextAreaField label="Your reply" rows={4} value={body} onChange={(e) => setBody(e.target.value)} required />
          <button className="btn btn-primary" disabled={!body.trim() || reply.isPending}>Post reply</button>
        </form>
      )}
    </div>
  );
}
