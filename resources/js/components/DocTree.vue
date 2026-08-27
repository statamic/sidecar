<template>
    <div>
        <div v-if="!loading && treeData.length == 0" class="no-results flex w-full items-center">
            <slot name="empty" />
        </div>

        <Panel v-show="treeData.length">
            <div class="loading card" v-if="loading">
                <Icon name="loading" />
            </div>

            <PanelHeader>
                <div class="page-tree-header font-medium text-sm items-center flex justify-between">
                    <div v-text="__('Tree Structure')" />
                    <div class="flex gap-2">
                        <Button size="sm" icon="tree-collapse" :text="__('Collapse')" @click="collapseAll" />
                        <Button size="sm" icon="tree-expand" :text="__('Expand')" @click="expandAll" />
                    </div>
                </div>
            </PanelHeader>
            <div v-if="!loading" class="page-tree" :class="{ 'page-tree--ready': ready }">
                <Draggable
                    ref="tree"
                    v-model="treeData"
                    :disable-drag="!editable"
                    :space="1"
                    :indent="24"
                    :dir="direction"
                    :node-key="(stat) => stat.data.id"
                    :dragOverThrottleInterval="30"
                    :each-droppable="eachDroppable"
                    :max-level="maxDepth"
                    :stat-handler="statHandler"
                    :aria-label="__('Tree Structure')"
                    @after-drop="afterDrop"
                    @open:node="nodeOpened"
                    @close:node="nodeClosed"
                >
                    <template #placeholder>
                        <div class="w-full rounded-sm border border-dashed border-blue-400 bg-blue-500/10 p-2">&nbsp;</div>
                    </template>

                    <template #default="{ node, stat }">
                        <DocBranch
                            :page="node"
                            :stat="stat"
                            :depth="stat.level"
                            :is-open="stat.open"
                            :has-children="stat.children.length > 0"
                            :show-slugs="showSlugs"
                            :editable="editable"
                            :can-create="canCreate"
                            :root="isRoot(stat)"
                            @toggle-open="stat.open = !stat.open"
                            @delete="confirmDelete"
                            @edit="$emit('edit-page', node, $event)"
                            @create-child="$emit('create-child', $event)"
                            @create-section="$emit('create-section', $event)"
                            class="mb-px"
                        />
                    </template>
                </Draggable>
            </div>
        </Panel>

        <ConfirmationModal
            :open="discardingChanges"
            :title="__('Discard Changes')"
            :body-text="__('Are you sure?')"
            :button-text="__('Discard Changes')"
            :danger="true"
            @confirm="confirmDiscard"
            @cancel="discardingChanges = false"
        />

        <ConfirmationModal
            :open="deleting !== null"
            :title="__('Delete')"
            :body-text="__('Are you sure you want to delete this document? The file will be deleted from disk.')"
            :button-text="__('Delete')"
            :danger="true"
            @confirm="deletePage"
            @cancel="deleting = null"
        />
    </div>
</template>

<script>
import { dragContext, Draggable, walkTreeData } from '@he-tree/vue';
import DocBranch from './DocBranch.vue';
import { Button, ConfirmationModal, Icon, Panel, PanelHeader } from '@statamic/cms/ui';
import { clone } from '@statamic/cms';

