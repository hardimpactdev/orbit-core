<script setup lang="ts">
import { ref, onMounted, computed, watch } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import DnsSettings from '@/components/DnsSettings.vue';
import Modal from '@/components/Modal.vue';
import {
    Loader2,
    Trash2,
    Plus,
    AlertTriangle,
    Check,
    AlertCircle,
    Stethoscope,
    RefreshCw,
    CheckCircle2,
    XCircle,
    AlertTriangleIcon,
    ChevronDown,
    ChevronRight,
    Globe,
    Key,
    Copy,
    Star,
    Pencil,
    FileCode2,
    ExternalLink,
} from 'lucide-vue-next';
import { Button, Badge, Input, Select, SelectContent, SelectItem, SelectTrigger, SelectValue, Switch, Textarea, Label } from '@hardimpactdev/craft-ui';

interface Environment {
    id: number;
    name: string;
    host: string;
    user: string;
    port: number;
    is_local: boolean;
    editor_scheme: string | null;
    external_access: boolean;
    external_host: string | null;
}

interface SshKey {
    id: number;
    name: string;
    public_key: string;
    key_type: string;
    is_default: boolean;
}

interface AvailableKey {
    content: string;
    type: string;
}

interface TemplateFavorite {
    id: number;
    repo_url: string;
    display_name: string;
    usage_count: number;
    last_used_at: string | null;
}

interface Editor {
    scheme: string;
    name: string;
}

interface Config {
    paths: string[];
    tld: string;
    default_php_version: string;
    available_php_versions?: string[];
}

const props = defineProps<{
    environment: Environment;
    remoteApiUrl: string | null;
    editor: Editor;
    editorOptions: Record<string, string>;
    sshKeys: SshKey[];
    availableSshKeys: Record<string, AvailableKey>;
    templateFavorites: TemplateFavorite[];
    notificationsEnabled: boolean;
    menuBarEnabled: boolean;
}>();

const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';

// Whether this is an external environment viewed from desktop
const isExternalEnvironment = computed(() => !props.environment.is_local && props.remoteApiUrl);

// Environment form
const envForm = useForm({
    name: props.environment.name,
    host: props.environment.host,
    user: props.environment.user,
    port: props.environment.port,
    editor_scheme: props.editor.scheme,
});

// Instance info sync (for external environments)
const instanceInfoLoading = ref(false);
const instanceInfoError = ref<string | null>(null);
const nameSaving = ref(false);

// CLI config
const config = ref<Config | null>(null);
const configLoading = ref(true);
const configSaving = ref(false);
const editPaths = ref<string[]>([]);
const editTld = ref('');
const editPhpVersion = ref('8.4');
const availablePhpVersions = ref<string[]>(['8.3', '8.4', '8.5']);

// Delete confirmation
const showDeleteConfirm = ref(false);
const deleteConfirmName = ref('');

const tld = ref('test');

// Doctor/Health Check state
interface HealthCheck {
    status: 'ok' | 'warning' | 'error';
    message: string;
    details?: Record<string, unknown>;
}

interface DoctorResult {
    success: boolean;
    status: 'healthy' | 'degraded' | 'unhealthy';
    checks: Record<string, HealthCheck>;
    summary: {
        passed: number;
        warnings: number;
        errors: number;
        total: number;
        messages: string[];
    };
}

const doctorRunning = ref(false);
const doctorResult = ref<DoctorResult | null>(null);
const doctorError = ref<string | null>(null);
const expandedChecks = ref<Set<string>>(new Set());

const checkLabels: Record<string, string> = {
    ssh: 'SSH Connection',
    cli: 'CLI Installation',
    docker: 'Docker Services',
    api: 'API Connectivity',
    environment_dns: 'Environment DNS',
    local_dns: 'Local DNS',
    config: 'Configuration',
};

function toggleCheckExpanded(key: string) {
    if (expandedChecks.value.has(key)) {
        expandedChecks.value.delete(key);
    } else {
        expandedChecks.value.add(key);
    }
}

const fixingChecks = ref<Set<string>>(new Set());

async function runDoctor() {
    doctorRunning.value = true;
    doctorError.value = null;
    doctorResult.value = null;

    try {
        const response = await fetch(`/environments/${props.environment.id}/doctor`);
        const result = await response.json();

        if (result.success) {
            doctorResult.value = result;
        } else {
            doctorError.value = result.error || 'Health check failed';
        }
    } catch (error) {
        doctorError.value = 'Failed to run health check';
    } finally {
        doctorRunning.value = false;
    }
}

async function fixIssue(checkKey: string) {
    fixingChecks.value.add(checkKey);

    try {
        const response = await fetch(
            `/environments/${props.environment.id}/doctor/fix/${checkKey}`,
            {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Content-Type': 'application/json',
                },
            },
        );
        const result = await response.json();

        if (result.success) {
            // Re-run doctor to show updated status
            await runDoctor();
        } else {
            doctorError.value = result.message || 'Failed to fix issue';
        }
    } catch (error) {
        doctorError.value = 'Failed to fix issue';
    } finally {
        fixingChecks.value.delete(checkKey);
    }
}


