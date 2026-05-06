export default function ActionBar({
  children,
  align = 'end',
  className = '',
}) {
  return (
    <div className={`action-bar action-bar-${align} ${className}`.trim()}>
      {children}
    </div>
  )
}
