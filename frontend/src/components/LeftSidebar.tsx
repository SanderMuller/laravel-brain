import { useRef, useState, useCallback, useMemo } from 'react'
import { FilterPanel } from './FilterPanel'
import { Tooltip } from './Tooltip'
import { SECURITY_RISK_COLORS, SECURITY_SEVERITY_LABELS } from '../utils/graphConstants'
import type { TabEntry, GraphData } from '../types/graph'

const RISK_ORDER: Record<string, number> = { none: 0, low: 1, medium: 2, high: 3, critical: 4 }

const MIN_WIDTH = 280
const MAX_WIDTH = 480
const DEFAULT_WIDTH = 300

interface TreeNode {
  name: string
  path: string
  isCategory: boolean
  children: TreeNode[]
  leaves: TabEntry[]
}

interface Props {
  tabs: TabEntry[]
  activeId: string | null
  loadingId: string | null
  onSelect: (tab: TabEntry) => void
  mode: 'routes' | 'risks' | 'recent'
  onModeChange: (m: 'routes' | 'risks' | 'recent') => void
  highRiskCount: number
  recentCount: number
  previousAnalyzedAt?: string
  visibleTypes: Set<string>
  counts: Record<string, number>
  onToggle: (type: string) => void
  onShowAll: () => void
  onHideAll: () => void
  graphData: GraphData | null
  complexityFilter: 'all' | 'complex' | 'critical'
  onComplexityFilterChange: (f: 'all' | 'complex' | 'critical') => void
  onNodeSelect: (id: string) => void
  selectedId: string | null
}

const METHOD_COLORS: Record<string, string> = {
  GET: '#4ade80',
  POST: '#60a5fa',
  PUT: '#f59e0b',
  PATCH: '#a78bfa',
  DELETE: '#f87171',
}

const ALL_HTTP_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as const

function splitLabel(label: string): { method: string | null; uri: string } {
  const [first, ...rest] = label.split(' ')
  if (first in METHOD_COLORS) return { method: first, uri: rest.join(' ') }
  return { method: null, uri: label }
}

function riskOf(tab: TabEntry): string {
  return tab.riskLevel ?? 'none'
}

function riskDescription(tab: TabEntry): string {
  const parts: string[] = []
  if (tab.securityCount) parts.push(`${tab.securityCount} security`)
  if (tab.n1Count) parts.push(`${tab.n1Count} N+1`)
  const fat = (tab.fatMethodCount ?? 0) + (tab.fatClassCount ?? 0)
  if (fat) parts.push(`${fat} fat`)
  return parts.length ? parts.join(' · ') : 'flagged for review'
}

function relativeTime(from?: string): string {
  if (!from) return 'new'
  const ms = Date.now() - new Date(from).getTime()
  const mins = Math.floor(ms / 60000)
  if (mins < 60) return `${mins}m ago`
  const hrs = Math.floor(mins / 60)
  if (hrs < 24) return `${hrs}h ago`
  return `${Math.floor(hrs / 24)}d ago`
}

function RouteItem({ tab, isActive, isLoading, onSelect }: {
  tab: TabEntry
  isActive: boolean
  isLoading: boolean
  onSelect: (tab: TabEntry) => void
}) {
  const { method, uri } = splitLabel(tab.label)
  const color = method ? METHOD_COLORS[method] : 'var(--faint)'
  const risk = riskOf(tab)
  const badgeColor = risk === 'high' || risk === 'critical'
    ? 'var(--danger)'
    : tab.issueCount
      ? 'var(--warn)'
      : null

  return (
    <Tooltip content={`Open lifecycle graph · ${tab.nodeCount} nodes · ${tab.edgeCount} edges`}>
      <button
        className={`route-row ${isActive ? 'route-row--active' : ''}`}
        type="button"
        onClick={() => onSelect(tab)}
      >
        <span className="route-row-method" style={{ color }}>{method ?? '›'}</span>
        <span className="route-row-uri">{uri}</span>
        {badgeColor && (
          <span className="route-row-risk" style={{ '--rc': badgeColor } as React.CSSProperties}>
            {tab.issueCount}
          </span>
        )}
        {isLoading && <span className="route-row-loading">…</span>}
      </button>
    </Tooltip>
  )
}

