import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { ApiError, get, post } from '../api/client.js';
import { Icon } from './ui/Icon.jsx';

const MAX = 300;
const STORE = 'gospel-chat-v1';
const HIDDEN_ON = [/^\/student\/attempts\//, /^\/checkout\//];
// Only in-app paths are ever rendered as links, whatever the server sends.
const safeUrl = (u) => typeof u === 'string' && /^\/(?!\/)[A-Za-z0-9/_\-?=&%.]*$/.test(u);

function load() {
  try { const v = JSON.parse(sessionStorage.getItem(STORE) || '[]'); return Array.isArray(v) ? v.slice(-40) : []; } catch { return []; }
}
function save(m) { try { sessionStorage.setItem(STORE, JSON.stringify(m.slice(-40))); } catch { /* storage unavailable: keep in memory only */ } }

let seq = 0;
const mk = (from, data) => ({ id: `${Date.now()}-${seq++}`, from, ...data });

function Bot({ m, onPick, onNavigate, last }) {
  return (
    <div className="chat-row bot">
      <div className="chat-bubble">
        <p>{m.reply}</p>
        {m.cards?.length > 0 && (
          <ul className="chat-cards list-plain">
            {m.cards.filter((c) => safeUrl(c.url)).map((c) => (
              <li key={c.url}><Link to={c.url} onClick={onNavigate}><strong>{c.title}</strong>{c.subtitle && <span>{c.subtitle}</span>}<Icon name="chevron-right" size={16} /></Link></li>
            ))}
          </ul>
        )}
        {m.links?.length > 0 && (
          <div className="chat-links">{m.links.filter((l) => safeUrl(l.url)).map((l) => <Link key={l.url + l.label} to={l.url} onClick={onNavigate}>{l.label}</Link>)}</div>
        )}
      </div>
      {last && m.suggestions?.length > 0 && (
        <div className="chat-chips" aria-label="Suggested questions">{m.suggestions.map((s) => <button key={s} type="button" className="chip-btn" onClick={() => onPick(s)}>{s}</button>)}</div>
      )}
    </div>
  );
}

export function ChatWidget() {
  const loc = useLocation();
  const [open, setOpen] = useState(false);
  const [msgs, setMsgs] = useState(load);
  const [text, setText] = useState('');
  const [busy, setBusy] = useState(false);
  const logRef = useRef(null);
  const inputRef = useRef(null);
  const launcherRef = useRef(null);
  const hidden = HIDDEN_ON.some((r) => r.test(loc.pathname));

  const push = useCallback((m) => setMsgs((cur) => { const n = [...cur, m].slice(-40); save(n); return n; }), []);

  const greet = useCallback(async () => {
    try { const r = await get('/chat/greeting'); push(mk('bot', r.data)); } catch { push(mk('bot', { reply: 'Hello. The assistant is unavailable right now, but you can reach the academy through the contact page.', links: [{ label: 'Contact us', url: '/contact' }], suggestions: [], cards: [] })); }
  }, [push]);

  useEffect(() => { if (open && msgs.length === 0) greet(); }, [open]); // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { if (open) { inputRef.current?.focus(); logRef.current?.scrollTo({ top: logRef.current.scrollHeight }); } }, [open, msgs, busy]);
  const wasOpen = useRef(false);
  useEffect(() => { if (wasOpen.current && !open) launcherRef.current?.focus(); wasOpen.current = open; }, [open]);
  useEffect(() => {
    if (!open) return undefined;
    const onKey = (e) => { if (e.key === 'Escape') setOpen(false); };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [open]);

  const send = useCallback(async (raw) => {
    const message = raw.trim().slice(0, MAX);
    if (!message || busy) return;
    push(mk('user', { reply: message }));
    setText(''); setBusy(true);
    const started = Date.now();
    try {
      const r = await post('/chat/message', { message });
      await new Promise((res) => setTimeout(res, Math.max(0, 450 - (Date.now() - started))));
      push(mk('bot', r.data));
    } catch (e) {
      const limited = e instanceof ApiError && e.status === 429;
      push(mk('bot', { reply: limited ? 'You are sending messages very quickly. Please wait a minute and try again.' : e instanceof ApiError && e.status === 422 ? 'Please keep your message short (up to 300 characters) and try again.' : 'I could not reach the server. Please check your connection and try again.', cards: [], links: [], suggestions: [] }));
    } finally { setBusy(false); }
  }, [busy, push]);

  const reset = () => { setMsgs([]); save([]); greet(); };
  if (hidden) return null;
  const lastBot = [...msgs].reverse().find((m) => m.from === 'bot');

  return (
    <>
      {!open && (
        <button ref={launcherRef} type="button" className="chat-launcher" aria-haspopup="dialog" aria-expanded="false" aria-controls="chat-panel" onClick={() => setOpen(true)}>
          <Icon name="message" size={22} /><span>Ask us</span>
        </button>
      )}
      {open && (
        <section id="chat-panel" className="chat-panel" role="dialog" aria-label="GOSPEL LMS help assistant">
          <header className="chat-head">
            <img src="/logo-white.png" alt="" width="554" height="446" />
            <div><strong>GOSPEL assistant</strong><span>Automated help · answers in seconds</span></div>
            <button type="button" className="chat-x" onClick={reset} aria-label="Start a new conversation" title="New conversation"><Icon name="plus" size={18} /></button>
            <button type="button" className="chat-x" onClick={() => setOpen(false)} aria-label="Close assistant"><Icon name="x" size={20} /></button>
          </header>
          <div className="chat-log" ref={logRef} role="log" aria-live="polite" aria-relevant="additions">
            {msgs.map((m) => m.from === 'user'
              ? <div key={m.id} className="chat-row user"><div className="chat-bubble"><p>{m.reply}</p></div></div>
              : <Bot key={m.id} m={m} last={m === lastBot && !busy} onPick={send} onNavigate={() => setOpen(window.innerWidth > 560)} />)}
            {busy && <div className="chat-row bot"><div className="chat-bubble typing" role="status" aria-label="The assistant is typing"><i /><i /><i /></div></div>}
          </div>
          <form className="chat-form" onSubmit={(e) => { e.preventDefault(); send(text); }}>
            <label htmlFor="chat-input" className="sr-only">Your question</label>
            <input id="chat-input" ref={inputRef} type="text" value={text} maxLength={MAX} autoComplete="off" enterKeyHint="send" placeholder="Type your question…" onChange={(e) => setText(e.target.value)} />
            <button type="submit" className="chat-send" disabled={busy || !text.trim()} aria-label="Send message"><Icon name="chevron-right" size={20} /></button>
            {text.length > 240 && <span className="chat-count" aria-live="off">{MAX - text.length} left</span>}
          </form>
          <p className="chat-note">Automated assistant: it answers from the academy's published information and can make mistakes. Never type passwords or card details. For account or payment problems, <Link to="/contact" onClick={() => setOpen(window.innerWidth > 560)}>contact the team</Link>.</p>
        </section>
      )}
    </>
  );
}
