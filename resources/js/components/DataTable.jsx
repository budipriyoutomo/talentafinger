import React, { useEffect, useState } from 'react'
import {
  flexRender,
  getCoreRowModel,
  getFilteredRowModel,
  getPaginationRowModel,
  getSortedRowModel,
  useReactTable,
} from '@tanstack/react-table'
import { ArrowDown, ArrowUp, ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight, Inbox, Search } from 'lucide-react'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'

export function DataTable({
  columns,
  data,
  filterPlaceholder = 'Filter...',
  // Seleksi baris opsional (dikontrol pemanggil). rowSelection = { [rowId]: true }.
  enableRowSelection = false,
  rowSelection,
  onRowSelectionChange,
  getRowId,
  // Pilihan "Rows per page". Bila onPageSizeChange diisi, pemilihan diserahkan ke
  // pemanggil (mis. paginasi server-side) — kalau tidak, tabel yang mengatur sendiri.
  pageSizeOptions = [10, 20, 30, 40, 50],
  pageSize,
  onPageSizeChange,
}) {
  const [sorting, setSorting] = useState([])
  const [columnFilters, setColumnFilters] = useState([])
  const [globalFilter, setGlobalFilter] = useState('')
  const [pagination, setPagination] = useState({
    pageIndex: 0,
    pageSize: pageSize ?? pageSizeOptions[0],
  })

  // Ikuti ukuran halaman dari luar (kalau dikontrol pemanggil).
  useEffect(() => {
    if (pageSize) setPagination((prev) => ({ ...prev, pageIndex: 0, pageSize }))
  }, [pageSize])

  const table = useReactTable({
    data,
    columns,
    getCoreRowModel: getCoreRowModel(),
    getPaginationRowModel: getPaginationRowModel(),
    getSortedRowModel: getSortedRowModel(),
    getFilteredRowModel: getFilteredRowModel(),
    enableRowSelection,
    getRowId,
    state: {
      sorting,
      columnFilters,
      globalFilter,
      pagination,
      ...(enableRowSelection ? { rowSelection: rowSelection ?? {} } : {}),
    },
    onSortingChange: setSorting,
    onColumnFiltersChange: setColumnFilters,
    onGlobalFilterChange: setGlobalFilter,
    onPaginationChange: setPagination,
    onRowSelectionChange,
  })

  return (
    <div className="w-full space-y-4">
      {/* Global Filter */}
      <div className="relative max-w-sm">
        <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2" />
        <Input
          placeholder={filterPlaceholder}
          value={globalFilter}
          onChange={(event) => setGlobalFilter(event.target.value)}
          className="pl-9"
        />
      </div>

      {/* Table */}
      <div className="bg-card overflow-hidden rounded-xl border shadow-xs">
        <Table>
          <TableHeader>
            {table.getHeaderGroups().map((headerGroup) => (
              <TableRow key={headerGroup.id}>
                {headerGroup.headers.map((header) => (
                  <TableHead
                    key={header.id}
                    onClick={header.column.getToggleSortingHandler()}
                    className={header.column.getCanSort() ? 'cursor-pointer select-none' : ''}
                  >
                    <div className="flex items-center gap-1.5">
                      {flexRender(header.column.columnDef.header, header.getContext())}
                      {header.column.getIsSorted() &&
                        (header.column.getIsSorted() === 'desc' ? (
                          <ArrowDown className="text-primary h-3.5 w-3.5" />
                        ) : (
                          <ArrowUp className="text-primary h-3.5 w-3.5" />
                        ))}
                    </div>
                  </TableHead>
                ))}
              </TableRow>
            ))}
          </TableHeader>
          <TableBody>
            {table.getRowModel().rows?.length ? (
              table.getRowModel().rows.map((row) => (
                <TableRow key={row.id} data-state={row.getIsSelected() && 'selected'}>
                  {row.getVisibleCells().map((cell) => (
                    <TableCell key={cell.id}>
                      {flexRender(cell.column.columnDef.cell, cell.getContext())}
                    </TableCell>
                  ))}
                </TableRow>
              ))
            ) : (
              <TableRow>
                <TableCell colSpan={columns.length} className="h-32 text-center">
                  <div className="text-muted-foreground flex flex-col items-center gap-2">
                    <Inbox className="h-8 w-8 opacity-40" />
                    <span className="text-sm">Tidak ada data.</span>
                  </div>
                </TableCell>
              </TableRow>
            )}
          </TableBody>
        </Table>
      </div>

      {/* Pagination */}
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="text-muted-foreground flex-1 text-sm">
          {table.getFilteredSelectedRowModel().rows.length} of{' '}
          {table.getFilteredRowModel().rows.length} row(s) selected.
        </div>

        <div className="flex items-center gap-1.5">
          <Button
            variant="outline"
            size="sm"
            onClick={() => table.setPageIndex(0)}
            disabled={!table.getCanPreviousPage()}
          >
            <ChevronsLeft className="h-4 w-4" />
          </Button>
          <Button
            variant="outline"
            size="sm"
            onClick={() => table.previousPage()}
            disabled={!table.getCanPreviousPage()}
          >
            <ChevronLeft className="h-4 w-4" />
          </Button>

          <div className="flex items-center gap-1">
            <span className="px-2 text-sm font-medium tabular-nums">
              Page {table.getState().pagination.pageIndex + 1} of{' '}
              {table.getPageCount()}
            </span>
          </div>

          <Button
            variant="outline"
            size="sm"
            onClick={() => table.nextPage()}
            disabled={!table.getCanNextPage()}
          >
            <ChevronRight className="h-4 w-4" />
          </Button>
          <Button
            variant="outline"
            size="sm"
            onClick={() => table.setPageIndex(table.getPageCount() - 1)}
            disabled={!table.getCanNextPage()}
          >
            <ChevronsRight className="h-4 w-4" />
          </Button>
        </div>

        <div className="text-muted-foreground flex items-center gap-2 text-sm">
          <span>Rows per page:</span>
          <select
            value={table.getState().pagination.pageSize}
            onChange={(e) => {
              const size = Number(e.target.value)
              if (onPageSizeChange) onPageSizeChange(size)
              else table.setPageSize(size)
            }}
            className="border-input bg-card text-foreground h-8 cursor-pointer rounded-lg border px-2 shadow-xs"
          >
            {pageSizeOptions.map((size) => (
              <option key={size} value={size}>
                {size}
              </option>
            ))}
          </select>
        </div>
      </div>
    </div>
  )
}
