import { useContext, useEffect, useEffectEvent, useRef } from 'react'
import {
  UNSAFE_NavigationContext as NavigationContext,
  useBeforeUnload,
  useLocation,
} from 'react-router-dom'

function readHistoryIndex() {
  const index = window.history.state?.idx
  return Number.isFinite(index) ? index : null
}

export function useUnsavedChangesGuard({ when, message }) {
  const navigationContext = useContext(NavigationContext)
  const location = useLocation()
  const historyIndexRef = useRef(readHistoryIndex())
  const suppressNextPopRef = useRef(false)

  const confirmNavigation = useEffectEvent(() => {
    if (!when) {
      return true
    }

    return window.confirm(message)
  })

  useBeforeUnload((event) => {
    if (!when) {
      return
    }

    event.preventDefault()
    event.returnValue = ''
  }, { capture: true })

  useEffect(() => {
    historyIndexRef.current = readHistoryIndex()
  }, [location.key, location.pathname, location.search, location.hash])

  useEffect(() => {
    const navigator = navigationContext?.navigator

    if (!when || !navigator) {
      return undefined
    }

    const originalPush = navigator.push?.bind(navigator)
    const originalReplace = navigator.replace?.bind(navigator)
    const originalGo = navigator.go?.bind(navigator)

    if (originalPush) {
      navigator.push = (...args) => {
        if (confirmNavigation()) {
          originalPush(...args)
        }
      }
    }

    if (originalReplace) {
      navigator.replace = (...args) => {
        if (confirmNavigation()) {
          originalReplace(...args)
        }
      }
    }

    if (originalGo) {
      navigator.go = (delta) => {
        if (delta === 0 || confirmNavigation()) {
          originalGo(delta)
        }
      }
    }

    return () => {
      if (originalPush) {
        navigator.push = originalPush
      }

      if (originalReplace) {
        navigator.replace = originalReplace
      }

      if (originalGo) {
        navigator.go = originalGo
      }
    }
  }, [confirmNavigation, navigationContext, when])

  useEffect(() => {
    if (!when) {
      return undefined
    }

    function handlePopState(event) {
      if (suppressNextPopRef.current) {
        suppressNextPopRef.current = false
        historyIndexRef.current = readHistoryIndex()
        return
      }

      const nextIndex = Number.isFinite(event.state?.idx) ? event.state.idx : null
      const previousIndex = historyIndexRef.current

      if (confirmNavigation()) {
        historyIndexRef.current = nextIndex
        return
      }

      suppressNextPopRef.current = true

      if (nextIndex !== null && previousIndex !== null) {
        window.history.go(nextIndex > previousIndex ? -1 : 1)
        return
      }

      window.history.go(1)
    }

    window.addEventListener('popstate', handlePopState)

    return () => {
      window.removeEventListener('popstate', handlePopState)
    }
  }, [confirmNavigation, when])
}
