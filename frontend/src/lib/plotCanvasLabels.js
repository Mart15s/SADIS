const EPSILON = 0.001

const LABEL_MODES = {
  full: {
    minWidth: 96,
    minHeight: 24,
    minArea: 1180,
    minFontSize: 10.5,
    maxFontSize: 12.5,
    minHorizontalPadding: 8,
    maxHorizontalPadding: 12,
    minVerticalPadding: 4,
    maxVerticalPadding: 6,
    maxWidth: 156,
    maxLength: 24,
    maxWidthRatio: 0.86,
  },
  compact: {
    minWidth: 46,
    minHeight: 18,
    minArea: 300,
    minFontSize: 9.25,
    maxFontSize: 10.8,
    minHorizontalPadding: 7,
    maxHorizontalPadding: 9,
    minVerticalPadding: 3,
    maxVerticalPadding: 5,
    maxWidth: 108,
    maxLength: 14,
    maxWidthRatio: 0.8,
  },
  marker: {
    minWidth: 12,
    minHeight: 12,
    minArea: 52,
    minFontSize: 8.75,
    maxFontSize: 10.25,
    minSize: 14,
    maxSize: 19,
  },
}

const BOUNDARY_LABEL_MODES = {
  full: {
    minWidth: 124,
    minHeight: 28,
    minArea: 1600,
    minFontSize: 10.75,
    maxFontSize: 13.5,
    minHorizontalPadding: 10,
    maxHorizontalPadding: 14,
    minVerticalPadding: 4,
    maxVerticalPadding: 7,
    maxWidth: 228,
    maxLength: 30,
    maxWidthRatio: 0.9,
  },
  compact: {
    minWidth: 76,
    minHeight: 22,
    minArea: 680,
    minFontSize: 9.5,
    maxFontSize: 11.5,
    minHorizontalPadding: 8,
    maxHorizontalPadding: 11,
    minVerticalPadding: 3,
    maxVerticalPadding: 5,
    maxWidth: 156,
    maxLength: 18,
    maxWidthRatio: 0.84,
  },
  marker: {
    minWidth: 14,
    minHeight: 14,
    minArea: 24,
    minFontSize: 8.25,
    maxFontSize: 10.5,
    minSize: 16,
    maxSize: 22,
  },
}

function clamp(value, min, max) {
  return Math.min(Math.max(value, min), max)
}

function round(value, digits = 2) {
  const factor = 10 ** digits
  return Math.round(value * factor) / factor
}

function normalizePoint(point) {
  return {
    x: round(Number(point?.x) || 0, 2),
    y: round(Number(point?.y) || 0, 2),
  }
}

function dedupeSorted(values) {
  return values.reduce((result, value) => {
    if (!result.length || Math.abs(result[result.length - 1] - value) > EPSILON) {
      result.push(value)
    }

    return result
  }, [])
}

function truncateLabel(text, maxLength) {
  if (!text) {
    return ''
  }

  if (text.length <= maxLength) {
    return text
  }

  return `${text.slice(0, Math.max(maxLength - 3, 1)).trim()}...`
}

function normalizeViewportBounds(viewportBounds) {
  if (!viewportBounds) {
    return null
  }

  const left = round(Number(viewportBounds.left) || 0, 2)
  const top = round(Number(viewportBounds.top) || 0, 2)
  const width = round(
    Number(viewportBounds.width)
      || ((Number(viewportBounds.right) || 0) - left),
    2,
  )
  const height = round(
    Number(viewportBounds.height)
      || ((Number(viewportBounds.bottom) || 0) - top),
    2,
  )

  return {
    left,
    top,
    width,
    height,
    right: round(left + width, 2),
    bottom: round(top + height, 2),
  }
}

function createBadgeLabel(text) {
  if (!text) {
    return 'Z'
  }

  const words = text
    .split(/\s+/)
    .map((word) => word.trim())
    .filter(Boolean)

  if (words.length >= 2) {
    return words
      .slice(0, 2)
      .map((word) => word[0]?.toUpperCase() ?? '')
      .join('')
  }

  return text.slice(0, 3).toUpperCase()
}

