{% set extraParams = extraParams is defined ? extraParams : [] %}
<div class="mt-4 flex flex-col items-center justify-between gap-3 border-t border-gray-100 pt-4 dark:border-gray-800 sm:flex-row">
    <span class="text-sm text-gray-500 dark:text-gray-400">ทั้งหมด {{ totalItems }} รายการ</span>
    {% if totalPages > 1 %}
    <nav class="flex flex-wrap items-center justify-center gap-1" aria-label="Pagination">
        {% if page > 1 %}
        <a class="inline-flex h-8 shrink-0 items-center gap-1 rounded-lg border border-gray-300 bg-white px-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700" href="{{ baseUrl }}?page={{ page - 1 }}{% for key, value in extraParams %}&{{ key }}={{ value|url_encode }}{% endfor %}">
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m15 19-7-7 7-7"/></svg>
            <span class="hidden sm:inline">ก่อนหน้า</span>
        </a>
        {% else %}
        <span class="inline-flex h-8 shrink-0 cursor-not-allowed items-center gap-1 rounded-lg border border-gray-200 bg-gray-50 px-2.5 text-sm font-medium text-gray-300 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-700">
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m15 19-7-7 7-7"/></svg>
            <span class="hidden sm:inline">ก่อนหน้า</span>
        </span>
        {% endif %}

        {% for p in pageNumbers %}
            {% if p == 0 %}
            <span class="w-8 shrink-0 text-center text-sm text-gray-400 dark:text-gray-600">&hellip;</span>
            {% elseif p == page %}
            <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-blue-600 text-sm font-medium text-white shadow-sm">{{ p }}</span>
            {% else %}
            <a class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-gray-300 bg-white text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700" href="{{ baseUrl }}?page={{ p }}{% for key, value in extraParams %}&{{ key }}={{ value|url_encode }}{% endfor %}">{{ p }}</a>
            {% endif %}
        {% endfor %}

        {% if page < totalPages %}
        <a class="inline-flex h-8 shrink-0 items-center gap-1 rounded-lg border border-gray-300 bg-white px-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700" href="{{ baseUrl }}?page={{ page + 1 }}{% for key, value in extraParams %}&{{ key }}={{ value|url_encode }}{% endfor %}">
            <span class="hidden sm:inline">ถัดไป</span>
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7"/></svg>
        </a>
        {% else %}
        <span class="inline-flex h-8 shrink-0 cursor-not-allowed items-center gap-1 rounded-lg border border-gray-200 bg-gray-50 px-2.5 text-sm font-medium text-gray-300 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-700">
            <span class="hidden sm:inline">ถัดไป</span>
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7"/></svg>
        </span>
        {% endif %}
    </nav>
    {% endif %}
</div>