// Instance info sync (for external environments)
async function loadInstanceInfo() {
    if (!isExternalEnvironment.value || !props.remoteApiUrl) return;

    instanceInfoLoading.value = true;
    instanceInfoError.value = null;

    try {
        const response = await fetch(`${props.remoteApiUrl}/instance-info`);
        const result = await response.json();

        if (result.success && result.data) {
            // Update form with the canonical name from remote
            envForm.name = result.data.name;
        } else {
            instanceInfoError.value = result.error || 'Failed to load instance info';
        }
    } catch (error) {
        instanceInfoError.value = 'Failed to connect to remote environment';
    } finally {
        instanceInfoLoading.value = false;
    }
}

async function saveRemoteName() {
    if (!props.remoteApiUrl) return;

    nameSaving.value = true;
    instanceInfoError.value = null;

    try {
        const response = await fetch(`${props.remoteApiUrl}/instance-info`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ name: envForm.name }),
        });

        const result = await response.json();

        if (!result.success) {
            instanceInfoError.value = result.error || 'Failed to update name';
        }
    } catch (error) {
        instanceInfoError.value = 'Failed to connect to remote environment';
    } finally {
        nameSaving.value = false;
    }
}

async function loadConfig() {
    configLoading.value = true;
    try {
        const response = await fetch(`/environments/${props.environment.id}/config`);
        const result = await response.json();

        if (result.success) {
            config.value = result.data;
            editPaths.value = [...(result.data.paths || [])];
            if (editPaths.value.length === 0) editPaths.value.push('');
            editTld.value = result.data.tld || 'test';
            editPhpVersion.value = result.data.default_php_version || '8.4';
            tld.value = result.data.tld || 'test';
            if (result.data.available_php_versions?.length) {
                availablePhpVersions.value = result.data.available_php_versions;
            }
        }
    } catch (error) {
        console.error('Failed to load config:', error);
    } finally {
        configLoading.value = false;
    }
}

async function saveEnvSettings() {
    // For external environments, update the remote's canonical name via API
    if (isExternalEnvironment.value) {
        await saveRemoteName();
    }
    // Always save to local database (SSH connection info, etc.)
    envForm.post(`/environments/${props.environment.id}/settings`);
}

function addPath() {
    editPaths.value.push('');
}

function removePath(index: number) {
    editPaths.value.splice(index, 1);
}

async function saveConfig() {
    const paths = editPaths.value.filter((p) => p.trim() !== '');
    if (paths.length === 0) {
        alert('Please add at least one site path');
        return;
    }

    configSaving.value = true;
    try {
        const response = await fetch(`/environments/${props.environment.id}/config`, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Content-Type': 'application/json' },
            body: JSON.stringify({
                paths,
                tld: editTld.value.trim() || 'test',
                default_php_version: editPhpVersion.value,
            }),
        });
        const result = await response.json();

        if (result.success) {
            config.value = result.data;
            tld.value = editTld.value.trim() || 'test';
        } else {
            alert('Failed to save config: ' + (result.error || 'Unknown error'));
        }
    } catch {
        alert('Failed to save config');
    } finally {
        configSaving.value = false;
    }
}

function confirmDelete() {
    showDeleteConfirm.value = true;
    deleteConfirmName.value = '';
}

function cancelDelete() {
    showDeleteConfirm.value = false;
    deleteConfirmName.value = '';
}

function deleteEnvironment() {
    if (deleteConfirmName.value !== props.environment.name) {
        return;
    }

    router.delete(`/environments/${props.environment.id}`, {
        onSuccess: () => {
            // Will redirect to dashboard which will then redirect appropriately
        },
    });
}

onMounted(() => {
    loadConfig();
    // For external environments, fetch the canonical name from remote
    if (isExternalEnvironment.value) {
        loadInstanceInfo();
    }
});

// === External Access ===
const externalAccessForm = useForm({
    external_access: props.environment.external_access ?? false,
    external_host: props.environment.external_host ?? '',
});

const saveExternalAccess = () => {
    externalAccessForm.post(`/environments/${props.environment.id}/settings/external-access`);
};

watch(() => externalAccessForm.external_access, (newValue, oldValue) => {
    if (oldValue === true && newValue === false) {
        saveExternalAccess();
    }
});

// === SSH Keys ===
const showKeyModal = ref(false);
const editingKey = ref<SshKey | null>(null);

const keyForm = useForm({
    name: '',
    public_key: '',
});

const openAddKeyModal = () => {
    editingKey.value = null;
    keyForm.reset();
    showKeyModal.value = true;
};

const openEditKeyModal = (key: SshKey) => {
    editingKey.value = key;
    keyForm.name = key.name;
    keyForm.public_key = key.public_key;
    showKeyModal.value = true;
};

const closeKeyModal = () => {
    showKeyModal.value = false;
    editingKey.value = null;
    keyForm.reset();
};

