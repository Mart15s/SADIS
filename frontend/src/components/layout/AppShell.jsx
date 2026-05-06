import { useMemo, useState } from 'react'
import { Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../../context/AuthContext.jsx'
import { PageChromeContext } from './PageChromeContext.jsx'
import Sidebar from './Sidebar.jsx'
import Topbar from './Topbar.jsx'

export default function AppShell() {
  const { isAdmin, isAuthenticated } = useAuth()
  const location = useLocation()
  const [isSidebarCollapsed, setIsSidebarCollapsed] = useState(() => {
    if (typeof window === 'undefined') return false
    return window.matchMedia('(max-width: 1100px)').matches
  })
  const isWorkspaceRoute = (
    /^\/plots\/new$/.test(location.pathname)
    || /^\/plots\/[^/]+(?:\/(?:calendar|history|harvests|analytics|sharing|rotation))?$/.test(location.pathname)
  )
  const isPlotEditorRoute = /^\/plots\/new$/.test(location.pathname) || /^\/plots\/[^/]+$/.test(location.pathname)
  const [pageHeader, setPageHeader] = useState(null)
  const pageChromeContext = useMemo(() => ({
    registerPageHeader(id, header) {
      setPageHeader((current) => {
        if (current?.id === id && current?.signature === header.signature && current?.pathname === header.pathname) {
          return current
        }

        return { ...header, id }
      })
    },
    clearPageHeader(id) {
      setPageHeader((current) => (current?.id === id ? null : current))
    },
  }), [])
  const activePageHeader = pageHeader?.pathname === location.pathname ? pageHeader : null

  return (
    <PageChromeContext.Provider value={pageChromeContext}>
      <div className={`app-shell ${isSidebarCollapsed ? 'is-sidebar-collapsed' : ''}`}>
        <Sidebar
          isAuthenticated={isAuthenticated}
          isAdmin={isAdmin}
          isCollapsed={isSidebarCollapsed}
          onToggleCollapse={() => setIsSidebarCollapsed((current) => !current)}
        />
        <main className={`shell-main ${isPlotEditorRoute ? 'shell-main--plot-editor' : ''}`.trim()}>
          {isWorkspaceRoute ? null : <Topbar isWide={isWorkspaceRoute} pageHeader={activePageHeader} />}
          <div
            className={[
              'page-container',
              isWorkspaceRoute ? 'page-container-wide page-container-workspace' : '',
              isPlotEditorRoute ? 'page-container-plot-editor' : '',
            ].filter(Boolean).join(' ')}
          >
            <Outlet />
          </div>
        </main>
      </div>
    </PageChromeContext.Provider>
  )
}
