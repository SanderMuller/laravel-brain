import { useRef, useState, useCallback, useMemo } from 'react'
import { FilterPanel } from './FilterPanel'
import { ComplexityPanel } from './ComplexityPanel'
import { Tooltip } from './Tooltip'
import { SECURITY_RISK_COLORS } from '../utils/graphConstants'
import type { TabEntry, GraphData } from '../types/graph'

const RISK_ORDER: Record<string, number> = { none: 0, low: 1, medium: 2, high: 3, critical: 4 }

interface IssueSummary {
  count: number
  risk: string
  security: number
  n1: number
  fatMethod: number
  fatClass: number
}

function aggregateIssues(tabs: TabEntry[]): IssueSummary {
  const sum: IssueSummary = { count: 0, risk: 'none', security: 0, n1: 0, fatMethod: 0, fatClass: 0 }
  for (const t of tabs) {
    if (!t.issueCount || t.issueCount <= 0) continue
    sum.count += t.issueCount
    sum.security += t.securityCount ?? 0
    sum.n1 += t.n1Count ?? 0
    sum.fatMethod += t.fatMethodCount ?? 0
    sum.fatClass += t.fatClassCount ?? 0
    const level = t.riskLevel ?? 'none'
    if ((RISK_ORDER[level] ?? 0) > (RISK_ORDER[sum.risk] ?? 0)) sum.risk = level
  }
  return sum
}

function tabSummary(tab: TabEntry): IssueSummary {
  return {
    count: tab.issueCount ?? 0,
    risk: tab.riskLevel ?? 'none',
    security: tab.securityCount ?? 0,
    n1: tab.n1Count ?? 0,
    fatMethod: tab.fatMethodCount ?? 0,
    fatClass: tab.fatClassCount ?? 0,
  }
}

const N1_COLOR = '#f97316'
const FAT_COLOR = '#facc15'

// Security: shield
const SecurityIcon = () => (
  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
  </svg>
)

// N+1: looping arrows (a query repeated in a loop)
const N1Icon = () => (
  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
    <polyline points="17 1 21 5 17 9" />
    <path d="M3 11V9a4 4 0 0 1 4-4h14" />
    <polyline points="7 23 3 19 7 15" />
    <path d="M21 13v2a4 4 0 0 1-4 4H3" />
  </svg>
)

// Fat method/class: stacked box
const FatIcon = () => (
  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
    <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" />
    <polyline points="3.27 6.96 12 12.01 20.73 6.96" />
    <line x1="12" y1="22.08" x2="12" y2="12" />
  </svg>
)

function IssueChip({ count, color, title, icon }: {
  count: number
  color: string
  title: string
  icon: React.ReactNode
}) {
  if (count <= 0) return null
  return (
    <Tooltip content={title}>
      <span
        className="left-nav-issue-badge"
        style={{ '--issue-color': color } as React.CSSProperties}
      >
        {icon}
        {count}
      </span>
    </Tooltip>
  )
}

function IssueBadge({ summary }: { summary: IssueSummary }) {
  if (summary.count <= 0) return null
  const fat = summary.fatMethod + summary.fatClass
  const securityColor = summary.risk !== 'none'
    ? SECURITY_RISK_COLORS[summary.risk]
    : SECURITY_RISK_COLORS.medium

  const fatParts: string[] = []
  if (summary.fatMethod) fatParts.push(`${summary.fatMethod} fat ${summary.fatMethod === 1 ? 'method' : 'methods'}`)
  if (summary.fatClass) fatParts.push(`${summary.fatClass} fat ${summary.fatClass === 1 ? 'class' : 'classes'}`)

  return (
    <span className="left-nav-issue-badges">
      <IssueChip
        count={summary.security}
        color={securityColor}
        icon={<SecurityIcon />}
        title={`${summary.security} security ${summary.security === 1 ? 'issue' : 'issues'} (${summary.risk}). Open to see details.`}
      />
      <IssueChip
        count={summary.n1}
        color={N1_COLOR}
        icon={<N1Icon />}
        title={`${summary.n1} N+1 ${summary.n1 === 1 ? 'query' : 'queries'}. Open to see details.`}
      />
      <IssueChip
        count={fat}
        color={FAT_COLOR}
        icon={<FatIcon />}
        title={`${fatParts.join(', ')}. Open to see details.`}
      />
    </span>
  )
}

const MIN_WIDTH = 160
const MAX_WIDTH = 480
const DEFAULT_WIDTH = 240

