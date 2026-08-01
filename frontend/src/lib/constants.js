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
export const INVENTORY_UNITS = ['unit', 'g', 'kg', 'ml', 'l', 'm', 'cm', 'bag', 'pack', 'm3']
export const MATERIAL_UNITS = INVENTORY_UNITS
export const TOOL_UNITS = ['unit']
export const INVENTORY_UNIT_LABELS = {
  unit: 'unit',
  units: 'units',
  pcs: 'pcs',
  piece: 'piece',
  pieces: 'pieces',
  g: 'g',
  gram: 'g',
  grams: 'g',
  kg: 'kg',
  kilogram: 'kg',
  kilograms: 'kg',
  ml: 'ml',
  milliliter: 'ml',
  milliliters: 'ml',
  millilitre: 'ml',
  millilitres: 'ml',
  l: 'l',
  liter: 'l',
  liters: 'l',
  litre: 'l',
  litres: 'l',
  m: 'm',
  meter: 'm',
  meters: 'm',
  metre: 'm',
  metres: 'm',
  cm: 'cm',
  centimeter: 'cm',
  centimeters: 'cm',
  centimetre: 'cm',
  centimetres: 'cm',
  bag: 'bag',
  bags: 'bags',
  pack: 'pak.',
  packs: 'pak.',
  package: 'pak.',
  packages: 'pak.',
  m3: 'm³',
}
export const ACCESS_ROLES = ['viewer', 'editor']
export const USER_ROLES = ['owner', 'admin']

const DISPLAY_LOCALE = 'en-US'
const NUMBER_LOCALE = 'en-US'
const DATE_FORMATTER = new Intl.DateTimeFormat(DISPLAY_LOCALE, {
  year: 'numeric',
  month: '2-digit',
  day: '2-digit',
})
const DATE_TIME_FORMATTER = new Intl.DateTimeFormat(DISPLAY_LOCALE, {
  year: 'numeric',
  month: '2-digit',
  day: '2-digit',
  hour: '2-digit',
  minute: '2-digit',
})

export function formatDate(value, options = {}) {
  if (!value) {
    return 'Not specified'
  }

  return Object.keys(options).length
    ? new Intl.DateTimeFormat(DISPLAY_LOCALE, options).format(new Date(value))
    : DATE_FORMATTER.format(new Date(value))
}

export function formatDateTime(value) {
  if (!value) {
    return 'Not specified'
  }

  return DATE_TIME_FORMATTER.format(new Date(value))
}

export function safeNumber(value, digits = 0) {
  if (value === null || value === undefined || Number.isNaN(Number(value))) {
    return '0'
  }

  return formatCompactNumber(value, digits, '0')
}

export function hasDisplayValue(value) {
  return value !== null && value !== undefined && value !== ''
}

export function formatDisplayValue(value, fallback = 'Not specified') {
  return hasDisplayValue(value) ? value : fallback
}

export function formatCompactNumber(value, digits = 0, fallback = 'Not specified') {
  if (!hasDisplayValue(value) || Number.isNaN(Number(value))) {
    return fallback
  }

  return new Intl.NumberFormat(NUMBER_LOCALE, {
    maximumFractionDigits: digits,
  }).format(Number(value))
}

export function formatDayCount(value, fallback = 'Not specified') {
  if (!hasDisplayValue(value) || Number.isNaN(Number(value))) {
    return fallback
  }

  const numeric = Number(value)
  const rounded = formatCompactNumber(numeric, Number.isInteger(numeric) ? 0 : 1)
  const absolute = Math.abs(numeric)
  const unit = absolute === 1 ? 'day' : 'days'
  return `${rounded} ${unit}`
}

export function formatTemperatureC(value, digits = 1, fallback = 'Not specified') {
  if (!hasDisplayValue(value) || Number.isNaN(Number(value))) {
    return fallback
  }

  return `${formatCompactNumber(value, digits)} °C`
}

