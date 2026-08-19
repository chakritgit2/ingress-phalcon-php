<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Ingress Self-Service</title>
    <link rel="stylesheet" href="{{ url.get('css/app.css') }}">
</head>
<body class="min-h-screen bg-gray-50 font-sans text-gray-900 antialiased">
<header class="sticky top-0 z-10 flex items-center justify-between bg-gray-900 px-6 py-4 shadow-md">
    <div class="flex items-center">
        <span class="mr-6 text-base font-semibold tracking-tight text-white">Ingress Self-Service</span>
        {% if currentUser is defined and currentUser %}
        <nav class="flex items-center gap-1">
            <a class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-md px-3 py-2 text-sm font-medium transition {{ dispatcher.getControllerName() == 'ingress' ? 'bg-white/10 text-white' : 'text-gray-300 hover:bg-white/10 hover:text-white' }}" href="/ingress">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <circle cx="5" cy="12" r="2.5"/>
                    <circle cx="19" cy="6" r="2.5"/>
                    <circle cx="19" cy="18" r="2.5"/>
                    <path d="M7.2 11 16.8 6.8M7.2 13 16.8 17.2"/>
                </svg>
                Ingress
            </a>
            <a class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-md px-3 py-2 text-sm font-medium transition {{ dispatcher.getControllerName() == 'audit' ? 'bg-white/10 text-white' : 'text-gray-300 hover:bg-white/10 hover:text-white' }}" href="/audit">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <rect x="5" y="4" width="14" height="17" rx="1.5"/>
                    <path d="M9 3.5h6v2H9zM8 10h8M8 13.5h8M8 17h5"/>
                </svg>
                Audit Log
            </a>
            {% if currentUser.isDevops() %}
            <a class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-md px-3 py-2 text-sm font-medium transition {{ dispatcher.getControllerName() == 'users' ? 'bg-white/10 text-white' : 'text-gray-300 hover:bg-white/10 hover:text-white' }}" href="/users">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <circle cx="9" cy="8" r="3"/>
                    <path stroke-linecap="round" d="M3.5 19a5.5 5.5 0 0 1 11 0"/>
                    <path stroke-linecap="round" d="M16 8.5a3 3 0 1 1 3.5 4.5M18.5 14.5A5 5 0 0 1 21 19"/>
                </svg>
                ผู้ใช้งาน
            </a>
            {% endif %}
        </nav>
        {% endif %}
    </div>
    <div class="flex items-center gap-3">
        {% if currentUser is defined and currentUser %}
            <span class="whitespace-nowrap text-sm text-gray-300">{{ currentUser.email }} <span class="text-gray-500">({{ currentUser.role }})</span></span>
            <form class="inline shrink-0" method="post" action="/logout">
                <button type="submit" class="inline-flex shrink-0 cursor-pointer items-center gap-1.5 whitespace-nowrap rounded-lg border border-white/20 bg-white/5 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-white/10">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h7a2 2 0 0 0 2-2v-2"/>
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h11m0 0-3-3m3 3-3 3"/>
                    </svg>
                    ออกจากระบบ
                </button>
            </form>
        {% endif %}
    </div>
</header>
<main class="mx-auto my-8 {% block container_class %}max-w-4xl{% endblock %} px-4">
    <div class="animate-fade-in">{{ flash.output() }}</div>
    <div class="animate-fade-in rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200">
        {% block content %}{% endblock %}
    </div>
</main>
</body>
</html>
