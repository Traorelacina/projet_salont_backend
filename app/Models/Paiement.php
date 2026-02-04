<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Paiement extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'passage_id',
        'montant_total',
        'montant_paye',
        'mode_paiement',
        'statut',
        'notes',
        'date_paiement',
        'numero_recu',
        'device_id',
        'synced_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'montant_total' => 'decimal:2',
        'montant_paye' => 'decimal:2',
        'date_paiement' => 'datetime',
        'synced_at' => 'datetime',
    ];

    /**
     * Boot method for model events.
     */
    protected static function boot()
    {
        parent::boot();

        // Avant la création, générer le numéro de reçu
        static::creating(function ($paiement) {
            if (!$paiement->numero_recu) {
                $paiement->numero_recu = $paiement->genererNumeroRecu();
            }
        });
    }

    /**
     * Get the passage that owns the paiement.
     */
    public function passage(): BelongsTo
    {
        return $this->belongsTo(Passage::class);
    }

    /**
     * Générer un numéro de reçu unique.
     */
    protected function genererNumeroRecu(): string
    {
        $date = now()->format('Ymd');
        $random = strtoupper(substr(md5(uniqid()), 0, 6));
        return "REC-{$date}-{$random}";
    }

    /**
     * Scope pour filtrer par date.
     */
    public function scopeByDate($query, $date)
    {
        return $query->whereDate('date_paiement', $date);
    }

    /**
     * Scope pour filtrer par période.
     */
    public function scopeByPeriod($query, $dateDebut, $dateFin)
    {
        return $query->whereBetween('date_paiement', [$dateDebut, $dateFin]);
    }

    /**
     * Scope pour obtenir uniquement les paiements validés.
     */
    public function scopeValides($query)
    {
        return $query->where('statut', 'valide');
    }

    /**
     * Vérifier si le paiement est complet.
     */
    public function estComplet(): bool
    {
        return $this->montant_paye >= $this->montant_total;
    }

    /**
     * Calculer le montant restant à payer.
     */
    public function getMontantRestantAttribute(): float
    {
        return max(0, $this->montant_total - $this->montant_paye);
    }

    /**
     * Vérifier si c'est un paiement gratuit (fidélité).
     */
    public function estGratuit(): bool
    {
        return $this->passage->est_gratuit;
    }
}
