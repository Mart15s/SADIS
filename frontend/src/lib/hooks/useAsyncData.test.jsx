import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { useState } from 'react'
import { describe, expect, it } from 'vitest'
import { useAsyncData } from './useAsyncData.js'

function deferred() {
  let resolve
  const promise = new Promise((next) => {
    resolve = next
  })
  return { promise, resolve }
}

function Probe({ loaders }) {
  const [scope, setScope] = useState('farm-a')
  const state = useAsyncData(() => loaders[scope].promise, [scope], [])

  return (
    <div>
      <button type="button" onClick={() => setScope('farm-b')}>
        Switch
      </button>
      <span data-testid="loading">{state.loading ? 'loading' : 'ready'}</span>
      <span data-testid="rows">{state.data.join(',')}</span>
    </div>
  )
}

describe('useAsyncData request isolation', () => {
  it('clears stale scope data while loading the replacement scope', async () => {
    const loaders = { 'farm-a': deferred(), 'farm-b': deferred() }
    render(<Probe loaders={loaders} />)

    loaders['farm-a'].resolve(['Field A'])
    await waitFor(() => expect(screen.getByTestId('rows')).toHaveTextContent('Field A'))

    fireEvent.click(screen.getByRole('button', { name: 'Switch' }))
    await waitFor(() => expect(screen.getByTestId('loading')).toHaveTextContent('loading'))
    expect(screen.getByTestId('rows')).toBeEmptyDOMElement()

    loaders['farm-b'].resolve(['Field B'])
    await waitFor(() => expect(screen.getByTestId('rows')).toHaveTextContent('Field B'))
    expect(screen.getByTestId('rows')).not.toHaveTextContent('Field A')
  })

  it('ignores a superseded response that resolves after the active scope', async () => {
    const loaders = { 'farm-a': deferred(), 'farm-b': deferred() }
    render(<Probe loaders={loaders} />)

    fireEvent.click(screen.getByRole('button', { name: 'Switch' }))
    loaders['farm-b'].resolve(['Field B'])
    await waitFor(() => expect(screen.getByTestId('rows')).toHaveTextContent('Field B'))

    loaders['farm-a'].resolve(['Late field A'])
    await waitFor(() => expect(screen.getByTestId('loading')).toHaveTextContent('ready'))
    expect(screen.getByTestId('rows')).toHaveTextContent('Field B')
    expect(screen.getByTestId('rows')).not.toHaveTextContent('Late field A')
  })
})
