import { Navigate, useLocation } from 'react-router-dom'
import { useAuth } from '../../context/auth-context.js'
import { LoadingState } from './StatusView.jsx'

export default function ProtectedRoute({ children }) {
  const { isAuthenticated, restoring } = useAuth()
  const location = useLocation()

  if (restoring) {
    return <LoadingState title="Restoring your session…" />
  }

  if (!isAuthenticated) {
    const redirect = `${location.pathname}${location.search}`
    return <Navigate to={`/login?redirect=${encodeURIComponent(redirect)}`} replace />
  }

  return children
}