interface TreeNode {
  /** Segment label, e.g. 'users'. For non-HTTP buckets this is the category name. */
  name: string
  /** Unique key/path for expand state + react key, e.g. 'api/users'. */
  path: string
  /** A non-HTTP category bucket (commands/channels/…) renders flat, no leading slash. */
  isCategory: boolean
  children: TreeNode[]
  leaves: TabEntry[]
}

interface Props {
  tabs: TabEntry[]
  activeId: string | null
  loadingId: string | null
  onSelect: (tab: TabEntry) => void
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
  GET:    '#4ade80',
  POST:   '#60a5fa',
  PUT:    '#f59e0b',
  PATCH:  '#a78bfa',
  DELETE: '#f87171',
}

const ALL_HTTP_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as const

function RouteItem({ tab, isActive, isLoading, onSelect }: {
  tab: TabEntry
  isActive: boolean
  isLoading: boolean
  onSelect: (tab: TabEntry) => void
}) {
  const [first, ...rest] = tab.label.split(' ')
  const hasMethod = first in METHOD_COLORS
  const method = hasMethod ? first : null
  const uri    = hasMethod ? rest.join(' ') : tab.label
  const color  = method ? METHOD_COLORS[method] : '#94a3b8'

  return (
    <Tooltip content={`Open this lifecycle graph. Nodes: ${tab.nodeCount} (symbols in the graph). Edges: ${tab.edgeCount} (references between them).`}>
      <button
        className={`left-nav-item ${isActive ? 'left-nav-item--active' : ''}`}
        type="button"
        onClick={() => onSelect(tab)}
      >
        {method
          ? <span className="left-nav-method" style={{ color }}>{method}</span>
          : <span className="left-nav-method" style={{ color }}>›</span>
        }
        <span className="left-nav-uri">{uri}</span>
        {tab.issueCount ? <IssueBadge summary={tabSummary(tab)} /> : null}
        {isLoading && <span className="left-nav-badge">…</span>}
      </button>
    </Tooltip>
  )
}

// Non-HTTP tabs (commands/channels/schedules/filament) have no URL path
// structure, so they get a flat category bucket instead of the URL tree.
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

/**
 * Build a recursive tree keyed by URL path segments.
 *
 * A route is normally filed under its parent path (all segments except the
 * last), so   GET /api/users/profile/update  and  …/create  both land under
 * api → users → profile, with the full URL kept on the row.
 *
 * Exception: when a route's *own* full path is also a directory for deeper
 * routes, it is filed *inside* that group rather than as a sibling in the
 * parent. e.g. with
 *   GET /divorce-child-custody-memes            (index)
 *   GET /divorce-child-custody-memes/{id}
 *   GET /divorce-child-custody-memes/{slug}
 * the index route joins the `/divorce-child-custody-memes` group alongside
 * its siblings instead of sitting one level up.
 */
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

  // Collect every directory path: each route's parent path and all of its
  // ancestor prefixes. A full path that appears here has deeper routes.
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
    // File inside the full-path group when it's a directory for other routes;
    // otherwise file under the parent path.
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

function collectLeaves(node: TreeNode): TabEntry[] {
  return [...node.leaves, ...node.children.flatMap(collectLeaves)]
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
  const issues = aggregateIssues(collectLeaves(node))
  const label = node.isCategory ? node.name : `/${node.name}`

  return (
    <div className="left-nav-prefix-group">
      <Tooltip content={node.isCategory
        ? `${node.name} — ${leafCount(node)} item(s).`
        : `URL segment /${node.name} — routes nested under this path.`}>
        <button
          type="button"
          className="left-nav-prefix-header"
          onClick={() => onToggle(node.path)}
        >
          <span className="left-nav-prefix-chevron">{open ? '▾' : '▸'}</span>
          <span className="left-nav-prefix-icon">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
              <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z" />
            </svg>
          </span>
          <span className="left-nav-prefix-name">{label}</span>
          <IssueBadge summary={issues} />
          <span className="left-nav-prefix-count">{leafCount(node)}</span>
        </button>
      </Tooltip>

      {open && (
        <>
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
        </>
      )}
    </div>
  )
}

