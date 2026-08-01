import { useMemo } from 'react'
import { normalizeZoneColor, suggestZoneColor, ZONE_PALETTE } from '../../lib/plotPlan.js'

export default function ZoneColorControl({ value, onChange, usedColors = [], disabled = false }) {
  const normalized = normalizeZoneColor(value)
  const suggestion = useMemo(() => suggestZoneColor(usedColors), [usedColors])
  const error = value && !normalized ? 'Enter a six-digit HEX color, for example #4F7A5A.' : ''

  return (
    <div className="zone-color-control">
      <div className="zone-color-preview-row">
        <span className="zone-color-preview" style={{ background: normalized ?? suggestion }} aria-hidden="true" />
        <div>
          <strong>{normalized ?? suggestion}</strong>
          <small>Opaque zone color</small>
        </div>
      </div>
      <div className="zone-color-palette" role="group" aria-label="Professional zone color palette">
        {ZONE_PALETTE.map((color) => (
          <button
            key={color}
            type="button"
            className={normalized === color ? 'is-selected' : ''}
            style={{ background: color }}
            onClick={() => onChange(color)}
            aria-label={`Choose color ${color}`}
            aria-pressed={normalized === color}
            disabled={disabled}
          />
        ))}
      </div>
      <div className="zone-color-inputs">
        <label>
          <span>Color picker</span>
          <input type="color" value={normalized ?? suggestion} onChange={(event) => onChange(event.target.value.toUpperCase())} disabled={disabled} />
        </label>
        <label>
          <span>HEX value</span>
          <input
            type="text"
            value={value ?? ''}
            onChange={(event) => onChange(event.target.value.toUpperCase())}
            placeholder={suggestion}
            maxLength="7"
            pattern="#[0-9A-Fa-f]{6}"
            aria-invalid={Boolean(error)}
            aria-describedby={error ? 'zone-color-error' : undefined}
            disabled={disabled}
          />
        </label>
      </div>
      {error ? <span id="zone-color-error" className="field-error">{error}</span> : null}
      {!value ? (
        <button type="button" className="zone-color-suggestion" onClick={() => onChange(suggestion)} disabled={disabled}>
          Use suggested color {suggestion}
        </button>
      ) : null}
    </div>
  )
}
