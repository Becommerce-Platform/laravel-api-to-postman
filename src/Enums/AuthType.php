<?php

declare(strict_types=1);

namespace Becommerce\PostmanGenerator\Enums;

enum AuthType: string
{
    case BEARER = 'bearer';
    
    case BASIC = 'basic';
}
