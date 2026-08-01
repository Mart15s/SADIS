// @vitest-environment node
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const primarySources = [
  'src/App.jsx',
  'src/components/layout/AppShell.jsx',
  'src/components/layout/Sidebar.jsx',
  'src/components/layout/Topbar.jsx',
  'src/components/layout/ContextSwitcher.jsx',
  'src/pages/stage1/Stage1DashboardPage.jsx',
  'src/pages/stage1/DomainWorkspacePage.jsx',
  'src/pages/stage1/MembershipPage.jsx',
  'src/pages/stage1/AnalyticsWorkspacePage.jsx',
  'src/pages/stage1/FieldEditorPage.jsx',
  'src/pages/stage1/OnboardingPage.jsx',
  'src/pages/stage1/OtpVerificationPage.jsx',
  'src/pages/stage1/domainDefinitions.js',
  'src/pages/user/LoginPage.jsx',
  'src/pages/user/RegisterPage.jsx',
  'src/pages/user/ForgotPasswordPage.jsx',
  'src/pages/user/ResetPasswordPage.jsx',
  'src/pages/user/AccountPage.jsx',
]

describe('English primary interface source', () => {
  it.each(primarySources)('%s contains no Lithuanian interface characters', (path) => {
    const source = readFileSync(resolve(process.cwd(), path), 'utf8')
    expect(source).not.toMatch(/[ĄČĘĖĮŠŲŪŽąčęėįšųūž]/u)
  })

  it('declares explicit narrow-phone and mobile layouts for the field editor', () => {
    const css = readFileSync(resolve(process.cwd(), 'src/index.css'), 'utf8')
    expect(css).toContain('@media (max-width: 359px)')
    expect(css).toContain('@media (max-width: 720px)')
    expect(css).toContain('.field-editor-sheet')
  })
})
