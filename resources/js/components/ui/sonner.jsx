import React from 'react'
import { Toaster as Sonner } from 'sonner'

// Wrapper Toaster bergaya shadcn/ui. Tema "system" mengikuti prefers-color-scheme
// (sama dengan strategi dark mode aplikasi). Warna dipetakan ke design token.
const Toaster = ({ ...props }) => (
  <Sonner
    theme="system"
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

export { Toaster }
