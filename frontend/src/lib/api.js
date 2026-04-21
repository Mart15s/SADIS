import axios from 'axios'
import { getAuthToken } from './auth.js'

let unauthorizedHandler = null

function normalizeMessage(payload) {
  if (typeof payload?.message === 'string' && payload.message.trim()) {
    return payload.message
  }

  const validationMessages = payload?.errors
    ? Object.values(payload.errors).flat().filter(Boolean)
    : []

  if (validationMessages.length > 0) {
    return validationMessages[0]
  }

  return 'Request failed. Please try again.'
}

function toApiError(error) {
  if (!error.response) {
    return Object.assign(new Error('Unable to reach the API.'), {
      status: 0,
      details: null,
      original: error,
    })
  }

  return Object.assign(new Error(normalizeMessage(error.response.data)), {
    status: error.response.status,
    details: error.response.data?.errors ?? null,
    original: error,
  })
}

export function registerUnauthorizedHandler(handler) {
  unauthorizedHandler = handler

  return () => {
    if (unauthorizedHandler === handler) {
      unauthorizedHandler = null
    }
  }
}

export function unwrapCollection(payload) {
  if (Array.isArray(payload)) {
    return payload
  }

  if (Array.isArray(payload?.data)) {
    return payload.data
  }

  return payload
}

export const apiClient = axios.create({
  baseURL: '/api',
  withCredentials: true,
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
})

apiClient.interceptors.request.use((config) => {
  const token = getAuthToken()

  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }

  return config
})

apiClient.interceptors.response.use(
  (response) => response,
  (error) => {
    if ([401, 419].includes(error.response?.status) && unauthorizedHandler) {
      unauthorizedHandler()
    }

    return Promise.reject(toApiError(error))
  },
)

function fileNameFromDisposition(dispositionHeader, fallback) {
  const match = dispositionHeader?.match(/filename="?([^"]+)"?/)
  return match?.[1] ?? fallback
}

export async function downloadBlob(path, fallbackFileName) {
  const response = await apiClient.get(path, {
    responseType: 'blob',
  })
  const fileName = fileNameFromDisposition(
    response.headers['content-disposition'],
    fallbackFileName,
  )
  const url = window.URL.createObjectURL(response.data)
  const link = document.createElement('a')

  link.href = url
  link.download = fileName
  document.body.appendChild(link)
  link.click()
  link.remove()
  window.URL.revokeObjectURL(url)
}

