import { useRef, useState, useCallback, useMemo } from 'react'
import { FilterPanel } from './FilterPanel'
import { ComplexityPanel } from './ComplexityPanel'
import { Tooltip } from './Tooltip'
import type { TabEntry, GraphData } from '../types/graph'

const MIN_WIDTH = 160
const MAX_WIDTH = 480
const DEFAULT_WIDTH = 240

interface PrefixGroup {
  prefix: string
  tabs: TabEntry[]
}

interface FileGroup {
  fileName: string
  prefixGroups: PrefixGroup[]
}

interface Props {
  fileGroups: FileGroup[]
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
        {isLoading && <span className="left-nav-badge">…</span>}
      </button>
    </Tooltip>
  )
}

export function LeftSidebar({
  fileGroups, activeId, loadingId, onSelect,
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

  // Track expanded state (collapsed by default)
  const [expandedFiles, setExpandedFiles] = useState<Set<string>>(new Set())
  const [expandedPrefixes, setExpandedPrefixes] = useState<Set<string>>(new Set())

  const toggleFile = (fileName: string) =>
    setExpandedFiles((s) => {
      const n = new Set(s)
      if (n.has(fileName)) {
        n.delete(fileName)
      } else {
        n.add(fileName)
      }
      return n
    })

  const togglePrefix = (key: string) =>
    setExpandedPrefixes((s) => {
      const n = new Set(s)
      if (n.has(key)) {
        n.delete(key)
      } else {
        n.add(key)
      }
      return n
    })

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

  const filteredFileGroups = useMemo(() => {
    const q = search.trim().toLowerCase()
    const allMethodsVisible = ALL_HTTP_METHODS.every(m => visibleMethods.has(m))
    return fileGroups
      .map((fg) => ({
        ...fg,
        prefixGroups: fg.prefixGroups
          .map((pg) => ({
            ...pg,
            tabs: pg.tabs.filter((t) => {
              if (q && !t.label.toLowerCase().includes(q)) return false
              if (!allMethodsVisible) {
                const firstWord = t.label.split(' ')[0]
                if (firstWord in METHOD_COLORS && !visibleMethods.has(firstWord)) return false
              }
              return true
            }),
          }))
          .filter((pg) => pg.tabs.length > 0),
      }))
      .filter((fg) => fg.prefixGroups.length > 0)
  }, [fileGroups, search, visibleMethods])

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
          {filteredFileGroups.map((fg) => {
            const fileOpen = expandedFiles.has(fg.fileName)
            const totalRoutes = fg.prefixGroups.reduce((s, pg) => s + pg.tabs.length, 0)
            return (
              <div key={fg.fileName} className="left-nav-file-group">
                <Tooltip content={`Route definitions from ${fg.fileName}. Expand to see URI groups and endpoints.`}>
                  <button
                    type="button"
                    className="left-nav-file-header"
                    onClick={() => toggleFile(fg.fileName)}
                  >
                    <span className="left-nav-file-chevron">{fileOpen ? '▾' : '▸'}</span>
                    <span className="left-nav-file-icon">
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                        <polyline points="14 2 14 8 20 8" />
                      </svg>
                    </span>
                    <span className="left-nav-file-name">{fg.fileName}</span>
                    <span className="left-nav-file-count">{totalRoutes}</span>
                  </button>
                </Tooltip>

                {fileOpen && fg.prefixGroups.map((pg) => {
                  // '_flat' = non-HTTP tabs (commands/channels): no prefix wrapper
                  if (pg.prefix === '_flat') {
                    return pg.tabs.map((tab) => (
                      <RouteItem
                        key={tab.id}
                        tab={tab}
                        isActive={tab.id === activeId}
                        isLoading={tab.id === loadingId}
                        onSelect={onSelect}
                      />
                    ))
                  }

                  const prefixKey = `${fg.fileName}::${pg.prefix}`
                  const prefixOpen = expandedPrefixes.has(prefixKey)

                  return (
                    <div key={pg.prefix} className="left-nav-prefix-group">
                      <Tooltip content={`URL segment /${pg.prefix} — routes that share this path prefix.`}>
                        <button
                          type="button"
                          className="left-nav-prefix-header"
                          onClick={() => togglePrefix(prefixKey)}
                        >
                          <span className="left-nav-prefix-chevron">{prefixOpen ? '▾' : '▸'}</span>
                          <span className="left-nav-prefix-icon">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                              <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z" />
                            </svg>
                          </span>
                          <span className="left-nav-prefix-name">/{pg.prefix}</span>
                          <span className="left-nav-prefix-count">{pg.tabs.length}</span>
                        </button>
                      </Tooltip>

                      {prefixOpen && pg.tabs.map((tab) => (
                        <RouteItem
                          key={tab.id}
                          tab={tab}
                          isActive={tab.id === activeId}
                          isLoading={tab.id === loadingId}
                          onSelect={onSelect}
                        />
                      ))}
                    </div>
                  )
                })}
              </div>
            )
          })}
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
