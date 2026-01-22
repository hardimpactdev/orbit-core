import { useConnectionStatus } from '@laravel/echo-vue';
import { usePage } from '@inertiajs/vue3';
import { ref, computed, type Ref } from 'vue';

export type ProvisionStatus =
    | 'queued'
    | 'provisioning'
    | 'validating_package'
    | 'creating_project'
    | 'forking'
    | 'creating_repo'
    | 'cloning'
    | 'setting_up'
    | 'installing_composer'
    | 'installing_npm'
    | 'building'
    | 'finalizing'
    | 'ready'
    | 'failed';

export type DeletionStatus =
    | 'deleting'
    | 'removing_files'
    | 'deleted'
    | 'delete_failed';

export interface ProvisionEvent {
    slug: string;
    status: ProvisionStatus;
    error?: string | null;
    site_id?: number | null;
    timestamp?: string;
}

export interface DeletionEvent {
    slug: string;
    status: DeletionStatus;
    error?: string | null;
    timestamp?: string;
}

export interface ProvisioningSite {
    slug: string;
    status: ProvisionStatus;
    error?: string | null;
    siteId?: number | null;
}

export interface DeletingSite {
    slug: string;
    status: DeletionStatus;
    error?: string | null;
}

interface ReverbProps {
    enabled?: boolean;
}

// Singleton state - persists across component re-mounts during Inertia navigation
// This ensures WebSocket events received during navigation aren't lost
const provisioningSites: Ref<Map<string, ProvisioningSite>> = ref(new Map());
const deletingSites: Ref<Map<string, DeletingSite>> = ref(new Map());
const siteReadyCount = ref(0);
const siteDeletedCount = ref(0);
const processedEvents = new Map<string, string>();
const processedDeletionEvents = new Map<string, string>();

/**
 * Global event handler for provisioning events - called from app.ts
 * This bypasses useEchoPublic's lifecycle hooks to ensure events are never missed
 */
export function globalProvisioningHandler(event: ProvisionEvent | DeletionEvent) {
    if ('status' in event) {
        // Check if it's a deletion event
        if (['deleting', 'removing_files', 'deleted', 'delete_failed'].includes(event.status)) {
            handleGlobalDeletionEvent(event as DeletionEvent);
        } else {
            handleGlobalProvisionEvent(event as ProvisionEvent);
        }
    }
}

function handleGlobalProvisionEvent(event: ProvisionEvent) {
    // Deduplicate events
    if (processedEvents.get(event.slug) === event.status) {
        return;
    }
    processedEvents.set(event.slug, event.status);

    const existing = provisioningSites.value.get(event.slug);
    provisioningSites.value.set(event.slug, {
        slug: event.slug,
        status: event.status,
        error: event.error,
        siteId: event.site_id ?? existing?.siteId,
    });

    // Remove from tracking if terminal state
    if (event.status === 'ready' || event.status === 'failed') {
        if (event.status === 'ready') {
            siteReadyCount.value++;
        }
        setTimeout(() => {
            provisioningSites.value.delete(event.slug);
            processedEvents.delete(event.slug);
        }, 15000);
    }
}

function handleGlobalDeletionEvent(event: DeletionEvent) {
    // Deduplicate events
    if (processedDeletionEvents.get(event.slug) === event.status) {
        return;
    }
    processedDeletionEvents.set(event.slug, event.status);

    deletingSites.value.set(event.slug, {
        slug: event.slug,
        status: event.status,
        error: event.error,
    });

    // Remove from tracking if terminal state
    if (event.status === 'deleted' || event.status === 'delete_failed') {
        if (event.status === 'deleted') {
            siteDeletedCount.value++;
        }
        setTimeout(() => {
            deletingSites.value.delete(event.slug);
            processedDeletionEvents.delete(event.slug);
        }, 2000);
    }
}

/**
 * Composable for listening to site provisioning events via WebSocket.
 * Uses the globally configured Echo connection.
 * State is singleton - persists across navigations.
 */
export function useSiteProvisioning() {
    const connectionStatus = useConnectionStatus();
    const page = usePage();

    const reverbEnabled = computed(
        () => Boolean((page.props.reverb as ReverbProps | undefined)?.enabled),
    );

    const isConfigured = computed(() => reverbEnabled.value);

    const isConnected = computed(() =>
        reverbEnabled.value && connectionStatus.value === 'connected',
    );
    const connectionError = computed(() =>
        reverbEnabled.value && connectionStatus.value === 'failed'
            ? 'Reverb connection unavailable'
            : null,
    );

    // WebSocket listeners are now set up globally in app.ts to avoid
    // lifecycle issues with useEchoPublic during Inertia navigation

    function trackDeletion(slug: string) {
        if (!deletingSites.value.has(slug)) {
            deletingSites.value.set(slug, {
                slug,
                status: 'deleting',
            });
        }
    }

    function getDeletionStatus(slug: string): DeletingSite | undefined {
        return deletingSites.value.get(slug);
    }

    function markDeletionComplete(slug: string) {
        deletingSites.value.set(slug, {
            slug,
            status: 'deleted',
        });
        siteDeletedCount.value++;
        setTimeout(() => {
            deletingSites.value.delete(slug);
        }, 2000);
    }

    function markDeletionFailed(slug: string, error?: string) {
        deletingSites.value.set(slug, {
            slug,
            status: 'delete_failed',
            error,
        });
    }

    function clearDeletion(slug: string) {
        deletingSites.value.delete(slug);
        processedDeletionEvents.delete(slug);
    }

    function trackSite(slug: string) {
        if (!provisioningSites.value.has(slug)) {
            provisioningSites.value.set(slug, {
                slug,
                status: 'queued',
            });
        }
        // Events are now handled globally in app.ts
    }

    function getSiteStatus(slug: string): ProvisioningSite | undefined {
        return provisioningSites.value.get(slug);
    }

    function disconnect() {
        // no-op: channels are cleaned up by the echo-vue composables
    }

    return {
        provisioningSites,
        deletingSites,
        isConnected,
        connectionError,
        isConfigured,
        siteReadyCount,
        siteDeletedCount,
        connect: () => undefined,
        disconnect,
        trackSite,
        getSiteStatus,
        trackDeletion,
        getDeletionStatus,
        markDeletionComplete,
        markDeletionFailed,
        clearDeletion,
    };
}
