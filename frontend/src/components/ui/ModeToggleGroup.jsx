import Button from './Button.jsx'

export default function ModeToggleGroup({
  options,
  value,
  onChange,
  className = '',
  ariaLabel = 'Mode selection',
}) {
  return (
    <div className={`mode-toggle-group ${className}`.trim()} role="group" aria-label={ariaLabel}>
      {options.map((option) => (
        <Button
          key={option.value}
          variant="toggle"
          active={value === option.value}
          onClick={() => onChange(option.value)}
          disabled={option.disabled}
        >
          {option.label}
        </Button>
      ))}
    </div>
  )
}