const saveKey = () => {
    if (editingKey.value) {
        keyForm.put(`/ssh-keys/${editingKey.value.id}`, {
            onSuccess: closeKeyModal,
        });
    } else {
        keyForm.post('/ssh-keys', {
            onSuccess: closeKeyModal,
        });
    }
};

const deleteKey = (key: SshKey) => {
    if (confirm('Delete this SSH key?')) {
        router.delete(`/ssh-keys/${key.id}`);
    }
};

const setDefaultKey = (key: SshKey) => {
    router.post(`/ssh-keys/${key.id}/default`);
};

const copyKey = async (key: SshKey) => {
    await navigator.clipboard.writeText(key.public_key);
};

const importKey = (event: Event) => {
    const select = event.target as HTMLSelectElement;
    const option = select.selectedOptions[0];
    if (option.value) {
        keyForm.public_key = option.value;
        const keyName = option.dataset.name;
        if (keyName && !keyForm.name) {
            keyForm.name = keyName;
        }
    }
};

const truncateKey = (key: string, length = 80) => {
    return key.length > length ? key.substring(0, length) + '...' : key;
};

// === Template Favorites ===
const showTemplateModal = ref(false);
const editingTemplate = ref<TemplateFavorite | null>(null);

const templateForm = useForm({
    repo_url: '',
    display_name: '',
});

const openAddTemplateModal = () => {
    editingTemplate.value = null;
    templateForm.reset();
    showTemplateModal.value = true;
};

const openEditTemplateModal = (template: TemplateFavorite) => {
    editingTemplate.value = template;
    templateForm.repo_url = template.repo_url;
    templateForm.display_name = template.display_name;
    showTemplateModal.value = true;
};

const closeTemplateModal = () => {
    showTemplateModal.value = false;
    editingTemplate.value = null;
    templateForm.reset();
};

const saveTemplate = () => {
    if (editingTemplate.value) {
        templateForm.put(`/template-favorites/${editingTemplate.value.id}`, {
            onSuccess: closeTemplateModal,
        });
    } else {
        templateForm.post('/template-favorites', {
            onSuccess: closeTemplateModal,
        });
    }
};

const deleteTemplate = (template: TemplateFavorite) => {
    if (confirm('Delete this template?')) {
        router.delete(`/template-favorites/${template.id}`);
    }
};

const extractRepoName = (url: string): string => {
    const match = url.match(/(?:github\.com\/)?([^/]+)\/([^/]+)/);
    return match ? match[2] : url;
};

const onRepoUrlChange = () => {
    if (!editingTemplate.value && templateForm.repo_url && !templateForm.display_name) {
        templateForm.display_name = extractRepoName(templateForm.repo_url);
    }
};

const openGitHub = (url: string) => {
    const fullUrl = url.startsWith('http') ? url : `https://github.com/${url}`;
    window.open(fullUrl, '_blank');
};

// === Notifications & Menu Bar ===
const notificationForm = useForm({
    enabled: props.notificationsEnabled,
});

const toggleNotifications = () => {
    notificationForm.enabled = !notificationForm.enabled;
    notificationForm.post('/settings/notifications');
};

const menuBarForm = useForm({
    enabled: props.menuBarEnabled,
});

const toggleMenuBar = () => {
    menuBarForm.enabled = !menuBarForm.enabled;
    menuBarForm.post('/settings/menu-bar');
};
</script>

