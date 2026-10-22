import { useCallback, useState } from 'react'

export const GEO_ERRORS = {
  1: 'Izin lokasi ditolak. Aktifkan izin lokasi untuk situs ini di pengaturan browser.',
  2: 'Lokasi tidak tersedia. Pastikan GPS/lokasi HP aktif.',
  3: 'Waktu habis saat mencari lokasi. Coba lagi di tempat terbuka.',
}

/**
 * Lokasi HP untuk absen karyawan (absen foto & absen QR).
 *
 * `locate()` selalu mengambil lokasi SEGAR (maximumAge 0) dan resolve
 * { latitude, longitude, accuracy } atau null — pesan error sudah ditaruh di
 * geoError, jadi pemanggil cukup cek null.
 */
export function useFreshLocation() {
  const [position, setPosition] = useState(null) // { latitude, longitude, accuracy }
  const [locating, setLocating] = useState(false)
  const [geoError, setGeoError] = useState(null)

  const locate = useCallback(() => new Promise((resolve) => {
    if (!('geolocation' in navigator)) {
      setGeoError('Browser ini tidak mendukung lokasi.')
      resolve(null)
      return
    }
    setLocating(true)
    setGeoError(null)
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        const next = {
          latitude: pos.coords.latitude,
          longitude: pos.coords.longitude,
          accuracy: pos.coords.accuracy,
        }
        setPosition(next)
        setLocating(false)
        resolve(next)
      },
      (err) => {
        setPosition(null)
        setGeoError(GEO_ERRORS[err.code] || err.message)
        setLocating(false)
        resolve(null)
      },
      { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 },
    )
  }), [])

  return { position, locating, geoError, locate }
}
