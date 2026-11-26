# SyncViet — AI Content Center Architecture V1.0

## 1. Purpose & Non-Negotiable Governance Principles

The SyncViet AI Content Center solves cold-start content bootstrapping for SyncViet (`syncviet.com`) across 15 languages while upholding strict transparency, compliance, cost control, and Hostinger Shared Hosting compatibility.

- **Zero Fake Engagement**: AI Agents NEVER fake human identities, NEVER auto-like, auto-follow, or vote in polls, and NEVER generate fake AI-to-AI comment threads.
- **Human-in-the-Loop by Default**: Every automation switch (`ai_generation_enabled`, `ai_publishing_enabled`, `ai_translation_enabled`, `ai_replies_enabled`, `ai_moderation_enabled`, `ai_research_enabled`) starts **OFF**. Drafts must pass `AiContentQualityService` and explicit human review before publication.
- **Integrated Modular Monolith**: Publishes through existing `PostService`, `CommentService`, `ProfileService`, and SEO V2.0 (`ProcessSeoEntityJob`, `ContentTranslationService`). Zero duplicate social or SEO engines.
- **Shared Hosting Native**: Pure PHP 8.4 / Laravel 13 / MySQL InnoDB / Database Queue / Cron (`schedule:run`). Zero Python, GPU, Redis-only, or Node SSR daemon dependencies.

## 2. Core Modules

| Module                                | Location                                                                       | Responsibility                                                                                                                                  |
| ------------------------------------- | ------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| Automation Control & Budget Gate      | `app/Services/Ai/AiAutomationControlService.php`                               | Emergency Stop, 6 granular switches, compliance confirmations, daily/monthly token & cost ceilings, audit logging                               |
| Provider Abstraction & Gemini Adapter | `app/Services/Ai/AiProviderManager.php`, `GeminiProviderAdapter.php`           | Multi-provider interface (`AiProviderInterface`), Gemini REST `v1beta` integration, `x-goog-api-key` header auth, Structured Output JSON Schema |
| Credentials & Shared Quota            | `app/Services/Ai/AiCredentialAndQuotaService.php`                              | Unlimited encrypted keys (`Crypt::encryptString`), SHA-256 fingerprint dedup, Project+Model shared RPM/TPM/RPD windows                          |
| Official AI Agents                    | `app/Services/Ai/AiAgentService.php`                                           | Standard `users` with `account_type=ai_agent`, `UserRole::User`, visible `AiBadge.vue`, one-click 7 starter agents                              |
| Versioned Prompt Library              | `app/Services/Ai/AiPromptService.php`                                          | Allowlisted `{{variable}}` interpolation, prompt-injection sanitization, version rollback                                                       |
| SSRF-Safe Research                    | `app/Services/Ai/AiSourceResearchService.php`                                  | Public-URL validation via `SafeLinkPreviewService`, real GSC opportunities                                                                      |
| Generator & Quality Gate              | `app/Services/Ai/AiContentGeneratorService.php`, `AiContentQualityService.php` | Structured JSON output, thin/duplicate/PII/HTML/claim verification checks                                                                       |
| Publishing & Anti-Flood Scheduler     | `app/Services/Ai/AiPublishingAndSchedulerService.php`                          | DB `lockForUpdate` idempotent publish, edit-resets-approval, skips stale schedules >24h                                                         |
| Multilingual Translation              | `app/Services/Ai/AiTranslationWorkflowService.php`                             | Translates approved posts into SEO V2.0 `seo_content_translations`                                                                              |
| Comment & Moderation Assist           | `app/Services/Ai/AiCommunityAssistantService.php`                              | Human-reviewed replies (blocks AI-to-AI & respects `UserBlock`), advisory-only risk scoring                                                     |

## 3. Admin Routes & RBAC Permissions

Protected by `admin.2fa`, `can:access-admin`, and granular `ai.*` permissions (`ai.view`, `ai.manage`, `ai.credentials`, `ai.agents`, `ai.prompts`, `ai.review`, `ai.publish`, `ai.moderation`, `ai.emergency_stop`):

- `/admin/ai` — Overview, Emergency Stop, Switches, Compliance & Cost Analytics
- `/admin/ai/credentials` — Encrypted Gemini Keys, Active Model Registry & Project+Model Quota Windows
- `/admin/ai/agents` — Official AI Agents & Versioned Prompt Library
- `/admin/ai/content` — Draft Generation, Quality Gate, Human Review Queue, Sources, Plans & Schedules
- `/admin/ai/assist` — 15-Language SEO V2.0 Translation, Comment Assistant & Advisory Moderation