export default {
    components: {
        Draggable,
        DocBranch,
        Button,
        ConfirmationModal,
        Panel,
        PanelHeader,
        Icon,
    },

    props: {
        pagesUrl: { type: String, required: true },
        submitUrl: { type: String },
        expectsRoot: { type: Boolean, required: true },
        maxDepth: { type: Number, default: Infinity },
        showSlugs: { type: Boolean, default: false },
        preferencesPrefix: { type: String },
        editable: { type: Boolean, default: true },
        canCreate: { type: Boolean, default: false },
    },

    emits: ['loaded', 'changed', 'saved', 'canceled', 'deleted', 'edit-page', 'create-child', 'create-section'],

    data() {
        return {
            loading: false,
            saving: false,
            pages: [],
            treeData: [],
            collapsedState: [],
            discardingChanges: false,
            deleting: null,
            ready: false,
            saveKeyBinding: null,
            initialPages: [],
        };
    },

    computed: {
        preferencesKey() {
            return this.preferencesPrefix ? `${this.preferencesPrefix}.pagetree` : null;
        },

        direction() {
            return this.$config.get('direction', 'ltr');
        },
    },

    watch: {
        collapsedState: {
            deep: true,
            handler(state) {
                if (this.preferencesKey) {
                    localStorage.setItem(this.preferencesKey, JSON.stringify(state));
                }
            },
        },
    },

    created() {
        this.collapsedState = this.getCollapsedState();

        this.getPages().then(() => {
            this.initialPages = clone(this.pages);
        });

        if (this.editable) {
            this.saveKeyBinding = this.$keys.bindGlobal(['mod+s'], (e) => {
                e.preventDefault();
                this.save();
            });
        }
    },

    mounted() {
        setTimeout(() => (this.ready = true), 500);
    },

    beforeUnmount() {
        this.saveKeyBinding?.destroy();
    },

    methods: {
        isRoot(stat) {
            if (!this.expectsRoot) {
                return false;
            }

            return stat.level === 1 && stat.data.id === this.treeData[0]?.id;
        },

        getPages() {
            this.loading = true;

            return this.$axios.get(this.pagesUrl).then((response) => {
                this.pages = response.data.pages;
                this.updateTreeData();
                this.loading = false;
                this.$emit('loaded', this.pages);
            });
        },

        treeUpdated() {
            this.pages = this.$refs.tree.getData();
            this.$emit('changed', this.pages);
        },

        afterDrop() {
            const root = this.$refs.tree.getData()[0];

            // Prevent items with children being moved to the root position
            if (this.expectsRoot && root.id !== this.pages[0].id && root.children?.length > 0) {
                const { dragNode, parent, indexBeforeDrop } = dragContext.startInfo;
                this.$refs.tree.move(dragNode, parent, indexBeforeDrop);
                return;
            }

            this.treeUpdated();
        },

        cleanPagesForSubmission(pages) {
            return pages.map((page) => ({
                id: page.id,
                slug: page.slug,
                children: this.cleanPagesForSubmission(page.children || []),
            }));
        },

        save() {
            if (!this.editable) {
                return;
            }

            this.saving = true;

            const payload = {
                pages: this.cleanPagesForSubmission(this.pages),
                expectsRoot: this.expectsRoot,
            };

            return this.$axios
                .patch(this.submitUrl, payload)
                .then((response) => {
                    if (!response.data.saved) {
                        return this.$toast.error(__("Couldn't save tree"));
                    }

                    this.$emit('saved', response);
                    this.$toast.success(__('Saved'));
                    return this.getPages().then(() => {
                        this.initialPages = clone(this.pages);
                        return response;
                    });
                })
                .catch((e) => {
                    let message = e.response ? e.response.data.message : __('Something went wrong');

                    if (e.response && e.response.status === 422) {
                        const { errors } = e.response.data;
                        message = errors[Object.keys(errors)[0]][0];
                    }

                    this.$toast.error(message);
                    return Promise.reject(e);
                })
                .finally(() => (this.saving = false));
        },

        updateTreeData() {
            this.treeData = [...this.pages];
        },

        confirmDelete(page) {
            this.deleting = page;
        },

        deletePage() {
            const page = this.deleting;
            this.deleting = null;

            this.$axios
                .delete(page.delete_url)
                .then(() => {
                    this.$toast.success(__('Deleted'));
                    this.getPages().then(() => {
                        this.initialPages = clone(this.pages);
                    });
                    this.$emit('deleted', page);
                })
                .catch(() => this.$toast.error(__('Something went wrong')));
        },

        cancel() {
            this.discardingChanges = true;
        },

        confirmDiscard() {
            this.pages = this.initialPages;
            this.updateTreeData();
            this.$emit('canceled');
            this.discardingChanges = false;
        },

        eachDroppable(targetStat) {
            if (!this.expectsRoot) {
                return true;
            }

            return !this.isRoot(targetStat);
        },

        expandAll() {
            this.$refs.tree.openAll();
            this.collapsedState = [];
        },

        collapseAll() {
            this.$refs.tree.closeAll();
            this.collapsedState = [];
            walkTreeData(this.treeData, (node) => {
                this.collapsedState.push(node.id);
            });
        },

        getCollapsedState() {
            if (!this.preferencesKey) return [];

            return JSON.parse(localStorage.getItem(this.preferencesKey) || '[]');
        },

        nodeOpened(node) {
            this.collapsedState.splice(this.collapsedState.indexOf(node.data.id), 1);
        },

        nodeClosed(node) {
            this.collapsedState.push(node.data.id);
        },

        statHandler(stat) {
            stat.open = !this.collapsedState.includes(stat.data.id);
            return stat;
        },
    },
};
</script>
