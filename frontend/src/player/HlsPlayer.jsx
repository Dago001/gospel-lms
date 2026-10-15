import { useEffect, useRef } from 'react';

/** Adaptive streaming of a short-lived signed HLS URL. Safari plays HLS natively; other browsers use hls.js. */
export function HlsPlayer({ src, startAt = 0, trackerRef, onReady }) {
  const ref = useRef(null);
  useEffect(() => {
    const v = ref.current; let hls; let poll; let gone = false;
    const begin = () => { if (startAt > 0) v.currentTime = startAt; onReady?.({ getTime: () => v.currentTime }); };
    if (v.canPlayType('application/vnd.apple.mpegurl')) { v.src = src; v.addEventListener('loadedmetadata', begin, { once: true }); } else {
      // hls.js is large, so it is only downloaded for browsers that need it and lessons that use hosted video.
      import('hls.js').then(({ default: Hls }) => {
        if (gone) return;
        if (!Hls.isSupported()) { onReady?.({ error: true }); return; }
        hls = new Hls({ enableWorker: true });
        hls.loadSource(src); hls.attachMedia(v);
        hls.on(Hls.Events.MANIFEST_PARSED, begin);
        hls.on(Hls.Events.ERROR, (_, d) => { if (d.fatal) onReady?.({ error: true }); });
      });
    }
    const on = (n, fn) => { v.addEventListener(n, fn); return () => v.removeEventListener(n, fn); };
    const offs = [
      on('play', () => trackerRef.current?.play(v.currentTime)),
      on('pause', () => !v.ended && trackerRef.current?.pause(v.currentTime)),
      on('seeked', () => trackerRef.current?.seek(v.currentTime)),
      on('ended', () => trackerRef.current?.ended(v.currentTime)),
    ];
    poll = setInterval(() => trackerRef.current?.tick(v.currentTime, v.playbackRate), 1000);
    return () => { gone = true; offs.forEach((o) => o()); clearInterval(poll); hls?.destroy(); };
  }, [src]); // eslint-disable-line react-hooks/exhaustive-deps
  return <div className="player"><video ref={ref} controls playsInline preload="metadata" controlsList="nodownload" /></div>;
}
