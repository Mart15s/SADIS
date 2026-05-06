export default function FormField({
  id,
  label,
  helper = null,
  error = null,
  children,
  className = '',
  span = false,
}) {
  return (
    <div className={`field ${span ? 'field-span-2' : ''} ${error ? 'field-invalid' : ''} ${className}`.trim()}>
      {label ? <label htmlFor={id}>{label}</label> : null}
      {children}
      {helper ? <span className="field-hint">{helper}</span> : null}
      {error ? <span className="field-error">{error}</span> : null}
    </div>
  )
}
