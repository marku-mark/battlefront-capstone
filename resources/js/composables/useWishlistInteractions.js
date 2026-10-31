import { router, usePage } from '@inertiajs/vue3';
import { createSharedComposable } from '@vueuse/core';
import { onScopeDispose, reactive, watch } from 'vue';

const productProps = ['products', 'product', 'recommendations', 'components'];

export const useWishlistInteractions = createSharedComposable(() => {
    const page = usePage();
    const saved = reactive(new Map());
    const pending = reactive(new Map());
    const errors = reactive(new Map());
    const visits = new Map();
    let generation = 0;
    let disposed = false;
    let overlappingRequests = false;

    function reconcileProducts(value) {
        if (!value || typeof value !== 'object') return;
        if (typeof value.is_wishlisted === 'boolean' && value.id != null) {
            saved.set(value.id, value.is_wishlisted);
            return;
        }
        Object.values(value).forEach(reconcileProducts);
    }

    const removeStart = router.on('start', ({ detail: { visit } }) => {
        visits.set(visit.id, visit);
    });
    const removeSuccess = router.on('success', ({ detail }) => {
        const visit = visits.get(detail.visitId);
        if (visit?.method !== 'get') return;
        const isInfiniteScroll = Object.keys(visit.headers ?? {}).some(
            (name) =>
                name.toLowerCase() === 'x-inertia-infinite-scroll-merge-intent',
        );
        const keys = visit.only.length
            ? visit.only
            : [...productProps, 'wishlist'];
        for (const key of productProps) {
            if (key === 'products' && isInfiniteScroll) continue;
            if (keys.includes(key)) reconcileProducts(detail.page.props[key]);
        }
        if (keys.includes('wishlist')) {
            for (const item of detail.page.props.wishlist?.items.data ?? []) {
                saved.set(item.product_id, true);
            }
        }
    });
    const removeFinish = router.on('finish', ({ detail: { visit } }) => {
        visits.delete(visit.id);
    });
    const removeNavigate = router.on('navigate', ({ detail }) => {
        if (detail.visitId || !page.props.auth?.user) return;
        // History restores cached props; refresh their wishlist state from the server.
        router.reload({
            only: ['wishlistCount', 'wishlist', ...productProps].filter(
                (key) => key in page.props,
            ),
            showProgress: false,
        });
    });

    watch(
        [
            () => page.props.auth?.user?.id,
            () => page.props.auth?.can?.useCustomerCart,
        ],
        () => {
            generation++;
            saved.clear();
            pending.clear();
            errors.clear();
            visits.clear();
            overlappingRequests = false;
        },
        { flush: 'sync' },
    );

    function formOptions(
        productId,
        { only = ['wishlistCount'], isSaved } = {},
    ) {
        let token;
        let requestGeneration;
        const isCurrent = () =>
            !disposed &&
            requestGeneration === generation &&
            pending.get(productId) === token;
        const fail = (message) => {
            if (isCurrent()) errors.set(productId, message);
        };

        return {
            preserveScroll: true,
            preserveState: true,
            preserveUrl: true,
            only,
            async: true,
            onBefore: () => {
                if (
                    disposed ||
                    pending.has(productId) ||
                    !page.props.auth?.can?.useCustomerCart
                )
                    return false;
                requestGeneration = generation;
                token = Symbol();
                overlappingRequests ||= pending.size > 0;
                pending.set(productId, token);
                errors.delete(productId);
            },
            onSuccess: () => {
                if (!isCurrent()) return;
                router.flushAll();
                if (typeof isSaved === 'boolean') {
                    saved.set(productId, isSaved);
                }
            },
            onError: (fields) =>
                fail(
                    fields.product_id ||
                        fields.quantity ||
                        Object.values(fields)[0] ||
                        'Your wishlist could not be updated. Please try again.',
                ),
            onNetworkError: () => {
                fail('Unable to connect. Please try again.');
                return false;
            },
            onHttpException: ({ status }) => {
                fail(
                    status === 401 || status === 419
                        ? 'Your session expired. Refresh the page and sign in again.'
                        : 'This action could not be completed. Please refresh the page and try again.',
                );
                return false;
            },
            onFinish: () => {
                if (!isCurrent()) return;
                pending.delete(productId);
                if (pending.size === 0 && overlappingRequests) {
                    overlappingRequests = false;
                    router.reload({
                        only: [
                            'wishlistCount',
                            ...(page.component === 'Wishlist/Index'
                                ? ['wishlist']
                                : []),
                        ],
                        showProgress: false,
                    });
                }
            },
        };
    }

    onScopeDispose(() => {
        disposed = true;
        removeStart();
        removeSuccess();
        removeFinish();
        removeNavigate();
    });

    return {
        isSaved: (productId, fallback = false) =>
            saved.get(productId) ?? fallback,
        isBusy: (productId) => pending.has(productId),
        error: (productId) => errors.get(productId),
        formOptions,
    };
});
