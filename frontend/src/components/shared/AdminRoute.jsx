import { Navigate, useLocation } from 'react-router-dom'
import { useAuth } from '../../context/AuthContext.jsx'

export default function AdminRoute({ children }) {
  const { isAdmin, isAuthenticated } = useAuth()
  const location = useLocation()

  if (!isAuthenticated) {
    return <Navigate to={`/login?redirect=${encodeURIComponent(location.pathname)}`} replace />
  }

  if (!isAdmin) {
    return <Navigate to="/" replace />
  }

  return children
}
