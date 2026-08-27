import { useState, useEffect } from 'react'
import type { NodeUsages } from '../types/graph'

export function useNodeUsages(nodeId: string | null) {
  const [data, setData] = useState<NodeUsages | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  // Adjust state during render when nodeId changes
  const [prevNodeId, setPrevNodeId] = useState(nodeId)
  if (nodeId !== prevNodeId) {
    setPrevNodeId(nodeId)
    if (!nodeId) {
      setData(null)
      setError(null)
    } else {
      setLoading(true)
      setError(null)
      setData(null)
    }
  }

  useEffect(() => {
    if (!nodeId) return

    fetch(`${import.meta.env.BASE_URL}api/usages?nodeId=${encodeURIComponent(nodeId)}`)
      .then((r) => r.json())
      .then((data) => {
        if (data.error) throw new Error(data.error)
        setData(data)
      })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false))
  }, [nodeId])

  return { data, loading, error }
}
