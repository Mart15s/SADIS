export default function Toolbar({
  children,
  className = '',
  label = 'Įrankių juosta',
}) {
  return (
    <div className={`toolbar ${className}`.trim()} role="toolbar" aria-label={label}>
      {children}
    </div>
  )
}
