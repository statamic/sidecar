import Show from './pages/Show.vue';
import Edit from './pages/Edit.vue';

Statamic.booting(() => {
    Statamic.$inertia.register('sidecar/Show', Show);
    Statamic.$inertia.register('sidecar/Edit', Edit);
});
