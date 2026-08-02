import { Navigate, useLocation } from 'react-router-dom'
import { useAuth } from '../../context/auth-context.js'
import { LoadingState } from './StatusView.jsx'

export default function AdminRoute({ children }) {
  const { isAdmin, isAuthenticated, restoring } = useAuth()
  const location = useLocation()

  if (restoring) {
    return <LoadingState title="Restoring your session…" />
  }

  if (!isAuthenticated) {
    return <Navigate to={`/login?redirect=${encodeURIComponent(location.pathname)}`} replace />
  }

  if (!isAdmin) {
    return <Navigate to="/" replace />
  }

  return children
}
