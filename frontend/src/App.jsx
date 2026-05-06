import { Navigate, Outlet, Route, Routes } from 'react-router-dom'
import AppShell from './components/layout/AppShell.jsx'
import AdminRoute from './components/shared/AdminRoute.jsx'
import ProtectedRoute from './components/shared/ProtectedRoute.jsx'
import { useAuth } from './context/AuthContext.jsx'
import AdminUsersPage from './pages/admin/AdminUsersPage.jsx'
import PlotCalendarPage from './pages/calendar/PlotCalendarPage.jsx'
import CommunityPage from './pages/community/CommunityPage.jsx'
import InventoryPage from './pages/inventory/InventoryPage.jsx'
import CatalogPlantDetailPage from './pages/plant/CatalogPlantDetailPage.jsx'
import CatalogPlantFormPage from './pages/plant/CatalogPlantFormPage.jsx'
import CatalogPlantsPage from './pages/plant/CatalogPlantsPage.jsx'
import PlantDetailPage from './pages/plant/PlantDetailPage.jsx'
import PlantFormPage from './pages/plant/PlantFormPage.jsx'
import PlantsPage from './pages/plant/PlantsPage.jsx'
import PlotAnalyticsPage from './pages/plot/PlotAnalyticsPage.jsx'
import PlotCreatePage from './pages/plot/PlotCreatePage.jsx'
import PlotDetailPage from './pages/plot/PlotDetailPage.jsx'
import PlotEditPage from './pages/plot/PlotEditPage.jsx'
import PlotHarvestsPage from './pages/plot/PlotHarvestsPage.jsx'
import PlotHistoryPage from './pages/plot/PlotHistoryPage.jsx'
import PlotRotationPage from './pages/plot/PlotRotationPage.jsx'
import PlotSharingPage from './pages/plot/PlotSharingPage.jsx'
import PlotsPage from './pages/plot/PlotsPage.jsx'
import AccountPage from './pages/user/AccountPage.jsx'
import DashboardPage from './pages/user/DashboardPage.jsx'
import ForgotPasswordPage from './pages/user/ForgotPasswordPage.jsx'
import LoginPage from './pages/user/LoginPage.jsx'
import NotFoundPage from './pages/user/NotFoundPage.jsx'
import RegisterPage from './pages/user/RegisterPage.jsx'
import ResetPasswordPage from './pages/user/ResetPasswordPage.jsx'

function AuthShell() {
  return (
    <main className="auth-shell">
      <div className="auth-shell-brand" aria-label="SADiS">
        <span className="brand-leaf" aria-hidden="true">
          <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round">
            <path d="M10 17V9" />
            <path d="M10 9.5c-2.2-.1-4.1-1.6-4.8-4 3-.1 4.6 1.1 4.8 4Z" />
            <path d="M10.2 8c.3-2.7 2-4.2 5-4.5.1 3-1.6 4.7-5 4.5Z" />
          </svg>
        </span>
        <span className="brand-copy">
          <span className="brand-title">SAD<em>iS</em></span>
          <span className="brand-subtitle">Plot GIS and care planner</span>
        </span>
      </div>
      <div className="auth-shell-container">
        <Outlet />
      </div>
    </main>
  )
}

function AuthRoute({ children }) {
  const { isAuthenticated } = useAuth()

  if (isAuthenticated) {
    return <Navigate to="/" replace />
  }

  return children
}

