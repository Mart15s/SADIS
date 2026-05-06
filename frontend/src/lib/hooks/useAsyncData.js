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
      setData(nextData)
    } catch (nextError) {
      setError(nextError)
    } finally {
      setLoading(false)
    }
  // eslint-disable-next-line react-hooks/exhaustive-deps
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
