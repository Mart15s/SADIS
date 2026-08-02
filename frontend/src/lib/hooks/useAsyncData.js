import { useCallback, useEffect, useRef, useState } from 'react'

export function useAsyncData(loader, deps = [], initialData = null) {
  const initialDataRef = useRef(initialData)
  const requestIdRef = useRef(0)
  const [data, setData] = useState(initialData)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const run = useCallback(async ({ clear = false } = {}) => {
    const requestId = ++requestIdRef.current
    if (clear) setData(initialDataRef.current)
    setLoading(true)
    setError(null)

    try {
      const nextData = await loader()
      if (requestId !== requestIdRef.current) return
      // A successful DELETE/204 has no body. Do not replace valid render state
      // with an invalid empty value while a page is still mounted.
      if (nextData !== undefined) {
        setData(nextData)
      }
    } catch (nextError) {
      if (requestId !== requestIdRef.current) return
      setError(nextError)
    } finally {
      if (requestId === requestIdRef.current) setLoading(false)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps, react-hooks/use-memo
  }, deps)

  useEffect(() => {
    run({ clear: true })
    return () => {
      requestIdRef.current += 1
    }
  }, [run])

  const reload = useCallback(() => run(), [run])

  return {
    data,
    loading,
    error,
    reload,
    setData,
  }
}
