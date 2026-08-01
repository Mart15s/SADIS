import { useCallback, useMemo, useState } from 'react'
import { en } from './en.js'
import { formatArea, formatDate, formatDateTime, formatNumber, formatQuantity } from './formatters.js'
import { I18nContext } from './i18n-context.js'

const STORAGE_KEY = 'yava-locale'
const dictionaries = { 'en-IN': en }

export function I18nProvider({ children }) {
  const [locale, setLocaleState] = useState(() => localStorage.getItem(STORAGE_KEY) || 'en-IN')
  const messages = dictionaries[locale] ?? en
  const setLocale = useCallback((nextLocale) => {
    const supportedLocale = dictionaries[nextLocale] ? nextLocale : 'en-IN'
    localStorage.setItem(STORAGE_KEY, supportedLocale)
    setLocaleState(supportedLocale)
    document.documentElement.lang = supportedLocale.split('-')[0]
  }, [])
  const t = useCallback((key, values = {}) => {
    const template = messages[key] ?? en[key] ?? key
    return Object.entries(values).reduce(
      (result, [name, value]) => result.replaceAll(`{${name}}`, String(value)),
      template,
    )
  }, [messages])
  const value = useMemo(() => ({
    locale,
    setLocale,
    t,
    formatDate: (input, options) => formatDate(input, options, locale),
    formatDateTime: (input, options, timeZone) => formatDateTime(input, options, locale, timeZone),
    formatNumber: (input, options) => formatNumber(input, options, locale),
    formatArea: (input, preference) => formatArea(input, preference, locale),
    formatQuantity: (input, unit) => formatQuantity(input, unit, locale),
  }), [locale, setLocale, t])

  return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>
}
