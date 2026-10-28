import React, { useEffect, useState } from 'react'
import { Toaster as Sonner } from 'sonner'

const isDark = () =>
  typeof document !== 'undefined' && document.documentElement.classList.contains('dark')

// Toast muncul di TENGAH layar (bukan pojok) supaya pesan tak terlewat, dan
// lebih besar dari bawaan sonner (gaya ukuran ada di resources/css/app.css).
// sonner hanya punya posisi atas/bawah, jadi "tengah" = top-center yang
// digeser turun ke setengah tinggi layar (dikurangi kira-kira separuh toast).
const CENTER_OFFSET = { top: 'calc(50vh - 44px)' }
const CENTER_MOBILE_OFFSET = { top: 'calc(50vh - 44px)', left: '16px', right: '16px' }

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
      position="top-center"
      offset={CENTER_OFFSET}
      mobileOffset={CENTER_MOBILE_OFFSET}
      richColors
      closeButton
      style={{
        '--normal-bg': 'var(--popover)',
        '--normal-text': 'var(--popover-foreground)',
        '--normal-border': 'var(--border)',
        '--width': '440px',
      }}
      {...props}
    />
  )
}

export { Toaster }
