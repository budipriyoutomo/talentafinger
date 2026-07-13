import React from 'react'
import { Monitor, Moon, Sun } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { useTheme } from '@/lib/theme'

const OPTIONS = [
  { value: 'light', label: 'Terang', Icon: Sun },
  { value: 'dark', label: 'Gelap', Icon: Moon },
  { value: 'system', label: 'Ikuti Sistem', Icon: Monitor },
]

export default function ThemeToggle({ className }) {
  const [theme, setTheme] = useTheme()

  const index = Math.max(0, OPTIONS.findIndex((o) => o.value === theme))
  const current = OPTIONS[index]
  const next = OPTIONS[(index + 1) % OPTIONS.length]
  const { Icon } = current

  return (
    <Button
      variant="ghost"
      size="icon"
      onClick={() => setTheme(next.value)}
      className={className}
      title={`Tema: ${current.label} — klik untuk ${next.label}`}
      aria-label={`Tema: ${current.label}. Klik untuk ganti ke ${next.label}.`}
    >
      <Icon className="h-4 w-4" />
    </Button>
  )
}
