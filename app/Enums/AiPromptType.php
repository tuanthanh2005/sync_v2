<?php

namespace App\Enums;

enum AiPromptType: string
{
    case GeneratePost = 'generate_post';
    case GenerateArticle = 'generate_article';
    case GenerateDiscussion = 'generate_discussion';
    case GenerateTutorial = 'generate_tutorial';
    case GenerateNewsSummary = 'generate_news_summary';
    case GenerateComment = 'generate_comment';
    case GenerateReply = 'generate_reply';
    case TranslateContent = 'translate_content';
    case GenerateSeoMetadata = 'generate_seo_metadata';
    case SuggestHashtags = 'suggest_hashtags';
    case SuggestTopics = 'suggest_topics';
    case QualityReview = 'quality_review';
    case ContentModeration = 'content_moderation';

    public function label(): string
    {
        return match ($this) {
            self::GeneratePost => 'Generate Post',
            self::GenerateArticle => 'Generate Article',
            self::GenerateDiscussion => 'Generate Discussion',
            self::GenerateTutorial => 'Generate Tutorial',
            self::GenerateNewsSummary => 'Generate News Summary',
            self::GenerateComment => 'Generate Comment',
            self::GenerateReply => 'Generate Reply',
            self::TranslateContent => 'Translate Content',
            self::GenerateSeoMetadata => 'Generate SEO Metadata',
            self::SuggestHashtags => 'Suggest Hashtags',
            self::SuggestTopics => 'Suggest Topics',
            self::QualityReview => 'Quality Review',
            self::ContentModeration => 'Content Moderation',
        };
    }
}
