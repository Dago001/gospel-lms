import { useEffect, useRef } from 'react';

let apiPromise = null;
function loadYouTubeApi() {
  if (window.YT?.Player) return Promise.resolve(window.YT);
  if (!apiPromise) {
    apiPromise = new Promise((resolve) => {
      const prev = window.onYouTubeIframeAPIReady;
      window.onYouTubeIframeAPIReady = () => { prev?.(); resolve(window.YT); };
      const s = document.createElement('script');
      s.src = 'https://www.youtube.com/iframe_api';
      s.async = true;
      document.head.appendChild(s);
    });
  }
  return apiPromise;
}

/** Official YouTube player (privacy-enhanced host). We only observe it through its supported API. */
export function YouTubePlayer({ videoId, startAt = 0, trackerRef, onReady }) {
  const host = useRef(null);
  useEffect(() => {
    let player; let poll; let disposed = false;
    loadYouTubeApi().then((YT) => {
      if (disposed || !host.current) return;
      const el = document.createElement('div');
      host.current.appendChild(el);
      player = new YT.Player(el, {
        host: 'https://www.youtube-nocookie.com', videoId,
        playerVars: { start: Math.floor(startAt), rel: 0, modestbranding: 1, playsinline: 1, origin: window.location.origin },
        events: {
          onReady: () => onReady?.({ getTime: () => player.getCurrentTime() }),
          onStateChange: (e) => {
            const t = player.getCurrentTime();
            if (e.data === YT.PlayerState.PLAYING) trackerRef.current?.play(t);
            else if (e.data === YT.PlayerState.PAUSED) trackerRef.current?.pause(t);
            else if (e.data === YT.PlayerState.ENDED) trackerRef.current?.ended(t);
          },
          onError: () => onReady?.({ error: true }),
        },
      });
      poll = setInterval(() => { try { trackerRef.current?.tick(player.getCurrentTime(), player.getPlaybackRate()); } catch { /* not ready */ } }, 1000);
    });
    return () => { disposed = true; clearInterval(poll); try { player?.destroy(); } catch { /* ignore */ } };
  }, [videoId]); // eslint-disable-line react-hooks/exhaustive-deps
  return <div className="player" ref={host} />;
}
