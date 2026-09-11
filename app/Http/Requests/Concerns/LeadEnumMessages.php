<?php

namespace App\Http\Requests\Concerns;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use BackedEnum;
use Illuminate\Validation\Rules\Enum;

trait LeadEnumMessages
{
    /**
     * Rule::enum() failures are keyed by the rule's class name, not by "enum".
     *
     * @return array<string, string>
     */
    protected function leadEnumMessages(): array
    {
        return [
            'status.'.Enum::class => 'The status must be one of: '.$this->valuesOf(LeadStatus::class).'.',
            'source.'.Enum::class => 'The source must be one of: '.$this->valuesOf(LeadSource::class).'.',
        ];
    }

    /**
     * @param  class-string<BackedEnum>  $enum
     */
    private function valuesOf(string $enum): string
    {
        return implode(', ', array_column($enum::cases(), 'value'));
    }
}
