<script setup lang="ts">
import type { CheckboxRootProps } from 'radix-vue'
import { cn } from '@/lib/utils'
import { Check } from 'lucide-vue-next'
import { CheckboxIndicator, CheckboxRoot, useForwardPropsEmits } from 'radix-vue'
import { computed, type HTMLAttributes } from 'vue'

const props = defineProps<CheckboxRootProps & {
  class?: HTMLAttributes['class']
  modelValue?: boolean | 'indeterminate'
}>()

const emits = defineEmits<{
  'update:checked': [value: boolean]
  'update:modelValue': [value: boolean]
}>()

const isChecked = computed(() => {
  if (props.checked !== undefined) return props.checked
  if (props.modelValue !== undefined) return props.modelValue
  return undefined
})

const delegatedProps = computed(() => {
  const { class: _, modelValue: __, checked: ___, ...delegated } = props

  return delegated
})

const forwarded = useForwardPropsEmits(delegatedProps, emits)

function onUpdateChecked(value: boolean) {
  emits('update:checked', value)
  emits('update:modelValue', value)
}
</script>

<template>
  <CheckboxRoot
    v-bind="forwarded"
    :checked="isChecked"
    @update:checked="onUpdateChecked"
    :class="
      cn('peer size-5 shrink-0 rounded-sm border border-input ring-offset-background focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 data-[state=checked]:bg-primary data-[state=checked]:text-primary-foreground data-[state=checked]:border-accent-foreground',
         props.class)"
  >
    <CheckboxIndicator class="flex h-full w-full items-center justify-center text-current">
      <slot>
        <Check class="size-3.5 stroke-[3]" />
      </slot>
    </CheckboxIndicator>
  </CheckboxRoot>
</template>
