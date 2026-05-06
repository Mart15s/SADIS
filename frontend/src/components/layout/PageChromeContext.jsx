import { createContext, useContext } from 'react'

export const PageChromeContext = createContext(null)

export function usePageChrome() {
  return useContext(PageChromeContext)
}
