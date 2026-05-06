import { useState } from 'react'
import { Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../../context/AuthContext.jsx'
import Sidebar from './Sidebar.jsx'
import Topbar from './Topbar.jsx'

export default function AppShell() {
  const { isAdmin, isAuthenticated } = useAuth()
  const location = useLocation()
  const [isSidebarCollapsed, setIsSidebarCollapsed] = useState(() => {
    if (typeof window === 'undefined') return false
    return window.matchMedia('(max-width: 1100px)').matches
  })
  const isWorkspaceRoute = /^\/plots\/[^/]+(?:\/(?:calendar|history|harvests|analytics|sharing|rotation))?$/.test(location.pathname)

  return (
    <div className={`app-shell ${isSidebarCollapsed ? 'is-sidebar-collapsed' : ''}`}>
      <Sidebar
        isAuthenticated={isAuthenticated}
        isAdmin={isAdmin}
        isCollapsed={isSidebarCollapsed}
        onToggleCollapse={() => setIsSidebarCollapsed((current) => !current)}
      />
      <main className="shell-main">
        <Topbar isWide={isWorkspaceRoute} />
        <div className={`page-container ${isWorkspaceRoute ? 'page-container-wide page-container-workspace' : ''}`.trim()}>
          <Outlet />
        </div>
      </main>
    </div>
  )
}
