<?php

declare(strict_types=1);

namespace App\Model;

enum FeedbackCategory: string
{
    case BUG = 'bug';
    case SUGGESTION = 'suggestion';
    case GAMEPLAY = 'gameplay';
    case OTHER = 'other';
    case CONTACT = 'contact';
    case DATA_EXPORT_REQUEST = 'data_export_request';
    case ACCOUNT_DELETION_REQUEST = 'account_deletion_request';
    case WAITLIST = 'waitlist';
}
