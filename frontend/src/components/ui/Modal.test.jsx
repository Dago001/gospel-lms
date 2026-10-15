import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { Modal } from './Modal.jsx';

// jsdom performs no layout, so offsetParent is always null; emulate a rendered tree for the focus-trap visibility check.
Object.defineProperty(HTMLElement.prototype, 'offsetParent', { configurable: true, get() { return this.parentNode; } });

describe('Modal', () => {
  it('is a labelled dialog, focuses its first control, closes on Escape and traps Tab', async () => {
    const onClose = vi.fn();
    const user = userEvent.setup();
    render(<Modal title="Confirm refund" onClose={onClose} footer={<button>Confirm</button>}><input aria-label="Reason" /></Modal>);
    expect(screen.getByRole('dialog', { name: 'Confirm refund' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Close dialog' })).toHaveFocus();
    await user.tab(); await user.tab(); await user.tab();
    expect(screen.getByRole('dialog').contains(document.activeElement)).toBe(true);
    await user.keyboard('{Escape}');
    expect(onClose).toHaveBeenCalled();
  });
  it('cannot be dismissed when dismissible is false', async () => {
    const onClose = vi.fn();
    render(<Modal title="Busy" onClose={onClose} dismissible={false}>x</Modal>);
    await userEvent.setup().keyboard('{Escape}');
    expect(onClose).not.toHaveBeenCalled();
    expect(screen.queryByRole('button', { name: 'Close dialog' })).toBeNull();
  });
});
