import { onScopeDispose, watch, type Ref, type WatchSource } from 'vue';

/** Batch filter resets into one visit, and cancel any pending search visit. */
export function useFilterReload(search: Ref<string>, filters: WatchSource[], reload: () => void) {
    let timer: ReturnType<typeof setTimeout> | undefined;

    watch([search, ...filters], (values, previous) => {
        clearTimeout(timer);
        const changedFilter = values.slice(1).some((value, index) => value !== previous[index + 1]);
        if (changedFilter || !values[0]) {
            reload();
        } else {
            timer = setTimeout(reload, 300);
        }
    });

    onScopeDispose(() => clearTimeout(timer));
}
