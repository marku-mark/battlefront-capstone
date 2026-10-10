import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, watch } from 'vue';

export function useDebouncedSearch({
    initialSearch = '',
    currentSearch,
    route,
    query = () => ({}),
    debounceMs = 400,
    reset = [],
    only = [],
    preserveScroll = true,
}) {
    const search = ref(initialSearch ?? '');
    const isSearching = ref(false);
    let debounceTimeout;
    let cancelToken;
    let requestVersion = 0;

    function cancelPendingSearch() {
        requestVersion++;
        clearTimeout(debounceTimeout);
        cancelToken?.cancel();
        cancelToken = undefined;
        isSearching.value = false;
    }

    function visitSearch() {
        const normalizedSearch = search.value.trim();

        if (normalizedSearch === (currentSearch() ?? '')) {
            return;
        }

        const version = ++requestVersion;

        router.visit(
            route({
                query: {
                    ...query(),
                    q: normalizedSearch || undefined,
                },
            }),
            {
                preserveScroll,
                preserveState: true,
                replace: true,
                reset,
                only,
                onCancelToken: (token) => {
                    if (version !== requestVersion) {
                        token.cancel();
                        return;
                    }

                    cancelToken = token;
                },
                onStart: () => {
                    if (version === requestVersion) {
                        isSearching.value = true;
                    }
                },
                onFinish: () => {
                    if (version === requestVersion) {
                        cancelToken = undefined;
                        isSearching.value = false;
                    }
                },
            },
        );
    }

    function clearSearch() {
        search.value = '';
    }

    watch(search, () => {
        cancelPendingSearch();
        if (search.value.trim() === '') {
            visitSearch();
            return;
        }

        debounceTimeout = setTimeout(visitSearch, debounceMs);
    });

    onBeforeUnmount(cancelPendingSearch);

    return {
        search,
        isSearching,
        clearSearch,
        cancelPendingSearch,
    };
}
