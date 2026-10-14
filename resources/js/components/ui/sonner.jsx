import React, { useEffect, useState } from 'react'
import { Toaster as Sonner } from 'sonner'

const isDark = () =>
  typeof document !== 'undefined' && document.documentElement.classList.contains('dark')

// Wrapper Toaster bergaya shadcn/ui. Tema mengikuti class `.dark` di <html>
// (yang diatur ThemeToggle), bukan prefers-color-scheme, supaya toast ikut
// pilihan tema manual user. Warna dipetakan ke design token.
const Toaster = ({ ...props }) => {
  const [theme, setTheme] = useState(() => (isDark() ? 'dark' : 'light'))

  useEffect(() => {
    const observer = new MutationObserver(() => setTheme(isDark() ? 'dark' : 'light'))
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] })
    return () => observer.disconnect()
  }, [])

  return (
    <Sonner
      theme={theme}
      className="toaster group"
      position="top-right"
      richColors
      closeButton
      style={{
        '--normal-bg': 'var(--popover)',
        '--normal-text': 'var(--popover-foreground)',
        '--normal-border': 'var(--border)',
      }}
      {...props}
    />
  )
}

export { Toaster }
