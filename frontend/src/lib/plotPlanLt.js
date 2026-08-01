const STRINGS = {
  viewMode: 'View',
  editMode: 'Edit',
  addPlant: 'Add plant',
  changeColor: 'Change color',
  archiveZone: 'Archive zone',
  deleteZone: 'Delete zone',
  duplicateZone: 'Duplicate zone',
  viewInformation: 'View information',
  editZone: 'Edit zone',
  showPlants: 'Show plant markers',
  showZoneNames: 'Show zone names',
  bordersOnly: 'Zone borders only',
  unsavedChanges: 'Unsaved changes',
  resetFilters: 'Clear filters',
  noFilterResults: 'No results match the selected filters.',
  noRecommendedTask: 'No recommended work',
  plantInformation: 'Plant information',
  viewPlantInformation: 'View plant information',
  editPlanting: 'Edit planting',
  morePlantings: '+{count}',
  activePlantings: '{count} active plantings',
  archived: 'Archived',
}

export function plotPlanText(key, values = {}) {
  return Object.entries(values).reduce(
    (value, [name, replacement]) => value.replaceAll(`{${name}}`, String(replacement)),
    STRINGS[key] ?? key,
  )
}

export { STRINGS as PLOT_PLAN_LT }
