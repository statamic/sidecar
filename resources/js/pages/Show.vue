<template>
    <div>
        <Header :title="title">
            <Badge v-if="readOnly" icon="padlock-locked" :text="__('Read Only')" />

            <Dropdown>
                <template #trigger>
                    <Button icon="dots" variant="ghost" :aria-label="__('Open dropdown menu')" />
                </template>
                <DropdownMenu>
                    <DropdownItem :text="__('Edit Blueprint')" icon="blueprint-edit" :href="editBlueprintUrl" />
                </DropdownMenu>
            </Dropdown>

            <template v-if="view === 'tree'">
                <Button
                    v-if="treeIsDirty"
                    :text="__('Discard Changes')"
                    @click="cancelTreeProgress"
                />

                <Button
                    v-if="treeIsDirty"
                    :text="__('Save Changes')"
                    variant="primary"
                    @click="saveTree"
                />
            </template>

            <ToggleGroup v-if="structured" v-model="view">
                <ToggleItem icon="navigation" value="tree" />
                <ToggleItem icon="layout-list" value="list" />
            </ToggleGroup>

            <Button
                v-if="!readOnly && (!treeIsDirty || view === 'list')"
                :href="createUrl"
                :variant="treeIsDirty ? 'default' : 'primary'"
                :text="__('Create Document')"
            />
        </Header>

        <DocTree
            v-if="structured && view === 'tree'"
            ref="tree"
            :pages-url="treeIndexUrl"
            :submit-url="treeSubmitUrl"
            :expects-root="expectsRoot"
            :editable="!readOnly && supportsOrdering"
            :can-create="!readOnly && supportsNesting"
            :preferences-prefix="`sidecar.${handle}`"
            @changed="markTreeDirty"
            @saved="markTreeClean"
            @deleted="refreshRows"
            @edit-page="editPage"
            @create-child="createChild"
            @create-section="createSection"
        >
            <template #empty>
                <p v-text="__('No documents were found in this directory.')" />
            </template>
        </DocTree>

        <Listing
            v-if="view === 'list'"
            :items="rows"
            :columns="columns"
            :allow-search="true"
            :allow-customizing-columns="false"
        >
            <template #cell-title="{ row }">
                <Link :href="row.edit_url">{{ row.title }}</Link>
            </template>
            <template #cell-path="{ row }">
                <span class="font-mono text-2xs text-gray-700 dark:text-gray-500">{{ row.path }}</span>
            </template>
            <template #cell-hidden="{ row }">
                <span v-if="row.hidden" v-text="__('Yes')" />
            </template>
            <template #prepended-row-actions="{ row }">
                <DropdownItem :text="__('Edit')" icon="edit" :href="row.edit_url" />
                <DropdownItem
                    v-if="!readOnly"
                    :text="__('Delete')"
                    icon="trash"
                    variant="destructive"
                    @click="deleting = row"
                />
            </template>
        </Listing>

        <ConfirmationModal
            :open="deleting !== null"
            :title="__('Delete')"
            :body-text="__('Are you sure you want to delete this document? The file will be deleted from disk.')"
            :button-text="__('Delete')"
            :danger="true"
            @confirm="deleteDocument"
            @cancel="deleting = null"
        />
    </div>
</template>

<script>
import {
    Badge,
    Button,
    ConfirmationModal,
    Dropdown,
    DropdownItem,
    DropdownMenu,
    Header,
    Listing,
    ToggleGroup,
    ToggleItem,
} from '@statamic/cms/ui';
import { Link, router } from '@statamic/cms/inertia';
import DocTree from '../components/DocTree.vue';

export default {
    components: {
        Badge,
        Button,
        ConfirmationModal,
        DocTree,
        Dropdown,
        DropdownItem,
        DropdownMenu,
        Header,
        Link,
        Listing,
        ToggleGroup,
        ToggleItem,
    },

    props: {
        handle: String,
        title: String,
        readOnly: Boolean,
        structured: Boolean,
        expectsRoot: Boolean,
        supportsNesting: Boolean,
        supportsOrdering: Boolean,
        treeIndexUrl: String,
        treeSubmitUrl: String,
        createUrl: String,
        editBlueprintUrl: String,
        columns: Array,
        rows: Array,
    },

    data() {
        return {
            view: null,
            deleting: null,
        };
    },

    computed: {
        treeIsDirty() {
            return this.$dirty.has('page-tree');
        },
    },

    watch: {
        view(view) {
            this.$preferences.set(`sidecar.${this.handle}.view`, view);
        },
    },

    mounted() {
        this.view = this.initialView();
    },

    methods: {
        initialView() {
            const savedView = this.$preferences.get(`sidecar.${this.handle}.view`);

            if (savedView === 'tree' && this.structured) {
                return 'tree';
            }

            if (savedView === 'list') {
                return 'list';
            }

            return this.structured ? 'tree' : 'list';
        },

        cancelTreeProgress() {
            this.$refs.tree.cancel();
        },

        saveTree() {
            this.$refs.tree.save();
        },

        markTreeDirty() {
            this.$dirty.add('page-tree');
        },

        markTreeClean() {
            this.$dirty.remove('page-tree');
        },

        editPage(page, $event) {
            const url = page.edit_url;

            $event.metaKey ? window.open(url) : router.get(url);
        },

        parentFolder(page) {
            let id = page.id ?? '';

            if (id.startsWith('_folder::')) {
                return id.slice('_folder::'.length);
            }

            if (id.endsWith('/_index')) {
                return id.slice(0, -'/_index'.length);
            }

            if (id === '_index') {
                return '';
            }

            return id;
        },

        createUrlWith(params) {
            const url = new URL(this.createUrl, window.location.origin);

            Object.entries(params).forEach(([key, value]) => {
                if (value !== '' && value !== null && value !== undefined && value !== false) {
                    url.searchParams.set(key, value);
                }
            });

            return url.pathname + url.search;
        },

        createChild(page) {
            router.get(this.createUrlWith({ parent: this.parentFolder(page) }));
        },

        createSection(page) {
            router.get(this.createUrlWith({ parent: this.parentFolder(page), section: 1 }));
        },

        refreshRows() {
            router.reload({ only: ['rows'] });
        },

        deleteDocument() {
            const row = this.deleting;
            this.deleting = null;

            this.$axios
                .delete(row.delete_url)
                .then(() => {
                    this.$toast.success(__('Deleted'));
                    this.refreshRows();

                    if (this.$refs.tree) {
                        this.$refs.tree.getPages();
                    }
                })
                .catch(() => this.$toast.error(__('Something went wrong')));
        },
    },
};
</script>
