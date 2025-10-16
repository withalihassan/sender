<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Dashboard — Apps</title>

  <!-- Tailwind Play CDN (no custom CSS required) -->
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gradient-to-b from-slate-900 via-slate-800 to-slate-900 text-slate-100 antialiased">

  <main class="max-w-4xl mx-auto p-6">
    <!-- Page header -->
    <header class="mb-8">
      <h1 class="text-3xl font-extrabold tracking-tight">Dashboard</h1>
      <p class="mt-1 text-slate-400">Manage the most trusted platforrms Traffics</p>
    </header>

    <!-- Cards grid (copy the .card element to add more boxes) -->
    <section class="grid grid-cols-1 sm:grid-cols-2 gap-6">

      <!-- START CARD (copy this entire <article> to create more boxes) -->
      <article class="card bg-white/4 backdrop-blur-sm rounded-2xl p-5 flex gap-4 items-start shadow-md border border-white/6 hover:translate-y-0.5 transition-transform">
        <!-- Left icon -->
        <div class="flex-shrink-0">
          <!-- Simple OKX-style SVG placeholder — replace with official logo if desired -->
          <div class="w-14 h-14 rounded-lg bg-gradient-to-br from-indigo-500 to-teal-400 flex items-center justify-center shadow-sm">
            <svg viewBox="0 0 48 48" class="w-8 h-8" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" role="img">
              <!-- stylized OKX text inside -->
              <rect width="48" height="48" rx="8" fill="rgba(255,255,255,0.06)"/>
              <text x="50%" y="55%" text-anchor="middle" font-family="Inter, system-ui, Arial" font-weight="700" font-size="14" fill="white">OKX</text>
            </svg>
          </div>
        </div>

        <!-- Okx Portal -->
        <div class="flex-1 min-w-0">
          <div class="flex items-center justify-between gap-4">
            <div>
              <h3 class="text-lg font-semibold leading-tight">Okx Portal</h3>
              <p class="mt-1 text-sm text-slate-300">Manage all OKX related tasks</p>
            </div>
            <!-- small status / tag -->
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-white/6 text-slate-100">Connected</span>
          </div>

          <!-- optional actions / meta row -->
          <div class="mt-4 flex items-center gap-3 text-sm text-slate-300">
            <a href="./okx" target="_blank"><button class="px-3 py-1 rounded-lg bg-white/6 hover:bg-white/8 transition">Open</button></a>
            <button class="px-3 py-1 rounded-lg bg-white/4 hover:bg-white/6 transition">logs</button>
            <span class="ml-auto text-xs">Sync OK</span>
          </div>
        </div>
      </article>
      <!-- END CARD -->

      <!-- Google:  -->
      <article class="card bg-white/4 backdrop-blur-sm rounded-2xl p-5 flex gap-4 items-start shadow-md border border-white/6 hover:translate-y-0.5 transition-transform">
        <div class="flex-shrink-0">
          <div class="w-14 h-14 rounded-lg bg-gradient-to-br from-pink-500 to-yellow-400 flex items-center justify-center shadow-sm">
            <svg viewBox="0 0 48 48" class="w-8 h-8" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" role="img">
              <rect width="48" height="48" rx="8" fill="rgba(255,255,255,0.06)"/>
              <text x="50%" y="55%" text-anchor="middle" font-family="Inter, system-ui, Arial" font-weight="700" font-size="12" fill="white">Google</text>
            </svg>
          </div>
        </div>
        <div class="flex-1 min-w-0">
          <div class="flex items-center justify-between gap-4">
            <div>
              <h3 class="text-lg font-semibold leading-tight">Google</h3>
              <p class="mt-1 text-sm text-slate-300">Create Google Accounts</p>
            </div>
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-white/6 text-slate-100">Connect</span>
          </div>
          <div class="mt-4 flex items-center gap-3 text-sm text-slate-300">
            <button class="px-3 py-1 rounded-lg bg-white/6 hover:bg-white/8 transition">Open</button>
            <button class="px-3 py-1 rounded-lg bg-white/4 hover:bg-white/6 transition">Logs</button>
            <span class="ml-auto text-xs">Sync OK</span>
          </div>
        </div>
      </article>

      <!-- SumSung:  -->
      <article class="card bg-white/4 backdrop-blur-sm rounded-2xl p-5 flex gap-4 items-start shadow-md border border-white/6 hover:translate-y-0.5 transition-transform">
        <div class="flex-shrink-0">
          <div class="w-14 h-14 rounded-lg bg-gradient-to-br from-purple-500 to-black-400 flex items-center justify-center shadow-sm">
            <svg viewBox="0 0 48 48" class="w-8 h-8" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" role="img">
              <rect width="48" height="48" rx="8" fill="rgba(255,255,255,0.06)"/>
              <text x="50%" y="55%" text-anchor="middle" font-family="Inter, system-ui, Arial" font-weight="700" font-size="12" fill="white">Sumsung</text>
            </svg>
          </div>
        </div>
        <div class="flex-1 min-w-0">
          <div class="flex items-center justify-between gap-4">
            <div>
              <h3 class="text-lg font-semibold leading-tight">SumSung</h3>
              <p class="mt-1 text-sm text-slate-300">Create Sumsung Accounts</p>
            </div>
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-white/6 text-slate-100">Connect</span>
          </div>
          <div class="mt-4 flex items-center gap-3 text-sm text-slate-300">
            <button class="px-3 py-1 rounded-lg bg-white/6 hover:bg-white/8 transition">Open</button>
            <button class="px-3 py-1 rounded-lg bg-white/4 hover:bg-white/6 transition">Logs</button>
            <span class="ml-auto text-xs">Sync OK</span>
          </div>
        </div>
      </article>

      <!-- Stripe:  -->
      <article class="card bg-white/4 backdrop-blur-sm rounded-2xl p-5 flex gap-4 items-start shadow-md border border-white/6 hover:translate-y-0.5 transition-transform">
        <div class="flex-shrink-0">
          <div class="w-14 h-14 rounded-lg bg-gradient-to-br from-indigo-500 to-slate-900 flex items-center justify-center shadow-sm">
            <svg viewBox="0 0 48 48" class="w-8 h-8" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" role="img">
              <rect width="48" height="48" rx="8" fill="rgba(255,255,255,0.06)"/>
              <text x="50%" y="55%" text-anchor="middle" font-family="Inter, system-ui, Arial" font-weight="700" font-size="12" fill="white">Stripe</text>
            </svg>
          </div>
        </div>
        <div class="flex-1 min-w-0">
          <div class="flex items-center justify-between gap-4">
            <div>
              <h3 class="text-lg font-semibold leading-tight">Stripe</h3>
              <p class="mt-1 text-sm text-slate-300">Create Stripe Accounts</p>
            </div>
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-white/6 text-slate-100">Connect</span>
          </div>
          <div class="mt-4 flex items-center gap-3 text-sm text-slate-300">
            <button class="px-3 py-1 rounded-lg bg-white/6 hover:bg-white/8 transition">Open</button>
            <button class="px-3 py-1 rounded-lg bg-white/4 hover:bg-white/6 transition">Logs</button>
            <span class="ml-auto text-xs">Sync OK</span>
          </div>
        </div>
      </article>
      
      <!-- TINDER:  -->
      <article class="card bg-white/4 backdrop-blur-sm rounded-2xl p-5 flex gap-4 items-start shadow-md border border-white/6 hover:translate-y-0.5 transition-transform">
        <div class="flex-shrink-0">
          <div class="w-14 h-14 rounded-lg bg-gradient-to-br from-emerald-500 to-slate-800 flex items-center justify-center shadow-sm">
            <svg viewBox="0 0 48 48" class="w-8 h-8" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" role="img">
              <rect width="48" height="48" rx="8" fill="rgba(255,255,255,0.06)"/>
              <text x="50%" y="55%" text-anchor="middle" font-family="Inter, system-ui, Arial" font-weight="700" font-size="12" fill="white">TINDER</text>
            </svg>
          </div>
        </div>
        <div class="flex-1 min-w-0">
          <div class="flex items-center justify-between gap-4">
            <div>
              <h3 class="text-lg font-semibold leading-tight">TINDER</h3>
              <p class="mt-1 text-sm text-slate-300">Create TINDER Accounts</p>
            </div>
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-white/6 text-slate-100">Connect</span>
          </div>
          <div class="mt-4 flex items-center gap-3 text-sm text-slate-300">
            <button class="px-3 py-1 rounded-lg bg-white/6 hover:bg-white/8 transition">Open</button>
            <button class="px-3 py-1 rounded-lg bg-white/4 hover:bg-white/6 transition">Logs</button>
            <span class="ml-auto text-xs">Sync OK</span>
          </div>
        </div>
      </article>

      <!-- Facebook:  -->
      <article class="card bg-white/4 backdrop-blur-sm rounded-2xl p-5 flex gap-4 items-start shadow-md border border-white/6 hover:translate-y-0.5 transition-transform">
        <div class="flex-shrink-0">
          <div class="w-14 h-14 rounded-lg bg-gradient-to-br from-pink-500 to-purple-900 flex items-center justify-center shadow-sm">
            <svg viewBox="0 0 48 48" class="w-8 h-8" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" role="img">
              <rect width="48" height="48" rx="8" fill="rgba(255,255,255,0.06)"/>
              <text x="50%" y="55%" text-anchor="middle" font-family="Inter, system-ui, Arial" font-weight="700" font-size="12" fill="white">Facebook</text>
            </svg>
          </div>
        </div>
        <div class="flex-1 min-w-0">
          <div class="flex items-center justify-between gap-4">
            <div>
              <h3 class="text-lg font-semibold leading-tight">Facebook</h3>
              <p class="mt-1 text-sm text-slate-300">Create Facebook Accounts</p>
            </div>
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-white/6 text-slate-100">Connect</span>
          </div>
          <div class="mt-4 flex items-center gap-3 text-sm text-slate-300">
            <button class="px-3 py-1 rounded-lg bg-white/6 hover:bg-white/8 transition">Open</button>
            <button class="px-3 py-1 rounded-lg bg-white/4 hover:bg-white/6 transition">Logs</button>
            <span class="ml-auto text-xs">Sync OK</span>
          </div>
        </div>
      </article>

      <!-- TikTok:  -->
      <article class="card bg-white/4 backdrop-blur-sm rounded-2xl p-5 flex gap-4 items-start shadow-md border border-white/6 hover:translate-y-0.5 transition-transform">
        <div class="flex-shrink-0">
          <div class="w-14 h-14 rounded-lg bg-gradient-to-br from-orange-500 to-stone-900 flex items-center justify-center shadow-sm">
            <svg viewBox="0 0 48 48" class="w-8 h-8" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" role="img">
              <rect width="48" height="48" rx="8" fill="rgba(255,255,255,0.06)"/>
              <text x="50%" y="55%" text-anchor="middle" font-family="Inter, system-ui, Arial" font-weight="700" font-size="12" fill="white">TikTok</text>
            </svg>
          </div>
        </div>
        <div class="flex-1 min-w-0">
          <div class="flex items-center justify-between gap-4">
            <div>
              <h3 class="text-lg font-semibold leading-tight">TikTok</h3>
              <p class="mt-1 text-sm text-slate-300">Create TikTok Accounts</p>
            </div>
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-white/6 text-slate-100">Connect</span>
          </div>
          <div class="mt-4 flex items-center gap-3 text-sm text-slate-300">
            <button class="px-3 py-1 rounded-lg bg-white/6 hover:bg-white/8 transition">Open</button>
            <button class="px-3 py-1 rounded-lg bg-white/4 hover:bg-white/6 transition">Logs</button>
            <span class="ml-auto text-xs">Sync OK</span>
          </div>
        </div>
      </article>
    </section>
  </main>

</body>
</html>
