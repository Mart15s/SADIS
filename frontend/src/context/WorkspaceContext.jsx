import { useCallback, useEffect, useMemo, useState } from 'react'
import { api } from '../lib/api.js'
import { useAuth } from './auth-context.js'
import { WorkspaceContext } from './workspace-context.js'

const STORAGE_KEY = 'yava-active-context'
function normalizeContexts(payload) {
  const contexts = Array.isArray(payload) ? payload : []
  return contexts
    .filter((item) => item?.id && ['farm', 'community'].includes(item.type))
    .map((item) => ({
      id: String(item.id),
      type: item.type,
      name: item.name || `${item.type} ${item.id}`,
      role: item.role || null,
      timezone: item.timezone || 'Asia/Kolkata',
    }))
}

export function WorkspaceProvider({ children }) {
  const { isAuthenticated } = useAuth()
  const [contexts, setContexts] = useState([])
  const [active, setActiveState] = useState(() => {
    try { return JSON.parse(localStorage.getItem(STORAGE_KEY)) }
    catch { return null }
  })
  const [loading, setLoading] = useState(false)

  const reload = useCallback(async () => {
    if (!isAuthenticated) {
      setContexts([])
      return
    }
    setLoading(true)
    try {
      const next = normalizeContexts(await api.listContexts())
      setContexts(next)
      setActiveState((current) => {
        if (current && next.some((item) => item.id === current.id && item.type === current.type)) return current
        return next[0] ?? null
      })
    } catch {
      // The application remains useful while a newly deployed v1 context endpoint warms up.
      setContexts([])
    } finally {
      setLoading(false)
    }
  }, [isAuthenticated])

  useEffect(() => { reload() }, [reload])
  useEffect(() => {
    if (active) localStorage.setItem(STORAGE_KEY, JSON.stringify(active))
    else localStorage.removeItem(STORAGE_KEY)
  }, [active])

  const setActive = useCallback((type, id) => {
    const next = contexts.find((item) => item.type === type && item.id === String(id)) ?? null
    setActiveState(next)
  }, [contexts])

  const value = useMemo(() => ({ contexts, active, setActive, loading, reload }), [active, contexts, loading, reload, setActive])
  return <WorkspaceContext.Provider value={value}>{children}</WorkspaceContext.Provider>
}
