import { useCallback, useEffect, useState } from 'react'

export function useAsyncData(loader, deps = [], initialData = null) {
  const [data, setData] = useState(initialData)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const run = useCallback(async () => {
    setLoading(true)
    setError(null)

    try {
      const nextData = await loader()
      // A successful DELETE/204 has no body. Do not replace valid render state
      // with an invalid empty value while a page is still mounted.
      if (nextData !== undefined) {
        setData(nextData)
      }
    } catch (nextError) {
      setError(nextError)
    } finally {
      setLoading(false)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps, react-hooks/use-memo
  }, deps)

  useEffect(() => {
    run()
  }, [run])

  return {
    data,
    loading,
    error,
    reload: run,
    setData,
  }
}
