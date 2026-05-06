export default function ResponsiveList({
  children,
  className = '',
  ariaLabel = undefined,
}) {
  return (
    <div className={`responsive-list ${className}`.trim()} aria-label={ariaLabel}>
      {children}
    </div>
  )
}
