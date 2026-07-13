import { useCallback, useEffect, useState } from 'react'

export const THEME_KEY = 'adms.theme'
export const THEMES = ['light', 'dark', 'system']

const media = () => window.matchMedia('(prefers-color-scheme: dark)')

export function getStoredTheme() {
  if (typeof window === 'undefined') return 'system'
  try {
    const value = window.localStorage.getItem(THEME_KEY)
    return value === 'light' || value === 'dark' ? value : 'system'
  } catch {
    return 'system'
  }
}

// Nilai .dark pada <html> harus tetap sinkron dengan script boot di
// app.blade.php — keduanya memakai aturan resolve yang sama.
export function applyTheme(theme) {
  const dark = theme === 'dark' || (theme === 'system' && media().matches)
  document.documentElement.classList.toggle('dark', dark)
}

export function useTheme() {
  const [theme, setTheme] = useState(getStoredTheme)

  useEffect(() => {
    if (theme !== 'system') return
    const mq = media()
    const onChange = () => applyTheme('system')
    mq.addEventListener('change', onChange)
    return () => mq.removeEventListener('change', onChange)
  }, [theme])

  const changeTheme = useCallback((next) => {
    try {
      if (next === 'system') window.localStorage.removeItem(THEME_KEY)
      else window.localStorage.setItem(THEME_KEY, next)
    } catch {
      // Private mode / storage penuh: tema tetap berlaku untuk sesi ini.
    }
    applyTheme(next)
    setTheme(next)
  }, [])

  return [theme, changeTheme]
}