function categoryBucket(tab: TabEntry): string {
  if (tab.category === 'Command') return 'Console Commands'
  if (tab.category === 'Channel') return 'Broadcast Channels'
  if (tab.category === 'Schedule') return 'Schedules'
  if (tab.category === 'Filament') {
    const p = tab.panelId ?? ''
    return p ? `Filament · ${p.charAt(0).toUpperCase()}${p.slice(1)} Panel` : 'Filament'
  }
  return 'Other'
}

function sortTree(node: TreeNode): void {
  node.children.sort((a, b) => a.name.localeCompare(b.name))
  node.leaves.sort((a, b) => a.label.localeCompare(b.label))
  node.children.forEach(sortTree)
}

function routeSegments(tab: TabEntry): string[] | null {
  const first = tab.label.split(' ')[0]
  if (!(first in METHOD_COLORS)) return null
  const uri = tab.label.slice(first.length).trim()
  return uri.split('/').filter(Boolean)
}

function buildTree(tabs: TabEntry[]): TreeNode {
  const root: TreeNode = { name: '', path: '', isCategory: false, children: [], leaves: [] }

  const childByName = (parent: TreeNode, name: string, isCategory: boolean): TreeNode => {
    let child = parent.children.find((c) => c.name === name)
    if (!child) {
      child = {
        name,
        path: parent.path ? `${parent.path}/${name}` : name,
        isCategory,
        children: [],
        leaves: [],
      }
      parent.children.push(child)
    }
    return child
  }

  const dirs = new Set<string>()
  for (const tab of tabs) {
    const segments = routeSegments(tab)
    if (!segments) continue
    const groupSegments = segments.slice(0, -1)
    for (let i = 1; i <= groupSegments.length; i++) {
      dirs.add(groupSegments.slice(0, i).join('/'))
    }
  }

  for (const tab of tabs) {
    const segments = routeSegments(tab)

    if (!segments) {
      const bucket = childByName(root, categoryBucket(tab), true)
      bucket.leaves.push(tab)
      continue
    }

    const fullPath = segments.join('/')
    const targetSegments = fullPath !== '' && dirs.has(fullPath)
      ? segments
      : segments.slice(0, -1)

    let node = root
    for (const seg of targetSegments) {
      node = childByName(node, seg, false)
    }
    node.leaves.push(tab)
  }

  sortTree(root)
  return root
}

function leafCount(node: TreeNode): number {
  return node.leaves.length + node.children.reduce((s, c) => s + leafCount(c), 0)
}

function TreeGroup({
  node, forceOpen, expanded, onToggle, activeId, loadingId, onSelect,
}: {
  node: TreeNode
  forceOpen: boolean
  expanded: Set<string>
  onToggle: (path: string) => void
  activeId: string | null
  loadingId: string | null
  onSelect: (tab: TabEntry) => void
}) {
  const open = forceOpen || expanded.has(node.path)
  const label = node.isCategory ? node.name : `/${node.name}`

  return (
    <div className="tree-group">
      <button type="button" className="tree-group-header" onClick={() => onToggle(node.path)}>
        <span className="tree-group-chevron">{open ? '▾' : '▸'}</span>
        <span className="tree-group-name">{label}</span>
        <span className="tree-group-count">{leafCount(node)}</span>
      </button>

      {open && (
        <div className="tree-group-body">
          {node.children.map((child) => (
            <TreeGroup
              key={child.path}
              node={child}
              forceOpen={forceOpen}
              expanded={expanded}
              onToggle={onToggle}
              activeId={activeId}
              loadingId={loadingId}
              onSelect={onSelect}
            />
          ))}
          {node.leaves.map((tab) => (
            <RouteItem
              key={tab.id}
              tab={tab}
              isActive={tab.id === activeId}
              isLoading={tab.id === loadingId}
              onSelect={onSelect}
            />
          ))}
        </div>
      )}
    </div>
  )
}

