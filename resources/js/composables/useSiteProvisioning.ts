import { useEchoPublic, useConnectionStatus } from '@laravel/echo-vue';
import { usePage } from '@inertiajs/vue3';
import { ref, computed, type Ref } from 'vue';

export type ProvisionStatus =
    | 'queued'
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
    timestamp: string;
}

export interface DeletionEvent {
    slug: string;
    status: DeletionStatus;
    error?: string | null;
    timestamp: string;
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

/**
 * Composable for listening to site provisioning events via WebSocket.
 * Uses the globally configured Echo connection.
 */
export function useSiteProvisioning() {
    const provisioningSites: Ref<Map<string, ProvisioningSite>> = ref(new Map());
    const deletingSites: Ref<Map<string, DeletingSite>> = ref(new Map());
    const connectionStatus = useConnectionStatus();
    const page = usePage();

    const reverbEnabled = computed(
        () => Boolean((page.props.reverb as ReverbProps | undefined)?.enabled),
    );

    const isConfigured = computed(() => reverbEnabled.value);


    // Reactive counters that increment on terminal events - easier to watch than Maps
    const siteReadyCount = ref(0);
    const siteDeletedCount = ref(0);

    const isConnected = computed(() =>
        reverbEnabled.value && connectionStatus.value === 'connected',
    );
    const connectionError = computed(() =>
        reverbEnabled.value && connectionStatus.value === 'failed'
            ? 'Reverb connection unavailable'
            : null,
    );

    // Track which slugs we've already processed to prevent duplicate handling
    const processedEvents = new Map<string, string>();

    function handleProvisionEvent(event: ProvisionEvent) {
        // Deduplicate events (we listen on multiple channels)
        if (processedEvents.get(event.slug) === event.status) {
            return;
        }
        processedEvents.set(event.slug, event.status);

        const existing = provisioningSites.value.get(event.slug);

        // Always use .set() to ensure Vue reactivity triggers properly
        provisioningSites.value.set(event.slug, {
            slug: event.slug,
            status: event.status,
            error: event.error,
            siteId: event.site_id ?? existing?.siteId,
        });

        // Remove from tracking if terminal state
        if (event.status === 'ready' || event.status === 'failed') {
            // Increment counter for watchers
            if (event.status === 'ready') {
                siteReadyCount.value++;
            }
            // Keep in list longer so UI can show final state
            setTimeout(() => {
                provisioningSites.value.delete(event.slug);
                processedEvents.delete(event.slug);
            }, 15000);
        }
    }

    if (reverbEnabled.value) {
        useEchoPublic('provisioning', '.site.provision.status', handleProvisionEvent);
        useEchoPublic('provisioning', '.site.deletion.status', handleDeletionEvent);
    }



    // Track deletion events separately
    const processedDeletionEvents = new Map<string, string>();

    function handleDeletionEvent(event: DeletionEvent) {
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
            // Increment counter for watchers
            if (event.status === 'deleted') {
                siteDeletedCount.value++;
            }
            setTimeout(() => {
                deletingSites.value.delete(event.slug);
                processedDeletionEvents.delete(event.slug);
            }, 2000);
        }
    }

    function trackDeletion(slug: string) {
        if (!deletingSites.value.has(slug)) {
            deletingSites.value.set(slug, {
                slug,
                status: 'deleting',
            });
        }

        useEchoPublic(`site.${slug}`, '.site.deletion.status', (event: DeletionEvent) => {
            if (!reverbEnabled.value) {
                return;
            }

            handleDeletionEvent(event);
        });
    }

    function getDeletionStatus(slug: string): DeletingSite | undefined {
        return deletingSites.value.get(slug);
    }

    function markDeletionComplete(slug: string) {
        deletingSites.value.set(slug, {
            slug,
            status: 'deleted',
        });
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

        useEchoPublic(`site.${slug}`, '.site.provision.status', (event: ProvisionEvent) => {
            if (!reverbEnabled.value) {
                return;
            }

            handleProvisionEvent(event);
        });
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
