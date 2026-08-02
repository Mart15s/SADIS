import { render, screen } from '@testing-library/react'
import { beforeEach, describe, expect, it } from 'vitest'
import { I18nProvider } from './I18nContext.jsx'
import { useI18n } from './i18n-context.js'

function TranslationProbe() {
  const { t } = useI18n()
  return (
    <>
      <span data-testid="known">{t('nav.home')}</span>
      <span data-testid="fallback">{t('missing.semantic.key')}</span>
      <span data-testid="interpolation">
        {t('context.option', { type: 'Farm', name: 'Sunrise' })}
      </span>
    </>
  )
}

describe('I18nProvider', () => {
  beforeEach(() => localStorage.clear())

  it('resolves semantic keys and falls back safely for unknown keys', () => {
    render(
      <I18nProvider>
        <TranslationProbe />
      </I18nProvider>,
    )

    expect(screen.getByTestId('known')).toHaveTextContent('Overview')
    expect(screen.getByTestId('fallback')).toHaveTextContent('missing.semantic.key')
    expect(screen.getByTestId('interpolation')).toHaveTextContent('Farm · Sunrise')
    expect(document.documentElement).toHaveAttribute('lang', 'en')
  })
})
