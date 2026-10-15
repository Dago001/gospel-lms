import { describe, expect, it } from 'vitest';
import { bytes, clock, duration, fromMinor, money, pct, titleCase, toMinor } from './format.js';

describe('format', () => {
  it('formats kobo as naira without floating point drift', () => {
    expect(money(2500000)).toMatch(/25,000\.00/);
    expect(money(null)).toMatch(/0\.00/);
  });
  it('converts user-entered naira to integer kobo and rejects bad input', () => {
    expect(toMinor('25,000.10')).toBe(2500010);
    expect(toMinor('0.07')).toBe(7);
    expect(toMinor('-5')).toBeNull();
    expect(toMinor('abc')).toBeNull();
    expect(fromMinor(2500010)).toBe('25000.10');
  });
  it('formats durations, clocks, sizes and percentages', () => {
    expect(duration(90)).toBe('1h 30m');
    expect(duration(60)).toBe('1h');
    expect(duration(0)).toBeNull();
    expect(clock(65)).toMatch(/1:05/);
    expect(bytes(500)).toBe('500 B');
    expect(bytes(2048)).toBe('2 KB');
    expect(pct(null)).toBe('-');
    expect(pct(72.5)).toBe('72.5%');
    expect(titleCase('in_progress')).toBe('In progress');
  });
});