function App() {
  return (
    <Routes>
      <Route element={<AuthShell />}>
        <Route path="login" element={<AuthRoute><LoginPage /></AuthRoute>} />
        <Route path="register" element={<AuthRoute><RegisterPage /></AuthRoute>} />
        <Route path="forgot-password" element={<AuthRoute><ForgotPasswordPage /></AuthRoute>} />
        <Route path="reset-password" element={<AuthRoute><ResetPasswordPage /></AuthRoute>} />
      </Route>
      <Route element={<AppShell />}>
        <Route index element={<DashboardPage />} />
        <Route
          path="community"
          element={(
            <ProtectedRoute>
              <CommunityPage />
            </ProtectedRoute>
          )}
        />
        <Route
          path="account"
          element={(
            <ProtectedRoute>
              <AccountPage />
            </ProtectedRoute>
          )}
        />
        <Route
          path="plots"
          element={(
            <ProtectedRoute>
              <PlotsPage />
            </ProtectedRoute>
          )}
        />
        <Route
          path="plots/new"
          element={(
            <ProtectedRoute>
              <PlotCreatePage />
            </ProtectedRoute>
          )}
        />
        <Route
          path="catalog-plants"
          element={(
            <ProtectedRoute>
              <CatalogPlantsPage />
            </ProtectedRoute>
          )}
        />
        <Route
          path="catalog-plants/new"
          element={(
            <ProtectedRoute>
              <CatalogPlantFormPage />
            </ProtectedRoute>
          )}
        />
        <Route
          path="catalog-plants/:catalogPlantId"
          element={(
            <ProtectedRoute>
              <CatalogPlantDetailPage />
            </ProtectedRoute>
          )}
        />
        <Route
          path="catalog-plants/:catalogPlantId/edit"
          element={(
            <ProtectedRoute>
              <CatalogPlantFormPage />
            </ProtectedRoute>
          )}
        />
        <Route
          path="plants"
          element={(
            <ProtectedRoute>
              <PlantsPage />
            </ProtectedRoute>
          )}
        />
        <Route
          path="plants/new"
          element={(
            <ProtectedRoute>
              <PlantFormPage />
            </ProtectedRoute>
          )}
        />
        <Route
          path="plants/catalog/new"
          element={(
            <ProtectedRoute>
              <CatalogPlantFormPage />
            </ProtectedRoute>
          )}
        />
        <Route
          path="plants/catalog/:catalogPlantId"
          element={(
            <ProtectedRoute>
              <CatalogPlantDetailPage />
            </ProtectedRoute>
          )}
        />
        <Route
          path="plants/catalog/:catalogPlantId/edit"
          element={(
            <ProtectedRoute>
              <CatalogPlantFormPage />
            </ProtectedRoute>
          )}
        />
        <Route
          path="plants/:plantId"
          element={(
            <ProtectedRoute>
              <PlantDetailPage />
            </ProtectedRoute>
          )}
        />
        <Route
          path="plants/:plantId/edit"
          element={(
            <ProtectedRoute>
              <PlantFormPage />
            </ProtectedRoute>
          )}
        />
        <Route
          path="plots/:plotId"
          element={(
            <ProtectedRoute>
              <PlotDetailPage />
            </ProtectedRoute>
          )}
        />
        <Route
          path="plots/:plotId/edit"
          element={(
            <ProtectedRoute>
              <PlotEditPage />
            </ProtectedRoute>
          )}
        />
        <Route
          path="plots/:plotId/analytics"
          element={(
            <ProtectedRoute>
              <PlotAnalyticsPage />
            </ProtectedRoute>
          )}
        />
        <Route
          path="plots/:plotId/history"
          element={(
            <ProtectedRoute>
              <PlotHistoryPage />
            </ProtectedRoute>
          )}
        />
        <Route
          path="plots/:plotId/harvests"
          element={(
            <ProtectedRoute>
              <PlotHarvestsPage />
            </ProtectedRoute>
          )}
        />
        <Route
          path="plots/:plotId/sharing"
          element={(
            <ProtectedRoute>
              <PlotSharingPage />
            </ProtectedRoute>
          )}
        />
        <Route
          path="plots/:plotId/rotation"
          element={(
            <ProtectedRoute>
              <PlotRotationPage />
            </ProtectedRoute>
          )}
        />
        <Route
          path="plots/:plotId/calendar"
          element={(
            <ProtectedRoute>
              <PlotCalendarPage />
            </ProtectedRoute>
          )}
        />
        <Route
          path="plots/:plotId/plants/:plantId"
          element={(
            <ProtectedRoute>
              <PlantDetailPage />
            </ProtectedRoute>
          )}
        />
        <Route
          path="inventory"
          element={(
            <ProtectedRoute>
              <InventoryPage />
            </ProtectedRoute>
          )}
        />
        <Route
          path="admin/users"
          element={(
            <AdminRoute>
              <AdminUsersPage />
            </AdminRoute>
          )}
        />
        <Route path="home" element={<Navigate to="/" replace />} />
        <Route path="*" element={<NotFoundPage />} />
      </Route>
    </Routes>
  )
}

export default App