<template>
    <Head :title="`Settings - ${environment.name}`" />

    <div>
        <div class="mb-8">
            <Heading title="Settings" />
            <p class="text-zinc-400 mt-1">Configure {{ environment.name }}</p>
        </div>

        <form @submit.prevent="saveEnvSettings">
            <!-- Environment Name -->
            <div class="grid grid-cols-2 gap-8 py-6">
                <div>
                    <h3 class="text-sm font-medium text-white">Environment Name</h3>
                    <p class="text-sm text-zinc-500 mt-1">
                        Display name for this environment.
                        <template v-if="isExternalEnvironment">
                            <br /><span class="text-xs text-zinc-600">Changes will be synced to the remote environment.</span>
                        </template>
                    </p>
                </div>
                <div>
                    <div class="relative">
                        <Input
                            v-model="envForm.name"
                            type="text"
                            id="name"
                            class="w-full"
                            :disabled="instanceInfoLoading"
                        />
                        <Loader2
                            v-if="instanceInfoLoading"
                            class="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 animate-spin text-zinc-500"
                        />
                    </div>
                    <p v-if="envForm.errors.name" class="mt-2 text-sm text-red-400">
                        {{ envForm.errors.name }}
                    </p>
                    <p v-if="instanceInfoError" class="mt-2 text-sm text-amber-400">
                        {{ instanceInfoError }}
                    </p>
                </div>
            </div>

            <hr class="border-zinc-800" />

            <!-- Code Editor -->
            <div class="grid grid-cols-2 gap-8 py-6">
                <div>
                    <h3 class="text-sm font-medium text-white">Code Editor</h3>
                    <p class="text-sm text-zinc-500 mt-1">
                        Select your preferred editor for opening files in this environment.
                    </p>
                </div>
                <div>
                    <Select v-model="envForm.editor_scheme">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="Select editor" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="(name, scheme) in editorOptions"
                                :key="scheme"
                                :value="scheme"
                            >
                                {{ name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
            </div>

            <hr class="border-zinc-800" />

            <!-- SSH Connection (remote only) -->
            <template v-if="!environment.is_local">
                <div class="grid grid-cols-2 gap-8 py-6">
                    <div>
                        <h3 class="text-sm font-medium text-white">SSH Connection</h3>
                        <p class="text-sm text-zinc-500 mt-1">
                            Host, user, and port for SSH access.
                        </p>
                    </div>
                    <div class="space-y-4">
                        <div>
                            <label for="host" class="block text-sm font-medium text-zinc-400 mb-1"
                                >Host</label
                            >
                            <Input
                                v-model="envForm.host"
                                type="text"
                                id="host"
                                class="w-full font-mono"
                            />
                            <p v-if="envForm.errors.host" class="mt-1 text-sm text-red-400">
                                {{ envForm.errors.host }}
                            </p>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label
                                    for="user"
                                    class="block text-sm font-medium text-zinc-400 mb-1"
                                    >User</label
                                >
                                <Input
                                    v-model="envForm.user"
                                    type="text"
                                    id="user"
                                    class="w-full font-mono"
                                />
                                <p v-if="envForm.errors.user" class="mt-1 text-sm text-red-400">
                                    {{ envForm.errors.user }}
                                </p>
                            </div>
                            <div>
                                <label
                                    for="port"
                                    class="block text-sm font-medium text-zinc-400 mb-1"
                                    >Port</label
                                >
                                <Input
                                    v-model="envForm.port"
                                    type="number"
                                    id="port"
                                    class="w-full font-mono"
                                />
                                <p v-if="envForm.errors.port" class="mt-1 text-sm text-red-400">
                                    {{ envForm.errors.port }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <hr class="border-zinc-800" />
            </template>

            <!-- Save Environment Button -->
            <div class="flex justify-end py-6">
                <Button
                    type="submit"
                    :disabled="envForm.processing || nameSaving"
                    variant="secondary"
                >
                    {{ envForm.processing || nameSaving ? 'Saving...' : 'Save Environment' }}
                </Button>
            </div>
        </form>

        <hr class="border-zinc-800" />

        <!-- Orbit Configuration Section -->
        <div class="py-6">
            <h2 class="text-lg font-medium text-white mb-6">Orbit Configuration</h2>

            <div v-if="configLoading" class="text-zinc-500 text-sm">
                <Loader2 class="w-4 h-4 inline animate-spin mr-2" />
                Loading configuration...
            </div>

            <template v-else>
                <!-- Site Paths -->
                <div class="grid grid-cols-2 gap-8 py-6">
                    <div>
                        <h3 class="text-sm font-medium text-white">Site Paths</h3>
                        <p class="text-sm text-zinc-500 mt-1">
                            Directories where your sites are located.
                        </p>
                    </div>
                    <div>
                        <div class="space-y-2">
                            <div
                                v-for="(path, index) in editPaths"
                                :key="index"
                                class="flex items-center gap-2"
                            >
                                <Input
                                    v-model="editPaths[index]"
                                    type="text"
                                    placeholder="/home/user/sites"
                                    class="flex-1 font-mono"
                                />
                                <button
                                    @click="removePath(index)"
                                    type="button"
                                    class="text-zinc-500 hover:text-red-400 p-2 transition-colors"
                                >
                                    <Trash2 class="w-4 h-4" />
                                </button>
                            </div>
                        </div>
                        <button
                            @click="addPath"
                            type="button"
                            class="mt-2 text-sm text-zinc-400 hover:text-white transition-colors flex items-center gap-1"
                        >
                            <Plus class="w-4 h-4" />
                            Add path
                        </button>
                    </div>
                </div>

                <hr class="border-zinc-800" />

                <!-- TLD -->
                <div class="grid grid-cols-2 gap-8 py-6">
                    <div>
                        <h3 class="text-sm font-medium text-white">TLD</h3>
                        <p class="text-sm text-zinc-500 mt-1">
                            Top-level domain for local sites. Sites will be accessible at
                            sitename.{{ editTld || 'test' }}
                        </p>
                    </div>
                    <div>
                        <Input
                            v-model="editTld"
                            type="text"
                            id="config-tld"
                            placeholder="test"
                            class="w-full font-mono"
                        />
                    </div>
                </div>

                <hr class="border-zinc-800" />

                <!-- Default PHP Version -->
                <div class="grid grid-cols-2 gap-8 py-6">
                    <div>
                        <h3 class="text-sm font-medium text-white">Default PHP Version</h3>
                        <p class="text-sm text-zinc-500 mt-1">PHP version used for new sites.</p>
                    </div>
                    <div>
                        <Select v-model="editPhpVersion">
                            <SelectTrigger class="w-full">
                                <SelectValue placeholder="Select PHP version" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="version in availablePhpVersions"
                                    :key="version"
                                    :value="version"
                                >
                                    PHP {{ version }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                <!-- Save Config Button -->
                <div class="flex justify-end py-6">
                    <Button
                        @click="saveConfig"
                        type="button"
                        :disabled="configSaving"
                        variant="secondary"
                    >
                        {{ configSaving ? 'Saving...' : 'Save Configuration' }}
                    </Button>
                </div>
            </template>
        </div>

        <hr class="border-zinc-800" />

        <!-- DNS Settings -->
        <div class="py-6">
            <DnsSettings :environment-id="environment.id" />
        </div>

        <hr class="border-zinc-800" />

        <!-- External Access -->
        <div class="py-6">
            <h2 class="text-lg font-medium text-white mb-6">External Access</h2>
            <div class="grid grid-cols-2 gap-8">
                <div>
                    <h3 class="text-sm font-medium text-white">Enable External Access</h3>
                    <p class="text-sm text-zinc-500 mt-1">
                        Enable SSH links for accessing this environment from external machines.
                    </p>
                </div>
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <Label for="external_access" class="text-zinc-400">
                            Enable external access
                        </Label>
                        <Switch
                            id="external_access"
                            :checked="externalAccessForm.external_access"
                            @update:checked="externalAccessForm.external_access = $event"
                        />
                    </div>

                    <div v-if="externalAccessForm.external_access" class="space-y-3">
                        <div>
                            <Label for="external_host" class="text-zinc-400 mb-1.5">
                                External Host / IP
                            </Label>
                            <Input
                                v-model="externalAccessForm.external_host"
                                type="text"
                                id="external_host"
                                placeholder="e.g. 192.168.1.100 or myserver.example.com"
                                class="w-full font-mono"
                            />
                            <p class="mt-1 text-xs text-zinc-500">
                                The hostname or IP address external users will use to connect via SSH.
                            </p>
                        </div>
                        <Button
                            type="button"
                            @click="saveExternalAccess"
                            :disabled="externalAccessForm.processing"
                            variant="outline"
                            size="sm"
                        >
                            Save External Access
                        </Button>
                    </div>
                </div>
            </div>
        </div>

        <hr class="border-zinc-800" />

        <!-- Template Favorites -->
        <div class="py-6">
            <h2 class="text-lg font-medium text-white mb-6">Template Favorites</h2>
            <div class="grid grid-cols-2 gap-8">
                <div>
                    <h3 class="text-sm font-medium text-white">Site Templates</h3>
                    <p class="text-sm text-zinc-500 mt-1">
                        Manage your favorite site templates for quick project creation.
                    </p>
                </div>
                <div class="space-y-4">
                    <!-- Empty State -->
                    <div v-if="templateFavorites.length === 0" class="text-center py-6 text-zinc-500">
                        <FileCode2 class="w-8 h-8 mx-auto mb-2 text-zinc-600" />
                        <p class="text-sm">No template favorites yet.</p>
                    </div>

                    <!-- Templates List -->
                    <div v-else class="space-y-3">
                        <div
                            v-for="template in templateFavorites"
                            :key="template.id"
                            class="flex items-center justify-between rounded-lg border border-zinc-700 bg-zinc-800/50 px-4 py-3"
                        >
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-medium text-white">{{ template.display_name }}</span>
                                    <Badge v-if="template.usage_count > 0" variant="secondary">
                                        Used {{ template.usage_count }}x
                                    </Badge>
                                </div>
                                <p class="mt-0.5 font-mono text-xs text-zinc-500">
                                    {{ template.repo_url }}
                                </p>
                            </div>
                            <div class="ml-4 flex items-center gap-1">
                                <Button
                                    type="button"
                                    @click="openGitHub(template.repo_url)"
                                    variant="ghost"
                                    size="icon-sm"
                                    title="Open on GitHub"
                                >
                                    <ExternalLink class="w-4 h-4" />
                                </Button>
                                <Button
                                    type="button"
                                    @click="openEditTemplateModal(template)"
                                    variant="ghost"
                                    size="icon-sm"
                                    title="Edit"
                                >
                                    <Pencil class="w-4 h-4" />
                                </Button>
                                <Button
                                    type="button"
                                    @click="deleteTemplate(template)"
                                    variant="ghost"
                                    size="icon-sm"
                                    class="hover:text-red-400"
                                    title="Delete"
                                >
                                    <Trash2 class="w-4 h-4" />
                                </Button>
                            </div>
                        </div>
                    </div>

                    <Button type="button" @click="openAddTemplateModal" variant="outline">
                        Add Template
                    </Button>
                </div>
            </div>
        </div>

        <hr class="border-zinc-800" />

        <!-- SSH Keys (multi_environment only) -->
        <div v-if="$page.props.multi_environment" class="py-6">
            <h2 class="text-lg font-medium text-white mb-6">SSH Public Keys</h2>
            <div class="grid grid-cols-2 gap-8">
                <div>
                    <h3 class="text-sm font-medium text-white">Manage SSH Keys</h3>
                    <p class="text-sm text-zinc-500 mt-1">
                        SSH keys for environment provisioning.
                    </p>
                </div>
                <div class="space-y-4">
                    <!-- Empty State -->
                    <div v-if="sshKeys.length === 0" class="text-center py-6 text-zinc-500">
                        <Key class="w-8 h-8 mx-auto mb-2 text-zinc-600" />
                        <p class="text-sm">No SSH keys configured.</p>
                    </div>

                    <!-- Keys List -->
                    <div v-else class="space-y-3">
                        <div
                            v-for="key in sshKeys"
                            :key="key.id"
                            class="flex items-center justify-between rounded-lg border border-zinc-700 bg-zinc-800/50 px-4 py-3"
                        >
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-medium text-white">{{ key.name }}</span>
                                    <Badge v-if="key.is_default" class="bg-lime-400/10 text-lime-300 border-lime-400/20">
                                        Default
                                    </Badge>
                                </div>
                                <p class="mt-0.5 truncate font-mono text-xs text-zinc-500">
                                    {{ truncateKey(key.public_key, 50) }}
                                </p>
                            </div>
                            <div class="ml-4 flex items-center gap-1">
                                <Button
                                    type="button"
                                    @click="copyKey(key)"
                                    variant="ghost"
                                    size="icon-sm"
                                    title="Copy"
                                >
                                    <Copy class="w-4 h-4" />
                                </Button>
                                <Button
                                    v-if="!key.is_default"
                                    type="button"
                                    @click="setDefaultKey(key)"
                                    variant="ghost"
                                    size="icon-sm"
                                    class="hover:text-lime-400"
                                    title="Set default"
                                >
                                    <Star class="w-4 h-4" />
                                </Button>
                                <Button
                                    type="button"
                                    @click="openEditKeyModal(key)"
                                    variant="ghost"
                                    size="icon-sm"
                                    title="Edit"
                                >
                                    <Pencil class="w-4 h-4" />
                                </Button>
                                <Button
                                    type="button"
                                    @click="deleteKey(key)"
                                    variant="ghost"
                                    size="icon-sm"
                                    class="hover:text-red-400"
                                    title="Delete"
                                >
                                    <Trash2 class="w-4 h-4" />
                                </Button>
                            </div>
                        </div>
                    </div>

                    <Button type="button" @click="openAddKeyModal" variant="outline">
                        Add SSH Key
                    </Button>
                </div>
            </div>
        </div>

        <hr v-if="$page.props.multi_environment" class="border-zinc-800" />

        <!-- Desktop Settings (multi_environment only) -->
        <div v-if="$page.props.multi_environment" class="py-6">
            <h2 class="text-lg font-medium text-white mb-6">Desktop Settings</h2>
            
            <!-- Notifications -->
            <div class="grid grid-cols-2 gap-8 mb-6">
                <div>
                    <h3 class="text-sm font-medium text-white">Desktop Notifications</h3>
                    <p class="text-sm text-zinc-500 mt-1">
                        Show system notifications for site events.
                    </p>
                </div>
                <div class="flex items-center">
                    <Switch
                        :checked="notificationForm.enabled"
                        @update:checked="toggleNotifications"
                        :disabled="notificationForm.processing"
                    />
                </div>
            </div>

            <!-- Menu Bar -->
            <div class="grid grid-cols-2 gap-8">
                <div>
                    <h3 class="text-sm font-medium text-white">Menu Bar Icon</h3>
                    <p class="text-sm text-zinc-500 mt-1">
                        Show Orbit in the system menu bar for quick access.
                    </p>
                </div>
                <div class="flex items-center">
                    <Switch
                        :checked="menuBarForm.enabled"
                        @update:checked="toggleMenuBar"
                        :disabled="menuBarForm.processing"
                    />
                </div>
            </div>
        </div>

        <hr v-if="$page.props.multi_environment" class="border-zinc-800" />

        <!-- Health Check / Doctor -->
        <div class="py-6">
            <div class="flex items-center justify-between mb-6">
                <div class="flex items-center gap-2">
                    <Stethoscope class="w-5 h-5 text-zinc-400" />
                    <h2 class="text-lg font-medium text-white">Health Check</h2>
                    <span
                        v-if="doctorResult"
                        :class="[
                            'text-xs px-2 py-0.5 rounded-full',
                            doctorResult.status === 'healthy'
                                ? 'bg-lime-500/10 text-lime-400'
                                : doctorResult.status === 'degraded'
                                  ? 'bg-amber-500/10 text-amber-400'
                                  : 'bg-red-500/10 text-red-400',
                        ]"
                    >
                        {{
                            doctorResult.status === 'healthy'
                                ? 'All Passed'
                                : doctorResult.status === 'degraded'
                                  ? 'Warnings'
                                  : 'Issues Found'
                        }}
                    </span>
                </div>
                <Button
                    @click="runDoctor"
                    :disabled="doctorRunning"
                    variant="outline"
                >
                    <RefreshCw v-if="doctorRunning" class="w-4 h-4 animate-spin" />
                    <Stethoscope v-else class="w-4 h-4" />
                    {{ doctorRunning ? 'Running...' : 'Run Diagnostics' }}
                </Button>
            </div>

            <!-- Error State -->
            <div v-if="doctorError" class="p-4 bg-red-900/20 border border-red-800 rounded-lg mb-4">
                <div class="flex items-center gap-2 text-red-400">
                    <XCircle class="w-5 h-5" />
                    <span>{{ doctorError }}</span>
                </div>
            </div>

            <!-- Results -->
            <div v-if="doctorResult" class="space-y-2">
                <!-- Summary -->
                <div class="flex items-center gap-4 mb-4 text-sm">
                    <span class="text-lime-400">{{ doctorResult.summary.passed }} passed</span>
                    <span v-if="doctorResult.summary.warnings > 0" class="text-amber-400">
                        {{ doctorResult.summary.warnings }} warnings
                    </span>
                    <span v-if="doctorResult.summary.errors > 0" class="text-red-400">
                        {{ doctorResult.summary.errors }} errors
                    </span>
                </div>

                <!-- Individual Checks -->
                <div
                    v-for="(check, key) in doctorResult.checks"
                    :key="key"
                    class="border border-zinc-700/50 rounded-lg overflow-hidden"
                >
                    <button
                        @click="toggleCheckExpanded(key as string)"
                        class="w-full flex items-center justify-between p-3 bg-zinc-800/30 hover:bg-zinc-700/30 transition-colors text-left"
                    >
                        <div class="flex items-center gap-3">
                            <!-- Status Icon -->
                            <CheckCircle2
                                v-if="check.status === 'ok'"
                                class="w-5 h-5 text-lime-400"
                            />
                            <AlertTriangleIcon
                                v-else-if="check.status === 'warning'"
                                class="w-5 h-5 text-amber-400"
                            />
                            <XCircle v-else class="w-5 h-5 text-red-400" />

                            <!-- Check Name & Message -->
                            <div>
                                <span class="text-white font-medium">
                                    {{ checkLabels[key as string] || key }}
                                </span>
                                <p class="text-sm text-zinc-400 mt-0.5">{{ check.message }}</p>
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            <!-- Fix Button -->
                            <Button
                                v-if="check.status === 'error' && check.details?.can_fix"
                                @click.stop="fixIssue(key as string)"
                                :disabled="fixingChecks.has(key as string)"
                                variant="secondary"
                                size="sm"
                            >
                                <Loader2
                                    v-if="fixingChecks.has(key as string)"
                                    class="w-3 h-3 animate-spin mr-1"
                                />
                                {{ fixingChecks.has(key as string) ? 'Fixing...' : 'Fix' }}
                            </Button>

                            <!-- Expand Arrow -->
                            <ChevronDown
                                v-if="check.details && Object.keys(check.details).length > 0"
                                :class="[
                                    'w-4 h-4 text-zinc-500 transition-transform',
                                    expandedChecks.has(key as string) ? 'rotate-180' : '',
                                ]"
                            />
                        </div>
                    </button>

                    <!-- Details (expanded) -->
                    <div
                        v-if="
                            expandedChecks.has(key as string) &&
                            check.details &&
                            Object.keys(check.details).filter((k) => k !== 'can_fix').length > 0
                        "
                        class="px-4 py-3 bg-zinc-900/50 border-t border-zinc-700/50"
                    >
                        <dl class="space-y-2 text-sm">
                            <div
                                v-for="(value, detailKey) in check.details"
                                :key="detailKey"
                                v-show="detailKey !== 'can_fix'"
                                class="flex"
                            >
                                <dt class="text-zinc-500 w-32 flex-shrink-0">{{ detailKey }}</dt>
                                <dd class="text-zinc-300 font-mono break-all">
                                    <template v-if="Array.isArray(value)">
                                        <span v-for="(item, i) in value" :key="i" class="block">{{
                                            item
                                        }}</span>
                                    </template>
                                    <template
                                        v-else-if="typeof value === 'object' && value !== null"
                                    >
                                        <pre class="text-xs">{{
                                            JSON.stringify(value, null, 2)
                                        }}</pre>
                                    </template>
                                    <template v-else>{{ value }}</template>
                                </dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </div>

            <!-- Empty State -->
            <div v-else-if="!doctorRunning && !doctorError" class="text-center py-8 text-zinc-500">
                <Stethoscope class="w-12 h-12 mx-auto mb-3 opacity-50" />
                <p>Run diagnostics to check the health of this environment</p>
            </div>
        </div>

        <hr class="border-zinc-800" />

        <!-- Danger Zone -->
        <div v-if="$page.props.multi_environment" class="py-6">
            <h2 class="text-lg font-medium text-red-400 mb-6">Danger Zone</h2>

            <div class="grid grid-cols-2 gap-8">
                <div>
                    <h3 class="text-sm font-medium text-white">Delete Environment</h3>
                    <p class="text-sm text-zinc-500 mt-1">
                        Permanently delete this environment. This action cannot be undone.
                    </p>
                </div>
                <div>
                    <div v-if="!showDeleteConfirm">
                        <Button
                            @click="confirmDelete"
                            type="button"
                            variant="destructive"
                        >
                            Delete Environment
                        </Button>
                    </div>

                    <div v-else class="space-y-4">
                        <div class="flex items-start gap-3 p-3 bg-red-900/20 rounded-lg">
                            <AlertTriangle class="w-5 h-5 text-red-400 flex-shrink-0 mt-0.5" />
                            <div>
                                <p class="text-sm text-red-300 font-medium">Are you sure?</p>
                                <p class="text-sm text-red-400/80">
                                    Type
                                    <strong class="text-red-300">{{ environment.name }}</strong> to
                                    confirm.
                                </p>
                            </div>
                        </div>
                        <Input
                            v-model="deleteConfirmName"
                            type="text"
                            placeholder="Type environment name"
                            class="w-full"
                        />
                        <div class="flex gap-3">
                            <Button
                                @click="deleteEnvironment"
                                type="button"
                                :disabled="deleteConfirmName !== environment.name"
                                variant="destructive"
                            >
                                Delete
                            </Button>
                            <Button @click="cancelDelete" type="button" variant="ghost">
                                Cancel
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add/Edit SSH Key Modal -->
    <Modal
        :show="showKeyModal"
        :title="editingKey ? 'Edit SSH Key' : 'Add SSH Key'"
        @close="closeKeyModal"
    >
        <form @submit.prevent="saveKey">
            <div class="p-6 space-y-4">
                <div>
                    <Label for="keyName" class="text-zinc-400 mb-1.5">Name</Label>
                    <Input
                        v-model="keyForm.name"
                        type="text"
                        id="keyName"
                        required
                        placeholder="e.g., MacBook Pro"
                        class="w-full"
                    />
                </div>

                <div v-if="Object.keys(availableSshKeys).length > 0 && !editingKey">
                    <Label class="text-zinc-400 mb-1.5">Import from ~/.ssh/</Label>
                    <select @change="importKey" class="w-full">
                        <option value="">Select a key to import...</option>
                        <option
                            v-for="(keyInfo, filename) in availableSshKeys"
                            :key="filename"
                            :value="keyInfo.content"
                            :data-name="String(filename).replace('.pub', '')"
                        >
                            {{ filename }} ({{ keyInfo.type }})
                        </option>
                    </select>
                </div>

                <div>
                    <Label for="keyPublicKey" class="text-zinc-400 mb-1.5">Public Key</Label>
                    <Textarea
                        v-model="keyForm.public_key"
                        id="keyPublicKey"
                        rows="4"
                        required
                        placeholder="ssh-ed25519 AAAA..."
                        class="w-full font-mono text-sm"
                    />
                </div>
            </div>

            <div class="flex justify-end gap-4 px-6 py-4 border-t border-zinc-800">
                <Button type="button" @click="closeKeyModal" variant="ghost">Cancel</Button>
                <Button type="submit" :disabled="keyForm.processing" variant="secondary">
                    Save
                </Button>
            </div>
        </form>
    </Modal>

    <!-- Add/Edit Template Modal -->
    <Modal
        :show="showTemplateModal"
        :title="editingTemplate ? 'Edit Template' : 'Add Template'"
        @close="closeTemplateModal"
    >
        <form @submit.prevent="saveTemplate">
            <div class="p-6 space-y-4">
                <div v-if="!editingTemplate">
                    <Label for="templateRepoUrl" class="text-zinc-400 mb-1.5">Repository URL</Label>
                    <Input
                        v-model="templateForm.repo_url"
                        @blur="onRepoUrlChange"
                        type="text"
                        id="templateRepoUrl"
                        required
                        placeholder="owner/repo or https://github.com/owner/repo"
                        class="w-full"
                    />
                </div>
                <div v-else class="text-sm text-zinc-400">
                    <span class="font-medium">Repository:</span> {{ editingTemplate.repo_url }}
                </div>

                <div>
                    <Label for="templateDisplayName" class="text-zinc-400 mb-1.5">Display Name</Label>
                    <Input
                        v-model="templateForm.display_name"
                        type="text"
                        id="templateDisplayName"
                        required
                        placeholder="e.g., Laravel, Next.js Starter"
                        class="w-full"
                    />
                </div>
            </div>

            <div class="flex justify-end gap-4 px-6 py-4 border-t border-zinc-800">
                <Button type="button" @click="closeTemplateModal" variant="ghost">
                    Cancel
                </Button>
                <Button type="submit" :disabled="templateForm.processing" variant="secondary">
                    {{ editingTemplate ? 'Update' : 'Add' }}
                </Button>
            </div>
        </form>
    </Modal>
</template>
