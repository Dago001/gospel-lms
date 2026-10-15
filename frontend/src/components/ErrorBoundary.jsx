import { Component } from 'react';

/** Catches render errors so a bug in one page shows a message instead of a blank screen. The error is logged, never hidden. */
export class ErrorBoundary extends Component {
  state = { error: null };
  static getDerivedStateFromError(error) { return { error }; }
  componentDidCatch(error, info) { console.error('Unhandled UI error', error, info?.componentStack); }
  componentDidUpdate(prev) { if (this.state.error && prev.resetKey !== this.props.resetKey) this.setState({ error: null }); }
  render() {
    if (!this.state.error) return this.props.children;
    return (
      <main id="main" className="container" style={{ padding: '48px 16px' }}>
        <div className="alert alert-error" role="alert">
          <strong>Something went wrong on this page</strong>
          Try reloading. If it keeps happening, contact support and mention what you were doing.
        </div>
        <p><button type="button" className="btn btn-primary" onClick={() => window.location.reload()}>Reload the page</button></p>
      </main>
    );
  }
}
