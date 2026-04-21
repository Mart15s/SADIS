export const SOIL_TYPES = ['clay', 'peaty', 'rocky', 'sandy']
export const PLANT_TYPES = ['berry', 'cereal', 'flower', 'forage', 'fruit', 'herb', 'legume', 'oilseed', 'shrub', 'tree', 'vegetable']
export const CONDITION_TYPES = [
  'diseased',
  'dried',
  'flowering',
  'germinating',
  'growing',
  'mature',
  'planted',
  'regenerating',
]
export const INVENTORY_TYPES = ['material', 'tool']
export const INVENTORY_UNITS = ['unit', 'g', 'kg', 'ml', 'l', 'bag', 'pack', 'm3']
export const MATERIAL_UNITS = INVENTORY_UNITS
export const TOOL_UNITS = ['unit']
export const INVENTORY_UNIT_LABELS = {
  unit: 'vnt.',
  g: 'g',
  kg: 'kg',
  ml: 'ml',
  l: 'l',
  bag: 'bag',
  pack: 'pack',
  m3: 'm3',
}
export const ACCESS_ROLES = ['viewer', 'editor']
export const USER_ROLES = ['owner', 'admin']

export function formatDate(value, options = {}) {
  if (!value) {
    return 'Not set'
  }

  return new Date(value).toLocaleDateString(undefined, options)
}

export function formatDateTime(value) {
  if (!value) {
    return 'Not set'
  }

  return new Date(value).toLocaleString()
}

export function safeNumber(value, digits = 0) {
  if (value === null || value === undefined || Number.isNaN(Number(value))) {
    return '0'
  }

  return Number(value).toFixed(digits)
}

export function formatInventoryUnit(unit) {
  return INVENTORY_UNIT_LABELS[unit] ?? unit ?? 'vnt.'
}
