import type { Directive } from 'vue';
import type { Auth } from '@/types/auth';
import type {
    AdminPendingCounts,
    FooterSharedData,
    MonetizationQuickShared,
    PublicLanguageItem,
    SidebarHighlights,
    SystemSettingsPublic,
} from '@/types/ui';

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            appUrl: string;
            canonicalOrigin?: string;
            locale: string;
            isRtl: boolean;
            languages: PublicLanguageItem[];
            translationOverrides: Record<string, string>;
            supportedLocales: string[];
            systemSettings: SystemSettingsPublic;
            footer: FooterSharedData;
            sidebarHighlights: SidebarHighlights;
            monetizationQuick: MonetizationQuickShared | null;
            unreadNotificationsCount: number;
            unreadMessagesCount: number;
            walletBalance: number;
            adminPendingCounts: AdminPendingCounts | null;
            auth: Auth;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}

declare module 'vue' {
    interface GlobalDirectives {
        vFocus: Directive<HTMLElement, boolean | undefined>;
    }

    interface ComponentCustomProperties {
        $inertia: typeof Router;
        $page: Page;
        $headManager: ReturnType<typeof createHeadManager>;
    }
}