export function formatArea(valueInSquareMeters, digits = 1, fallback = 'Not specified') {
  if (!hasDisplayValue(valueInSquareMeters) || Number.isNaN(Number(valueInSquareMeters))) {
    return fallback
  }

  const area = Number(valueInSquareMeters)
  const squareMeters = `${formatCompactNumber(area, Number.isInteger(area) ? 0 : digits)} m²`

  if (Math.abs(area) >= 10000) {
    return `${squareMeters} (${formatCompactNumber(area / 100, 1)} a / ${formatCompactNumber(area / 10000, 2)} ha)`
  }

  if (Math.abs(area) >= 100) {
    return `${squareMeters} (${formatCompactNumber(area / 100, 1)} a)`
  }

  return squareMeters
}

export function formatLength(valueInMeters, digits = 1, fallback = 'Not specified') {
  if (!hasDisplayValue(valueInMeters) || Number.isNaN(Number(valueInMeters))) {
    return fallback
  }

  return `${formatCompactNumber(valueInMeters, digits)} m`
}

export function formatSquareMetersValue(value, digits = 2, fallback = 'Not specified') {
  if (!hasDisplayValue(value) || Number.isNaN(Number(value))) {
    return fallback
  }

  return formatArea(value, digits, fallback)
}

export function formatNumberWithUnit(value, unit, digits = 0, fallback = 'Not specified') {
  if (!hasDisplayValue(value) || Number.isNaN(Number(value))) {
    return fallback
  }

  return `${formatCompactNumber(value, digits)} ${unit}`.trim()
}

export function formatInventoryUnit(unit) {
  return INVENTORY_UNIT_LABELS[String(unit ?? '').toLowerCase()] ?? unit ?? 'unit'
}

export function formatQuantity(value, unit, digits = 0, fallback = 'Not specified') {
  if (!hasDisplayValue(value) || Number.isNaN(Number(value))) {
    return fallback
  }

  return `${formatCompactNumber(value, digits)} ${formatInventoryUnit(unit)}`.trim()
}

export const SOIL_TYPE_LABELS = {
  clay: 'Clay',
  peaty: 'Peaty',
  rocky: 'Rocky',
  sandy: 'Sandy',
}

export const PLANT_TYPE_LABELS = {
  berry: 'Berry',
  cereal: 'Javai',
  flower: 'Flower',
  forage: 'Forage crop',
  fruit: 'Fruit',
  herb: 'Herb',
  legume: 'Legume',
  oilseed: 'Oilseed',
  shrub: 'Shrub',
  tree: 'Tree',
  vegetable: 'Vegetable',
}

export const PLANT_CONDITION_LABELS = {
  diseased: 'Diseased',
  dried: 'Withered',
  flowering: 'Flowering',
  germinating: 'Germinating',
  growing: 'Growing',
  healthy: 'Healthy',
  mature: 'Mature',
  planted: 'Planted',
  regenerating: 'Recovering',
  seedling: 'Seedling',
}

export const TASK_STATUS_LABELS = {
  pending: 'Pending',
  planned: 'Planned',
  completed: 'Completed',
  rejected: 'Removed',
}

export const TASK_TYPE_LABELS = {
  watering: 'Watering',
  fertilizing: 'Fertilizing',
  pest_check: 'Pest check',
  harvest: 'Harvest',
  buy: 'Purchase',
  frost_protection: 'Frost protection',
  heat_extra_watering: 'Extra watering in hot weather',
  wind_protection: 'Wind protection',
  lifecycle_review: 'Plant status review',
}

export const PRIORITY_LABELS = {
  low: 'Low',
  medium: 'Medium',
  high: 'High',
}

export const INVENTORY_TYPE_LABELS = {
  material: 'Material',
  tool: 'Tool',
}

export const ACCESS_ROLE_LABELS = {
  viewer: 'Viewer',
  editor: 'Editor',
  owner: 'Owner',
  admin: 'Administrator',
}

