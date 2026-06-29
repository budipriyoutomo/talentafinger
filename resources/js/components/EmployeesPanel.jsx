import React, { useState, useMemo } from 'react'
import { router } from '@inertiajs/react'
import { toast } from 'sonner'
import { confirmToast } from '@/lib/confirm'
import { DataTable } from '@/components/DataTable'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { Badge } from '@/components/ui/badge'
import { Trash2, Plus, Pencil, Fingerprint, Send, X } from 'lucide-react'
import EmployeeFingerprintDialog from '@/components/EmployeeFingerprintDialog'
import BulkDistributeDialog from '@/components/BulkDistributeDialog'

// company_id & brand_id hanya untuk dropdown cascading; yang disimpan ke
// karyawan cuma outlet_id (brand & company tersirat dari outlet).
const emptyForm = {
  name: '',
  talenta_employee_id: '',
  employee_code: '',
  biometric_id: '',
  company_id: '',
  brand_id: '',
  outlet_id: '',
  device_privilege: 0,
  is_active: true,
}

// Hak akses di mesin (privilege ZKTeco). 0=user biasa, 14=admin/super.
const PRIVILEGE_OPTIONS = [
  { value: 0, label: 'User biasa' },
  { value: 14, label: 'Admin (super)' },
]
const privilegeLabel = (v) =>
  PRIVILEGE_OPTIONS.find((o) => o.value === Number(v))?.label ?? `Privilege ${v}`

function csrf() {
  return document.querySelector('meta[name="csrf-token"]').content
}

