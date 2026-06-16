import React, { useState } from 'react'
import { Head, router } from '@inertiajs/react'
import Layout from '../layouts/Layout'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import { Cpu, Trash2, Plus } from 'lucide-react'

export default function Machines({ machines = [] }) {
  const [showForm, setShowForm] = useState(false)
  const [formData, setFormData] = useState({
    serial_number: '',
    name: '',
    location: '',
  })

  const handleSubmit = async (e) => {
    e.preventDefault()
    try {
      const response = await fetch('/api/machines', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
        },
        body: JSON.stringify(formData),
      })
      if (response.ok) {
        setFormData({ serial_number: '', name: '', location: '' })
        setShowForm(false)
        router.reload()
      }
    } catch (err) {
      console.error('Failed to create machine:', err)
    }
  }

  const deleteMachine = async (id) => {
    if (confirm('Are you sure you want to delete this machine?')) {
      try {
        await fetch(`/api/machines/${id}`, {
          method: 'DELETE',
          headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
          },
        })
        router.reload()
      } catch (err) {
        console.error('Failed to delete machine:', err)
      }
    }
  }

  return (
    <Layout>
      <Head title="Machines" />

      <div className="space-y-6">
        <div className="flex justify-between items-center">
          <h1 className="text-3xl font-bold text-slate-900 dark:text-slate-50">Machines</h1>
          <Button
            onClick={() => setShowForm(!showForm)}
            className="gap-2"
          >
            <Plus className="h-4 w-4" />
            {showForm ? 'Cancel' : 'Add Machine'}
          </Button>
        </div>

        {/* Add Machine Form */}
        {showForm && (
          <Card>
            <CardHeader>
              <CardTitle>Register New Machine</CardTitle>
              <CardDescription>Add a new fingerprint machine to the system</CardDescription>
            </CardHeader>
            <CardContent>
              <form onSubmit={handleSubmit} className="space-y-4">
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                  <div className="space-y-2">
                    <label className="text-sm font-medium text-slate-900 dark:text-slate-50">
                      Serial Number
                    </label>
                    <Input
                      type="text"
                      required
                      value={formData.serial_number}
                      onChange={(e) => setFormData({ ...formData, serial_number: e.target.value })}
                      placeholder="e.g., ABC123"
                    />
                  </div>
                  <div className="space-y-2">
                    <label className="text-sm font-medium text-slate-900 dark:text-slate-50">
                      Name
                    </label>
                    <Input
                      type="text"
                      required
                      value={formData.name}
                      onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                      placeholder="e.g., Jakarta Office"
                    />
                  </div>
                  <div className="space-y-2">
                    <label className="text-sm font-medium text-slate-900 dark:text-slate-50">
                      Location
                    </label>
                    <Input
                      type="text"
                      value={formData.location}
                      onChange={(e) => setFormData({ ...formData, location: e.target.value })}
                      placeholder="e.g., Building A, Floor 1"
                    />
                  </div>
                </div>
                <div className="flex gap-2">
                  <Button type="submit">Save Machine</Button>
                  <Button
                    type="button"
                    variant="outline"
                    onClick={() => {
                      setFormData({ serial_number: '', name: '', location: '' })
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

        {/* Machine List */}
        <div className="grid grid-cols-1 gap-4">
          {machines.length === 0 ? (
            <Card className="text-center py-12">
              <CardContent className="flex flex-col items-center gap-4">
                <Cpu className="h-12 w-12 text-slate-400" />
                <div>
                  <h3 className="font-semibold text-slate-900 dark:text-slate-50">No machines registered</h3>
                  <p className="text-sm text-slate-500 dark:text-slate-400">
                    Add your first fingerprint machine to get started
                  </p>
                </div>
              </CardContent>
            </Card>
          ) : (
            machines.map(machine => (
              <Card key={machine.id}>
                <CardContent className="pt-6">
                  <div className="flex justify-between items-start">
                    <div className="space-y-2 flex-1">
                      <h3 className="text-lg font-semibold text-slate-900 dark:text-slate-50">{machine.name}</h3>
                      <p className="text-sm text-slate-600 dark:text-slate-400">Serial: {machine.serial_number}</p>
                      {machine.location && (
                        <p className="text-sm text-slate-500 dark:text-slate-400">Location: {machine.location}</p>
                      )}
                      {machine.last_seen_at && (
                        <p className="text-xs text-slate-400 dark:text-slate-500">
                          Last seen: {new Date(machine.last_seen_at).toLocaleString()}
                        </p>
                      )}
                    </div>
                    <div className="flex items-center gap-4">
                      <Badge variant={machine.status === 'online' ? 'success' : 'destructive'}>
                        {machine.status}
                      </Badge>
                      <Button
                        onClick={() => deleteMachine(machine.id)}
                        variant="destructive"
                        size="sm"
                        className="gap-2"
                      >
                        <Trash2 className="h-4 w-4" />
                        Delete
                      </Button>
                    </div>
                  </div>
                </CardContent>
              </Card>
            ))
          )}
        </div>
      </div>
    </Layout>
  )
}
