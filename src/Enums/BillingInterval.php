<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Enums;

enum BillingInterval: string
{
    case HOUR = 'hour';
    case DAY = 'day';
    case WEEK = 'week';
    case MONTH = 'month';
    case YEAR = 'year';

    public function toCarbonMethod(): string
    {
        return 'add'.ucfirst($this->value).'s';
    }

    public function toCarbonInterval(): string
    {
        return match ($this) {
            self::HOUR => 'PT1H',
            self::DAY => 'P1D',
            self::WEEK => 'P1W',
            self::MONTH => 'P1M',
            self::YEAR => 'P1Y',
        };
    }
}