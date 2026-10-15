import { describe, expect, it } from 'vitest';
import { safeNext } from './Guards.jsx';

describe('safeNext (open-redirect protection)', () => {
  it.each([['/student/courses', '/student/courses'], ['/a?b=1', '/a?b=1']])('keeps in-app path %s', (i, o) => expect(safeNext(i)).toBe(o));
  it.each(['//evil.example/x', '/\\evil.example', 'https://evil.example', 'javascript:alert(1)', '', null, undefined])('rejects %s', (i) => expect(safeNext(i)).toBeNull());
});
