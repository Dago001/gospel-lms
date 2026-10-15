import { post } from '../api/client.js';

/**
 * Reports playback to the server, which alone decides what counts.
 *  - every event carries a sequence number (the server de-duplicates replays)
 *  - events queue locally and retry, so a dropped connection loses nothing
 *  - seeks are reported as seeks (no credit); only continuous playback is reported as playback
 */
export class PlaybackTracker {
  constructor({ sessionId, getTime, onProgress, onExpired, heartbeatMs = 10_000 }) {
    this.sessionId = sessionId;
    this.getTime = getTime;
    this.onProgress = onProgress;
    this.onExpired = onExpired;
    this.heartbeatMs = heartbeatMs;
    this.seq = 0;
    this.queue = [];
    this.playing = false;
    this.lastT = null;
    this.lastWall = null;
    this.flushing = false;
    this.dead = false;
    this.status = 'idle';        // idle | saving | saved | offline
    this.timer = null;
  }

  _emit(type, position) {
    if (this.dead || !Number.isFinite(position)) return;
    this.queue.push({ seq: ++this.seq, type, position: Math.max(0, Math.round(position * 100) / 100), client_ts_ms: Date.now() });
    this.lastT = position;
    this.lastWall = performance.now();
  }

  play(t) { this.playing = true; this._emit('play', t); this._startTimer(); this.flush(); }
  pause(t) { if (!this.playing) { this._emit('seek', t); } else { this._emit('pause', t); } this.playing = false; this._stopTimer(); this.flush(); }
  seek(t) { this._emit('seek', t); this.flush(); }
  ended(t) { this._emit('ended', t); this.playing = false; this._stopTimer(); this.flush(); }

  /** Call from the player's time polling (about once a second) to notice jumps the player did not announce. */
  tick(t, rate = 1) {
    if (!this.playing || this.lastT === null) return;
    const wall = (performance.now() - this.lastWall) / 1000;
    const expected = this.lastT + wall * rate;
    if (Math.abs(t - expected) > Math.max(2.5, wall * 0.5 + 2)) {    // jumped
      this._emit('seek', t);
      this._emit('play', t);
      this.flush();
    }
  }

  _startTimer() { this._stopTimer(); this.timer = setInterval(() => { this._emit('heartbeat', this.getTime()); this.flush(); }, this.heartbeatMs); }
  _stopTimer() { if (this.timer) clearInterval(this.timer); this.timer = null; }

  async flush() {
    if (this.flushing || this.dead || !this.queue.length) return;
    this.flushing = true;
    this._set('saving');
    try {
      while (this.queue.length && !this.dead) {
        const batch = this.queue.slice(0, 40);
        const r = await post(`/learn/playback/${this.sessionId}/events`, { events: batch }, { headers: {} });
        this.queue.splice(0, batch.length);
        this.onProgress?.(r.data.progress, r.data.completed, r.data.results);
      }
      this._set('saved');
    } catch (e) {
      if (e.status === 410 || e.code === 'session_expired') { this.dead = true; this._stopTimer(); this.onExpired?.(); } else if (e.status === 403 || e.status === 404) { this.dead = true; this._stopTimer(); this._set('offline'); } else { this._set('offline'); setTimeout(() => this.flush(), 5000); }
    } finally { this.flushing = false; }
  }

  _set(s) { this.status = s; this.onStatus?.(s); }

  /** Final best-effort flush when leaving the page. */
  dispose() {
    if (this.playing) { try { this._emit('pause', this.getTime()); } catch { /* player already gone */ } }
    this._stopTimer();
    this.playing = false;
    this.flush();
    setTimeout(() => { this.dead = true; }, 3000);
  }
}
