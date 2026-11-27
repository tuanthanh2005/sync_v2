<script setup lang="ts">
import type { DialogRootEmits, DialogRootProps } from "reka-ui"
import { DialogRoot, useForwardPropsEmits } from "reka-ui"
import { useModalRootLock } from "@/composables/useModalRuntimeLock"

const props = defineProps<DialogRootProps>()
const emits = defineEmits<DialogRootEmits>()

const forwarded = useForwardPropsEmits(props, emits)
const { handleUpdateOpen } = useModalRootLock(() => props.open)
</script>

<template>
  <DialogRoot
    v-slot="slotProps"
    data-slot="sheet"
    v-bind="forwarded"
    @update:open="handleUpdateOpen"
  >
    <slot v-bind="slotProps" />
  </DialogRoot>
</template>
