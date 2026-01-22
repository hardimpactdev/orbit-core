<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import api from '@/lib/axios';
import Layout from '@/layouts/Layout.vue';
import Heading from '@/components/Heading.vue';
import Modal from '@/components/Modal.vue';
import {
    ArrowLeft,
    Boxes,
    Plus,
    Trash2,
    ExternalLink,
    FolderGit2,
    Loader2,
    X,
    Terminal,
    Package,
    Link2,
    Unlink,
    ChevronDown,
    ChevronRight,
} from 'lucide-vue-next';
import EditorIcon from '@/components/icons/EditorIcon.vue';
import { Button, Label, Badge } from '@hardimpactdev/craft-ui';
import { toast } from 'vue-sonner';

interface Environment {
    id: number;
    name: string;
    host: string;
    user: string;
    is_local: boolean;
    external_access: boolean;
    external_host: string | null;
}

interface Editor {
    scheme: string;
    name: string;
}

interface WorkspaceSite {
    name: string;
    path: string;
}

interface Workspace {
    name: string;
    path: string;
    sites: WorkspaceSite[];
    site_count: number;
    has_workspace_file: boolean;
    has_claude_md: boolean;
}

interface Site {
    name: string;
    display_name?: string;
    path: string;
    has_public_folder: boolean;
}

interface LinkedPackage {
    name: string;
    path: string;
}

const props = defineProps<{
    environment: Environment;
    workspaceName: string;
    editor: Editor;
    remoteApiUrl: string | null; // Direct API URL for remote environments (bypasses NativePHP)
}>();

// Helper to get the API URL - uses remote API directly when available, falls back to NativePHP
const getApiUrl = (path: string) => {
    if (props.remoteApiUrl) {
        return `${props.remoteApiUrl}${path}`;
    }
    return `/api/environments/${props.environment.id}${path}`;
};

defineOptions({
    layout: Layout,
});

// Workspace data loading
const workspace = ref<Workspace | null>(null);
const loadingWorkspace = ref(true);
const workspaceError = ref<string | null>(null);

const showAddSiteModal = ref(false);
const selectedSite = ref('');
const addingSite = ref(false);
const removingSite = ref<string | null>(null);

async function loadWorkspace() {
    loadingWorkspace.value = true;
    workspaceError.value = null;
    try {
        const { data: result } = await api.get(getApiUrl(`/workspaces/${props.workspaceName}`));
        if (result.success && result.data) {
            workspace.value = result.data;
        } else {
            workspaceError.value = result.error || 'Workspace not found';
        }
    } catch (error) {
        if (axios.isCancel(error)) return;
        console.error('Failed to load workspace:', error);
        workspaceError.value = 'Failed to load workspace';
    } finally {
        loadingWorkspace.value = false;
    }
}

// Lazy-loaded sites for "Add site" dropdown
const allSites = ref<Site[]>([]);
const loadingSites = ref(false);

// Package linking state
const expandedSites = ref<Set<string>>(new Set());
const linkedPackages = ref<Record<string, LinkedPackage[]>>({});
const loadingPackages = ref<Set<string>>(new Set());
const showLinkPackageModal = ref(false);
const linkingToSite = ref<string | null>(null);
const selectedPackage = ref('');
const linkingPackage = ref(false);
const unlinkingPackage = ref<string | null>(null);

// Sites available to add (not already in workspace)
const availableSites = computed(() => {
    if (!workspace.value) return [];
    const workspaceSiteNames = new Set(
        workspace.value.sites.map((p: WorkspaceSite) => p.name),
    );
    return allSites.value.filter((p) => !workspaceSiteNames.has(p.name));
});

