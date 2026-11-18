<script setup>
import 'leaflet/dist/leaflet.css';

import { MapPinned } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Skeleton } from '@/components/ui/skeleton';

const props = defineProps({
    branches: {
        type: Array,
        required: true,
    },
});

const mapContainer = ref(null);
const mapState = ref('loading');
let mapInstance = null;
let isUnmounted = false;

const hasValidCoordinates = (branch) => {
    if (
        branch.latitude == null ||
        branch.longitude == null ||
        String(branch.latitude).trim() === '' ||
        String(branch.longitude).trim() === ''
    ) {
        return false;
    }

    const latitude = Number(branch.latitude);
    const longitude = Number(branch.longitude);

    return (
        Number.isFinite(latitude) &&
        Number.isFinite(longitude) &&
        latitude >= -90 &&
        latitude <= 90 &&
        longitude >= -180 &&
        longitude <= 180
    );
};

const mappedBranches = computed(() =>
    props.branches.filter(hasValidCoordinates),
);

const createPopupContent = (branch) => {
    const container = document.createElement('div');
    const city = document.createElement('strong');
    const address = document.createElement('p');

    city.textContent = branch.city;
    address.textContent = branch.address ?? 'Address not currently available';

    container.append(city, address);

    return container;
};

const createTooltipContent = (branch) => {
    const content = document.createElement('span');

    content.textContent = branch.city;

    return content;
};

onMounted(async () => {
    if (!mapContainer.value || mappedBranches.value.length === 0) {
        mapState.value = 'unavailable';

        return;
    }

    try {
        const { default: leaflet } = await import('leaflet');

        if (isUnmounted || !mapContainer.value) {
            return;
        }

        mapInstance = leaflet.map(mapContainer.value, {
            scrollWheelZoom: false,
        });

        leaflet
            .tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution:
                    '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                maxZoom: 19,
            })
            .addTo(mapInstance);

        const bounds = [];

        mappedBranches.value.forEach((branch) => {
            const coordinates = [
                Number(branch.latitude),
                Number(branch.longitude),
            ];
            const markerOptions = branch.is_operational
                ? {
                      color: '#B91C1C',
                      fillColor: '#B91C1C',
                      fillOpacity: 0.9,
                      radius: 10,
                      weight: 3,
                  }
                : {
                      color: '#2A2E36',
                      fillColor: '#F8FAFC',
                      fillOpacity: 0.9,
                      radius: 8,
                      weight: 3,
                  };

            leaflet
                .circleMarker(coordinates, markerOptions)
                .bindTooltip(createTooltipContent(branch))
                .bindPopup(createPopupContent(branch))
                .addTo(mapInstance);

            bounds.push(coordinates);
        });

        mapInstance.fitBounds(bounds, {
            maxZoom: 11,
            padding: [32, 32],
        });
        mapState.value = 'ready';
    } catch {
        mapState.value = 'unavailable';
    }
});

onBeforeUnmount(() => {
    isUnmounted = true;
    mapInstance?.remove();
});
</script>

<template>
    <section aria-labelledby="branch-map-heading">
        <div
            class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"
        >
            <div>
                <p
                    class="text-primary-ink text-xs font-bold tracking-[0.18em] uppercase"
                >
                    Location map
                </p>
                <h2
                    id="branch-map-heading"
                    class="mt-2 text-2xl font-bold tracking-tight"
                >
                    Approved branch locations
                </h2>
            </div>
            <p class="text-muted-foreground text-sm">
                {{ mappedBranches.length }} {{ mappedBranches.length === 1 ? 'location' : 'locations' }}
            </p>
        </div>

        <figure
            class="surface-panel border-border bg-card isolate overflow-hidden border"
        >
            <div class="relative min-h-96">
                <div
                    ref="mapContainer"
                    class="h-96 w-full"
                    :class="mapState === 'unavailable' ? 'opacity-20' : ''"
                    aria-label="Map of approved Battlefront branch locations"
                    :aria-busy="mapState === 'loading'"
                />

                <div
                    v-if="mapState === 'loading'"
                    class="bg-card/90 absolute inset-0 z-10 flex items-center justify-center"
                    role="status"
                >
                    <Skeleton
                        class="absolute inset-0 rounded-none"
                        aria-hidden="true"
                    />
                    <p class="text-muted-foreground relative text-sm">
                        Loading branch map…
                    </p>
                </div>

                <div
                    v-else-if="mapState === 'unavailable'"
                    class="bg-card/90 absolute inset-0 z-10 flex flex-col items-center justify-center px-6 text-center"
                    role="status"
                >
                    <MapPinned
                        class="text-muted-foreground size-7"
                        aria-hidden="true"
                    />
                    <p class="mt-4 font-semibold">
                        The branch map is not currently available.
                    </p>
                    <p class="text-muted-foreground mt-1 text-sm">
                        Confirmed branch details remain available below.
                    </p>
                </div>
            </div>

            <figcaption
                class="border-border grid gap-4 border-t p-5 text-sm sm:grid-cols-[auto_auto_1fr] sm:items-center sm:p-6"
            >
                <span class="flex items-center gap-2 font-medium">
                    <span
                        class="bg-primary ring-primary/30 size-3 rounded-full ring-2"
                        aria-hidden="true"
                    />
                    Sagay City
                </span>
                <span class="flex items-center gap-2 font-medium">
                    <span
                        class="border-foreground bg-background size-3 rounded-full border-2"
                        aria-hidden="true"
                    />
                    Other store locations
                </span>
            </figcaption>
        </figure>
    </section>
</template>

<style scoped>
:deep(.leaflet-popup-content-wrapper),
:deep(.leaflet-popup-tip),
:deep(.leaflet-tooltip),
:deep(.leaflet-bar a),
:deep(.leaflet-control-attribution) {
    background: var(--popover);
    color: var(--popover-foreground);
}

:deep(.leaflet-tooltip),
:deep(.leaflet-bar) {
    border-color: var(--input);
}

:deep(.leaflet-bar a:hover) {
    background: var(--accent);
}

:deep(.leaflet-control-attribution a),
:deep(.leaflet-popup-close-button) {
    color: var(--primary-ink);
}
</style>