function createMarkerText(text, markerText) {
  if (markerText) {
    return String(markerText).slice(0, 3)
  }

  return createBadgeLabel(text)
}

function estimateTextWidth(text, fontSize) {
  return Array.from(text).reduce((width, character) => {
    if (character === ' ') {
      return width + (fontSize * 0.34)
    }

    if ('WMQG@#'.includes(character)) {
      return width + (fontSize * 0.72)
    }

    if ('ijlI.,:|'.includes(character)) {
      return width + (fontSize * 0.3)
    }

    return width + (fontSize * 0.58)
  }, 0)
}

function polygonArea(points) {
  if (points.length < 3) {
    return 0
  }

  let sum = 0

  for (let index = 0; index < points.length; index += 1) {
    const current = points[index]
    const next = points[(index + 1) % points.length]
    sum += (current.x * next.y) - (next.x * current.y)
  }

  return Math.abs(sum) / 2
}

function getPolygonBounds(points) {
  if (!points.length) {
    return {
      left: 0,
      top: 0,
      right: 0,
      bottom: 0,
      width: 0,
      height: 0,
      centerX: 0,
      centerY: 0,
    }
  }

  const xs = points.map((point) => point.x)
  const ys = points.map((point) => point.y)
  const left = Math.min(...xs)
  const right = Math.max(...xs)
  const top = Math.min(...ys)
  const bottom = Math.max(...ys)
  const width = right - left
  const height = bottom - top

  return {
    left: round(left, 2),
    top: round(top, 2),
    right: round(right, 2),
    bottom: round(bottom, 2),
    width: round(width, 2),
    height: round(height, 2),
    centerX: round(left + (width / 2), 2),
    centerY: round(top + (height / 2), 2),
  }
}

function pointOnSegment(point, start, end) {
  const cross = ((point.y - start.y) * (end.x - start.x)) - ((point.x - start.x) * (end.y - start.y))

  if (Math.abs(cross) > 0.02) {
    return false
  }

  const dot = ((point.x - start.x) * (end.x - start.x)) + ((point.y - start.y) * (end.y - start.y))

  if (dot < -0.02) {
    return false
  }

  const squaredLength = ((end.x - start.x) ** 2) + ((end.y - start.y) ** 2)
  return dot <= squaredLength + 0.02
}

function pointInPolygon(point, points) {
  if (points.length < 3) {
    return false
  }

  for (let index = 0; index < points.length; index += 1) {
    if (pointOnSegment(point, points[index], points[(index + 1) % points.length])) {
      return true
    }
  }

  let isInside = false

  for (let index = 0, previous = points.length - 1; index < points.length; previous = index, index += 1) {
    const current = points[index]
    const prior = points[previous]
    const intersects = (
      current.y > point.y
      !== prior.y > point.y
      && point.x < (((prior.x - current.x) * (point.y - current.y)) / ((prior.y - current.y) || Number.EPSILON)) + current.x
    )

    if (intersects) {
      isInside = !isInside
    }
  }

  return isInside
}

function getPolygonCentroid(points, bounds) {
  if (points.length < 3) {
    return { x: bounds.centerX, y: bounds.centerY }
  }

  let twiceArea = 0
  let x = 0
  let y = 0

  for (let index = 0; index < points.length; index += 1) {
    const current = points[index]
    const next = points[(index + 1) % points.length]
    const cross = (current.x * next.y) - (next.x * current.y)

    twiceArea += cross
    x += (current.x + next.x) * cross
    y += (current.y + next.y) * cross
  }

  if (Math.abs(twiceArea) < EPSILON) {
    return { x: bounds.centerX, y: bounds.centerY }
  }

  return {
    x: x / (3 * twiceArea),
    y: y / (3 * twiceArea),
  }
}

