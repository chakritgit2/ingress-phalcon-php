{% if status == 'active' %}
<span class="inline-flex items-center gap-1.5 rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800"><span class="h-1.5 w-1.5 animate-pulse rounded-full bg-green-500"></span>{{ status }}</span>
{% elseif status == 'failed' %}
<span class="inline-flex items-center gap-1.5 rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-800"><span class="h-1.5 w-1.5 rounded-full bg-red-500"></span>{{ status }}</span>
{% else %}
<span class="inline-flex items-center gap-1.5 rounded-full bg-gray-200 px-2.5 py-0.5 text-xs font-medium text-gray-700"><span class="h-1.5 w-1.5 rounded-full bg-gray-500"></span>{{ status }}</span>
{% endif %}