export function LeftSidebar({
  tabs, activeId, loadingId, onSelect,
  visibleTypes, counts, onToggle, onShowAll, onHideAll,
  graphData, complexityFilter, onComplexityFilterChange, onNodeSelect, selectedId,
}: Props) {
  const [leftTab, setLeftTab] = useState<'routes' | 'complexity'>('routes')
  const [topHeight, setTopHeight] = useState(320)
  const [width, setWidth] = useState(DEFAULT_WIDTH)
  const [search, setSearch] = useState('')
  const [visibleMethods, setVisibleMethods] = useState<Set<string>>(new Set(ALL_HTTP_METHODS))

  const toggleMethod = useCallback((m: string) => {
    setVisibleMethods(prev => {
      const next = new Set(prev)
      if (next.has(m)) { next.delete(m) } else { next.add(m) }
      return next
    })
  }, [])
  const containerRef = useRef<HTMLDivElement>(null)
  const dragStartY = useRef(0)
  const dragStartHeight = useRef(0)
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
      const next = Math.min(MAX_WIDTH, Math.max(MIN_WIDTH, startWidth.current + delta))
      setWidth(next)
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

  // Track expanded tree nodes by path (collapsed by default)
  const [expanded, setExpanded] = useState<Set<string>>(new Set())

  const toggleNode = useCallback((path: string) =>
    setExpanded((s) => {
      const n = new Set(s)
      if (n.has(path)) { n.delete(path) } else { n.add(path) }
      return n
    }), [])

  const onMouseDown = useCallback((e: React.MouseEvent) => {
    e.preventDefault()
    dragStartY.current = e.clientY
    dragStartHeight.current = topHeight

    const onMouseMove = (e: MouseEvent) => {
      const container = containerRef.current
      if (!container) return
      const delta = e.clientY - dragStartY.current
      const totalHeight = container.clientHeight
      setTopHeight(Math.max(80, Math.min(totalHeight - 80, dragStartHeight.current + delta)))
    }
    const onMouseUp = () => {
      window.removeEventListener('mousemove', onMouseMove)
      window.removeEventListener('mouseup', onMouseUp)
    }
    window.addEventListener('mousemove', onMouseMove)
    window.addEventListener('mouseup', onMouseUp)
  }, [topHeight])

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

  return (
    <div className="left-sidebar-resizable" style={{ width }}>
    <div className="left-sidebar" ref={containerRef}>
      <div className="left-sidebar-tabs">
        <Tooltip content="Browse routes and commands grouped by file. Selecting one loads its lifecycle graph.">
          <button
            type="button"
            className={`left-sidebar-tab ${leftTab === 'routes' ? 'left-sidebar-tab--active' : ''}`}
            onClick={() => setLeftTab('routes')}
          >
            Routes
          </button>
        </Tooltip>
        <Tooltip content="List methods ranked by cyclomatic complexity for the current graph.">
          <button
            type="button"
            className={`left-sidebar-tab ${leftTab === 'complexity' ? 'left-sidebar-tab--active' : ''}`}
            onClick={() => setLeftTab('complexity')}
          >
            Complexity
          </button>
        </Tooltip>
      </div>

      <div className="left-sidebar-top" style={{ height: topHeight }}>

        {leftTab === 'complexity' ? (
          <ComplexityPanel
            graphData={graphData}
            filter={complexityFilter}
            onFilterChange={onComplexityFilterChange}
            onNodeSelect={onNodeSelect}
            selectedId={selectedId}
          />
        ) : (
        <>
        <div className="left-nav-search">
          <Tooltip content="Filter the route list by HTTP method, URI segment, or command name (substring match).">
            <input
              className="left-nav-search-input"
              type="text"
              placeholder="Search routes…"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
          </Tooltip>
          {search && (
            <Tooltip content="Clear route search">
              <button type="button" className="left-nav-search-clear" onClick={() => setSearch('')}>
                ×
              </button>
            </Tooltip>
          )}
        </div>
        <div className="left-nav-method-filters">
          {ALL_HTTP_METHODS.map((m) => (
            <Tooltip key={m} content={`${visibleMethods.has(m) ? 'Hide' : 'Show'} ${m} routes`}>
              <button
                type="button"
                className={`left-nav-method-badge ${!visibleMethods.has(m) ? 'left-nav-method-badge--off' : ''}`}
                style={{ '--method-color': METHOD_COLORS[m] } as React.CSSProperties}
                onClick={() => toggleMethod(m)}
              >
                {m}
              </button>
            </Tooltip>
          ))}
        </div>
        <div className="left-nav">
          {tree.children.length === 0 && tree.leaves.length === 0 && (
            <div className="left-nav-empty">No routes match.</div>
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
          {/* Single-segment routes (e.g. /login) live at the root. */}
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
        </>
        )}
      </div>

      <div className="left-sidebar-handle" onMouseDown={onMouseDown} />

      <div className="left-sidebar-bottom">
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