function distanceToSegment(point, start, end) {
  const dx = end.x - start.x
  const dy = end.y - start.y
  const lengthSquared = (dx ** 2) + (dy ** 2)

  if (!lengthSquared) {
    return Math.hypot(point.x - start.x, point.y - start.y)
  }

  const ratio = clamp((((point.x - start.x) * dx) + ((point.y - start.y) * dy)) / lengthSquared, 0, 1)
  const projected = {
    x: start.x + (ratio * dx),
    y: start.y + (ratio * dy),
  }

  return Math.hypot(point.x - projected.x, point.y - projected.y)
}

function getAnchorClearance(point, points) {
  return points.reduce((minimum, start, index) => {
    const end = points[(index + 1) % points.length]
    return Math.min(minimum, distanceToSegment(point, start, end))
  }, Number.POSITIVE_INFINITY)
}

function getSafeAnchorPoint(points, bounds) {
  const centroid = getPolygonCentroid(points, bounds)
  const candidates = [
    centroid,
    { x: bounds.centerX, y: bounds.centerY },
  ]

  const ratios = [0.5, 0.35, 0.65, 0.2, 0.8]

  ratios.forEach((ratioY) => {
    ratios.forEach((ratioX) => {
      candidates.push({
        x: bounds.left + (bounds.width * ratioX),
        y: bounds.top + (bounds.height * ratioY),
      })
    })
  })

  const sampleColumns = Math.max(4, Math.min(10, Math.round(bounds.width / 24)))
  const sampleRows = Math.max(4, Math.min(10, Math.round(bounds.height / 20)))

  for (let row = 0; row <= sampleRows; row += 1) {
    const ratioY = row / sampleRows

    for (let column = 0; column <= sampleColumns; column += 1) {
      const ratioX = column / sampleColumns
      candidates.push({
        x: bounds.left + (bounds.width * ratioX),
        y: bounds.top + (bounds.height * ratioY),
      })
    }
  }

  const bestPoint = candidates.reduce((best, candidate) => {
    if (!pointInPolygon(candidate, points)) {
      return best
    }

    const clearance = getAnchorClearance(candidate, points)
    const distanceFromCenter = Math.hypot(candidate.x - bounds.centerX, candidate.y - bounds.centerY)
    const score = clearance - (distanceFromCenter * 0.08)

    if (!best || score > best.score) {
      return {
        point: normalizePoint(candidate),
        score,
      }
    }

    return best
  }, null)

  if (bestPoint?.point) {
    return bestPoint.point
  }

  const closestInsideCandidate = candidates
    .map((candidate) => ({
      point: candidate,
      distance: Math.hypot(candidate.x - centroid.x, candidate.y - centroid.y),
    }))
    .sort((left, right) => left.distance - right.distance)
    .find(({ point }) => pointInPolygon(point, points))

  return normalizePoint(closestInsideCandidate?.point ?? {
    x: clamp(centroid.x, bounds.left, bounds.right),
    y: clamp(centroid.y, bounds.top, bounds.bottom),
  })
}

function getLineIntersections(points, axis, value) {
  const intersections = []

  for (let index = 0; index < points.length; index += 1) {
    const start = points[index]
    const end = points[(index + 1) % points.length]

    if (axis === 'horizontal') {
      if (Math.abs(start.y - end.y) < EPSILON) {
        if (Math.abs(start.y - value) < EPSILON) {
          intersections.push(start.x, end.x)
        }
        continue
      }

      const minY = Math.min(start.y, end.y)
      const maxY = Math.max(start.y, end.y)

      if (value < minY - EPSILON || value > maxY + EPSILON) {
        continue
      }

      const ratio = (value - start.y) / (end.y - start.y)
      intersections.push(start.x + ((end.x - start.x) * ratio))
      continue
    }

    if (Math.abs(start.x - end.x) < EPSILON) {
      if (Math.abs(start.x - value) < EPSILON) {
        intersections.push(start.y, end.y)
      }
      continue
    }

    const minX = Math.min(start.x, end.x)
    const maxX = Math.max(start.x, end.x)

    if (value < minX - EPSILON || value > maxX + EPSILON) {
      continue
    }

    const ratio = (value - start.x) / (end.x - start.x)
    intersections.push(start.y + ((end.y - start.y) * ratio))
  }

  return dedupeSorted(intersections.sort((left, right) => left - right))
}

