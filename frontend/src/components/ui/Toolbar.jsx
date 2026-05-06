export default function Toolbar({
  children,
  className = '',
  label = 'Toolbar',
}) {
  return (
    <div className={`toolbar ${className}`.trim()} role="toolbar" aria-label={label}>
      {children}
    </div>
  )
}
