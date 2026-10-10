<script setup>
import { Form } from '@inertiajs/vue3';
import { Power } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';

defineProps({
    name: { type: String, required: true },
    form: { type: Object, required: true },
    description: { type: String, required: true },
});

const open = ref(false);
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button variant="destructive-outline" size="sm">
                <Power />
                Deactivate
            </Button>
        </DialogTrigger>
        <DialogContent>
            <Form
                v-bind="form"
                :options="{ preserveScroll: true }"
                @success="open = false"
                v-slot="{ processing }"
            >
                <input type="hidden" name="is_active" value="0" />
                <DialogHeader>
                    <DialogTitle>Deactivate {{ name }}?</DialogTitle>
                    <DialogDescription>{{ description }}</DialogDescription>
                </DialogHeader>
                <DialogFooter class="mt-6 gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button
                        type="submit"
                        variant="destructive-solid"
                        :disabled="processing"
                    >
                        <Spinner v-if="processing" />
                        <Power v-else />
                        Deactivate
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
