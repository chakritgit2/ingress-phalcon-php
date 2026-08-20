{% if status == 'active' %}
<span class="inline-flex items-center gap-1.5 rounded-full bg-green-50 px-2.5 py-0.5 text-xs font-medium text-green-700 ring-1 ring-inset ring-green-600/20 dark:bg-green-500/10 dark:text-green-400 dark:ring-green-500/20"><span class="h-1.5 w-1.5 animate-pulse rounded-full bg-green-500"></span>{{ status }}</span>
{% elseif status == 'failed' %}
<span class="inline-flex items-center gap-1.5 rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-medium text-red-700 ring-1 ring-inset ring-red-600/20 dark:bg-red-500/10 dark:text-red-400 dark:ring-red-500/20"><span class="h-1.5 w-1.5 rounded-full bg-red-500"></span>{{ status }}</span>
{% else %}
<span class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-500/10 dark:bg-gray-800 dark:text-gray-400 dark:ring-gray-700"><span class="h-1.5 w-1.5 rounded-full bg-gray-400"></span>{{ status }}</span>
{% endif %}
