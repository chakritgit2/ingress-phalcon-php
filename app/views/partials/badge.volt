{% if status == 'active' %}
<span class="inline-flex items-center gap-1.5 rounded-full bg-green-50 px-2.5 py-0.5 text-xs font-medium text-green-700 ring-1 ring-inset ring-green-600/20"><span class="h-1.5 w-1.5 animate-pulse rounded-full bg-green-500"></span>{{ status }}</span>
{% elseif status == 'failed' %}
<span class="inline-flex items-center gap-1.5 rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-medium text-red-700 ring-1 ring-inset ring-red-600/20"><span class="h-1.5 w-1.5 rounded-full bg-red-500"></span>{{ status }}</span>
{% else %}
<span class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-500/10"><span class="h-1.5 w-1.5 rounded-full bg-gray-400"></span>{{ status }}</span>
{% endif %}
