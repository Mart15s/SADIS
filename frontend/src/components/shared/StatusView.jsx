import Button from '../ui/Button.jsx'
import EmptyStatePanel from '../ui/EmptyStatePanel.jsx'

function SparkIcon() {
  return (
    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round">
      <path d="M10 2.8 11.7 7l4.5 1.1-3.2 2.9.9 4.4L10 13.2l-3.9 2.2.9-4.4L3.8 8.1 8.3 7z" />
    </svg>
  )
}

function SearchIcon() {
  return (
    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round">
      <circle cx="8.5" cy="8.5" r="5" />
      <path d="M17 17l-3.5-3.5" />
    </svg>
  )
}

function AlertIcon() {
  return (
    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round">
      <path d="M10 3L18 17H2L10 3z" />
      <path d="M10 9v4" />
      <circle cx="10" cy="15" r="0.5" fill="currentColor" />
    </svg>
  )
}

function CheckIcon() {
  return (
    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round">
      <path d="M4.5 10.5 8 14l7.5-8" />
    </svg>
  )
}

export function LoadingState({
  title = 'Loading data...',
  description = 'Preparing the latest workspace data.',
  layout = 'cards',
}) {
  return (
    <section className="status-screen">
      <div className="status-hero status-hero-loading">
        <span className="status-hero-icon">
          <SparkIcon />
        </span>
        <div className="status-hero-copy">
          <strong>{title}</strong>
          <p>{description}</p>
        </div>
      </div>

      <div className={`skeleton-grid skeleton-grid-${layout}`}>
        {[1, 2, 3].map((index) => (
          <article key={index} className="skeleton-card status-skeleton-card">
            <div className="skeleton skeleton-icon" />
            <div className="skeleton skeleton-line" style={{ width: `${68 + (index * 6)}%` }} />
            <div className="skeleton skeleton-line-sm" />
            <div className="skeleton skeleton-line-sm" style={{ width: `${42 + (index * 8)}%` }} />
          </article>
        ))}
      </div>
    </section>
  )
}

export function ErrorState({
  error,
  title = 'Something went wrong',
  description,
  onRetry,
}) {
  return (
    <section className="status-screen">
      <div className="status-card status-card-error">
        <span className="status-card-icon">
          <AlertIcon />
        </span>
        <div className="status-card-copy">
          <strong>{title}</strong>
          <p>{description ?? error?.message ?? 'Unexpected error.'}</p>
        </div>
        {onRetry ? (
          <div className="status-card-actions">
            <Button variant="secondary" onClick={onRetry}>
              Retry
            </Button>
          </div>
        ) : null}
      </div>
    </section>
  )
}

export function EmptyState({
  title,
  description,
  action,
  icon = 'search',
}) {
  const Icon = icon === 'success' ? CheckIcon : SearchIcon

  return (
    <EmptyStatePanel
      title={title}
      description={description}
      action={action}
      className="empty-state empty-state-polished"
    >
      <span className="empty-state-icon" aria-hidden="true">
        <Icon />
      </span>
    </EmptyStatePanel>
  )
}

export function ProcessingState({
  title,
  description,
  steps = [],
  tone = 'brand',
  compact = false,
}) {
  return (
    <section className={`processing-state processing-state-${tone} ${compact ? 'processing-state-compact' : ''}`.trim()}>
      <div className="processing-state-copy">
        <strong>{title}</strong>
        {description ? <p>{description}</p> : null}
      </div>
      {steps.length > 0 ? (
        <div className="processing-steps" aria-label="Processing steps">
          {steps.map((step) => (
            <span key={step} className="processing-step">
              <span className="processing-step-dot" />
              {step}
            </span>
          ))}
        </div>
      ) : null}
    </section>
  )
}

export function SuccessToast({ message, onDismiss }) {
  if (!message) {
    return null
  }

  return (
    <div className="toast toast-success" role="status" aria-live="polite">
      <span className="toast-icon">
        <CheckIcon />
      </span>
      <span className="toast-message">{message}</span>
      {onDismiss ? (
        <button type="button" className="toast-dismiss" onClick={onDismiss} aria-label="Dismiss notification">
          x
        </button>
      ) : null}
    </div>
  )
}
