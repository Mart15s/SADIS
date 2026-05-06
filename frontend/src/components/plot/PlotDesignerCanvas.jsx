import { forwardRef, memo, useEffect, useImperativeHandle, useMemo, useRef, useState } from 'react'
import { Circle, Group, Layer, Line, Rect, Stage } from 'react-konva'
import { MapLayerControl, MeasurementBadge, PlotScaleControl } from '../garden/GardenControls.jsx'
import Button from '../ui/Button.jsx'
import ModeToggleGroup from '../ui/ModeToggleGroup.jsx'
import { safeNumber } from '../../lib/constants.js'
import { getBoundaryLabelLayout } from '../../lib/plotCanvasLabels.js'
import {
  buildShapeMetrics,
  createDimensionLabels,
  formatMeters,
  formatSquareMeters,
} from '../../lib/plotMeasurements.js'
import { getProjectedLabelConfig, getZoneColor, projectShape } from '../../lib/plotRender.js'
import {
  GRID_SIZE,
  MAX_ZOOM,
  MIN_BOUNDARY_EDGE,
  MIN_ZOOM,
  MIN_ZONE_EDGE,
  ZONE_VERTEX_HANDLES,
  buildDesignerStateFromPersistence,
  calculateArea,
  clearDesignerState,
  createDefaultZoneShape,
  createRectShape,
  createViewportToFit,
  doShapesOverlap,
  estimateBoundaryFromArea,
  fitShapeInsideBoundary,
  getConstrainedVertexMove,
  getShapeEdgeMidpoints,
  getShapeBounds,
  getShapePoints,
  insertShapePoint,
  pointInPolygon,
  isShapeInsideBoundary,
  isZonePlacementValid,
  loadDesignerState,
  mergeZoneLayouts,
  projectPointToPolygon,
  resolveTranslatedShape,
  sanitizeBoundary,
  sanitizeShape,
  saveDesignerState,
  snapShapeToGrid,
  translateLayouts,
  translateShape,
  updateShapePoint,
} from '../../lib/plotDesigner.js'

const HANDLE_RADIUS_PIXELS = 8
const HANDLE_HIT_PIXELS = 22
const SHAPE_HIT_PIXELS = 26
const POINTER_SLOP_PIXELS = 6
const DIMENSIONS_STORAGE_KEY = 'sad-plot-designer-show-dimensions'
const INTERACTION_MODES = {
  idle: 'idle',
  drawingBoundary: 'drawingBoundary',
  editingBoundary: 'editingBoundary',
  drawingZone: 'drawingZone',
  movingZone: 'movingZone',
  editingZone: 'editingZone',
  addingBoundaryPoint: 'addingBoundaryPoint',
}

function nodeHasAnyName(node, names) {
  if (!node) {
    return false
  }

  if (typeof node.hasName === 'function') {
    return names.some((name) => node.hasName(name))
  }

  const nodeNames = typeof node.name === 'function' ? String(node.name() ?? '').split(/\s+/) : []
  return names.some((name) => nodeNames.includes(name))
}

function targetHasNamedAncestor(target, names) {
  const stage = target?.getStage?.()
  let current = target

  while (current) {
    if (nodeHasAnyName(current, names)) {
      return true
    }

    if (current === stage) {
      break
    }

    current = current.getParent?.()
  }

  return false
}

export function classifyDesignerTarget(target) {
  if (!target || target === target.getStage?.()) {
    return 'background'
  }

  if (targetHasNamedAncestor(target, ['zone-handle'])) {
    return 'zone-handle'
  }

  if (targetHasNamedAncestor(target, ['zone-node', 'zone-shape'])) {
    return 'zone'
  }

  if (targetHasNamedAncestor(target, ['boundary-handle'])) {
    return 'boundary-handle'
  }

  if (targetHasNamedAncestor(target, ['boundary-node', 'boundary-shape'])) {
    return 'boundary'
  }

  return 'background'
}

function isStageBackground(target) {
  return classifyDesignerTarget(target) === 'background'
}

function flattenPoints(points) {
  return points.flatMap((point) => [point.x, point.y])
}

function roundViewport(viewport) {
  return {
    x: Math.round(viewport.x * 100) / 100,
    y: Math.round(viewport.y * 100) / 100,
    scale: Math.round(viewport.scale * 10000) / 10000,
  }
}

function loadDimensionPreference() {
  if (typeof window === 'undefined') {
    return true
  }

  return window.localStorage.getItem(DIMENSIONS_STORAGE_KEY) !== 'false'
}

function labelToBox(label) {
  if (!label) {
    return null
  }

  return {
    left: label.x,
    right: label.x + label.width,
    top: label.y,
    bottom: label.y + label.height,
  }
}