function FlagCard({ tab, isActive, onSelect, timestamp }: {
  tab: TabEntry
  isActive: boolean
  onSelect: (tab: TabEntry) => void
  timestamp?: string
}) {
  const { method, uri } = splitLabel(tab.label)
  const risk = riskOf(tab)
  const sev = risk === 'critical' ? 'critical' : risk === 'high' ? 'high' : risk === 'medium' ? 'medium' : 'low'
  const sevColor = SECURITY_RISK_COLORS[sev] ?? SECURITY_RISK_COLORS.medium

  return (
    <button
      type="button"
      className={`flag-card ${isActive ? 'flag-card--active' : ''}`}
      onClick={() => onSelect(tab)}
    >
      <div className="flag-card-top">
        {timestamp
          ? <span className="flag-card-time">{timestamp}</span>
          : <span className="flag-card-sev" style={{ '--sc': sevColor } as React.CSSProperties}>
              {(SECURITY_SEVERITY_LABELS[sev] ?? sev).toUpperCase()}
            </span>}
        {method && (
          <span className="flag-card-method" style={{ color: METHOD_COLORS[method] }}>{method}</span>
        )}
      </div>
      <div className="flag-card-path">{uri}</div>
      <div className="flag-card-desc">{riskDescription(tab)}</div>
    </button>
  )
}

