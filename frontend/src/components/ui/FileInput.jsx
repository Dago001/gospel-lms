import { useId, useRef, useState } from 'react';
import { bytes } from '../../lib/format.js';
import { Icon } from './Icon.jsx';

export function FileInput({ label, accept, multiple, onFiles, files = [], help, error, maxMb }) {
  const id = useId();
  const ref = useRef(null);
  const [over, setOver] = useState(false);
  const take = (list) => {
    const arr = [...list];
    const tooBig = maxMb ? arr.find((f) => f.size > maxMb * 1048576) : null;
    if (tooBig) { onFiles([], `“${tooBig.name}” is larger than ${maxMb} MB.`); return; }
    onFiles(multiple ? arr : arr.slice(0, 1));
  };
  return (
    <div className="field">
      {label && <label className="label" htmlFor={id}>{label}</label>}
      <div className={`file-drop ${over ? 'is-over' : ''}`} onDragOver={(e) => { e.preventDefault(); setOver(true); }} onDragLeave={() => setOver(false)}
        onDrop={(e) => { e.preventDefault(); setOver(false); take(e.dataTransfer.files); }}>
        <Icon name="upload" size={24} />
        <p style={{ margin: '8px auto' }}>Drag a file here, or <button type="button" className="link-btn" onClick={() => ref.current?.click()}>browse</button></p>
        <input ref={ref} id={id} type="file" className="sr-only" accept={accept} multiple={multiple} onChange={(e) => take(e.target.files)} />
        {files.length > 0 && <ul className="list-plain small">{files.map((f) => <li key={f.name + f.size}>{f.name} <span className="muted">({bytes(f.size)})</span></li>)}</ul>}
      </div>
      {help && !error && <div className="help">{help}</div>}
      {error && <div className="error-text" role="alert">{error}</div>}
    </div>
  );
}
