import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import ZoneColorControl from './ZoneColorControl.jsx'

describe('ZoneColorControl', () => {
  it('renders the curated palette and accepts keyboard-friendly custom colours', () => {
    const onChange = vi.fn()
    const { rerender } = render(<ZoneColorControl value="#4F7A5A" onChange={onChange} />)

    expect(screen.getByRole('group', { name: 'Professional zone color palette' }).querySelectorAll('button')).toHaveLength(8)
    fireEvent.click(screen.getByRole('button', { name: 'Choose color #A06B3B' }))
    expect(onChange).toHaveBeenCalledWith('#A06B3B')

    rerender(<ZoneColorControl value="#FFF" onChange={onChange} />)
    expect(screen.getByText(/six-digit HEX color/)).toBeInTheDocument()
    expect(screen.getByRole('textbox')).toHaveAttribute('aria-invalid', 'true')
  })
})
