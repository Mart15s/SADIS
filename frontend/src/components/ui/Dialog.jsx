import { useEffect, useId, useRef } from 'react'
import { createPortal } from 'react-dom'

const focusableSelector = [
  'a[href]',
  'button:not([disabled])',
  'textarea:not([disabled])',
  'input:not([disabled])',
  'select:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
].join(',')

function getFocusableElements(container) {
  if (!container) return []

  return Array.from(container.querySelectorAll(focusableSelector))
    .filter((element) => !element.hasAttribute('disabled') && !element.getAttribute('aria-hidden'))
}

function useModalBehavior(open, panelRef, onClose) {
  useEffect(() => {
    if (!open) return undefined

    const previousActiveElement = document.activeElement
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'

    const focusTimer = window.setTimeout(() => {
      const focusableElements = getFocusableElements(panelRef.current)
      const firstFocusable = focusableElements[0]
      ;(firstFocusable ?? panelRef.current)?.focus()
    }, 0)

    function handleKeyDown(event) {
      if (event.key === 'Escape') {
        event.preventDefault()
        onClose?.()
        return
      }

      if (event.key !== 'Tab' || !panelRef.current) {
        return
      }

      const focusableElements = getFocusableElements(panelRef.current)

      if (focusableElements.length === 0) {
        event.preventDefault()
        panelRef.current.focus()
        return
      }

      const firstFocusable = focusableElements[0]
      const lastFocusable = focusableElements[focusableElements.length - 1]

      if (event.shiftKey && document.activeElement === firstFocusable) {
        event.preventDefault()
        lastFocusable.focus()
      } else if (!event.shiftKey && document.activeElement === lastFocusable) {
        event.preventDefault()
        firstFocusable.focus()
      }
    }

    document.addEventListener('keydown', handleKeyDown)

    return () => {
      window.clearTimeout(focusTimer)
      document.removeEventListener('keydown', handleKeyDown)
      document.body.style.overflow = previousOverflow

      if (previousActiveElement && typeof previousActiveElement.focus === 'function') {
        previousActiveElement.focus()
      }
    }
  }, [onClose, open, panelRef])
}

function ModalFrame({
  children,
  className = '',
  closeOnOverlayClick = true,
  labelledBy,
  describedBy,
  onClose,
  open,
  placement = 'center',
  size = 'md',
}) {
  const panelRef = useRef(null)
  useModalBehavior(open, panelRef, onClose)

  if (!open) return null

  function handleOverlayMouseDown(event) {
    if (closeOnOverlayClick && event.target === event.currentTarget) {
      onClose?.()
    }
  }

  const frame = (
    <div
      className={`modal-layer modal-layer-${placement}`.trim()}
      onMouseDown={handleOverlayMouseDown}
    >
      <div
        ref={panelRef}
        className={`modal-surface modal-surface-${placement} modal-surface-${size} ${className}`.trim()}
        role="dialog"
        aria-modal="true"
        aria-labelledby={labelledBy}
        aria-describedby={describedBy}
        tabIndex={-1}
      >
        {children}
      </div>
    </div>
  )

  return typeof document === 'undefined' ? frame : createPortal(frame, document.body)
}

export function Dialog(props) {
  return <ModalFrame {...props} placement="center" />
}

export function Drawer({ side = 'right', ...props }) {
  return <ModalFrame {...props} placement={`drawer-${side}`} />
}

export function DialogHeader({
  title,
  subtitle = null,
  meta = null,
  onClose,
  titleId,
  subtitleId,
  closeLabel = 'Close',
  className = '',
}) {
  const fallbackTitleId = useId()
  const resolvedTitleId = titleId ?? fallbackTitleId

  return (
    <header className={`dialog-header ${className}`.trim()}>
      <div className="dialog-heading">
        <div className="dialog-title-row">
          <h2 className="dialog-title" id={resolvedTitleId}>{title}</h2>
          {meta ? <div className="dialog-meta">{meta}</div> : null}
        </div>
        {subtitle ? <p className="dialog-subtitle" id={subtitleId}>{subtitle}</p> : null}
      </div>
      {onClose ? <CloseButton onClick={onClose} ariaLabel={closeLabel} /> : null}
    </header>
  )
}

export function DialogBody({ children, className = '' }) {
  return (
    <div className={`dialog-body ${className}`.trim()}>
      {children}
    </div>
  )
}

export function DialogFooter({ children, className = '' }) {
  return (
    <footer className={`dialog-footer ${className}`.trim()}>
      {children}
    </footer>
  )
}

export function CloseButton({ onClick, ariaLabel = 'Close', className = '' }) {
  return (
    <button
      type="button"
      className={`dialog-close-button ${className}`.trim()}
      onClick={onClick}
      aria-label={ariaLabel}
    >
      <span aria-hidden="true">x</span>
    </button>
  )
}
