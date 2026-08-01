import { Component } from 'react'
import Button from '../ui/Button.jsx'

export default class ErrorBoundary extends Component {
  constructor(props) {
    super(props)
    this.state = { error: null }
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
          <Button onClick={() => this.setState({ error: null })}>Try again</Button>
        </main>
      )
    }

    return this.props.children
  }
}
