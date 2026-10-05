<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinancialInvoiceRevision extends Model
{
    protected $fillable = [
        'financial_transaction_id',
        'invoice_path',
        'replaced_by',
        'replaced_at',
    ];

    protected $casts = [
        'replaced_at' => 'datetime',
    ];

    public function transaction()
    {
        return $this->belongsTo(FinancialTransaction::class, 'financial_transaction_id');
    }

    public function replacer()
    {
        return $this->belongsTo(User::class, 'replaced_by');
    }
}
