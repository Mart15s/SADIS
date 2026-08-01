import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { expect, test, vi } from 'vitest'
import PlotWorkspaceModeSwitch from './PlotWorkspaceModeSwitch.jsx'

function renderSwitch(props = {}) {
  const onChange = props.onChange ?? vi.fn()
  render(<PlotWorkspaceModeSwitch value="view" onChange={onChange} {...props} />)
  return { onChange }
}

test('renders all plot workspace modes with tabs semantics', () => {
  renderSwitch()

  expect(screen.getAllByRole('tab')).toHaveLength(3)
  expect(screen.getByRole('tab', { name: 'View' })).toHaveAttribute('aria-selected', 'true')
  expect(screen.getByRole('tab', { name: 'Zone view' })).toBeInTheDocument()
})

test('calls the existing mode transition with each selected mode', async () => {
  const user = userEvent.setup()
  const { onChange } = renderSwitch()

  await user.click(screen.getByRole('tab', { name: 'Edit' }))
  await user.click(screen.getByRole('tab', { name: 'Zone view' }))
  await user.click(screen.getByRole('tab', { name: 'View' }))

  expect(onChange).toHaveBeenNthCalledWith(1, 'edit')
  expect(onChange).toHaveBeenNthCalledWith(2, 'zones')
  expect(onChange).toHaveBeenNthCalledWith(3, 'view')
})

test('supports arrow-key navigation and selection', async () => {
  const user = userEvent.setup()
  const { onChange } = renderSwitch()
  const view = screen.getByRole('tab', { name: 'View' })

  view.focus()
  await user.keyboard('{ArrowRight}')

  expect(screen.getByRole('tab', { name: 'Edit' })).toHaveFocus()
  expect(onChange).toHaveBeenCalledWith('edit')
})

test('keeps the edit mode visible but unavailable to view-only users', () => {
  renderSwitch({ canEdit: false })

  expect(screen.getByRole('tab', { name: 'Edit' })).toBeDisabled()
})