function getContainingSpan(intersections, value) {
  for (let index = 0; index < intersections.length - 1; index += 2) {
    const start = intersections[index]
    const end = intersections[index + 1]

    if (value >= start - EPSILON && value <= end + EPSILON) {
      return {
        start,
        end,
        size: end - start,
      }
    }
  }

  return null
}

function getLargestSpan(intersections) {
  let bestSpan = null

  for (let index = 0; index < intersections.length - 1; index += 2) {
    const start = intersections[index]
    const end = intersections[index + 1]
    const size = end - start

    if (!bestSpan || size > bestSpan.size) {
      bestSpan = { start, end, size }
    }
  }

  return bestSpan
}

function constrainLabelToViewport(layout, viewportBounds, margin = 10) {
  const bounds = normalizeViewportBounds(viewportBounds)

  if (!layout || !bounds || bounds.width <= 0 || bounds.height <= 0) {
    return layout
  }

  const safeLeft = bounds.left + margin
  const safeTop = bounds.top + margin
  const safeWidth = Math.max(bounds.width - (margin * 2), 24)
  const safeHeight = Math.max(bounds.height - (margin * 2), 18)
  const width = round(Math.min(layout.width, safeWidth), 2)
  const height = round(Math.min(layout.height, safeHeight), 2)
  const x = round(clamp(layout.x, safeLeft, (safeLeft + safeWidth) - width), 2)
  const y = round(clamp(layout.y, safeTop, (safeTop + safeHeight) - height), 2)
  const maxTextWidth = round(
    Math.max(
      Math.min(layout.maxTextWidth ?? width, width - ((layout.paddingX ?? 0) * 2)),
      width * 0.55,
    ),
    2,
  )

  return {
    ...layout,
    x,
    y,
    width,
    height,
    maxTextWidth,
  }
}

export function getZoneLabelMetrics(screenPoints) {
  const points = (screenPoints ?? []).map(normalizePoint).filter((point) => Number.isFinite(point.x) && Number.isFinite(point.y))
  const bounds = getPolygonBounds(points)
  const anchor = getSafeAnchorPoint(points, bounds)
  const horizontalSpan = getContainingSpan(getLineIntersections(points, 'horizontal', anchor.y), anchor.x)
  const verticalSpan = getContainingSpan(getLineIntersections(points, 'vertical', anchor.x), anchor.y)

  return {
    points,
    bounds,
    anchor,
    area: polygonArea(points),
    horizontalSpan: horizontalSpan ?? { start: bounds.left, end: bounds.right, size: bounds.width },
    verticalSpan: verticalSpan ?? { start: bounds.top, end: bounds.bottom, size: bounds.height },
  }
}

function canFitLabel(metrics, mode, isSelected, configMap = LABEL_MODES) {
  const minimums = configMap[mode]
  const thresholdMultiplier = isSelected ? 0.88 : 1

  return metrics.horizontalSpan.size >= minimums.minWidth * thresholdMultiplier
    && metrics.verticalSpan.size >= minimums.minHeight * thresholdMultiplier
    && metrics.area >= minimums.minArea * thresholdMultiplier
}

