import { Navigate, Outlet, Route, Routes, useSearchParams } from 'react-router-dom'
import AppShell from './components/layout/AppShell.jsx'
import BrandLogo from './components/layout/BrandLogo.jsx'
import AdminRoute from './components/shared/AdminRoute.jsx'
import ProtectedRoute from './components/shared/ProtectedRoute.jsx'
import { useAuth } from './context/auth-context.js'
import AccountPage from './pages/user/AccountPage.jsx'
import ForgotPasswordPage from './pages/user/ForgotPasswordPage.jsx'
import LoginPage from './pages/user/LoginPage.jsx'
import NotFoundPage from './pages/user/NotFoundPage.jsx'
import RegisterPage from './pages/user/RegisterPage.jsx'
import ResetPasswordPage from './pages/user/ResetPasswordPage.jsx'
import DomainWorkspacePage from './pages/stage1/DomainWorkspacePage.jsx'
import MembershipPage from './pages/stage1/MembershipPage.jsx'
import AnalyticsWorkspacePage from './pages/stage1/AnalyticsWorkspacePage.jsx'
import CalendarWorkspacePage from './pages/stage1/CalendarWorkspacePage.jsx'
import FieldEditorPage from './pages/stage1/FieldEditorPage.jsx'
import OnboardingPage from './pages/stage1/OnboardingPage.jsx'
import OtpVerificationPage from './pages/stage1/OtpVerificationPage.jsx'
import Stage1DashboardPage from './pages/stage1/Stage1DashboardPage.jsx'
import UserAdministrationPage from './pages/stage1/UserAdministrationPage.jsx'
import { LoadingState } from './components/shared/StatusView.jsx'
import { safeRedirectPath } from './lib/navigation.js'

function AuthShell() {
  return (
    <main className="auth-shell">
      <div className="auth-shell-brand" aria-label="Yava">
        <BrandLogo className="brand-logo--auth" alt="Yava logo" />
        <span className="brand-copy">
          <span className="brand-title">Yava</span>
          <span className="brand-subtitle">Yava seed. Yava plan. Yava harvest.</span>
        </span>
      </div>
      <div className="auth-shell-container">
        <Outlet />
      </div>
    </main>
  )
}

export function AuthRoute({ children }) {
  const { isAuthenticated, restoring } = useAuth()
  const [searchParams] = useSearchParams()
  if (restoring) return <LoadingState title="Restoring your session…" />
  return isAuthenticated ? (
    <Navigate to={safeRedirectPath(searchParams.get('redirect'))} replace />
  ) : (
    children
  )
}

function Private({ children }) {
  return <ProtectedRoute>{children}</ProtectedRoute>
}

export default function App() {
  return (
    <Routes>
      <Route element={<AuthShell />}>
        <Route
          path="login"
          element={
            <AuthRoute>
              <LoginPage />
            </AuthRoute>
          }
        />
        <Route
          path="register"
          element={
            <AuthRoute>
              <RegisterPage />
            </AuthRoute>
          }
        />
        <Route
          path="forgot-password"
          element={
            <AuthRoute>
              <ForgotPasswordPage />
            </AuthRoute>
          }
        />
        <Route
          path="reset-password"
          element={
            <AuthRoute>
              <ResetPasswordPage />
            </AuthRoute>
          }
        />
        <Route
          path="otp"
          element={
            <AuthRoute>
              <OtpVerificationPage purpose="login" />
            </AuthRoute>
          }
        />
      </Route>
      <Route element={<AppShell />}>
        <Route index element={<Stage1DashboardPage />} />
        <Route
          path="communities"
          element={
            <Private>
              <DomainWorkspacePage resource="communities" />
            </Private>
          }
        />
        <Route
          path="communities/:communityId/members"
          element={
            <Private>
              <MembershipPage scope="community" />
            </Private>
          }
        />
        <Route
          path="farms"
          element={
            <Private>
              <DomainWorkspacePage resource="farms" />
            </Private>
          }
        />
        <Route
          path="farms/:farmId/members"
          element={
            <Private>
              <MembershipPage scope="farm" />
            </Private>
          }
        />
        <Route
          path="fields"
          element={
            <Private>
              <DomainWorkspacePage resource="fields" />
            </Private>
          }
        />
        <Route
          path="fields/:fieldId/editor"
          element={
            <Private>
              <FieldEditorPage />
            </Private>
          }
        />
        <Route
          path="crops"
          element={
            <Private>
              <DomainWorkspacePage resource="crops" />
            </Private>
          }
        />
        <Route
          path="crop-seasons"
          element={
            <Private>
              <DomainWorkspacePage resource="crop-seasons" />
            </Private>
          }
        />
        <Route
          path="tasks"
          element={
            <Private>
              <DomainWorkspacePage resource="tasks" />
            </Private>
          }
        />
        <Route
          path="calendar"
          element={
            <Private>
              <CalendarWorkspacePage />
            </Private>
          }
        />
        <Route
          path="inventory"
          element={
            <Private>
              <DomainWorkspacePage resource="inventories" />
            </Private>
          }
        />
        <Route
          path="resources"
          element={
            <Private>
              <DomainWorkspacePage resource="resources" />
            </Private>
          }
        />
        <Route
          path="reservations"
          element={
            <Private>
              <DomainWorkspacePage resource="reservations" />
            </Private>
          }
        />
        <Route
          path="analytics"
          element={
            <Private>
              <AnalyticsWorkspacePage />
            </Private>
          }
        />
        <Route
          path="account"
          element={
            <Private>
              <AccountPage />
            </Private>
          }
        />
        <Route
          path="onboarding"
          element={
            <Private>
              <OnboardingPage />
            </Private>
          }
        />
        <Route
          path="verify-phone"
          element={
            <Private>
              <OtpVerificationPage purpose="verify_phone" />
            </Private>
          }
        />
        <Route
          path="admin/users"
          element={
            <AdminRoute>
              <UserAdministrationPage />
            </AdminRoute>
          }
        />

        {/* Stage 1 compatibility redirects keep bookmarks valid without loading
          the retired garden vocabulary into the primary product bundle. */}
        <Route path="community" element={<Navigate to="/communities" replace />} />
        <Route path="plots" element={<Navigate to="/fields" replace />} />
        <Route path="plots/*" element={<Navigate to="/fields" replace />} />
        <Route path="plants" element={<Navigate to="/crop-seasons" replace />} />
        <Route path="plants/*" element={<Navigate to="/crop-seasons" replace />} />
        <Route path="catalog-plants/*" element={<Navigate to="/crops" replace />} />
        <Route path="home" element={<Navigate to="/" replace />} />
        <Route path="*" element={<NotFoundPage />} />
      </Route>
    </Routes>
  )
}
