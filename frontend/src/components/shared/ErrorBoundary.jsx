import { Component } from 'react'
import Button from '../ui/Button.jsx'

export default class ErrorBoundary extends Component {
  constructor(props) {
    super(props)
    this.state = { error: null, retryKey: 0 }
  }

  static getDerivedStateFromError(error) {
    return { error }
  }

  componentDidCatch(error, info) {
    console.error('Unhandled application error', error, info)
  }

  render() {
    if (this.state.error) {
      return (
        <main className="error-boundary-panel" role="alert">
          <p className="eyebrow">Yava</p>
          <h1>Something went wrong</h1>
          <p>Yava could not display this screen. Your saved data is safe.</p>
          <div className="form-actions">
            <Button onClick={() => this.setState((state) => ({ error: null, retryKey: state.retryKey + 1 }))}>Try again</Button>
            <a className="button button-secondary button-md" href="/">Return to overview</a>
          </div>
        </main>
      )
    }

    return <div key={this.state.retryKey} className="error-boundary-content">{this.props.children}</div>
  }
}
