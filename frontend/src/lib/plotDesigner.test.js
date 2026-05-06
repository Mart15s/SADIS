import { describe, expect, it } from 'vitest'
import {
  buildDesignerStateFromPersistence,
  createRectShape,
  createDefaultZoneShape,
  doShapesOverlap,
  fitShapeInsideBoundary,
  getConstrainedTranslation,
  getConstrainedVertexMove,
  getShapeEdgeMidpoints,
  getShapeBounds,
  insertShapePoint,
  pointInPolygon,
  resolveTranslatedShape,
  isZonePlacementValid,
  shapeToGeometry,
  updateShapePoint,
  resizeShapeByHandle,
  ZONE_VERTEX_HANDLES,
} from './plotDesigner.js'

describe('fitShapeInsideBoundary', () => {
  it('clamps shapes flush against the right and bottom plot edges', () => {
    const boundary = createRectShape({ x: 0, y: 0, width: 10, height: 10 })
    const overflowingShape = createRectShape({ x: 8.8, y: 8.6, width: 2, height: 2 })

    const fitted = fitShapeInsideBoundary(overflowingShape, boundary)
    const bounds = getShapeBounds(fitted)

    expect(bounds.right).toBe(10)
    expect(bounds.bottom).toBe(10)
    expect(bounds.left).toBe(8)
    expect(bounds.top).toBe(8)
  })

  it('clamps shapes flush against the left and top plot edges symmetrically', () => {
    const boundary = createRectShape({ x: 0, y: 0, width: 10, height: 10 })
    const overflowingShape = createRectShape({ x: -0.8, y: -1.2, width: 2, height: 2 })

    const fitted = fitShapeInsideBoundary(overflowingShape, boundary)
    const bounds = getShapeBounds(fitted)

    expect(bounds.left).toBe(0)
    expect(bounds.top).toBe(0)
    expect(bounds.right).toBe(2)
    expect(bounds.bottom).toBe(2)
  })
})

describe('resizeShapeByHandle', () => {
  it('lets the right edge resize exactly to the plot boundary', () => {
    const boundary = createRectShape({ x: 0, y: 0, width: 10, height: 10 })
    const shape = createRectShape({ x: 2, y: 2, width: 3, height: 3 })

    const resized = resizeShapeByHandle(shape, 'e', { x: 10, y: 3.5 }, boundary)
    const bounds = getShapeBounds(resized)

    expect(bounds.left).toBe(2)
    expect(bounds.right).toBe(10)
  })

  it('keeps resized zones inside the normalized plot-local boundary', () => {
    const boundary = {
      kind: 'polygon',
      points: [
        { x: 0, y: 0 },
        { x: 1, y: 0 },
        { x: 1, y: 1 },
        { x: 0, y: 1 },
      ],
    }
    const shape = {
      kind: 'polygon',
      points: [
        { x: 0.25, y: 0.25 },
        { x: 0.5, y: 0.25 },
        { x: 0.5, y: 0.5 },
        { x: 0.25, y: 0.5 },
      ],
    }

    const resized = resizeShapeByHandle(shape, 'se', { x: 2, y: 2 }, boundary, 0.000001, 0.05)
    const bounds = getShapeBounds(resized)

    expect(bounds.right).toBeLessThanOrEqual(1)
    expect(bounds.bottom).toBeLessThanOrEqual(1)
    expect(bounds.left).toBeGreaterThanOrEqual(0)
    expect(bounds.top).toBeGreaterThanOrEqual(0)
  })

  it('does not expand on release when the handle position is unchanged', () => {
    const boundary = createRectShape({ x: 0, y: 0, width: 10, height: 10 })
    const shape = createRectShape({ x: 2, y: 2, width: 3, height: 3 })
    const moved = resizeShapeByHandle(shape, 'se', { x: 7, y: 7 }, boundary)
    const released = resizeShapeByHandle(moved, 'se', { x: 7, y: 7 }, boundary)

    expect(getShapeBounds(released)).toEqual(getShapeBounds(moved))
  })
})

describe('polygon vertex editing', () => {
  it('exposes exactly four zone vertex handles for the editor flow', () => {
    expect(ZONE_VERTEX_HANDLES).toEqual([0, 1, 2, 3])
  })

  it('moves only the selected polygon corner instead of rebuilding from bounds', () => {
    const boundary = createRectShape({ x: 0, y: 0, width: 20, height: 20 })
    const shape = {
      kind: 'polygon',
      points: [
        { x: 2, y: 2 },
        { x: 7, y: 2 },
        { x: 6, y: 6 },
        { x: 2, y: 7 },
      ],
    }

    const nextPoint = getConstrainedVertexMove(shape, 2, { x: 9, y: 8 }, boundary)
    const edited = updateShapePoint(shape, 2, nextPoint)

    expect(edited.points).toEqual([
      { x: 2, y: 2 },
      { x: 7, y: 2 },
      { x: 9, y: 8 },
      { x: 2, y: 7 },
    ])
  })

  it('clamps a vertex at the last valid point before overlapping another zone', () => {
    const boundary = createRectShape({ x: 0, y: 0, width: 20, height: 20 })
    const shape = createRectShape({ x: 2, y: 2, width: 4, height: 4 })
    const neighbor = createRectShape({ x: 8, y: 2, width: 4, height: 4 })
    const nextPoint = getConstrainedVertexMove(
      shape,
      1,
      { x: 10, y: 2 },
      boundary,
      0.1,
      (candidate) => !doShapesOverlap(candidate, neighbor),
    )
    const edited = updateShapePoint(shape, 1, nextPoint)

    expect(doShapesOverlap(edited, neighbor)).toBe(false)
    expect(nextPoint.x).toBeLessThanOrEqual(8)
  })

  it('clamps zone dragging before overlap instead of accepting then rolling back', () => {
    const boundary = createRectShape({ x: 0, y: 0, width: 20, height: 20 })
    const shape = createRectShape({ x: 2, y: 2, width: 4, height: 4 })
    const neighbor = createRectShape({ x: 8, y: 2, width: 4, height: 4 })
    const offset = getConstrainedTranslation(
      shape,
      boundary,
      { x: 8, y: 0 },
      (candidate) => !doShapesOverlap(candidate, neighbor),
    )

    expect(offset.x).toBeLessThanOrEqual(2)
  })
})