export default memo(forwardRef(function PlotDesignerCanvas({
  plotId,
  plotName,
  plotSize,
  plotGeometry,
  zones,
  plants,
  canEdit,
  activeZoneId,
  persistState = true,
  showSaveAction = true,
  isLayoutSaveDisabled,
  isLayoutSaving,
  layoutSaveFeedback,
  onSaveLayout,
  onSelectZone,
  onCreateZone,
  onZoneCreateBlocked,
  onZoneGeometryCommit,
  onBoundaryCommit,
}, ref) {
  const containerRef = useRef(null)
  const stageRef = useRef(null)
  const panRef = useRef(null)
  const backgroundPointerRef = useRef(null)
  const zoneDragSessionRef = useRef(null)
  const zoneResizeSessionRef = useRef(null)
  const viewportRef = useRef({ x: 0, y: 0, scale: 1 })
  const hasUserViewportRef = useRef(false)
  const initializedPlotRef = useRef(null)
  const draftSessionRef = useRef(null)
  const liveBoundaryRef = useRef(null)
  const liveLayoutsRef = useRef({})
  const redrawFrameRef = useRef(0)
  const autoFitSignatureRef = useRef('')
  const [canvasSize, setCanvasSize] = useState({ width: 1120, height: 760 })
  const [viewport, setViewport] = useState({ x: 0, y: 0, scale: 1 })
  const [designerState, setDesignerState] = useState(() => buildDesignerStateFromPersistence({
    plotSize,
    plotGeometry,
    zones,
    storedState: loadDesignerState(plotId),
  }))
  const [interactionMode, setInteractionMode] = useState(INTERACTION_MODES.idle)
  const [snapEnabled, setSnapEnabled] = useState(false)
  const [showDimensions, setShowDimensions] = useState(loadDimensionPreference)
  const [selectedTarget, setSelectedTarget] = useState({ type: 'none', id: null })
  const [draftZone, setDraftZone] = useState(null)
  const [hoveredZoneId, setHoveredZoneId] = useState(null)
  const [isPanning, setIsPanning] = useState(false)
  const [fitSeed, setFitSeed] = useState(0)
  const [, setInteractionTick] = useState(0)
  const zoneIdsKey = useMemo(() => zones.map((zone) => zone.id).join(','), [zones])
  const geometrySignature = useMemo(
    () => JSON.stringify({
      plotGeometry: plotGeometry ?? null,
      zones: zones.map((zone) => ({
        id: zone.id,
        zone_size: zone.zone_size,
        geometry: zone.geometry ?? null,
      })),
    }),
    [plotGeometry, zones],
  )

  const boundary = designerState.boundary
  const layouts = designerState.layouts
  const isDrawingZoneMode = interactionMode === INTERACTION_MODES.drawingZone

  const renderedBoundary = liveBoundaryRef.current ?? boundary
  const renderedLayouts = liveLayoutsRef.current ?? layouts

  const zonesById = useMemo(
    () => Object.fromEntries(zones.map((zone) => [String(zone.id), zone])),
    [zones],
  )

  const plantCountsByZoneId = useMemo(
    () => plants.reduce((counts, plant) => {
      const zoneId = String(plant.fk_plant_zone_id)
      counts[zoneId] = (counts[zoneId] ?? 0) + 1
      return counts
    }, {}),
    [plants],
  )

  const fitViewport = useMemo(
    () => createViewportToFit(boundary, layouts, canvasSize),
    [boundary, canvasSize, layouts],
  )

  const gridLines = useMemo(() => {
    const worldLeft = (-viewport.x / viewport.scale) - 240
    const worldTop = (-viewport.y / viewport.scale) - 240
    const worldRight = ((canvasSize.width - viewport.x) / viewport.scale) + 240
    const worldBottom = ((canvasSize.height - viewport.y) / viewport.scale) + 240
    const lines = []
    const step = GRID_SIZE
    const startX = Math.floor(worldLeft / step) * step
    const endX = Math.ceil(worldRight / step) * step
    const startY = Math.floor(worldTop / step) * step
    const endY = Math.ceil(worldBottom / step) * step
    const strokeWidth = Math.max(1 / viewport.scale, 0.03)

    for (let x = startX; x <= endX; x += step) {
      lines.push(
        <Line
          key={`grid-x-${x}`}
          name="grid-line"
          points={[x, worldTop, x, worldBottom]}
          stroke={x % (step * 5) === 0 ? 'rgba(71, 99, 59, 0.16)' : 'rgba(71, 99, 59, 0.08)'}
          strokeWidth={strokeWidth}
          listening={false}
        />,
      )
    }

    for (let y = startY; y <= endY; y += step) {
      lines.push(
        <Line
          key={`grid-y-${y}`}
          name="grid-line"
          points={[worldLeft, y, worldRight, y]}
          stroke={y % (step * 5) === 0 ? 'rgba(71, 99, 59, 0.16)' : 'rgba(71, 99, 59, 0.08)'}
          strokeWidth={strokeWidth}
          listening={false}
        />,
      )
    }

    return lines
  }, [canvasSize.height, canvasSize.width, viewport.scale, viewport.x, viewport.y])

  function requestVisualRefresh() {
    if (redrawFrameRef.current) {
      return
    }

    redrawFrameRef.current = window.requestAnimationFrame(() => {
      redrawFrameRef.current = 0
      setInteractionTick((current) => current + 1)
    })
  }

  function updateCursor(cursor) {
    if (containerRef.current) {
      containerRef.current.style.cursor = cursor
    }
  }

  function applyViewport(nextViewport) {
    const rounded = roundViewport(nextViewport)
    viewportRef.current = rounded

    if (stageRef.current) {
      stageRef.current.position({ x: rounded.x, y: rounded.y })
      stageRef.current.scale({ x: rounded.scale, y: rounded.scale })
      stageRef.current.batchDraw()
    }

    setViewport(rounded)
  }

  function toWorldPoint(pointer, activeViewport = viewportRef.current) {
    return {
      x: (pointer.x - activeViewport.x) / activeViewport.scale,
      y: (pointer.y - activeViewport.y) / activeViewport.scale,
    }
  }

  function fitView() {
    const nextViewport = createViewportToFit(
      liveBoundaryRef.current ?? boundary,
      liveLayoutsRef.current ?? layouts,
      canvasSize,
      0.86,
    )
    applyViewport(nextViewport)
  }

  function handleFitView() {
    hasUserViewportRef.current = false
    fitView()
  }

  function selectBoundary() {
    setInteractionMode(INTERACTION_MODES.editingBoundary)
    setSelectedTarget({ type: 'boundary', id: null })
    onSelectZone(null)
  }

  function clearSelection() {
    setInteractionMode(INTERACTION_MODES.idle)
    setSelectedTarget({ type: 'none', id: null })
    onSelectZone(null)
  }

  function selectZone(zoneId) {
    const zone = zonesById[String(zoneId)]
    setInteractionMode(INTERACTION_MODES.editingZone)
    setSelectedTarget({ type: 'zone', id: String(zoneId) })
    if (zone) {
      onSelectZone(zone)
    }
  }

  function replaceDesignerState(nextBoundary, nextLayouts) {
    liveBoundaryRef.current = nextBoundary
    liveLayoutsRef.current = nextLayouts
    setDesignerState({
      boundary: nextBoundary,
      layouts: nextLayouts,
    })
  }

  function resolveZoneShape(shape, boundaryShape = boundary, fallbackShape = shape, options = {}) {
    const snappedShape = options.allowSnap && snapEnabled ? snapShapeToGrid(shape, boundaryShape) : shape
    return sanitizeShape(snappedShape, boundaryShape, fallbackShape)
  }

  function zoneOverlapsOther(zoneId, shape, candidateLayouts = liveLayoutsRef.current ?? renderedLayouts) {
    return Object.entries(candidateLayouts).some(([otherZoneId, otherShape]) => (
      String(otherZoneId) !== String(zoneId) && doShapesOverlap(shape, otherShape)
    ))
  }

  function reportZoneCreateBlocked(message = 'No available non-overlapping space was found for a new zone.') {
    if (onZoneCreateBlocked) {
      onZoneCreateBlocked(message)
    }
  }

  function resolveNewZoneShape(shape, boundaryShape = boundary) {
    if (!shape) {
      return null
    }

    const activeLayouts = liveLayoutsRef.current ?? renderedLayouts
    const existingShapes = Object.values(activeLayouts)
    const snappedShape = resolveZoneShape(shape, boundaryShape, shape, { allowSnap: true })

    if (isZonePlacementValid(snappedShape, boundaryShape, existingShapes)) {
      return snappedShape
    }

    const unsnappedShape = resolveZoneShape(shape, boundaryShape, shape)

    if (isZonePlacementValid(unsnappedShape, boundaryShape, existingShapes)) {
      return unsnappedShape
    }

    return null
  }

  function commitZoneLayout(zoneId, shape, boundaryShape = boundary) {
    const activeLayouts = liveLayoutsRef.current ?? layouts
    const fallbackShape = activeLayouts[zoneId] ?? shape
    const adjusted = resolveZoneShape(shape, boundaryShape, fallbackShape)
    const safeShape = zoneOverlapsOther(zoneId, adjusted, activeLayouts) ? fallbackShape : adjusted
    const nextLayouts = {
      ...activeLayouts,
      [zoneId]: safeShape,
    }

    liveLayoutsRef.current = nextLayouts
    setDesignerState((current) => ({
      ...current,
      layouts: {
        ...current.layouts,
        [zoneId]: safeShape,
      },
    }))

    onZoneGeometryCommit(zoneId, safeShape, boundaryShape)
  }

  function insertBoundaryPoint(edgeIndex, point) {
    const activeBoundary = liveBoundaryRef.current ?? renderedBoundary
    const activeLayouts = liveLayoutsRef.current ?? renderedLayouts
    const nextBoundary = insertShapePoint(
      activeBoundary,
      edgeIndex,
      point,
      activeBoundary,
      MIN_BOUNDARY_EDGE * MIN_BOUNDARY_EDGE * 0.55,
    )

    if (!Object.values(activeLayouts).every((shape) => isShapeInsideBoundary(shape, nextBoundary))) {
      return
    }

    setInteractionMode(INTERACTION_MODES.addingBoundaryPoint)
    commitBoundaryLayout(nextBoundary, activeLayouts)
    setSelectedTarget({ type: 'boundary', id: null })
  }

  function commitBoundaryLayout(nextBoundaryShape, nextLayoutsShape) {
    const snappedBoundary = snapEnabled
      ? sanitizeBoundary(snapShapeToGrid(nextBoundaryShape), nextBoundaryShape)
      : sanitizeBoundary(nextBoundaryShape, boundary)

    const adjustedLayouts = Object.fromEntries(
      Object.entries(nextLayoutsShape).map(([zoneId, shape]) => [
        zoneId,
        resolveZoneShape(fitShapeInsideBoundary(shape, snappedBoundary), snappedBoundary, shape, { allowSnap: true }),
      ]),
    )

    replaceDesignerState(snappedBoundary, adjustedLayouts)
    onBoundaryCommit(snappedBoundary, adjustedLayouts)
  }

  function resetDesignerLayout() {
    const freshBoundary = estimateBoundaryFromArea(plotSize)
    const freshLayouts = mergeZoneLayouts(zones, freshBoundary)

    clearDesignerState(plotId)
    replaceDesignerState(freshBoundary, freshLayouts)
    setSelectedTarget({ type: 'none', id: null })
    onSelectZone(null)
    setInteractionMode(INTERACTION_MODES.idle)
    setFitSeed((current) => current + 1)
  }

  function buildDraftZoneShape(anchorPoint, candidatePoint) {
    const projectedPoint = projectPointToPolygon(candidatePoint, renderedBoundary)

    function createCandidate(targetPoint) {
      return createRectShape({
        x: Math.min(anchorPoint.x, targetPoint.x),
        y: Math.min(anchorPoint.y, targetPoint.y),
        width: Math.max(MIN_ZONE_EDGE, Math.abs(targetPoint.x - anchorPoint.x)),
        height: Math.max(MIN_ZONE_EDGE, Math.abs(targetPoint.y - anchorPoint.y)),
      })
    }

    const fullShape = createCandidate(projectedPoint)

    if (isShapeInsideBoundary(fullShape, renderedBoundary)) {
      return fullShape
    }

    let low = 0
    let high = 1
    let bestPoint = anchorPoint

    for (let iteration = 0; iteration < 20; iteration += 1) {
      const middle = (low + high) / 2
      const probe = {
        x: anchorPoint.x + ((projectedPoint.x - anchorPoint.x) * middle),
        y: anchorPoint.y + ((projectedPoint.y - anchorPoint.y) * middle),
      }
      const probeShape = createCandidate(probe)

      if (isShapeInsideBoundary(probeShape, renderedBoundary)) {
        bestPoint = probe
        low = middle
      } else {
        high = middle
      }
    }

    return createCandidate(bestPoint)
  }

  function getBoundaryHandlePoint(index, targetPoint) {
    const activeBoundary = liveBoundaryRef.current ?? renderedBoundary
    const activeLayouts = liveLayoutsRef.current ?? renderedLayouts

    return getConstrainedVertexMove(
      activeBoundary,
      index,
      targetPoint,
      null,
      MIN_BOUNDARY_EDGE * MIN_BOUNDARY_EDGE * 0.55,
      (candidateBoundary) => Object.values(activeLayouts).every((shape) => isShapeInsideBoundary(shape, candidateBoundary)),
    )
  }

  function pointerMovedEnough(startPointer, pointer) {
    return Math.hypot(pointer.x - startPointer.x, pointer.y - startPointer.y) >= POINTER_SLOP_PIXELS
  }

  async function createZoneFromShape(shape, boundaryShape = liveBoundaryRef.current ?? boundary) {
    const nextShape = resolveNewZoneShape(shape, boundaryShape)

    if (!nextShape || calculateArea(nextShape) < MIN_ZONE_EDGE * MIN_ZONE_EDGE * 0.6) {
      reportZoneCreateBlocked()
      return null
    }

    const created = await onCreateZone(nextShape, boundaryShape)
    const activeLayouts = liveLayoutsRef.current ?? layouts
    const nextLayouts = {
      ...activeLayouts,
      [created.id]: nextShape,
    }

    liveLayoutsRef.current = nextLayouts
    setDesignerState((current) => ({
      ...current,
      layouts: nextLayouts,
    }))
    setInteractionMode(INTERACTION_MODES.editingZone)
    selectZone(created.id)

    return created
  }

  function beginZoneDrag(zoneId, pointer) {
    const activeBoundary = liveBoundaryRef.current ?? renderedBoundary
    const activeLayouts = liveLayoutsRef.current ?? renderedLayouts
    const activeShape = activeLayouts[zoneId]

    if (!activeShape) {
      return
    }

    zoneDragSessionRef.current = {
      zoneId,
      boundary: activeBoundary,
      originShape: activeShape,
      lastValidShape: activeShape,
      anchorPoint: toWorldPoint(pointer),
      moved: false,
    }
    setInteractionMode(INTERACTION_MODES.movingZone)
    selectZone(zoneId)
    setInteractionMode(INTERACTION_MODES.movingZone)
    updateCursor('grabbing')
  }

  function updateZoneDrag(pointer) {
    const session = zoneDragSessionRef.current

    if (!session) {
      return null
    }

    const currentPoint = toWorldPoint(pointer)
    const requestedOffset = {
      x: currentPoint.x - session.anchorPoint.x,
      y: currentPoint.y - session.anchorPoint.y,
    }
    const nextShape = resolveTranslatedShape(
      session.originShape,
      session.boundary,
      requestedOffset,
      session.lastValidShape,
      (candidateShape) => !zoneOverlapsOther(session.zoneId, candidateShape),
    )
    const activeLayouts = liveLayoutsRef.current ?? renderedLayouts
    const didMove = JSON.stringify(getShapePoints(nextShape)) !== JSON.stringify(getShapePoints(session.originShape))
    const acceptedCandidate = JSON.stringify(getShapePoints(nextShape)) !== JSON.stringify(getShapePoints(session.lastValidShape))

    zoneDragSessionRef.current = {
      ...session,
      moved: session.moved || didMove,
      currentShape: nextShape,
      lastValidShape: acceptedCandidate ? nextShape : session.lastValidShape,
    }
    liveLayoutsRef.current = {
      ...activeLayouts,
      [session.zoneId]: nextShape,
    }
    requestVisualRefresh()

    return nextShape
  }

  function beginZoneResize(zoneId, pointIndex, pointer) {
    const activeBoundary = liveBoundaryRef.current ?? renderedBoundary
    const activeLayouts = liveLayoutsRef.current ?? renderedLayouts
    const activeShape = activeLayouts[zoneId]

    if (!activeShape) {
      return
    }

    zoneResizeSessionRef.current = {
      zoneId,
      pointIndex,
      boundary: activeBoundary,
      pointer,
      currentShape: activeShape,
      moved: false,
    }
    setInteractionMode(INTERACTION_MODES.editingZone)
    selectZone(zoneId)
    updateCursor('grabbing')
  }

  function updateZoneResize(pointer) {
    const session = zoneResizeSessionRef.current

    if (!session) {
      return
    }

    const targetPoint = toWorldPoint(pointer)
    const nextPoint = getConstrainedVertexMove(
      session.currentShape,
      session.pointIndex,
      targetPoint,
      session.boundary,
      MIN_ZONE_EDGE * MIN_ZONE_EDGE * 0.35,
      (candidateShape) => !zoneOverlapsOther(session.zoneId, candidateShape),
    )
    const nextShape = updateShapePoint(session.currentShape, session.pointIndex, nextPoint)
    const activeLayouts = liveLayoutsRef.current ?? renderedLayouts
    const authoritativePoint = getShapePoints(nextShape)[session.pointIndex]

    zoneResizeSessionRef.current = {
      ...session,
      currentShape: nextShape,
      moved: session.moved || pointerMovedEnough(session.pointer, pointer),
    }
    liveLayoutsRef.current = {
      ...activeLayouts,
      [session.zoneId]: nextShape,
    }
    requestVisualRefresh()

    return authoritativePoint
  }

  function finishZoneResize() {
    const session = zoneResizeSessionRef.current
    zoneResizeSessionRef.current = null

    if (!session) {
      return false
    }

    if (!session.moved || !session.currentShape) {
      return true
    }

    commitZoneLayout(session.zoneId, session.currentShape, session.boundary)
    setInteractionMode(INTERACTION_MODES.editingZone)
    return true
  }

  function finishZoneDrag() {
    const session = zoneDragSessionRef.current
    zoneDragSessionRef.current = null

    if (!session) {
      return false
    }

    if (!session.moved || !session.currentShape) {
      return true
    }

    commitZoneLayout(session.zoneId, session.currentShape, session.boundary)
    setInteractionMode(INTERACTION_MODES.editingZone)
    return true
  }

  useImperativeHandle(ref, () => ({
    async createZoneFromForm() {
      const activeBoundary = liveBoundaryRef.current ?? boundary
      const activeLayouts = liveLayoutsRef.current ?? layouts
      const nextShape = createDefaultZoneShape(activeBoundary, Object.values(activeLayouts))
      return createZoneFromShape(nextShape, activeBoundary)
    },
  }))

  useEffect(() => {
    const observer = new ResizeObserver(([entry]) => {
      const nextWidth = Math.max(Math.round(entry.contentRect.width), 720)
      const nextHeight = Math.max(Math.round(entry.contentRect.height), 620)

      setCanvasSize((current) => (
        current.width === nextWidth && current.height === nextHeight
          ? current
          : { width: nextWidth, height: nextHeight }
      ))
    })

    if (containerRef.current) {
      observer.observe(containerRef.current)
    }

    return () => observer.disconnect()
  }, [])

  useEffect(() => {
    const storedState = persistState
      ? loadDesignerState(plotId)
      : {
        boundary: liveBoundaryRef.current ?? boundary,
        layouts: liveLayoutsRef.current ?? layouts,
      }
    const nextState = buildDesignerStateFromPersistence({
      plotSize,
      plotGeometry,
      zones,
      storedState,
    })

    replaceDesignerState(nextState.boundary, nextState.layouts)
    setInteractionMode(INTERACTION_MODES.idle)
    if (initializedPlotRef.current !== plotId) {
      initializedPlotRef.current = plotId
      hasUserViewportRef.current = false
      setFitSeed((current) => current + 1)
    }
  }, [geometrySignature, persistState, plotId, plotSize])

  useEffect(() => {
    setDesignerState((current) => ({
      ...current,
      layouts: mergeZoneLayouts(zones, current.boundary, current.layouts),
    }))
  }, [zoneIdsKey, zones])

  useEffect(() => {
    if (activeZoneId) {
      setInteractionMode(INTERACTION_MODES.editingZone)
      setSelectedTarget({ type: 'zone', id: String(activeZoneId) })
      return
    }

    setSelectedTarget((current) => (
      current.type === 'zone'
        ? { type: 'none', id: null }
        : current
    ))
  }, [activeZoneId])

  useEffect(() => {
    liveBoundaryRef.current = boundary
    liveLayoutsRef.current = layouts
  }, [boundary, layouts])

  useEffect(() => {
    if (!persistState) {
      return
    }

    saveDesignerState(plotId, designerState)
  }, [designerState, persistState, plotId])

  useEffect(() => {
    const signature = `${fitSeed}:${canvasSize.width}:${canvasSize.height}`

    if (
      !canvasSize.width
      || !canvasSize.height
      || autoFitSignatureRef.current === signature
      || hasUserViewportRef.current
      || zoneDragSessionRef.current
      || zoneResizeSessionRef.current
    ) {
      return
    }

    autoFitSignatureRef.current = signature
    applyViewport(fitViewport)
  }, [canvasSize.height, canvasSize.width, fitSeed, fitViewport])

  useEffect(() => {
    viewportRef.current = viewport
  }, [viewport])

  useEffect(() => {
    if (typeof window !== 'undefined') {
      window.localStorage.setItem(DIMENSIONS_STORAGE_KEY, String(showDimensions))
    }
  }, [showDimensions])

  useEffect(() => {
    updateCursor(isDrawingZoneMode ? 'crosshair' : isPanning ? 'grabbing' : 'grab')
  }, [isDrawingZoneMode, isPanning])

  useEffect(() => () => {
    if (redrawFrameRef.current) {
      window.cancelAnimationFrame(redrawFrameRef.current)
    }
  }, [])

  function handleWheel(event) {
    event.evt.preventDefault()
    hasUserViewportRef.current = true

    const pointer = stageRef.current?.getPointerPosition()

    if (!pointer) {
      return
    }

    const currentViewport = viewportRef.current
    const worldPoint = toWorldPoint(pointer, currentViewport)
    const direction = event.evt.deltaY > 0 ? -1 : 1
    const scaleFactor = direction > 0 ? 1.12 : 0.9
    const minScale = Math.max(MIN_ZOOM, fitViewport.scale * 0.35)
    const maxScale = Math.max(fitViewport.scale * 6, 18)
    const nextScale = Math.min(Math.max(currentViewport.scale * scaleFactor, minScale), Math.min(maxScale, MAX_ZOOM))

    applyViewport({
      scale: nextScale,
      x: pointer.x - (worldPoint.x * nextScale),
      y: pointer.y - (worldPoint.y * nextScale),
    })
  }

  function beginDraftZone(pointer) {
    if (!canEdit) {
      return false
    }

    const worldPoint = toWorldPoint(pointer)

    if (!pointInPolygon(worldPoint, renderedBoundary)) {
      reportZoneCreateBlocked('Draw zones by starting inside the plot boundary.')
      return false
    }

    const initialShape = createRectShape({
      x: worldPoint.x,
      y: worldPoint.y,
      width: MIN_ZONE_EDGE,
      height: MIN_ZONE_EDGE,
    })

    draftSessionRef.current = {
      anchor: worldPoint,
      shape: initialShape,
    }
    setDraftZone(initialShape)
    setInteractionMode(INTERACTION_MODES.drawingZone)
    return true
  }

  function handleStageMouseDown(event) {
    const stage = event.target.getStage()
    const pointer = stage?.getPointerPosition()

    if (!pointer) {
      return
    }

    if (isDrawingZoneMode) {
      beginDraftZone(pointer)
      return
    }

    if (isStageBackground(event.target)) {
      backgroundPointerRef.current = {
        pointer,
        moved: false,
      }
      panRef.current = {
        pointer,
        viewport: viewportRef.current,
      }
      setIsPanning(true)
      updateCursor('grabbing')
    }
  }

  function handleStageMouseMove() {
    const pointer = stageRef.current?.getPointerPosition()

    if (!pointer) {
      return
    }

    if (draftSessionRef.current) {
      const nextShape = buildDraftZoneShape(
        draftSessionRef.current.anchor,
        toWorldPoint(pointer),
      )

      draftSessionRef.current.shape = nextShape
      setDraftZone(nextShape)
      return
    }

    if (zoneDragSessionRef.current) {
      updateZoneDrag(pointer)
      return
    }

    if (zoneResizeSessionRef.current) {
      updateZoneResize(pointer)
      return
    }

    if (panRef.current) {
      const dx = pointer.x - panRef.current.pointer.x
      const dy = pointer.y - panRef.current.pointer.y

      if (backgroundPointerRef.current && !backgroundPointerRef.current.moved) {
        backgroundPointerRef.current = {
          ...backgroundPointerRef.current,
          moved: pointerMovedEnough(backgroundPointerRef.current.pointer, pointer),
        }
      }

      applyViewport({
        ...panRef.current.viewport,
        x: panRef.current.viewport.x + dx,
        y: panRef.current.viewport.y + dy,
      }, false)
      hasUserViewportRef.current = true
    }
  }

  async function handleStageMouseUp() {
    const backgroundPointer = backgroundPointerRef.current
    const zoneWasDragging = Boolean(zoneDragSessionRef.current)
    const zoneWasResizing = Boolean(zoneResizeSessionRef.current)

    backgroundPointerRef.current = null

    if (draftSessionRef.current?.shape) {
      const nextShape = draftSessionRef.current.shape
      draftSessionRef.current = null
      setDraftZone(null)

      try {
        await createZoneFromShape(nextShape, liveBoundaryRef.current ?? renderedBoundary)
      } catch {
        setInteractionMode(INTERACTION_MODES.idle)
      }
    }

    if (zoneWasDragging) {
      finishZoneDrag()
      updateCursor('grab')
    }

    if (zoneWasResizing) {
      finishZoneResize()
      updateCursor('grab')
    }

    if (panRef.current) {
      setViewport(viewportRef.current)
    }

    panRef.current = null
    setIsPanning(false)

    if (backgroundPointer && !backgroundPointer.moved && !draftSessionRef.current && !zoneWasDragging && !zoneWasResizing) {
      clearSelection()
    }

    updateCursor(isDrawingZoneMode ? 'crosshair' : 'grab')
  }

  const selectedZone = selectedTarget.type === 'zone'
    ? zonesById[selectedTarget.id]
    : null

  const selectedShape = selectedTarget.type === 'zone'
    ? renderedLayouts[selectedTarget.id]
    : selectedTarget.type === 'boundary'
      ? renderedBoundary
      : null

  const selectedBounds = selectedShape ? getShapeBounds(selectedShape) : null
  const strokeWidth = Math.max(2 / viewport.scale, 0.08)
  const activeStrokeWidth = Math.max(4.25 / viewport.scale, 0.13)
  const handleRadius = Math.max(HANDLE_RADIUS_PIXELS / viewport.scale, 0.16)
  const handleHitWidth = Math.max(HANDLE_HIT_PIXELS / viewport.scale, handleRadius * 2.5)
  const hitStrokeWidth = Math.max(SHAPE_HIT_PIXELS / viewport.scale, strokeWidth * 3)
  const zoomLabel = viewport.scale >= 10 ? `${safeNumber(viewport.scale, 1)}x` : `${safeNumber(viewport.scale, 2)}x`
  const viewportBounds = { width: canvasSize.width, height: canvasSize.height }
  const saveStatus = isLayoutSaving
    ? { className: 'badge badge-warning', text: 'Saving layout...' }
    : layoutSaveFeedback?.type === 'error'
      ? { className: 'badge badge-danger', text: layoutSaveFeedback.message }
      : layoutSaveFeedback?.type === 'success'
        ? { className: 'badge badge-success', text: layoutSaveFeedback.message }
        : null
  const plotBoundaryLabel = getBoundaryLabelLayout({
    plotName: plotName?.trim() || 'Plot boundary',
    areaText: formatSquareMeters(calculateArea(renderedBoundary), 1),
    screenPoints: projectShape(renderedBoundary, viewport),
    viewportBounds,
    isSelected: selectedTarget.type === 'boundary',
    context: 'editor',
  })
  const screenLabels = zones.map((zone, index) => {
    const zoneId = String(zone.id)
    const shape = renderedLayouts[zoneId]

    if (!shape) {
      return null
    }

    const colors = getZoneColor(index)
    const isActive = selectedTarget.type === 'zone' && selectedTarget.id === zoneId
    const label = getProjectedLabelConfig(zone.name, shape, viewport, {
      isSelected: isActive,
      context: 'editor',
      markerText: index + 1,
      viewportBounds,
    })

    if (!label) {
      return null
    }

    return {
      id: zoneId,
      color: colors,
      isActive,
      label,
    }
  }).filter(Boolean)
  const plotMetrics = buildShapeMetrics(renderedBoundary)
  const selectedMetrics = selectedShape ? buildShapeMetrics(selectedShape) : null
  const occupiedDimensionBoxes = [
    labelToBox(plotBoundaryLabel),
    ...screenLabels.map(({ label }) => labelToBox(label)),
  ].filter(Boolean)
  const dimensionLabels = showDimensions
    ? [
      ...createDimensionLabels({
        shape: renderedBoundary,
        viewport,
        viewportBounds,
        idPrefix: 'plot-edge',
        occupiedBoxes: occupiedDimensionBoxes,
        minScreenLength: 64,
      }).map((label) => ({ ...label, scope: 'plot' })),
      ...zones.flatMap((zone) => {
        const zoneId = String(zone.id)
        const shape = renderedLayouts[zoneId]

        if (!shape) {
          return []
        }

        return createDimensionLabels({
          shape,
          viewport,
          viewportBounds,
          idPrefix: `zone-${zoneId}-edge`,
          occupiedBoxes: occupiedDimensionBoxes,
          minScreenLength: 58,
        }).map((label) => ({
          ...label,
          scope: selectedTarget.type === 'zone' && selectedTarget.id === zoneId ? 'zone-active' : 'zone',
        }))
      }),
    ]
    : []
  const toolbarStatusTitle = selectedZone
    ? `${selectedZone.name} selected`
    : selectedTarget.type === 'boundary'
      ? 'Plot boundary selected'
      : isDrawingZoneMode
        ? 'Zone drawing mode'
        : interactionMode === INTERACTION_MODES.addingBoundaryPoint
          ? 'Adding boundary corner'
          : 'Selection mode'
  const toolbarStatusHint = selectedZone
    ? `${plantCountsByZoneId[selectedTarget.id] ?? 0} plants in zone`
    : selectedTarget.type === 'boundary'
      ? 'Drag corners to reshape, or use the small + handles on edges to add a corner'
      : isDrawingZoneMode
        ? 'Click and drag inside the plot. Clicks outside the boundary are ignored.'
        : 'Click a zone to edit it, or drag the background to pan'
  const layerItems = [
    { id: 'boundary', label: 'Plot boundary', active: true, color: '#47633b' },
    { id: 'zones', label: `${zones.length} zones`, active: zones.length > 0, color: '#b9683f' },
    { id: 'grid', label: 'Grid', active: true, color: '#8c7c66' },
    { id: 'dimensions', label: 'Dimensions', active: showDimensions, color: '#ef6d22' },
  ]

  return (
    <div className="designer-panel">
      <div className="designer-toolbar">
        <div className="designer-toolbar-group designer-toolbar-group--controls">
          <ModeToggleGroup
            ariaLabel="Plot designer modes"
            value={isDrawingZoneMode ? INTERACTION_MODES.drawingZone : INTERACTION_MODES.idle}
            onChange={(nextMode) => {
              setInteractionMode((current) => (
                nextMode === INTERACTION_MODES.drawingZone && current === INTERACTION_MODES.drawingZone
                  ? INTERACTION_MODES.idle
                  : nextMode
              ))
            }}
            options={[
              { value: INTERACTION_MODES.idle, label: 'Select / edit' },
              ...(canEdit ? [{ value: INTERACTION_MODES.drawingZone, label: 'Draw zone' }] : []),
            ]}
          />
          <Button variant="ghost" onClick={handleFitView} title="Fit the full plot and all zones into view">Fit to view</Button>
          {canEdit ? <Button variant="secondary" onClick={resetDesignerLayout} title="Reset the visual plot layout to the default shape">Reset layout</Button> : null}
        </div>

        <div className="designer-toolbar-group designer-toolbar-group--status">
          <span className="designer-toolbar-kicker">{toolbarStatusTitle}</span>
          <span className="designer-toolbar-hint">{toolbarStatusHint}</span>
        </div>

        <div className="designer-toolbar-group designer-toolbar-group--actions">
          {canEdit && showSaveAction ? (
            <Button onClick={onSaveLayout} disabled={isLayoutSaveDisabled || isLayoutSaving}>
              {isLayoutSaving ? 'Saving layout...' : 'Save plot layout'}
            </Button>
          ) : null}
          {saveStatus ? <span className={saveStatus.className}>{saveStatus.text}</span> : null}
          <label className="designer-toggle">
            <input
              type="checkbox"
              checked={snapEnabled}
              onChange={(event) => setSnapEnabled(event.target.checked)}
            />
            <span>Snap to grid</span>
          </label>
          <label className="designer-toggle">
            <input
              type="checkbox"
              checked={showDimensions}
              onChange={(event) => setShowDimensions(event.target.checked)}
            />
            <span>Show dimensions</span>
          </label>
        </div>
      </div>

      <div className="designer-map-console">
        <MapLayerControl title="Visible layers" items={layerItems} />
        <PlotScaleControl zoom={zoomLabel} snapEnabled={snapEnabled} dimensionsVisible={showDimensions} />
      </div>

      <div className="designer-meta-grid">
        <MeasurementBadge label="Plot area" value={formatSquareMeters(calculateArea(renderedBoundary), 1)} tone="field" className="designer-measurement" />
        <MeasurementBadge label="Plot perimeter" value={formatMeters(plotMetrics.perimeter)} tone="earth" className="designer-measurement" />
        <MeasurementBadge label="Side lengths" value={plotMetrics.sideSummary || 'No geometry'} tone="amber" className="designer-measurement designer-measurement-wide" />
        <MeasurementBadge
          label={selectedZone ? 'Selected zone' : 'Mapped zones'}
          value={selectedZone && selectedMetrics ? `${formatMeters(selectedMetrics.perimeter)} perimeter` : `${zones.length} total`}
          tone="leaf"
          className="designer-measurement"
        />
      </div>

      <div ref={containerRef} className={`designer-stage ${isDrawingZoneMode ? 'is-drawing' : ''}`}>
        <Stage
          ref={stageRef}
          width={canvasSize.width}
          height={canvasSize.height}
          scaleX={viewport.scale}
          scaleY={viewport.scale}
          x={viewport.x}
          y={viewport.y}
          onWheel={handleWheel}
          onMouseDown={handleStageMouseDown}
          onMouseMove={handleStageMouseMove}
          onMouseUp={handleStageMouseUp}
          onMouseLeave={handleStageMouseUp}
        >
          <Layer>
            <Rect
              name="canvas-hit-area"
              x={(-viewport.x / viewport.scale) - 160}
              y={(-viewport.y / viewport.scale) - 160}
              width={(canvasSize.width / viewport.scale) + 320}
              height={(canvasSize.height / viewport.scale) + 320}
              fill="rgba(255,255,255,0.001)"
            />

            {gridLines}

            <Group
              name="boundary-node"
              listening={!isDrawingZoneMode}
              draggable={canEdit && selectedTarget.type === 'boundary' && !isDrawingZoneMode}
              onClick={(event) => {
                event.cancelBubble = true
                if (isDrawingZoneMode) {
                  return
                }
                selectBoundary()
              }}
              onTap={selectBoundary}
              onDragStart={() => {
                selectBoundary()
                updateCursor('grabbing')
              }}
              onDragEnd={(event) => {
                const offset = { x: event.target.x(), y: event.target.y() }
                event.target.position({ x: 0, y: 0 })
                updateCursor('grab')

                if (offset.x || offset.y) {
                  const activeBoundary = liveBoundaryRef.current ?? renderedBoundary
                  const activeLayouts = liveLayoutsRef.current ?? renderedLayouts
                  const nextBoundary = translateShape(activeBoundary, offset)
                  const nextLayouts = translateLayouts(activeLayouts, offset)
                  commitBoundaryLayout(nextBoundary, nextLayouts)
                }
              }}
            >
              <Line
                points={flattenPoints(getShapePoints(renderedBoundary))}
                closed
                fill="rgba(255, 250, 242, 0.82)"
                listening={false}
              />
              <Line
                name="boundary-shape"
                points={flattenPoints(getShapePoints(renderedBoundary))}
                closed
                stroke={selectedTarget.type === 'boundary' ? '#b9683f' : '#47633b'}
                strokeWidth={selectedTarget.type === 'boundary' ? activeStrokeWidth : strokeWidth}
                hitStrokeWidth={hitStrokeWidth}
                shadowColor={selectedTarget.type === 'boundary' ? 'rgba(185, 104, 63, 0.38)' : 'rgba(71, 99, 59, 0.14)'}
                shadowBlur={selectedTarget.type === 'boundary' ? 0.9 : 0.25}
                shadowOpacity={selectedTarget.type === 'boundary' ? 0.65 : 0.3}
                shadowOffsetY={0.08}
              />

            </Group>

            {zones.map((zone, index) => {
              const zoneId = String(zone.id)
              const shape = renderedLayouts[zoneId]

              if (!shape) {
                return null
              }

              const points = getShapePoints(shape)
              const colors = getZoneColor(index)
              const isActive = selectedTarget.type === 'zone' && selectedTarget.id === zoneId
              const isHovered = hoveredZoneId === zoneId

              return (
                <Group
                  key={zone.id}
                  name="zone-node"
                  listening={!isDrawingZoneMode}
                  onMouseDown={(event) => {
                    event.cancelBubble = true

                    if (!canEdit || isDrawingZoneMode) {
                      return
                    }

                    const pointer = event.target.getStage()?.getPointerPosition()

                    if (pointer) {
                      beginZoneDrag(zoneId, pointer)
                    }
                  }}
                  onClick={(event) => {
                    event.cancelBubble = true
                    if (isDrawingZoneMode) {
                      return
                    }
                    selectZone(zone.id)
                  }}
                  onTap={() => {
                    if (!isDrawingZoneMode) {
                      selectZone(zone.id)
                    }
                  }}
                  onMouseEnter={() => {
                    setHoveredZoneId(zoneId)
                    updateCursor(canEdit ? 'grab' : 'pointer')
                  }}
                  onMouseLeave={() => {
                    setHoveredZoneId(null)
                    updateCursor(isDrawingZoneMode ? 'crosshair' : isPanning ? 'grabbing' : 'grab')
                  }}
                >
                  <Line
                    name="zone-shape"
                    points={flattenPoints(points)}
                    closed
                    fill={colors.fill}
                    opacity={isHovered || isActive ? 0.94 : 0.84}
                    stroke={isActive ? '#b9683f' : colors.stroke}
                    strokeWidth={isActive ? activeStrokeWidth : strokeWidth}
                    hitStrokeWidth={hitStrokeWidth}
                    shadowColor={isActive ? 'rgba(185, 104, 63, 0.45)' : 'rgba(36, 49, 31, 0.16)'}
                    shadowBlur={isActive ? 0.8 : 0.2}
                    shadowOpacity={isActive ? 0.68 : 0.25}
                    shadowOffsetY={0.06}
                  />
                  {isActive ? (
                    <Line
                      points={flattenPoints(points)}
                      closed
                      fill="rgba(242, 106, 33, 0.12)"
                      stroke="#f26a21"
                      strokeWidth={Math.max(activeStrokeWidth * 0.48, strokeWidth)}
                      hitStrokeWidth={hitStrokeWidth}
                      listening={false}
                    />
                  ) : null}
                </Group>
              )
            })}

            {draftZone ? (
              <Line
                points={flattenPoints(getShapePoints(draftZone))}
                closed
                fill="rgba(185, 104, 63, 0.16)"
                stroke="#b9683f"
                strokeWidth={strokeWidth}
                dash={[0.6, 0.35]}
                listening={false}
              />
            ) : null}

            {canEdit && !isDrawingZoneMode && selectedTarget.type === 'boundary'
              ? getShapePoints(renderedBoundary).map((point, index) => (
                <Circle
                  key={`boundary-handle-${index}`}
                  name="boundary-handle"
                  x={point.x}
                  y={point.y}
                  radius={handleRadius}
                  fill="#fff8f2"
                  stroke="#b9683f"
                  strokeWidth={strokeWidth}
                  hitStrokeWidth={handleHitWidth}
                  draggable
                  onMouseDown={(event) => {
                    event.cancelBubble = true
                  }}
                  onDragStart={() => updateCursor('grabbing')}
                  onDragMove={(event) => {
                    const activeBoundary = liveBoundaryRef.current ?? renderedBoundary
                    const nextPoint = getBoundaryHandlePoint(index, {
                      x: event.target.x(),
                      y: event.target.y(),
                    })
                    const nextBoundary = updateShapePoint(activeBoundary, index, nextPoint)
                    event.target.position(nextPoint)
                    liveBoundaryRef.current = nextBoundary
                    requestVisualRefresh()
                  }}
                  onDragEnd={(event) => {
                    const activeBoundary = liveBoundaryRef.current ?? renderedBoundary
                    const activeLayouts = liveLayoutsRef.current ?? renderedLayouts
                    const nextPoint = getBoundaryHandlePoint(index, {
                      x: event.target.x(),
                      y: event.target.y(),
                    })
                    const nextBoundary = updateShapePoint(activeBoundary, index, nextPoint)
                    const nextLayouts = Object.fromEntries(
                      Object.entries(activeLayouts).map(([zoneId, shape]) => [
                        zoneId,
                        fitShapeInsideBoundary(shape, nextBoundary),
                      ]),
                    )
                    updateCursor('grab')
                    commitBoundaryLayout(nextBoundary, nextLayouts)
                  }}
                />
              ))
              : null}

            {canEdit && !isDrawingZoneMode && selectedTarget.type === 'boundary'
              ? getShapeEdgeMidpoints(renderedBoundary).map((edge) => {
                const iconSize = Math.max(5.5 / viewport.scale, 0.12)

                return (
                  <Group
                    key={`boundary-edge-add-${edge.index}`}
                    name="boundary-handle boundary-edge-add"
                    x={edge.point.x}
                    y={edge.point.y}
                    onMouseEnter={() => updateCursor('copy')}
                    onMouseLeave={() => updateCursor(isDrawingZoneMode ? 'crosshair' : 'grab')}
                    onMouseDown={(event) => {
                      event.cancelBubble = true
                    }}
                    onClick={(event) => {
                      event.cancelBubble = true
                      insertBoundaryPoint(edge.index, edge.point)
                    }}
                    onTap={() => insertBoundaryPoint(edge.index, edge.point)}
                  >
                    <Circle
                      radius={Math.max(6.5 / viewport.scale, 0.16)}
                      fill="#fffdf9"
                      stroke="#47633b"
                      strokeWidth={strokeWidth}
                      hitStrokeWidth={handleHitWidth}
                    />
                    <Line
                      points={[-iconSize, 0, iconSize, 0]}
                      stroke="#47633b"
                      strokeWidth={Math.max(2 / viewport.scale, 0.05)}
                      listening={false}
                    />
                    <Line
                      points={[0, -iconSize, 0, iconSize]}
                      stroke="#47633b"
                      strokeWidth={Math.max(2 / viewport.scale, 0.05)}
                      listening={false}
                    />
                  </Group>
                )
              })
              : null}

            {canEdit && !isDrawingZoneMode && selectedTarget.type === 'zone' && selectedTarget.id && selectedShape
              ? ZONE_VERTEX_HANDLES.map((pointIndex) => {
                const point = getShapePoints(selectedShape)[pointIndex]

                if (!point) {
                  return null
                }

                return (
                <Circle
                  key={`zone-handle-${selectedTarget.id}-${pointIndex}`}
                  name="zone-handle"
                  x={point.x}
                  y={point.y}
                  radius={handleRadius}
                  fill="#ffffff"
                  stroke="#b9683f"
                  strokeWidth={strokeWidth}
                  hitStrokeWidth={handleHitWidth}
                  draggable
                  onMouseDown={(event) => {
                    event.cancelBubble = true
                  }}
                  onDragStart={(event) => {
                    const pointer = event.target.getStage()?.getPointerPosition()
                    if (pointer) {
                      beginZoneResize(selectedTarget.id, pointIndex, pointer)
                    }
                  }}
                  onDragMove={(event) => {
                    const pointer = event.target.getStage()?.getPointerPosition()
                    if (pointer) {
                      const nextPoint = updateZoneResize(pointer)
                      if (nextPoint) {
                        event.target.position(nextPoint)
                      }
                    }
                  }}
                  onDragEnd={(event) => {
                    const session = zoneResizeSessionRef.current
                    const currentPoint = session?.currentShape
                      ? getShapePoints(session.currentShape)[session.pointIndex]
                      : null

                    if (currentPoint) {
                      event.target.position(currentPoint)
                    }

                    updateCursor('grab')
                    finishZoneResize()
                  }}
                />
                )
              })
              : null}

            {selectedBounds ? (
              <Line
                points={[
                  selectedBounds.left,
                  selectedBounds.top,
                  selectedBounds.right,
                  selectedBounds.top,
                  selectedBounds.right,
                  selectedBounds.bottom,
                  selectedBounds.left,
                  selectedBounds.bottom,
                ]}
                closed
                stroke="rgba(185, 104, 63, 0.55)"
                strokeWidth={Math.max(1.5 / viewport.scale, 0.05)}
                dash={[0.35, 0.24]}
                listening={false}
              />
            ) : null}
          </Layer>
        </Stage>

        <div className="designer-label-layer" aria-hidden="true">
          {plotBoundaryLabel ? (
            <div
              className={`designer-plot-label designer-plot-label--${plotBoundaryLabel.mode} ${selectedTarget.type === 'boundary' ? 'is-active' : ''}`.trim()}
              title={plotBoundaryLabel.title}
              style={{
                left: `${plotBoundaryLabel.x}px`,
                top: `${plotBoundaryLabel.y}px`,
                width: `${plotBoundaryLabel.width}px`,
                height: `${plotBoundaryLabel.height}px`,
                borderRadius: `${plotBoundaryLabel.cornerRadius}px`,
                fontSize: `${plotBoundaryLabel.fontSize}px`,
                padding: `${plotBoundaryLabel.paddingY}px ${plotBoundaryLabel.paddingX}px`,
              }}
            >
              <span className="designer-plot-label-text">{plotBoundaryLabel.text}</span>
            </div>
          ) : null}
          {screenLabels.map(({ id, label, color, isActive }) => (
            <div
              key={id}
              className={`designer-zone-label designer-zone-label--${label.mode} ${isActive ? 'is-active' : ''}`.trim()}
              title={label.title}
              style={{
                left: `${label.x}px`,
                top: `${label.y}px`,
                width: `${label.width}px`,
                height: `${label.height}px`,
                borderColor: isActive ? 'rgba(185, 104, 63, 0.34)' : 'rgba(36, 49, 31, 0.12)',
                background: label.mode === 'marker'
                  ? 'rgba(255, 252, 248, 0.98)'
                  : label.mode === 'full'
                    ? 'rgba(255, 250, 242, 0.9)'
                    : 'rgba(255, 251, 246, 0.95)',
                color: label.mode === 'marker' ? color.stroke : '#24311f',
                boxShadow: isActive
                  ? '0 14px 28px rgba(185, 104, 63, 0.22)'
                  : '0 8px 18px rgba(36, 49, 31, 0.08)',
                borderLeftColor: label.mode === 'marker' ? 'rgba(255,255,255,0)' : color.stroke,
                borderRadius: `${label.cornerRadius}px`,
                fontSize: `${label.fontSize}px`,
                padding: `${label.paddingY}px ${label.paddingX}px`,
                transform: isActive ? 'translate3d(0, 0, 0) scale(1.02)' : 'translate3d(0, 0, 0)',
              }}
            >
              <span className="designer-zone-label-text">{label.text}</span>
            </div>
          ))}
          {dimensionLabels.map((label) => (
            <div
              key={label.id}
              className={`designer-dimension-label designer-dimension-label--${label.scope}`}
              title={label.title}
              style={{
                left: `${label.x}px`,
                top: `${label.y}px`,
                width: `${label.width}px`,
                height: `${label.height}px`,
                transform: `translate(-50%, -50%) rotate(${label.angle}deg)`,
              }}
            >
              {label.text}
            </div>
          ))}
        </div>
      </div>

      {zones.length > 0 ? (
        <div className="designer-legend" aria-label="Zone legend">
          {zones.map((zone, index) => {
            const zoneId = String(zone.id)
            const colors = getZoneColor(index)
            const label = screenLabels.find((entry) => entry.id === zoneId)?.label
            const isActive = selectedTarget.type === 'zone' && selectedTarget.id === zoneId

            return (
              <span
                key={`designer-legend-${zoneId}`}
                className={`designer-legend-item ${isActive ? 'is-active' : ''}`.trim()}
              >
                <span className="designer-legend-index">{index + 1}</span>
                <span
                  className="designer-legend-swatch"
                  style={{
                    background: colors.fill,
                    borderColor: colors.stroke,
                  }}
                />
                <span className="designer-legend-text">{zone.name}</span>
                {label?.mode === 'marker' || !label ? (
                  <span className="designer-legend-note">compact</span>
                ) : null}
              </span>
            )
          })}
        </div>
      ) : null}
    </div>
  )
}))
