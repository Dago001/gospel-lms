import { useEffect, useMemo, useRef, useState } from 'react';
import { api, del, put } from '../../api/client.js';
import { useAction, useGet } from '../../api/hooks.js';
import { Alert, CheckField, SelectField, TextField } from '../../components/ui/Fields.jsx';
import { FileInput } from '../../components/ui/FileInput.jsx';
import { Loading } from '../../components/ui/Display.jsx';
import { bytes, dateTime } from '../../lib/format.js';

const SAMPLE = {
  holder_name: 'Sample Student Name', course_title: 'Sample Course Title', issued_on: 'Issued on 1 January 2027', grade: 'Final grade 85.0%', duration: 'Course duration 12h 30m',
  signatory: 'Authorised Signatory\nRegistrar', number: 'Certificate no. GL-SAMPLE-0001', verify_url: 'Verify at https://your-site/verify/…',
};
const PAGE_W = 841.89;

/** Upload the certificate artwork and place each student detail on it. Details are filled in automatically when a student qualifies. */
export function CertificateTemplateTab() {
  const q = useGet(['admin', 'certificate-template'], '/admin/certificate-template');
  const [files, setFiles] = useState([]);
  const [fileErr, setFileErr] = useState('');
  const [layout, setLayout] = useState(null);
  const [sel, setSel] = useState('holder_name');
  const [bgUrl, setBgUrl] = useState(null);
  const stage = useRef(null);
  const drag = useRef(null);
  const d = q.data?.data;

  useEffect(() => { if (d) setLayout(d.layout); }, [d]);
  const stamp = d?.meta?.uploaded_at;
  useEffect(() => {
    if (!d?.has_template) { setBgUrl(null); return undefined; }
    let url; let dead = false;
    api('/admin/certificate-template/image', { blob: true }).then((b) => { if (!dead) { url = URL.createObjectURL(b); setBgUrl(url); } }).catch(() => setBgUrl(null));
    return () => { dead = true; if (url) URL.revokeObjectURL(url); };
  }, [d?.has_template, stamp]);

  const upload = useAction(async () => { const fd = new FormData(); fd.append('template', files[0]); return api('/admin/certificate-template', { method: 'POST', body: fd }); },
    { invalidate: [['admin', 'certificate-template']], success: 'Template uploaded.', onSuccess: () => setFiles([]) });
  const save = useAction(() => put('/admin/certificate-template/layout', layout), { invalidate: [['admin', 'certificate-template']], success: 'Layout saved. New certificates will use it.' });
  const remove = useAction(() => del('/admin/certificate-template'), { invalidate: [['admin', 'certificate-template']], success: 'Template removed. The built-in design is used for new certificates.' });

  const dirty = useMemo(() => d && layout && JSON.stringify(d.layout) !== JSON.stringify(layout), [d, layout]);
  if (q.isLoading || !layout) return <Loading rows={4} />;
  const set = (k, patch) => setLayout((l) => ({ ...l, [k]: { ...l[k], ...patch } }));
  const num = (v, lo, hi) => Math.max(lo, Math.min(hi, Number(v) || 0));
  const cur = layout[sel];

  const move = (e) => {
    if (!drag.current) return;
    const r = stage.current.getBoundingClientRect();
    set(drag.current, { x: Math.round(num(((e.clientX - r.left) / r.width) * 100, 0, 100) * 10) / 10, y: Math.round(num(((e.clientY - r.top) / r.height) * 100, 0, 100) * 10) / 10 });
  };
  const nudge = (k, e) => {
    const step = e.shiftKey ? 2 : 0.5;
    const m = { ArrowLeft: ['x', -step], ArrowRight: ['x', step], ArrowUp: ['y', -step], ArrowDown: ['y', step] }[e.key];
    if (!m) return;
    e.preventDefault();
    set(k, { [m[0]]: Math.round(num(layout[k][m[0]] + m[1], 0, 100) * 10) / 10 });
  };

  return (
    <div className="stack">
      <div className="panel">
        <div className="panel-head"><h2>Certificate template</h2>{d.has_template && <button type="button" className="btn btn-secondary btn-sm" onClick={() => { if (window.confirm('Remove the template? Certificates already issued keep the artwork they were issued with.')) remove.mutate(); }}>Remove template</button>}</div>
        <div className="panel-body">
          <p>Upload your certificate artwork. When a student completes every requirement, including the final examination, the system prints their name, the course, the date, the certificate number, a signatory and a verification QR code onto it automatically. Do not type any student details into the artwork itself.</p>
          <ul className="small">
            <li><strong>Format:</strong> PNG or JPG, A4 landscape (297 × 210 mm). Recommended <strong>3508 × 2480 pixels</strong> (300 dpi). Minimum width 1400 px, maximum 10 MB.</li>
            <li>Leave blank areas where the details will go (the editor below shows the default positions); you can move any item afterwards.</li>
            <li>Changing the template affects <strong>new</strong> certificates only. Certificates already issued keep the artwork and layout they were issued with.</li>
          </ul>
          {d.meta && <p className="small muted">Current template: {d.meta.width} × {d.meta.height} px, {bytes(d.meta.bytes)}, uploaded {dateTime(d.meta.uploaded_at)}.</p>}
          <FileInput label={d.has_template ? 'Replace the template' : 'Upload a template'} accept="image/png,image/jpeg" files={files} maxMb={10} error={fileErr} onFiles={(f, err) => { setFiles(f); setFileErr(err || ''); }} />
          <button type="button" className="btn btn-primary" disabled={!files.length || upload.isPending} onClick={() => upload.mutate()}>{upload.isPending ? 'Uploading…' : 'Upload template'}</button>
        </div>
      </div>

      <div className="panel">
        <div className="panel-head"><h2>Layout</h2>
          <div className="btn-row">
            <button type="button" className="btn btn-quiet btn-sm" onClick={() => setLayout(d.defaults)}>Reset to default positions</button>
            <button type="button" className="btn btn-primary btn-sm" disabled={!dirty || save.isPending} onClick={() => save.mutate()}>Save layout</button>
            {d.has_template && <a className="btn btn-secondary btn-sm" href="/api/v1/admin/certificate-template/preview" target="_blank" rel="noopener">Preview as PDF</a>}
          </div>
        </div>
        <div className="panel-body">
          {!d.has_template && <Alert type="info">No template uploaded yet: new certificates use the built-in design. Upload a template to position the details on your own artwork.</Alert>}
          {dirty && <Alert type="warning">You have unsaved changes. “Preview as PDF” shows the last saved layout.</Alert>}
          <div className="tpl-editor">
            <div className="tpl-stage-wrap">
              <div className="tpl-stage" ref={stage} style={bgUrl ? { backgroundImage: `url(${bgUrl})` } : undefined} onPointerMove={move} onPointerUp={() => { drag.current = null; }} onPointerLeave={() => { drag.current = null; }} aria-label="Certificate layout preview. Drag an item or use the arrow keys to move it.">
                {Object.entries(layout).filter(([, f]) => f.visible).map(([k, f]) => k === 'qr' ? (
                  <button key={k} type="button" className={`tpl-item tpl-qr ${sel === k ? 'on' : ''}`} style={{ left: `${f.x}%`, top: `${f.y}%`, width: `${f.size}%` }} aria-label="QR code (selected item can be moved with the arrow keys)" onClick={() => setSel(k)} onKeyDown={(e) => nudge(k, e)}
                    onPointerDown={(e) => { e.preventDefault(); drag.current = k; setSel(k); }}>QR</button>
                ) : (
                  <button key={k} type="button" className={`tpl-item ${sel === k ? 'on' : ''}`} style={{ left: `${f.x}%`, top: `${f.y}%`, width: `${f.width}%`, fontSize: `${(f.size / PAGE_W) * 100}cqw`, color: f.color, fontFamily: f.font === 'sans' ? 'Arial, sans-serif' : f.font === 'times' ? '"Times New Roman", serif' : 'Georgia, serif' }}
                    aria-label={`${d.defaults[k].label} (move with the arrow keys)`} onClick={() => setSel(k)} onKeyDown={(e) => nudge(k, e)} onPointerDown={(e) => { e.preventDefault(); drag.current = k; setSel(k); }}>
                    {SAMPLE[k]}
                  </button>
                ))}
              </div>
              <p className="small muted">Sample details are shown. Drag an item, or select it and use the arrow keys (Shift for bigger steps).</p>
            </div>
            <div className="tpl-fields">
              <SelectField label="Item to adjust" value={sel} onChange={(e) => setSel(e.target.value)} options={Object.keys(layout).map((k) => ({ value: k, label: d.defaults[k].label }))} />
              <CheckField checked={!!cur.visible} onChange={(e) => set(sel, { visible: e.target.checked })}>Print this item</CheckField>
              <div className="form-grid">
                <TextField label="Horizontal position (%)" type="number" min="0" max="100" step="0.5" value={cur.x} onChange={(e) => set(sel, { x: num(e.target.value, 0, 100) })} help="Centre of the item." />
                <TextField label="Vertical position (%)" type="number" min="0" max="100" step="0.5" value={cur.y} onChange={(e) => set(sel, { y: num(e.target.value, 0, 100) })} />
                <TextField label={sel === 'qr' ? 'Size (% of page width)' : 'Text size (pt)'} type="number" min={sel === 'qr' ? 5 : 6} max={sel === 'qr' ? 30 : 72} step="1" value={cur.size} onChange={(e) => set(sel, { size: num(e.target.value, sel === 'qr' ? 5 : 6, sel === 'qr' ? 30 : 72) })} />
                {sel !== 'qr' && <TextField label="Maximum width (%)" type="number" min="10" max="100" step="1" value={cur.width} onChange={(e) => set(sel, { width: num(e.target.value, 10, 100) })} help="Longer text shrinks to fit." />}
                {sel !== 'qr' && <TextField label="Colour" type="color" value={cur.color} onChange={(e) => set(sel, { color: e.target.value })} />}
                {sel !== 'qr' && <SelectField label="Font" value={cur.font} onChange={(e) => set(sel, { font: e.target.value })} options={d.fonts.map((f) => ({ value: f, label: { serif: 'Serif', sans: 'Sans-serif', times: 'Times' }[f] }))} />}
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
