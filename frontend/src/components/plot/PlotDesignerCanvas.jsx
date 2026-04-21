import { forwardRef, memo, useEffect, useImperativeHandle, useMemo, useRef, useState } from 'react'
import { Circle, Group, Layer, Line, Rect, Stage, Text } from 'react-konva'
import Button from '../ui/Button.jsx'
import { safeNumber } from '../../lib/constants.js'
import { getZoneLabelConfig } from '../../lib/plotCanvasLabels.js'
import { getZoneColor } from '../../lib/plotRender.js'
import {
  GRID_SIZE,
  MAX_ZOOM,
  MIN_BOUNDARY_EDGE,
  MIN_ZOOM,
  MIN_ZONE_EDGE,
  buildDesignerStateFromPersistence,
  calculateArea,
  clearDesignerState,
  createDefaultZoneShape,
  createRectShape,
  createViewportToFit,
  estimateBoundaryFromArea,
  fitShapeInsideBoundary,
  getConstrainedTranslation,
  getConstrainedVertexMove,
  getShapeBounds,
  getShapePoints,
  isShapeInsideBoundary,
  loadDesignerState,
  mergeZoneLayouts,
  projectPointToPolygon,
  sanitizeBoundary,
  sanitizeShape,
  saveDesignerState,
  snapShapeToGrid,
  translateLayouts,
  translateShape,
  updateShapePoint,
} from '../../lib/plotDesigner.js'

const LABEL_FONT_PIXELS = 13
const HANDLE_RADIUS_PIXELS = 8
const HANDLE_HIT_PIXELS = 18
const SHAPE_HIT_PIXELS = 18
const POINTER_SLOP_PIXELS = 6

