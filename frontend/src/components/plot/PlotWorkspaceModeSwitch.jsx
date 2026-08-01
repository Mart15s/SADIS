import { useRef } from 'react'

const modes = [
  { value: 'view', label: 'View' },
  { value: 'edit', label: 'Edit' },
  { value: 'zones', label: 'Zone view', shortLabel: 'Zones' },
]

export default function PlotWorkspaceModeSwitch({
  value,
  onChange,
  canEdit = true,
  className = '',
}) {
  const tabsRef = useRef([])

  function selectMode(nextMode) {
    if (nextMode.value === 'edit' && !canEdit) return
    onChange(nextMode.value)
  }

  function handleKeyDown(event, currentIndex) {
    const enabledModes = modes.filter((mode) => mode.value !== 'edit' || canEdit)
    const enabledIndex = enabledModes.findIndex((mode) => mode.value === modes[currentIndex].value)
    let nextIndex = null

    if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
      nextIndex = (enabledIndex + 1) % enabledModes.length
    } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
      nextIndex = (enabledIndex - 1 + enabledModes.length) % enabledModes.length
    } else if (event.key === 'Home') {
      nextIndex = 0
    } else if (event.key === 'End') {
      nextIndex = enabledModes.length - 1
    } else if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault()
      selectMode(modes[currentIndex])
      return
    } else {
      return
    }

    event.preventDefault()
    const nextMode = enabledModes[nextIndex]
    const domIndex = modes.findIndex((mode) => mode.value === nextMode.value)
    tabsRef.current[domIndex]?.focus()
    selectMode(nextMode)
  }

  return (
    <div
      className={`plot-workspace-mode-switch ${className}`.trim()}
      role="tablist"
      aria-label="Plot work mode"
    >
      {modes.map((mode, index) => {
        const selected = mode.value === value
        const disabled = mode.value === 'edit' && !canEdit

        return (
          <button
            key={mode.value}
            ref={(element) => { tabsRef.current[index] = element }}
            type="button"
            role="tab"
            id={`plot-workspace-mode-${mode.value}`}
            aria-selected={selected}
            aria-label={mode.label}
            tabIndex={selected ? 0 : -1}
            disabled={disabled}
            className={selected ? 'is-active' : ''}
            onClick={() => selectMode(mode)}
            onKeyDown={(event) => handleKeyDown(event, index)}
          >
            <span className="plot-workspace-mode-switch__full-label">{mode.label}</span>
            {mode.shortLabel ? <span className="plot-workspace-mode-switch__short-label" aria-hidden="true">{mode.shortLabel}</span> : null}
          </button>
        )
      })}
    </div>
  )
}