function buildLabelBox(text, mode, metrics, configMap = LABEL_MODES) {
  const config = configMap[mode]
  const safeWidth = Math.max(metrics.horizontalSpan.size - 6, 0)
  const safeHeight = Math.max(metrics.verticalSpan.size - 4, 0)
  const fontSize = clamp(
    Math.min(safeHeight * 0.46, safeWidth * (mode === 'full' ? 0.16 : 0.19)),
    config.minFontSize,
    config.maxFontSize,
  )
  const horizontalPadding = clamp(
    fontSize * (mode === 'full' ? 0.84 : 0.72),
    config.minHorizontalPadding,
    config.maxHorizontalPadding,
  )
  const verticalPadding = clamp(
    fontSize * (mode === 'full' ? 0.45 : 0.34),
    config.minVerticalPadding,
    config.maxVerticalPadding,
  )
  const maxLabelWidth = Math.min(config.maxWidth, safeWidth * config.maxWidthRatio)
  const minLabelWidth = Math.min(config.minWidth, maxLabelWidth)
  const maxTextWidth = Math.max(maxLabelWidth - (horizontalPadding * 2), fontSize)
  const estimatedWidth = estimateTextWidth(text, fontSize) + (horizontalPadding * 2)
  const width = clamp(
    estimatedWidth,
    minLabelWidth,
    Math.max(maxLabelWidth, minLabelWidth),
  )
  const minLabelHeight = Math.min(config.minHeight, safeHeight)
  const height = clamp(
    fontSize + (verticalPadding * 2),
    minLabelHeight,
    Math.max(safeHeight, minLabelHeight),
  )
  const x = clamp(
    metrics.anchor.x - (width / 2),
    metrics.horizontalSpan.start,
    metrics.horizontalSpan.end - width,
  )
  const y = clamp(
    metrics.anchor.y - (height / 2),
    metrics.verticalSpan.start,
    metrics.verticalSpan.end - height,
  )

  return {
    mode,
    text,
    fontSize: round(fontSize, 2),
    x: round(x, 2),
    y: round(y, 2),
    width: round(width, 2),
    height: round(height, 2),
    paddingX: round(horizontalPadding, 2),
    paddingY: round(verticalPadding, 2),
    cornerRadius: round(Math.min(height / 2, mode === 'full' ? 999 : 14), 2),
    anchorX: round(metrics.anchor.x, 2),
    anchorY: round(metrics.anchor.y, 2),
    maxTextWidth: round(maxTextWidth, 2),
  }
}

function canFitMarker(metrics, context, configMap = LABEL_MODES) {
  const config = configMap.marker
  const contextMultiplier = context === 'editor' ? 0.9 : 1

  return metrics.horizontalSpan.size >= config.minWidth * contextMultiplier
    && metrics.verticalSpan.size >= config.minHeight * contextMultiplier
    && metrics.area >= config.minArea * contextMultiplier
}

function buildMarkerBox(text, metrics, configMap = LABEL_MODES) {
  const config = configMap.marker
  const size = clamp(
    Math.min(metrics.horizontalSpan.size * 0.56, metrics.verticalSpan.size * 0.72),
    config.minSize,
    config.maxSize,
  )
  const x = clamp(
    metrics.anchor.x - (size / 2),
    metrics.bounds.left,
    metrics.bounds.right - size,
  )
  const y = clamp(
    metrics.anchor.y - (size / 2),
    metrics.bounds.top,
    metrics.bounds.bottom - size,
  )
  const fontSize = clamp(size * 0.58, config.minFontSize, config.maxFontSize)

  return {
    mode: 'marker',
    text,
    fontSize: round(fontSize, 2),
    x: round(x, 2),
    y: round(y, 2),
    width: round(size, 2),
    height: round(size, 2),
    paddingX: 0,
    paddingY: 0,
    cornerRadius: round(size / 2, 2),
    anchorX: round(metrics.anchor.x, 2),
    anchorY: round(metrics.anchor.y, 2),
    maxTextWidth: round(size, 2),
  }
}

function fitTextToMode(text, mode, metrics, configMap = LABEL_MODES) {
  const config = configMap[mode]
  const maxTextWidth = Math.max(
    (Math.min(config.maxWidth, metrics.horizontalSpan.size * config.maxWidthRatio))
    - (config.maxHorizontalPadding * 2)
    - 4,
    config.minFontSize,
  )
  const maxLength = Math.min(
    config.maxLength,
    Math.max(3, Math.floor(maxTextWidth / (config.maxFontSize * 0.56))),
  )

  return truncateLabel(text, maxLength)
}

