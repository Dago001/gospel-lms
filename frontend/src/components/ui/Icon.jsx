const P = {
  menu: 'M4 6h16M4 12h16M4 18h16', x: 'M6 6l12 12M18 6L6 18', bell: 'M6 8a6 6 0 0112 0c0 7 3 8 3 8H3s3-1 3-8M10 20a2 2 0 004 0',
  user: 'M12 12a4 4 0 100-8 4 4 0 000 8zM4 21a8 8 0 0116 0', book: 'M4 5a2 2 0 012-2h13v16H6a2 2 0 00-2 2V5zM4 19a2 2 0 012-2h13',
  home: 'M3 11l9-8 9 8M5 10v10h14V10', chart: 'M4 20V10M10 20V4M16 20v-7M22 20H2', users: 'M9 11a4 4 0 100-8 4 4 0 000 8zM2 21a7 7 0 0114 0M17 3.5a4 4 0 010 7M22 21a7 7 0 00-4-6.3',
  shield: 'M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6l8-3z', card: 'M3 6h18v12H3zM3 10h18', award: 'M12 15a6 6 0 100-12 6 6 0 000 12zM8.5 14L7 21l5-3 5 3-1.5-7',
  calendar: 'M4 5h16v16H4zM4 10h16M8 3v4M16 3v4', help: 'M12 21a9 9 0 100-18 9 9 0 000 18zM9.5 9.5a2.5 2.5 0 114 2c-.8.6-1.5 1-1.5 2M12 17h.01', logout: 'M9 4H5v16h4M16 8l4 4-4 4M20 12H9',
  search: 'M11 18a7 7 0 100-14 7 7 0 000 14zM21 21l-5-5', check: 'M5 12l5 5L20 7', lock: 'M6 11h12v10H6zM8 11V7a4 4 0 018 0v4', play: 'M7 4l13 8-13 8z',
  file: 'M6 3h9l4 4v14H6zM14 3v5h5', settings: 'M12 15a3 3 0 100-6 3 3 0 000 6zM19 12a7 7 0 00-.1-1.2l2-1.5-2-3.4-2.3.9a7 7 0 00-2-1.2L14 3h-4l-.6 2.6a7 7 0 00-2 1.2l-2.3-.9-2 3.4 2 1.5A7 7 0 005 12c0 .4 0 .8.1 1.2l-2 1.5 2 3.4 2.3-.9c.6.5 1.3.9 2 1.2L10 21h4l.6-2.6c.7-.3 1.4-.7 2-1.2l2.3.9 2-3.4-2-1.5c.1-.4.1-.8.1-1.2z',
  alert: 'M12 3l10 18H2L12 3zM12 10v5M12 18h.01', plus: 'M12 5v14M5 12h14', chevron: 'M9 6l6 6-6 6', chevdown: 'M6 9l6 6 6-6', edit: 'M4 20h4L19 9l-4-4L4 16v4z', trash: 'M5 7h14M10 7V4h4v3M7 7l1 13h8l1-13',
  video: 'M3 6h13v12H3zM16 10l5-3v10l-5-3', clock: 'M12 21a9 9 0 100-18 9 9 0 000 18zM12 7v5l3 2', upload: 'M12 16V4M7 9l5-5 5 5M4 20h16', download: 'M12 4v12M7 11l5 5 5-5M4 20h16',
  message: 'M4 5h16v11H9l-5 4V5z', clipboard: 'M9 4h6v3H9zM6 5h12v16H6zM9 12h6M9 16h6', gift: 'M3 9h18v4H3zM5 13v8h14v-8M12 9v12M12 9c-3 0-4-4-1-4 2 0 1 4 1 4zm0 0c3 0 4-4 1-4-2 0-1 4-1 4z',
};
export function Icon({ name, size = 20, label, ...rest }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"
      role={label ? 'img' : undefined} aria-label={label} aria-hidden={label ? undefined : true} focusable="false" {...rest}>
      <path d={P[name] || P.help} />
    </svg>
  );
}
