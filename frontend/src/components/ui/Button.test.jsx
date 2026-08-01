import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import Button from './Button.jsx'
import DestructiveButton from './DestructiveButton.jsx'

describe('destructive buttons', () => {
  it('always has a visible, accessible text name', () => {
    render(<DestructiveButton label="Delete inventory item">Delete</DestructiveButton>)
    expect(screen.getByRole('button', { name: 'Delete inventory item' })).toHaveTextContent('Delete')
  })

  it('exposes loading state without removing its label', () => {
    render(<Button variant="danger" loading>Delete</Button>)
    expect(screen.getByRole('button', { name: 'Delete' })).toHaveAttribute('aria-busy', 'true')
    expect(screen.getByRole('button', { name: 'Delete' })).toHaveTextContent('Delete')
  })
})
