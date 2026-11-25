import { ref } from 'vue';

const mobileComposerOpen = ref(false);
const mobileComposerNonce = ref(0);

export function useMobileComposer() {
    function openMobileComposer(): void {
        mobileComposerOpen.value = true;
        mobileComposerNonce.value += 1;
    }

    function closeMobileComposer(): void {
        mobileComposerOpen.value = false;
    }

    return {
        mobileComposerOpen,
        mobileComposerNonce,
        openMobileComposer,
        closeMobileComposer,
    };
}
