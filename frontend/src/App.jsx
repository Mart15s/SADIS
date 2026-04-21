import { Navigate, Route, Routes } from 'react-router-dom'
import AppShell from './components/layout/AppShell.jsx'
import AdminRoute from './components/shared/AdminRoute.jsx'
import ProtectedRoute from './components/shared/ProtectedRoute.jsx'
import AdminUsersPage from './pages/admin/AdminUsersPage.jsx'
import PlotCalendarPage from './pages/calendar/PlotCalendarPage.jsx'
import CommunityPage from './pages/community/CommunityPage.jsx'
import PlantCareDebugPage from './pages/dev/PlantCareDebugPage.jsx'
import InventoryPage from './pages/inventory/InventoryPage.jsx'
import CatalogPlantDetailPage from './pages/plant/CatalogPlantDetailPage.jsx'
import CatalogPlantFormPage from './pages/plant/CatalogPlantFormPage.jsx'
import CatalogPlantsPage from './pages/plant/CatalogPlantsPage.jsx'
import ManagedPlantDetailPage from './pages/plant/ManagedPlantDetailPage.jsx'
import PlantDetailPage from './pages/plant/PlantDetailPage.jsx'
import PlantFormPage from './pages/plant/PlantFormPage.jsx'
import PlantsPage from './pages/plant/PlantsPage.jsx'
import PlotAnalyticsPage from './pages/plot/PlotAnalyticsPage.jsx'
import PlotDetailPage from './pages/plot/PlotDetailPage.jsx'
import PlotEditPage from './pages/plot/PlotEditPage.jsx'
import PlotHarvestsPage from './pages/plot/PlotHarvestsPage.jsx'
import PlotHistoryPage from './pages/plot/PlotHistoryPage.jsx'
import PlotsPage from './pages/plot/PlotsPage.jsx'
import AccountPage from './pages/user/AccountPage.jsx'
import DashboardPage from './pages/user/DashboardPage.jsx'
import ForgotPasswordPage from './pages/user/ForgotPasswordPage.jsx'
import LoginPage from './pages/user/LoginPage.jsx'
import NotFoundPage from './pages/user/NotFoundPage.jsx'
import RegisterPage from './pages/user/RegisterPage.jsx'
import ResetPasswordPage from './pages/user/ResetPasswordPage.jsx'

function App() {
  return (
    <Routes>
      <Route element={<AppShell />}>
        <Route index element={<DashboardPage />} />
        <Route path="login" element={<LoginPage />} />
        <Route path="register" element={<RegisterPage />} />
        <Route path="forgot-password" element={<ForgotPasswordPage />} />
        <Route path="reset-password" element={<ResetPasswordPage />} />
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
              <ManagedPlantDetailPage />
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
          path="dev/plant-care-test"
          element={(
            <ProtectedRoute>
              <PlantCareDebugPage />
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