// Load sites when opening the add site modal
const openAddSiteModal = async () => {
    showAddSiteModal.value = true;

    // Only load if not already loaded
    if (allSites.value.length === 0) {
        loadingSites.value = true;
        try {
            const { data: result } = await api.get(getApiUrl('/sites'));
            if (result.success && result.data?.sites) {
                allSites.value = result.data.sites;
            }
        } catch (error) {
            if (axios.isCancel(error)) return;
            console.error('Failed to load sites:', error);
        } finally {
            loadingSites.value = false;
        }
    }
};

const openInEditor = () => {
    if (!workspace.value) return;
    const workspacePath = workspace.value.path;
    const workspaceFile = `${workspacePath}/${workspace.value.name}.code-workspace`;

    let url: string;
    if (props.environment.external_access || !props.environment.is_local) {
        // Use SSH remote URL for external access or remote environments
        const user = props.environment.user;
        const host = props.environment.external_access && props.environment.external_host
            ? props.environment.external_host
            : props.environment.host;
        url = `${props.editor.scheme}://vscode-remote/ssh-remote+${user}@${host}${workspaceFile}?windowId=_blank`;
    } else {
        // Use local file URL
        url = `${props.editor.scheme}://file${workspaceFile}`;
    }
    window.open(url, '_blank');
};

const openInTerminal = () => {
    if (!workspace.value) return;
    const user = props.environment.user;
    const host = props.environment.external_access && props.environment.external_host
        ? props.environment.external_host
        : props.environment.host;
    // Use ssh:// protocol with path - OS handles opening the terminal
    const url = `ssh://${user}@${host}${workspace.value.path}`;
    window.open(url, '_self');
};

const addSite = async () => {
    if (!selectedSite.value || !workspace.value) return;

    const siteName = selectedSite.value;
    addingSite.value = true;

    try {
        const { data } = await api.post(
            getApiUrl(`/workspaces/${workspace.value.name}/sites`),
            { site: siteName },
            {},
        );

        if (data.success && data.workspace) {
            workspace.value = data.workspace;
            toast.success(`Site "${siteName}" added to workspace`);
        } else {
            toast.error('Failed to add site', {
                description: data.error || 'Unknown error',
            });
        }

        showAddSiteModal.value = false;
        selectedSite.value = '';
    } catch {
        // Error toast handled by axios interceptor
    } finally {
        addingSite.value = false;
    }
};

const removeSite = async (siteName: string) => {
    if (!workspace.value) return;
    removingSite.value = siteName;

    try {
        const { data } = await api.delete(
            getApiUrl(`/workspaces/${workspace.value.name}/sites/${siteName}`),
            {},
        );

        if (data.success && data.workspace) {
            workspace.value = data.workspace;
            toast.success(`Site "${siteName}" removed from workspace`);
        } else {
            toast.error('Failed to remove site', {
                description: data.error || 'Unknown error',
            });
        }
    } catch {
        // Error toast handled by axios interceptor
    } finally {
        removingSite.value = null;
    }
};

const getSiteDisplayName = (name: string) => {
    const site = allSites.value.find((p: Site) => p.name === name);
    return site?.display_name || name;
};

// Package linking functions
const toggleSiteExpanded = (siteName: string) => {
    if (expandedSites.value.has(siteName)) {
        expandedSites.value.delete(siteName);
    } else {
        expandedSites.value.add(siteName);
        // Load linked packages if not already loaded
        if (!linkedPackages.value[siteName]) {
            loadLinkedPackages(siteName);
        }
    }
    // Force reactivity
    expandedSites.value = new Set(expandedSites.value);
};

const loadLinkedPackages = async (siteName: string) => {
    loadingPackages.value.add(siteName);
    loadingPackages.value = new Set(loadingPackages.value);

    try {
        const { data } = await api.get(getApiUrl(`/packages/${siteName}/linked`), {});

        if (data.success) {
            linkedPackages.value[siteName] = data.linked_packages || [];
        }
    } catch {
        // Error toast handled by axios interceptor
    } finally {
        loadingPackages.value.delete(siteName);
        loadingPackages.value = new Set(loadingPackages.value);
    }
};

