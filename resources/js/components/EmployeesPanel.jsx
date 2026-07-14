import React, { useState, useMemo, useEffect } from 'react'
import { router } from '@inertiajs/react'
import { toast } from 'sonner'
import { confirmToast } from '@/lib/confirm'
import { DataTable } from '@/components/DataTable'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { Badge } from '@/components/ui/badge'
import { Trash2, Plus, Pencil, Fingerprint, Send, X, SlidersHorizontal } from 'lucide-react'
import { usePermissions } from '@/lib/permissions'
import EmployeeFingerprintDialog from '@/components/EmployeeFingerprintDialog'
import BulkDistributeDialog from '@/components/BulkDistributeDialog'

// company_id & brand_id hanya untuk dropdown cascading; yang disimpan ke
// karyawan cuma outlet_ids (banyak outlet; brand & company tersirat dari outlet).
const emptyForm = {
  name: '',
  talenta_employee_id: '',
  employee_code: '',
  biometric_id: '',
  company_id: '',
  brand_id: '',
  outlet_ids: [],
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
  // employee.manage = tambah/ubah/hapus karyawan; fingerprint.sync = sebar massal.
  const { can } = usePermissions()
  const canManage = can('employee.manage')
  const canSyncFingerprint = can('fingerprint.sync')

  const [showForm, setShowForm] = useState(false)
  const [editingId, setEditingId] = useState(null)
  const [formData, setFormData] = useState(emptyForm)
  // Karyawan yang dialog sidik jarinya sedang dibuka (null = tertutup).
  const [fpEmployee, setFpEmployee] = useState(null)
  // Seleksi baris untuk sebar massal + status dialog sebar massal.
  const [selected, setSelected] = useState({})
  const [bulkOpen, setBulkOpen] = useState(false)
  // Filter daftar karyawan (semua client-side).
  const [companyFilter, setCompanyFilter] = useState('')
  const [brandFilter, setBrandFilter] = useState('')
  const [outletFilter, setOutletFilter] = useState('')
  const [statusFilter, setStatusFilter] = useState('')       // '' | 'active' | 'inactive'
  const [privilegeFilter, setPrivilegeFilter] = useState('') // '' | '0' | '14'
  const [fpFilter, setFpFilter] = useState('')               // '' | 'has' | 'none'
  const [biometricFilter, setBiometricFilter] = useState('') // '' | 'has' | 'none'
  const [placementFilter, setPlacementFilter] = useState('') // '' | 'placed' | 'unplaced'
  // Panel filter disembunyikan default, ditampilkan lewat toggle.
  const [showFilters, setShowFilters] = useState(false)

  const hasActiveFilters =
    companyFilter || brandFilter || outletFilter || statusFilter ||
    privilegeFilter || fpFilter || biometricFilter || placementFilter

  const resetFilters = () => {
    setCompanyFilter('')
    setBrandFilter('')
    setOutletFilter('')
    setStatusFilter('')
    setPrivilegeFilter('')
    setFpFilter('')
    setBiometricFilter('')
    setPlacementFilter('')
  }

  // Daftar brand & outlet diratakan dari hierarki companies untuk dropdown filter.
  const allBrands = useMemo(
    () =>
      companies.flatMap((c) =>
        (c.brands ?? []).map((b) => ({ id: b.id, name: b.name, company_id: c.id }))
      ),
    [companies]
  )
  const allOutlets = useMemo(
    () =>
      companies.flatMap((c) =>
        (c.brands ?? []).flatMap((b) =>
          (b.outlets ?? []).map((o) => ({
            id: o.id,
            name: o.name,
            brand_id: b.id,
            brand_name: b.name,
            company_id: c.id,
            company_name: c.name,
          }))
        )
      ),
    [companies]
  )
  // Lookup cepat metadata outlet by id (untuk chip & kolom daftar).
  const outletMeta = useMemo(
    () => Object.fromEntries(allOutlets.map((o) => [o.id, o])),
    [allOutlets]
  )
  // Brand yang ditampilkan mengikuti company terpilih (kalau ada).
  const filterBrandOptions = useMemo(
    () => (companyFilter ? allBrands.filter((b) => b.company_id === companyFilter) : allBrands),
    [allBrands, companyFilter]
  )
  // Outlet yang ditampilkan mengikuti company & brand terpilih (kalau ada).
  const filterOutletOptions = useMemo(
    () =>
      allOutlets.filter(
        (o) =>
          (!companyFilter || o.company_id === companyFilter) &&
          (!brandFilter || o.brand_id === brandFilter)
      ),
    [allOutlets, companyFilter, brandFilter]
  )

  // Ganti company -> reset brand & outlet di bawahnya supaya tak nyangkut.
  const onCompanyFilterChange = (value) => {
    setCompanyFilter(value)
    setBrandFilter('')
    setOutletFilter('')
  }
  // Ganti brand -> reset outlet supaya tak nyangkut di outlet brand lain.
  const onBrandFilterChange = (value) => {
    setBrandFilter(value)
    setOutletFilter('')
  }

  const filteredEmployees = useMemo(
    () =>
      employees.filter((e) => {
        const outlets = e.outlets ?? []
        if (companyFilter && !outlets.some((o) => o.company_id === companyFilter)) return false
        if (brandFilter && !outlets.some((o) => o.brand_id === brandFilter)) return false
        if (outletFilter && !outlets.some((o) => o.id === outletFilter)) return false
        if (statusFilter === 'active' && !e.is_active) return false
        if (statusFilter === 'inactive' && e.is_active) return false
        if (privilegeFilter && Number(e.device_privilege) !== Number(privilegeFilter)) return false
        const fpCount = e.fingerprints_count ?? 0
        if (fpFilter === 'has' && fpCount === 0) return false
        if (fpFilter === 'none' && fpCount > 0) return false
        if (biometricFilter === 'has' && !e.biometric_id) return false
        if (biometricFilter === 'none' && e.biometric_id) return false
        if (placementFilter === 'placed' && outlets.length === 0) return false
        if (placementFilter === 'unplaced' && outlets.length > 0) return false
        return true
      }),
    [
      employees, companyFilter, brandFilter, outletFilter, statusFilter,
      privilegeFilter, fpFilter, biometricFilter, placementFilter,
    ]
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
      company_id: '',
      brand_id: '',
      outlet_ids: (emp.outlets ?? []).map((o) => o.id),
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

  // Modal form: tutup dengan Escape & kunci scroll body selama terbuka.
  useEffect(() => {
    if (!showForm) return
    const onKey = (e) => { if (e.key === 'Escape') closeForm() }
    document.addEventListener('keydown', onKey)
    document.body.style.overflow = 'hidden'
    return () => {
      document.removeEventListener('keydown', onKey)
      document.body.style.overflow = ''
    }
  }, [showForm])

  // Ganti company -> reset brand di bawahnya supaya tidak nyangkut.
  const onCompanyChange = (company_id) =>
    setFormData((f) => ({ ...f, company_id, brand_id: '' }))
  const onBrandChange = (brand_id) =>
    setFormData((f) => ({ ...f, brand_id }))

  // Tambah/hapus outlet dari daftar penempatan karyawan (banyak outlet).
  const addOutlet = (outlet_id) => {
    if (!outlet_id) return
    setFormData((f) =>
      f.outlet_ids.includes(outlet_id) ? f : { ...f, outlet_ids: [...f.outlet_ids, outlet_id] }
    )
  }
  const removeOutlet = (outlet_id) =>
    setFormData((f) => ({ ...f, outlet_ids: f.outlet_ids.filter((id) => id !== outlet_id) }))

  const handleSubmit = async (e) => {
    e.preventDefault()
    const url = editingId ? `/api/employees/${editingId}` : '/api/employees'
    const method = editingId ? 'PUT' : 'POST'
    // Backend butuh outlet_ids (+ field karyawan); company/brand cuma alat bantu UI.
    const payload = {
      name: formData.name,
      talenta_employee_id: formData.talenta_employee_id,
      employee_code: formData.employee_code,
      biometric_id: formData.biometric_id || null,
      outlet_ids: formData.outlet_ids,
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
          const outlets = row.original.outlets ?? []
          if (outlets.length === 0) return <span className="text-slate-400">-</span>
          return (
            <div className="flex flex-col gap-1">
              {outlets.map((o) => (
                <div key={o.id} className="text-sm">
                  <div className="font-medium">{o.name}</div>
                  <div className="text-xs text-slate-500">
                    {[o.company_name, o.brand_name].filter(Boolean).join(' / ')}
                  </div>
                </div>
              ))}
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
        cell: ({ row }) =>
          canManage ? (
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
          ) : (
            <span className="text-xs text-slate-400">-</span>
          ),
      },
    ],
    [selected, allSelected, selectableIds, canManage]
  )

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          {canSyncFingerprint && selectedIds.length > 0 && (
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
        {canManage && (
          <Button onClick={openCreate} className="gap-2">
            <Plus className="h-4 w-4" />
            Add Employee
          </Button>
        )}
      </div>

      {showForm && canManage && (
        <div
          className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4"
          onMouseDown={(e) => { if (e.target === e.currentTarget) closeForm() }}
        >
          <div className="my-10 w-full max-w-3xl rounded-lg bg-white dark:bg-slate-900 shadow-xl">
            <div className="flex items-start justify-between gap-4 border-b border-slate-200 dark:border-slate-800 p-5">
              <div className="space-y-1">
                <h3 className="text-lg font-semibold text-slate-900 dark:text-slate-50">
                  {editingId ? 'Edit Karyawan' : 'Tambah Karyawan'}
                </h3>
                <p className="text-sm text-slate-500 dark:text-slate-400">
                  Master karyawan. Penempatan dipilih bertingkat: Company → Brand → Outlet.
                  Biometric ID (PIN) dipakai sebagai identitas di semua mesin.
                </p>
              </div>
              <Button variant="ghost" size="sm" onClick={closeForm}><X className="h-4 w-4" /></Button>
            </div>
            <form onSubmit={handleSubmit} className="space-y-4 p-5">
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
                    value=""
                    onChange={(e) => { addOutlet(e.target.value); }}
                    disabled={!formData.brand_id}
                  >
                    <option value="">— tambah outlet —</option>
                    {outletOptions
                      .filter((o) => !formData.outlet_ids.includes(o.id))
                      .map((o) => (
                        <option key={o.id} value={o.id}>{o.name}</option>
                      ))}
                  </Select>
                  <p className="text-xs text-slate-500">
                    Pilih untuk menambah. Satu karyawan boleh punya lebih dari satu outlet.
                  </p>
                </div>
              </div>

              {/* Daftar outlet terpilih (banyak) */}
              <div className="space-y-2">
                <label className="text-sm font-medium text-slate-900 dark:text-slate-50">
                  Outlet Terdaftar ({formData.outlet_ids.length})
                </label>
                {formData.outlet_ids.length === 0 ? (
                  <p className="text-sm text-slate-400">Belum ada outlet dipilih.</p>
                ) : (
                  <div className="flex flex-wrap gap-2">
                    {formData.outlet_ids.map((id) => {
                      const o = outletMeta[id]
                      return (
                        <span
                          key={id}
                          className="inline-flex items-center gap-1.5 rounded-full bg-slate-100 dark:bg-slate-800 px-3 py-1 text-sm"
                        >
                          <span className="font-medium">{o?.name ?? id}</span>
                          {o?.brand_name && <span className="text-xs text-slate-500">· {o.brand_name}</span>}
                          <button
                            type="button"
                            onClick={() => removeOutlet(id)}
                            className="text-slate-400 hover:text-red-600"
                            title="Hapus outlet"
                          >
                            <X className="h-3.5 w-3.5" />
                          </button>
                        </span>
                      )
                    })}
                  </div>
                )}
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
              <div className="flex gap-2 border-t border-slate-200 dark:border-slate-800 pt-4">
                <Button type="submit">{editingId ? 'Update' : 'Save'}</Button>
                <Button type="button" variant="outline" onClick={closeForm}>Cancel</Button>
              </div>
            </form>
          </div>
        </div>
      )}

      <Card>
        <CardHeader>
          <div className="space-y-4">
            <div className="flex items-start justify-between gap-3">
              <div>
                <CardTitle>Daftar Karyawan</CardTitle>
                <CardDescription>
                  {filteredEmployees.length}
                  {filteredEmployees.length !== employees.length ? ` dari ${employees.length}` : ''} karyawan
                </CardDescription>
              </div>
              <Button
                variant={showFilters ? 'default' : 'outline'}
                className="gap-2 shrink-0"
                onClick={() => setShowFilters((v) => !v)}
              >
                <SlidersHorizontal className="h-4 w-4" />
                Filter
                {hasActiveFilters && (
                  <span className="ml-0.5 inline-flex h-2 w-2 rounded-full bg-indigo-400" title="Filter aktif" />
                )}
              </Button>
            </div>
            {showFilters && (
            <>
            <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
              <div className="space-y-1.5">
                <label className="text-sm font-medium text-slate-900 dark:text-slate-50">Company</label>
                <Select value={companyFilter} onChange={(e) => onCompanyFilterChange(e.target.value)}>
                  <option value="">Semua Company</option>
                  {companies.map((c) => (
                    <option key={c.id} value={c.id}>{c.name}</option>
                  ))}
                </Select>
              </div>
              <div className="space-y-1.5">
                <label className="text-sm font-medium text-slate-900 dark:text-slate-50">Brand</label>
                <Select value={brandFilter} onChange={(e) => onBrandFilterChange(e.target.value)}>
                  <option value="">Semua Brand</option>
                  {filterBrandOptions.map((b) => (
                    <option key={b.id} value={b.id}>{b.name}</option>
                  ))}
                </Select>
              </div>
              <div className="space-y-1.5">
                <label className="text-sm font-medium text-slate-900 dark:text-slate-50">Outlet</label>
                <Select value={outletFilter} onChange={(e) => setOutletFilter(e.target.value)}>
                  <option value="">Semua Outlet</option>
                  {filterOutletOptions.map((o) => (
                    <option key={o.id} value={o.id}>{o.name}</option>
                  ))}
                </Select>
              </div>
              <div className="space-y-1.5">
                <label className="text-sm font-medium text-slate-900 dark:text-slate-50">Status</label>
                <Select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
                  <option value="">Semua Status</option>
                  <option value="active">Aktif</option>
                  <option value="inactive">Nonaktif</option>
                </Select>
              </div>
              <div className="space-y-1.5">
                <label className="text-sm font-medium text-slate-900 dark:text-slate-50">Hak Akses Mesin</label>
                <Select value={privilegeFilter} onChange={(e) => setPrivilegeFilter(e.target.value)}>
                  <option value="">Semua Hak Akses</option>
                  {PRIVILEGE_OPTIONS.map((o) => (
                    <option key={o.value} value={o.value}>{o.label}</option>
                  ))}
                </Select>
              </div>
              <div className="space-y-1.5">
                <label className="text-sm font-medium text-slate-900 dark:text-slate-50">Sidik Jari (DB)</label>
                <Select value={fpFilter} onChange={(e) => setFpFilter(e.target.value)}>
                  <option value="">Semua</option>
                  <option value="has">Sudah ada</option>
                  <option value="none">Belum ada</option>
                </Select>
              </div>
              <div className="space-y-1.5">
                <label className="text-sm font-medium text-slate-900 dark:text-slate-50">Biometric ID</label>
                <Select value={biometricFilter} onChange={(e) => setBiometricFilter(e.target.value)}>
                  <option value="">Semua</option>
                  <option value="has">Sudah diisi</option>
                  <option value="none">Belum diisi</option>
                </Select>
              </div>
              <div className="space-y-1.5">
                <label className="text-sm font-medium text-slate-900 dark:text-slate-50">Penempatan</label>
                <Select value={placementFilter} onChange={(e) => setPlacementFilter(e.target.value)}>
                  <option value="">Semua</option>
                  <option value="placed">Punya outlet</option>
                  <option value="unplaced">Tanpa outlet</option>
                </Select>
              </div>
            </div>
            {hasActiveFilters && (
              <div>
                <Button variant="outline" className="gap-2" onClick={resetFilters}>
                  <X className="h-4 w-4" />
                  Reset Filter
                </Button>
              </div>
            )}
            </>
            )}
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
