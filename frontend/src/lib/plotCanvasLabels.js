const LABEL_FONT_PIXELS = 14
const MIN_ZONE_LABEL_WIDTH_PIXELS = 96
const MIN_ZONE_LABEL_HEIGHT_PIXELS = 40
const MIN_ZONE_LABEL_AREA_PIXELS = 3600
const MIN_COMPACT_LABEL_WIDTH_PIXELS = 40
const MIN_COMPACT_LABEL_HEIGHT_PIXELS = 18
const MIN_COMPACT_LABEL_AREA_PIXELS = 620

function truncateLabel(text, maxLength) {
  if (!text) {
    return ''
  }

  return text.length <= maxLength
    ? text
    : `${text.slice(0, Math.max(maxLength - 3, 1)).trim()}...`
}

export function getZoneLabelConfig(zoneName, bounds, viewportScale, options = {}) {
  const widthPixels = bounds.width * viewportScale
  const heightPixels = bounds.height * viewportScale
  const areaPixels = widthPixels * heightPixels
  const allowCompact = options.allowCompact ?? true
  const compactCenter = options.compactCenter ?? true
  const labelText = zoneName?.trim() || 'Zone'

  if (
    widthPixels < MIN_ZONE_LABEL_WIDTH_PIXELS
    || heightPixels < MIN_ZONE_LABEL_HEIGHT_PIXELS
    || areaPixels < MIN_ZONE_LABEL_AREA_PIXELS
  ) {
    if (
      !allowCompact
      || widthPixels < MIN_COMPACT_LABEL_WIDTH_PIXELS
      || heightPixels < MIN_COMPACT_LABEL_HEIGHT_PIXELS
      || areaPixels < MIN_COMPACT_LABEL_AREA_PIXELS
    ) {
      return null
    }

    const maxLength = widthPixels < 86 ? 7 : widthPixels < 130 ? 10 : 14
    const compactFontPixels = Math.max(
      11,
      Math.min(14, Math.min(widthPixels * 0.12, heightPixels * 0.38, LABEL_FONT_PIXELS)),
    )
    const compactWidthPixels = Math.min(Math.max(56, widthPixels * 0.68), 150)
    const compactHeightPixels = Math.max(24, compactFontPixels * 1.65)
    const width = compactWidthPixels / viewportScale
    const height = compactHeightPixels / viewportScale

    return {
      variant: 'compact',
      text: truncateLabel(labelText, maxLength),
      fontSize: compactFontPixels / viewportScale,
      x: compactCenter
        ? bounds.centerX - (width / 2)
        : bounds.left + ((bounds.width - width) / 2),
      y: compactCenter
        ? bounds.centerY - (height / 2)
        : bounds.top + ((bounds.height - height) / 2),
      width,
      height,
      padding: Math.max(6, Math.min(10, compactWidthPixels * 0.08)) / viewportScale,
      cornerRadius: Math.max(999 / viewportScale, 0.2),
    }
  }

  const maxLength = widthPixels < 140 ? 10 : widthPixels < 220 ? 18 : 28
  const fontPixels = Math.max(
    12,
    Math.min(18, Math.min(widthPixels * 0.12, heightPixels * 0.36, LABEL_FONT_PIXELS + 3)),
  )
  const labelWidthPixels = Math.min(widthPixels * 0.84, 260)
  const labelHeightPixels = Math.max(fontPixels * 1.95, 30)

  if (labelWidthPixels < 72 || labelHeightPixels < 24) {
    return null
  }

  const width = labelWidthPixels / viewportScale
  const height = labelHeightPixels / viewportScale

  return {
    variant: 'inline',
    text: truncateLabel(labelText, maxLength),
    fontSize: fontPixels / viewportScale,
    x: bounds.centerX - (width / 2),
    y: bounds.centerY - (height / 2),
    width,
    height,
    padding: Math.max(8, Math.min(14, widthPixels * 0.05)) / viewportScale,
    cornerRadius: Math.max(10 / viewportScale, 0.2),
  }
}