// Get packages available to link (other sites in workspace that aren't the target app)
const availablePackagesToLink = computed(() => {
    if (!linkingToSite.value || !workspace.value) return [];
    return workspace.value.sites.filter((p) => p.name !== linkingToSite.value);
});

const openLinkPackageModal = (siteName: string) => {
    linkingToSite.value = siteName;
    selectedPackage.value = '';
    showLinkPackageModal.value = true;
};

const linkPackage = async () => {
    if (!selectedPackage.value || !linkingToSite.value) return;

    const packageName = selectedPackage.value;
    const siteName = linkingToSite.value;
    linkingPackage.value = true;

    try {
        const { data } = await api.post(
            getApiUrl(`/packages/${siteName}/link`),
            { package: packageName },
            {},
        );

        if (data.success) {
            toast.success(`Package "${packageName}" linked to "${siteName}"`);
            // Reload linked packages for this site
            await loadLinkedPackages(siteName);
        } else {
            toast.error('Failed to link package', {
                description: data.error || 'Unknown error',
            });
        }

        showLinkPackageModal.value = false;
        selectedPackage.value = '';
    } catch {
        // Error toast handled by axios interceptor
    } finally {
        linkingPackage.value = false;
    }
};

const unlinkPackage = async (siteName: string, packageName: string) => {
    unlinkingPackage.value = `${siteName}:${packageName}`;

    try {
        const { data } = await api.delete(
            getApiUrl(`/packages/${siteName}/unlink/${encodeURIComponent(packageName)}`),
            {},
        );

        if (data.success) {
            toast.success(`Package "${packageName}" unlinked from "${siteName}"`);
            // Reload linked packages for this site
            await loadLinkedPackages(siteName);
        } else {
            toast.error('Failed to unlink package', {
                description: data.error || 'Unknown error',
            });
        }
    } catch {
        // Error toast handled by axios interceptor
    } finally {
        unlinkingPackage.value = null;
    }
};

onMounted(() => {
    loadWorkspace();
});
</script>

