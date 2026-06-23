import React, { useState, useMemo } from 'react'
import { router } from '@inertiajs/react'
import { toast } from 'sonner'
import { confirmToast } from '@/lib/confirm'
import { DataTable } from '@/components/DataTable'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { Trash2, Plus } from 'lucide-react'

export default function MappingsPanel({ mappings = [], machines = [], employees = [] }) {
  const [showForm, setShowForm] = useState(false)
  const [filters, setFilters] = useState({ machine_id: '' })
  const [employeeId, setEmployeeId] = useState('')
  const [bulkBio, setBulkBio] = useState('')
  // rows: { [machineId]: { checked: bool, bio: string } }
  const [rows, setRows] = useState({})

  const setRow = (machineId, patch) =>
    setRows((prev) => ({ ...prev, [machineId]: { ...prev[machineId], ...patch } }))

  const applyBulkBio = () => {
    setRows((prev) => {
      const next = { ...prev }
      machines.forEach((m) => {
        if (next[m.id]?.checked) next[m.id] = { ...next[m.id], bio: bulkBio }
      })
      return next
    })
  }

  const resetForm = () => {
    setEmployeeId('')
    setBulkBio('')
    setRows({})
    setShowForm(false)
  }

  const filteredMappings = filters.machine_id
    ? mappings.filter(m => m.machine_id === filters.machine_id)
    : mappings

  const columns = useMemo(
    () => [
      {
        accessorKey: 'machine_id',
        header: 'Machine',
        cell: ({ row }) => {
          const machineId = row.getValue('machine_id')
          return machines.find(m => m.id === machineId)?.name || 'Unknown'
        },
      },
      {
        accessorKey: 'biometric_id_lokal',
        header: 'Biometric ID',
        cell: ({ row }) => <div className="font-mono">{row.getValue('biometric_id_lokal')}</div>,
      },
      {
        accessorKey: 'talenta_employee_id',
        header: 'Talenta ID',
        cell: ({ row }) => <div className="font-mono">{row.getValue('talenta_employee_id')}</div>,
      },
      {
        accessorKey: 'employee_name',
        header: 'Employee Name',
        cell: ({ row }) => row.getValue('employee_name') || '-',
      },
      {
        id: 'actions',
        header: 'Action',
        cell: ({ row }) => (
          <Button
            onClick={() => deleteMapping(row.original.id)}
            variant="destructive"
            size="sm"
            className="gap-2"
          >
            <Trash2 className="h-3 w-3" />
            Delete
          </Button>
        ),
      },
    ],
    [machines]
  )

  const handleSubmit = async (e) => {
    e.preventDefault()

    const selected = machines
      .filter((m) => rows[m.id]?.checked)
      .map((m) => ({ machine_id: m.id, biometric_id_lokal: (rows[m.id]?.bio || '').trim() }))

    if (!employeeId) return toast.error('Pilih karyawan dulu.')
    if (selected.length === 0) return toast.error('Centang minimal satu mesin.')
    const missing = selected.filter((s) => !s.biometric_id_lokal)
    if (missing.length > 0) return toast.error('Isi Biometric ID untuk semua mesin yang dicentang.')

    try {
      const response = await fetch('/api/employee-mappings/batch', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
        },
        body: JSON.stringify({ employee_id: employeeId, mappings: selected }),
      })
      const body = await response.json().catch(() => ({}))
      if (response.ok) {
        toast.success(body.message || 'Mapping tersimpan')
        resetForm()
        router.reload()
      } else {
        const msg = body?.errors
          ? Object.values(body.errors).flat().join('\n')
          : body.message || 'Gagal menyimpan mapping'
        toast.error(msg)
      }
    } catch (err) {
      console.error('Failed to create mappings:', err)
    }
  }

  const deleteMapping = (id) => {
    confirmToast({
      message: 'Hapus mapping ini?',
      confirmLabel: 'Hapus',
      destructive: true,
      onConfirm: async () => {
        try {
          await fetch(`/api/employee-mappings/${id}`, {
            method: 'DELETE',
            headers: {
              'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
          })
          router.reload()
        } catch (err) {
          console.error('Failed to delete mapping:', err)
        }
      },
    })
  }

  const stats = {
    total: mappings.length,
    machines: machines.length,
    avgPerMachine: machines.length > 0 ? Math.round(mappings.length / machines.length) : 0,
  }

  return (
    <div className="space-y-6">
      <div className="flex justify-end">
        <Button onClick={() => setShowForm(!showForm)} className="gap-2">
          <Plus className="h-4 w-4" />
          {showForm ? 'Cancel' : 'Add Mapping'}
        </Button>
      </div>

      {/* Add Mapping Form (batch: 1 karyawan -> banyak mesin) */}
      {showForm && (
        <Card>
          <CardHeader>
            <CardTitle>Mapping Karyawan ke Mesin</CardTitle>
            <CardDescription>
              Pilih karyawan, lalu centang semua mesin tempat dia aktif dan isi Biometric ID-nya.
              Satu karyawan bisa dipetakan ke banyak mesin sekaligus.
            </CardDescription>
          </CardHeader>
          <CardContent>
            {employees.length === 0 ? (
              <p className="text-sm text-yellow-600">
                Belum ada karyawan. Tambahkan dulu di tab Karyawan.
              </p>
            ) : (
              <form onSubmit={handleSubmit} className="space-y-4">
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <label className="text-sm font-medium text-slate-900 dark:text-slate-50">
                      Employee
                    </label>
                    <Select required value={employeeId} onChange={(e) => setEmployeeId(e.target.value)}>
                      <option value="">Select Employee</option>
                      {employees.map((emp) => (
                        <option key={emp.id} value={emp.id}>
                          {emp.name} ({emp.talenta_employee_id})
                        </option>
                      ))}
                    </Select>
                  </div>
                  <div className="space-y-2">
                    <label className="text-sm font-medium text-slate-900 dark:text-slate-50">
                      Biometric ID sama untuk semua
                    </label>
                    <div className="flex gap-2">
                      <Input
                        type="text"
                        value={bulkBio}
                        onChange={(e) => setBulkBio(e.target.value)}
                        placeholder="mis. 1001 — lalu klik Terapkan"
                      />
                      <Button type="button" variant="outline" onClick={applyBulkBio}>
                        Terapkan
                      </Button>
                    </div>
                  </div>
                </div>

                <div className="border rounded-md divide-y dark:divide-slate-800 dark:border-slate-800">
                  {machines.map((m) => {
                    const row = rows[m.id] || {}
                    return (
                      <div key={m.id} className="flex items-center gap-3 p-3">
                        <input
                          type="checkbox"
                          className="h-4 w-4"
                          checked={!!row.checked}
                          onChange={(e) => setRow(m.id, { checked: e.target.checked })}
                        />
                        <div className="flex-1 min-w-0">
                          <div className="text-sm font-medium text-slate-900 dark:text-slate-50">{m.name}</div>
                          <div className="text-xs text-slate-500">{m.serial_number}</div>
                        </div>
                        <Input
                          type="text"
                          value={row.bio || ''}
                          disabled={!row.checked}
                          onChange={(e) => setRow(m.id, { bio: e.target.value })}
                          placeholder="Biometric ID"
                          className="w-40"
                        />
                      </div>
                    )
                  })}
                </div>

                <div className="flex gap-2">
                  <Button type="submit">Simpan Mapping</Button>
                  <Button type="button" variant="outline" onClick={resetForm}>
                    Cancel
                  </Button>
                </div>
              </form>
            )}
          </CardContent>
        </Card>
      )}

      {/* Filter */}
      <Card>
        <CardContent className="pt-6">
          <div className="space-y-2">
            <label className="text-sm font-medium text-slate-900 dark:text-slate-50">
              Filter by Machine
            </label>
            <Select
              value={filters.machine_id}
              onChange={(e) => setFilters({ machine_id: e.target.value })}
              className="w-full md:w-48"
            >
              <option value="">All Machines</option>
              {machines.map(m => (
                <option key={m.id} value={m.id}>{m.name}</option>
              ))}
            </Select>
          </div>
        </CardContent>
      </Card>

      {/* Mappings Table */}
      <Card>
        <CardHeader>
          <CardTitle>Mappings</CardTitle>
          <CardDescription>Biometric ID to Talenta Employee mappings</CardDescription>
        </CardHeader>
        <CardContent>
          <DataTable columns={columns} data={filteredMappings} filterPlaceholder="Filter mappings..." />
        </CardContent>
      </Card>

      {/* Stats */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-sm font-medium">Total Mappings</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{stats.total}</div>
          </CardContent>
        </Card>
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-sm font-medium">Machines</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{stats.machines}</div>
          </CardContent>
        </Card>
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-sm font-medium">Avg per Machine</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{stats.avgPerMachine}</div>
          </CardContent>
        </Card>
      </div>
    </div>
  )
}
