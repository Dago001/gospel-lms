/**
 * Minimal resumable (tus 1.0.0) uploader for the video host. The backend has already created the upload; we only
 * send bytes. If the connection drops we ask the server how much it has and continue from there.
 */
export async function tusUpload(file, uploadUrl, onProgress, signal) {
  const CHUNK = 5 * 1024 * 1024;   // a multiple of 256 KiB, as the host requires
  const headers = { 'Tus-Resumable': '1.0.0' };
  const offsetOf = async () => {
    const r = await fetch(uploadUrl, { method: 'HEAD', headers, signal });
    if (!r.ok) throw new Error('Could not resume upload');
    return Number(r.headers.get('Upload-Offset') || 0);
  };
  let offset = await offsetOf().catch(() => 0);
  let failures = 0;
  while (offset < file.size) {
    const end = Math.min(offset + CHUNK, file.size);
    try {
      const r = await fetch(uploadUrl, { method: 'PATCH', headers: { ...headers, 'Content-Type': 'application/offset+octet-stream', 'Upload-Offset': String(offset) }, body: file.slice(offset, end), signal });
      if (!r.ok) throw new Error(`Upload failed (${r.status})`);
      offset = Number(r.headers.get('Upload-Offset') || end);
      failures = 0;
      onProgress?.(Math.min(100, Math.round((offset / file.size) * 100)));
    } catch (e) {
      if (e.name === 'AbortError' || ++failures > 6) throw e;
      await new Promise((res) => setTimeout(res, Math.min(1000 * 2 ** failures, 15000)));
      offset = await offsetOf().catch(() => offset);
    }
  }
}
