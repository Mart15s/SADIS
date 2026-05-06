import Button from './Button.jsx'

export default function FilterBar({
  children,
  resultCount,
  resultLabel = 'results',
  onClear = null,
  clearDisabled = false,
  className = '',
}) {
  return (
    <div className={`resource-filter-bar filter-bar ${className}`.trim()}>
      {children}
      <div className="resource-filter-summary filter-bar-summary" aria-live="polite">
        {typeof resultCount === 'number' ? <span>{resultCount} {resultLabel}</span> : resultCount}
        {onClear ? (
          <Button variant="ghost" size="sm" onClick={onClear} disabled={clearDisabled}>
            Clear
          </Button>
        ) : null}
      </div>
    </div>
  )
}
