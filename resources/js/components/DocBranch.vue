<template>
    <div class="page-tree-branch flex" :class="{ 'page-tree-branch--has-children': hasChildren }">
        <div v-if="editable" class="page-move w-6" />
        <div class="flex flex-1 items-center px-1.5 text-xs leading-normal">
            <div class="flex gap-2 sm:gap-3 grow items-center py-3">
                <Icon v-if="isRoot" name="home" class="size-4" v-tooltip="__('This is the root page')" />

                <a
                    v-if="page.edit_url"
                    @click.prevent="$emit('edit', $event)"
                    :class="{
                        'text-sm font-medium is-top-level-branch': isTopLevelBranch,
                        'opacity-60': page.hidden,
                    }"
                    :href="page.edit_url"
                    v-text="title"
                />
                <span
                    v-else
                    :class="{ 'text-sm font-medium is-top-level-branch': isTopLevelBranch }"
                    class="text-gray-500"
                    v-text="title"
                />

                <span v-if="showSlugs" class="pt-[2px] font-mono text-2xs text-gray-700 dark:text-gray-500">
                    {{ slugPath }}
                </span>

                <Badge v-if="page.group" size="sm" :text="page.group" />
                <Badge v-if="page.badge" size="sm" :text="page.badge" />

                <Icon
                    v-if="page.hidden"
                    name="eye-slash"
                    class="size-3.5 text-gray-500"
                    v-tooltip="__('Hidden from nav')"
                />
                <Icon
                    v-if="page.redirect"
                    name="external-link"
                    class="size-3.5 text-gray-500"
                    v-tooltip="__('Redirect')"
                />

                <Button
                    v-if="hasChildren"
                    class="transition duration-100 [&_svg]:size-4! -mx-1.5"
                    icon="chevron-down"
                    size="xs"
                    round
                    variant="ghost"
                    :class="{ '-rotate-90 is-closed': !isOpen, 'is-open': isOpen }"
                    :aria-label="isOpen ? __('Collapse') : __('Expand')"
                    :aria-expanded="isOpen"
                    @click.stop="$emit('toggle-open')"
                />
            </div>

            <div class="flex items-center gap-2 sm:gap-3">
                <template v-if="page.edit_url || canCreate">
                    <Dropdown placement="left-start" :class="{ invisible: isRoot && !canCreate }">
                        <DropdownMenu>
                            <DropdownItem v-if="page.edit_url" :text="__('Edit')" icon="edit" :href="page.edit_url" />
                            <DropdownItem
                                v-if="canCreate && !isRoot"
                                :text="__('Create Child')"
                                icon="add-entry"
                                @click="$emit('create-child', page)"
                            />
                            <DropdownItem
                                v-if="canCreate && !isRoot"
                                :text="__('Create Section')"
                                icon="folder-add"
                                @click="$emit('create-section', page)"
                            />
                            <DropdownItem
                                v-if="page.delete_url && !isRoot && editable"
                                :text="__('Delete')"
                                icon="trash"
                                variant="destructive"
                                @click="$emit('delete', page)"
                            />
                        </DropdownMenu>
                    </Dropdown>
                </template>
            </div>
        </div>
    </div>
</template>

<script>
import { Badge, Button, Dropdown, DropdownItem, DropdownMenu, Icon } from '@statamic/cms/ui';

export default {
    components: { Badge, Button, Dropdown, DropdownItem, DropdownMenu, Icon },

    props: {
        page: Object,
        depth: Number,
        root: Boolean,
        isOpen: Boolean,
        hasChildren: Boolean,
        showSlugs: Boolean,
        editable: { type: Boolean, default: true },
        canCreate: { type: Boolean, default: false },
        stat: Object,
    },

    emits: ['toggle-open', 'delete', 'edit', 'create-child', 'create-section'],

    computed: {
        isTopLevelBranch() {
            return this.depth === 1;
        },

        isRoot() {
            return this.root;
        },

        title() {
            return this.page.title || this.page.slug;
        },

        slugPath() {
            return this.isRoot ? '/' : '/' + this.page.slug;
        },
    },
};
</script>