export function getZoneLabelLayout({
  zoneName,
  screenPoints,
  isSelected = false,
  context = 'editor',
  markerText = '',
  viewportBounds = null,
} = {}) {
  const labelText = zoneName?.trim() || 'Zone'
  const metrics = getZoneLabelMetrics(screenPoints)

  if (!metrics.points.length || metrics.bounds.width <= 0 || metrics.bounds.height <= 0) {
    return null
  }

  const modeOrder = ['full', 'compact']

  for (const mode of modeOrder) {
    if (!canFitLabel(metrics, mode, isSelected)) {
      continue
    }

    const text = fitTextToMode(labelText, mode, metrics)
    return constrainLabelToViewport({
      ...buildLabelBox(text, mode, metrics),
      title: labelText,
      metrics,
    }, viewportBounds)
  }

  if (canFitMarker(metrics, context)) {
    const text = createMarkerText(labelText, markerText)
    return constrainLabelToViewport({
      ...buildMarkerBox(text, metrics),
      title: labelText,
      metrics,
    }, viewportBounds)
  }

  return null
}

function getBoundaryAnchorMetrics(points) {
  const baseMetrics = getZoneLabelMetrics(points)
  const candidateRatios = [0.12, 0.18, 0.24, 0.3, 0.38]

  for (const ratio of candidateRatios) {
    const y = baseMetrics.bounds.top + (baseMetrics.bounds.height * ratio)
    const span = getLargestSpan(getLineIntersections(points, 'horizontal', y))

    if (span?.size >= BOUNDARY_LABEL_MODES.compact.minWidth) {
      const verticalSpan = getContainingSpan(
        getLineIntersections(points, 'vertical', span.start + (span.size / 2)),
        y,
      )
      const preferredBottom = Math.min(
        verticalSpan?.end ?? baseMetrics.bounds.bottom,
        baseMetrics.bounds.top + Math.max(baseMetrics.bounds.height * 0.44, 42),
      )

      return {
        ...baseMetrics,
        anchor: {
          x: round(span.start + (span.size / 2), 2),
          y: round(y, 2),
        },
        horizontalSpan: {
          start: round(span.start, 2),
          end: round(span.end, 2),
          size: round(span.size, 2),
        },
        verticalSpan: verticalSpan
          ? {
            start: round(verticalSpan.start, 2),
            end: round(preferredBottom, 2),
            size: round(Math.max(preferredBottom - verticalSpan.start, 0), 2),
          }
          : baseMetrics.verticalSpan,
      }
    }
  }

  return baseMetrics
}

export function getBoundaryLabelLayout({
  plotName = '',
  areaText = '',
  screenPoints,
  viewportBounds = null,
  isSelected = false,
  context = 'editor',
} = {}) {
  const points = (screenPoints ?? []).map(normalizePoint).filter((point) => Number.isFinite(point.x) && Number.isFinite(point.y))

  if (!points.length) {
    return null
  }

  const metrics = getBoundaryAnchorMetrics(points)
  const titleText = [plotName?.trim(), areaText?.trim()].filter(Boolean).join(' | ') || 'Plot boundary'
  const modeCandidates = [
    {
      mode: 'full',
      text: [plotName?.trim(), areaText?.trim()].filter(Boolean).join(' | '),
    },
    {
      mode: 'compact',
      text: areaText?.trim() || plotName?.trim() || 'Plot',
    },
  ]

  for (const candidate of modeCandidates) {
    if (!candidate.text || !canFitLabel(metrics, candidate.mode, isSelected, BOUNDARY_LABEL_MODES)) {
      continue
    }

    return constrainLabelToViewport({
      ...buildLabelBox(
        fitTextToMode(candidate.text, candidate.mode, metrics, BOUNDARY_LABEL_MODES),
        candidate.mode,
        metrics,
        BOUNDARY_LABEL_MODES,
      ),
      title: titleText,
      metrics,
    }, viewportBounds, 12)
  }

  if (canFitMarker(metrics, context, BOUNDARY_LABEL_MODES)) {
    return constrainLabelToViewport({
      ...buildMarkerBox(areaText?.trim() ? 'm2' : 'Plot', metrics, BOUNDARY_LABEL_MODES),
      title: titleText,
      metrics,
    }, viewportBounds, 12)
  }

  return constrainLabelToViewport({
    ...buildMarkerBox('Plot', metrics, BOUNDARY_LABEL_MODES),
    title: titleText,
    metrics,
  }, viewportBounds, 12)
}
