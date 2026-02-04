<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PassagePrestation extends Pivot
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'passage_prestation';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'passage_id',
        'prestation_id',
        'coiffeur_id',
        'prix_applique',
        'quantite',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'prix_applique' => 'decimal:2',
        'quantite' => 'integer',
    ];

    /**
     * Get the passage that owns the passage prestation.
     */
    public function passage(): BelongsTo
    {
        return $this->belongsTo(Passage::class);
    }

    /**
     * Get the prestation that owns the passage prestation.
     */
    public function prestation(): BelongsTo
    {
        return $this->belongsTo(Prestation::class);
    }

    /**
     * Get the coiffeur (user) that performed the service.
     */
    public function coiffeur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coiffeur_id');
    }
}