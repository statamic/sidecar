<template>
    <div>
        <Header :title="currentTitle">
            <Badge v-if="readOnly" icon="padlock-locked" :text="__('Read Only')" />

            <Button
                v-if="!readOnly"
                :disabled="saving"
                variant="primary"
                @click.prevent="save"
                :text="__('Save')"
            />
        </Header>

        <PublishContainer
            ref="container"
            name="base"
            :blueprint="blueprint"
            v-model="currentValues"
            :meta="currentMeta"
            :errors="errors"
            :read-only="readOnly"
        >
            <LivePreview
                :enabled="isPreviewing"
                :url="livePreviewUrl"
                :targets="previewTargets"
                @opened="openLivePreview"
                @closed="closeLivePreview"
            >
                <PublishComponents />

                <PublishTabs>
                    <template #actions>
                        <div v-if="showLivePreviewButton || currentPermalink" class="space-y-6">
                            <div class="flex flex-wrap gap-3 lg:gap-4">
                                <Button
                                    v-if="showLivePreviewButton"
                                    :text="__('Live Preview')"
                                    class="flex-1"
                                    icon="live-preview"
                                    @click="openLivePreview"
                                />
                                <Button
                                    v-if="currentPermalink"
                                    :href="currentPermalink"
                                    :text="__('Visit URL')"
                                    class="flex-1"
                                    icon="external-link"
                                    target="_blank"
                                />
                            </div>
                        </div>
                    </template>
                </PublishTabs>

                <template #buttons>
                    <Button
                        v-if="!readOnly"
                        size="sm"
                        variant="primary"
                        :disabled="saving"
                        @click.prevent="save"
                        :text="__('Save')"
                    />
                </template>
            </LivePreview>
        </PublishContainer>
    </div>
</template>

<script>
import { computed, ref } from 'vue';
import {
    Badge,
    Button,
    Header,
    LivePreview,
    PublishComponents,
    PublishContainer,
    PublishTabs,
} from '@statamic/cms/ui';
import { clone } from '@statamic/cms';
import { Pipeline, Request, PipelineStopped } from '@statamic/cms/save-pipeline';
import { router } from '@statamic/cms/inertia';

export default {
    components: {
        Badge,
        Button,
        Header,
        LivePreview,
        PublishComponents,
        PublishContainer,
        PublishTabs,
    },

    props: {
        title: String,
        blueprint: Object,
        values: Object,
        meta: Object,
        submitUrl: String,
        submitMethod: { type: String, default: 'patch' },
        readOnly: { type: Boolean, default: false },
        isCreating: { type: Boolean, default: false },
        livePreviewUrl: String,
        previewTargets: { type: Array, default: () => [] },
        permalink: String,
        listingUrl: String,
    },

    setup() {
        const savingRef = ref(false);
        const errorsRef = ref({});

        return {
            savingRef: computed(() => savingRef),
            errorsRef: computed(() => errorsRef),
        };
    },

    data() {
        return {
            currentTitle: this.title,
            currentValues: clone(this.values),
            currentMeta: clone(this.meta),
            currentPermalink: this.permalink,
            isPreviewing: false,
            saveKeyBinding: null,
            quickSaveKeyBinding: null,
        };
    },

    computed: {
        containerRef() {
            return computed(() => this.$refs.container);
        },

        saving() {
            return this.savingRef.value;
        },

        errors() {
            return this.errorsRef.value;
        },

        showLivePreviewButton() {
            return !this.isPreviewing && !this.readOnly && this.livePreviewUrl && this.previewTargets.length > 0;
        },
    },

    mounted() {
        this.saveKeyBinding = this.$keys.bindGlobal(['mod+return'], (e) => {
            e.preventDefault();
            this.save();
        });

        this.quickSaveKeyBinding = this.$keys.bindGlobal(['mod+s'], (e) => {
            e.preventDefault();
            this.save();
        });
    },

    beforeUnmount() {
        this.saveKeyBinding?.destroy();
        this.quickSaveKeyBinding?.destroy();
    },

    methods: {
        save() {
            if (this.readOnly || this.saving) {
                return;
            }

            new Pipeline()
                .provide({
                    container: this.containerRef,
                    errors: this.errorsRef,
                    saving: this.savingRef,
                })
                .through([new Request(this.submitUrl, this.submitMethod)])
                .then((response) => {
                    const data = response.data.data ?? {};

                    if (data.redirect) {
                        router.visit(data.redirect);
                        return;
                    }

                    this.$toast.success(__('Saved'));

                    if (data.title) {
                        this.currentTitle = data.title;
                    }

                    if (data.permalink !== undefined) {
                        this.currentPermalink = data.permalink;
                    }

                    // A slug rename changes the document's path-based URL.
                    if (data.editUrl && !this.isCurrentUrl(data.editUrl)) {
                        router.visit(data.editUrl);
                    }
                })
                .catch((e) => {
                    if (!(e instanceof PipelineStopped)) {
                        this.$toast.error(__('Something went wrong'));
                        console.error(e);
                    }
                });
        },

        isCurrentUrl(url) {
            const current = window.location.origin + window.location.pathname;

            return decodeURI(url) === decodeURI(current);
        },

        openLivePreview() {
            this.isPreviewing = true;
        },

        closeLivePreview() {
            this.isPreviewing = false;
        },
    },
};
</script>