export const api = {
  async getMe() {
    const { data } = await apiClient.get('/me')
    return data?.data ?? data
  },
  async updateMe(payload) {
    const { data } = await apiClient.patch('/me', payload)
    return data?.data ?? data
  },
  async login(payload) {
    const { data } = await apiClient.post('/login', payload)
    return data
  },
  async register(payload) {
    const { data } = await apiClient.post('/register', payload)
    return data
  },
  async forgotPassword(payload) {
    const { data } = await apiClient.post('/forgot-password', payload)
    return data
  },
  async resetPassword(payload) {
    const { data } = await apiClient.post('/reset-password', payload)
    return data
  },
  async logout() {
    const { data } = await apiClient.post('/logout')
    return data
  },
  async listPlots() {
    const { data } = await apiClient.get('/plots')
    return unwrapCollection(data)
  },
  async getPlot(plotId) {
    const { data } = await apiClient.get(`/plots/${plotId}`)
    return data
  },
  async createPlot(payload) {
    const { data } = await apiClient.post('/plots', payload)
    return data
  },
  async updatePlot(plotId, payload) {
    const { data } = await apiClient.patch(`/plots/${plotId}`, payload)
    return data
  },
  async deletePlot(plotId) {
    await apiClient.delete(`/plots/${plotId}`)
  },
  async listPlantZones(plotId) {
    const { data } = await apiClient.get(`/plots/${plotId}/plant-zones`)
    return unwrapCollection(data)
  },
  async createPlantZone(plotId, payload) {
    const { data } = await apiClient.post(`/plots/${plotId}/plant-zones`, payload)
    return data
  },
  async updatePlantZone(plotId, zoneId, payload) {
    const { data } = await apiClient.patch(`/plots/${plotId}/plant-zones/${zoneId}`, payload)
    return data
  },
  async deletePlantZone(plotId, zoneId) {
    await apiClient.delete(`/plots/${plotId}/plant-zones/${zoneId}`)
  },
  async listPlants(plotId) {
    const { data } = await apiClient.get(`/plots/${plotId}/plants`)
    return unwrapCollection(data)
  },
  async listManagedPlants(params = {}) {
    const { data } = await apiClient.get('/plants', { params })
    return unwrapCollection(data)
  },
  async listCatalogPlants(params = {}) {
    const { data } = await apiClient.get('/catalog-plants', { params })
    return unwrapCollection(data)
  },
  async getCatalogPlant(catalogPlantId) {
    const { data } = await apiClient.get(`/catalog-plants/${catalogPlantId}`)
    return data?.data ?? data
  },
  async createCatalogPlant(payload) {
    const { data } = await apiClient.post('/catalog-plants', payload)
    return data?.data ?? data
  },
  async updateCatalogPlant(catalogPlantId, payload) {
    const { data } = await apiClient.patch(`/catalog-plants/${catalogPlantId}`, payload)
    return data?.data ?? data
  },
  async deleteCatalogPlant(catalogPlantId) {
    await apiClient.delete(`/catalog-plants/${catalogPlantId}`)
  },
  async searchPlants(query) {
    const { data } = await apiClient.get('/catalog-plants', {
      params: query ? { q: query } : {},
    })
    return unwrapCollection(data)
  },
  async searchPerenualPlants(query, options = {}) {
    const { data } = await apiClient.get('/catalog-plants/perenual/search', {
      params: {
        q: query,
        ...(options.limit ? { limit: options.limit } : {}),
      },
    })
    return data
  },
  async previewPerenualCatalogPlant(speciesId) {
    const { data } = await apiClient.get(`/catalog-plants/perenual/species/${speciesId}`)
    return data?.data ?? data
  },
  async debugSearchPlants(query) {
    const { data } = await apiClient.get('/dev/plant-care-test/search', {
      params: { q: query },
    })
    return data
  },
  async debugLoadPlantCareSpecies(speciesId, params = {}) {
    const { data } = await apiClient.get(`/dev/plant-care-test/species/${speciesId}`, {
      params,
    })
    return data
  },
  async debugCheckWeather(city) {
    const { data } = await apiClient.get('/dev/plant-care-test/weather', {
      params: { city },
    })
    return data
  },
  async createPlant(plotId, payload) {
    const { data } = await apiClient.post(`/plots/${plotId}/plants`, payload)
    return data
  },
  async getPlant(plotId, plantId) {
    const { data } = await apiClient.get(`/plots/${plotId}/plants/${plantId}`)
    return data
  },
  async getManagedPlant(plantId) {
    const { data } = await apiClient.get(`/plants/${plantId}`)
    return data
  },
  async updatePlant(plotId, plantId, payload) {
    const { data } = await apiClient.patch(`/plots/${plotId}/plants/${plantId}`, payload)
    return data
  },
  async createManagedPlant(payload) {
    const { data } = await apiClient.post('/plants', payload)
    return data
  },
  async updateManagedPlant(plantId, payload) {
    const { data } = await apiClient.patch(`/plants/${plantId}`, payload)
    return data
  },
  async deletePlant(plotId, plantId) {
    await apiClient.delete(`/plots/${plotId}/plants/${plantId}`)
  },
  async deleteManagedPlant(plantId) {
    await apiClient.delete(`/plants/${plantId}`)
  },
  async listPlantConditions(plotId, plantId) {
    const { data } = await apiClient.get(`/plots/${plotId}/plants/${plantId}/conditions`)
    return unwrapCollection(data)
  },
  async createPlantCondition(plotId, plantId, payload) {
    const { data } = await apiClient.post(`/plots/${plotId}/plants/${plantId}/conditions`, payload)
    return data
  },
  async listRotations(plotId) {
    const { data } = await apiClient.get(`/plots/${plotId}/rotations`)
    return unwrapCollection(data)
  },
  async createRotationPlan(plotId, payload) {
    const { data } = await apiClient.post(`/plots/${plotId}/rotations/plans`, payload)
    return data
  },
  async getRotationRecommendations(plotId, params = {}) {
    const { data } = await apiClient.get(`/plots/${plotId}/rotations/recommendations`, { params })
    return data?.data ?? data
  },
  async confirmRotationPlan(plotId, draftId) {
    const { data } = await apiClient.post(`/plots/${plotId}/rotations/plans/${draftId}/confirm`)
    return data
  },
  async rejectRotationPlan(plotId, draftId) {
    const { data } = await apiClient.delete(`/plots/${plotId}/rotations/plans/${draftId}`)
    return data
  },
  async listCalendars(plotId) {
    const { data } = await apiClient.get(`/plots/${plotId}/calendars`)
    return unwrapCollection(data)
  },
  async generateCalendar(plotId, payload) {
    const { data } = await apiClient.post(`/plots/${plotId}/calendars`, payload)
    return data
  },
  async getCalendar(plotId, calendarId) {
    const { data } = await apiClient.get(`/plots/${plotId}/calendars/${calendarId}`)
    return data
  },
  async listCalendarTasks(calendarId, params = {}) {
    const { data } = await apiClient.get(`/calendars/${calendarId}/tasks`, { params })
    return unwrapCollection(data)
  },
  async completeTask(taskId, payload = {}) {
    const { data } = await apiClient.patch(`/tasks/${taskId}/complete`, payload)
    return data
  },
  async rejectTask(taskId, payload = {}) {
    const { data } = await apiClient.patch(`/tasks/${taskId}/reject`, payload)
    return data
  },
  async listInventory() {
    const { data } = await apiClient.get('/inventory')
    return unwrapCollection(data)
  },
  async getInventoryItem(itemId) {
    const { data } = await apiClient.get(`/inventory/${itemId}`)
    return data?.data ?? data
  },
  async createInventoryItem(payload) {
    const { data } = await apiClient.post('/inventory', payload)
    return data?.data ?? data
  },
  async updateInventoryItem(itemId, payload) {
    const { data } = await apiClient.patch(`/inventory/${itemId}`, payload)
    return data?.data ?? data
  },
  async deleteInventoryItem(itemId) {
    const { data } = await apiClient.delete(`/inventory/${itemId}`)
    return data
  },
  async listCommunityPosts(plotId = null) {
    const path = plotId ? `/plots/${plotId}/community` : '/community'
    const { data } = await apiClient.get(path)
    return unwrapCollection(data)
  },
  async createCommunityPost(payload) {
    const { data } = await apiClient.post('/community', payload)
    return data?.data ?? data
  },
  async getPlotAnalytics(plotId) {
    const { data } = await apiClient.get(`/plots/${plotId}/analytics`)
    return data?.data ?? data
  },
  async generatePlotAnalytics(plotId, payload) {
    const { data } = await apiClient.post(`/plots/${plotId}/analytics`, payload)
    return data?.data ?? data
  },
  async listPlotHistory(plotId, params = {}) {
    const { data } = await apiClient.get(`/plots/${plotId}/history`, { params })
    return unwrapCollection(data)
  },
  async listHarvests(plotId, params = {}) {
    const { data } = await apiClient.get(`/plots/${plotId}/harvests`, { params })
    return unwrapCollection(data)
  },
  async createHarvest(plotId, payload) {
    const { data } = await apiClient.post(`/plots/${plotId}/harvests`, payload)
    return data?.data ?? data
  },
  async downloadPlotPdf(plotId, plotName = 'plot-report') {
    await downloadBlob(
      `/plots/${plotId}/export/pdf`,
      `${plotName.toLowerCase().replace(/\s+/g, '-') || 'plot'}-report.pdf`,
    )
  },
  async listAccessRights(plotId) {
    const { data } = await apiClient.get(`/plots/${plotId}/access`)
    return unwrapCollection(data)
  },
  async sharePlot(plotId, payload) {
    const { data } = await apiClient.post(`/plots/${plotId}/share`, payload)
    return data
  },
  async revokeAccessRight(accessRightId) {
    const { data } = await apiClient.delete(`/access/${accessRightId}`)
    return data
  },
  async listAdminUsers(params = {}) {
    const { data } = await apiClient.get('/admin/users', { params })
    return unwrapCollection(data)
  },
  async getAdminUser(userId) {
    const { data } = await apiClient.get(`/admin/users/${userId}`)
    return data?.data ?? data
  },
  async updateAdminUserRole(userId, role) {
    const { data } = await apiClient.patch(`/admin/users/${userId}/role`, { role })
    return data?.data ?? data
  },
  async deleteAdminUser(userId) {
    const { data } = await apiClient.delete(`/admin/users/${userId}`)
    return data
  },
}