export const USER_ROLE_LABELS = {
  owner: 'Owner',
  admin: 'Administrator',
}

export const ROTATION_STATUS_LABELS = {
  assigned: 'Assigned',
  blocked: 'Blocked',
  generated: 'Generated',
  manual_override: 'Manual override',
  ready: 'Ready',
  rejected: 'Rejected',
  stays: 'Stays in place',
  unresolved: 'Unresolved',
}

export const SNAPSHOT_TYPE_LABELS = {
  created_plot_version: 'Created plot version',
  initial_plot_version: 'Created initial plot version',
  manual: 'Manual entry',
  saved_layout_update: 'Saved layout update',
  update: 'Saved update',
}

const SNAPSHOT_TEXT_TRANSLATIONS = {
  'Saved layout update': 'Saved layout update',
  'Layout updated, 3 zone addeds.': 'Layout updated, 3 zones added.',
  'Created plot version': 'Created plot version',
  'Initial plot version was created.': 'Initial plot version was created.',
}

// Database/API enum values stay unchanged; only their presentation is localized.
const ENGLISH_ENUM_LABELS = {
  planted: 'Planted', germinating: 'Germinating', growing: 'Growing', flowering: 'Flowering',
  mature: 'Mature', diseased: 'Diseased', dried: 'Withered', healthy: 'Healthy',
  regenerating: 'Recovering', seedling: 'Seedling',
  pending: 'Pending', planned: 'Planned', completed: 'Completed', rejected: 'Removed',
  low: 'Low', medium: 'Medium', high: 'High', material: 'Material', tool: 'Tool',
  viewer: 'Viewer', editor: 'Editor', owner: 'Owner', admin: 'Administrator',
  watering: 'Watering', fertilizing: 'Fertilizing', pest_check: 'Pest check', harvest: 'Harvest',
  buy: 'Purchase', lifecycle_review: 'Plant status review',
}

function formatMappedValue(value, labels, fallback = 'Not specified') {
  if (!hasDisplayValue(value)) {
    return fallback
  }

  const key = String(value).toLowerCase()
  return ENGLISH_ENUM_LABELS[key] ?? labels[key] ?? value
}

export function formatSoilType(type) {
  return formatMappedValue(type, SOIL_TYPE_LABELS)
}

export function formatPlantType(type) {
  return formatMappedValue(type, PLANT_TYPE_LABELS)
}

export function formatPlantCondition(condition) {
  return formatMappedValue(condition, PLANT_CONDITION_LABELS)
}

export function formatLifecycleStage(stage) {
  return formatPlantCondition(stage)
}

export function formatTaskStatus(status) {
  return formatMappedValue(status, TASK_STATUS_LABELS)
}

export function formatTaskType(type) {
  return formatMappedValue(type, TASK_TYPE_LABELS)
}

export function formatPriority(priority) {
  return formatMappedValue(priority, PRIORITY_LABELS)
}

export function formatInventoryType(type) {
  return formatMappedValue(type, INVENTORY_TYPE_LABELS)
}

export function formatAccessRole(role) {
  return formatMappedValue(role, ACCESS_ROLE_LABELS)
}

export function formatUserRole(role) {
  return formatMappedValue(role, USER_ROLE_LABELS)
}

export function formatRotationStatus(status) {
  return formatMappedValue(status, ROTATION_STATUS_LABELS)
}

export function formatSnapshotType(type) {
  return formatMappedValue(type, SNAPSHOT_TYPE_LABELS)
}

export function formatSnapshotText(value, fallback = 'Not specified') {
  if (!hasDisplayValue(value)) {
    return fallback
  }

  return SNAPSHOT_TEXT_TRANSLATIONS[value] ?? formatSnapshotType(value)
}

export function formatMonthYear(value) {
  if (!value) {
    return 'Not specified'
  }

  return new Intl.DateTimeFormat(DISPLAY_LOCALE, {
    month: 'long',
    year: 'numeric',
  }).format(new Date(value))
}
