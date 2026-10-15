# Accessibility (target: WCAG 2.2 AA)

## Built in
Skip link; landmarks (`header`, `nav`, `main`, `footer`); one `h1` per page; every form control has a visible label, required state, help text and error text linked by `aria-describedby`/`aria-invalid`; errors announced in `role="alert"` regions; visible focus ring on every interactive element; 44px-high controls; focus-trapping, Esc-closable modal that returns focus; keyboard-operable tabs and menus; status communicated by text and icon, never by colour alone; data tables with column headers; exam answer autosave status in an `aria-live="polite"` region; `prefers-reduced-motion` honoured for loading animations; responsive from 320px (no horizontal scroll at 390/768/1366/1920 is an E2E test).

## Automatically verified
`frontend/e2e/a11y.spec.js` runs axe-core with the `wcag2a, wcag2aa, wcag21a, wcag21aa, wcag22aa` rule tags on public pages (home, catalogue, course, login, register, contact, FAQ, pricing, verify) and the main student, lecturer and admin pages. Result at the time of writing: no violations. `src/components/ui/Modal.test.jsx` checks dialog semantics, focus and Esc.

Automated tools find only a fraction of real problems. **Not yet done** (and not covered by the claims above, which describe design intent verified only by axe and the E2E tests): a manual keyboard-only walkthrough of every flow, screen-reader testing (NVDA/JAWS with Firefox/Chrome, VoiceOver with Safari), 200–400% zoom and text-spacing checks, captions/transcripts for real course videos (lecturers must supply captions; the video upload UI should prompt for them), and review of the PDF certificate for tagged-PDF accessibility.
