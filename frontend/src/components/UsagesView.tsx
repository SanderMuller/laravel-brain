import { useNodeUsages } from '../hooks/useNodeUsages'

interface Props {
  nodeId: string
}

export function UsagesView({ nodeId }: Props) {
  const { data, loading, error } = useNodeUsages(nodeId)

  if (loading) {
    return (
      <div className="source-state">
        <div className="loading-spinner" style={{ width: 20, height: 20, borderWidth: 2 }} />
        <span>Finding usages…</span>
      </div>
    )
  }

  if (error) {
    return (
      <div className="source-state source-state--error">
        Could not load usages
        <small style={{ display: 'block', opacity: 0.6, marginTop: 4 }}>{error}</small>
      </div>
    )
  }

  if (!data) return null

  if (data.usageCount === 0) {
    return (
      <div className="sidebar-section">
        <div className="security-clean">
          <span>✓</span> Not used anywhere else in the project.
        </div>
      </div>
    )
  }

  return (
    <div className="sidebar-section">
      <h3>
        Used in {data.fileCount} file{data.fileCount === 1 ? '' : 's'} · {data.usageCount} reference{data.usageCount === 1 ? '' : 's'}
      </h3>
      {data.files.map((group) => (
        <div key={group.file ?? `#${group.usages[0]?.nodeId ?? ''}`} style={{ marginBottom: 12 }}>
          <span
            className="ins-chip ins-chip--neutral"
            title={group.file ?? 'Location could not be resolved'}
            style={{ display: 'inline-block', marginBottom: 6 }}
          >
            {group.file ? group.file.split('/').slice(-2).join('/') : 'Unresolved location'} · {group.count}
          </span>
          {group.usages.map((usage) => (
            <div key={usage.nodeId} className="edge-row">
              <span className="edge-target">{usage.label}</span>
              <span className="edge-label">{usage.edgeLabel}</span>
            </div>
          ))}
        </div>
      ))}
    </div>
  )
}
