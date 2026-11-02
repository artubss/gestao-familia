<?php

namespace Modules\Spending;

use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FixedExpense extends Model
{
    use BelongsToFamily;

    public const METHODS = ['pix', 'boleto', 'card', 'debit', 'cash', 'transfer', 'other'];

    protected $table = 'spending_fixed_expenses';

    protected $fillable = ['name', 'amount', 'currency', 'category', 'payer_id', 'day_of_month', 'payment_method', 'active', 'note', 'created_by'];

    protected $casts = [
        'amount' => 'integer',
        'day_of_month' => 'integer',
        'active' => 'boolean',
    ];

    public function payer(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'payer_id');
    }
}
