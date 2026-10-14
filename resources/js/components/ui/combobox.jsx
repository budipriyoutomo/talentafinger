import React, { useEffect, useMemo, useRef, useState } from 'react'
import { Check, ChevronsUpDown, Search, X } from 'lucide-react'
import { cn } from '@/lib/utils'

// Batas opsi yang dirender sekaligus: daftar karyawan bisa ribuan, cukup
// tampilkan sebagian dan minta user mempersempit lewat pencarian.
const MAX_VISIBLE = 100

/**
 * Dropdown dengan kotak pencarian (pengganti <Select> bila opsinya banyak).
 * options = [{ value, label, description?, keywords? }]; value '' = belum dipilih.
 */
export function Combobox({
  options = [],
  value = '',
  onChange,
  placeholder = 'Pilih...',
  searchPlaceholder = 'Cari...',
  emptyText = 'Tidak ditemukan.',
  className,
}) {
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const [active, setActive] = useState(0)
  const rootRef = useRef(null)
  const inputRef = useRef(null)
  const listRef = useRef(null)

  const selected = options.find((o) => String(o.value) === String(value))

  const matches = useMemo(() => {
    const terms = query.toLowerCase().split(/\s+/).filter(Boolean)
    if (terms.length === 0) return options
    return options.filter((o) => {
      const haystack = [o.label, o.description, o.keywords].filter(Boolean).join(' ').toLowerCase()
      return terms.every((t) => haystack.includes(t))
    })
  }, [options, query])
  const visible = matches.slice(0, MAX_VISIBLE)

  useEffect(() => {
    if (!open) return
    setQuery('')
    setActive(0)
    inputRef.current?.focus()
    const onDown = (e) => !rootRef.current?.contains(e.target) && setOpen(false)
    document.addEventListener('mousedown', onDown)
    return () => document.removeEventListener('mousedown', onDown)
  }, [open])

  useEffect(() => setActive(0), [query])

  useEffect(() => {
    listRef.current?.children[active]?.scrollIntoView({ block: 'nearest' })
  }, [active])

  const pick = (option) => {
    setOpen(false)
    if (String(option.value) !== String(value)) onChange?.(option.value)
  }

  const onKeyDown = (e) => {
    if (e.key === 'ArrowDown') {
      e.preventDefault()
      setActive((i) => Math.min(i + 1, visible.length - 1))
    } else if (e.key === 'ArrowUp') {
      e.preventDefault()
      setActive((i) => Math.max(i - 1, 0))
    } else if (e.key === 'Enter') {
      e.preventDefault()
      if (visible[active]) pick(visible[active])
    } else if (e.key === 'Escape') {
      setOpen(false)
    }
  }

  return (
    <div ref={rootRef} className={cn('relative', className)}>
      <button
        type="button"
        role="combobox"
        aria-expanded={open}
        onClick={() => setOpen((o) => !o)}
        className={cn(
          'border-input flex h-9 w-full items-center justify-between gap-2 rounded-md border bg-transparent px-3 py-1 text-left text-sm shadow-xs outline-none',
          'focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]'
        )}
      >
        <span className={cn('truncate', !selected && 'text-muted-foreground')}>
          {selected ? selected.label : placeholder}
        </span>
        <span className="flex shrink-0 items-center gap-1">
          {selected && (
            <X
              className="text-muted-foreground hover:text-foreground h-3.5 w-3.5"
              aria-label="Hapus pilihan"
              onClick={(e) => {
                e.stopPropagation()
                onChange?.('')
              }}
            />
          )}
          <ChevronsUpDown className="text-muted-foreground h-4 w-4" />
        </span>
      </button>

      {open && (
        <div className="bg-popover text-popover-foreground absolute z-40 mt-1 w-full min-w-64 rounded-md border shadow-md">
          <div className="flex items-center gap-2 border-b px-3">
            <Search className="text-muted-foreground h-4 w-4 shrink-0" />
            <input
              ref={inputRef}
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              onKeyDown={onKeyDown}
              placeholder={searchPlaceholder}
              className="placeholder:text-muted-foreground h-9 w-full bg-transparent text-sm outline-none"
            />
          </div>
          <ul ref={listRef} role="listbox" className="max-h-64 overflow-y-auto p-1">
            {visible.length === 0 && <li className="text-muted-foreground px-2 py-3 text-center text-sm">{emptyText}</li>}
            {visible.map((o, i) => {
              const isSelected = String(o.value) === String(value)
              return (
                <li
                  key={o.value}
                  role="option"
                  aria-selected={isSelected}
                  onMouseEnter={() => setActive(i)}
                  onMouseDown={(e) => e.preventDefault()}
                  onClick={() => pick(o)}
                  className={cn(
                    'flex cursor-pointer items-start gap-2 rounded-sm px-2 py-1.5 text-sm',
                    i === active && 'bg-accent text-accent-foreground'
                  )}
                >
                  <Check className={cn('mt-0.5 h-4 w-4 shrink-0', isSelected ? 'opacity-100' : 'opacity-0')} />
                  <span className="min-w-0">
                    <span className="block truncate">{o.label}</span>
                    {o.description && <span className="text-muted-foreground block truncate text-xs">{o.description}</span>}
                  </span>
                </li>
              )
            })}
            {matches.length > MAX_VISIBLE && (
              <li className="text-muted-foreground px-2 py-1.5 text-center text-xs">
                {matches.length - MAX_VISIBLE} lainnya, ketik untuk mempersempit
              </li>
            )}
          </ul>
        </div>
      )}
    </div>
  )
}