<template>
    <Head :title="`${workspace?.name || workspaceName} - Workspaces`" />

    <!-- Loading State -->
    <div v-if="loadingWorkspace">
        <header class="flex items-center gap-4 mb-8">
            <Link
                :href="`/environments/${environment.id}/workspaces`"
                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-zinc-800/50 border border-zinc-700/50 text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors"
            >
                <ArrowLeft class="w-4 h-4" />
            </Link>
            <div>
                <div class="flex items-center gap-2">
                    <Boxes class="w-5 h-5 text-lime-400" />
                    <h1 class="text-2xl font-semibold tracking-tight text-zinc-100">{{ workspaceName }}</h1>
                </div>
            </div>
        </header>
        <div class="rounded-lg border border-zinc-800 bg-zinc-900/50 p-8 text-center">
            <Loader2 class="w-8 h-8 mx-auto text-zinc-600 animate-spin mb-3" />
            <p class="text-zinc-500">Loading workspace...</p>
        </div>
    </div>

    <!-- Error State -->
    <div v-else-if="workspaceError">
        <header class="flex items-center gap-4 mb-8">
            <Link
                :href="`/environments/${environment.id}/workspaces`"
                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-zinc-800/50 border border-zinc-700/50 text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors"
            >
                <ArrowLeft class="w-4 h-4" />
            </Link>
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-zinc-100">Workspace Not Found</h1>
            </div>
        </header>
        <div class="rounded-lg border border-zinc-800 bg-zinc-900/50 p-8 text-center">
            <p class="text-red-400 mb-4">{{ workspaceError }}</p>
            <Button as-child variant="secondary" size="sm">
                <Link :href="`/environments/${environment.id}/workspaces`">
                    Back to Workspaces
                </Link>
            </Button>
        </div>
    </div>

    <!-- Loaded Content -->
    <div v-else-if="workspace">
        <!-- Header -->
        <header class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between mb-8">
            <div class="flex items-center gap-4">
                <Link
                    :href="`/environments/${environment.id}/workspaces`"
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-zinc-800/50 border border-zinc-700/50 text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors"
                >
                    <ArrowLeft class="w-4 h-4" />
                </Link>
                <div>
                    <div class="flex items-center gap-2">
                        <Boxes class="w-5 h-5 text-lime-400" />
                        <h1 class="text-2xl font-semibold tracking-tight text-zinc-100">{{ workspace.name }}</h1>
                    </div>
                    <p class="text-sm text-zinc-500 mt-1">
                        {{ workspace.site_count }} site{{ workspace.site_count !== 1 ? 's' : '' }}
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <Button
                    v-if="environment.external_access"
                    @click="openInTerminal"
                    variant="secondary"
                    size="sm"
                    title="Open in Terminal"
                >
                    <Terminal class="w-4 h-4 mr-1.5" />
                    SSH
                </Button>
                <Button
                    v-if="workspace.has_workspace_file"
                    @click="openInEditor"
                    variant="secondary"
                    size="sm"
                >
                    <EditorIcon :editor="editor.scheme" class="w-4 h-4 mr-1.5" />
                    Open in {{ editor.name }}
                </Button>
                <Button
                    @click="openAddSiteModal"
                    size="sm"
                    class="bg-lime-500 hover:bg-lime-600 text-zinc-950"
                >
                    <Plus class="w-4 h-4 mr-1.5" />
                    Add Site
                </Button>
            </div>
        </header>

        <!-- Sites List -->
        <div class="space-y-6">
            <div class="rounded-lg border border-zinc-800 bg-zinc-900/50 overflow-hidden">
                <!-- Section Header -->
                <div class="px-4 py-3 border-b border-zinc-800 bg-zinc-800/30">
                    <h2 class="text-sm font-medium text-zinc-100">Sites</h2>
                </div>

                <!-- Empty State -->
                <div v-if="workspace.sites.length === 0" class="p-8 text-center">
                    <div class="flex h-12 w-12 mx-auto items-center justify-center rounded-md bg-zinc-800/50 border border-zinc-700/50 mb-4">
                        <FolderGit2 class="w-6 h-6 text-zinc-400" />
                    </div>
                    <p class="text-zinc-500 mb-4">No sites in this workspace yet.</p>
                    <Button
                        @click="openAddSiteModal"
                        size="sm"
                        class="bg-lime-500 hover:bg-lime-600 text-zinc-950"
                    >
                        <Plus class="w-4 h-4 mr-1.5" />
                        Add Your First Site
                    </Button>
                </div>

                <!-- Site Rows -->
                <div v-else class="divide-y divide-zinc-800/50">
                    <div
                        v-for="site in workspace.sites"
                        :key="site.name"
                    >
                        <div class="flex items-center gap-4 px-4 py-3 transition-colors hover:bg-zinc-800/30">
                            <!-- Expand Button -->
                            <button
                                @click="toggleSiteExpanded(site.name)"
                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-zinc-800/50 border border-zinc-700/50 text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors"
                            >
                                <ChevronDown
                                    v-if="expandedSites.has(site.name)"
                                    class="w-4 h-4"
                                />
                                <ChevronRight v-else class="w-4 h-4" />
                            </button>

                            <!-- Site Info -->
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <FolderGit2 class="w-4 h-4 text-lime-400" />
                                    <span class="font-medium text-sm text-zinc-100">{{ getSiteDisplayName(site.name) }}</span>
                                    <span class="text-xs text-zinc-500 font-mono">{{ site.name }}</span>
                                </div>
                            </div>

                            <!-- Actions -->
                            <div class="flex items-center gap-0.5 opacity-40 hover:opacity-100 transition-opacity">
                                <Button
                                    @click="openLinkPackageModal(site.name)"
                                    variant="ghost"
                                    size="icon-sm"
                                    class="h-8 w-8 text-zinc-400 hover:text-lime-400 hover:bg-zinc-800"
                                    title="Link a package"
                                >
                                    <Link2 class="w-3.5 h-3.5" />
                                </Button>
                                <Button
                                    @click="removeSite(site.name)"
                                    variant="ghost"
                                    size="icon-sm"
                                    class="h-8 w-8 text-zinc-400 hover:text-red-400 hover:bg-zinc-800"
                                    :disabled="removingSite === site.name"
                                    title="Remove site"
                                >
                                    <Loader2
                                        v-if="removingSite === site.name"
                                        class="w-3.5 h-3.5 animate-spin"
                                    />
                                    <X v-else class="w-3.5 h-3.5" />
                                </Button>
                            </div>
                        </div>

                        <!-- Expanded: Linked Packages -->
                        <div
                            v-if="expandedSites.has(site.name)"
                            class="px-4 pb-4 pl-16 border-t border-zinc-800/50 bg-zinc-900/30"
                        >
                            <div class="pt-4">
                                <div class="flex items-center gap-2 mb-3">
                                    <Package class="w-3.5 h-3.5 text-zinc-500" />
                                    <span class="text-xs text-zinc-500 uppercase tracking-wider font-medium">Linked Packages</span>
                                </div>

                                <div
                                    v-if="loadingPackages.has(site.name)"
                                    class="py-3 text-center"
                                >
                                    <Loader2 class="w-4 h-4 animate-spin text-zinc-500 mx-auto" />
                                </div>

                                <div
                                    v-else-if="
                                        !linkedPackages[site.name] ||
                                        linkedPackages[site.name].length === 0
                                    "
                                    class="py-2"
                                >
                                    <p class="text-xs text-zinc-500">No packages linked.</p>
                                </div>

                                <div v-else class="space-y-1">
                                    <div
                                        v-for="pkg in linkedPackages[site.name]"
                                        :key="pkg.name"
                                        class="flex items-center justify-between py-2 px-3 rounded-md bg-zinc-800/50 border border-zinc-700/50"
                                    >
                                        <div class="flex items-center gap-2">
                                            <Package class="w-3.5 h-3.5 text-amber-400" />
                                            <span class="text-sm text-zinc-300 font-mono">{{ pkg.name }}</span>
                                        </div>
                                        <Button
                                            @click="unlinkPackage(site.name, pkg.name)"
                                            variant="ghost"
                                            size="icon-sm"
                                            class="h-7 w-7 text-zinc-400 hover:text-red-400 hover:bg-zinc-700"
                                            :disabled="unlinkingPackage === `${site.name}:${pkg.name}`"
                                            title="Unlink package"
                                        >
                                            <Loader2
                                                v-if="unlinkingPackage === `${site.name}:${pkg.name}`"
                                                class="w-3 h-3 animate-spin"
                                            />
                                            <Unlink v-else class="w-3 h-3" />
                                        </Button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Workspace Info -->
            <div class="rounded-lg border border-zinc-800 bg-zinc-900/50 overflow-hidden">
                <!-- Section Header -->
                <div class="px-4 py-3 border-b border-zinc-800 bg-zinc-800/30">
                    <h2 class="text-sm font-medium text-zinc-100">Workspace Info</h2>
                </div>

                <!-- Info Rows -->
                <div class="divide-y divide-zinc-800/50">
                    <div class="flex items-center gap-4 px-4 py-3">
                        <span class="text-sm text-zinc-500 w-28 shrink-0">Path</span>
                        <span class="font-mono text-sm text-zinc-300">{{ workspace.path }}</span>
                    </div>
                    <div class="flex items-center gap-4 px-4 py-3">
                        <span class="text-sm text-zinc-500 w-28 shrink-0">Workspace file</span>
                        <div class="flex items-center gap-2">
                            <span class="font-mono text-sm text-zinc-300">{{ workspace.name }}.code-workspace</span>
                            <span
                                v-if="workspace.has_workspace_file"
                                class="px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide rounded-full bg-lime-500/10 text-lime-400 ring-1 ring-inset ring-lime-500/20"
                            >
                                Ready
                            </span>
                        </div>
                    </div>
                    <div class="flex items-center gap-4 px-4 py-3">
                        <span class="text-sm text-zinc-500 w-28 shrink-0">CLAUDE.md</span>
                        <span
                            v-if="workspace.has_claude_md"
                            class="px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide rounded-full bg-lime-500/10 text-lime-400 ring-1 ring-inset ring-lime-500/20"
                        >
                            Present
                        </span>
                        <span v-else class="text-sm text-zinc-500">Not found</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Site Modal -->
    <Modal
        :show="showAddSiteModal"
        title="Add Site to Workspace"
        @close="showAddSiteModal = false"
    >
        <div class="p-6">
            <div v-if="loadingSites" class="text-center py-6">
                <Loader2 class="w-6 h-6 mx-auto text-zinc-400 animate-spin mb-2" />
                <p class="text-zinc-400">Loading sites...</p>
            </div>

            <div v-else-if="availableSites.length === 0" class="text-center py-6">
                <p class="text-zinc-400">All sites are already in this workspace.</p>
            </div>

            <div v-else>
                <label for="site-select" class="block text-sm font-medium text-zinc-300 mb-2">
                    Select a site
                </label>
                <select id="site-select" v-model="selectedSite" class="w-full">
                    <option value="">Choose a site...</option>
                    <option
                        v-for="site in availableSites"
                        :key="site.name"
                        :value="site.name"
                    >
                        {{ site.display_name || site.name }}
                    </option>
                </select>
            </div>

            <div class="flex justify-end gap-3 mt-6">
                <Button @click="showAddSiteModal = false" variant="ghost">Cancel</Button>
                <Button
                    @click="addSite"
                    variant="secondary"
                    :disabled="!selectedSite || addingSite"
                >
                    <Loader2 v-if="addingSite" class="w-4 h-4 mr-2 animate-spin" />
                    Add Site
                </Button>
            </div>
        </div>
    </Modal>

    <!-- Link Package Modal -->
    <Modal :show="showLinkPackageModal" title="Link Package" @close="showLinkPackageModal = false">
        <div class="p-6">
            <p class="text-zinc-400 mb-4">
                Link a package from this workspace to
                <span class="text-white font-medium">{{ linkingToSite }}</span> for local
                development.
            </p>

            <div v-if="availablePackagesToLink.length === 0" class="text-center py-6">
                <p class="text-zinc-400">
                    No other sites in this workspace to link as packages.
                </p>
            </div>

            <div v-else>
                <label for="package-select" class="block text-sm font-medium text-zinc-300 mb-2">
                    Select a package to link
                </label>
                <select id="package-select" v-model="selectedPackage" class="w-full">
                    <option value="">Choose a package...</option>
                    <option
                        v-for="site in availablePackagesToLink"
                        :key="site.name"
                        :value="site.name"
                    >
                        {{ getSiteDisplayName(site.name) }}
                    </option>
                </select>
                <p class="text-xs text-zinc-500 mt-2">
                    The selected site will be symlinked as a Composer dependency.
                </p>
            </div>

            <div class="flex justify-end gap-3 mt-6">
                <Button @click="showLinkPackageModal = false" variant="ghost">Cancel</Button>
                <Button
                    @click="linkPackage"
                    variant="secondary"
                    :disabled="!selectedPackage || linkingPackage"
                >
                    <Loader2 v-if="linkingPackage" class="w-4 h-4 mr-2 animate-spin" />
                    <Link2 v-else class="w-4 h-4 mr-2" />
                    Link Package
                </Button>
            </div>
        </div>
    </Modal>
</template>