export default function EmployeesPanel({ employees = [], companies = [], machines = [] }) {
  const [showForm, setShowForm] = useState(false)
  const [editingId, setEditingId] = useState(null)
  const [formData, setFormData] = useState(emptyForm)
  // Karyawan yang dialog sidik jarinya sedang dibuka (null = tertutup).
  const [fpEmployee, setFpEmployee] = useState(null)
  // Seleksi baris untuk sebar massal + status dialog sebar massal.
  const [selected, setSelected] = useState({})
  const [bulkOpen, setBulkOpen] = useState(false)
  // Filter daftar karyawan berdasarkan Brand & Outlet (client-side).
  const [brandFilter, setBrandFilter] = useState('')
  const [outletFilter, setOutletFilter] = useState('')

  // Daftar brand & outlet diratakan dari hierarki companies untuk dropdown filter.
  const allBrands = useMemo(
    () => companies.flatMap((c) => (c.brands ?? []).map((b) => ({ id: b.id, name: b.name }))),
    [companies]
  )
  const allOutlets = useMemo(
    () =>
      companies.flatMap((c) =>
        (c.brands ?? []).flatMap((b) =>
          (b.outlets ?? []).map((o) => ({ id: o.id, name: o.name, brand_id: b.id }))
        )
      ),
    [companies]
  )
  // Outlet yang ditampilkan mengikuti brand terpilih (kalau ada).
  const filterOutletOptions = useMemo(
    () => (brandFilter ? allOutlets.filter((o) => o.brand_id === brandFilter) : allOutlets),
    [allOutlets, brandFilter]
  )

  // Ganti brand -> reset outlet supaya tak nyangkut di outlet brand lain.
  const onBrandFilterChange = (value) => {
    setBrandFilter(value)
    setOutletFilter('')
  }

  const filteredEmployees = useMemo(
    () =>
      employees.filter((e) => {
        if (brandFilter && e.brand_id !== brandFilter) return false
        if (outletFilter && e.outlet_id !== outletFilter) return false
        return true
      }),
    [employees, brandFilter, outletFilter]
  )

  // Hanya karyawan (yang lolos filter) dan punya template di DB yang bisa disebar.
  const selectableIds = useMemo(
    () => filteredEmployees.filter((e) => (e.fingerprints_count ?? 0) > 0).map((e) => e.id),
    [filteredEmployees]
  )
  const selectedIds = useMemo(
    () => selectableIds.filter((id) => selected[id]),
    [selectableIds, selected]
  )
  const allSelected = selectableIds.length > 0 && selectedIds.length === selectableIds.length
  const selectedEmployees = useMemo(
    () => employees.filter((e) => selectedIds.includes(e.id)),
    [employees, selectedIds]
  )

  const toggleSelect = (id) => setSelected((p) => ({ ...p, [id]: !p[id] }))
  const toggleSelectAll = () =>
    setSelected(allSelected ? {} : Object.fromEntries(selectableIds.map((id) => [id, true])))
  const clearSelection = () => setSelected({})

  // Brand & outlet yang tampil mengikuti pilihan di atasnya.
  const brandOptions = useMemo(
    () => companies.find((c) => c.id === formData.company_id)?.brands ?? [],
    [companies, formData.company_id]
  )
  const outletOptions = useMemo(
    () => brandOptions.find((b) => b.id === formData.brand_id)?.outlets ?? [],
    [brandOptions, formData.brand_id]
  )

  const openCreate = () => {
    setEditingId(null)
    setFormData(emptyForm)
    setShowForm(true)
  }

  const openEdit = (emp) => {
    setEditingId(emp.id)
    setFormData({
      name: emp.name ?? '',
      talenta_employee_id: emp.talenta_employee_id ?? '',
      employee_code: emp.employee_code ?? '',
      biometric_id: emp.biometric_id ?? '',
      company_id: emp.company_id ?? '',
      brand_id: emp.brand_id ?? '',
      outlet_id: emp.outlet_id ?? '',
      device_privilege: emp.device_privilege ?? 0,
      is_active: emp.is_active,
    })
    setShowForm(true)
  }

  const closeForm = () => {
    setFormData(emptyForm)
    setEditingId(null)
    setShowForm(false)
  }

  // Ganti company -> reset brand & outlet di bawahnya supaya tidak nyangkut.
  const onCompanyChange = (company_id) =>
    setFormData((f) => ({ ...f, company_id, brand_id: '', outlet_id: '' }))
  const onBrandChange = (brand_id) =>
    setFormData((f) => ({ ...f, brand_id, outlet_id: '' }))

  const handleSubmit = async (e) => {
    e.preventDefault()
    const url = editingId ? `/api/employees/${editingId}` : '/api/employees'
    const method = editingId ? 'PUT' : 'POST'
    // Backend hanya butuh outlet_id (+ field karyawan); company/brand cuma alat bantu UI.
    const payload = {
      name: formData.name,
      talenta_employee_id: formData.talenta_employee_id,
      employee_code: formData.employee_code,
      biometric_id: formData.biometric_id || null,
      outlet_id: formData.outlet_id || null,
      device_privilege: Number(formData.device_privilege) || 0,
      is_active: formData.is_active,
    }
    try {
      const res = await fetch(url, {
        method,
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrf(),
        },
        body: JSON.stringify(payload),
      })
      if (res.ok) {
        closeForm()
        router.reload({ only: ['employees'] })
      } else {
        const body = await res.json().catch(() => ({}))
        const msg = body?.errors
          ? Object.values(body.errors).flat().join('\n')
          : body.message || 'Gagal menyimpan karyawan'
        toast.error(msg)
      }
    } catch (err) {
      console.error('Failed to save employee:', err)
    }
  }

  const deleteEmployee = (id) => {
    confirmToast({
      message: 'Hapus karyawan ini?',
      description: 'Semua mapping biometric-nya ikut terhapus.',
      confirmLabel: 'Hapus',
      destructive: true,
      onConfirm: async () => {
        try {
          await fetch(`/api/employees/${id}`, {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': csrf() },
          })
          router.reload({ only: ['employees'] })
        } catch (err) {
          console.error('Failed to delete employee:', err)
        }
      },
    })
  }

  const columns = useMemo(
    () => [
      {
        id: 'select',
        header: () => (
          <input
            type="checkbox"
            className="h-4 w-4"
            checked={allSelected}
            onChange={toggleSelectAll}
            title="Pilih semua (yang punya sidik jari di DB)"
          />
        ),
        cell: ({ row }) =>
          (row.original.fingerprints_count ?? 0) > 0 ? (
            <input
              type="checkbox"
              className="h-4 w-4"
              checked={!!selected[row.original.id]}
              onChange={() => toggleSelect(row.original.id)}
            />
          ) : null,
      },
      {
        accessorKey: 'name',
        header: 'Nama',
        cell: ({ row }) => (
          <div className="flex items-center gap-2">
            <span className="font-medium">{row.getValue('name')}</span>
            {Number(row.original.device_privilege) !== 0 && (
              <Badge variant="secondary" title={privilegeLabel(row.original.device_privilege)}>Admin</Badge>
            )}
          </div>
        ),
      },
      {
        accessorKey: 'talenta_employee_id',
        header: 'Talenta ID',
        cell: ({ row }) => <div className="font-mono text-sm">{row.getValue('talenta_employee_id')}</div>,
      },
      {
        accessorKey: 'biometric_id',
        header: 'Biometric ID',
        cell: ({ row }) =>
          row.getValue('biometric_id') ? (
            <div className="font-mono text-sm">{row.getValue('biometric_id')}</div>
          ) : (
            <span className="text-slate-400">-</span>
          ),
      },
      {
        accessorKey: 'employee_code',
        header: 'Kode / NIK',
        cell: ({ row }) => <div className="text-sm">{row.getValue('employee_code') || '-'}</div>,
      },
      {
        id: 'outlet',
        header: 'Outlet',
        cell: ({ row }) => {
          const e = row.original
          if (!e.outlet_name) return <span className="text-slate-400">-</span>
          return (
            <div className="text-sm">
              <div className="font-medium">{e.outlet_name}</div>
              <div className="text-xs text-slate-500">
                {[e.company_name, e.brand_name].filter(Boolean).join(' / ')}
              </div>
            </div>
          )
        },
      },
      {
        id: 'fingerprints',
        header: 'Sidik Jari (DB)',
        cell: ({ row }) => {
          const e = row.original
          const n = e.fingerprints_count ?? 0
          return (
            <Button onClick={() => setFpEmployee(e)} variant="outline" size="sm" className="gap-1.5">
              <Fingerprint className={`h-3.5 w-3.5 ${n > 0 ? 'text-indigo-500' : 'text-slate-400'}`} />
              {n > 0 ? `${n} jari` : 'Kelola'}
            </Button>
          )
        },
      },
      {
        accessorKey: 'is_active',
        header: 'Status',
        cell: ({ row }) => (
          <Badge variant={row.getValue('is_active') ? 'success' : 'destructive'}>
            {row.getValue('is_active') ? 'aktif' : 'nonaktif'}
          </Badge>
        ),
      },
      {
        id: 'actions',
        header: 'Aksi',
        cell: ({ row }) => (
          <div className="flex gap-2">
            <Button onClick={() => openEdit(row.original)} variant="outline" size="sm" className="gap-1">
              <Pencil className="h-3 w-3" />
              Edit
            </Button>
            <Button onClick={() => deleteEmployee(row.original.id)} variant="destructive" size="sm" className="gap-1">
              <Trash2 className="h-3 w-3" />
              Hapus
            </Button>
          </div>
        ),
      },
    ],
    [selected, allSelected, selectableIds]
  )

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          {selectedIds.length > 0 && (
            <>
              <Button onClick={() => setBulkOpen(true)} className="gap-2">
                <Send className="h-4 w-4" />
                Sebar Terpilih ({selectedIds.length})
              </Button>
              <Button variant="outline" onClick={clearSelection} className="gap-2">
                <X className="h-4 w-4" />
                Bersihkan
              </Button>
            </>
          )}
        </div>
        <Button onClick={showForm ? closeForm : openCreate} className="gap-2">
          <Plus className="h-4 w-4" />
          {showForm ? 'Cancel' : 'Add Employee'}
        </Button>
      </div>

      {showForm && (
        <Card>
          <CardHeader>
            <CardTitle>{editingId ? 'Edit Karyawan' : 'Tambah Karyawan'}</CardTitle>
            <CardDescription>
              Master karyawan. Penempatan dipilih bertingkat: Company → Brand → Outlet.
              Biometric ID (PIN) dipakai sebagai identitas di semua mesin.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <form onSubmit={handleSubmit} className="space-y-4">
              <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div className="space-y-2">
                  <label className="text-sm font-medium text-slate-900 dark:text-slate-50">Nama</label>
                  <Input
                    type="text"
                    required
                    value={formData.name}
                    onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                    placeholder="e.g., Budi Santoso"
                  />
                </div>
                <div className="space-y-2">
                  <label className="text-sm font-medium text-slate-900 dark:text-slate-50">Talenta Employee ID</label>
                  <Input
                    type="text"
                    required
                    value={formData.talenta_employee_id}
                    onChange={(e) => setFormData({ ...formData, talenta_employee_id: e.target.value })}
                    placeholder="e.g., EMP001"
                  />
                </div>
                <div className="space-y-2">
                  <label className="text-sm font-medium text-slate-900 dark:text-slate-50">Kode / NIK (opsional)</label>
                  <Input
                    type="text"
                    value={formData.employee_code}
                    onChange={(e) => setFormData({ ...formData, employee_code: e.target.value })}
                    placeholder="opsional"
                  />
                </div>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div className="space-y-2">
                  <label className="text-sm font-medium text-slate-900 dark:text-slate-50">Company</label>
                  <Select
                    value={formData.company_id}
                    onChange={(e) => onCompanyChange(e.target.value)}
                  >
                    <option value="">— pilih company —</option>
                    {companies.map((c) => (
                      <option key={c.id} value={c.id}>{c.name}</option>
                    ))}
                  </Select>
                </div>
                <div className="space-y-2">
                  <label className="text-sm font-medium text-slate-900 dark:text-slate-50">Brand</label>
                  <Select
                    value={formData.brand_id}
                    onChange={(e) => onBrandChange(e.target.value)}
                    disabled={!formData.company_id}
                  >
                    <option value="">— pilih brand —</option>
                    {brandOptions.map((b) => (
                      <option key={b.id} value={b.id}>{b.name}</option>
                    ))}
                  </Select>
                </div>
                <div className="space-y-2">
                  <label className="text-sm font-medium text-slate-900 dark:text-slate-50">Outlet</label>
                  <Select
                    value={formData.outlet_id}
                    onChange={(e) => setFormData({ ...formData, outlet_id: e.target.value })}
                    disabled={!formData.brand_id}
                  >
                    <option value="">— pilih outlet —</option>
                    {outletOptions.map((o) => (
                      <option key={o.id} value={o.id}>{o.name}</option>
                    ))}
                  </Select>
                </div>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div className="space-y-2">
                  <label className="text-sm font-medium text-slate-900 dark:text-slate-50">Biometric ID</label>
                  <Input
                    type="text"
                    value={formData.biometric_id}
                    onChange={(e) => setFormData({ ...formData, biometric_id: e.target.value })}
                    placeholder="PIN di mesin, mis. 101"
                  />
                  <p className="text-xs text-slate-500">
                    Dipakai sebagai dasar tarik/sebar sidik jari dari/ke mesin.
                  </p>
                </div>
                <div className="space-y-2">
                  <label className="text-sm font-medium text-slate-900 dark:text-slate-50">Hak Akses Mesin</label>
                  <Select
                    value={formData.device_privilege}
                    onChange={(e) => setFormData({ ...formData, device_privilege: Number(e.target.value) })}
                  >
                    {PRIVILEGE_OPTIONS.map((o) => (
                      <option key={o.value} value={o.value}>{o.label}</option>
                    ))}
                  </Select>
                  <p className="text-xs text-slate-500">
                    Role saat sidik jari disebar ke mesin. Akan diperbarui otomatis bila menarik dari mesin.
                  </p>
                </div>
              </div>

              <label className="flex items-center gap-2 text-sm font-medium text-slate-900 dark:text-slate-50">
                <input
                  type="checkbox"
                  className="h-4 w-4"
                  checked={formData.is_active}
                  onChange={(e) => setFormData({ ...formData, is_active: e.target.checked })}
                />
                Aktif
              </label>
              <div className="flex gap-2">
                <Button type="submit">{editingId ? 'Update' : 'Save'}</Button>
                <Button type="button" variant="outline" onClick={closeForm}>Cancel</Button>
              </div>
            </form>
          </CardContent>
        </Card>
      )}

      <Card>
        <CardHeader>
          <div className="space-y-4">
            <div>
              <CardTitle>Daftar Karyawan</CardTitle>
              <CardDescription>
                {filteredEmployees.length}
                {filteredEmployees.length !== employees.length ? ` dari ${employees.length}` : ''} karyawan
              </CardDescription>
            </div>
            <div className="flex flex-col sm:flex-row sm:items-end gap-3">
              <div className="space-y-1.5 sm:w-48">
                <label className="text-sm font-medium text-slate-900 dark:text-slate-50">Brand</label>
                <Select value={brandFilter} onChange={(e) => onBrandFilterChange(e.target.value)}>
                  <option value="">Semua Brand</option>
                  {allBrands.map((b) => (
                    <option key={b.id} value={b.id}>{b.name}</option>
                  ))}
                </Select>
              </div>
              <div className="space-y-1.5 sm:w-48">
                <label className="text-sm font-medium text-slate-900 dark:text-slate-50">Outlet</label>
                <Select value={outletFilter} onChange={(e) => setOutletFilter(e.target.value)}>
                  <option value="">Semua Outlet</option>
                  {filterOutletOptions.map((o) => (
                    <option key={o.id} value={o.id}>{o.name}</option>
                  ))}
                </Select>
              </div>
              {(brandFilter || outletFilter) && (
                <Button
                  variant="outline"
                  className="gap-2"
                  onClick={() => { setBrandFilter(''); setOutletFilter('') }}
                >
                  <X className="h-4 w-4" />
                  Reset
                </Button>
              )}
            </div>
          </div>
        </CardHeader>
        <CardContent>
          <DataTable columns={columns} data={filteredEmployees} filterPlaceholder="Cari nama / Talenta ID..." />
        </CardContent>
      </Card>

      {fpEmployee && (
        <EmployeeFingerprintDialog
          employee={employees.find((e) => e.id === fpEmployee.id) ?? fpEmployee}
          machines={machines}
          onClose={() => setFpEmployee(null)}
        />
      )}

      {bulkOpen && (
        <BulkDistributeDialog
          employees={selectedEmployees}
          machines={machines}
          onClose={() => setBulkOpen(false)}
        />
      )}
    </div>
  )
}
