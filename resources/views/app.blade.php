<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title inertia>ADMS Middleware</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    {{-- Dipasang sebelum CSS/JS app supaya halaman tidak sempat berkedip terang
         dulu sebelum React sempat menerapkan tema tersimpan. --}}
    <script>
        (function () {
            try {
                var stored = window.localStorage.getItem('adms.theme');
                var theme = stored === 'light' || stored === 'dark' ? stored : 'system';
                var dark = theme === 'dark'
                    || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.classList.toggle('dark', dark);
            } catch (e) {}
        })();
    </script>

    @vite('resources/js/app.jsx')
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