describe('geometry validation helpers', () => {
  it('treats points on polygon edges as inside the plot boundary', () => {
    const boundary = createRectShape({ x: 0, y: 0, width: 10, height: 10 })

    expect(pointInPolygon({ x: 5, y: 5 }, boundary)).toBe(true)
    expect(pointInPolygon({ x: 10, y: 4 }, boundary)).toBe(true)
    expect(pointInPolygon({ x: 12, y: 4 }, boundary)).toBe(false)
  })

  it('adds a boundary corner exactly on the selected edge midpoint', () => {
    const boundary = createRectShape({ x: 0, y: 0, width: 10, height: 10 })
    const [topEdge] = getShapeEdgeMidpoints(boundary)
    const edited = insertShapePoint(boundary, topEdge.index, topEdge.point)

    expect(edited.points).toHaveLength(5)
    expect(edited.points[1]).toEqual({ x: 5, y: 0 })
  })

  it('keeps the last valid drag position when the requested position collides', () => {
    const boundary = createRectShape({ x: 0, y: 0, width: 20, height: 20 })
    const shape = createRectShape({ x: 2, y: 2, width: 4, height: 4 })
    const neighbor = createRectShape({ x: 8, y: 2, width: 4, height: 4 })
    const lastValid = createRectShape({ x: 3, y: 2, width: 4, height: 4 })

    const nextShape = resolveTranslatedShape(
      shape,
      boundary,
      { x: 4, y: 0 },
      lastValid,
      (candidate) => !doShapesOverlap(candidate, neighbor),
    )

    expect(getShapeBounds(nextShape)).toEqual(getShapeBounds(lastValid))
  })
})

describe('default zone placement', () => {
  it('moves a new default zone to the nearest valid placement when the standard area is occupied', () => {
    const boundary = createRectShape({ x: 0, y: 0, width: 20, height: 20 })
    const firstZone = createDefaultZoneShape(boundary, [])
    const nextZone = createDefaultZoneShape(boundary, [firstZone])

    expect(nextZone).not.toBeNull()
    expect(isZonePlacementValid(nextZone, boundary, [firstZone])).toBe(true)
    expect(doShapesOverlap(nextZone, firstZone)).toBe(false)
  })

  it('refuses to create a default zone when no valid non-overlapping placement exists', () => {
    const boundary = createRectShape({ x: 0, y: 0, width: 20, height: 20 })
    const occupiedPlot = createRectShape({ x: 0, y: 0, width: 20, height: 20 })

    expect(createDefaultZoneShape(boundary, [occupiedPlot])).toBeNull()
  })
})

describe('designer state reconciliation', () => {
  it('preserves existing draft layouts when a newly-added zone has geometry', () => {
    const boundary = createRectShape({ x: 0, y: 0, width: 30, height: 20 })
    const existingLayouts = {
      1: createRectShape({ x: 1, y: 1, width: 5, height: 5 }),
      2: createRectShape({ x: 8, y: 1, width: 5, height: 5 }),
      3: createRectShape({ x: 15, y: 1, width: 5, height: 5 }),
    }
    const newShape = createRectShape({ x: 22, y: 1, width: 5, height: 5 })
    const zones = [
      { id: 1, name: 'Zone 1', zone_size: 25, geometry: null },
      { id: 2, name: 'Zone 2', zone_size: 25, geometry: null },
      { id: 3, name: 'Zone 3', zone_size: 25, geometry: null },
      {
        id: 'draft-zone-new',
        client_id: 'draft-zone-new',
        name: 'Zone 4',
        zone_size: 25,
        geometry: shapeToGeometry(newShape, boundary),
      },
    ]

    const state = buildDesignerStateFromPersistence({
      plotSize: 600,
      plotGeometry: null,
      zones,
      storedState: {
        boundary,
        layouts: existingLayouts,
      },
    })

    expect(state.layouts[1]).toEqual(existingLayouts[1])
    expect(state.layouts[2]).toEqual(existingLayouts[2])
    expect(state.layouts[3]).toEqual(existingLayouts[3])
    expect(getShapeBounds(state.layouts['draft-zone-new'])).toEqual(getShapeBounds(newShape))
  })
})
