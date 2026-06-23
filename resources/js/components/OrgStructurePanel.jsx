import React from 'react'
import { router } from '@inertiajs/react'
import { toast } from 'sonner'
import { confirmToast } from '@/lib/confirm'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Building2, Tag, Store, Plus, Pencil, Trash2 } from 'lucide-react'

function csrf() {
  return document.querySelector('meta[name="csrf-token"]').content
}

// Helper request JSON kecil; tampilkan error validasi bila ada.
async function api(url, method, body) {
  const res = await fetch(url, {
    method,
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-CSRF-TOKEN': csrf(),
    },
    body: body ? JSON.stringify(body) : undefined,
  })
  if (!res.ok) {
    const b = await res.json().catch(() => ({}))
    const msg = b?.errors ? Object.values(b.errors).flat().join('\n') : b.message || 'Operasi gagal'
    toast.error(msg)
    return false
  }
  return true
}

function reload() {
  router.reload({ only: ['companies'] })
}

export default function OrgStructurePanel({ companies = [] }) {
  // Company
  const addCompany = async () => {
    const name = prompt('Nama Company baru:')?.trim()
    if (!name) return
    if (await api('/api/companies', 'POST', { name })) reload()
  }
  const editCompany = async (c) => {
    const name = prompt('Ubah nama Company:', c.name)?.trim()
    if (!name || name === c.name) return
    if (await api(`/api/companies/${c.id}`, 'PUT', { name })) reload()
  }
  const deleteCompany = (c) => {
    confirmToast({
      message: `Hapus company "${c.name}"?`,
      description: 'Semua brand & outlet di bawahnya ikut terhapus, dan penempatan karyawan terkait dikosongkan.',
      confirmLabel: 'Hapus',
      destructive: true,
      onConfirm: async () => {
        if (await api(`/api/companies/${c.id}`, 'DELETE')) reload()
      },
    })
  }

  // Brand
  const addBrand = async (company) => {
    const name = prompt(`Nama Brand baru di ${company.name}:`)?.trim()
    if (!name) return
    if (await api('/api/brands', 'POST', { company_id: company.id, name })) reload()
  }
  const editBrand = async (company, b) => {
    const name = prompt('Ubah nama Brand:', b.name)?.trim()
    if (!name || name === b.name) return
    if (await api(`/api/brands/${b.id}`, 'PUT', { company_id: company.id, name })) reload()
  }
  const deleteBrand = (b) => {
    confirmToast({
      message: `Hapus brand "${b.name}"?`,
      description: 'Semua outlet di bawahnya ikut terhapus.',
      confirmLabel: 'Hapus',
      destructive: true,
      onConfirm: async () => {
        if (await api(`/api/brands/${b.id}`, 'DELETE')) reload()
      },
    })
  }

  // Outlet
  const addOutlet = async (brand) => {
    const name = prompt(`Nama Outlet baru di ${brand.name}:`)?.trim()
    if (!name) return
    if (await api('/api/outlets', 'POST', { brand_id: brand.id, name })) reload()
  }
  const editOutlet = async (brand, o) => {
    const name = prompt('Ubah nama Outlet:', o.name)?.trim()
    if (!name || name === o.name) return
    if (await api(`/api/outlets/${o.id}`, 'PUT', { brand_id: brand.id, name })) reload()
  }
  const deleteOutlet = (o) => {
    confirmToast({
      message: `Hapus outlet "${o.name}"?`,
      confirmLabel: 'Hapus',
      destructive: true,
      onConfirm: async () => {
        if (await api(`/api/outlets/${o.id}`, 'DELETE')) reload()
      },
    })
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <p className="text-sm text-slate-500">
          Hierarki penempatan karyawan: <strong>Company → Brand → Outlet</strong>.
        </p>
        <Button onClick={addCompany} className="gap-2">
          <Plus className="h-4 w-4" />
          Tambah Company
        </Button>
      </div>

      {companies.length === 0 && (
        <Card>
          <CardContent className="py-10 text-center text-slate-500">
            Belum ada company. Klik “Tambah Company” untuk mulai.
          </CardContent>
        </Card>
      )}

      {companies.map((company) => (
        <Card key={company.id}>
          <CardHeader>
            <div className="flex items-center justify-between">
              <CardTitle className="flex items-center gap-2">
                <Building2 className="h-5 w-5 text-indigo-500" />
                {company.name}
                <Badge variant="secondary">{company.brands?.length ?? 0} brand</Badge>
              </CardTitle>
              <div className="flex gap-2">
                <Button onClick={() => addBrand(company)} variant="outline" size="sm" className="gap-1">
                  <Plus className="h-3 w-3" /> Brand
                </Button>
                <Button onClick={() => editCompany(company)} variant="outline" size="sm" className="gap-1">
                  <Pencil className="h-3 w-3" />
                </Button>
                <Button onClick={() => deleteCompany(company)} variant="destructive" size="sm" className="gap-1">
                  <Trash2 className="h-3 w-3" />
                </Button>
              </div>
            </div>
          </CardHeader>
          <CardContent className="space-y-4">
            {(company.brands ?? []).length === 0 && (
              <p className="text-sm text-slate-400 italic">Belum ada brand.</p>
            )}
            {(company.brands ?? []).map((brand) => (
              <div key={brand.id} className="rounded-lg border border-slate-200 dark:border-slate-800 p-3">
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-2 font-medium">
                    <Tag className="h-4 w-4 text-emerald-500" />
                    {brand.name}
                    <Badge variant="secondary">{brand.outlets?.length ?? 0} outlet</Badge>
                  </div>
                  <div className="flex gap-2">
                    <Button onClick={() => addOutlet(brand)} variant="outline" size="sm" className="gap-1">
                      <Plus className="h-3 w-3" /> Outlet
                    </Button>
                    <Button onClick={() => editBrand(company, brand)} variant="outline" size="sm">
                      <Pencil className="h-3 w-3" />
                    </Button>
                    <Button onClick={() => deleteBrand(brand)} variant="destructive" size="sm">
                      <Trash2 className="h-3 w-3" />
                    </Button>
                  </div>
                </div>
                {(brand.outlets ?? []).length > 0 && (
                  <ul className="mt-3 space-y-1 pl-6">
                    {brand.outlets.map((outlet) => (
                      <li key={outlet.id} className="flex items-center justify-between text-sm">
                        <span className="flex items-center gap-2">
                          <Store className="h-3.5 w-3.5 text-slate-400" />
                          {outlet.name}
                        </span>
                        <div className="flex gap-1">
                          <Button onClick={() => editOutlet(brand, outlet)} variant="ghost" size="sm">
                            <Pencil className="h-3 w-3" />
                          </Button>
                          <Button onClick={() => deleteOutlet(outlet)} variant="ghost" size="sm">
                            <Trash2 className="h-3 w-3 text-red-500" />
                          </Button>
                        </div>
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            ))}
          </CardContent>
        </Card>
      ))}
    </div>
  )
}
