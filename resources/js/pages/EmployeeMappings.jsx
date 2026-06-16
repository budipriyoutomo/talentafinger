import React, { useState, useMemo } from 'react'
import { Head, router } from '@inertiajs/react'
import Layout from '../layouts/Layout'
import { DataTable } from '@/components/DataTable'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { Trash2, Plus } from 'lucide-react'

export default function EmployeeMappings({ mappings = [], machines = [] }) {
  const [showForm, setShowForm] = useState(false)
  const [filters, setFilters] = useState({ machine_id: '' })
  const [formData, setFormData] = useState({
    machine_id: '',
    biometric_id_lokal: '',
    talenta_employee_id: '',
    employee_name: '',
  })

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
    try {
      const response = await fetch('/api/employee-mappings', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
        },
        body: JSON.stringify(formData),
      })
      if (response.ok) {
        setFormData({
          machine_id: '',
          biometric_id_lokal: '',
          talenta_employee_id: '',
          employee_name: '',
        })
        setShowForm(false)
        router.reload()
      }
    } catch (err) {
      console.error('Failed to create mapping:', err)
    }
  }

  const deleteMapping = async (id) => {
    if (confirm('Are you sure you want to delete this mapping?')) {
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
    }
  }

  const stats = {
    total: mappings.length,
    machines: machines.length,
    avgPerMachine: machines.length > 0 ? Math.round(mappings.length / machines.length) : 0,
  }

  return (
    <Layout>
      <Head title="Employee Mappings" />

      <div className="space-y-6">
        <div className="flex justify-between items-center">
          <h1 className="text-3xl font-bold text-slate-900 dark:text-slate-50">Employee Mappings</h1>
          <Button onClick={() => setShowForm(!showForm)} className="gap-2">
            <Plus className="h-4 w-4" />
            {showForm ? 'Cancel' : 'Add Mapping'}
          </Button>
        </div>

        {/* Add Mapping Form */}
        {showForm && (
          <Card>
            <CardHeader>
              <CardTitle>Create Mapping</CardTitle>
              <CardDescription>Link a biometric ID to a Mekari Talenta employee</CardDescription>
            </CardHeader>
            <CardContent>
              <form onSubmit={handleSubmit} className="space-y-4">
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <label className="text-sm font-medium text-slate-900 dark:text-slate-50">
                      Machine
                    </label>
                    <Select
                      required
                      value={formData.machine_id}
                      onChange={(e) => setFormData({ ...formData, machine_id: e.target.value })}
                    >
                      <option value="">Select Machine</option>
                      {machines.map(m => (
                        <option key={m.id} value={m.id}>{m.name}</option>
                      ))}
                    </Select>
                  </div>
                  <div className="space-y-2">
                    <label className="text-sm font-medium text-slate-900 dark:text-slate-50">
                      Biometric ID
                    </label>
                    <Input
                      type="text"
                      required
                      value={formData.biometric_id_lokal}
                      onChange={(e) => setFormData({ ...formData, biometric_id_lokal: e.target.value })}
                      placeholder="e.g., 1001"
                    />
                  </div>
                  <div className="space-y-2">
                    <label className="text-sm font-medium text-slate-900 dark:text-slate-50">
                      Talenta Employee ID
                    </label>
                    <Input
                      type="text"
                      required
                      value={formData.talenta_employee_id}
                      onChange={(e) => setFormData({ ...formData, talenta_employee_id: e.target.value })}
                      placeholder="e.g., EMP001"
                    />
                  </div>
                  <div className="space-y-2">
                    <label className="text-sm font-medium text-slate-900 dark:text-slate-50">
                      Employee Name
                    </label>
                    <Input
                      type="text"
                      value={formData.employee_name}
                      onChange={(e) => setFormData({ ...formData, employee_name: e.target.value })}
                      placeholder="Optional"
                    />
                  </div>
                </div>
                <div className="flex gap-2">
                  <Button type="submit">Save Mapping</Button>
                  <Button
                    type="button"
                    variant="outline"
                    onClick={() => {
                      setFormData({
                        machine_id: '',
                        biometric_id_lokal: '',
                        talenta_employee_id: '',
                        employee_name: '',
                      })
                      setShowForm(false)
                    }}
                  >
                    Cancel
                  </Button>
                </div>
              </form>
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
    </Layout>
  )
}