function isStageBackground(target) {
  const name = target?.name?.() ?? ''
  return !target || target === target.getStage() || name === 'canvas-hit-area' || name === 'grid-line'
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

export default memo(forwardRef(function PlotDesignerCanvas({
  plotId,
  plotSize,
  plotGeometry,
  zones,
  plants,
  canEdit,
  activeZoneId,
  isLayoutSaveDisabled,
  isLayoutSaving,
  layoutSaveFeedback,
  onSaveLayout,
  onSelectZone,
  onCreateZone,
  onZoneGeometryCommit,
  onBoundaryCommit,
}, ref) {
  const containerRef = useRef(null)
  const stageRef = useRef(null)
  const panRef = useRef(null)
  const backgroundPointerRef = useRef(null)
  const zoneDragSessionRef = useRef(null)
  const viewportRef = useRef({ x: 0, y: 0, scale: 1 })
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
  const [mode, setMode] = useState('select')
  const [snapEnabled, setSnapEnabled] = useState(true)
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

  function applyViewport(nextViewport, commitState = true) {
    const rounded = roundViewport(nextViewport)
    viewportRef.current = rounded

    if (stageRef.current) {
      stageRef.current.position({ x: rounded.x, y: rounded.y })
      stageRef.current.scale({ x: rounded.scale, y: rounded.scale })
      stageRef.current.batchDraw()
    }

    if (commitState) {
      setViewport(rounded)
    }
  }

  function toWorldPoint(pointer, activeViewport = viewportRef.current) {
    return {
      x: (pointer.x - activeViewport.x) / activeViewport.scale,
      y: (pointer.y - activeViewport.y) / activeViewport.scale,
    }
  }

  function fitView() {
    const nextViewport = createViewportToFit(boundary, layouts, canvasSize)
    applyViewport(nextViewport)
  }

  function selectBoundary() {
    setSelectedTarget({ type: 'boundary', id: null })
    onSelectZone(null)
  }

  function clearSelection() {
    setSelectedTarget({ type: 'none', id: null })
    onSelectZone(null)
  }

  function selectZone(zoneId) {
    const zone = zonesById[String(zoneId)]
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

  function resolveZoneShape(shape, boundaryShape = boundary) {
    const snappedShape = snapEnabled ? snapShapeToGrid(shape, boundaryShape) : shape
    return sanitizeShape(snappedShape, boundaryShape, shape)
  }

  function commitZoneLayout(zoneId, shape, boundaryShape = boundary) {
    const adjusted = resolveZoneShape(shape, boundaryShape)
    const activeLayouts = liveLayoutsRef.current ?? layouts
    const nextLayouts = {
      ...activeLayouts,
      [zoneId]: adjusted,
    }

    liveLayoutsRef.current = nextLayouts
    setDesignerState((current) => ({
      ...current,
      layouts: {
        ...current.layouts,
        [zoneId]: adjusted,
      },
    }))

    onZoneGeometryCommit(zoneId, adjusted, boundaryShape)
  }

  function commitBoundaryLayout(nextBoundaryShape, nextLayoutsShape) {
    const snappedBoundary = snapEnabled
      ? sanitizeBoundary(snapShapeToGrid(nextBoundaryShape), nextBoundaryShape)
      : sanitizeBoundary(nextBoundaryShape, boundary)

    const adjustedLayouts = Object.fromEntries(
      Object.entries(nextLayoutsShape).map(([zoneId, shape]) => [
        zoneId,
        resolveZoneShape(fitShapeInsideBoundary(shape, snappedBoundary), snappedBoundary),
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
    setMode('select')
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
    const nextShape = resolveZoneShape(shape, boundaryShape)

    if (calculateArea(nextShape) < MIN_ZONE_EDGE * MIN_ZONE_EDGE * 0.6) {
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
    setMode('select')
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
      anchorPoint: toWorldPoint(pointer),
      moved: false,
    }
    selectZone(zoneId)
    updateCursor('grabbing')
  }

  function updateZoneDrag(pointer) {
    const session = zoneDragSessionRef.current

    if (!session) {
      return
    }

    const currentPoint = toWorldPoint(pointer)
    const requestedOffset = {
      x: currentPoint.x - session.anchorPoint.x,
      y: currentPoint.y - session.anchorPoint.y,
    }
    const constrainedOffset = getConstrainedTranslation(
      session.originShape,
      session.boundary,
      requestedOffset,
    )
    const nextShape = translateShape(session.originShape, constrainedOffset)
    const activeLayouts = liveLayoutsRef.current ?? renderedLayouts

    zoneDragSessionRef.current = {
      ...session,
      moved: session.moved || Math.abs(constrainedOffset.x) > 0.01 || Math.abs(constrainedOffset.y) > 0.01,
      currentShape: nextShape,
    }
    liveLayoutsRef.current = {
      ...activeLayouts,
      [session.zoneId]: nextShape,
    }
    requestVisualRefresh()
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
    const storedState = loadDesignerState(plotId)
    const nextState = buildDesignerStateFromPersistence({
      plotSize,
      plotGeometry,
      zones,
      storedState,
    })

    replaceDesignerState(nextState.boundary, nextState.layouts)
    setMode('select')
    setFitSeed((current) => current + 1)
  }, [geometrySignature, plotId, plotSize])

  useEffect(() => {
    setDesignerState((current) => ({
      ...current,
      layouts: mergeZoneLayouts(zones, current.boundary, current.layouts),
    }))
  }, [zoneIdsKey, zones])

  useEffect(() => {
    if (activeZoneId) {
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
    saveDesignerState(plotId, designerState)
  }, [designerState, plotId])

  useEffect(() => {
    const signature = `${fitSeed}:${canvasSize.width}:${canvasSize.height}`

    if (!canvasSize.width || !canvasSize.height || autoFitSignatureRef.current === signature) {
      return
    }

    autoFitSignatureRef.current = signature
    applyViewport(fitViewport)
  }, [canvasSize.height, canvasSize.width, fitSeed, fitViewport])

  useEffect(() => {
    viewportRef.current = viewport
  }, [viewport])

  useEffect(() => {
    updateCursor(mode === 'draw-zone' ? 'crosshair' : isPanning ? 'grabbing' : 'grab')
  }, [isPanning, mode])

  useEffect(() => () => {
    if (redrawFrameRef.current) {
      window.cancelAnimationFrame(redrawFrameRef.current)
    }
  }, [])

  function handleWheel(event) {
    event.evt.preventDefault()

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

  function handleStageMouseDown(event) {
    const stage = event.target.getStage()
    const pointer = stage?.getPointerPosition()

    if (!pointer) {
      return
    }

    if (mode === 'draw-zone' && canEdit && isStageBackground(event.target)) {
      const worldPoint = projectPointToPolygon(toWorldPoint(pointer), renderedBoundary)
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
    }
  }

  async function handleStageMouseUp() {
    const backgroundPointer = backgroundPointerRef.current
    const zoneWasDragging = Boolean(zoneDragSessionRef.current)

    backgroundPointerRef.current = null

    if (draftSessionRef.current?.shape) {
      const nextShape = draftSessionRef.current.shape
      draftSessionRef.current = null
      setDraftZone(null)

      try {
        await createZoneFromShape(nextShape, liveBoundaryRef.current ?? renderedBoundary)
      } catch {
        setMode('select')
      }
    }

    if (zoneWasDragging) {
      finishZoneDrag()
      updateCursor('grab')
    }

    if (panRef.current) {
      setViewport(viewportRef.current)
    }

    panRef.current = null
    setIsPanning(false)

    if (backgroundPointer && !backgroundPointer.moved && !draftSessionRef.current && !zoneWasDragging) {
      clearSelection()
    }

    updateCursor(mode === 'draw-zone' ? 'crosshair' : 'grab')
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
  const boundaryBounds = getShapeBounds(renderedBoundary)
  const labelFontSize = Math.max(LABEL_FONT_PIXELS / viewport.scale, 0.34)
  const strokeWidth = Math.max(2 / viewport.scale, 0.08)
  const activeStrokeWidth = Math.max(3 / viewport.scale, 0.11)
  const handleRadius = Math.max(HANDLE_RADIUS_PIXELS / viewport.scale, 0.16)
  const handleHitWidth = Math.max(HANDLE_HIT_PIXELS / viewport.scale, handleRadius * 2.5)
  const hitStrokeWidth = Math.max(SHAPE_HIT_PIXELS / viewport.scale, strokeWidth * 3)
  const saveStatus = isLayoutSaving
    ? { className: 'badge badge-warning', text: 'Saving layout...' }
    : layoutSaveFeedback?.type === 'error'
      ? { className: 'badge badge-danger', text: layoutSaveFeedback.message }
      : layoutSaveFeedback?.type === 'success'
        ? { className: 'badge badge-success', text: layoutSaveFeedback.message }
        : null

  return (
    <div className="designer-panel">
      <div className="designer-toolbar">
        <div className="inline-actions">
          <Button
            variant={mode === 'select' ? 'primary' : 'secondary'}
            onClick={() => setMode('select')}
          >
            Select
          </Button>
          {canEdit ? (
            <Button
              variant={mode === 'draw-zone' ? 'primary' : 'secondary'}
              onClick={() => {
                setMode((current) => (current === 'draw-zone' ? 'select' : 'draw-zone'))
              }}
            >
              Draw zone
            </Button>
          ) : null}
          <Button variant="ghost" onClick={fitView}>Fit to view</Button>
          <Button variant="ghost" onClick={resetDesignerLayout}>Reset layout</Button>
        </div>

        <div className="designer-toolbar-actions">
          {canEdit ? (
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
        </div>
      </div>

      <div className="designer-meta">
        <span>Plot {safeNumber(calculateArea(renderedBoundary), 1)} m2</span>
        <span>
          Bounds {safeNumber(boundaryBounds.width, 1)}m x {safeNumber(boundaryBounds.height, 1)}m
        </span>
        <span>Zoom {safeNumber(viewport.scale, 2)}x</span>
        <span>{zones.length} zones</span>
        <span>
          {selectedZone
            ? `${selectedZone.name} - ${plantCountsByZoneId[selectedTarget.id] ?? 0} plants`
            : selectedTarget.type === 'boundary'
              ? 'Plot boundary selected'
              : mode === 'draw-zone'
                ? 'Drag inside the plot to draw a zone'
                : 'Drag the background to pan'}
        </span>
      </div>

      <div ref={containerRef} className={`designer-stage ${mode === 'draw-zone' ? 'is-drawing' : ''}`}>
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
              draggable={canEdit && selectedTarget.type === 'boundary' && mode !== 'draw-zone'}
              onClick={(event) => {
                event.cancelBubble = true
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
                  const nextBoundary = translateShape(boundary, offset)
                  const nextLayouts = translateLayouts(layouts, offset)
                  commitBoundaryLayout(nextBoundary, nextLayouts)
                }
              }}
            >
              <Line
                points={flattenPoints(getShapePoints(renderedBoundary))}
                closed
                fill="rgba(255, 250, 242, 0.82)"
                stroke={selectedTarget.type === 'boundary' ? '#b9683f' : '#47633b'}
                strokeWidth={selectedTarget.type === 'boundary' ? activeStrokeWidth : strokeWidth}
                hitStrokeWidth={hitStrokeWidth}
                shadowColor={selectedTarget.type === 'boundary' ? 'rgba(185, 104, 63, 0.38)' : 'rgba(71, 99, 59, 0.14)'}
                shadowBlur={selectedTarget.type === 'boundary' ? 0.9 : 0.25}
                shadowOpacity={selectedTarget.type === 'boundary' ? 0.65 : 0.3}
                shadowOffsetY={0.08}
              />

              <Text
                x={boundaryBounds.left}
                y={boundaryBounds.top - (labelFontSize * 1.8)}
                text={`Plot boundary | ${safeNumber(calculateArea(renderedBoundary), 1)} m2`}
                fontSize={labelFontSize}
                fill="#47633b"
                listening={false}
              />
            </Group>

            {zones.map((zone, index) => {
              const zoneId = String(zone.id)
              const shape = renderedLayouts[zoneId]

              if (!shape) {
                return null
              }

              const points = getShapePoints(shape)
              const bounds = getShapeBounds(shape)
              const colors = getZoneColor(index)
              const isActive = selectedTarget.type === 'zone' && selectedTarget.id === zoneId
              const isHovered = hoveredZoneId === zoneId
              const labelConfig = getZoneLabelConfig(zone.name, bounds, viewport.scale)

              return (
                <Group
                  key={zone.id}
                  onMouseDown={(event) => {
                    event.cancelBubble = true

                    if (!canEdit || mode === 'draw-zone') {
                      return
                    }

                    const pointer = event.target.getStage()?.getPointerPosition()

                    if (pointer) {
                      beginZoneDrag(zoneId, pointer)
                    }
                  }}
                  onClick={(event) => {
                    event.cancelBubble = true
                    selectZone(zone.id)
                  }}
                  onTap={() => selectZone(zone.id)}
                  onMouseEnter={() => {
                    setHoveredZoneId(zoneId)
                    updateCursor(canEdit ? 'grab' : 'pointer')
                  }}
                  onMouseLeave={() => {
                    setHoveredZoneId(null)
                    updateCursor(mode === 'draw-zone' ? 'crosshair' : isPanning ? 'grabbing' : 'grab')
                  }}
                >
                  <Line
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
                  {labelConfig ? (
                    <>
                      <Rect
                        x={labelConfig.x}
                        y={labelConfig.y}
                        width={labelConfig.width}
                        height={labelConfig.height}
                        cornerRadius={labelConfig.cornerRadius}
                        fill={labelConfig.variant === 'compact' ? 'rgba(255, 250, 242, 0.92)' : 'rgba(255, 250, 242, 0.82)'}
                        stroke={isActive ? 'rgba(185, 104, 63, 0.35)' : 'rgba(36, 49, 31, 0.12)'}
                        strokeWidth={Math.max(1 / viewport.scale, 0.04)}
                        listening={false}
                      />
                      <Text
                        x={labelConfig.x}
                        y={labelConfig.y}
                        width={labelConfig.width}
                        height={labelConfig.height}
                        padding={labelConfig.padding}
                        text={labelConfig.text}
                        fontSize={labelConfig.fontSize}
                        fontStyle="bold"
                        fill="#24311f"
                        align="center"
                        verticalAlign="middle"
                        wrap="none"
                        ellipsis
                        listening={false}
                      />
                    </>
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

            {canEdit && selectedTarget.type === 'boundary'
              ? getShapePoints(renderedBoundary).map((point, index) => (
                <Circle
                  key={`boundary-handle-${index}`}
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

            {canEdit && selectedTarget.type === 'zone' && selectedTarget.id && selectedShape
              ? getShapePoints(selectedShape).map((point, index) => (
                <Circle
                  key={`zone-handle-${selectedTarget.id}-${index}`}
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
                  onDragStart={() => updateCursor('grabbing')}
                  onDragMove={(event) => {
                    const activeLayouts = liveLayoutsRef.current ?? renderedLayouts
                    const activeShape = liveLayoutsRef.current?.[selectedTarget.id] ?? selectedShape
                    const nextPoint = getConstrainedVertexMove(
                      activeShape,
                      index,
                      { x: event.target.x(), y: event.target.y() },
                      renderedBoundary,
                    )
                    const nextShape = updateShapePoint(activeShape, index, nextPoint)
                    event.target.position(nextPoint)
                    liveLayoutsRef.current = {
                      ...activeLayouts,
                      [selectedTarget.id]: nextShape,
                    }
                    requestVisualRefresh()
                  }}
                  onDragEnd={(event) => {
                    const activeShape = liveLayoutsRef.current?.[selectedTarget.id] ?? selectedShape
                    const nextPoint = getConstrainedVertexMove(
                      activeShape,
                      index,
                      { x: event.target.x(), y: event.target.y() },
                      renderedBoundary,
                    )
                    const nextShape = updateShapePoint(activeShape, index, nextPoint)
                    updateCursor('grab')
                    commitZoneLayout(selectedTarget.id, nextShape, renderedBoundary)
                  }}
                />
              ))
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
      </div>
    </div>
  )
}))
