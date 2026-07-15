<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromptRule extends Model
{
    protected $fillable = [
        'prompt_rule_set_id',
        'category',
        'rule_text',
        'sort_order',
    ];

    public function set(): BelongsTo
    {
        return $this->belongsTo(PromptRuleSet::class, 'prompt_rule_set_id');
    }
}
