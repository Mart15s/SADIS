import { useEffect, useEffectEvent, useState } from 'react'

export function useAsyncData(loader, deps = [], initialData = null) {
  const [data, setData] = useState(initialData)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const run = useEffectEvent(async () => {
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
  })

  useEffect(() => {
    run()
  }, deps)

  return {
    data,
    loading,
    error,
    reload: run,
    setData,
  }
}
