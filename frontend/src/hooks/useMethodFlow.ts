import { useState, useEffect } from 'react'
import type { FlowStep } from '../types/graph'

interface MethodFlowData {
  flowSteps: FlowStep[]
  declaringFqcn: string
}

/** A method's flow chart, resolved on demand — for a method that may never have been traced. */
export function useMethodFlow(fqcn: string | null, method: string | null) {
  const key = fqcn && method ? `${fqcn}::${method}` : null

  const [data, setData] = useState<MethodFlowData | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  // Adjust state during render when the fqcn/method pair changes
  const [prevKey, setPrevKey] = useState(key)
  if (key !== prevKey) {
    setPrevKey(key)
    if (!key) {
      setData(null)
      setError(null)
    } else {
      setLoading(true)
      setError(null)
      setData(null)
    }
  }

  useEffect(() => {
    if (!fqcn || !method) return

    const params = new URLSearchParams({ fqcn, method })
    fetch(`${import.meta.env.BASE_URL}api/method-flow?${params.toString()}`)
      .then((r) => r.json())
      .then((json) => {
        if (json.error) throw new Error(json.error)
        setData({ flowSteps: json.flowSteps, declaringFqcn: json.declaringFqcn })
      })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false))
  }, [fqcn, method])

  return { data, loading, error }
}
