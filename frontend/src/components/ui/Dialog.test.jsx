import { useState } from 'react'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { Dialog } from './Dialog.jsx'

function ComposerDialog() {
  const [name, setName] = useState('')
  const [text, setText] = useState('')

  return (
    <Dialog open onClose={() => {}} labelledBy="composer-title">
      <h2 id="composer-title">Composer</h2>
      <label htmlFor="composer-name">Name</label>
      <input id="composer-name" value={name} onChange={(event) => setName(event.target.value)} />
      <label htmlFor="composer-text">Text</label>
      <textarea id="composer-text" value={text} onChange={(event) => setText(event.target.value)} />
    </Dialog>
  )
}

describe('Dialog', () => {
  it('keeps textarea focus when controlled composer state changes', async () => {
    const user = userEvent.setup()

    render(<ComposerDialog />)

    const textarea = screen.getByLabelText('Text')
    await user.click(textarea)
    await user.type(textarea, 'Sakinys po sakinio.')

    expect(textarea).toHaveValue('Sakinys po sakinio.')
    expect(textarea).toHaveFocus()
  })
})
