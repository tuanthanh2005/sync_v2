<?php

namespace App\Enums;

enum ActivationMilestone: string
{
    case RegistrationCompleted = 'registration_completed';
    case EmailVerified = 'email_verified';
    case InterestsSelected = 'interests_selected';
    case FirstPostViewed = 'first_post_viewed';
    case FirstCommunityJoined = 'first_community_joined';
    case FirstInteraction = 'first_interaction';
    case FirstPostPublished = 'first_post_published';
    case FirstReturnVisit = 'first_return_visit';

    public function label(): string
    {
        return match ($this) {
            self::RegistrationCompleted => 'Registration Completed',
            self::EmailVerified => 'Email Verified',
            self::InterestsSelected => 'Interests Selected',
            self::FirstPostViewed => 'First Public Post Viewed',
            self::FirstCommunityJoined => 'First Community Joined',
            self::FirstInteraction => 'First Meaningful Interaction',
            self::FirstPostPublished => 'First Post Published',
            self::FirstReturnVisit => 'First Return Visit',
        };
    }
}