export function LeftSidebar({
  tabs, activeId, loadingId, onSelect,
  mode, onModeChange, highRiskCount, recentCount, previousAnalyzedAt,
  visibleTypes, counts, onToggle, onShowAll, onHideAll,
}: Props) {
  const [width, setWidth] = useState(DEFAULT_WIDTH)
  const [search, setSearch] = useState('')
  const [visibleMethods, setVisibleMethods] = useState<Set<string>>(new Set(ALL_HTTP_METHODS))
  const [expanded, setExpanded] = useState<Set<string>>(new Set())

  const toggleMethod = useCallback((m: string) => {
    setVisibleMethods((prev) => {
      const next = new Set(prev)
      if (next.has(m)) next.delete(m)
      else next.add(m)
      return next
    })
  }, [])

  const toggleNode = useCallback((path: string) =>
    setExpanded((s) => {
      const n = new Set(s)
      if (n.has(path)) n.delete(path)
      else n.add(path)
      return n
    }), [])

  const isDraggingWidth = useRef(false)
  const startX = useRef(0)
  const startWidth = useRef(DEFAULT_WIDTH)

  const onWidthMouseDown = useCallback((e: React.MouseEvent) => {
    e.preventDefault()
    isDraggingWidth.current = true
    startX.current = e.clientX
    startWidth.current = width

    const onMove = (ev: MouseEvent) => {
      if (!isDraggingWidth.current) return
      const delta = ev.clientX - startX.current
      setWidth(Math.min(MAX_WIDTH, Math.max(MIN_WIDTH, startWidth.current + delta)))
    }
    const onUp = () => {
      isDraggingWidth.current = false
      window.removeEventListener('mousemove', onMove)
      window.removeEventListener('mouseup', onUp)
      document.body.style.cursor = ''
      document.body.style.userSelect = ''
    }

    document.body.style.cursor = 'col-resize'
    document.body.style.userSelect = 'none'
    window.addEventListener('mousemove', onMove)
    window.addEventListener('mouseup', onUp)
  }, [width])

  const query = search.trim().toLowerCase()

  const tree = useMemo(() => {
    const allMethodsVisible = ALL_HTTP_METHODS.every((m) => visibleMethods.has(m))
    const filtered = tabs.filter((t) => {
      if (query && !t.label.toLowerCase().includes(query)) return false
      if (!allMethodsVisible) {
        const firstWord = t.label.split(' ')[0]
        if (firstWord in METHOD_COLORS && !visibleMethods.has(firstWord)) return false
      }
      return true
    })
    return buildTree(filtered)
  }, [tabs, query, visibleMethods])

  const riskTabs = useMemo(
    () => tabs
      .filter((t) => riskOf(t) !== 'none')
      .sort((a, b) => (RISK_ORDER[riskOf(b)] ?? 0) - (RISK_ORDER[riskOf(a)] ?? 0)),
    [tabs],
  )

  const recentTabs = useMemo(
    () => tabs.filter((t) => t.changeStatus === 'new' || t.changeStatus === 'changed'),
    [tabs],
  )

  const modeTabs: { id: 'routes' | 'risks' | 'recent'; label: string; count: number }[] = [
    { id: 'routes', label: 'Routes', count: tabs.length },
    { id: 'risks', label: 'Risks', count: highRiskCount },
    { id: 'recent', label: 'Recent', count: recentCount },
  ]

  return (
    <div className="left-sidebar-resizable" style={{ width }}>
      <div className="left-sidebar">
        <div className="left-search">
          <input
            className="left-search-input"
            type="text"
            placeholder="Search routes…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
          {search && (
            <button type="button" className="left-search-clear" onClick={() => setSearch('')}>×</button>
          )}
        </div>

        <div className="left-method-chips">
          {ALL_HTTP_METHODS.map((m) => (
            <button
              key={m}
              type="button"
              className={`method-chip ${visibleMethods.has(m) ? 'method-chip--on' : ''}`}
              style={{ '--mc': METHOD_COLORS[m] } as React.CSSProperties}
              onClick={() => toggleMethod(m)}
            >
              {m}
            </button>
          ))}
        </div>

        <div className="mode-tabs">
          {modeTabs.map((t) => (
            <button
              key={t.id}
              type="button"
              className={`mode-tab ${mode === t.id ? 'mode-tab--active' : ''}`}
              onClick={() => onModeChange(t.id)}
            >
              {t.label}
              <span
                className={`mode-tab-count ${t.id === 'risks' && mode === 'risks' && t.count > 0 ? 'mode-tab-count--alert' : ''}`}
              >
                {t.count}
              </span>
            </button>
          ))}
        </div>

        <div className="left-content">
          {mode === 'routes' && (
            <div className="route-tree">
              {tree.children.length === 0 && tree.leaves.length === 0 && (
                <div className="left-empty">No routes match.</div>
              )}
              {tree.children.map((child) => (
                <TreeGroup
                  key={child.path}
                  node={child}
                  forceOpen={query.length > 0}
                  expanded={expanded}
                  onToggle={toggleNode}
                  activeId={activeId}
                  loadingId={loadingId}
                  onSelect={onSelect}
                />
              ))}
              {tree.leaves.map((tab) => (
                <RouteItem
                  key={tab.id}
                  tab={tab}
                  isActive={tab.id === activeId}
                  isLoading={tab.id === loadingId}
                  onSelect={onSelect}
                />
              ))}
            </div>
          )}

          {mode === 'risks' && (
            <div className="flag-list">
              {riskTabs.length === 0 && <div className="left-empty">No flagged routes. ✓</div>}
              {riskTabs.map((tab) => (
                <FlagCard key={tab.id} tab={tab} isActive={tab.id === activeId} onSelect={onSelect} />
              ))}
            </div>
          )}

          {mode === 'recent' && (
            <div className="flag-list">
              {recentTabs.length === 0 && (
                <div className="left-empty">Nothing changed since the previous scan.</div>
              )}
              {recentTabs.map((tab) => (
                <FlagCard
                  key={tab.id}
                  tab={tab}
                  isActive={tab.id === activeId}
                  onSelect={onSelect}
                  timestamp={`${tab.changeStatus === 'new' ? 'new' : 'changed'} · ${relativeTime(previousAnalyzedAt)}`}
                />
              ))}
            </div>
          )}
        </div>

        <div className="left-footer">
          <FilterPanel
            visibleTypes={visibleTypes}
            counts={counts}
            onToggle={onToggle}
            onShowAll={onShowAll}
            onHideAll={onHideAll}
          />
        </div>
      </div>
      <Tooltip content="Drag to resize">
        <div className="left-sidebar-drag-handle" onMouseDown={onWidthMouseDown} />
      </Tooltip>
    </div>
  )
}
