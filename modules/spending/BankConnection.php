<?php

namespace Modules\Spending;

use App\Models\User;
use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankConnection extends Model
{
    use BelongsToFamily;

    protected $table = 'spending_bank_connections';

    protected $fillable = ['user_id', 'label', 'provider', 'item_id', 'connector_name', 'status', 'last_synced_at', 'created_by'];

    protected $casts = ['last_synced_at' => 'datetime'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
