import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { SuccessToast, Toast } from './StatusView.jsx'

describe('Toast', () => {
  it('auto-dismisses success notifications', () => {
    vi.useFakeTimers()
    const onDismiss = vi.fn()

    render(<SuccessToast message="Analysis generated successfully." onDismiss={onDismiss} />)

    expect(screen.getByText('Analysis generated successfully.')).toBeInTheDocument()

    vi.advanceTimersByTime(3499)
    expect(onDismiss).not.toHaveBeenCalled()

    vi.advanceTimersByTime(1)
    expect(onDismiss).toHaveBeenCalledTimes(1)

    vi.useRealTimers()
  })

  it('keeps errors dismissible while allowing a longer timeout', () => {
    vi.useFakeTimers()
    const onDismiss = vi.fn()

    render(<Toast type="error" message="Save failed." onDismiss={onDismiss} />)

    expect(screen.getByLabelText('Dismiss notification')).toBeInTheDocument()
    vi.advanceTimersByTime(9000)
    expect(onDismiss).toHaveBeenCalledTimes(1)

    vi.useRealTimers()
  })
})
